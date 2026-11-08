<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Intake y reparación independientes de los procesos que esperan HTTP. */
final class AuditExecutionMaintenance
{
    public const GC_PAGE_SIZE = 1000;
    public const GC_SCAN_STEPS = 10;

    private const GC_DELETE_TASK_LUA = <<<'LUA'
        local raw = redis.call('HGET', KEYS[1], 'record')
        if raw ~= ARGV[1] then
            return 0
        end
        local r = cjson.decode(raw)
        if redis.call('SREM', KEYS[2], r.task_id) == 1 then
            redis.call('HINCRBY', KEYS[3], r.stage .. ':' .. r.lane .. ':' .. r.state, -1)
        end
        redis.call('SREM', KEYS[4], 'task:' .. r.task_id)
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    private const GC_DELETE_SEMANTIC_PLAN_LUA = <<<'LUA'
        local raw = redis.call('HGET', KEYS[1], 'record')
        if not raw then
            return 0
        end
        local p = cjson.decode(raw)
        if p.revision ~= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('SREM', KEYS[2], KEYS[1])
        redis.call('HINCRBY', KEYS[3], p.state, -1)
        redis.call('SREM', KEYS[4], 'plan:' .. p.plan_id)
        return redis.call('DEL', KEYS[1])
    LUA;

    private const GC_DELETE_AUDIT_STATE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if raw ~= ARGV[1] then
            return 0
        end
        for _, member in ipairs(redis.call('SMEMBERS', KEYS[2])) do
            if string.sub(member, 1, 5) ~= 'plan:' then
                return 0
            end
        end
        return redis.call('DEL', KEYS[1], KEYS[2])
    LUA;

    private const GC_DELETE_GEMINI_ATTEMPT_LUA = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        local r = cjson.decode(ARGV[1])
        if r.state == 'unknown' then
            redis.call('HINCRBY', KEYS[3], 'unknown_attempts', -1)
            redis.call('HINCRBY', KEYS[3], (r.stage or 'extraction') .. '_unknown_attempts', -1)
        end
        redis.call('SREM', KEYS[2], r.attempt_id)
        return redis.call('DEL', KEYS[1])
    LUA;

    private const GC_DELETE_JOB_STATE_LUA = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        for i = 3, #KEYS do
            for _, member in ipairs(redis.call('SMEMBERS', KEYS[i])) do
                if string.sub(member, 1, 5) ~= 'plan:' then
                    return 0
                end
            end
        end
        redis.call('ZREM', KEYS[2], ARGV[2])
        return redis.call('DEL', KEYS[1])
    LUA;

