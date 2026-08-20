<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class AuditEventPublisher
{
    public const STREAM_INBOX_PRIORITY       = 'audit.inbox.priority';
    public const STREAM_INBOX_BATCH          = 'audit.inbox.batch';
    public const STREAM_DOCUMENTS_PRIORITY   = 'audit.documents.priority';
    public const STREAM_DOCUMENTS_BATCH      = 'audit.documents.batch';
    public const STREAM_PERSISTENCE_PRIORITY = 'audit.persistence.priority';
    public const STREAM_PERSISTENCE_BATCH    = 'audit.persistence.batch';
    public const STREAM_RESULTS_PRIORITY     = 'audit.results.priority';
    public const STREAM_RESULTS_BATCH        = 'audit.results.batch';
    public const STREAM_BATCH_INBOX          = 'audit.batch.inbox';

    public const GROUP_ORCHESTRATOR = 'orchestrator';
    public const GROUP_DOWNLOADERS  = 'downloaders';
    public const GROUP_EXTRACTORS   = 'extractors';
    public const GROUP_NORMALIZERS  = 'normalizers';
    public const GROUP_POLICY       = 'policy';
    public const GROUP_PERSISTENCE  = 'persistence';
    public const GROUP_BATCH        = 'batch-workers';

    private const DEFAULT_STREAM_MAXLEN = 100000;

    private RedisClient $redis;
    private ?int $streamMaxLen;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
        $raw = Env::get('AUDIT_STREAM_MAXLEN', (string) self::DEFAULT_STREAM_MAXLEN);
        $parsed = is_numeric($raw) ? (int) $raw : self::DEFAULT_STREAM_MAXLEN;
        $this->streamMaxLen = $parsed > 0 ? $parsed : null;
    }

    public function publish(AuditEvent $event): string
    {
        $stream = self::streamForEvent($event);

        return $this->publishTo($stream, $event);
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        if ($event->eventType !== AuditEvent::TYPE_DEAD_LETTER) {
            throw new \InvalidArgumentException('publishDeadLetter requiere evento de tipo dead_letter');
        }

        return $this->publishTo(self::dlqStream(), $event);
    }

    private function publishTo(string $stream, AuditEvent $event): string
    {
        try {
            $id = $this->redis->xAdd($stream, ['event' => $event->toJson()], $this->streamMaxLen);
        } catch (RedisUnavailableException $e) {
            Logger::error('AuditEventPublisher: Redis falló publicando evento', [
                'stream' => $stream,
                'event_type' => $event->eventType,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo publicar el evento en Redis', 0, $e);
        }

        if ($id === null) {
            Logger::error('AuditEventPublisher: Redis no disponible', [
                'stream' => $stream,
                'event_type' => $event->eventType,
            ]);
            throw new RuntimeException('Redis no disponible para publicar eventos');
        }

        Logger::info('AuditEventPublisher: evento publicado', [
            'stream' => $stream,
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'audit_id' => $event->auditId,
            'job_id' => $event->jobId,
            'document_id' => $event->documentId,
            'stream_id' => $id,
        ]);

        return $id;
    }

    public static function isPriorityEvent(AuditEvent $event): bool
    {
        if (($event->payload['source'] ?? '') === 'single') {
            return true;
        }

        if (($event->payload['is_priority'] ?? false) === true) {
            return true;
        }

        return false;
    }

    public static function streamForEvent(AuditEvent $event, ?bool $isPriority = null): string
    {
        $priority = $isPriority ?? self::isPriorityEvent($event);

        return self::streamForEventType($event->eventType, $priority);
    }

    public static function streamForEventType(string $eventType, bool $isPriority = false): string
    {
        return match ($eventType) {
            AuditEvent::TYPE_AUDIT_CREATED,
            AuditEvent::TYPE_BATCH_CREATED       => $isPriority ? self::STREAM_INBOX_PRIORITY : self::STREAM_INBOX_BATCH,
            AuditEvent::TYPE_DOCUMENT_REGISTERED,
            AuditEvent::TYPE_DOCUMENT_DOWNLOADED,
            AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            AuditEvent::TYPE_DOCUMENT_REJECTED,
            AuditEvent::TYPE_DOCUMENT_NORMALIZED => $isPriority ? self::STREAM_DOCUMENTS_PRIORITY : self::STREAM_DOCUMENTS_BATCH,
            AuditEvent::TYPE_AUDIT_COMPLETED,
            AuditEvent::TYPE_AUDIT_FAILED,
            AuditEvent::TYPE_BATCH_COMPLETED,
            AuditEvent::TYPE_BATCH_COMPLETED_ERR => $isPriority ? self::STREAM_RESULTS_PRIORITY : self::STREAM_RESULTS_BATCH,
            AuditEvent::TYPE_BATCH_REQUESTED     => self::STREAM_BATCH_INBOX,
            default => throw new \InvalidArgumentException("event_type sin stream asignado: {$eventType}"),
        };
    }

    public static function dlqStream(): string
    {
        $value = Env::get('AUDIT_DLQ_STREAM', 'audit.dlq');
        return is_string($value) && $value !== '' ? $value : 'audit.dlq';
    }
}
