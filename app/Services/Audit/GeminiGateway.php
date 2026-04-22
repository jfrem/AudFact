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

    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = []
    ): array {
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

                $this->recordCircuitFailure($httpCode);
                throw new \RuntimeException('Error HTTP Gemini FC: ' . $errorMessage, $httpCode, $e);
            }
        }

        $this->recordCircuitFailure(0);
        throw $lastException ?? new \RuntimeException('Error desconocido en Gemini FC');
    }

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
