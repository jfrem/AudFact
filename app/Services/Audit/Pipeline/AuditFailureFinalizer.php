<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Cierre idempotente de identidad y job después del commit de fallo técnico. */
final class AuditFailureFinalizer
{
    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function finalize(AuditEvent $event): void
    {
        $audit = (new AuditStateStore($this->redis))->getAudit((string) $event->auditId);
        if ($audit === null || ($audit['status'] ?? '') !== 'failed') {
            throw new \RuntimeException('failed_audit_state_missing');
        }
        $jobs = new BatchJobStore($this->redis);
        if ($event->jobId !== null) {
            $jobs->markAuditCompletedInJob($event->jobId, (string) $event->auditId, 'failed', 0, (string) $event->payload['failed_stage']);
        }
        $jobs->releaseAuditReservationFromAudit($audit);
        // Publicar desde un outbox durable evita depender de la salida del worker.
        $publisher = new AuditEventPublisher($this->redis);
        $publisher->publishTerminalOnce($event);
        if ($event->jobId !== null) {
            $job = $jobs->getJob($event->jobId);
            $type = match ($job['status'] ?? '') {
                'completed' => AuditEvent::TYPE_BATCH_COMPLETED,
                'completed_with_errors' => AuditEvent::TYPE_BATCH_COMPLETED_ERR,
                default => null,
            };
            if ($type !== null) {
                $record = $event->followUp($type, ['status' => $job['status'], 'total' => $job['total'],
                    'done' => $job['done'], 'failed' => $job['failed']], null)->toArray();
                $record['event_id'] = AuditTaskEffects::uuid($event->jobId . ':' . $type);
                $publisher->publishTerminalOnce(AuditEvent::fromArray($record));
                $jobs->claimBatchTerminalEvent($event->jobId, $type);
            }
        }
    }
}
