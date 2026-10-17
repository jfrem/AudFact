<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final readonly class AuditTask
{
    public function __construct(public array $record)
    {
        AuditTaskStage::from((string) ($record['stage'] ?? ''));
        AuditTaskState::from((string) ($record['state'] ?? ''));
        if (AuditLane::fromString((string) ($record['lane'] ?? ''))->isAll()
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($record['task_id'] ?? '')) !== 1
            || !is_int($record['generation'] ?? null) || $record['generation'] < 1
            || !is_int($record['fence'] ?? null) || $record['fence'] < 0) {
            throw new \InvalidArgumentException('task_record_invalid');
        }
        AuditEvent::fromArray($record['original_event']);
    }

    public static function fromEvent(AuditTaskStage $stage, AuditEvent $event): self
    {
        if ($event->auditId === null || !in_array($event->eventType, $stage->eventTypes(), true)) {
            throw new \InvalidArgumentException('task_event_invalid');
        }
        $lane = AuditEventPublisher::isPriorityEvent($event) ? AuditLane::PRIORITY : AuditLane::BATCH;
        $generation = (int) ($event->payload['generation'] ?? 1);
        $pairIds = $event->payload['pair_ids'] ?? [];
        if ($stage === AuditTaskStage::SEMANTIC) {
            if (!is_array($pairIds) || $pairIds === [] || !array_is_list($pairIds)) {
                throw new \InvalidArgumentException('semantic_task_pairs_invalid');
            }
            sort($pairIds, SORT_STRING);
        }
        $identity = $stage === AuditTaskStage::SEMANTIC
            ? ['audit-task-v2', $stage->value, $lane->value, $event->payload['plan_id'] ?? '', $generation,
                $event->payload['purpose'] ?? '', $event->payload['wave'] ?? 0, $pairIds]
            : ['audit-task-v2', $stage->value, $lane->value, $event->eventId, $generation];
        return new self([
            'schema_version' => 2, 'task_id' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'stage' => $stage->value, 'lane' => $lane->value,
            'scope' => $event->jobId === null ? 'single:' . $event->auditId : 'job:' . $event->jobId,
            'audit_id' => $event->auditId, 'document_id' => $event->documentId, 'job_id' => $event->jobId,
            'plan_id' => $event->payload['plan_id'] ?? null, 'state' => AuditTaskState::PENDING->value,
            'generation' => $generation, 'fence' => 0, 'operation' => 'count_tokens', 'operation_attempt' => 0,
            'recovery_count' => 0, 'ready_sequence' => 0, 'enqueued_at_ms' => 0, 'due_at_ms' => 0,
            'lease_owner' => null, 'lease_until_ms' => null, 'original_event' => $event->toArray(),
            'pair_ids' => $event->payload['pair_ids'] ?? [], 'stage_artifact' => null, 'last_error' => null,
        ]);
    }

    public function event(): AuditEvent
    {
        return AuditEvent::fromArray($this->record['original_event']);
    }

    public function id(): string
    {
        return $this->record['task_id'];
    }
}
