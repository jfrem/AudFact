<?php

namespace App\Worker;

use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Models\DispensationModel;
use App\Services\Audit\AuditFileManager;
use App\Services\Audit\AuditPromptBuilder;
use App\Services\Audit\AuditResponseSchema;
use App\Services\Audit\AuditResultValidator;
use App\Services\Audit\JsonResponseParser;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class GeminiAuditService
{
    // Constantes de Errores
    private const ERROR_MAX_TOKENS = 'Respuesta truncada del modelo (MAX_TOKENS)';
    private const ERROR_INVALID_RESPONSE = 'Respuesta inválida del modelo IA';
    private const ERROR_API_KEY_MISSING = 'GEMINI_API_KEY no configurada';
    private const ERROR_DISPENSATION_NOT_FOUND = 'Dispensación no encontrada';
    private const ERROR_ATTACHMENT_NOT_FOUND = 'Adjunto no encontrado';
    private const ERROR_PREPARING_ATTACHMENT = 'Error preparando adjunto';
    private const ERROR_MISSING_ATTACHMENTS = 'Documentos requeridos sin archivo adjunto';
    private const ERROR_HUMAN_REVIEW_REQUIRED = 'Tipo de servicio requiere revisión humana';

    // Tipos de servicio que requieren revisión humana (no se envían a IA)
    private const HUMAN_REVIEW_SERVICE_TYPES = ['TUTELA'];

    private const RESPONSE_DIR = '/../../responseIA';
    private const MAX_ITEMS_STRICT_MODE = 20;
    private const MAX_DETAIL_LENGTH_STRICT_MODE = 200;

    // Constantes de Retry Logic
    private const MAX_API_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 1000; // 1 segundo
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    private Client $http;
    private string $apiKey;
    private string $model;

    // Configuración AI
    private ?float $temperature;
    private ?float $topP;
    private ?int $topK;
    private ?int $maxOutputTokens;
    private string $responseMimeType;
    private ?string $mediaResolution;
    private ?int $thinkingBudget;

    // Componentes Delegados
    private AuditFileManager $fileManager;
    private AuditPromptBuilder $promptBuilder;
    private AuditResultValidator $validator;
    private JsonResponseParser $parser;

    // Modelos (Inyectables para Testing)
    private DispensationModel $dispensationModel;
    private AttachmentsModel $attachmentsModel;
    private AuditStatusModel $auditStatusModel;

    /**
     * Constructor del servicio de auditoría Gemini
     *
     * @param Client|null $http Cliente HTTP para las peticiones a la API
     * @param DispensationModel|null $dispensationModel Modelo de dispensación
     * @param AttachmentsModel|null $attachmentsModel Modelo de adjuntos
     * @param AuditFileManager|null $fileManager Gestor de archivos de auditoría
     * @param AuditPromptBuilder|null $promptBuilder Constructor de prompts
     * @param AuditResultValidator|null $validator Validador de resultados
     * @param JsonResponseParser|null $parser Parser de respuestas JSON
     * @throws \RuntimeException Si GEMINI_API_KEY no está configurada
     */
    public function __construct(
        ?Client $http = null,
        ?DispensationModel $dispensationModel = null,
        ?AttachmentsModel $attachmentsModel = null,
        ?AuditStatusModel $auditStatusModel = null,
        ?AuditFileManager $fileManager = null,
        ?AuditPromptBuilder $promptBuilder = null,
        ?AuditResultValidator $validator = null,
        ?JsonResponseParser $parser = null
    ) {
        // 1. Configuración Básica
        $this->apiKey = (string)(getenv('GEMINI_API_KEY') ?: '');
        if ($this->apiKey === '') {
            throw new \RuntimeException(self::ERROR_API_KEY_MISSING);
        }

        $this->model = (string)(getenv('GEMINI_MODEL') ?: '');
        if ($this->model === '') {
            throw new \RuntimeException('GEMINI_MODEL no está configurada en .env');
        }

        // Timeout configurable desde .env
        $timeout = $this->readIntEnv('GEMINI_TIMEOUT');
        $this->http = $http ?: new Client(['timeout' => $timeout ?: 60]);

        // 2. Parámetros de Generación (todos desde .env)
        $this->temperature = $this->readFloatEnv('GEMINI_TEMPERATURE');
        $this->topP = $this->readFloatEnv('GEMINI_TOP_P');
        $this->topK = $this->readIntEnv('GEMINI_TOP_K');
        $this->maxOutputTokens = $this->readIntEnv('GEMINI_MAX_OUTPUT_TOKENS');
        if ($this->maxOutputTokens === null || $this->maxOutputTokens <= 0) {
            throw new \RuntimeException('GEMINI_MAX_OUTPUT_TOKENS no está configurada o es inválida en .env');
        }
        $this->responseMimeType = (string)(getenv('GEMINI_RESPONSE_MIME') ?: '');
        if ($this->responseMimeType === '') {
            throw new \RuntimeException('GEMINI_RESPONSE_MIME no está configurada en .env');
        }
        $this->mediaResolution = getenv('GEMINI_MEDIA_RESOLUTION') ?: null;
        $this->thinkingBudget = $this->readIntEnv('GEMINI_THINKING_BUDGET');

        // 3. Inicializar Componentes Delegados (DI o Default)
        $this->fileManager = $fileManager ?: new AuditFileManager();
        $this->promptBuilder = $promptBuilder ?: new AuditPromptBuilder();
        $this->validator = $validator ?: new AuditResultValidator();
        $this->parser = $parser ?: new JsonResponseParser();

        // 4. Inicializar Modelos (DI o Default)
        $this->dispensationModel = $dispensationModel ?: new DispensationModel();
        $this->attachmentsModel = $attachmentsModel ?: new AttachmentsModel();
        $this->auditStatusModel = $auditStatusModel ?: new AuditStatusModel();
    }

    /**
     * Audita un adjunto contra la dispensación (fuente de verdad).
     *
     * @param string $invoiceId ID de la factura
     * @param string $DisDetNro Factura (identificador único)
     * @param string|null $attachmentId ID específico del adjunto (opcional)
     * @return array Resultado de la auditoría con estructura estandarizada
     */
    public function auditInvoice(string $invoiceId, string $DisDetNro, ?string $attachmentId = null): array
    {
        // C03: Resetear timeout por cada factura auditoría (ej: 2 minutos adicionales)
        set_time_limit(120);

        $totalStart = hrtime(true);
        $attempts = 0;

        // 1. Obtener datos de dispensación
        $dataFetchStart = hrtime(true);
        $dispensationData = $this->dispensationModel->getDispensationData($DisDetNro);
        Logger::info('Dispensación encontrada', [
            'DisDetNro' => $DisDetNro,
        ]);
        if (empty($dispensationData)) {
            return $this->terminate($DisDetNro, self::ERROR_DISPENSATION_NOT_FOUND);
        }

        // Usar el primer registro para obtener datos comunes (NitSec, etc.)
        $dispensation = $dispensationData[0];

        // Validar tipo de servicio — abortar si requiere revisión humana
        $tipoServicio = trim($dispensation['Tipo'] ?? '');
        if (in_array(strtoupper($tipoServicio), self::HUMAN_REVIEW_SERVICE_TYPES, true)) {
            Logger::info('Auditoría omitida — requiere revisión humana', [
                'DisDetNro' => $DisDetNro,
                'tipoServicio' => $tipoServicio,
            ]);
            $result = [
                'response' => 'human_review',
                'message' => self::ERROR_HUMAN_REVIEW_REQUIRED,
                'tipoServicio' => $tipoServicio,
                'documento' => $dispensation['NumeroFactura'] ?? '',
                'DisDetNro' => $DisDetNro,
                'data' => ['items' => []],
            ];
            $this->saveResponse($DisDetNro, $result);
            return $result;
        }

        // 2. Obtener todos los adjuntos requeridos
        $resolvedInvoiceId = (string)($dispensation['NumeroFactura'] ?? $invoiceId);
        if ($resolvedInvoiceId === '') {
            return $this->terminate($DisDetNro, self::ERROR_ATTACHMENT_NOT_FOUND);
        }
        $attachments = $this->attachmentsModel->getAttachmentsByInvoiceId(
            $resolvedInvoiceId,
            (string)($dispensation['NitSec'] ?? '')
        );
        if (empty($attachments)) {
            return $this->terminate($DisDetNro, self::ERROR_ATTACHMENT_NOT_FOUND);
        }

        // 3. Validar que todos los documentos requeridos tengan archivo adjunto (regla de negocio crítica)
        $missingFiles = $this->fileManager->getMissingRequiredAttachments($attachments, $dispensationData);
        if (!empty($missingFiles)) {
            $errorMsg = self::ERROR_MISSING_ATTACHMENTS . ': ' . implode(', ', $missingFiles);
            Logger::warning('Auditoría abortada por documentos faltantes', [
                'DisDetNro' => $DisDetNro,
                'missingDocuments' => $missingFiles,
                'totalDocuments' => count($attachments)
            ]);
            return $this->terminate($DisDetNro, $errorMsg);
        }
        $dataFetchMs = (hrtime(true) - $dataFetchStart) / 1e6;

        // 4. Preparar y validar archivos
        $filePrepStart = hrtime(true);
        try {
            $files = $this->fileManager->prepareAttachments($attachments, $dispensationData);
        } catch (\Exception $e) {
            Logger::error('Error preparando adjunto', ['error' => $e->getMessage()]);
            return $this->terminate($DisDetNro, self::ERROR_PREPARING_ATTACHMENT . ': ' . $e->getMessage());
        }
        $filePrepMs = (hrtime(true) - $filePrepStart) / 1e6;

        Logger::info('Documentos preparados para auditoría', [
            'DisDetNro' => $DisDetNro,
            'totalDocuments' => count($files),
            'documentTypes' => array_map(fn($f) => $f['label'] ?? 'N/A', $files)
        ]);

        // 5. Ejecutar Auditoría
        $geminiStart = hrtime(true);
        try {
            [$result, $attempts] = $this->executeAuditFlow($dispensationData, $files);
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $httpCode = (int)$e->getCode();

            Logger::error('Error crítico en flujo Gemini', [
                'error' => $errorMsg,
                'httpCode' => $httpCode
            ]);

            // Generar mensaje de error basado en código HTTP
            $shortMsg = $this->formatErrorMessage($httpCode, $errorMsg, $DisDetNro);
            $result = $this->errorResponse($shortMsg, ['raw' => $errorMsg]);
        } finally {
            // 6. Limpieza Garantizada
            foreach ($files as $file) {
                $this->fileManager->cleanup($file);
            }
        }
        $geminiApiMs = (hrtime(true) - $geminiStart) / 1e6;
        $totalMs = (hrtime(true) - $totalStart) / 1e6;

        // 7. Inyectar métricas de rendimiento
        $result['_meta'] = [
            'totalTimeMs' => round($totalMs),
            'phases' => [
                'dataFetchMs' => round($dataFetchMs),
                'filePrepMs' => round($filePrepMs),
                'geminiApiMs' => round($geminiApiMs),
            ],
            'attempts' => $attempts,
            'factura' => $dispensation['NumeroFactura'] ?? '',
            'FacSec' => $dispensation['FacSec'] ?? '',
            'documentos' => array_values(array_unique(array_map(fn($f) => $f['label'] ?? 'N/A', $files))),
            'timestamp' => date('c'),
        ];

        Logger::info('Auditoría completada', [
            'DisDetNro' => $DisDetNro,
            'totalTimeMs' => round($totalMs),
            'dataFetchMs' => round($dataFetchMs),
            'filePrepMs' => round($filePrepMs),
            'geminiApiMs' => round($geminiApiMs),
            'attempts' => $attempts,
        ]);

        // 8. Persistir y Retornar
        $this->saveResponse($DisDetNro, $result);
        $this->saveToDatabase($DisDetNro, $result, $dispensation);
        return $result;
    }

    /**
     * Flujo principal de llamadas a la API con reintento "Estricto"
     *
     * @param array $dispensationData Datos de la dispensación (puede contener múltiples registros)
     * @param array $files Archivos preparados para enviar a la API
     * @return array Resultado validado de la auditoría
     * @throws \Exception Si todos los intentos fallan
     */
    private function executeAuditFlow(array $dispensationData, array $files): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $systemInstruction = $this->promptBuilder->getSystemInstruction();

        // Generar instrucción dinámica basada en cantidad de documentos
        $multiDocInstruction = '';
        $fileCount = count($files);

        if ($fileCount > 1) {
            $docList = [];
            foreach ($files as $index => $file) {
                $label = $file['label'] ?? 'Documento';
                $docList[] = "Documento " . ($index + 1) . ": {$label}";
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
                    'maxOutputTokens' => $this->maxOutputTokens,
                ],
                'extraInstruction' => trim($multiDocInstruction . "\n" . 'IMPORTANTE: Responde con máximo ' . self::MAX_ITEMS_STRICT_MODE . ' items y detalles concisos (<=' . self::MAX_DETAIL_LENGTH_STRICT_MODE . ' caracteres).'),
            ],
        ];

        $lastError = self::ERROR_INVALID_RESPONSE;

        foreach ($attempts as $index => $cfg) {
            $prompt = $this->promptBuilder->buildUserPrompt($dispensationData);
            if ($cfg['extraInstruction'] !== '') {
                $prompt .= "\n" . $cfg['extraInstruction'];
            }

            // Llamar con retry logic incorporado
            $result = $this->sendGeminiRequestWithRetry(
                $url,
                $prompt,
                $files,
                $systemInstruction,
                $cfg['overrides']
            );

            $finishReason = $result['candidates'][0]['finishReason'] ?? null;
            Logger::info('Gemini finish reason', ['finishReason' => $finishReason, 'attempt' => $index + 1]);

            $rawText = $this->extractResponseText($result);
            Logger::warning('Respuesta de Gemini:', ['raw' => $rawText, 'attempt' => $index + 1]);

            $parsed = $rawText !== null ? $this->parser->parse($rawText) : null;

            if ($parsed === null) {
                Logger::error('Parser falló al procesar respuesta', [
                    'attempt' => $index + 1,
                    'rawText' => $rawText
                ]);
            } elseif (!$this->validator->isValid($parsed)) {
                Logger::error('Validación falló para respuesta parseada', [
                    'attempt' => $index + 1,
                    'parsed' => $parsed
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

    /**
     * Extrae el texto de la respuesta de Gemini
     *
     * @param array $result Resultado completo de la API
     * @return string|null Texto extraído o null si no se encuentra
     */
    private function extractResponseText(array $result): ?string
    {
        $part = $result['candidates'][0]['content']['parts'][0] ?? null;

        if ($part === null) {
            return null;
        }

        if (is_array($part)) {
            return $part['text'] ?? null;
        }

        if (is_string($part)) {
            return $part;
        }

        return null;
    }

    /**
     * Envía una petición a la API de Gemini con retry logic y exponential backoff
     *
     * @param string $url URL del endpoint de la API
     * @param string $prompt Prompt del usuario
     * @param array $files Archivos a enviar
     * @param string $systemInstruction Instrucciones del sistema
     * @param array $generationOverrides Configuración a sobrescribir
     * @return array Respuesta de la API
     * @throws \RuntimeException Si todos los reintentos fallan
     */
    private function sendGeminiRequestWithRetry(
        string $url,
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = []
    ): array {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_API_RETRIES; $attempt++) {
            try {
                return $this->sendGeminiRequest($url, $prompt, $files, $systemInstruction, $generationOverrides);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                $httpCode = (int)$e->getCode();
                $isRetryable = in_array($httpCode, self::RETRYABLE_HTTP_CODES, true);
                $isLastAttempt = $attempt === self::MAX_API_RETRIES - 1;

                if ($isRetryable && !$isLastAttempt) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);

                    Logger::warning('API error retryable, esperando antes de reintentar', [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'maxRetries' => self::MAX_API_RETRIES,
                        'delayMs' => $delayMs,
                        'error' => $e->getMessage()
                    ]);

                    usleep($delayMs * 1000);
                    continue;
                }

                // Error no retryable o último intento
                Logger::error('API error no retryable o último intento fallido', [
                    'httpCode' => $httpCode,
                    'attempt' => $attempt + 1,
                    'isRetryable' => $isRetryable,
                    'error' => $e->getMessage()
                ]);

                throw $e;
            }
        }

        // Este punto solo se alcanza si todos los intentos fallan
        throw $lastException ?? new \RuntimeException('Error desconocido en API Gemini');
    }

    /**
     * Envía una petición a la API de Gemini (método base sin retry)
     *
     * @param string $url URL del endpoint de la API
     * @param string $prompt Prompt del usuario
     * @param array $files Archivos a enviar
     * @param string $systemInstruction Instrucciones del sistema
     * @param array $generationOverrides Configuración a sobrescribir
     * @return array Respuesta de la API
     * @throws \RuntimeException Si hay error HTTP
     */
    private function sendGeminiRequest(
        string $url,
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = []
    ): array {
        $payload = $this->buildPayload($prompt, $files, $systemInstruction, $generationOverrides);

        try {
            $res = $this->http->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            // Extraer código HTTP y mensaje de error correctamente
            $httpCode = 0;
            $errorMessage = $e->getMessage();

            // RequestException tiene método hasResponse() y getResponse()
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $httpCode = $response->getStatusCode();
                $bodyContent = (string)$response->getBody();

                // Intentar extraer mensaje de error del body JSON
                $errorBody = json_decode($bodyContent, true);
                if (isset($errorBody['error']['message'])) {
                    $errorMessage = $errorBody['error']['message'];
                }
            }

            throw new \RuntimeException(
                'Error HTTP Gemini: ' . $errorMessage,
                $httpCode,
                $e
            );
        }

        $bodyStr = (string)$res->getBody();
        $body = json_decode($bodyStr, true);

        // Validar que el JSON sea válido
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Respuesta no JSON de Gemini: ' . json_last_error_msg(),
                0
            );
        }

        return $body ?? [];
    }

    /**
     * Construye el payload para la API de Gemini
     *
     * @param string $prompt Prompt del usuario
     * @param array $files Archivos preparados
     * @param string $systemInstruction Instrucciones del sistema
     * @param array $generationOverrides Parámetros a sobrescribir
     * @return array Payload completo para la API
     */
    private function buildPayload(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = []
    ): array {
        // Usar schema compatible con Gemini AI (subset simplificado)
        $auditSchema = AuditResponseSchema::getGeminiSchema();

        $generationConfig = array_filter([
            'temperature' => $this->temperature,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'responseMimeType' => $this->responseMimeType,
        ], fn($value) => $value !== null);

        if (!empty($generationOverrides)) {
            $generationConfig = array_merge($generationConfig, $generationOverrides);
        }

        // Explicitly add responseSchema (no override allowed)
        $generationConfig['responseSchema'] = $auditSchema;

        $parts = [
            ['text' => $prompt],
        ];

        foreach ($files as $index => $file) {
            $label = (string)($file['label'] ?? '');
            if ($label !== '') {
                $parts[] = ['text' => 'DOCUMENTO ' . ($index + 1) . ': ' . $label];
            }
            $parts[] = ['inlineData' => [
                'mimeType' => $file['mime'],
                'data' => $file['data']
            ]];
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => $generationConfig,
            'safetySettings' => $this->getSafetySettings()
        ];

        if ($this->mediaResolution !== null) {
            $payload['mediaResolution'] = $this->mediaResolution;
        }

        if ($this->thinkingBudget !== null) {
            $payload['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }

        return $payload;
    }


    /**
     * Obtiene la configuración de safety settings para Gemini
     * Configuración por defecto: BLOCK_NONE para todos (procesamiento de documentos médicos/administrativos)
     *
     * @return array Safety settings para la API
     */
    private function getSafetySettings(): array
    {
        // NOTA: Se usa BLOCK_NONE porque procesamos documentos administrativos/médicos
        // que pueden contener información sensible pero legítima
        return [
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_NONE'
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_NONE'
            ]
        ];
    }

    // Helpers y Utilidades

    /**
     * Termina el proceso de auditoría con un error
     *
     * @param string $DisDetNro Factura (identificador único)
     * @param string $message Mensaje de error
     * @return array Respuesta de error formateada
     */
    private function terminate(string $DisDetNro, string $message): array
    {
        $result = $this->errorResponse($message);
        $this->saveResponse($DisDetNro, $result);
        $this->saveToDatabase($DisDetNro, $result);
        Logger::error("Terminated audit for invoice: $DisDetNro with message: $message");
        return $result;
    }

    /**
     * Genera una respuesta de error estandarizada
     *
     * @param string $message Mensaje de error
     * @param array $data Datos adicionales del error
     * @return array Estructura de error
     */
    private function errorResponse(string $message, array $data = []): array
    {
        return [
            'response' => 'error',
            'message' => $message,
            'documento' => $data['documento'] ?? AuditResponseSchema::DOCUMENTO_MULTIPLE,
            'data' => [
                'items' => $data['items'] ?? [],
                'details' => $data['raw'] ?? null,
            ],
        ];
    }

    /**
     * Guarda la respuesta de la auditoría en un archivo JSON
     *
     * @param string $DisDetNro Factura (identificador único)
     * @param array $result Resultado de la auditoría
     * @return void
     */
    private function saveResponse(string $DisDetNro, array $result): void
    {
        $dir = __DIR__ . self::RESPONSE_DIR;

        // Crear directorio si no existe
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                Logger::error('No se pudo crear directorio de respuestas', ['dir' => $dir]);
                return;
            }
        }

        // Sanitizar nombre de archivo
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $DisDetNro) ?: 'unknown';
        $path = $dir . '/' . $safe . '_' . time() . '.json';

        // Codificar resultado
        $payload = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            Logger::error('Error codificando JSON de respuesta', ['DisDetNro' => $DisDetNro]);
            $payload = '{"response": "error", "message": "JSON Encoding Error"}';
        }

        // Guardar archivo
        if (file_put_contents($path, $payload) === false) {
            Logger::error('Error guardando respuesta de auditoría', ['path' => $path]);
        }
    }

    /**
     * Persiste el resultado de auditoría en la tabla AudDispEst.
     * Mapea el array de resultado a las columnas de la tabla y ejecuta upsert.
     *
     * @param string $DisDetNro Factura (identificador único = FacSec)
     * @param array $result Resultado de la auditoría
     * @param array|null $dispensation Datos de dispensación (para FacNro, FacNitSec)
     * @return void
     */
    private function saveToDatabase(string $DisDetNro, array $result, ?array $dispensation = null): void
    {
        try {
            // Manejar datos de dispensación
            $master = (isset($dispensation[0]) && is_array($dispensation[0])) ? $dispensation[0] : ($dispensation ?: []);

            $response = $result['response'] ?? 'error';
            $isSuccess = ($response === 'success');
            $findings = $result['data']['items'] ?? [];
            $severity = $result['severity'] ?? 'ninguna';

            // Detectar documento fallido (el primero con severidad alta)
            $failedDoc = null;
            foreach ($findings as $finding) {
                if (strtolower($finding['severidad'] ?? '') === 'alta') {
                    $failedDoc = $finding['documento'] ?? $finding['item'] ?? null;
                    break;
                }
            }

            $data = [
                'FacSec'                  => $master['FacSec'] ?? 'Hola',
                'FacNro'                  => $master['NumeroFactura'] ?? ($result['_meta']['factura'] ?? $DisDetNro),
                'EstAud'                  => $isSuccess ? 1 : 0,
                'EstadoDetallado'         => substr(trim($response), 0, 50),
                'RequiereRevisionHumana'  => ($severity === 'alta' || $severity === 'media' || $response === 'warning' || $response === 'error') ? 1 : 0,
                'Severidad'               => substr($severity, 0, 20),
                'Hallazgos'               => !empty($findings) ? json_encode($findings, JSON_UNESCAPED_UNICODE) : null,
                'DetalleError'            => $result['message'] ?? null,
                'DocumentosProcesados'    => count($result['_meta']['documentos'] ?? []),
                'FacNitSec'               => $master['NitSec'] ?? null,
                'VlrCobrado'              => (float)($master['VlrCobrado'] ?? 0),
                'DuracionProcesamientoMs' => (int)($result['_meta']['totalTimeMs'] ?? 0),
                'IPS_NIT'                 => $master['IPS_NIT'] ?? null,
                'DocumentoFallido'        => $failedDoc ? substr($failedDoc, 0, 255) : null
            ];

            Logger::info('Persistiendo auditoría en BD', ['FacSec' => $DisDetNro, 'EstAud' => $data['EstAud']]);
            $this->auditStatusModel->upsertAuditResult($data);
        } catch (\Exception $e) {
            Logger::error('Error persistiendo auditoría en BD', [
                'DisDetNro' => $DisDetNro,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Lee una variable de entorno como float
     *
     * @param string $key Nombre de la variable
     * @return float|null Valor o null si no existe
     */
    private function readFloatEnv(string $key): ?float
    {
        $val = getenv($key);
        return ($val !== false && $val !== '') ? (float)$val : null;
    }

    /**
     * Lee una variable de entorno como int
     *
     * @param string $key Nombre de la variable
     * @return int|null Valor o null si no existe
     */
    private function readIntEnv(string $key): ?int
    {
        $val = getenv($key);
        return ($val !== false && $val !== '') ? (int)$val : null;
    }

    /**
     * Formatea un mensaje de error basado en el código HTTP y mensaje original
     *
     * @param int $httpCode Código HTTP del error
     * @param string $errorMsg Mensaje de error original
     * @return string Mensaje formateado para el usuario
     */
    private function formatErrorMessage(int $httpCode, string $errorMsg, string $DisDetNro): string
    {
        $httpPrefix = $httpCode > 0 ? "[HTTP {$httpCode}] " : '';

        // Mapeo de códigos HTTP a mensajes amigables
        $friendlyMessages = [
            429 => 'Cuota de API excedida. Espera unos minutos.',
            503 => 'Servicio temporalmente no disponible. Reintenta más tarde.',
            500 => 'Error interno del servidor de IA.',
            502 => 'Error de gateway. Reintenta más tarde.',
            504 => 'Timeout del servidor. Reintenta más tarde.',
        ];

        // Si hay un mensaje amigable para este código, usarlo
        if (isset($friendlyMessages[$httpCode])) {
            return $httpPrefix . $friendlyMessages[$httpCode];
        }

        // Casos especiales basados en contenido del mensaje
        if (str_contains($errorMsg, 'quota') || str_contains($errorMsg, 'exceeded')) {
            Logger::error("Quota exceeded for invoice: $DisDetNro with message: $errorMsg");
            return $httpPrefix . 'Cuota de API excedida. Espera unos minutos.';
        }

        if (str_contains($errorMsg, 'timeout') || str_contains($errorMsg, 'timed out')) {
            Logger::error("Timeout for invoice: $DisDetNro with message: $errorMsg");
            return $httpPrefix . 'Timeout de conexión. Reintenta más tarde.';
        }

        // Mensaje genérico
        return $httpPrefix . 'Error del servicio de IA';
    }
}
