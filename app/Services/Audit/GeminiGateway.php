<?php

namespace App\Services\Audit;

use App\Services\Audit\Debug\ResponseIADiskStore;
use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class GeminiGateway
{
    private const MAX_API_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 1000;
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    private Client $http;
    private string $apiKey;
    private GeminiConfig $config;
    private GeminiCircuitBreaker $circuitBreaker;
    private ResponseIADiskStore $diskStore;

    public function __construct(
        Client $http,
        string $apiKey,
        GeminiConfig $config,
        ?GeminiCircuitBreaker $circuitBreaker = null,
        ?ResponseIADiskStore $diskStore = null
    ) {
        $this->http = $http;
        $this->apiKey = $apiKey;
        $this->config = $config;
        $this->circuitBreaker = $circuitBreaker ?? new GeminiCircuitBreaker();
        $this->diskStore = $diskStore ?? new ResponseIADiskStore();
    }

    /**
     * Envía un request de Function Calling a la API de Gemini.
     *
     * @param  string $prompt  Texto del prompt del usuario.
     * @param  array<int, array<string, mixed>> $files  Archivos inline (mime + data + label).
     * @param  string $systemInstruction  Instrucción de sistema.
     * @param  array<int, array<string, mixed>> $tools  Declaraciones de funciones.
     * @param  array<string, mixed> $toolConfig  Configuración de function calling.
     * @param  array<string, mixed> $generationOverrides  Sobrecargas de generación (temp, tokens, etc).
     * @param  array<string, mixed>|null $debugContext  Metadata de trazabilidad para debug (audit_id, document_id, etc).
     * @return array<string, mixed>
     */
    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $ctx = $debugContext ?? [];

        $this->circuitBreaker->check();

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->config->model}:generateContent";
        $payload = $this->buildPayload($prompt, $files, $systemInstruction, $tools, $toolConfig, $generationOverrides);

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
                $this->circuitBreaker->recordSuccess();

                $bodyStr = (string) $res->getBody();
                $body = json_decode($bodyStr, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException(
                        'Respuesta no JSON de Gemini FC: ' . json_last_error_msg(),
                        0
                    );
                }

                $this->saveDebugLog($payload, $body ?? ['error' => 'cuerpo_vacio'], $ctx, 'success');

                $body['X-Audit-Metrics'] = ['duration_ms' => (int) ((microtime(true) - $startTime) * 1000)];
                return $body ?? [];
            } catch (\RuntimeException $e) {
                $lastException = $e;
                $httpCode = (int) $e->getCode();

                if ($this->shouldRetry($httpCode, $attempt)) {
                    Logger::warning('FC API error retryable', [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'delayMs' => $this->retryDelayMs($attempt),
                    ]);
                    $this->applyRetryDelay($attempt);
                    continue;
                }

                $this->saveDebugLog($payload, ['error' => $e->getMessage()], $ctx, 'runtime_error');
                $this->circuitBreaker->recordFailure($httpCode);
                throw $e;
            } catch (GuzzleException $e) {
                $lastException = $e;
                [$httpCode, $errorMessage] = $this->extractGuzzleError($e);

                if ($this->shouldRetry($httpCode, $attempt)) {
                    $this->applyRetryDelay($attempt);
                    continue;
                }

                $this->saveDebugLog($payload, ['error' => $errorMessage], $ctx, 'http_error');
                $this->circuitBreaker->recordFailure($httpCode);
                throw new \RuntimeException('Error HTTP Gemini FC: ' . $errorMessage, $httpCode, $e);
            }
        }

        $this->saveDebugLog($payload, ['error' => 'Error desconocido en Gemini FC'], $ctx, 'unknown_error');
        $this->circuitBreaker->recordFailure(0);
        throw $lastException ?? new \RuntimeException('Error desconocido en Gemini FC');
    }

    // ─── Retry ──────────────────────────────────────────────

    private function shouldRetry(int $httpCode, int $attempt): bool
    {
        return in_array($httpCode, self::RETRYABLE_HTTP_CODES, true)
            && $attempt < self::MAX_API_RETRIES - 1;
    }

    private function retryDelayMs(int $attempt): int
    {
        return self::BASE_RETRY_DELAY_MS * (2 ** $attempt);
    }

    private function applyRetryDelay(int $attempt): void
    {
        usleep($this->retryDelayMs($attempt) * 1000);
    }

    // ─── Error Extraction ───────────────────────────────────

    /**
     * @return array{0:int,1:string}
     */
    private function extractGuzzleError(GuzzleException $e): array
    {
        $httpCode = 0;
        $errorMessage = $e->getMessage();

        if ($e instanceof RequestException && $e->hasResponse()) {
            $httpCode = $e->getResponse()->getStatusCode();
            $errorBody = json_decode((string) $e->getResponse()->getBody(), true);
            if (isset($errorBody['error']['message'])) {
                $errorMessage = $errorBody['error']['message'];
            }
        }

        return [$httpCode, $errorMessage];
    }

    // ─── Payload Builder ────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>> $files
     * @param  array<int, array<string, mixed>> $tools
     * @param  array<string, mixed> $toolConfig
     * @param  array<string, mixed> $generationOverrides
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = []
    ): array {
        $generationConfig = $this->config->toGenerationConfig($generationOverrides);

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
     * @return array<int, array<string, string>>
     */
    private function getSafetySettings(): array
    {
        return [
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',  'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',         'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_HARASSMENT',           'threshold' => 'BLOCK_NONE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',    'threshold' => 'BLOCK_NONE'],
        ];
    }

    // ─── Debug & Observability ──────────────────────────────

    /**
     * @param  array<string, mixed> $requestPayload
     * @param  array<string, mixed> $responseBody
     * @param  array<string, mixed> $context
     */
    private function saveDebugLog(array $requestPayload, array $responseBody, array $context, string $status): void
    {
        try {
            $this->diskStore->persist($requestPayload, $responseBody, array_merge($context, ['status' => $status]));
        } catch (\Throwable $e) {
            Logger::warning('Fallo inesperado al persistir responseIA', [
                'error' => $e->getMessage(),
                'disDetNro' => (string) ($context['dis_det_nro'] ?? ''),
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

        $hasQuotaHeaders = array_filter($headers, fn($v) => $v !== '');
        if (empty($hasQuotaHeaders)) {
            return;
        }

        $remaining = (int) ($headers['remaining'] ?: 0);
        $limit = (int) ($headers['limit'] ?: 1);
        $threshold = max(1, (int) ($limit * 0.2));

        if ($remaining > 0 && $remaining <= $threshold) {
            Logger::warning('Gemini API: cuota baja', [
                'remaining'  => $remaining,
                'limit'      => $limit,
                'reset'      => $headers['reset'],
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
