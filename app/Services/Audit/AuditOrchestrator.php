<?php

namespace App\Services\Audit;

use Core\Logger;

class AuditOrchestrator
{
    private const ERROR_MAX_TOKENS = 'Respuesta truncada del modelo (MAX_TOKENS)';
    private const ERROR_INVALID_RESPONSE = 'Respuesta inválida del modelo IA';

    private const MAX_ITEMS_STRICT_MODE = 20;
    private const MAX_DETAIL_LENGTH_STRICT_MODE = 200;

    private AuditFileManager $fileManager;
    private AuditPromptBuilder $promptBuilder;
    private AuditResultValidator $validator;
    private JsonResponseParser $parser;
    private GeminiGateway $gateway;
    private AuditPersistenceService $persistence;
    private AuditTelemetryService $telemetry;
    private AuditPreValidator $preValidator;

    public function __construct(
        AuditFileManager $fileManager,
        AuditPromptBuilder $promptBuilder,
        AuditResultValidator $validator,
        JsonResponseParser $parser,
        GeminiGateway $gateway,
        AuditPersistenceService $persistence,
        AuditTelemetryService $telemetry,
        AuditPreValidator $preValidator
    ) {
        $this->fileManager = $fileManager;
        $this->promptBuilder = $promptBuilder;
        $this->validator = $validator;
        $this->parser = $parser;
        $this->gateway = $gateway;
        $this->persistence = $persistence;
        $this->telemetry = $telemetry;
        $this->preValidator = $preValidator;
    }

    /**
     * Audita un adjunto contra la dispensación (fuente de verdad).
     *
     * @param string $invoiceId ID de la factura
     * @param string $disDetNro Identificador de dispensación/factura
     * @param string|null $attachmentId ID opcional de adjunto específico
     * @return array Resultado estructurado de auditoría
     */
    public function auditInvoice(string $invoiceId, string $disDetNro, ?string $attachmentId = null): array
    {
        set_time_limit(120);
        $totalStart = hrtime(true);
        $attempts = 0;

        $preValidation = $this->preValidator->validate($invoiceId, $disDetNro);

        if ($preValidation['result'] !== null) {
            $failResult = $preValidation['result'];
            $this->persistence->saveResponse($disDetNro, $failResult);
            $this->persistence->saveToDatabase(
                $disDetNro,
                $failResult,
                $preValidation['dispensation'] ?? []
            );
            return $failResult;
        }

        $dispensationData = $preValidation['dispensationData'];
        $dispensation = $preValidation['dispensation'];
        $files = $preValidation['files'];
        $dataFetchMs = $preValidation['dataFetchMs'];
        $filePrepMs = $preValidation['filePrepMs'];

        // ── Fase 2: Ejecutar auditoría con Gemini ──
        $geminiStart = hrtime(true);

        try {
            [$result, $attempts] = $this->executeAuditFlow($dispensationData, $files);
            $result['_errorOrigin'] = 'gemini';
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $httpCode = (int) $e->getCode();

            Logger::error('Error crítico en flujo Gemini', [
                'error' => $errorMsg,
                'httpCode' => $httpCode,
            ]);

            $shortMsg = $this->telemetry->formatErrorMessage($httpCode, $errorMsg, $disDetNro);
            $result = $this->errorResponse($shortMsg, ['raw' => $errorMsg]);
        } finally {
            foreach ($files as $file) {
                $this->fileManager->cleanup($file);
            }
        }

        $geminiApiMs = (hrtime(true) - $geminiStart) / 1e6;
        $totalMs = (hrtime(true) - $totalStart) / 1e6;

        // ── Fase 3: Telemetría y persistencia ──
        $result['_meta'] = $this->telemetry->buildMeta(
            $dispensation,
            $files,
            $dataFetchMs,
            $filePrepMs,
            $geminiApiMs,
            $attempts,
            $totalMs
        );

        Logger::info('Auditoría completada', [
            'DisDetNro' => $disDetNro,
            'totalTimeMs' => round($totalMs),
            'dataFetchMs' => round($dataFetchMs),
            'filePrepMs' => round($filePrepMs),
            'geminiApiMs' => round($geminiApiMs),
            'attempts' => $attempts,
        ]);

        $this->persistence->saveResponse($disDetNro, $result);
        $this->persistence->saveToDatabase($disDetNro, $result, $dispensation);

        return $result;
    }

