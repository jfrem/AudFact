<?php

namespace App\Services\Audit;

use Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Cliente HTTP para la Gemini Embedding API.
 *
 * Vectoriza textos usando el modelo gemini-embedding-001 para
 * comparaciones semánticas en la Fase 2 del pipeline determinista.
 *
 * @version 4.0
 */
class EmbeddingGateway
{
    private const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';
    private const BATCH_ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';

    private const MAX_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 500;
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    /**
     * Máximo de textos por llamada batch (límite de la API).
     */
    private const MAX_BATCH_SIZE = 100;

    private Client $http;
    private string $apiKey;
    private string $model;

    public function __construct(Client $http, string $apiKey, ?string $model = null)
    {
        $this->http = $http;
        $this->apiKey = $apiKey;
        $this->model = $model ?? 'gemini-embedding-001';
    }

    /**
     * Vectoriza un texto individual.
     *
     * @param string $text Texto a vectorizar
     * @return array<float> Vector de embedding
     * @throws \RuntimeException Si la API falla después de reintentos
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
     * Vectoriza múltiples textos en una sola llamada batch.
     *
     * Respeta el límite de MAX_BATCH_SIZE textos por llamada.
     * Si hay más textos, los divide en chunks y concatena resultados.
     *
     * @param array<string> $texts Lista de textos a vectorizar
     * @return array<array{text: string, vector: array<float>}> Textos con sus vectores
     * @throws \RuntimeException Si la API falla
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

        // Reconstruir resultado en el orden original (incluyendo duplicados)
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
     * Envía request con reintentos y backoff exponencial.
     *
     * @param string $url URL del endpoint
     * @param array $payload Cuerpo JSON
     * @return array Respuesta decodificada
     * @throws \RuntimeException Si agota reintentos
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
