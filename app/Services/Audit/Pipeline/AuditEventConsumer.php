<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\SqlServerOperationException;
use RuntimeException;
use Throwable;

abstract class AuditEventConsumer
{
    protected RedisClient $redis;
    protected AuditEventPublisher $publisher;
    private AuditStateStore $telemetryStateStore;
    protected int $maxRetries;
    protected int $blockMs;
    protected int $pendingReclaimIdleMs;
    protected int $pendingReclaimIntervalMs;
    private int $lastPendingReclaimNs = 0;

    private bool $stopRequested = false;

    public function __construct(
        ?RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?AuditStateStore $stateStore = null
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
        $this->publisher = $publisher ?? new AuditEventPublisher($this->redis);
        $this->telemetryStateStore = $stateStore ?? new AuditStateStore($this->redis);

        $this->maxRetries = (int) Env::get('AUDIT_EVENT_MAX_RETRIES', 3);
        $this->blockMs = (int) Env::get('AUDIT_STREAM_BLOCK_MS', 5000);

        if ($this->maxRetries < 1) {
            $this->maxRetries = 1;
        }
        if ($this->blockMs < 100) {
            $this->blockMs = 100;
        }

        $this->pendingReclaimIdleMs = self::positiveEnvInt(
            'AUDIT_PENDING_RECLAIM_IDLE_MS',
            max($this->blockMs * 120, 600_000),
            30_000
        );
        $this->pendingReclaimIntervalMs = self::positiveEnvInt(
            'AUDIT_PENDING_RECLAIM_INTERVAL_MS',
            max($this->blockMs * 2, 30_000),
            5_000
        );
        $this->leaseTtlSeconds = self::positiveEnvInt(
            'AUDIT_CONSUMER_LEASE_TTL_SECONDS',
            self::DEFAULT_LEASE_TTL_SECONDS,
            60
        );
    }

    public const DEFAULT_LEASE_TTL_SECONDS = 900;
    protected int $leaseTtlSeconds;
    protected ?string $currentProcessingEventId = null;
    protected ?string $currentLeaseToken = null;

    /**
     * @return array<int, string>
     */
    abstract protected function streams(): array;

    abstract protected function group(): string;

    abstract protected function consumer(): string;

    abstract protected function handle(AuditEvent $event): void;

    protected function afterTerminalFailure(AuditEvent $event, Throwable $error): void
    {
    }