    private FairAuditTaskQueue $queue;
    private AuditTaskOutbox $outbox;
    private array $reclaimCursors = [];
    private bool $groupsReady = false;
    private array $gcCursors = [];
    private int $lastGcMs = 0;
    private ?GeminiQuotaBudget $quota = null;

    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
        $this->queue = new FairAuditTaskQueue($redis, $config);
        $this->outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
    }

    public static function stages(): array
    {
        return [
            AuditTaskStage::ORCHESTRATION->value => [
                'group'   => AuditEventPublisher::GROUP_ORCHESTRATOR,
                'streams' => [AuditEventPublisher::STREAM_INBOX_PRIORITY, AuditEventPublisher::STREAM_INBOX_BATCH],
            ],
            AuditTaskStage::EXTRACTION->value => [
                'group'   => AuditEventPublisher::GROUP_EXTRACTORS,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH],
            ],
            AuditTaskStage::POLICY->value => [
                'group'   => AuditEventPublisher::GROUP_POLICY,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH],
            ],
            AuditTaskStage::SEMANTIC->value => [
                'group'   => AuditEventPublisher::GROUP_SEMANTIC,
                'streams' => [AuditEventPublisher::STREAM_SEMANTIC_PRIORITY, AuditEventPublisher::STREAM_SEMANTIC_BATCH],
            ],
        ];
    }

    public function tick(): int
    {
        if ($this->config->version !== AuditExecutionConfig::VERSION || ($this->redis->get('audit:execution:version') ?? '2') !== '2') {
            throw new \RuntimeException('execution_protocol_mismatch');
        }
        $this->ensureGroups();
        ($this->quota ??= new GeminiQuotaBudget($this->redis))->recoverExpired();
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
                    $raw = $this->redis->eval(
                        'return redis.call("XAUTOCLAIM",KEYS[1],ARGV[1],"execution-intake",5000,ARGV[2],"COUNT",ARGV[3])',
                        [$stream],
                        [$transport['group'], $this->reclaimCursors[$reference] ?? '0-0', $capacity]
                    );
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
                    $raw = $this->redis->eval(
                        'return redis.call("XREADGROUP","GROUP",ARGV[1],"execution-intake","COUNT",ARGV[2],"STREAMS",KEYS[1],">")',
                        [$stream],
                        [$transport['group'], $capacity]
                    );
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
        $atCapacity = (new AuditAdmissionGuard($this->redis, $this->config))->orchestrationAtCapacity();
        if ($atCapacity || $now - $this->lastGcMs >= 10000) {
            $this->gc();
            (new AuditEventPublisher($this->redis))->trimWorkStreams();
            $this->lastGcMs = $now;
        }
        $this->redis->eval(
            'local t=redis.call("TIME"); return redis.call("SET",KEYS[1],tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000),"EX",30)',
            ['audit:execution:heartbeat']
        );
        return $count;
    }

    public function flush(): int
    {
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

    /** Páginas de 1000 registros; ninguna dependencia activa recibe TTL. */
    public function gc(): int
    {
        $retention = max(
            AuditExecutionConfig::integer('AUDIT_STATE_TTL', 86400, 1),
            AuditExecutionConfig::integer('AUDIT_JOB_TTL', 86400, 1),
            AuditExecutionConfig::integer('AUDIT_PERSISTENCE_QUEUE_TTL', 86400, 1)
        );
        $now = $this->queue->nowMs();
        $stateStore = new AuditStateStore($this->redis);
        $audits = [];
        $getAudit = static function (string $auditId) use ($stateStore, &$audits): ?array {
            if (!array_key_exists($auditId, $audits)) {
                $audits[$auditId] = $stateStore->getAudit($auditId);
            }
            return $audits[$auditId];
        };

        $count = 0;

        foreach ($this->scanPattern('audit:task:v2:*', 'tasks') as $key) {
            $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [$key]);
            if ($raw === null) {
                continue;
            }
            $task = new AuditTask(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            if (!in_array($task->record['state'], ['completed', 'failed'], true)) {
                continue;
            }
            $root = $getAudit($task->record['audit_id']);
            if ($root !== null && !AuditStateStore::isTerminalStatus($root['status'])) {
                continue;
            }
            $count += (int) $this->redis->eval(
                self::GC_DELETE_TASK_LUA,
                [
                    $key,
                    FairAuditTaskQueue::ALL,
                    FairAuditTaskQueue::COUNTS,
                    'audit:execution:refs:' . $task->record['audit_id'],
                ],
                [$raw]
            );
        }

        // Retirar planes semánticos huérfanos o pertenecientes a auditorías terminales en un solo paso.
        foreach ($this->scanPattern('audit:semantic:v2:*', 'plans') as $key) {
            $raw = $this->redis->eval('if redis.call("TYPE",KEYS[1]).ok~="hash" then return nil end; return redis.call("HGET",KEYS[1],"record")', [$key]);
            if (!is_string($raw)) {
                continue;
            }
            $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $audit = $getAudit($plan['audit_id']);
            if ($audit === null || AuditStateStore::isTerminalStatus((string) ($audit['status'] ?? ''))
                || in_array($plan['state'], ['consumed', 'failed'], true)) {
                $count += (int) $this->redis->eval(
                    self::GC_DELETE_SEMANTIC_PLAN_LUA,
                    [
                        $key,
                        SemanticEvaluationStore::PLANS,
                        'audit:semantic:v2:counts',
                        'audit:execution:refs:' . $plan['audit_id'],
                    ],
                    [$plan['revision']]
                );
            }
        }

        foreach ($this->scanPattern('audit:*:state', 'audits') as $key) {
            $raw = $this->redis->get($key);
            if ($raw === null) {
                continue;
            }
            $audit = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $completed = strtotime((string) ($audit['completed_at'] ?? $audit['updated_at'] ?? ''));
            if (!AuditStateStore::isTerminalStatus((string) ($audit['status'] ?? ''))
                || $completed === false || $now < ($completed + $retention) * 1000) {
                continue;
            }
            $count += (int) $this->redis->eval(
                self::GC_DELETE_AUDIT_STATE_LUA,
                [$key, 'audit:execution:refs:' . $audit['audit_id']],
                [$raw]
            );
        }

        foreach ($this->scanPattern('gemini:attempt:v2:*', 'gemini:attempt:v2:*') as $key) {
            $raw = $this->redis->get($key);
            if (!is_string($raw) || !str_starts_with($raw, '{')) {
                continue;
            }
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if ($record['state'] !== 'inflight') {
                $task = $this->queue->get($record['task_id']);
                $root = isset($record['audit_id']) ? $getAudit($record['audit_id']) : null;
                if (($root === null || AuditStateStore::isTerminalStatus($root['status']))
                    && ($task === null || in_array($task->record['state'], ['completed', 'failed'], true))) {
                    $count += (int) $this->redis->eval(
                        self::GC_DELETE_GEMINI_ATTEMPT_LUA,
                        [
                            $key,
                            'audit:gemini:v2:attempts:' . ($record['audit_id'] ?? 'none'),
                            $record['quota_base'] . ':metrics',
                        ],
                        [$raw]
                    );
                }
            }
        }

        foreach ($this->scanPattern('job:*:state', 'jobs') as $key) {
            $raw = $this->redis->get($key);
            if ($raw === null) {
                continue;
            }
            $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $completed = strtotime((string) ($job['completed_at'] ?? $job['updated_at'] ?? ''));
            if (!in_array($job['status'] ?? '', ['completed', 'completed_with_errors', 'failed'], true)
                || $completed === false || $now < ($completed + $retention) * 1000) {
                continue;
            }
            $refs = array_map(
                static fn(string $id): string => 'audit:execution:refs:' . $id,
                array_keys($job['audits'] ?? [])
            );
            $count += (int) $this->redis->eval(
                self::GC_DELETE_JOB_STATE_LUA,
                array_merge([$key, 'jobs:index'], $refs),
                [$raw, (string) ($job['job_id'] ?? '')]
            );
        }

        foreach ($this->scanPattern('audit:reservation:disid:*', 'reservations') as $key) {
            $raw = $this->redis->get($key);
            if (!is_string($raw) || !str_starts_with($raw, '{')) {
                continue;
            }
            $reservation = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $auditId = (string) ($reservation['audit_id'] ?? '');
            $claimed = strtotime((string) ($reservation['claimed_at'] ?? ''));
            $root = $auditId !== '' ? $getAudit($auditId) : null;
            if (($root !== null && AuditStateStore::isTerminalStatus((string) ($root['status'] ?? '')))
                || ($root === null && ($claimed === false || $now - ($claimed * 1000) >= 60000))) {
                $count += (int) $this->redis->eval(
                    'if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end; return redis.call("DEL",KEYS[1])',
                    [$key],
                    [$raw]
                );
            }
        }

        foreach ($this->scanPattern('batch:idem:*', 'idempotency') as $key) {
            $jobId = $this->redis->get($key);
            if ($jobId !== null) {
                // Conservar mientras viva el job; al retirarlo comienza retención adicional.
                $this->redis->eval(
                    'if redis.call("EXISTS",KEYS[2])==0 and redis.call("TTL",KEYS[1])==-1 then return redis.call("EXPIRE",KEYS[1],ARGV[1]) end; return 0',
                    [$key, BatchJobStore::jobKey($jobId)],
                    [$retention]
                );
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function scanPattern(string $pattern, string $cursorKey): array
    {
        $cursor = $this->gcCursors[$cursorKey] ?? '0';
        $collected = [];
        for ($step = 0; $step < self::GC_SCAN_STEPS; $step++) {
            [$cursor, $keys] = $this->redis->scanKeys($pattern, $cursor, self::GC_PAGE_SIZE);
            foreach ($keys as $key) {
                $collected[] = $key;
            }
            if ($cursor === '0' || count($collected) >= self::GC_PAGE_SIZE) {
                break;
            }
        }
        $this->gcCursors[$cursorKey] = $cursor;
        return $collected;
    }

    private function ensureGroups(): void
    {
        if ($this->groupsReady) {
            return;
        }
        foreach (self::stages() as $transport) {
            foreach ($transport['streams'] as $stream) {
                $this->redis->eval(
                    'local result=redis.pcall("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); if type(result)=="table" and result.err and not string.find(result.err,"BUSYGROUP") then return redis.error_reply(result.err) end; return 1',
                    [$stream],
                    [$transport['group']]
                );
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
                'original_event_json' => $fields['event'] ?? '',
                'error'               => 'intake_event_contract_invalid',
                'stage'               => $stage->value,
                'stream'              => $stream,
                'stream_id'           => $entry[0],
                'group'               => $group,
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
