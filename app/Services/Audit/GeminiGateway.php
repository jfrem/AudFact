<?php

namespace App\Services\Audit;

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
    private string $model;
    private ?float $temperature;
    private ?float $topP;
    private ?int $topK;
    private int $maxOutputTokens;
    private string $responseMimeType;
    private ?string $mediaResolution;
    private ?int $thinkingBudget;

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
        ?int $thinkingBudget
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
    }

    /**
     * Envía la solicitud a Gemini con reintentos y backoff exponencial.
     *
     * @param string $prompt Prompt del usuario
     * @param array $files Archivos preparados
     * @param string $systemInstruction Instrucciones del sistema
     * @param array $generationOverrides Overrides de generación
     * @return array Respuesta de Gemini decodificada
     */
    public function sendWithRetry(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationOverrides = []
    ): array {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_API_RETRIES; $attempt++) {
            try {
                return $this->send($url, $prompt, $files, $systemInstruction, $generationOverrides);
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

                Logger::error('API error no retryable o último intento fallido', [
                    'httpCode' => $httpCode,
                    'attempt' => $attempt + 1,
                    'isRetryable' => $isRetryable,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

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

    private function send(
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
        array $generationOverrides = []
    ): array {
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

        if ($this->mediaResolution !== null) {
            $payload['mediaResolution'] = $this->mediaResolution;
        }

        if ($this->thinkingBudget !== null) {
            $payload['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }

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
}
