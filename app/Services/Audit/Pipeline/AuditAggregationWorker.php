<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditStatusModel;
use RuntimeException;

final class AuditAggregationWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private BatchJobStore $jobStore;
    private AuditResultAggregator $aggregator;
    private AuditStatusModel $auditStatusModel;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?BatchJobStore $jobStore = null,
        ?AuditResultAggregator $aggregator = null,
        ?AuditStatusModel $auditStatusModel = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->jobStore = $jobStore ?? new BatchJobStore($this->redis);
        $this->aggregator = $aggregator ?? new AuditResultAggregator();
        $this->auditStatusModel = $auditStatusModel ?? new AuditStatusModel();
        $this->consumerName = $consumerName ?? ('aggregator-' . getmypid());
    }

    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_RESULTS;
    }

    protected function group(): string
    {
        return 'aggregator';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_RULES_EVALUATED) {
            return;
        }

        if ($event->auditId === null) {
            throw new RuntimeException('rules_evaluated sin audit_id');
        }

        $audit = $this->stateStore->getAudit($event->auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada para agregación final');
        }

        $aggregate = $this->aggregator->aggregate($audit, $event->payload);
        try {
            $persisted = $this->auditStatusModel->persistAuditResultWithAttachments(
                $aggregate['audit_result_data'],
                $aggregate['document_decisions']
            );
        } catch (\Throwable $e) {
            $this->handleFinalFailure($event, $aggregate, $e);
            throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $e);
        }

        if ($persisted === false) {
            $failure = new RuntimeException('persistAuditResultWithAttachments retornó false');
            $this->handleFinalFailure($event, $aggregate, $failure);
            throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $failure);
        }

        if (!$this->stateStore->completeAudit($event->auditId, $aggregate['completion_payload'])) {
            $latestAudit = $this->stateStore->getAudit($event->auditId);
            $currentStatus = is_array($latestAudit) ? (string) ($latestAudit['status'] ?? '') : '';
            if (in_array($currentStatus, [
                AuditStateStore::AUDIT_STATUS_COMPLETED,
                AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
                AuditStateStore::AUDIT_STATUS_ERROR,
                AuditStateStore::AUDIT_STATUS_FAILED,
            ], true)) {
                return;
            }

            throw new RuntimeException('No se pudo cerrar la auditoría en Redis después de persistir SQL');
        }

        if ($event->jobId !== null) {
            $this->jobStore->markAuditCompletedInJob($event->jobId, $event->auditId, $aggregate['final_status']);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_COMPLETED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: array_merge($aggregate['completion_payload'], [
                'audit_result_data' => $aggregate['audit_result_data'],
                'document_decisions' => $aggregate['document_decisions'],
                'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]),
            parentEventId: $event->eventId,
        ));

        if ($event->jobId !== null) {
            $this->publishBatchTerminalEventIfNeeded($event->jobId, $event->auditId, $event->eventId);
        }
    }

    /**
     * @param  array<string,mixed> $aggregate
     */
    private function handleFinalFailure(AuditEvent $event, array $aggregate, \Throwable $error): void
    {
        $failedPayload = [
            'status' => AuditStateStore::AUDIT_STATUS_FAILED,
            'requires_manual_review' => true,
            'detail_error' => $error->getMessage(),
            'failed_stage' => 'final_persistence',
            'audit_result' => $aggregate['completion_payload']['audit_result'] ?? null,
            'audit_result_data' => $aggregate['audit_result_data'] ?? null,
            'document_decisions' => $aggregate['document_decisions'] ?? [],
        ];

        $this->stateStore->completeAudit($event->auditId ?? '', $failedPayload);

        if ($event->jobId !== null && $event->auditId !== null) {
            $this->jobStore->markAuditCompletedInJob($event->jobId, $event->auditId, AuditStateStore::AUDIT_STATUS_FAILED);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_FAILED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: array_merge($failedPayload, ['failed_at' => gmdate('Y-m-d\TH:i:s\Z')]),
            parentEventId: $event->eventId,
        ));

        if ($event->jobId !== null && $event->auditId !== null) {
            $this->publishBatchTerminalEventIfNeeded($event->jobId, $event->auditId, $event->eventId);
        }
    }

    private function publishBatchTerminalEventIfNeeded(string $jobId, string $auditId, string $parentEventId): void
    {
        $job = $this->jobStore->getJob($jobId);
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

        if (!$this->jobStore->claimBatchTerminalEvent($jobId, $eventType)) {
            return;
        }

        $facNitSec = isset($job['fac_nit_sec']) ? (int) $job['fac_nit_sec'] : 0;
        $dateFrom = isset($job['date_from']) ? trim((string) $job['date_from']) : '';
        $dateTo = isset($job['date_to']) ? trim((string) $job['date_to']) : '';
        $normalizedDateTo = $dateTo !== '' ? $dateTo : null;
        if ($facNitSec > 0 && $dateFrom !== '') {
            $this->jobStore->releaseBatchSlot($facNitSec, $dateFrom, $normalizedDateTo);
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
}
