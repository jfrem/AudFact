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
    public const STREAM_INBOX       = 'audit.inbox';
    public const STREAM_DOCUMENTS   = 'audit.documents';
    public const STREAM_PERSISTENCE = 'audit.persistence:{queue}';
    public const STREAM_RESULTS     = 'audit.results';
    public const STREAM_BATCH_INBOX = 'audit.batch.inbox';

    private const STREAM_BY_TYPE = [
        AuditEvent::TYPE_AUDIT_CREATED       => self::STREAM_INBOX,
        AuditEvent::TYPE_BATCH_CREATED       => self::STREAM_INBOX,
        AuditEvent::TYPE_DOCUMENT_REGISTERED => self::STREAM_DOCUMENTS,
        AuditEvent::TYPE_DOCUMENT_DOWNLOADED => self::STREAM_DOCUMENTS,
        AuditEvent::TYPE_DOCUMENT_EXTRACTED  => self::STREAM_DOCUMENTS,
        AuditEvent::TYPE_DOCUMENT_REJECTED   => self::STREAM_DOCUMENTS,
        AuditEvent::TYPE_DOCUMENT_NORMALIZED => self::STREAM_DOCUMENTS,
        AuditEvent::TYPE_AUDIT_COMPLETED     => self::STREAM_RESULTS,
        AuditEvent::TYPE_AUDIT_FAILED        => self::STREAM_RESULTS,
        AuditEvent::TYPE_BATCH_COMPLETED     => self::STREAM_RESULTS,
        AuditEvent::TYPE_BATCH_COMPLETED_ERR => self::STREAM_RESULTS,
        AuditEvent::TYPE_BATCH_REQUESTED     => self::STREAM_BATCH_INBOX,
    ];

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function publish(AuditEvent $event): string
    {
        $stream = self::streamForEventType($event->eventType);

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
            $id = $this->redis->xAdd($stream, ['event' => $event->toJson()]);
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

    public static function streamForEventType(string $eventType): string
    {
        if (!isset(self::STREAM_BY_TYPE[$eventType])) {
            throw new \InvalidArgumentException("event_type sin stream asignado: {$eventType}");
        }

        return self::STREAM_BY_TYPE[$eventType];
    }

    public static function dlqStream(): string
    {
        $value = Env::get('AUDIT_DLQ_STREAM', 'audit.dlq');
        return is_string($value) && $value !== '' ? $value : 'audit.dlq';
    }
}
