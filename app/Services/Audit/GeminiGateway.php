<?php

namespace App\Services\Audit;

use App\Services\Audit\Debug\ResponseIADiskStore;
use Core\Logger;
use Core\RedisClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class GeminiGateway
{
    private const MAX_API_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 1000;
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    // Circuit Breaker keys en Redis
    private const CB_KEY_STATE = 'cb:gemini:state';
    private const CB_KEY_FAILS = 'cb:gemini:fails';
    private const CB_STATE_CLOSED = 'closed';
    private const CB_STATE_OPEN = 'open';
    private const CB_STATE_HALF_OPEN = 'half-open';
    private const CTX_DIS_DET_NRO = 'X-Audit-Context-DisDetNro';
    private const CTX_AUDIT_ID = 'X-Audit-Context-AuditId';
    private const CTX_DOCUMENT_ID = 'X-Audit-Context-DocumentId';
    private const CTX_DOCUMENT_TYPE = 'X-Audit-Context-DocumentType';

    private Client $http;
    private string $apiKey;
    private string $model;
    private ?float $temperature;
    private ?float $topP;
    private ?int $topK;
    private int $maxOutputTokens;
    private string $responseMimeType;
    private ?string $mediaResolution;
    private ?int $thinkingBudget;
    private ?int $seed;
    private ResponseIADiskStore $responseIADiskStore;

    /**
     * Configura el gateway HTTP hacia Gemini y sus parámetros de generación.
     *
     * @param  Client $http  Cliente HTTP compartido.
     * @param  string $apiKey  API key de Gemini.
     * @param  string $model  Modelo generateContent configurado.
     * @param  float|null $temperature  Temperatura de generación.
     * @param  float|null $topP  Parámetro nucleus sampling.
     * @param  int|null $topK  Parámetro top-k.
     * @param  int $maxOutputTokens  Máximo de tokens de salida.
     * @param  string $responseMimeType  MIME esperado de respuesta.
     * @param  string|null $mediaResolution  Resolución de medios opcional.
     * @param  int|null $thinkingBudget  Presupuesto de thinking cuando aplica.
     * @param  int|null $seed  Semilla para reproducibilidad.
     * @param  ResponseIADiskStore|null $responseIADiskStore  Persistencia de snapshots request/response.
     * @return void
     */
    public function __construct(
        Client $http,
        string $apiKey,
        string $model,
        ?float $temperature,
        ?float $topP,
        ?int $topK,
        int $maxOutputTokens,
        string $responseMimeType,
        ?string $mediaResolution,
        ?int $thinkingBudget,
        ?int $seed = null,
        ?ResponseIADiskStore $responseIADiskStore = null
    ) {
        $this->http = $http;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->topP = $topP;
        $this->topK = $topK;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->responseMimeType = $responseMimeType;
        $this->mediaResolution = $mediaResolution;
        $this->thinkingBudget = $thinkingBudget;
        $this->seed = $seed;
        $this->responseIADiskStore = $responseIADiskStore ?? new ResponseIADiskStore();
    }

    /**
     * Extrae texto plano de una respuesta Gemini legacy cuando no se usa Function Calling.
     *
     * @param  array<string, mixed> $result  Respuesta decodificada de Gemini.
     * @return string|null Texto del primer part, si existe.
     */
    public function extractResponseText(array $result): ?string
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
     * Devuelve el límite de tokens configurado para las respuestas de Gemini.
     *
     * @return int Máximo de tokens de salida.
     */
    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    /**
     * Devuelve la configuración efectiva no sensible usada para llamadas Gemini.
     *
     * @return array<string, mixed> Parámetros de generación seguros para debug.
     */
    public function getDebugConfig(): array
    {
        return [
            'model' => $this->model,
            'temperature' => $this->temperature ?? 0.0,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'responseMimeType' => $this->responseMimeType,
            'mediaResolution' => $this->mediaResolution,
            'thinkingBudget' => $this->thinkingBudget,
            'seed' => $this->seed,
        ];
    }

    /**
     * Envía documentos y prompt a Gemini obligando Function Calling.
     *
     * @param  string $prompt  Prompt de usuario para extracción.
     * @param  array<int, array<string, mixed>> $files  Archivos inlineData preparados.
     * @param  string $systemInstruction  Instrucción de sistema.
     * @param  array<int, array<string, mixed>> $tools  Declaraciones de funciones.
     * @param  array<string, mixed> $toolConfig  Configuración de Function Calling.
     * @param  array<string, mixed> $generationOverrides  Overrides puntuales de generación.
     * @return array<string, mixed> Respuesta JSON de Gemini.
     * @throws \RuntimeException Si el circuito está abierto, la respuesta no es JSON o Gemini falla.
     */
    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = []
    ): array {
        $debugContext = $this->extractDebugContext($generationOverrides);

        $this->checkCircuitBreaker();

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $payload = $this->buildFunctionCallingPayload(
            $prompt,
            $files,
            $systemInstruction,
            $tools,
            $toolConfig,
            $generationOverrides
        );

        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_API_RETRIES; $attempt++) {
            $startTime = microtime(true);
            try {
                $res = $this->http->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
                    ],
                    'json' => $payload,
                ]);

                $this->logApiQuotaHeaders($res);
                $this->recordCircuitSuccess();

                $bodyStr = (string) $res->getBody();
                $body = json_decode($bodyStr, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException(
                        'Respuesta no JSON de Gemini FC: ' . json_last_error_msg(),
                        0
                    );
                }
                
                $this->saveDebugLogWithStatus($payload, $body ?? ['error' => 'cuerpo_vacio'], $debugContext, 'success');

                $body['X-Audit-Metrics'] = ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)];
                return $body ?? [];
            } catch (\RuntimeException $e) {
                $lastException = $e;
                $httpCode = (int) $e->getCode();
                $isRetryable = in_array($httpCode, self::RETRYABLE_HTTP_CODES, true);
                $isLastAttempt = $attempt === self::MAX_API_RETRIES - 1;

                if ($isRetryable && !$isLastAttempt) {
                    $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);
                    Logger::warning('FC API error retryable', [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'delayMs' => $delayMs,
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }
                
                $this->saveDebugLogWithStatus($payload, ['error' => $e->getMessage()], $debugContext, 'runtime_error');
                $this->recordCircuitFailure($httpCode);
                throw $e;
            } catch (GuzzleException $e) {
                $lastException = $e;
                $httpCode = 0;
                $errorMessage = $e->getMessage();

                if ($e instanceof RequestException && $e->hasResponse()) {
                    $httpCode = $e->getResponse()->getStatusCode();
                    $errorBody = json_decode((string) $e->getResponse()->getBody(), true);
                    if (isset($errorBody['error']['message'])) {
                        $errorMessage = $errorBody['error']['message'];
                    }
                }

                $isRetryable = in_array($httpCode, self::RETRYABLE_HTTP_CODES, true);
                $isLastAttempt = $attempt === self::MAX_API_RETRIES - 1;

                if ($isRetryable && !$isLastAttempt) {
                    $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }
                
                $this->saveDebugLogWithStatus($payload, ['error' => $errorMessage], $debugContext, 'http_error');
                $this->recordCircuitFailure($httpCode);
                throw new \RuntimeException('Error HTTP Gemini FC: ' . $errorMessage, $httpCode, $e);
            }
        }

        $this->saveDebugLogWithStatus($payload, ['error' => 'Error desconocido en Gemini FC'], $debugContext, 'unknown_error');
        $this->recordCircuitFailure(0);
        throw $lastException ?? new \RuntimeException('Error desconocido en Gemini FC');
    }

    /**
     * Extrae y limpia claves de contexto debug de los overrides de generación.
     *
     * @param  array<string, mixed> $generationOverrides  Overrides de generación mutables.
     * @return array<string, mixed> Contexto depurado para persistencia debug.
     */
    private function extractDebugContext(array &$generationOverrides): array
    {
        $context = [
            'dis_det_nro' => $generationOverrides[self::CTX_DIS_DET_NRO] ?? null,
            'audit_id' => $generationOverrides[self::CTX_AUDIT_ID] ?? null,
            'document_id' => $generationOverrides[self::CTX_DOCUMENT_ID] ?? null,
            'document_type' => $generationOverrides[self::CTX_DOCUMENT_TYPE] ?? null,
        ];

        unset($generationOverrides[self::CTX_DIS_DET_NRO]);
        unset($generationOverrides[self::CTX_AUDIT_ID]);
        unset($generationOverrides[self::CTX_DOCUMENT_ID]);
        unset($generationOverrides[self::CTX_DOCUMENT_TYPE]);

        return $context;
    }

    /**
     * Persiste snapshot debug agregando estado de la ejecución.
     *
     * @param  array<string, mixed> $requestPayload  Payload request a Gemini.
     * @param  array<string, mixed> $responseBody  Respuesta o error serializable.
     * @param  array<string, mixed> $context  Contexto de auditoría/documento.
     * @param  string $status  Estado operativo del intento.
     * @return void
     */
    private function saveDebugLogWithStatus(array $requestPayload, array $responseBody, array $context, string $status): void
    {
        $this->saveDebugLog($requestPayload, $responseBody, array_merge($context, ['status' => $status]));
    }

    /**
     * Guarda el request/response en `responseIA` si `APP_ENV=development`.
     *
     * @param  array<string, mixed> $requestPayload  Payload enviado a Gemini.
     * @param  array<string, mixed> $responseBody  Respuesta de Gemini.
     * @param  array<string, mixed> $context  Contexto de auditoría/documento.
     * @return void
     */
    private function saveDebugLog(array $requestPayload, array $responseBody, array $context): void
    {
        try {
            $this->responseIADiskStore->persist($requestPayload, $responseBody, $context);
        } catch (\Throwable $e) {
            Logger::warning('Fallo inesperado al persistir responseIA', [
                'error' => $e->getMessage(),
                'disDetNro' => (string) ($context['dis_det_nro'] ?? ''),
            ]);
        }
    }

    /**
     * Arma el payload multimodal de generateContent con prompt, documentos y tools.
     *
     * @param  string $prompt  Texto del usuario.
     * @param  array<int, array<string, mixed>> $files  Archivos base64 con MIME.
     * @param  string $systemInstruction  Instrucción de sistema.
     * @param  array<int, array<string, mixed>> $tools  Tools disponibles para Gemini.
     * @param  array<string, mixed> $toolConfig  Configuración de invocación de tools.
     * @param  array<string, mixed> $generationOverrides  Ajustes temporales de generación.
     * @return array<string, mixed> Payload serializable para la API.
     */
    private function buildFunctionCallingPayload(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = []
    ): array {
        $generationConfig = array_filter([
            'temperature' => $this->temperature ?? 0.0,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'seed' => $this->seed,
        ], fn($value) => $value !== null);

        if (!empty($generationOverrides)) {
            $thinkingBudget = $generationOverrides['thinkingBudget'] ?? null;
            unset($generationOverrides['thinkingBudget']);
            $generationConfig = array_merge($generationConfig, $generationOverrides);

            if ($thinkingBudget !== null) {
                $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $thinkingBudget];
            }
        } elseif ($this->thinkingBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }

        // Parts: prompt text + archivos inline
        $parts = [['text' => $prompt]];

        foreach ($files as $index => $file) {
            $label = (string) ($file['label'] ?? '');
            if ($label !== '') {
                $parts[] = ['text' => 'DOCUMENTO ' . ($index + 1) . ': ' . $label];
            }
            $parts[] = ['inlineData' => [
                'mimeType' => $file['mime'],
                'data' => $file['data'],
            ]];
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'tools' => $tools,
            'toolConfig' => $toolConfig,
            'generationConfig' => $generationConfig,
            'safetySettings' => $this->getSafetySettings(),
        ];
    }

    /**
     * Define safety settings permisivos para documentos clínicos y administrativos.
     *
     * @return array<int, array<string, string>> Configuración de seguridad para Gemini.
     */
    private function getSafetySettings(): array
    {
        return [
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_NONE',
            ],
        ];
    }

    /**
     * Revisa el estado del circuit breaker antes de llamar Gemini.
     *
     * @return void
     * @throws \RuntimeException Si el circuito está abierto y debe rechazarse la llamada.
     */
    private function checkCircuitBreaker(): void
    {
        $redis = RedisClient::getInstance();
        if (!$redis->isAvailable()) {
            return; // Degradación: sin Redis, CB siempre cerrado
        }

        try {
            $state = $redis->get(self::CB_KEY_STATE) ?? self::CB_STATE_CLOSED;
        } catch (\Core\RedisUnavailableException $e) {
            // Redis fallo durante GET: degradar a circuito cerrado
            return;
        }

        if ($state === self::CB_STATE_OPEN) {
            $ttl = $redis->ttl(self::CB_KEY_STATE);
            Logger::warning('Circuit Breaker ABIERTO — request rechazado sin llamar API', [
                'cooldownRestante' => $ttl,
            ]);
            throw new \RuntimeException(
                'Circuit Breaker abierto: API Gemini temporalmente no disponible. Reintentar en ' . max($ttl, 0) . 's',
                503
            );
        }

        // Half-Open: permitir 1 request de prueba (no bloquear)
        if ($state === self::CB_STATE_HALF_OPEN) {
            Logger::info('Circuit Breaker HALF-OPEN — permitiendo request de prueba');
        }
    }

    /**
     * Limpia el estado del circuit breaker tras una llamada exitosa.
     *
     * @return void
     */
    private function recordCircuitSuccess(): void
    {
        $redis = RedisClient::getInstance();
        if (!$redis->isAvailable()) {
            return;
        }

        try {
            $state = $redis->get(self::CB_KEY_STATE);
            if ($state === self::CB_STATE_HALF_OPEN) {
                Logger::info('Circuit Breaker: Half-Open → Closed (request exitoso)');
            }
        } catch (\Core\RedisUnavailableException $e) {
            // Ignorar — no es crítico para el flujo de éxito
        }

        $redis->del(self::CB_KEY_STATE);
        $redis->del(self::CB_KEY_FAILS);
    }

    /**
     * Registra un fallo de Gemini y abre el circuito si supera el umbral configurado.
     *
     * @param  int $httpCode  Código HTTP asociado al fallo.
     * @return void
     */
    private function recordCircuitFailure(int $httpCode): void
    {
        $redis = RedisClient::getInstance();
        if (!$redis->isAvailable()) {
            return;
        }

        $threshold = (int) \Core\Env::get('CB_GEMINI_THRESHOLD', 3);
        $cooldown = (int) \Core\Env::get('CB_GEMINI_COOLDOWN', 60);

        // Incrementar contador de fallos (con TTL del doble del cooldown como safety)
        $fails = $redis->incr(self::CB_KEY_FAILS, $cooldown * 2);

        if ($fails !== null && $fails >= $threshold) {
            // Abrir circuito con TTL de enfriamiento
            $redis->set(self::CB_KEY_STATE, self::CB_STATE_OPEN, $cooldown);

            Logger::warning('Circuit Breaker ABIERTO', [
                'fallosConsecutivos' => $fails,
                'threshold' => $threshold,
                'cooldownSeconds' => $cooldown,
                'httpCode' => $httpCode,
            ]);
        } else {
            Logger::info('Circuit Breaker: fallo registrado', [
                'fallosActuales' => $fails,
                'threshold' => $threshold,
                'httpCode' => $httpCode,
            ]);
        }
    }

    /**
     * Registra headers de cuota cuando Gemini los retorna.
     *
     * @param  mixed $response  Respuesta HTTP compatible con getHeaderLine().
     * @return void
     */
    private function logApiQuotaHeaders($response): void
    {
        $headers = [
            'remaining' => $response->getHeaderLine('x-ratelimit-remaining'),
            'limit'     => $response->getHeaderLine('x-ratelimit-limit'),
            'reset'     => $response->getHeaderLine('x-ratelimit-reset'),
        ];

        // Solo loguear si hay headers de cuota presentes
        $hasQuotaHeaders = array_filter($headers, fn($v) => $v !== '');
        if (empty($hasQuotaHeaders)) {
            return;
        }

        $remaining = (int) ($headers['remaining'] ?: 0);
        $limit = (int) ($headers['limit'] ?: 1);
        $threshold = max(1, (int) ($limit * 0.2));

        if ($remaining > 0 && $remaining <= $threshold) {
            Logger::warning('Gemini API: cuota baja', [
                'remaining' => $remaining,
                'limit'     => $limit,
                'reset'     => $headers['reset'],
                'umbral20pct' => $threshold,
            ]);
        } else {
            Logger::info('Gemini API: cuota', [
                'remaining' => $headers['remaining'],
                'limit'     => $headers['limit'],
            ]);
        }
    }
}
