<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use App\Services\Audit\Pipeline\QuotaDeferredException;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\ResponseIADiskStore;
use App\Services\Audit\GeminiCallMetrics;
use Core\Env;
use Core\Logger;
use Core\RedisClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class GeminiGateway
{
    public const TASK_EXTRACTION = 'extraction';
    public const TASK_SEMANTIC_MATCH = 'semantic_match';

    private const MAX_API_RETRIES = 3;
    private const BASE_RETRY_DELAY_MS = 1000;
    private const RETRYABLE_HTTP_CODES = [429, 503, 500, 502, 504];

    // Circuit Breaker keys y estados
    private const CB_KEY_STATE = 'cb:gemini:state';
    private const CB_KEY_FAILS = 'cb:gemini:fails';
    private const CB_STATE_CLOSED = 'closed';
    private const CB_STATE_OPEN   = 'open';

    private Client $http;
    private string $apiKey;
    private GeminiConfig $config;
    private RedisClient $cbRedis;
    private ResponseIADiskStore $diskStore;
    private AuditLane $lane;
    private ?AuditTask $task = null;
    private ?GeminiQuotaBudget $quota = null;
    private ?FairAuditTaskQueue $taskQueue = null;

    public function __construct(
        Client $http,
        string $apiKey,
        GeminiConfig $config,
        ?RedisClient $cbRedis = null,
        ?ResponseIADiskStore $diskStore = null,
        string|AuditLane $lane = AuditLane::ALL
    ) {
        $this->http = $http;
        $this->apiKey = $apiKey;
        $this->config = $config;
        $this->cbRedis = $cbRedis ?? RedisClient::getInstance();
        $this->diskStore = $diskStore ?? new ResponseIADiskStore();
        $this->lane = $lane instanceof AuditLane ? $lane : AuditLane::fromString($lane);
    }

    /**
     * Factory estático: construye un GeminiGateway con configuración de .env.
     */
    public static function create(string|AuditLane|null $lane = null): self
    {
        Env::load();

        $laneEnum = $lane instanceof AuditLane
            ? $lane
            : AuditLane::fromString((string) ($lane ?? Env::get('AUDIT_WORKER_LANE', AuditLane::ALL->value)));

        // Selección de API Key según carril con fallback a la clave común
        $apiKey = match ($laneEnum) {
            AuditLane::PRIORITY => (string) Env::get('GEMINI_API_KEY_PRIORITY', ''),
            AuditLane::BATCH => (string) Env::get('GEMINI_API_KEY_BATCH', ''),
            AuditLane::ALL => '',
        };
        if ($apiKey === '') {
            $apiKey = (string) Env::get('GEMINI_API_KEY', '');
        }

        if ($apiKey === '') {
            $laneSuffix = !$laneEnum->isAll() ? " (carril: {$laneEnum->value})" : '';
            throw new RuntimeException("GEMINI_API_KEY no configurada{$laneSuffix}");
        }

        $config = GeminiConfig::fromEnv();
        $timeout = (int) Env::get('GEMINI_TIMEOUT', 300);

        return new self(
            http: new Client(['timeout' => $timeout, 'connect_timeout' => 10]),
            apiKey: $apiKey,
            config: $config,
            lane: $laneEnum
        );
    }


    /**
     * Envía un request de Structured Output a la API de Gemini (generationConfig.responseSchema).
     *
     * @param  string $prompt  Texto del prompt del usuario.
     * @param  array<int, array<string, mixed>> $files  Archivos inline (PDFs rasterizados, etc).
     * @param  string $systemInstruction  Instrucción de sistema.
     * @param  array<string, mixed> $responseSchema  Schema JSON para Structured Output.
     * @param  string $taskType Perfil explícito de tarea Gemini.
     * @param  array<string, mixed> $generationOverrides  Sobrecargas de generación.
     * @param  array<string, mixed>|null $debugContext  Metadata de trazabilidad.
     * @return array<string, mixed>
     */
    public function sendWithStructuredOutput(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $responseSchema,
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $ctx = array_merge($debugContext ?? [], [
            'task_type' => $taskType,
            'source'    => 'GeminiGateway::sendWithStructuredOutput',
            'mode'      => 'structured_output',
        ]);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->config->model}:generateContent";
        $payload = $this->buildStructuredOutputPayload($prompt, $files, $systemInstruction, $responseSchema, $taskType, $generationOverrides);
        if ($this->task !== null) {
            return $this->executeTaskRequest($payload, $ctx);
        }
        $this->cbCheck();

        return $this->executePost($url, $payload, $ctx, 'Structured Output');
    }

    public function model(): string
    {
        return $this->config->model;
    }

    /** Ciclo de vida por tarea; los gateways legacy conservan su contrato. */
    public function withTask(AuditTask $task, GeminiQuotaBudget $quota, FairAuditTaskQueue $queue): self
    {
        $gateway = clone $this;
        $gateway->task = $task;
        $gateway->quota = $quota;
        $gateway->taskQueue = $queue;
        return $gateway;
    }

    public function requestBody(string $prompt, array $files, string $system, array $schema, string $taskType, array $overrides = []): array
    {
        return $this->buildStructuredOutputPayload($prompt, $files, $system, $schema, $taskType, $overrides);
    }

    /**
     * Envía un body pre-construido (vía requestBody()) con routing automático V1/V2.
     *
     * V1 (sin task): HTTP POST directo vía executePost().
     * V2 (con task): routing a través del task queue vía executeTaskRequest().
     *
     * @param  array<string, mixed> $body     Payload completo listo para la API.
     * @param  array<string, mixed> $context  Metadata de trazabilidad y task_type.
     * @return array<string, mixed>
     */
    public function sendPrebuiltBody(array $body, array $context): array
    {
        $ctx = array_merge($context, [
            'source' => $context['source'] ?? 'GeminiGateway::sendPrebuiltBody',
            'mode'   => 'prebuilt_body',
        ]);
        if ($this->task !== null) {
            return $this->executeTaskRequest($body, $ctx);
        }
        $this->cbCheck();
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->config->model}:generateContent";
        return $this->executePost($url, $body, $ctx, 'Prebuilt Body');
    }

    public function countInputTokens(array $body, array $requestContext): array
    {
        $response = $this->executeSingleOperation('countTokens', ['generateContentRequest' => ['model' => 'models/' . $this->config->model] + $body], $requestContext);
        if (!is_int($response['totalTokens'] ?? null) || $response['totalTokens'] < 1) {
            throw new GeminiOperationException('count_tokens');
        }
        return $response;
    }

    public function sendSingleAttempt(array $body, array $requestContext): array
    {
        return $this->executeSingleOperation('generateContent', $body, $requestContext);
    }

    public function executeTaskRequest(array $body, array $context): array
    {
        if ($this->task === null || $this->quota === null || $this->taskQueue === null) {
            throw new \LogicException('gemini_task_required');
        }
        $hash = hash('sha256', json_encode([$this->config->model, $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $latest = $this->taskQueue->get($this->task->id());
        $artifact = $latest?->record['stage_artifact'] ?? [];
        $artifact = is_array($artifact) ? $artifact : [];
        foreach (($artifact['quota_finishes'] ?? []) as $attemptId => $usage) {
            $this->quota->finish($attemptId, $usage);
        }
        if (is_array($artifact['responses'][$hash] ?? null)) {
            return $artifact['responses'][$hash];
        }
        $artifact = is_array($artifact) ? $artifact : [];
        $deadline = (int) $this->task->record['lease_until_ms'] - AuditExecutionConfig::LEASE_MS + AuditExecutionConfig::DEADLINE_MS;
        $tokens = $artifact['token_counts'][$hash] ?? null;
        foreach ($tokens === null ? ['count_tokens', 'generate_content'] : ['generate_content'] as $operation) {
            if ($operation === 'generate_content' && $this->task->record['stage'] === AuditTaskStage::SEMANTIC->value
                && $tokens > AuditExecutionConfig::MAX_INPUT_TOKENS) {
                throw new \DomainException('semantic_input_too_large');
            }
            $grant = $this->quota->reserve(['task' => $this->task->record, 'operation' => $operation,
                'tokens' => $operation === 'count_tokens' ? 0 : (int) ceil($tokens * 1.05), 'body_hash' => $hash]);
            if ($grant['status'] === 'DEFERRED') {
                throw new QuotaDeferredException((int) $grant['due_at_ms'], $grant['reason']);
            }
            $requestContext = $context + ['grant' => $grant, 'deadline_ms' => $deadline, 'body_hash' => $hash,
                'task_id' => $this->task->id(), 'operation' => $operation];
            try {
                $result = $operation === 'count_tokens' ? $this->countInputTokens($body, $requestContext) : $this->sendSingleAttempt($body, $requestContext);
            } catch (\Throwable $error) {
                $usage = ['error' => true, 'http_status' => (int) $error->getCode(),
                    'duration_ms' => $error instanceof GeminiOperationException ? $error->durationMs : null,
                    'unknown' => $error instanceof GeminiOperationException && $error->unknownResult];
                $artifact['attempt_ids'][] = $grant['attempt_id'];
                $artifact['quota_finishes'][$grant['attempt_id']] = $usage;
                $this->taskQueue->saveArtifact($this->task, $artifact);
                try {
                    $this->quota->finish($grant['attempt_id'], $usage);
                } catch (\Throwable $finishError) {
                    Logger::warning('Gemini attempt finish pending', ['attempt_id' => $grant['attempt_id'],
                        'error_class' => get_class($finishError)]);
                }
                throw $error;
            }
            $artifact['attempt_ids'][] = $grant['attempt_id'];
            $usage = ($result['usageMetadata'] ?? []) + ['duration_ms' => $result['X-Audit-Metrics']['duration_ms'] ?? null];
            $artifact['quota_finishes'][$grant['attempt_id']] = $usage;
            if ($operation === 'count_tokens') {
                $tokens = $result['totalTokens'];
                $artifact['token_counts'][$hash] = $tokens;
            } else {
                $artifact['responses'][$hash] = $result;
            }
            if (!$this->taskQueue->saveArtifact($this->task, $artifact)) {
                throw new \RuntimeException('gemini_task_stale');
            }
            // Primero conservar la respuesta. Si finish falla, el siguiente turno
            // completa el recibo y reutiliza el resultado sin otro HTTP.
            $this->quota->finish($grant['attempt_id'], $usage);
        }
        return $artifact['responses'][$hash];
    }

    /** Marca el contrato fallido; conserva respuestas permanentes para reanudar sin repetir HTTP. */
    public function invalidateTaskResponses(bool $discardResponses = true): void
    {
        if ($this->task === null || $this->taskQueue === null) {
            return;
        }
        $artifact = $this->taskQueue->get($this->task->id())?->record['stage_artifact'] ?? [];
        foreach (($artifact['responses'] ?? []) as $response) {
            $id = $response['X-Audit-Metrics']['attempt_id'] ?? null;
            if (is_string($id)) {
                $this->quota?->failContract($id);
            }
        }
        if ($discardResponses) {
            $artifact['responses'] = [];
        }
        if (!$this->taskQueue->saveArtifact($this->task, $artifact)) {
            throw new \RuntimeException('gemini_task_stale');
        }
    }

    private function executeSingleOperation(string $method, array $body, array $context): array
    {
        $now = $this->taskQueue?->nowMs() ?? (int) floor(microtime(true) * 1000);
        $grant = $context['grant'] ?? [];
        if (($grant['status'] ?? '') !== 'GRANTED' || ($grant['body_hash'] ?? '') !== ($context['body_hash'] ?? null)
            || (int) ($grant['expires_at_ms'] ?? 0) <= $now || (int) ($context['deadline_ms'] ?? 0) <= $now) {
            throw new \RuntimeException('gemini_grant_required');
        }
        $timeout = min((int) Env::get('GEMINI_TIMEOUT', 300), max(0.001, ((int) $context['deadline_ms'] - $now) / 1000));
        if ($method === 'countTokens') {
            $timeout = min(20, $timeout);
        }
        $start = hrtime(true);
        try {
            $response = $this->http->post('https://generativelanguage.googleapis.com/v1beta/models/' . $this->config->model . ':' . $method, [
                'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $this->apiKey], 'json' => $body,
                'timeout' => $timeout, 'connect_timeout' => min(10, $timeout), 'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            throw new GeminiOperationException($context['operation'], durationMs: (int) ((hrtime(true) - $start) / 1000000), unknownResult: true);
        }
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            $header = trim($response->getHeaderLine('Retry-After'));
            $retry = ctype_digit($header) ? (int) $header * 1000 : max(0, ((strtotime($header) ?: 0) * 1000) - $now);
            $reason = null;
            if ($code === 400 && ($context['task_type'] ?? '') === self::TASK_EXTRACTION) {
                $message = strtolower((string) (json_decode((string) $response->getBody(), true)['error']['message'] ?? ''));
                $reason = match (true) {
                    str_contains($message, 'no pages') => \App\Services\Audit\Pipeline\DocumentRejectionReason::EMPTY_PDF_NO_PAGES,
                    str_contains($message, 'corrupt') => \App\Services\Audit\Pipeline\DocumentRejectionReason::CORRUPTED_DOCUMENT,
                    str_contains($message, 'could not be decoded'), str_contains($message, 'failed to decode') => \App\Services\Audit\Pipeline\DocumentRejectionReason::GEMINI_DECODE_FAILURE,
                    str_contains($message, 'unsupported file'), str_contains($message, 'unsupported media'), str_contains($message, 'unsupported format') => \App\Services\Audit\Pipeline\DocumentRejectionReason::UNSUPPORTED_MIME,
                    default => null,
                };
            }
            throw new GeminiOperationException($context['operation'], $code, $retry, $reason, (int) ((hrtime(true) - $start) / 1000000));
        }
        try {
            $result = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GeminiOperationException($context['operation'], $code, durationMs: (int) ((hrtime(true) - $start) / 1000000));
        }
        if (!is_array($result)) {
            throw new GeminiOperationException($context['operation'], $code, durationMs: (int) ((hrtime(true) - $start) / 1000000));
        }
        $result['X-Audit-Metrics'] = GeminiCallMetrics::fromResponse($result, (int) ((hrtime(true) - $start) / 1000000),
            $context + ['request_id' => $grant['request_id'], 'attempt_id' => $grant['attempt_id'],
                'operation' => $context['operation'], 'execution_version' => 2]);
        return $result;
    }

    /**
     * Ejecuta una petición POST a la API de Gemini con manejo de reintentos y Circuit Breaker.
     *
     * @param  array<string, mixed> $payload
     * @param  array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function executePost(string $url, array $payload, array $ctx, string $modeLabel): array
    {
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
                $this->cbRecordSuccess();

                $bodyStr = (string) $res->getBody();
                $body = json_decode($bodyStr, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException(
                        "Respuesta no JSON de Gemini {$modeLabel}: " . json_last_error_msg(),
                        0
                    );
                }

                $this->saveDebugLog($payload, $body ?? ['error' => 'cuerpo_vacio'], $ctx, 'success');

                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $body['X-Audit-Metrics'] = GeminiCallMetrics::fromResponse($body ?? [], $durationMs, $ctx);
                return $body ?? [];
            } catch (\RuntimeException $e) {
                $lastException = $e;
                $httpCode = (int) $e->getCode();

                if ($this->shouldRetry($httpCode, $attempt)) {
                    Logger::warning("{$modeLabel} API error retryable", [
                        'httpCode' => $httpCode,
                        'attempt' => $attempt + 1,
                        'delayMs' => $this->retryDelayMs($attempt),
                    ]);
                    $this->applyRetryDelay($attempt);
                    continue;
                }

                $this->saveDebugLog($payload, ['error' => $e->getMessage()], $ctx, 'runtime_error');
                $this->cbRecordFailure($httpCode);
                throw $e;
            } catch (GuzzleException $e) {
                $lastException = $e;
                [$httpCode, $errorMessage] = $this->extractGuzzleError($e);

                if ($this->shouldRetry($httpCode, $attempt)) {
                    $this->applyRetryDelay($attempt);
                    continue;
                }

                $this->saveDebugLog($payload, ['error' => $errorMessage], $ctx, 'http_error');
                if ($httpCode !== 400) {
                    $this->cbRecordFailure($httpCode);
                }
                throw new \RuntimeException("Error HTTP Gemini {$modeLabel}: " . $errorMessage, $httpCode, $e);
            }
        }

        $this->saveDebugLog($payload, ['error' => "Error desconocido en Gemini {$modeLabel}"], $ctx, 'unknown_error');
        $this->cbRecordFailure(0);
        throw $lastException ?? new \RuntimeException("Error desconocido en Gemini {$modeLabel}");
    }

    // ─── Circuit Breaker (inlined) ──────────────────────────────

    public function getLane(): string
    {
        return $this->lane->value;
    }

    public function getLaneEnum(): AuditLane
    {
        return $this->lane;
    }

    private function cbStateKey(): string
    {
        return !$this->lane->isAll()
            ? "cb:gemini:{$this->lane->value}:state"
            : self::CB_KEY_STATE;
    }

    private function cbFailsKey(): string
    {
        return !$this->lane->isAll()
            ? "cb:gemini:{$this->lane->value}:fails"
            : self::CB_KEY_FAILS;
    }

    /**
     * Verifica el estado del circuito antes de realizar una llamada.
     *
     * @throws \RuntimeException Si el circuito está abierto.
     */
    private function cbCheck(): void
    {
        if (!$this->cbRedis->isAvailable()) {
            return;
        }

        try {
            $state = $this->cbRedis->get($this->cbStateKey()) ?? self::CB_STATE_CLOSED;
        } catch (\Core\RedisUnavailableException $e) {
            return;
        }

        if ($state === self::CB_STATE_OPEN) {
            $ttl = $this->cbRedis->ttl($this->cbStateKey());
            Logger::warning("Circuit Breaker ABIERTO [carril: {$this->lane->value}] — request rechazado sin llamar API", [
                'cooldownRestante' => $ttl,
                'lane'             => $this->lane->value,
            ]);
            throw new \RuntimeException(
                "Circuit Breaker abierto [carril: {$this->lane->value}]: API Gemini temporalmente no disponible. Reintentar en " . max($ttl, 0) . 's',
                503
            );
        }
    }

    private function cbRecordSuccess(): void
    {
        if (!$this->cbRedis->isAvailable()) {
            return;
        }

        $this->cbRedis->del($this->cbStateKey());
        $this->cbRedis->del($this->cbFailsKey());
    }

    private function cbRecordFailure(int $httpCode): void
    {
        if (!$this->cbRedis->isAvailable()) {
            return;
        }

        $threshold = (int) Env::get('CB_GEMINI_THRESHOLD', 3);
        $cooldown  = (int) Env::get('CB_GEMINI_COOLDOWN', 60);

        $fails = $this->cbRedis->incr($this->cbFailsKey(), $cooldown * 2);

        if ($fails !== null && $fails >= $threshold) {
            $this->cbRedis->set($this->cbStateKey(), self::CB_STATE_OPEN, $cooldown);

            Logger::critical("Circuit Breaker Gemini ABIERTO [carril: {$this->lane->value}]", [
                'alert_type'         => 'circuit_breaker_open',
                'lane'               => $this->lane->value,
                'fallosConsecutivos' => $fails,
                'threshold'          => $threshold,
                'cooldownSeconds'    => $cooldown,
                'httpCode'           => $httpCode,
            ]);
        } else {
            Logger::info("Circuit Breaker [carril: {$this->lane->value}]: fallo registrado", [
                'lane'           => $this->lane->value,
                'fallosActuales' => $fails,
                'threshold'      => $threshold,
                'httpCode'       => $httpCode,
            ]);
        }
    }

    // ─── Retry ──────────────────────────────────────────────────

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

    // ─── Error Extraction ───────────────────────────────────────

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


    /**
     * @param  array<int, array<string, mixed>> $files
     * @param  array<string, mixed> $responseSchema
     * @param  string $taskType
     * @param  array<string, mixed> $generationOverrides
     * @return array<string, mixed>
     */
    private function buildStructuredOutputPayload(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $responseSchema,
        string $taskType,
        array $generationOverrides = []
    ): array {
        $this->assertTaskProfile($taskType, $files);
        $generationConfig = $this->config->toGenerationConfig(
            $generationOverrides,
            $taskType === self::TASK_EXTRACTION
        );
        $generationConfig['responseMimeType'] = 'application/json';
        $generationConfig['responseSchema'] = self::normalizeSchemaProperties($responseSchema);

        return $this->buildBaseEnvelope(
            prompt: $prompt,
            files: $files,
            systemInstruction: $systemInstruction,
            generationConfig: $generationConfig
        );
    }

    /**
     * Construye las partes de contenido (texto de prompt + archivos adjuntos inline) para la API de Gemini.
     *
     * @param  string $prompt
     * @param  array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function buildContentParts(string $prompt, array $files): array
    {
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

        return $parts;
    }

    /**
     * Construye la estructura envoltorio base compartida del payload de Gemini.
     *
     * @param  string $prompt
     * @param  array<int, array<string, mixed>> $files
     * @param  string $systemInstruction
     * @param  array<string, mixed> $generationConfig
     * @return array<string, mixed>
     */
    private function buildBaseEnvelope(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $generationConfig
    ): array {
        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => $this->buildContentParts($prompt, $files),
            ]],
            'generationConfig' => $generationConfig,
            'safetySettings' => $this->getSafetySettings(),
        ];
    }

    /**
     * Normaliza recursivamente el árbol de tools/schema para Gemini.
     *
     * La API de Gemini exige que `"properties"` sea un JSON object (`{}`),
     * pero el contrato pasa por Redis Streams (json_encode → json_decode($raw, true))
     * que convierte `{}` en `[]` (array vacío PHP). json_encode codifica `[]` como
     * un JSON array, no como un JSON object, provocando un 400.
     *
     * Este método recorre el árbol y convierte cada `"properties" => []` en
     * `"properties" => new \stdClass()` que se serializa correctamente como `{}`.
     */
    private static function normalizeSchemaProperties(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        // Si la key "properties" existe y es un array vacío, convertir a stdClass
        if (array_key_exists('properties', $data) && $data['properties'] === []) {
            $data['properties'] = new \stdClass();
        }

        // Recorrer recursivamente todos los valores del array
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalizeSchemaProperties($value);
            }
        }

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function assertTaskProfile(string $taskType, array $files): void
    {
        if ($taskType === self::TASK_EXTRACTION) {
            if ($files === []) {
                throw new RuntimeException('Perfil Gemini extraction requiere al menos un archivo');
            }
            return;
        }

        if ($taskType === self::TASK_SEMANTIC_MATCH) {
            if ($files !== []) {
                throw new RuntimeException('Perfil Gemini semantic_match no acepta archivos');
            }
            return;
        }

        throw new RuntimeException("Perfil Gemini no soportado: {$taskType}");
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

    /**
     * @param  array<string, mixed> $requestPayload
     * @param  array<string, mixed> $responseBody
     * @param  array<string, mixed> $context
     */
    private function saveDebugLog(array $requestPayload, array $responseBody, array $context, string $status): void
    {
        try {
            $this->diskStore->persist($requestPayload, $responseBody, array_merge($context, [
                'status' => $status,
                'lane'   => $this->lane->value,
            ]));
        } catch (\Throwable $e) {
            Logger::warning('Fallo inesperado al persistir responseIA', [
                'error'     => $e->getMessage(),
                'disDetNro' => (string) ($context['dis_det_nro'] ?? ''),
                'lane'      => $this->lane->value,
            ]);
        }
    }

    private function logApiQuotaHeaders(\Psr\Http\Message\ResponseInterface $response): void
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
            Logger::warning("Gemini API [carril: {$this->lane->value}]: cuota baja", [
                'lane'        => $this->lane->value,
                'remaining'   => $remaining,
                'limit'       => $limit,
                'reset'       => $headers['reset'],
                'umbral20pct' => $threshold,
            ]);
        } else {
            Logger::info("Gemini API [carril: {$this->lane->value}]: cuota", [
                'lane'      => $this->lane->value,
                'remaining' => $headers['remaining'],
                'limit'     => $headers['limit'],
            ]);
        }
    }
}