    private function executeAuditFlow(array $dispensationData, array $files): array
    {
        $systemInstruction = $this->promptBuilder->getSystemInstruction($dispensationData);

        $multiDocInstruction = '';
        $fileCount = count($files);

        if ($fileCount > 1) {
            $docList = [];
            foreach ($files as $index => $file) {
                $label = $file['label'] ?? 'Documento';
                $docList[] = 'Documento ' . ($index + 1) . ': ' . $label;
            }
            $multiDocInstruction = sprintf(
                'Se adjuntan %d documentos (%s). Analiza todos los documentos y valida coherencia entre ellos.',
                $fileCount,
                implode(', ', $docList)
            );
        }

        $attempts = [
            [
                'overrides' => [],
                'extraInstruction' => $multiDocInstruction,
            ],
            [
                'overrides' => [
                    'temperature' => 0,
                ],
                'extraInstruction' => trim($multiDocInstruction . "\n" . 'IMPORTANTE: Responde con máximo ' . self::MAX_ITEMS_STRICT_MODE . ' items y detalles concisos (<=' . self::MAX_DETAIL_LENGTH_STRICT_MODE . ' caracteres).'),
            ],
        ];

        $lastError = self::ERROR_INVALID_RESPONSE;
        $pdfList = array_values(array_unique(array_map(fn($f) => $f['label'] ?? 'Documento Adjunto', $files)));

        foreach ($attempts as $index => $cfg) {
            $prompt = $this->promptBuilder->buildUserPrompt($dispensationData, $pdfList);
            if ($cfg['extraInstruction'] !== '') {
                $prompt .= "\n" . $cfg['extraInstruction'];
            }

            $result = $this->gateway->sendWithRetry(
                $prompt,
                $files,
                $systemInstruction,
                $cfg['overrides']
            );

            $finishReason = $result['candidates'][0]['finishReason'] ?? null;
            Logger::info('Gemini finish reason', ['finishReason' => $finishReason, 'attempt' => $index + 1]);

            $rawText = $this->gateway->extractResponseText($result);
            Logger::info('Respuesta de Gemini recibida', [
                'attempt' => $index + 1,
                'finishReason' => $finishReason,
                'responseTextLength' => $rawText !== null ? strlen($rawText) : 0,
            ]);

            $parsed = $rawText !== null ? $this->parser->parse($rawText) : null;

            if ($parsed === null) {
                Logger::error('Parser falló al procesar respuesta', [
                    'attempt' => $index + 1,
                    'finishReason' => $finishReason,
                    'responseTextLength' => $rawText !== null ? strlen($rawText) : 0,
                ]);
            } elseif (!$this->validator->isValid($parsed)) {
                Logger::error('Validación falló para respuesta parseada', [
                    'attempt' => $index + 1,
                    'finishReason' => $finishReason,
                    'itemsCount' => count($parsed['data']['items'] ?? []),
                    'responseType' => $parsed['response'] ?? null,
                ]);
            } else {
                return [$parsed, $index + 1];
            }

            if ($finishReason === 'MAX_TOKENS') {
                $lastError = self::ERROR_MAX_TOKENS;
                continue;
            }

            $lastError = self::ERROR_INVALID_RESPONSE;
        }

        throw new \Exception($lastError, count($attempts));
    }

    private function errorResponse(string $message, array $data = []): array
    {
        return [
            'response' => 'error',
            'severity' => $data['severity'] ?? 'alta',
            'message' => $message,
            'documento' => $data['documento'] ?? AuditResponseSchema::DOCUMENTO_MULTIPLE,
            '_errorOrigin' => $data['_errorOrigin'] ?? 'infrastructure',
            'data' => [
                'items' => $data['items'] ?? [],
                'details' => $data['raw'] ?? null,
            ],
        ];
    }
}
