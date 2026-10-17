<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Intake y reparación independientes de los procesos que esperan HTTP. */
final class AuditExecutionMaintenance
{
    private FairAuditTaskQueue $queue;
    private AuditTaskCommitter $committer;
    private AuditTaskOutbox $outbox;
    private array $reclaimCursors = [];
    private bool $groupsReady = false;
    private array $gcCursors = [];
    private int $lastGcMs = 0;
    private ?GeminiQuotaBudget $quota = null;

    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
        $this->queue = new FairAuditTaskQueue($redis, $config);
        $this->committer = new AuditTaskCommitter($redis);
        $this->outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
    }

    public static function stages(): array
    {
        return [
            AuditTaskStage::ORCHESTRATION->value => ['group' => AuditEventPublisher::GROUP_ORCHESTRATOR,
                'streams' => [AuditEventPublisher::STREAM_INBOX_PRIORITY, AuditEventPublisher::STREAM_INBOX_BATCH]],
            AuditTaskStage::EXTRACTION->value => ['group' => AuditEventPublisher::GROUP_EXTRACTORS,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH]],
            AuditTaskStage::POLICY->value => ['group' => AuditEventPublisher::GROUP_POLICY,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH]],
            AuditTaskStage::SEMANTIC->value => ['group' => AuditEventPublisher::GROUP_SEMANTIC,
                'streams' => [AuditEventPublisher::STREAM_SEMANTIC_PRIORITY, AuditEventPublisher::STREAM_SEMANTIC_BATCH]],
        ];
    }

    public function tick(): int
    {
        if ($this->config->version !== 2 || $this->redis->get('audit:execution:version') !== '2') {
            throw new \RuntimeException('execution_protocol_mismatch');
        }
        $this->ensureGroups();
        ($this->quota ??= new GeminiQuotaBudget($this->redis))->recoverExpired();
        $this->committer->repair();
        $count = $this->outbox->flush();
        if ($this->redis->get('audit:execution:paused') !== '1') {
            $metrics = $this->queue->metrics();
            foreach (self::stages() as $name => $transport) {
                $stage = AuditTaskStage::from($name);
                $queued = array_sum(array_map(static fn(array $lane): int => $lane['queued'] + $lane['delayed'], $metrics[$name]));
                $capacity = min(100, $this->config->maxQueued - $queued);
                foreach ($transport['streams'] as $stream) {
                    $lane = str_ends_with($stream, '.priority') ? AuditLane::PRIORITY : AuditLane::BATCH;
                    $this->queue->maintain($stage, $lane);
                    if ($capacity < 1) {
                        continue;
                    }
                    $reference = $name . ':' . $stream;
                    $raw = $this->redis->eval('return redis.call("XAUTOCLAIM",KEYS[1],ARGV[1],"execution-intake",5000,ARGV[2],"COUNT",ARGV[3])',
                        [$stream], [$transport['group'], $this->reclaimCursors[$reference] ?? '0-0', $capacity]);
                    if (!is_array($raw) || !isset($raw[0], $raw[1]) || !is_array($raw[1])) {
                        throw new \RuntimeException('intake_reclaim_invalid');
                    }
                    if (($raw[2] ?? []) !== []) {
                        throw new \RuntimeException('intake_pending_message_deleted');
                    }
                    $this->reclaimCursors[$reference] = $raw[0];
                    foreach ($raw[1] as $entry) {
                        $count += (int) $this->intake($stage, $stream, $transport['group'], $entry);
                    }
                    $raw = $this->redis->eval('return redis.call("XREADGROUP","GROUP",ARGV[1],"execution-intake","COUNT",ARGV[2],"STREAMS",KEYS[1],">")',
                        [$stream], [$transport['group'], $capacity]);
                    if ($raw === null || $raw === []) {
                        continue;
                    }
                    if (!is_array($raw) || !is_array($raw[0][1] ?? null)) {
                        throw new \RuntimeException('intake_read_invalid');
                    }
                    foreach ($raw[0][1] as $entry) {
                        $count += (int) $this->intake($stage, $stream, $transport['group'], $entry);
                    }
                }
            }
        }
        $now = $this->queue->nowMs();
        if ($now - $this->lastGcMs >= 30000) {
            $this->gc();
            (new AuditEventPublisher($this->redis))->trimWorkStreams();
            $this->lastGcMs = $now;
        }
        $this->redis->eval('local t=redis.call("TIME"); return redis.call("SET",KEYS[1],tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000),"EX",30)', ['audit:execution:heartbeat']);
        return $count;
    }

    public function flush(): int
    {
        do {
            $repaired = $this->committer->repair();
        } while ($repaired > 0);
        $count = 0;
        do {
            $published = $this->outbox->flush();
            $count += $published;
        } while ($published > 0);
        if ($this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]) !== 0) {
            throw new \RuntimeException('outbox_publication_pending');
        }
        return $count;
    }

    /** Importa PEL/unread con sus envelopes originales; se usa con workers detenidos. */
    public function importPending(): int
    {
        if ($this->redis->get('audit:execution:paused') !== '1') {
            throw new \RuntimeException('execution_pause_required');
        }
        $this->ensureGroups();
        $count = 0;
        foreach (self::stages() as $name => $transport) {
            $stage = AuditTaskStage::from($name);
            foreach ($transport['streams'] as $stream) {
                $cursor = '0-0';
                do {
                    $raw = $this->redis->eval('return redis.call("XAUTOCLAIM",KEYS[1],ARGV[1],"execution-migration",0,ARGV[2],"COUNT",100)', [$stream], [$transport['group'], $cursor]);
                    if (($raw[2] ?? []) !== []) {
                        throw new \RuntimeException('migration_source_message_missing');
                    }
                    foreach ($raw[1] as $entry) {
                        if (!$this->intake($stage, $stream, $transport['group'], $entry)) {
                            $metrics = $this->queue->metrics()[$name];
                            if (array_sum(array_map(static fn(array $lane): int => $lane['queued'] + $lane['delayed'], $metrics)) >= $this->config->maxQueued) {
                                throw new \RuntimeException('migration_stage_capacity_exceeded');
                            }
                        }
                        $count++;
                    }
                    $cursor = $raw[0];
                } while ($cursor !== '0-0');
                do {
                    $raw = $this->redis->eval('return redis.call("XREADGROUP","GROUP",ARGV[1],"execution-migration","COUNT",100,"STREAMS",KEYS[1],">")', [$stream], [$transport['group']]);
                    foreach (($raw[0][1] ?? []) as $entry) {
                        if (!$this->intake($stage, $stream, $transport['group'], $entry)) {
                            $metrics = $this->queue->metrics()[$name];
                            if (array_sum(array_map(static fn(array $lane): int => $lane['queued'] + $lane['delayed'], $metrics)) >= $this->config->maxQueued) {
                                throw new \RuntimeException('migration_stage_capacity_exceeded');
                            }
                        }
                        $count++;
                    }
                } while ($raw !== null && $raw !== []);
            }
        }
        return $count;
    }

    /** Páginas de 100 registros; ninguna dependencia activa recibe TTL. */
    public function gc(): int
    {
        $retention = max(AuditExecutionConfig::integer('AUDIT_STATE_TTL', 604800, 1),
            AuditExecutionConfig::integer('AUDIT_JOB_TTL', 604800, 1),
            AuditExecutionConfig::integer('AUDIT_PERSISTENCE_QUEUE_TTL', 604800, 1));
        $now = $this->queue->nowMs();
        $count = 0;
        [$cursor, $keys] = $this->redis->scanKeys('audit:task:v2:*', $this->gcCursors['tasks'] ?? '0', 100);
        $this->gcCursors['tasks'] = $cursor;
        foreach ($keys as $key) {
            $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [$key]);
            if ($raw === null) {
                continue;
            }
            $task = new AuditTask(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            $rolledBack = ($task->record['migration_disposition'] ?? '') === 'rolled_back';
            if ((!in_array($task->record['state'], ['completed', 'failed'], true) && !$rolledBack)
                || $now - (int) ($task->record['completed_at_ms'] ?? $task->record['retired_at_ms'] ?? $now) < $retention * 1000) {
                continue;
            }
            $root = (new AuditStateStore($this->redis))->getAudit($task->record['audit_id']);
            if ($root !== null && !AuditStateStore::isTerminalStatus($root['status'])) {
                continue;
            }
            $receiptKeys = [];
            for ($fence = 1; $fence <= $task->record['fence']; $fence++) {
                $receiptKeys[] = 'audit:commit:v2:' . $task->id() . ':' . $fence;
            }
            $count += (int) $this->redis->eval('local raw=redis.call("HGET",KEYS[1],"record"); if raw~=ARGV[1] or redis.call("SCARD",KEYS[4])>0 or redis.call("SCARD",KEYS[5])>0 then return 0 end; local r=cjson.decode(raw); if redis.call("SREM",KEYS[2],r.task_id)==1 then redis.call("HINCRBY",KEYS[3],r.stage..":"..r.lane..":"..r.state,-1) end; for i=6,#KEYS do redis.call("DEL",KEYS[i]) end; redis.call("DEL",KEYS[1]); return 1',
                array_merge([$key, FairAuditTaskQueue::ALL, FairAuditTaskQueue::COUNTS, AuditTaskCommitter::PENDING,
                    'audit:execution:refs:' . $task->record['audit_id']], $receiptKeys), [$raw]);
        }
        // Cerrar planes técnicamente abandonados y retirar solo objetos terminales.
        [$cursor, $keys] = $this->redis->scanKeys('audit:semantic:v2:*', $this->gcCursors['plans'] ?? '0', 100);
        $this->gcCursors['plans'] = $cursor;
        foreach ($keys as $key) {
            $raw = $this->redis->eval('if redis.call("TYPE",KEYS[1]).ok~="hash" then return nil end; return redis.call("HGET",KEYS[1],"record")', [$key]);
            if (!is_string($raw)) {
                continue;
            }
            $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $rootKey = AuditStateStore::auditKey($plan['audit_id']);
            $audit = (new AuditStateStore($this->redis))->getAudit($plan['audit_id']);
            if (($audit['status'] ?? '') === 'failed' && in_array($plan['state'], ['planned', 'waiting', 'ready'], true)) {
                $this->redis->eval('local a=cjson.decode(redis.call("GET",KEYS[2])); local p=cjson.decode(redis.call("HGET",KEYS[1],"record")); if a.status~="failed" or p.revision~=tonumber(ARGV[1]) then return 0 end; redis.call("HINCRBY",KEYS[3],p.state,-1); redis.call("HINCRBY",KEYS[3],"failed",1); p.state="failed"; p.revision=p.revision+1; p.updated_at_ms=tonumber(ARGV[2]); redis.call("HSET",KEYS[1],"record",cjson.encode(p)); redis.call("SREM",KEYS[4],"plan:"..p.plan_id); return 1',
                    [$key, $rootKey, 'audit:semantic:v2:counts', 'audit:execution:refs:' . $plan['audit_id']], [$plan['revision'], $now]);
            } elseif ((in_array($plan['state'], ['consumed', 'failed'], true) || ($plan['migration_disposition'] ?? '') === 'rolled_back')
                && ($audit === null || AuditStateStore::isTerminalStatus($audit['status']))
                && $now - (int) ($plan['retired_at_ms'] ?? $plan['updated_at_ms'] ?? $now) >= $retention * 1000) {
                $count += (int) $this->redis->eval('local p=cjson.decode(redis.call("HGET",KEYS[1],"record")); if p.state~="consumed" and p.state~="failed" and p.migration_disposition~="rolled_back" then return 0 end; redis.call("SREM",KEYS[2],ARGV[1]); redis.call("HINCRBY",KEYS[3],p.state,-1); return redis.call("DEL",KEYS[1])', [$key, SemanticEvaluationStore::PLANS, 'audit:semantic:v2:counts'], [$key]);
            }
        }
        [$cursor, $keys] = $this->redis->scanKeys('audit:*:state', $this->gcCursors['audits'] ?? '0', 100);
        $this->gcCursors['audits'] = $cursor;
        foreach ($keys as $key) {
            $raw = $this->redis->get($key);
            if ($raw === null) {
                continue;
            }
            $audit = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $completed = strtotime((string) ($audit['completed_at'] ?? ''));
            if (($audit['execution_version'] ?? 1) !== 2 || !AuditStateStore::isTerminalStatus($audit['status'])
                || $completed === false || $now < ($completed + $retention) * 1000) {
                continue;
            }
            $count += (int) $this->redis->eval('local raw=redis.call("GET",KEYS[1]); if raw~=ARGV[1] or redis.call("SCARD",KEYS[2])>0 then return 0 end; return redis.call("DEL",KEYS[1],KEYS[2])',
                [$key, 'audit:execution:refs:' . $audit['audit_id']], [$raw]);
        }
        foreach (['audit:outbox:v2:*', 'gemini:attempt:v2:*', 'audit:terminal:v2:sent:*'] as $pattern) {
            [$cursor, $keys] = $this->redis->scanKeys($pattern, $this->gcCursors[$pattern] ?? '0', 100);
            $this->gcCursors[$pattern] = $cursor;
            foreach ($keys as $key) {
                $raw = $this->redis->eval('local ty=redis.call("TYPE",KEYS[1]).ok; if ty=="hash" then return redis.call("HGET",KEYS[1],"record") elseif ty=="string" then return redis.call("GET",KEYS[1]) end; return nil', [$key]);
                if (!is_string($raw) || !str_starts_with($raw, '{')) {
                    continue;
                }
                $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (str_starts_with($key, 'audit:outbox:')) {
                    $state = $this->redis->eval('return {redis.call("HGET",KEYS[1],"state"),redis.call("HGET",KEYS[1],"sent_at_ms")}', [$key]);
                    if (($state[0] ?? '') !== 'sent' || $now - (int) ($state[1] ?? $now) < $retention * 1000) {
                        continue;
                    }
                    $root = (new AuditStateStore($this->redis))->getAudit($record['event']['audit_id']);
                    if ($root !== null && !AuditStateStore::isTerminalStatus($root['status'])) {
                        continue;
                    }
                    $count += (int) $this->redis->eval('return redis.call("DEL",KEYS[1],KEYS[2])', [$key, 'audit:outbox:v2:sent:' . $record['effect_id']]);
                } elseif (str_starts_with($key, 'audit:terminal:v2:sent:')) {
                    if ($now - (int) ($record['published_at_ms'] ?? $now) < $retention * 1000) {
                        continue;
                    }
                    $root = isset($record['audit_id']) ? (new AuditStateStore($this->redis))->getAudit($record['audit_id']) : null;
                    $job = isset($record['job_id']) ? (new BatchJobStore($this->redis))->getJob($record['job_id']) : null;
                    if (($root !== null && !AuditStateStore::isTerminalStatus($root['status']))
                        || ($job !== null && !in_array($job['status'], ['completed', 'completed_with_errors', 'failed'], true))) {
                        continue;
                    }
                    $count += (int) $this->redis->eval('if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end; return redis.call("DEL",KEYS[1])', [$key], [$raw]);
                } elseif ($record['state'] !== 'inflight'
                    && $now - (int) ($record['finished_at_ms'] ?? $record['started_at_ms'] ?? $now) >= $retention * 1000) {
                    $task = $this->queue->get($record['task_id']);
                    $root = isset($record['audit_id']) ? (new AuditStateStore($this->redis))->getAudit($record['audit_id']) : null;
                    if (($root === null || AuditStateStore::isTerminalStatus($root['status']))
                        && ($task === null || in_array($task->record['state'], ['completed', 'failed'], true))) {
                        $count += (int) $this->redis->eval('if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end; local r=cjson.decode(ARGV[1]); if r.state=="unknown" then redis.call("HINCRBY",KEYS[3],"unknown_attempts",-1); redis.call("HINCRBY",KEYS[3],(r.stage or "extraction").."_unknown_attempts",-1) end; redis.call("SREM",KEYS[2],r.attempt_id); return redis.call("DEL",KEYS[1])',
                            [$key, 'audit:gemini:v2:attempts:' . ($record['audit_id'] ?? 'none'), $record['quota_base'] . ':metrics'], [$raw]);
                    }
                }
            }
        }
        [$cursor, $keys] = $this->redis->scanKeys('job:*:state', $this->gcCursors['jobs'] ?? '0', 100);
        $this->gcCursors['jobs'] = $cursor;
        foreach ($keys as $key) {
            $raw = $this->redis->get($key);
            if ($raw === null) {
                continue;
            }
            $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $completed = strtotime((string) ($job['completed_at'] ?? ''));
            if (($job['execution_version'] ?? 1) !== 2 || !in_array($job['status'], ['completed', 'completed_with_errors', 'failed'], true)
                || $completed === false || $now < ($completed + $retention) * 1000) {
                continue;
            }
            $refs = array_map(static fn(string $id): string => 'audit:execution:refs:' . $id, array_keys($job['audits'] ?? []));
            $count += (int) $this->redis->eval('if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end; for i=2,#KEYS do if redis.call("SCARD",KEYS[i])>0 then return 0 end end; return redis.call("DEL",KEYS[1])', array_merge([$key], $refs), [$raw]);
        }
        [$cursor, $keys] = $this->redis->scanKeys('batch:idem:*', $this->gcCursors['idempotency'] ?? '0', 100);
        $this->gcCursors['idempotency'] = $cursor;
        foreach ($keys as $key) {
            $jobId = $this->redis->get($key);
            if ($jobId !== null) {
                // Conservar mientras viva el job; al retirarlo comienza retención adicional.
                $this->redis->eval('if redis.call("EXISTS",KEYS[2])==0 and redis.call("TTL",KEYS[1])==-1 then return redis.call("EXPIRE",KEYS[1],ARGV[1]) end; return 0', [$key, BatchJobStore::jobKey($jobId)], [$retention]);
            }
        }
        return $count + $this->gcMigrations($now, $retention);
    }

    private function gcMigrations(int $now, int $retention): int
    {
        [$cursor, $keys] = $this->redis->eval('return redis.call("SSCAN",KEYS[1],ARGV[1],"COUNT",100)',
            ['audit:migrations:v2:all'], [$this->gcCursors['migrations'] ?? '0']);
        $this->gcCursors['migrations'] = (string) $cursor;
        $count = 0;
        foreach ($keys as $key) {
            if (!preg_match('/^audit:migration:v2:[a-zA-Z0-9_-]{1,80}$/D', $key)) {
                throw new \RuntimeException('migration_gc_key_invalid');
            }
            $raw = $this->redis->get($key);
            if ($raw === null) {
                throw new \RuntimeException('migration_manifest_missing');
            }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($manifest['accepted_at_ms']) || $now - $manifest['accepted_at_ms'] < $retention * 1000) {
                continue;
            }
            foreach ([$manifest['baseline'], $manifest['rollback_inventory'] ?? []] as $inventory) {
                foreach (array_merge(array_column($inventory['audits'] ?? [], 'key'), array_column($inventory['jobs'] ?? [], 'key')) as $rootKey) {
                    $rootRaw = $this->redis->get($rootKey);
                    if ($rootRaw === null) { continue; }
                    $root = json_decode($rootRaw, true, 512, JSON_THROW_ON_ERROR);
                    $terminal = str_starts_with($rootKey, 'job:')
                        ? in_array($root['status'], ['completed', 'completed_with_errors', 'failed'], true)
                        : AuditStateStore::isTerminalStatus($root['status']);
                    $completed = strtotime((string) ($root['completed_at'] ?? ''));
                    if (!$terminal || $completed === false || $now < ($completed + $retention) * 1000) {
                        continue 3;
                    }
                }
                foreach (array_keys($inventory['audits'] ?? []) as $auditId) {
                    if ($this->redis->eval('return redis.call("SCARD",KEYS[1])', ['audit:execution:refs:' . $auditId]) > 0) {
                        continue 3;
                    }
                }
            }
            [$next, $archives] = $this->redis->eval('return redis.call("SSCAN",KEYS[1],ARGV[1],"COUNT",100)',
                [$key . ':objects'], [$this->gcCursors[$key] ?? '0']);
            $this->gcCursors[$key] = (string) $next;
            foreach ($archives as $archiveKey) {
                if (!str_starts_with($archiveKey, $key . ':') || $archiveKey === $key . ':objects') {
                    throw new \RuntimeException('migration_gc_object_invalid');
                }
            }
            if ($archives !== []) {
                $count += (int) $this->redis->eval('local ids=cjson.decode(ARGV[1]); for i=2,#KEYS do redis.call("DEL",KEYS[i]); redis.call("SREM",KEYS[1],ids[i-1]) end; return #KEYS-1',
                    array_merge([$key . ':objects'], $archives), [json_encode($archives, JSON_THROW_ON_ERROR)]);
            }
            if ($this->redis->eval('return redis.call("SCARD",KEYS[1])', [$key . ':objects']) === 0) {
                $count += (int) $this->redis->eval('redis.call("SREM",KEYS[2],ARGV[1]); return redis.call("DEL",KEYS[1],KEYS[3])',
                    [$key, 'audit:migrations:v2:all', $key . ':objects'], [$key]);
                unset($this->gcCursors[$key]);
            }
        }
        return $count;
    }

    private function ensureGroups(): void
    {
        if ($this->groupsReady) {
            return;
        }
        foreach (self::stages() as $transport) {
            foreach ($transport['streams'] as $stream) {
                $this->redis->eval('local result=redis.pcall("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); if type(result)=="table" and result.err and not string.find(result.err,"BUSYGROUP") then return redis.error_reply(result.err) end; return 1', [$stream], [$transport['group']]);
            }
        }
        $this->groupsReady = true;
    }

    private function intake(AuditTaskStage $stage, string $stream, string $group, array $entry): bool
    {
        if (!is_string($entry[0] ?? null) || !is_array($entry[1] ?? null)) {
            throw new \RuntimeException('intake_message_invalid');
        }
        $fields = [];
        for ($i = 0; $i < count($entry[1]); $i += 2) {
            $fields[$entry[1][$i]] = $entry[1][$i + 1];
        }
        try {
            $event = AuditEvent::fromArray(json_decode($fields['event'] ?? '', true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\InvalidArgumentException|\TypeError $error) {
            $record = AuditEvent::create(AuditEvent::TYPE_DEAD_LETTER, null, payload: [
                'original_event_json' => $fields['event'] ?? '', 'error' => 'intake_event_contract_invalid',
                'stage' => $stage->value, 'stream' => $stream, 'stream_id' => $entry[0], 'group' => $group,
            ])->toArray();
            $record['event_id'] = AuditTaskEffects::uuid($stage->value . ':' . $stream . ':' . $entry[0]);
            (new AuditEventPublisher($this->redis))->publishDeadLetter(AuditEvent::fromArray($record));
            $this->redis->eval('return redis.call("XACK",KEYS[1],ARGV[1],ARGV[2])', [$stream], [$group, $entry[0]]);
            return false;
        }
        if (!in_array($event->eventType, $stage->eventTypes(), true)) {
            $this->redis->eval('return redis.call("XACK",KEYS[1],ARGV[1],ARGV[2])', [$stream], [$group, $entry[0]]);
            return false;
        }
        return $this->queue->admit(AuditTask::fromEvent($stage, $event), $stream, $group, $entry[0]);
    }
}
