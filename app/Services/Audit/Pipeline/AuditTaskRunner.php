<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\GeminiOperationException;
use Core\Logger;
use Core\RedisClient;

/** Un turno termina en confirmación, aplazamiento o cierre técnico durable. */
final class AuditTaskRunner
{
    private FairAuditTaskQueue $queue;
    private AuditTaskCommitter $committer;

    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
        $this->queue = new FairAuditTaskQueue($redis, $config);
        $this->committer = new AuditTaskCommitter($redis);
    }

    public function tick(AuditEventConsumer $worker, AuditLane $lane, string $owner): bool
    {
        $stage = $worker->taskStage();
        if ($stage === null) {
            throw new \LogicException('scheduled_stage_required');
        }
        if ($this->redis->get('audit:execution:paused') === '1') {
            return false;
        }
        $this->committer->repair();
        if ($stage === AuditTaskStage::ORCHESTRATION && (new AuditAdmissionGuard($this->redis, $this->config))->orchestrationAtCapacity()) {
            return false;
        }
        $task = $this->queue->claim($stage, $lane, $owner);
        if ($task === null) {
            return false;
        }
        try {
            $audit = (new AuditStateStore($this->redis))->getAudit($task->record['audit_id']);
            if ($audit !== null && AuditStateStore::isTerminalStatus((string) ($audit['status'] ?? ''))) {
                $this->committer->commit($task->record, AuditTaskEffects::empty($task));
                $this->cleanup($worker, $task, true);
                return true;
            }
            if ($task->record['recovery_count'] > 3) {
                throw new \DomainException('task_recovery_exhausted');
            }
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $effects = $worker->prepareTask($task);
                $effects['attempt_metrics'] = $this->attemptMetrics($task);
                $result = $this->committer->commit($task->record, $effects);
                if ($result === 'RETRY_PREPARE') {
                    $this->committer->repair();
                    continue;
                }
                if ($result === 'STALE') {
                    $this->committer->commit($task->record, AuditTaskEffects::empty($task));
                }
                $this->cleanup($worker, $task, $result !== 'STALE');
                return true;
            }
            $this->queue->defer($task, $this->queue->nowMs() + 100, 'commit_contention');
            $this->cleanup($worker, $task, false);
        } catch (QuotaDeferredException $error) {
            $this->queue->defer($task, $error->dueAtMs, $error->reason);
            $this->cleanup($worker, $task, false);
        } catch (\Throwable $error) {
            $permanent = $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                || ($error instanceof GeminiOperationException && in_array($error->httpCode, [400, 401, 403, 404], true));
            $operation = $error instanceof GeminiOperationException ? $error->operation : $task->record['operation'];
            $attempt = $operation === $task->record['operation'] ? (int) $task->record['operation_attempt'] : 0;
            if (!$permanent && $attempt < 2) {
                $delay = $attempt === 0 ? 1000 : 5000;
                $delay += (int) sprintf('%u', crc32($task->id() . ':' . $attempt)) % 251;
                $delay = max($delay, $error instanceof GeminiOperationException ? $error->retryAfterMs : 0);
                $this->queue->defer($task, $this->queue->nowMs() + $delay, 'task_operation_retry', true, $operation);
            } else {
                $effects = AuditTaskEffects::empty($task);
                $effects['attempt_metrics'] = $this->attemptMetrics($task);
                $payload = ['status' => 'failed', 'requires_manual_review' => true, 'detail_error' => 'audit_task_failed',
                    'completed_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv($this->queue->nowMs(), 1000)),
                    'failed_stage' => $stage->value, 'failed_event_type' => $task->event()->eventType, 'error_class' => get_class($error)];
                $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'fail_audit', null, $payload);
                $event = AuditTaskEffects::followUp($task, AuditEvent::TYPE_AUDIT_FAILED, $payload, $task->record['document_id'], 'failed');
                $effects['outbox'][] = AuditTaskEffects::event($task, $event, 'failed');
                $dlq = AuditTaskEffects::followUp($task, AuditEvent::TYPE_DEAD_LETTER, [
                    'failed_event_type' => $task->event()->eventType, 'failed_stage' => $stage->value,
                    'attempts' => $attempt + 1, 'last_error_code' => 'AUDIT_TASK_FAILED',
                    'last_error_message' => 'audit_task_failed', 'original_event' => $task->event()->toArray(),
                ], $task->record['document_id'], 'dead-letter');
                $effects['outbox'][] = AuditTaskEffects::event($task, $dlq, 'dead-letter');
                $this->committer->commit($task->record, $effects);
            }
            Logger::warning('Audit task operation failed', ['task_id' => $task->id(), 'stage' => $stage->value,
                'error_class' => get_class($error), 'operation_attempt' => $attempt + 1]);
            $this->cleanup($worker, $task, false);
        }
        return true;
    }

    private function cleanup(AuditEventConsumer $worker, AuditTask $task, bool $committed): void
    {
        try {
            $committed ? $worker->afterTaskCommitted($task) : $worker->afterTaskDeferred($task);
        } catch (\Throwable $error) {
            Logger::warning('Audit task cleanup failed', ['task_id' => $task->id(), 'error_class' => get_class($error)]);
        }
    }

    private function attemptMetrics(AuditTask $task): array
    {
        $stage = AuditTaskStage::from($task->record['stage']);
        if (!in_array($stage, [AuditTaskStage::EXTRACTION, AuditTaskStage::SEMANTIC], true)) {
            return [];
        }
        $cursor = '0';
        $out = [];
        do {
            [$cursor, $ids] = $this->redis->eval('return redis.call("SSCAN",KEYS[1],ARGV[1],"COUNT",100)',
                ['audit:gemini:v2:attempts:' . $task->record['audit_id']], [$cursor]);
            $cursor = (string) $cursor;
            foreach ($ids as $id) {
                $raw = $this->redis->get('gemini:attempt:v2:' . $id);
                if ($raw === null) {
                    throw new \RuntimeException('gemini_attempt_missing');
                }
                $row = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if ($row['task_id'] !== $task->id()) { continue; }
                $usage = $row['usage'] ?? [];
                $out[$id] = ['attempt_id' => $id, 'request_id' => $row['request_id'], 'task_id' => $task->id(),
                    'stage' => $row['stage'], 'operation' => $row['operation'], 'state' => $row['state'],
                    'duration_ms' => $usage['duration_ms'] ?? null, 'prompt_tokens' => $usage['promptTokenCount'] ?? null,
                    'output_tokens' => $usage['candidatesTokenCount'] ?? null, 'total_tokens' => $usage['totalTokenCount'] ?? null,
                    'provider_code' => $usage['http_status'] ?? null, 'reserved_input_tokens' => $row['reserved_input_tokens']];
            }
        } while ($cursor !== '0');
        return array_values($out);
    }
}
