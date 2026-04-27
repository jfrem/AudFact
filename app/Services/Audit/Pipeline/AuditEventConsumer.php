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
    protected int $maxRetries;
    protected int $blockMs;

    private bool $stopRequested = false;

    public function __construct(
        ?RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
        $this->publisher = $publisher ?? new AuditEventPublisher($this->redis);

        $this->maxRetries = (int) Env::get('AUDIT_EVENT_MAX_RETRIES', 3);
        $this->blockMs = (int) Env::get('AUDIT_STREAM_BLOCK_MS', 5000);

        if ($this->maxRetries < 1) {
            $this->maxRetries = 1;
        }
        if ($this->blockMs < 100) {
            $this->blockMs = 100;
        }
    }

    abstract protected function stream(): string;

    abstract protected function group(): string;

    abstract protected function consumer(): string;

    abstract protected function handle(AuditEvent $event): void;

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
        $this->reclaimPending();

        $processed = 0;
        while (!$this->stopRequested) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

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
                    // NOGROUP during the running loop means the group was destroyed after
                    // startup — an extraordinary condition (Redis data loss, manual deletion).
                    // Silently recreating with '0' would re-expose historical stream messages
                    // and risk duplicate audit processing. Fail loudly instead.
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

    private function reclaimPending(): void
    {
        // Recupera mensajes del PEL de cualquier consumer que murió antes de hacer ACK.
        // Se ejecuta una vez al arrancar, antes del bucle principal.
        $minIdleMs = max($this->blockMs * 2, 30_000);
        $cursor = '0-0';

        do {
            $result = $this->redis->xAutoClaim(
                $this->stream(), $this->group(), $this->consumer(),
                $minIdleMs, $cursor, 10
            );

            $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
            foreach ($messages as $message) {
                $this->dispatchMessage($message);
            }

            $nextCursor = $result['next'] ?? '0-0';
            $cursor = (is_string($nextCursor) && $nextCursor !== '') ? $nextCursor : '0-0';
        } while ($cursor !== '0-0' && !$this->stopRequested);
    }

    private function ensureGroup(): void
    {
        // '0' instead of '$': new groups start from the beginning of the stream so events
        // published before the worker started are not silently skipped. BUSYGROUP (group
        // already exists) is handled inside xGroupCreate and still returns true without
        // moving the cursor. xGroupCreate throws RedisUnavailableException on any real error.
        $this->redis->xGroupCreate($this->stream(), $this->group(), '0');
    }

    /**
     * @param array{id:string,fields:array<string,string>} $message
     */
    private function dispatchMessage(array $message): void
    {
        $streamId = $message['id'];
        $rawEvent = $message['fields']['event'] ?? null;

        if (!is_string($rawEvent) || $rawEvent === '') {
            Logger::error('AuditEventConsumer: mensaje sin campo event', [
                'stream' => $this->stream(),
                'stream_id' => $streamId,
            ]);
            $this->redis->xAck($this->stream(), $this->group(), $streamId);
            return;
        }

        $decoded = json_decode($rawEvent, true);
        if (!is_array($decoded)) {
            Logger::error('AuditEventConsumer: event JSON inválido', [
                'stream_id' => $streamId,
                'raw' => substr($rawEvent, 0, 200),
            ]);
            $this->redis->xAck($this->stream(), $this->group(), $streamId);
            return;
        }

        try {
            $event = AuditEvent::fromArray($decoded);
        } catch (Throwable $e) {
            Logger::error('AuditEventConsumer: event estructura inválida', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
            ]);
            $this->redis->xAck($this->stream(), $this->group(), $streamId);
            return;
        }

        try {
            $this->handle($event);
            $this->redis->xAck($this->stream(), $this->group(), $streamId);
            $this->clearAttempts($event->eventId);
        } catch (Throwable $e) {
            $this->handleFailure($event, $streamId, $e);
        }
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
            $this->sendToDeadLetter($event, $streamId, $attempts, $error);
            $this->redis->xAck($this->stream(), $this->group(), $streamId);
            $this->clearAttempts($event->eventId);
        }
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
