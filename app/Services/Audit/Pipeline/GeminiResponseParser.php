<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\DocumentQuality;
use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiGateway;
use Core\Logger;
use Core\RedisClient;
use RuntimeException;

/**
 * Parsea y valida la respuesta de Gemini para una extracción documental.
 *
 * Responsabilidades:
 * - Orquestar las 3 fases de recuperación: parse primario, retry selectivo, fallback.
 * - Validar la estructura del payload de extracción paralela.
 * - Validar ítems y visual checks.
 * - Incrementar métricas de telemetría (best-effort).
 *
 * @see DocumentExtractionWorker — delegante principal
 */
final class GeminiResponseParser
{
    private const ACCEPTED_FINISH_REASON = 'STOP';

    private const ERROR_MISSING_CANDIDATE       = 'GEMINI_EXTRACTION_MISSING_CANDIDATE';
    private const ERROR_UNSAFE_FINISH_REASON    = 'GEMINI_EXTRACTION_UNSAFE_FINISH_REASON';
    private const ERROR_MISSING_FUNCTION_CALL   = 'GEMINI_EXTRACTION_MISSING_FUNCTION_CALL';
    private const ERROR_INVALID_ARGS            = 'GEMINI_EXTRACTION_INVALID_ARGS';
    private const ERROR_UNEXPECTED_FUNCTION_CALL = 'GEMINI_EXTRACTION_UNEXPECTED_FUNCTION_CALL';
    private const ERROR_DUPLICATE_FUNCTION_CALL = 'GEMINI_EXTRACTION_DUPLICATE_FUNCTION_CALL';

