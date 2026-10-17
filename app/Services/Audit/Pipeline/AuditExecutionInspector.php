<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Lectura paginada de la fuente durable; los índices son reconstruibles. */
final class AuditExecutionInspector
{
    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function records(string $pattern, bool $hash = false): \Generator
    {
        $cursor = '0';
        $seen = [];
        do {
            [$cursor, $keys] = $this->redis->scanKeys($pattern, $cursor, 100);
            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $raw = $hash ? $this->redis->eval('if redis.call("TYPE",KEYS[1]).ok~="hash" then return nil end; return redis.call("HGET",KEYS[1],"record")', [$key]) : $this->redis->get($key);
                if ($raw === null) {
                    continue;
                }
                if (!is_string($raw)) {
                    throw new \RuntimeException('execution_record_invalid');
                }
                yield $key => ['raw' => $raw, 'value' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
            }
        } while ($cursor !== '0');
    }

    public function snapshot(): array
    {
        $out = ['audits' => [], 'jobs' => [], 'terminal_hashes' => [], 'tasks' => [],
            'persistence' => (new AuditPersistenceQueue($this->redis))->inventory()];
        foreach ($this->records('audit:*:state') as $key => $record) {
            $audit = $record['value'];
            if (!isset($audit['audit_id'], $audit['status'])) {
                throw new \RuntimeException('audit_snapshot_invalid');
            }
            if (AuditStateStore::isTerminalStatus($audit['status'])) {
                $out['terminal_hashes'][$key] = hash('sha256', $record['raw']);
            } else {
                $out['audits'][$audit['audit_id']] = ['key' => $key, 'job_id' => $audit['job_id'] ?? null,
                    'documents' => count($audit['documents'] ?? []), 'fingerprint' => hash('sha256', $record['raw'])];
            }
        }
        foreach ($this->records('job:*:state') as $key => $record) {
            $job = $record['value'];
            $total = (int) ($job['total'] ?? 0);
            $done = (int) ($job['done'] ?? 0);
            $failed = (int) ($job['failed'] ?? 0);
            if ($total < 0 || $done < 0 || $failed < 0 || $done + $failed > $total
                || count($job['audits'] ?? []) !== $total) {
                throw new \RuntimeException('job_totals_inconsistent');
            }
            if (in_array($job['status'], ['completed', 'completed_with_errors', 'failed'], true)) {
                $out['terminal_hashes'][$key] = hash('sha256', $record['raw']);
            } else {
                $out['jobs'][$job['job_id']] = ['key' => $key, 'total' => $total, 'done' => $done, 'failed' => $failed];
            }
        }
        foreach ($this->records('audit:task:v2:*', true) as $record) {
            $task = new AuditTask($record['value']);
            $out['tasks'][$task->id()] = ['stage' => $task->record['stage'], 'state' => $task->record['state'],
                'audit_id' => $task->record['audit_id'], 'migration_disposition' => $task->record['migration_disposition'] ?? null];
        }
        ksort($out['terminal_hashes']);
        return $out;
    }

