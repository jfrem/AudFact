<?php

namespace App\Services\Audit;

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
        ?int $seed = null
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
    }

    /**
     * Envía la solicitud a Gemini con reintentos y backoff exponencial.
     *
     * @param string $prompt Prompt del usuario
     * @param array $files Archivos preparados
     * @param string $systemInstruction Instrucciones del sistema
     * @param array $generationOverrides Overrides de generación
     * @param array<string> $documentNames Nombres reales de adjuntos para enum dinámico del schema
     * @return array Respuesta de Gemini decodificada
     */
    public function sendWithRetry(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = [],
        array $documentNames = []
    ): array {
        // Circuit Breaker: verificar estado ANTES de intentar
        $this->checkCircuitBreaker();

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_API_RETRIES; $attempt++) {
            try {
                $result = $this->send($url, $prompt, $files, $systemInstruction, $generationOverrides, $documentNames);

                // Éxito: resetear Circuit Breaker
                $this->recordCircuitSuccess();

                return $result;
            } catch (\RuntimeException $e) {
                $lastException = $e;
                $httpCode = (int) $e->getCode();
                $isRetryable = in_array($httpCode, self::RETRYABLE_HTTP_CODES, true);
                $isLastAttempt = $attempt === self::MAX_API_RETRIES - 1;

                if ($isRetryable && !$isLastAttempt) {
                    $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);

                    Logger::warning('API error retryable, esperando antes de reintentar', [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'maxRetries' => self::MAX_API_RETRIES,
                        'delayMs' => $delayMs,
                        'error' => $e->getMessage(),
                    ]);

                    usleep($delayMs * 1000);
                    continue;
                }

                // Fallo definitivo: registrar en Circuit Breaker
                $this->recordCircuitFailure($httpCode);

                Logger::error('API error no retryable o último intento fallido', [
                    'httpCode' => $httpCode,
                    'attempt' => $attempt + 1,
                    'isRetryable' => $isRetryable,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        // Todos los reintentos agotados: registrar fallo
        $this->recordCircuitFailure(0);

        throw $lastException ?? new \RuntimeException('Error desconocido en API Gemini');
    }

    /**
     * Extrae el texto principal de la respuesta candidata de Gemini.
     *
     * @param array $result Payload de respuesta de Gemini
     * @return string|null Texto extraído o null si no existe
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

    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    private function send(
        string $url,
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = [],
        array $documentNames = []
    ): array {
        $payload = $this->buildPayload($prompt, $files, $systemInstruction, $generationOverrides, $documentNames);

        try {
            $res = $this->http->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            $httpCode = 0;
            $errorMessage = $e->getMessage();

            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $httpCode = $response->getStatusCode();
                $bodyContent = (string) $response->getBody();
                $errorBody = json_decode($bodyContent, true);

                if (isset($errorBody['error']['message'])) {
                    $errorMessage = $errorBody['error']['message'];
                }
            }

            throw new \RuntimeException('Error HTTP Gemini: ' . $errorMessage, $httpCode, $e);
        }

        // Fase 1.3: Monitoreo de cuotas API Gemini
        $this->logApiQuotaHeaders($res);

        $bodyStr = (string) $res->getBody();
        $body = json_decode($bodyStr, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Respuesta no JSON de Gemini: ' . json_last_error_msg(), 0);
        }

        return $body ?? [];
    }

    private function buildPayload(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = [],
        array $documentNames = []
    ): array {
        $auditSchema = AuditResponseSchema::getGeminiSchema($documentNames);

        $generationConfig = array_filter([
            'temperature' => $this->temperature,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'responseMimeType' => $this->responseMimeType,
            'seed' => $this->seed,
        ], fn($value) => $value !== null);

        $overrideThinkingBudget = $generationOverrides['thinkingBudget'] ?? null;
        unset($generationOverrides['thinkingBudget']);

        if (!empty($generationOverrides)) {
            $generationConfig = array_merge($generationConfig, $generationOverrides);
        }

        $generationConfig['responseSchema'] = $auditSchema;

        $parts = [
            ['text' => $prompt],
        ];

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

        $effectiveBudget = $overrideThinkingBudget ?? $this->thinkingBudget;
        if ($effectiveBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $effectiveBudget];
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => $generationConfig,
            'safetySettings' => $this->getSafetySettings(),
        ];

        return $payload;
    }

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

    // ── Circuit Breaker (Redis-backed) ────────────────────────────

    /**
     * Verifica el estado del Circuit Breaker antes de llamar a la API.
     * Si está abierto, lanza excepción inmediatamente sin consumir cuota.
     *
     * @throws \RuntimeException Si el circuito está abierto
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
     * Registra un éxito en el Circuit Breaker.
     * Resetea contadores y cierra el circuito.
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
     * Registra un fallo en el Circuit Breaker.
     * Si se supera el umbral, abre el circuito con TTL de enfriamiento.
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
            // Cuando el TTL expire, Redis borra la key → checkCircuitBreaker ve null → closed
            // Pero primero transicionamos a half-open instalando un callback via TTL escalonado
            // Simplificación: usamos el TTL directo. Al expirar la key 'open', el próximo check ve
            // estado null (= closed). El primer request exitoso resetea todo.

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

    // ── Monitoreo de Cuotas API (Fase 1.3) ───────────────────────

    /**
     * Extrae y loguea headers de rate limit de la respuesta de Gemini.
     * Warning automático cuando remaining < 20% del limit.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
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
