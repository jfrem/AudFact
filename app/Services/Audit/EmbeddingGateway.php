<?php

namespace App\Services\Audit;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class EmbeddingGateway
{
    private const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';
    private const BATCH_ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';

    private const MAX_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 500;
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    private const MAX_BATCH_SIZE = 100;

    private Client $http;
    private string $apiKey;
    private string $model;

    /**
     * Configura el cliente HTTP y el modelo de embeddings de Gemini.
     *
     * @param  Client $http  Cliente HTTP compartido.
     * @param  string $apiKey  API key de Gemini.
     * @param  string|null $model  Modelo de embeddings; usa default si es null.
     * @return void
     */
    public function __construct(Client $http, string $apiKey, ?string $model = null)
    {
        $this->http = $http;
        $this->apiKey = $apiKey;
        $this->model = $model ?? 'gemini-embedding-001';
    }

    /**
     * Genera el vector de embedding para un texto individual.
     *
     * @param  string $text  Texto a vectorizar.
     * @return array<int, float|int> Vector numérico devuelto por Gemini.
     * @throws \RuntimeException Si la API responde sin vector válido.
     */
    public function embed(string $text): array
    {
        $url = sprintf(self::ENDPOINT_TEMPLATE, $this->model);

        $payload = [
            'model' => 'models/' . $this->model,
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ];

        $result = $this->sendWithRetry($url, $payload);
        $values = $result['embedding']['values'] ?? null;

        if (!is_array($values) || empty($values)) {
            throw new \RuntimeException('Embedding API: respuesta sin vector válido');
        }

        return $values;
    }

    /**
     * Genera embeddings en lote preservando el orden de los textos originales.
     *
     * @param  array<int, string> $texts  Textos a vectorizar.
     * @return array<int, array{text: string, vector: array<int, float|int>}> Vectores por texto.
     * @throws \RuntimeException Si la API falla luego de agotar reintentos.
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // Deduplicar textos para minimizar llamadas API
        $uniqueTexts = array_values(array_unique($texts));
        $chunks = array_chunk($uniqueTexts, self::MAX_BATCH_SIZE);
        $vectorMap = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            $url = sprintf(self::BATCH_ENDPOINT_TEMPLATE, $this->model);

            $requests = array_map(
                fn(string $text): array => [
                    'model' => 'models/' . $this->model,
                    'content' => [
                        'parts' => [['text' => $text]],
                    ],
                ],
                $chunk
            );

            $payload = ['requests' => $requests];

            Logger::info('Embedding batch request', [
                'chunk' => $chunkIndex + 1,
                'totalChunks' => count($chunks),
                'textsInChunk' => count($chunk),
            ]);

            $result = $this->sendWithRetry($url, $payload);
            $embeddings = $result['embeddings'] ?? [];

            if (count($embeddings) !== count($chunk)) {
                Logger::warning('Embedding batch: tamaño de respuesta no coincide', [
                    'expected' => count($chunk),
                    'received' => count($embeddings),
                ]);
            }

            foreach ($chunk as $i => $text) {
                $values = $embeddings[$i]['values'] ?? null;
                if (is_array($values) && !empty($values)) {
                    $vectorMap[$text] = $values;
                } else {
                    Logger::warning('Embedding batch: vector vacío para texto', [
                        'textPreview' => mb_substr($text, 0, 50),
                    ]);
                }
            }
        }

        // Preservar orden original incluyendo duplicados
        $result = [];
        foreach ($texts as $text) {
            $vector = $vectorMap[$text] ?? null;
            if ($vector !== null) {
                $result[] = ['text' => $text, 'vector' => $vector];
            }
        }

        return $result;
    }

    /**
     * Envía un payload a Gemini Embedding API con reintentos exponenciales.
     *
     * @param  string $url  Endpoint específico del modelo.
     * @param  array<string, mixed> $payload  Payload JSON para la API.
     * @return array<string, mixed> Respuesta JSON decodificada.
     * @throws \RuntimeException|\Throwable Si la API no responde correctamente.
     */
    private function sendWithRetry(string $url, array $payload): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->http->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
                    ],
                    'json' => $payload,
                ]);

                $bodyStr = (string) $response->getBody();
                $body = json_decode($bodyStr, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException(
                        'Embedding API: respuesta no JSON: ' . json_last_error_msg()
                    );
                }

                return $body ?? [];
            } catch (GuzzleException $e) {
                $lastException = $e;
                $httpCode = 0;
                $errorMessage = $e->getMessage();

                if ($e instanceof RequestException && $e->hasResponse()) {
                    $httpCode = $e->getResponse()->getStatusCode();
                    $errorBody = json_decode(
                        (string) $e->getResponse()->getBody(),
                        true
                    );
                    if (isset($errorBody['error']['message'])) {
                        $errorMessage = $errorBody['error']['message'];
                    }
                }

                $isRetryable = in_array($httpCode, self::RETRYABLE_HTTP_CODES, true);
                $isLastAttempt = $attempt === self::MAX_RETRIES - 1;

                if ($isRetryable && !$isLastAttempt) {
                    $delayMs = self::BASE_RETRY_DELAY_MS * (2 ** $attempt);

                    Logger::warning('Embedding API: error retryable', [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'delayMs' => $delayMs,
                        'error' => $errorMessage,
                    ]);

                    usleep($delayMs * 1000);
                    continue;
                }

                throw new \RuntimeException(
                    'Embedding API error: ' . $errorMessage,
                    $httpCode,
                    $e
                );
            }
        }

        throw $lastException ?? new \RuntimeException('Embedding API: error desconocido');
    }
}