    /** Reentrante: reconstruye solo claves del scheduler, con productores pausados. */
    public function reconcile(): array
    {
        if ($this->redis->get('audit:execution:paused') !== '1') {
            throw new \RuntimeException('execution_pause_required');
        }
        $this->reconcileCommitReceipts();
        do {
            $repaired = (new AuditTaskCommitter($this->redis))->repair();
        } while ($repaired > 0);
        $snapshot = $this->snapshot();
        $derived = [FairAuditTaskQueue::COUNTS => ['hash', []], FairAuditTaskQueue::ALL => ['set', []],
            SemanticEvaluationStore::PLANS => ['set', []], 'audit:semantic:v2:counts' => ['hash', []],
            AuditTaskCommitter::OUTBOX => ['zset', []]];
        $admitted = [];
        foreach ($this->records('audit:task:v2:*', true) as $taskKey => $stored) {
            $record = (new AuditTask($stored['value']))->record;
            $id = $record['task_id'];
            $admitted[] = $taskKey;
            if (($record['migration_disposition'] ?? '') === 'rolled_back') {
                continue;
            }
            $base = FairAuditTaskQueue::base($record['stage'], $record['lane']);
            $scope = $record['scope'];
            $state = $record['state'];
            $derived[FairAuditTaskQueue::ALL][1][] = $id;
            $field = $record['stage'] . ':' . $record['lane'] . ':' . $state;
            $derived[FairAuditTaskQueue::COUNTS][1][$field] = ($derived[FairAuditTaskQueue::COUNTS][1][$field] ?? 0) + 1;
            if (in_array($state, ['completed', 'failed'], true)) {
                continue;
            }
            $refKey = 'audit:execution:refs:' . $record['audit_id'];
            $derived[$refKey] ??= ['set', []];
            $derived[$refKey][1][] = 'task:' . $id;
            $derived[$base . ':members'] ??= ['set', []];
            if (!in_array($scope, $derived[$base . ':members'][1], true)) {
                $derived[$base . ':members'][1][] = $scope;
                $derived[$base . ':jobs'] ??= ['list', []];
                $derived[$base . ':jobs'][1][] = $scope;
            }
            $derived[$base . ':scope_counts'] ??= ['hash', []];
            $derived[$base . ':scope_counts'][1][$scope] = ($derived[$base . ':scope_counts'][1][$scope] ?? 0) + 1;
            if ($state === 'pending') {
                $derived[$base . ':job:' . $scope . ':pending'] ??= ['zset', []];
                $derived[$base . ':job:' . $scope . ':pending'][1][$id] = $record['ready_sequence'];
            } elseif ($state === 'delayed') {
                $derived[$base . ':delayed'] ??= ['zset', []];
                $derived[$base . ':delayed'][1][$id] = $record['due_at_ms'];
            } elseif (in_array($state, ['active', 'committing'], true)) {
                $derived[$base . ':active'] ??= ['hash', []];
                if (isset($derived[$base . ':active'][1][$scope])) {
                    throw new \RuntimeException('multiple_active_scope_tasks');
                }
                $derived[$base . ':active'][1][$scope] = $id;
                $derived[$base . ':leases'] ??= ['zset', []];
                $derived[$base . ':leases'][1][$id] = $record['lease_until_ms'];
            }
            if (in_array($state, ['pending', 'delayed'], true)) {
                $derived[$base . ':waiting'] ??= ['zset', []];
                $derived[$base . ':waiting'][1][$id] = $record['enqueued_at_ms'];
            }
        }
        foreach ($this->records('audit:semantic:v2:*', true) as $key => $stored) {
            $plan = $stored['value'];
            $derived[SemanticEvaluationStore::PLANS][1][] = $key;
            $state = $plan['state'];
            $derived['audit:semantic:v2:counts'][1][$state] = ($derived['audit:semantic:v2:counts'][1][$state] ?? 0) + 1;
            if (in_array($state, ['planned', 'waiting', 'ready'], true) && ($plan['migration_disposition'] ?? '') !== 'rolled_back') {
                $refKey = 'audit:execution:refs:' . $plan['audit_id'];
                $derived[$refKey] ??= ['set', []];
                $derived[$refKey][1][] = 'plan:' . $plan['plan_id'];
            }
        }
        foreach ($this->records('audit:outbox:v2:*', true) as $key => $stored) {
            $state = $this->redis->eval('return redis.call("HGET",KEYS[1],"state")', [$key]);
            if ($state === 'sent') {
                continue;
            }
            $effect = $stored['value'];
            $derived[AuditTaskCommitter::OUTBOX][1][$effect['effect_id']] = 0;
            $refKey = 'audit:execution:refs:' . $effect['event']['audit_id'];
            $derived[$refKey] ??= ['set', []];
            $derived[$refKey][1][] = 'outbox:' . $effect['effect_id'];
        }
        // Se conservan secuencias y turnos existentes. No borrar registros de tareas.
        foreach ($this->recordsIndexKeys() as $key) {
            $derived[$key] ??= ['none', []];
        }
        foreach ($derived as $key => [$type, $values]) {
            $this->redis->eval(self::REPLACE_INDEX, [$key, 'audit:execution:paused'],
                [$type, json_encode($values, JSON_THROW_ON_ERROR)]);
        }
        foreach ($admitted as $key) {
            $this->redis->eval('if redis.call("GET",KEYS[2])~="1" then return redis.error_reply("execution_pause_required") end; return redis.call("HSET",KEYS[1],"admitted","1")', [$key, 'audit:execution:paused']);
        }
        return ['tasks' => count($snapshot['tasks']), 'indexes' => count($derived)];
    }

    private function reconcileCommitReceipts(): void
    {
        $pending = [];
        foreach ($this->records('audit:commit:v2:*') as $key => $stored) {
            $receipt = $stored['value'];
            if ($receipt['state'] === 'committing') {
                $task = new AuditTask($receipt['task']);
                $reference = $task->id() . ':' . $task->record['fence'];
                if ($key !== 'audit:commit:v2:' . $reference) {
                    throw new \RuntimeException('commit_receipt_identity_invalid');
                }
                $pending[] = $reference;
            }
        }
        $old = $this->redis->eval('return redis.call("SMEMBERS",KEYS[1])', [AuditTaskCommitter::PENDING]);
        foreach ($old as $reference) {
            if ($this->redis->get('audit:commit:v2:' . $reference) !== null) {
                continue;
            }
            $id = explode(':', $reference, 2)[0];
            $task = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->get($id);
            if ($task === null || $task->record['state'] !== 'active') {
                throw new \RuntimeException('commit_receipt_missing');
            }
            // Sin recibo todavía no pudo ejecutarse ninguna transición de negocio.
        }
        $this->redis->eval(self::REPLACE_INDEX, [AuditTaskCommitter::PENDING, 'audit:execution:paused'],
            ['set', json_encode($pending, JSON_THROW_ON_ERROR)]);
    }

    private function recordsIndexKeys(): \Generator
    {
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scanKeys('audit:*', $cursor, 100);
            foreach ($keys as $key) {
                if ($key === FairAuditTaskQueue::COUNTS || $key === FairAuditTaskQueue::ALL
                    || str_starts_with($key, 'audit:execution:refs:')
                    || (str_starts_with($key, 'audit:tasks:v2:')
                        && preg_match('/:(pending|jobs|members|active|leases|delayed|scope_counts|waiting)$/D', $key))) {
                    yield $key;
                }
            }
        } while ($cursor !== '0');
    }

    private const REPLACE_INDEX = <<<'LUA'
        if redis.call('GET',KEYS[2])~='1' then return redis.error_reply('execution_pause_required') end
        local values=cjson.decode(ARGV[2]); local kind=ARGV[1]
        redis.call('DEL',KEYS[1])
        if kind=='hash' then for k,v in pairs(values) do redis.call('HSET',KEYS[1],k,v) end
        elseif kind=='set' then for _,v in ipairs(values) do redis.call('SADD',KEYS[1],v) end
        elseif kind=='list' then for _,v in ipairs(values) do redis.call('RPUSH',KEYS[1],v) end
        elseif kind=='zset' then for k,v in pairs(values) do redis.call('ZADD',KEYS[1],v,k) end
        elseif kind~='none' then return redis.error_reply('execution_index_kind_invalid') end
        return 1
    LUA;
}
