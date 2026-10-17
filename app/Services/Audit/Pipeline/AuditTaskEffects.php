<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/** Efectos preparados por PHP; ninguna operación de negocio se ejecuta aquí. */
final class AuditTaskEffects
{
    public static function uuid(string $identity): string
    {
        $hex = hash('sha256', $identity);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    public static function followUp(AuditTask $task, string $type, array $payload, ?string $documentId, string $name): AuditEvent
    {
        $record = $task->event()->followUp($type, $payload, $documentId)->toArray();
        $record['event_id'] = self::uuid($task->id() . ':' . $task->record['generation'] . ':' . $name);
        $record['timestamp'] = $task->event()->timestamp;
        return AuditEvent::fromArray($record);
    }

    public static function empty(AuditTask $task): array
    {
        return ['schema_version' => 2, 'audit_id' => $task->record['audit_id'], 'document_id' => $task->record['document_id'],
            'source_fingerprint' => null, 'evaluation_base_revision' => null, 'state_transitions' => [],
            'semantic_changes' => [], 'attempt_metrics' => [], 'outbox' => []];
    }

    public static function transition(AuditTask $task, string $op, ?string $target, array $data, ?string $expected = null): array
    {
        return ['operation_id' => hash('sha256', json_encode([$task->id(), $task->record['generation'], $op, $target], JSON_THROW_ON_ERROR)),
            'op' => $op, 'expected_status' => $expected, 'target_id' => $target, 'data' => $data];
    }

    public static function event(AuditTask $task, AuditEvent $event, string $name): array
    {
        $destination = match ($event->eventType) {
            AuditEvent::TYPE_RULES_EVALUATED => 'persistence_queue',
            AuditEvent::TYPE_AUDIT_FAILED => 'failure_finalize',
            AuditEvent::TYPE_DEAD_LETTER => 'dead_letter',
            default => 'stream:' . AuditEventPublisher::streamForEvent($event),
        };
        $id = hash('sha256', json_encode([$task->id(), $task->record['generation'], $name, $event->auditId, $event->documentId], JSON_THROW_ON_ERROR));
        return ['effect_id' => $id, 'event' => $event->toArray(), 'destination' => $destination];
    }

    public static function validate(array $effects, AuditTask $task): void
    {
        $keys = array_keys(self::empty($task));
        if (array_diff(array_keys($effects), $keys) !== [] || array_diff($keys, array_keys($effects)) !== []
            || $effects['schema_version'] !== 2 || $effects['audit_id'] !== $task->record['audit_id']) {
            throw new \InvalidArgumentException('task_effects_invalid');
        }
        $ops = ['start_audit', 'patch_audit_context', 'set_documents_total', 'register_document', 'mark_extracted',
            'mark_rejected', 'mark_evaluated', 'patch_document', 'store_rules_evaluation', 'fail_audit', 'protect_active_ttl'];
        foreach ($effects['state_transitions'] as $transition) {
            if (!in_array($transition['op'] ?? '', $ops, true) || !is_array($transition['data'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($transition['operation_id'] ?? '')) !== 1
                || array_diff(array_keys($transition), ['operation_id', 'op', 'expected_status', 'target_id', 'data']) !== []) {
                throw new \InvalidArgumentException('task_transition_invalid');
            }
        }
        foreach ($effects['semantic_changes'] as $change) {
            if (!in_array($change['op'] ?? '', ['create_plan', 'resolve_pairs', 'consume_plan', 'fail_plan'], true)
                || !is_array($change['data'] ?? null) || !isset($change['plan_id'], $change['generation'])) {
                throw new \InvalidArgumentException('semantic_change_invalid');
            }
        }
        foreach ($effects['attempt_metrics'] as $metric) {
            if (!is_array($metric) || !AuditEvent::isUuidV4($metric['attempt_id'] ?? '')
                || ($metric['task_id'] ?? null) !== $task->id()
                || !in_array($metric['state'] ?? '', ['inflight', 'completed', 'failed', 'unknown'], true)) {
                throw new \InvalidArgumentException('task_attempt_metric_invalid');
            }
        }
        foreach ($effects['outbox'] as $effect) {
            $event = AuditEvent::fromArray($effect['event']);
            $expectedDestination = match ($event->eventType) {
                AuditEvent::TYPE_RULES_EVALUATED => 'persistence_queue',
                AuditEvent::TYPE_AUDIT_FAILED => 'failure_finalize',
                AuditEvent::TYPE_DEAD_LETTER => 'dead_letter',
                default => 'stream:' . AuditEventPublisher::streamForEvent($event),
            };
            if ($event->auditId !== $task->record['audit_id'] || preg_match('/^[a-f0-9]{64}$/D', $effect['effect_id']) !== 1
                || $effect['destination'] !== $expectedDestination) {
                throw new \InvalidArgumentException('task_outbox_invalid');
            }
        }
    }
}
