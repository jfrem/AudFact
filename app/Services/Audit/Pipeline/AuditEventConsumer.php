<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;
use Throwable;

abstract class AuditEventConsumer
{
    protected RedisClient $redis;
    protected AuditEventPublisher $publisher;
    private AuditStateStore $telemetryStateStore;
    protected int $maxRetries;
    protected int $blockMs;
    private int $pendingReclaimIdleMs;
    private int $pendingReclaimIntervalMs;
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
    }

    abstract protected function stream(): string;

    abstract protected function group(): string;

    abstract protected function consumer(): string;

    abstract protected function handle(AuditEvent $event): void;

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
                $messages = $this->redis->xReadGroup(
                    $this->group(),
                    $this->consumer(),
                    $this->stream(),
                    1,
                    $this->blockMs
                );
            } catch (\Exception $e) {
                if (stripos($e->getMessage(), 'NOGROUP') !== false) {
                    throw new RuntimeException(
                        "Consumer group '{$this->group()}' desapareció en runtime en stream '{$this->stream()}'. Requiere intervención manual.",
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

        $cursor = '0-0';
        $processed = 0;

        do {
            $remaining = $maxMessages > 0 ? $maxMessages - $processed : 10;
            if ($maxMessages > 0 && $remaining <= 0) {
                break;
            }

            $result = $this->redis->xAutoClaim(
                $this->stream(), $this->group(), $this->consumer(),
                $this->pendingReclaimIdleMs, $cursor, min(10, $remaining)
            );

            $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
            foreach ($messages as $message) {
                $this->dispatchMessage($message);
                $processed++;
                if ($maxMessages > 0 && $processed >= $maxMessages) {
                    break 2;
                }
            }

            $nextCursor = $result['next'] ?? '0-0';
            $cursor = (is_string($nextCursor) && $nextCursor !== '') ? $nextCursor : '0-0';
        } while ($cursor !== '0-0' && !$this->stopRequested);

        $this->lastPendingReclaimNs = hrtime(true);

        return $processed;
    }

    private function ensureGroup(): void
    {
        $this->redis->xGroupCreate($this->stream(), $this->group(), '0');
    }

    /**
     * @param array{id:string,fields:array<string,string>} $message
     */
    private function dispatchMessage(array $message): void
    {
        $streamId = $message['id'];
        $event = $this->parseEventPayload($streamId, $message['fields']['event'] ?? null);
        if ($event === null) {
            return;
        }

        $receivedAt = self::nowUtc();
        $publishedAt = self::resolvePublishedAt($streamId, $event->timestamp);
        $handleStart = hrtime(true);

        try {
            $this->handle($event);
            $handleDurationMs = self::elapsedMs($handleStart);
            $ackStart = hrtime(true);
            $this->ackMessage($streamId);
            $ackDurationMs = self::elapsedMs($ackStart);
            $this->recordSuccessfulTelemetry(
                $event,
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
                $streamId,
                $receivedAt,
                self::nowUtc(),
                $publishedAt,
                self::elapsedMs($handleStart),
                $e
            );
            $this->handleFailure($event, $streamId, $e);
        }
    }

    private function parseEventPayload(string $streamId, mixed $rawEvent): ?AuditEvent
    {
        if (!is_string($rawEvent) || $rawEvent === '') {
            Logger::error('AuditEventConsumer: mensaje sin campo event', [
                'stream' => $this->stream(),
                'stream_id' => $streamId,
            ]);
            $this->ackMessage($streamId);
            return null;
        }

        $decoded = json_decode($rawEvent, true);
        if (!is_array($decoded)) {
            Logger::error('AuditEventConsumer: event JSON inválido', [
                'stream_id' => $streamId,
                'raw' => substr($rawEvent, 0, 200),
            ]);
            $this->ackMessage($streamId);
            return null;
        }

        try {
            return AuditEvent::fromArray($decoded);
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: event estructura inválida', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
            ]);
            $this->ackMessage($streamId);
            return null;
        }
    }

    private function recordSuccessfulTelemetry(
        AuditEvent $event,
        string $streamId,
        string $receivedAt,
        string $finishedAt,
        ?\DateTimeImmutable $publishedAt,
        int $handleDurationMs,
        int $ackDurationMs
    ): void {
        $this->recordEventTelemetry($event, $streamId, $receivedAt, $finishedAt, $publishedAt, [
            'status' => 'acked',
            'handle_duration_ms' => $handleDurationMs,
            'ack_duration_ms' => $ackDurationMs,
        ]);
    }

    private function recordFailedTelemetry(
        AuditEvent $event,
        string $streamId,
        string $receivedAt,
        string $finishedAt,
        ?\DateTimeImmutable $publishedAt,
        int $handleDurationMs,
        Throwable $error
    ): void {
        $this->recordEventTelemetry($event, $streamId, $receivedAt, $finishedAt, $publishedAt, [
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
            'stream' => $this->stream(),
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

    private function handleFailure(AuditEvent $event, string $streamId, Throwable $error): void
    {
        $attempts = $this->incrementAttempts($event->eventId);

        Logger::warning('AuditEventConsumer: fallo procesando evento', [
            'stream' => $this->stream(),
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

        if ($attempts >= $this->maxRetries) {
            $this->finalizeDeadLetterAudit($event, $error);
            $this->sendToDeadLetter($event, $streamId, $attempts, $error);
            $this->ackMessage($streamId);
            $this->clearAttempts($event->eventId);
        }
    }

    private function finalizeDeadLetterAudit(AuditEvent $event, Throwable $error): void
    {
        if ($event->auditId === null) {
            return;
        }

        try {
            $stateStore = new AuditStateStore($this->redis);
            $jobStore = new BatchJobStore($this->redis);
            $audit = $stateStore->getAudit($event->auditId);
            if ($audit === null) {
                return;
            }

            $failedPayload = [
                'status' => AuditStateStore::AUDIT_STATUS_FAILED,
                'requires_manual_review' => true,
                'detail_error' => $error->getMessage(),
                'failed_stage' => static::class,
                'failed_event_type' => $event->eventType,
            ];

            $stateStore->completeAudit($event->auditId, $failedPayload);

            if ($event->jobId !== null) {
                $jobStore->markAuditCompletedInJob(
                    $event->jobId,
                    $event->auditId,
                    AuditStateStore::AUDIT_STATUS_FAILED,
                    0
                );
                $this->publishBatchTerminalEventIfNeeded($jobStore, $event->jobId, $event->auditId, $event->eventId);
            }

            $jobStore->releaseAuditReservationFromAudit($audit);

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_FAILED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                documentId: $event->documentId,
                payload: array_merge($failedPayload, ['failed_at' => gmdate('Y-m-d\TH:i:s\Z')]),
                parentEventId: $event->eventId,
            ));
        } catch (Throwable $finalizeError) {
            Logger::error('AuditEventConsumer: no se pudo cerrar auditoría antes de DLQ', [
                'event_id' => $event->eventId,
                'audit_id' => $event->auditId,
                'error' => $finalizeError->getMessage(),
            ]);
        }
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

        if ($eventType === null || !$jobStore->claimBatchTerminalEvent($jobId, $eventType)) {
            return;
        }

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
    }

    private function sendToDeadLetter(AuditEvent $event, string $streamId, int $attempts, Throwable $error): void
    {
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $event->auditId,
            jobId: $event->jobId,
            documentId: $event->documentId,
            payload: [
                'failed_event_type'  => $event->eventType,
                'failed_stream'      => $this->stream(),
                'failed_stage'       => static::class,
                'failed_stream_id'   => $streamId,
                'attempts'           => $attempts,
                'last_error_code'    => self::errorCode($error),
                'last_error_message' => $error->getMessage(),
                'original_event'     => $event->toArray(),
            ],
            parentEventId: $event->eventId,
        );

        try {
            $this->publisher->publishDeadLetter($deadLetter);
            
            try {
                $this->redis->hIncrBy('telemetry:async_metrics', 'terminal_failures', 1);
            } catch (\Throwable $e) {
                // Ignore telemetry errors
            }
        } catch (RuntimeException $e) {
            Logger::error('AuditEventConsumer: no se pudo publicar dead_letter', [
                'event_id' => $event->eventId,
                'error' => $e->getMessage(),
            ]);
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

    private function ackMessage(string $streamId): void
    {
        $this->redis->xAck($this->stream(), $this->group(), $streamId);
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