    final public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
    }

    protected static function defaultConsumerName(string $role): string
    {
        $host = gethostname() ?: php_uname('n') ?: 'unknown-host';
        $host = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $host) ?: 'unknown-host';

        return sprintf('%s-%s-%d', $role, $host, getmypid());
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function run(int $maxEvents = 0): int
    {
        if (!$this->redis->isAvailable()) {
            throw new RuntimeException('Redis no disponible al iniciar consumer');
        }

        $this->ensureGroup();
        $processed = $this->reclaimPending($maxEvents);
        if ($maxEvents > 0 && $processed >= $maxEvents) {
            return $processed;
        }

        while (!$this->stopRequested) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($maxEvents > 0 && $processed >= $maxEvents) {
                break;
            }

            $remaining = $maxEvents > 0 ? $maxEvents - $processed : 0;
            $processed += $this->reclaimPendingIfDue($remaining);
            if ($maxEvents > 0 && $processed >= $maxEvents) {
                break;
            }

            try {
                $messages = $this->redis->xReadGroupMulti(
                    $this->group(),
                    $this->consumer(),
                    $this->streams(),
                    1,
                    $this->blockMs
                );
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'NOGROUP') !== false) {
                    throw new RuntimeException(
                        "Consumer group '{$this->group()}' desapareció en runtime en streams '" . implode(', ', $this->streams()) . "'. Requiere intervención manual.",
                        0,
                        $e
                    );
                }
                throw $e;
            }

            if ($messages === []) {
                continue;
            }

            foreach ($messages as $message) {
                $this->dispatchMessage($message);
                $processed++;
            }
        }

        return $processed;
    }

    private function reclaimPendingIfDue(int $maxMessages = 0): int
    {
        $now = hrtime(true);
        if ($this->lastPendingReclaimNs > 0) {
            $elapsedMs = (int) floor(($now - $this->lastPendingReclaimNs) / 1_000_000);
            if ($elapsedMs < $this->pendingReclaimIntervalMs) {
                return 0;
            }
        }

        return $this->reclaimPending($maxMessages);
    }

    private function reclaimPending(int $maxMessages = 0): int
    {
        if ($maxMessages < 0) {
            $maxMessages = 0;
        }

        $processed = 0;

        foreach ($this->streams() as $stream) {
            if ($stream === '') {
                continue;
            }

            $cursor = '0-0';
            do {
                $remaining = $maxMessages > 0 ? $maxMessages - $processed : 10;
                if ($maxMessages > 0 && $remaining <= 0) {
                    break 2;
                }

                $result = $this->redis->xAutoClaim(
                    $stream, $this->group(), $this->consumer(),
                    $this->pendingReclaimIdleMs, $cursor, min(10, $remaining)
                );

                $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
                foreach ($messages as $message) {
                    $message['stream'] = $stream;
                    $this->dispatchMessage($message);
                    $processed++;
                    if ($maxMessages > 0 && $processed >= $maxMessages) {
                        break 3;
                    }
                }

                $nextCursor = $result['next'] ?? '0-0';
                $cursor = (is_string($nextCursor) && $nextCursor !== '') ? $nextCursor : '0-0';
            } while ($cursor !== '0-0' && !$this->stopRequested);
        }

        $this->lastPendingReclaimNs = hrtime(true);

        return $processed;
    }

    private function ensureGroup(): void
    {
        foreach ($this->streams() as $stream) {
            if ($stream !== '') {
                $this->redis->xGroupCreate($stream, $this->group(), '0');
            }
        }
    }

    /**
     * @param array{id:string,fields:array<string,string>,stream?:string} $message
     */
    private function dispatchMessage(array $message): void
    {
        $streamId = $message['id'];
        $streamName = (string) ($message['stream'] ?? ($this->streams()[0] ?? ''));
        $event = $this->parseEventPayload($streamId, $message['fields']['event'] ?? null, $streamName);
        if ($event === null) {
            return;
        }

        $receivedAt = self::nowUtc();
        $publishedAt = self::resolvePublishedAt($streamId, $event->timestamp);
        $handleStart = hrtime(true);

        // Idempotencia y exclusión atómica de consumo con token propietario único (QUAL-009):
        $leaseToken = AuditEvent::uuidV4();
        $leaseStatus = $this->claimEventProcessingLease($event->eventId, $leaseToken);
        if ($leaseStatus === 'completed') {
            Logger::info('AuditEventConsumer: Evento duplicado omitido por deduplicación atómica', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'group' => $this->group(),
                'stream' => $streamName,
                'stream_id' => $streamId,
            ]);
            $this->ackMessage($streamName, $streamId);
            return;
        }

        if ($leaseStatus === 'processing') {
            Logger::warning('AuditEventConsumer: Evento en procesamiento concurrente por otra réplica; omitiendo ejecución duplicada', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'group' => $this->group(),
                'stream' => $streamName,
                'stream_id' => $streamId,
            ]);
            return;
        }

        $this->currentProcessingEventId = $event->eventId;
        $this->currentLeaseToken = $leaseToken;

        try {
            $this->handle($event);
            if (!$this->markEventCompleted($event->eventId, $leaseToken)) {
                throw new RuntimeException("AuditEventConsumer: lease ownership lost for event {$event->eventId}; unable to mark completed");
            }
            $handleDurationMs = self::elapsedMs($handleStart);
            $ackStart = hrtime(true);
            $this->ackMessage($streamName, $streamId);
            $ackDurationMs = self::elapsedMs($ackStart);
            $this->recordSuccessfulTelemetry(
                $event,
                $streamName,
                $streamId,
                $receivedAt,
                self::nowUtc(),
                $publishedAt,
                $handleDurationMs,
                $ackDurationMs
            );
            $this->clearAttempts($event->eventId);
        } catch (Throwable $e) {
            $this->recordFailedTelemetry(
                $event,
                $streamName,
                $streamId,
                $receivedAt,
                self::nowUtc(),
                $publishedAt,
                self::elapsedMs($handleStart),
                $e
            );
            $this->handleFailure($event, $streamName, $streamId, $e, $leaseToken);
        } finally {
            $this->currentProcessingEventId = null;
            $this->currentLeaseToken = null;
        }
    }

    private function parseEventPayload(string $streamId, mixed $rawEvent, ?string $streamName = null): ?AuditEvent
    {
        $stream = $streamName ?? ($this->streams()[0] ?? '');
        if (!is_string($rawEvent) || $rawEvent === '') {
            Logger::error('AuditEventConsumer: mensaje sin campo event', [
                'stream' => $stream,
                'stream_id' => $streamId,
            ]);
            $this->ackMessage($stream, $streamId);
            return null;
        }

        $decoded = json_decode($rawEvent, true);
        if (!is_array($decoded)) {
            Logger::error('AuditEventConsumer: event JSON inválido', [
                'stream' => $stream,
                'stream_id' => $streamId,
                'raw' => substr($rawEvent, 0, 200),
            ]);
            $this->ackMessage($stream, $streamId);
            return null;
        }

        try {
            return AuditEvent::fromArray($decoded);
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: event estructura inválida', [
                'stream' => $stream,
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
            ]);
            $this->ackMessage($stream, $streamId);
            return null;
        }
    }

    private function recordSuccessfulTelemetry(
        AuditEvent $event,
        string $streamName,
        string $streamId,
        string $receivedAt,
        string $finishedAt,
        ?\DateTimeImmutable $publishedAt,
        int $handleDurationMs,
        int $ackDurationMs
    ): void {
        $this->recordEventTelemetry($event, $streamName, $streamId, $receivedAt, $finishedAt, $publishedAt, [
            'status' => 'acked',
            'handle_duration_ms' => $handleDurationMs,
            'ack_duration_ms' => $ackDurationMs,
        ]);
    }

    private function recordFailedTelemetry(
        AuditEvent $event,
        string $streamName,
        string $streamId,
        string $receivedAt,
        string $finishedAt,
        ?\DateTimeImmutable $publishedAt,
        int $handleDurationMs,
        Throwable $error
    ): void {
        $this->recordEventTelemetry($event, $streamName, $streamId, $receivedAt, $finishedAt, $publishedAt, [
            'status' => 'failed',
            'handle_duration_ms' => $handleDurationMs,
            'ack_duration_ms' => 0,
            'error_class' => get_class($error),
        ]);
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function recordEventTelemetry(
        AuditEvent $event,
        string $streamName,
        string $streamId,
        string $receivedAt,
        string $finishedAt,
        ?\DateTimeImmutable $publishedAt,
        array $timing
    ): void {
        if ($event->auditId === null) {
            return;
        }

        $receivedAtDate = self::parseUtc($receivedAt);
        $queueWaitMs = ($publishedAt !== null && $receivedAtDate !== null)
            ? self::diffMs($publishedAt, $receivedAtDate)
            : 0;

        $payload = array_merge([
            'event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'stream' => $streamName,
            'stream_id' => $streamId,
            'group' => $this->group(),
            'consumer' => $this->consumer(),
            'document_id' => $event->documentId,
            'job_id' => $event->jobId,
            'event_timestamp' => $event->timestamp,
            'stream_published_at' => $publishedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'received_at' => $receivedAt,
            'finished_at' => $finishedAt,
            'queue_wait_ms' => $queueWaitMs,
        ], $timing);

        try {
            $this->telemetryStateStore->recordEventTelemetry($event->auditId, $payload);
        } catch (Throwable $telemetryError) {
            Logger::warning('AuditEventConsumer: no se pudo registrar telemetría del evento', [
                'event_id' => $event->eventId,
                'audit_id' => $event->auditId,
                'stream' => $streamName,
                'stream_id' => $streamId,
                'error' => $telemetryError->getMessage(),
            ]);
        }
    }

    private static function resolvePublishedAt(string $streamId, string $eventTimestamp): ?\DateTimeImmutable
    {
        $streamTimestamp = self::streamIdTimestamp($streamId);
        if ($streamTimestamp !== null) {
            return $streamTimestamp;
        }

        return self::parseUtc($eventTimestamp);
    }

    private static function streamIdTimestamp(string $streamId): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d+)-\d+$/', $streamId, $matches)) {
            return null;
        }

        $milliseconds = (int) $matches[1];
        $seconds = intdiv($milliseconds, 1000);
        $micros = ($milliseconds % 1000) * 1000;

        return \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $micros),
            new \DateTimeZone('UTC')
        ) ?: null;
    }

    private static function parseUtc(string $timestamp): ?\DateTimeImmutable
    {
        try {
            return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function elapsedMs(int $start): int
    {
        return max(0, (int) round((hrtime(true) - $start) / 1_000_000));
    }

    private static function diffMs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $diffUs = ((int) $to->format('U') - (int) $from->format('U')) * 1_000_000
            + ((int) $to->format('u') - (int) $from->format('u'));

        return max(0, (int) round($diffUs / 1000));
    }

    private static function positiveEnvInt(string $key, int $default, int $minimum): int
    {
        $value = (int) Env::get($key, $default);

        return max($minimum, $value);
    }

    protected function handleFailure(
        AuditEvent $event,
        string $streamName,
        string $streamId,
        Throwable $error,
        string $leaseToken = ''
    ): void {
        $attempts = $this->incrementAttempts($event->eventId);

        Logger::warning('AuditEventConsumer: fallo procesando evento', [
            'stream' => $streamName,
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'audit_id' => $event->auditId,
            'document_id' => $event->documentId,
            'stream_id' => $streamId,
            'attempts' => $attempts,
            'max_retries' => $this->maxRetries,
            'error_class' => get_class($error),
            'error_file' => $error->getFile(),
            'error_line' => $error->getLine(),
            'error' => $error->getMessage(),
        ]);

        $nonRetryable = $error instanceof \DomainException
            || $error instanceof \InvalidArgumentException
            || $error instanceof SqlServerOperationException
            || $error instanceof AttachmentDownloadException;

        if ($attempts >= $this->maxRetries || $nonRetryable) {
            // 1. DLQ confirmado primero (QUAL-011)
            $dlqPublished = $this->sendToDeadLetter($event, $streamName, $streamId, $attempts, $error, $leaseToken);
            if (!$dlqPublished) {
                Logger::critical('AuditEventConsumer: DLQ falló al publicar; mensaje retenido en PEL sin ACK', [
                    'event_id' => $event->eventId,
                    'stream' => $streamName,
                    'stream_id' => $streamId,
                    'attempts' => $attempts,
                    'error' => $error->getMessage(),
                ]);
                $this->releaseEventLease($event->eventId, $leaseToken);
                return;
            }

            // 2. Efectos terminales validados: auditoría, job y reserva (QUAL-011)
            $finalized = $this->finalizeDeadLetterAudit($event, $error, $leaseToken);
            if (!$finalized) {
                Logger::critical('AuditEventConsumer: finalización terminal de auditoría falló post-DLQ; mensaje retenido en PEL sin ACK', [
                    'event_id' => $event->eventId,
                    'audit_id' => $event->auditId,
                    'error' => $error->getMessage(),
                ]);
                $this->releaseEventLease($event->eventId, $leaseToken);
                return;
            }

            // 3. Hook terminal ANTES de deduplicación (QUAL-018), con idempotencia y ownership (QUAL-011)
            $hookKey = "terminal:hook:{$this->group()}:{$event->eventId}";
            try {
                $hookExecuted = $this->executeTerminalActionWithOwnership(
                    $hookKey,
                    $leaseToken,
                    function () use ($event, $error): bool {
                        $this->afterTerminalFailure($event, $error);
                        return true;
                    }
                );
                if (!$hookExecuted) {
                    Logger::warning('AuditEventConsumer: hook terminal en ejecución concurrente por otra réplica; reteniendo en PEL', [
                        'event_id' => $event->eventId,
                    ]);
                    $this->releaseEventLease($event->eventId, $leaseToken);
                    return;
                }
            } catch (Throwable $hookEx) {
                Logger::critical('AuditEventConsumer: afterTerminalFailure falló; reteniendo en PEL para reintento', [
                    'event_id' => $event->eventId,
                    'audit_id' => $event->auditId,
                    'hook_error' => $hookEx->getMessage(),
                    'original_error' => $error->getMessage(),
                ]);
                $this->releaseEventLease($event->eventId, $leaseToken);
                return;
            }

            // 4. Verificación de titularidad de lease / deduplicación ANTES de ACK (QUAL-009)
            $markedCompleted = false;
            try {
                $markedCompleted = $this->markEventCompleted($event->eventId, $leaseToken);
            } catch (Throwable $luaEx) {
                Logger::error('AuditEventConsumer: excepción en markEventCompleted en camino terminal', [
                    'event_id' => $event->eventId,
                    'error' => $luaEx->getMessage(),
                ]);
                $markedCompleted = false;
            }

            if (!$markedCompleted) {
                Logger::critical('AuditEventConsumer: lease ownership perdido en camino terminal; no se confirma ACK para preservar PEL', [
                    'event_id' => $event->eventId,
                    'lease_token' => $leaseToken,
                ]);
                // Conservar el PEL: NO ackMessage, NO clearAttempts
                return;
            }

            Logger::critical('Evento enviado a DLQ tras agotar reintentos', [
                'alert_type'  => 'dlq_event',
                'audit_id'    => $event->auditId,
                'event_type'  => $event->eventType,
                'stream'      => $streamName,
                'attempts'    => $attempts,
                'error'       => $error->getMessage(),
            ]);
            $this->ackMessage($streamName, $streamId);
            $this->clearAttempts($event->eventId);
        } else {
            // Reintento transitorio: liberar el lease para permitir que la siguiente entrega lo reclame
            $this->releaseEventLease($event->eventId, $leaseToken);
        }
    }

    /**
     * Reclama el lease de procesamiento atómico para un evento (QUAL-009).
     * Retorna 'acquired', 'processing' o 'completed'.
     */
    protected function claimEventProcessingLease(string $eventId, string $leaseToken): string
    {
        if ($eventId === '') {
            return 'acquired';
        }

        $key = "dedup:{$this->group()}:{$eventId}";
        $ttl = $this->leaseTtlSeconds;

        $lua = <<<'LUA'
local key = KEYS[1]
local leaseToken = ARGV[1]
local ttl = tonumber(ARGV[2])

local current = redis.call('GET', key)
if current == 'completed' then
    return 'completed'
end

if current and string.sub(current, 1, 11) == 'processing:' then
    return 'processing'
end

redis.call('SET', key, 'processing:' .. leaseToken, 'EX', ttl)
return 'acquired'
LUA;

        try {
            $result = $this->redis->eval($lua, [$key], [$leaseToken, (string) $ttl]);
            if (is_string($result) && $result !== '') {
                return $result;
            }
        } catch (Throwable) {
        }

        try {
            $current = $this->redis->get($key);
            if ($current === 'completed') {
                return 'completed';
            }
            if (is_string($current) && str_starts_with($current, 'processing:')) {
                return 'processing';
            }
            $acquired = $this->redis->setnx($key, "processing:{$leaseToken}", $ttl);
            return $acquired === true ? 'acquired' : 'processing';
        } catch (Throwable) {
            return 'processing';
        }
    }

    /**
     * Marca un evento como completado atómicamente si el token coincide (QUAL-009).
     */
    protected function markEventCompleted(string $eventId, string $leaseToken): bool
    {
        if ($eventId === '') {
            return true;
        }

        $key = "dedup:{$this->group()}:{$eventId}";
        $ttl = 86400; // Retención de 24h para deduplicación

        $lua = <<<'LUA'
local key = KEYS[1]
local leaseToken = ARGV[1]
local ttl = tonumber(ARGV[2])

local current = redis.call('GET', key)
if current == ('processing:' .. leaseToken) then
    redis.call('SET', key, 'completed', 'EX', ttl)
    return 1
end
return 0
LUA;

        try {
            $result = $this->redis->eval($lua, [$key], [$leaseToken, (string) $ttl]);
            if ($result !== false && $result !== null) {
                return (int) $result === 1;
            }
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: fallo eval Lua en markEventCompleted', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Renueva el TTL del lease atómicamente si y sólo si el token propietario coincide (QUAL-009).
     */
    public function renewEventLease(
        string $eventId,
        string $leaseToken,
        ?int $extensionSeconds = null
    ): bool {
        if ($eventId === '' || $leaseToken === '') {
            return false;
        }

        $ttl = $extensionSeconds ?? $this->leaseTtlSeconds;
        $key = "dedup:{$this->group()}:{$eventId}";

        $lua = <<<'LUA'
local key = KEYS[1]
local leaseToken = ARGV[1]
local newTtl = tonumber(ARGV[2])

local current = redis.call('GET', key)
if current == ('processing:' .. leaseToken) then
    redis.call('EXPIRE', key, newTtl)
    return 1
end
return 0
LUA;

        try {
            $result = $this->redis->eval($lua, [$key], [$leaseToken, (string) $ttl]);
            if ($result !== false && $result !== null) {
                return (int) $result === 1;
            }
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: fallo eval Lua en renewEventLease', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Indica si hay un lease activo registrado en el contexto de ejecución de este consumidor.
     */
    public function hasActiveLease(): bool
    {
        return $this->currentProcessingEventId !== null && $this->currentLeaseToken !== null;
    }

    /**
     * Renueva activamente el lease del evento actualmente en procesamiento por este consumidor (QUAL-009).
     *
     * @param int|null $extensionSeconds Segundos de extensión (null usa el TTL por defecto)
     * @return bool True si la renovación atómica tuvo éxito y el token sigue siendo propietario
     */
    public function renewActiveLease(?int $extensionSeconds = null): bool
    {
        if (!$this->hasActiveLease()) {
            return false;
        }

        return $this->renewEventLease(
            (string) $this->currentProcessingEventId,
            (string) $this->currentLeaseToken,
            $extensionSeconds
        );
    }

    /**
     * Valida si el lease actual sigue siendo válido y vigente en Redis (Fencing check).
     * Si no hay lease activo en el contexto actual (ej: invocación directa/unitaria), retorna true.
     *
     * @return bool True si la clave en Redis coincide exactamente con "processing:{$this->currentLeaseToken}" o no hay contexto
     */
    public function isCurrentLeaseValid(): bool
    {
        if ($this->currentProcessingEventId === null || $this->currentLeaseToken === null) {
            return true;
        }

        try {
            $key = "dedup:{$this->group()}:{$this->currentProcessingEventId}";
            $current = $this->redis->get($key);
            return is_string($current) && $current === "processing:{$this->currentLeaseToken}";
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Guarda de fencing: verifica que el lease siga perteneciendo a esta réplica antes de realizar
     * operaciones con efectos colaterales. Lanza RuntimeException si se perdió la propiedad (QUAL-009).
     *
     * @throws RuntimeException
     */
    public function ensureActiveLease(string $operationContext = ''): void
    {
        if (!$this->isCurrentLeaseValid()) {
            $opSuffix = $operationContext !== '' ? " antes de {$operationContext}" : '';
            throw new RuntimeException("AuditEventConsumer [{$this->group()}]: titularidad de lease perdida para evento {$this->currentProcessingEventId}{$opSuffix}");
        }
    }

    /**
     * Libera el lease de procesamiento con compare-and-delete atómico (QUAL-009).
     * Exige token no vacío para garantizar que nunca se elimine incondicionalmente el lease de otra réplica.
     */
    protected function releaseEventLease(string $eventId, string $leaseToken): bool
    {
        if ($eventId === '' || $leaseToken === '') {
            return false;
        }

        $key = "dedup:{$this->group()}:{$eventId}";

        $lua = <<<'LUA'
local key = KEYS[1]
local leaseToken = ARGV[1]

local current = redis.call('GET', key)
if current == ('processing:' .. leaseToken) then
    redis.call('DEL', key)
    return 1
end
return 0
LUA;

        try {
            $result = $this->redis->eval($lua, [$key], [$leaseToken]);
            if ($result !== false && $result !== null) {
                return (int) $result === 1;
            }
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: fallo eval Lua en releaseEventLease', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function finalizeDeadLetterAudit(AuditEvent $event, Throwable $error, string $leaseToken = ''): bool
    {
        if ($event->auditId === null) {
            return true;
        }

        // QUAL-011: Acción idempotente con ownership y cleanup fail-closed
        $finalizeKey = "terminal:finalized:{$event->auditId}:{$event->eventId}";

        return $this->executeTerminalActionWithOwnership(
            $finalizeKey,
            $leaseToken,
            function () use ($event, $error): bool {
                $stateStore = new AuditStateStore($this->redis);
                $jobStore = new BatchJobStore($this->redis);
                $audit = $stateStore->getAudit($event->auditId);
                if ($audit === null) {
                    return true;
                }

                $failedPayload = [
                    'status' => AuditStateStore::AUDIT_STATUS_FAILED,
                    'requires_manual_review' => true,
                    'detail_error' => $error->getMessage(),
                    'failed_stage' => static::class,
                    'failed_event_type' => $event->eventType,
                ];

                $completed = $stateStore->completeAudit($event->auditId, $failedPayload);
                if (!$completed) {
                    Logger::error('AuditEventConsumer: completeAudit retornó false durante finalización DLQ', [
                        'event_id' => $event->eventId,
                        'audit_id' => $event->auditId,
                    ]);
                    return false;
                }

                if ($event->jobId !== null) {
                    $jobMarked = $jobStore->markAuditCompletedInJob(
                        $event->jobId,
                        $event->auditId,
                        AuditStateStore::AUDIT_STATUS_FAILED,
                        0,
                        $failedPayload['failed_stage']
                    );
                    if (!$jobMarked) {
                        Logger::error('AuditEventConsumer: markAuditCompletedInJob retornó false durante finalización DLQ', [
                            'event_id' => $event->eventId,
                            'job_id' => $event->jobId,
                            'audit_id' => $event->auditId,
                        ]);
                        return false;
                    }
                    $this->publishBatchTerminalEventIfNeeded($jobStore, $event->jobId, $event->auditId, $event->eventId);
                }

                $released = $jobStore->releaseAuditReservationFromAudit($audit);
                if (!$released) {
                    Logger::error('AuditEventConsumer: releaseAuditReservationFromAudit retornó false durante finalización DLQ', [
                        'event_id' => $event->eventId,
                        'audit_id' => $event->auditId,
                    ]);
                    return false;
                }

                $this->publisher->publish(AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_FAILED,
                    auditId: $event->auditId,
                    jobId: $event->jobId,
                    documentId: $event->documentId,
                    payload: array_merge($failedPayload, ['failed_at' => gmdate('Y-m-d\TH:i:s\Z')]),
                    parentEventId: $event->eventId,
                ));

                return true;
            }
        );
    }

    /**
     * Publica el evento terminal del batch si el job ya llegó a estado terminal.
     */
    protected function publishBatchTerminalEventIfNeeded(
        BatchJobStore $jobStore,
        string $jobId,
        string $auditId,
        string $parentEventId
    ): void {
        $job = $jobStore->getJob($jobId);
        if ($job === null) {
            return;
        }

        $jobStatus = (string) ($job['status'] ?? '');
        $eventType = match ($jobStatus) {
            BatchJobStore::JOB_STATUS_COMPLETED => AuditEvent::TYPE_BATCH_COMPLETED,
            BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR => AuditEvent::TYPE_BATCH_COMPLETED_ERR,
            default => null,
        };

        if ($eventType === null) {
            return;
        }

        $claimToken = bin2hex(random_bytes(8));
        if (!$jobStore->claimBatchTerminalEvent($jobId, $eventType, $claimToken)) {
            return;
        }

        try {
            $this->publisher->publish(AuditEvent::create(
                eventType: $eventType,
                auditId: $auditId,
                jobId: $jobId,
                payload: [
                    'status' => $jobStatus,
                    'total' => (int) ($job['total'] ?? 0),
                    'done' => (int) ($job['done'] ?? 0),
                    'failed' => (int) ($job['failed'] ?? 0),
                ],
                parentEventId: $parentEventId,
            ));
            if (!$jobStore->confirmBatchTerminalEvent($jobId, $eventType, $claimToken)) {
                Logger::warning('AuditEventConsumer: confirm terminal falló (CAS perdido), evento ya publicado por otro proceso', [
                    'job_id' => $jobId,
                    'event_type' => $eventType,
                ]);
            }
        } catch (\Throwable $e) {
            $jobStore->releaseBatchTerminalEvent($jobId, $claimToken);
            Logger::error('AuditEventConsumer: falló publicación de evento terminal batch, claim liberado para reintento', [
                'job_id' => $jobId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function sendToDeadLetter(AuditEvent $event, string $streamName, string $streamId, int $attempts, Throwable $error, string $leaseToken = ''): bool
    {
        // QUAL-011: Acción idempotente con ownership y cleanup fail-closed
        $idempotencyKey = "dlq:sent:{$this->group()}:{$event->eventId}";

        return $this->executeTerminalActionWithOwnership(
            $idempotencyKey,
            $leaseToken,
            function () use ($event, $streamName, $streamId, $attempts, $error): bool {
                $deadLetterEventId = AuditEvent::deterministicUuidV4('dlq:' . $event->eventId);
                $deadLetter = AuditEvent::create(
                    eventType: AuditEvent::TYPE_DEAD_LETTER,
                    auditId: $event->auditId,
                    jobId: $event->jobId,
                    documentId: $event->documentId,
                    payload: [
                        'failed_event_type'  => $event->eventType,
                        'failed_stream'      => $streamName,
                        'failed_stage'       => static::class,
                        'failed_stream_id'   => $streamId,
                        'attempts'           => $attempts,
                        'last_error_code'    => self::errorCode($error),
                        'last_error_message' => $error->getMessage(),
                        'original_event'     => $event->toArray(),
                    ],
                    parentEventId: $event->eventId,
                    eventId: $deadLetterEventId,
                );

                try {
                    $dlqStreamId = $this->publisher->publishDeadLetter($deadLetter);
                    if (!is_string($dlqStreamId) || $dlqStreamId === '') {
                        Logger::error('AuditEventConsumer: publishDeadLetter retornó stream_id vacío', [
                            'event_id' => $event->eventId,
                        ]);
                        return false;
                    }

                    try {
                        $this->redis->hIncrBy('telemetry:async_metrics', 'terminal_failures', 1);
                    } catch (\Throwable $e) {
                        // Ignore telemetry errors
                    }

                    return true;
                } catch (\Throwable $e) {
                    Logger::error('AuditEventConsumer: no se pudo publicar dead_letter', [
                        'event_id' => $event->eventId,
                        'error' => $e->getMessage(),
                    ]);
                    return false;
                }
            }
        );
    }

    private const CLAIM_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current == 'completed' then return 2 end
        if current == false then
            redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
            return 1
        end
        if current == ARGV[1] then return 1 end
        return 0
    LUA;

    private const COMPLETE_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current ~= ARGV[1] then return 0 end
        redis.call('SET', KEYS[1], 'completed', 'EX', tonumber(ARGV[2]))
        return 1
    LUA;

    private const RELEASE_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current ~= ARGV[1] then return 0 end
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    /**
     * Ejecuta una acción terminal idempotente con ownership atómico via Lua (QUAL-003).
     *
     * @param string $key Clave de tracking en Redis
     * @param string $leaseToken Token propietario del worker
     * @param callable():bool $action Acción a ejecutar
     * @return bool True si ya estaba completada o si se completó exitosamente; false si falló o está ocupada por otra réplica.
     */
    private function executeTerminalActionWithOwnership(string $key, string $leaseToken, callable $action): bool
    {
        $claimToken = "processing:{$leaseToken}";

        // Reclamo atómico: 2=completado, 1=adquirido, 0=ocupado por otra réplica
        $claimResult = (int) $this->redis->eval(
            self::CLAIM_TERMINAL_ACTION_LUA,
            [$key],
            [$claimToken, 120]
        );

        if ($claimResult === 2) {
            return true; // Ya completada previamente (idempotente)
        }

        if ($claimResult === 0) {
            return false; // Ocupada por otra réplica — fail-closed, retener en PEL
        }

        try {
            $success = $action();
            if ($success) {
                // CAS: solo completar si sigo siendo el propietario
                $completed = (int) $this->redis->eval(
                    self::COMPLETE_TERMINAL_ACTION_LUA,
                    [$key],
                    [$claimToken, 86400]
                );
                return $completed === 1;
            }

            // Fail-closed: liberar claim solo si soy propietario
            $this->releaseTerminalActionClaim($key, $claimToken);
            return false;
        } catch (Throwable $e) {
            $this->releaseTerminalActionClaim($key, $claimToken);
            throw $e;
        }
    }

    private function releaseTerminalActionClaim(string $key, string $claimToken): void
    {
        try {
            $this->redis->eval(
                self::RELEASE_TERMINAL_ACTION_LUA,
                [$key],
                [$claimToken]
            );
        } catch (Throwable) {
            // Compensación de mejor esfuerzo — el TTL de 120s actúa como fallback
        }
    }

    private function incrementAttempts(string $eventId): int
    {
        $key = self::attemptsKey($eventId);
        $value = $this->redis->incr($key, 86400);

        try {
            $this->redis->hIncrBy('telemetry:async_metrics', 'retries', 1);
        } catch (\Throwable $e) {
            // Telemetría no debe frenar el flujo base
        }

        return $value ?? 1;
    }

    private function clearAttempts(string $eventId): void
    {
        $this->redis->del(self::attemptsKey($eventId));
    }

    private static function attemptsKey(string $eventId): string
    {
        return "event:{$eventId}:attempts";
    }

    private function ackMessage(string $stream, string $streamId): void
    {
        $this->redis->xAck($stream, $this->group(), $streamId);
    }

    private static function errorCode(Throwable $error): string
    {
        $code = $error->getCode();
        if (is_int($code) && $code !== 0) {
            return (string) $code;
        }
        $class = get_class($error);
        $short = substr($class, strrpos($class, '\\') + 1) ?: $class;
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $short) ?? 'UNKNOWN');
    }
}