    /**
     * Política de recuperación por función. Las funciones NO listadas aquí
     * son críticas: su ausencia lanza excepción sin intentar recovery.
     *
     * - retryable: admite segunda llamada selectiva a Gemini (Fase 2)
     * - fallback:  valor por defecto si la Fase 2 también falla (Fase 3)
     *
     * @see retryMissingFunctions() — Fase 2
     * @see parse()                 — Orquestación de las 3 fases
     */
    private const FUNCTION_RECOVERY_POLICY = [
        'detect_visual_checks' => [
            'retryable' => true,
            'fallback'  => ['visual_checks' => []],
        ],
        'assess_document_quality' => [
            'retryable' => true,
            'fallback'  => [
                'document_quality' => 'legible',
                'quality_notes'    => ['Fallback automático: Gemini omitió tras retry selectivo'],
            ],
        ],
    ];

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly RedisClient $redis,
        private readonly ExtractionPromptBuilder $promptBuilder
    ) {}

    /**
     * Orquesta las 3 fases de parsing de la respuesta Gemini.
     *
     * Fase 1: Parse primario del candidato principal.
     * Fase 2: Retry selectivo para funciones recuperables faltantes.
     * Fase 3: Fallback de último recurso para funciones no recuperadas.
     *
     * @param  array<string,mixed> $response      Respuesta de GeminiGateway (sin X-Audit-Metrics)
     * @param  array<string,mixed> $contract      Contrato de extracción
     * @param  array<string,mixed> $document      BLOB del documento (mime + data)
     * @param  string              $documentType  Tipo documental
     * @param  array<string,mixed> $payload       Payload del evento
     * @param  array<string,mixed> $debugContext  Contexto para logs
     * @return array<string,mixed>
     */
    public function parse(
        array $response,
        array $contract,
        array $document,
        string $documentType,
        array $payload,
        array $debugContext
    ): array {
        $requiredNames = $this->promptBuilder->requiredFunctionNames($contract);
        $candidate     = $this->extractPrimaryCandidate($response);
        $this->assertSuccessfulFinishReason($candidate);

        $parts = $this->extractCandidateParts($candidate);
        $calls = $this->extractFunctionCalls($parts, $requiredNames);

        // ── Detectar funciones faltantes ──
        $missingRecoverable = [];
        foreach ($requiredNames as $name) {
            if (!array_key_exists($name, $calls)) {
                if (!array_key_exists($name, self::FUNCTION_RECOVERY_POLICY)) {
                    throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL . ": {$name}");
                }
                $missingRecoverable[] = $name;
            }
        }

        if ($missingRecoverable !== []) {
            $auditId = $debugContext['audit_id'] ?? null;

            Logger::info('Parallel FC incompleto: intentando retry selectivo', [
                'missing_functions'  => $missingRecoverable,
                'received_functions' => array_keys($calls),
                'audit_id'           => $auditId,
                'document_type'      => $documentType,
            ]);
            $this->incrementMetric('parallel_fc_retry_attempted');

            // ── Fase 2: Retry selectivo ──
            try {
                $retryCalls = $this->retryMissingFunctions(
                    $document, $documentType, $contract, $payload,
                    $missingRecoverable, $debugContext
                );
                foreach ($retryCalls as $name => $args) {
                    if (!array_key_exists($name, $calls)) {
                        $calls[$name] = $args;
                    }
                }
                $recovered = array_intersect($missingRecoverable, array_keys($retryCalls));
                if ($recovered !== []) {
                    $this->incrementMetric('parallel_fc_retry_recovered');
                }
            } catch (\Throwable $retryError) {
                Logger::warning('Retry selectivo falló', [
                    'missing_functions' => $missingRecoverable,
                    'retry_error'       => $retryError->getMessage(),
                    'audit_id'          => $auditId,
                ]);
            }

            // ── Fase 3: Fallback último recurso ──
            foreach ($missingRecoverable as $name) {
                if (!array_key_exists($name, $calls)) {
                    $calls[$name] = self::FUNCTION_RECOVERY_POLICY[$name]['fallback'];
                    $this->incrementMetric('parallel_fc_fallback_applied');
                    Logger::warning('Fallback de último recurso activado', [
                        'function_name' => $name,
                        'audit_id'      => $auditId,
                    ]);
                }
            }
        }

        return $this->validateParallelExtractionPayload($calls);
    }

    // ─── Helpers de parsing ──────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function extractPrimaryCandidate(array $response): array
    {
        $candidate = $response['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            throw new RuntimeException(self::ERROR_MISSING_CANDIDATE);
        }

        return $candidate;
    }

    private function assertSuccessfulFinishReason(array $candidate): void
    {
        $finishReason = trim((string) ($candidate['finishReason'] ?? ''));
        if ($finishReason !== self::ACCEPTED_FINISH_REASON) {
            $reportedReason = $finishReason !== '' ? $finishReason : 'UNKNOWN';
            throw new RuntimeException(self::ERROR_UNSAFE_FINISH_REASON . ": {$reportedReason}");
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function extractCandidateParts(array $candidate): array
    {
        $parts = $candidate['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL);
        }

        return $parts;
    }

    /**
     * @param  array<int,array<string,mixed>> $parts
     * @param  array<int,string> $requiredNames
     * @return array<string,array<string,mixed>>
     */
    private function extractFunctionCalls(array $parts, array $requiredNames): array
    {
        $calls = [];
        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (!is_array($functionCall)) {
                continue;
            }

            $name = trim((string) ($functionCall['name'] ?? ''));
            if ($name === '' || !in_array($name, $requiredNames, true)) {
                $reportedName = $name !== '' ? $name : 'UNKNOWN';
                throw new RuntimeException(self::ERROR_UNEXPECTED_FUNCTION_CALL . ": {$reportedName}");
            }

            if (array_key_exists($name, $calls)) {
                throw new RuntimeException(self::ERROR_DUPLICATE_FUNCTION_CALL . ": {$name}");
            }

            $args = $functionCall['args'] ?? null;
            if (!is_array($args)) {
                throw new RuntimeException(self::ERROR_INVALID_ARGS . ": {$name}");
            }

            $calls[$name] = $args;
        }

        return $calls;
    }

    // ─── Retry selectivo (Fase 2) ────────────────────────────────────────────

    /**
     * Fase 2: Retry selectivo — segunda llamada a Gemini solo con funciones faltantes.
     *
     * Validado empíricamente: 100% recuperación en 3/3 pruebas con Acta de Entrega
     * de 17 items (X62260101059). ~190 tokens salida, ~3.4s duración.
     *
     * @param  array<string,mixed> $document
     * @param  array<string,mixed> $contract
     * @param  array<string,mixed> $payload
     * @param  array<int,string>   $missingNames
     * @param  array<string,mixed> $debugContext
     * @return array<string,array<string,mixed>>
     */
    private function retryMissingFunctions(
        array $document,
        string $documentType,
        array $contract,
        array $payload,
        array $missingNames,
        array $debugContext
    ): array {
        $allDeclarations   = $this->promptBuilder->contractFunctionDeclarations($contract);
        $retryDeclarations = array_values(array_filter(
            $allDeclarations,
            fn(array $decl) => in_array($decl['name'] ?? '', $missingNames, true)
        ));

        if ($retryDeclarations === []) {
            return [];
        }

        $systemPrompt = $this->promptBuilder->buildSystemPrompt($payload, $contract);

        $retryPrompt = "Documento objetivo: {$documentType}.\n"
            . "Analiza el documento y ejecuta las siguientes funciones: "
            . implode(', ', $missingNames) . ".\n"
            . "Invoca cada función exactamente una vez en el mismo turno.";

        $retryResponse = $this->gateway->sendWithFunctionCalling(
            $retryPrompt,
            [[
                'mime'  => $document['mime'],
                'data'  => $document['data'],
                'label' => $documentType,
            ]],
            $systemPrompt,
            [['functionDeclarations' => $retryDeclarations]],
            [
                'functionCallingConfig' => [
                    'mode'                 => 'ANY',
                    'allowedFunctionNames' => $missingNames,
                ],
            ],
            GeminiGateway::TASK_EXTRACTION,
            GeminiConfig::generationOverridesFromEnv('GEMINI_EXTRACTION', [
                'maxOutputTokens' => 2048,
            ]),
            array_merge($debugContext, ['call_purpose' => 'retry_missing_functions'])
        );

        unset($retryResponse['X-Audit-Metrics']);

        $retryCandidate = $this->extractPrimaryCandidate($retryResponse);
        $this->assertSuccessfulFinishReason($retryCandidate);
        $retryParts = $this->extractCandidateParts($retryCandidate);
        $retryCalls = $this->extractFunctionCalls($retryParts, $missingNames);

        Logger::info('Retry selectivo completado', [
            'requested' => $missingNames,
            'recovered' => array_keys($retryCalls),
            'audit_id'  => $debugContext['audit_id'] ?? null,
        ]);

        return $retryCalls;
    }

    // ─── Validación del payload ──────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validateParallelExtractionPayload(array $calls): array
    {
        $fields = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            'fields',
            'Gemini retornó extract_fields sin fields'
        );
        $items = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            'items',
            'Gemini retornó extract_items sin items'
        );
        $visualChecks = $this->optionalFunctionArray(
            $calls,
            DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            'visual_checks',
            'Gemini retornó detect_visual_checks sin visual_checks'
        );

        $qualityArgs     = $this->requiredFunctionArgs($calls, DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY);
        $documentQuality = $this->validateDocumentQuality($qualityArgs['document_quality'] ?? null);
        $qualityNotes    = is_array($qualityArgs['quality_notes'] ?? null)
            ? $qualityArgs['quality_notes']
            : ['Evaluación de calidad por defecto (propiedad no suministrada por la IA)'];

        $this->validateItems($items);
        $this->validateVisualChecks($visualChecks);

        return [
            'fields'           => $fields,
            'items'            => $items,
            'visual_checks'    => $visualChecks,
            'document_quality' => $documentQuality,
            'quality_notes'    => array_values($qualityNotes),
        ];
    }

    private function validateDocumentQuality(mixed $documentQuality): string
    {
        if (!is_string($documentQuality) || trim($documentQuality) === '') {
            throw new RuntimeException('Gemini retornó extraction payload sin document_quality');
        }

        return DocumentQuality::fromString(trim($documentQuality))->value;
    }

    /** @param array<int,mixed> $items */
    private function validateItems(array $items): void
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Gemini retornó item inválido en posición {$index}");
            }
        }
    }

    /** @param array<int,mixed> $visualChecks */
    private function validateVisualChecks(array $visualChecks): void
    {
        foreach ($visualChecks as $index => $visualCheck) {
            if (!is_array($visualCheck)) {
                throw new RuntimeException("Gemini retornó visual_check inválido en posición {$index}");
            }

            $check = $visualCheck['check'] ?? null;
            if (!is_string($check) || trim($check) === '') {
                throw new RuntimeException("Gemini retornó visual_check sin check en posición {$index}");
            }

            if (!array_key_exists('presente', $visualCheck) || !is_bool($visualCheck['presente'])) {
                throw new RuntimeException("Gemini retornó visual_check sin presente booleano en posición {$index}");
            }

            if (
                array_key_exists('valor', $visualCheck)
                && $visualCheck['valor'] !== null
                && !is_int($visualCheck['valor'])
                && !is_float($visualCheck['valor'])
                && !is_string($visualCheck['valor'])
            ) {
                throw new RuntimeException("Gemini retornó visual_check.valor inválido en posición {$index}");
            }

            foreach (['unidad', 'fecha_base'] as $optionalStringKey) {
                if (
                    array_key_exists($optionalStringKey, $visualCheck)
                    && $visualCheck[$optionalStringKey] !== null
                    && !is_string($visualCheck[$optionalStringKey])
                ) {
                    throw new RuntimeException("Gemini retornó visual_check.{$optionalStringKey} inválido en posición {$index}");
                }
            }
        }
    }

    // ─── Helpers de calls ────────────────────────────────────────────────────

    /** @return array<string|int,mixed> */
    private function optionalFunctionArray(array $calls, string $functionName, string $key, string $errorMessage): array
    {
        if (!array_key_exists($functionName, $calls)) {
            return [];
        }

        $value = $calls[$functionName][$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException($errorMessage);
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function requiredFunctionArgs(array $calls, string $functionName): array
    {
        $args = $calls[$functionName] ?? null;
        if (!is_array($args)) {
            throw new RuntimeException(self::ERROR_MISSING_FUNCTION_CALL . ": {$functionName}");
        }

        return $args;
    }

    // ─── Telemetría ──────────────────────────────────────────────────────────

    /** Incrementa un contador de telemetría en Redis (best-effort). */
    private function incrementMetric(string $metric): void
    {
        try {
            $this->redis->hIncrBy('telemetry:async_metrics', $metric, 1);
        } catch (\Throwable) {
            // Best-effort — nunca interrumpir el flujo por telemetría
        }
    }
}
