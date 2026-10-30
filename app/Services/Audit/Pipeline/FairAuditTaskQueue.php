<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Admisión durable y un turno activo por job/etapa/carril. Sin ejecución de negocio. */
final class FairAuditTaskQueue
{
    public const COUNTS = 'audit:tasks:v2:counts';
    public const ALL = 'audit:tasks:v2:all';

    public function __construct(private readonly RedisClient $redis, private readonly AuditExecutionConfig $config)
    {
    }

    public static function base(string $stage, string $lane): string
    {
        AuditTaskStage::from($stage);
        if (AuditLane::fromString($lane)->isAll()) {
            throw new \InvalidArgumentException('task_lane_invalid');
        }
        return "audit:tasks:v2:{$stage}:{$lane}";
    }

    public static function taskKey(string $id): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('task_id_invalid');
        }
        return 'audit:task:v2:' . $id;
    }

    public static function keys(array $record): array
    {
        $base = self::base($record['stage'], $record['lane']);
        return [self::taskKey($record['task_id']), $base . ':job:' . $record['scope'] . ':pending',
            $base . ':jobs', $base . ':members', $base . ':active', $base . ':leases', $base . ':delayed',
            self::COUNTS, self::ALL, 'audit:tasks:v2:sequence', AuditStateStore::auditKey($record['audit_id']), $base . ':scope_counts'];
    }

    public function admit(AuditTask $task, string $stream, string $group, string $streamId): bool
    {
        $result = $this->redis->eval(self::ADMIT, array_merge(self::keys($task->record),
            [$stream, 'audit:execution:version', $task->record['job_id'] === null ? 'audit:execution:none' : BatchJobStore::jobKey($task->record['job_id']), self::base($task->record['stage'], $task->record['lane']) . ':waiting', 'audit:execution:refs:' . $task->record['audit_id']]),
            [json_encode($task->record, JSON_THROW_ON_ERROR), $this->config->maxQueued, $group, $streamId]);
        if (!in_array($result, [0, 1, 2], true)) {
            throw new \RuntimeException('task_admission_invalid');
        }
        return $result !== 0;
    }

    public function get(string $id): ?AuditTask
    {
        $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [self::taskKey($id)]);
        return is_string($raw) ? new AuditTask(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)) : null;
    }

    public function claim(AuditTaskStage $stage, AuditLane $lane, string $owner): ?AuditTask
    {
        if ($owner === '' || strlen($owner) > 128) {
            throw new \InvalidArgumentException('task_owner_invalid');
        }
        $lanes = [$lane];
        if ($lane->isAll()) {
            $turn = $this->redis->eval('return tonumber(redis.call("GET",KEYS[1]) or "0")', ['audit:tasks:v2:' . $stage->value . ':lane_turn']);
            $lanes = (int) $turn % 4 === 3 ? [AuditLane::BATCH, AuditLane::PRIORITY] : [AuditLane::PRIORITY, AuditLane::BATCH];
        }
        foreach ($lanes as $selected) {
            $this->maintain($stage, $selected);
            $base = self::base($stage->value, $selected->value);
            $length = $this->redis->eval('return redis.call("LLEN",KEYS[1])', [$base . ':jobs']);
            for ($offset = 0; $offset < $length; $offset += 100) {
            $scopes = $this->redis->eval('return redis.call("LRANGE",KEYS[1],ARGV[1],ARGV[1]+99)', [$base . ':jobs'], [$offset]);
            if (!is_array($scopes)) {
                throw new \RuntimeException('task_index_invalid');
            }
            foreach ($scopes as $scope) {
                // Con varios turnos por job, otro worker puede tomar la cabeza entre la lectura y el claim:
                // se relee la cabeza una vez; si no cambió, el job está en su cupo y se pasa al siguiente.
                $contended = null;
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $ids = $this->redis->eval('return redis.call("ZRANGE",KEYS[1],0,0)', [$base . ':job:' . $scope . ':pending']);
                    if (!is_array($ids)) {
                        throw new \RuntimeException('task_index_invalid');
                    }
                    if ($ids === [] || (string) $ids[0] === $contended) {
                        break;
                    }
                    $task = $this->get((string) $ids[0]);
                    if ($task === null) {
                        throw new \RuntimeException('task_record_missing');
                    }
                    $raw = $this->redis->eval(self::CLAIM, array_merge(self::keys($task->record),
                        ['audit:tasks:v2:' . $stage->value . ':lane_turn', $base . ':waiting']),
                        [$owner, AuditExecutionConfig::LEASE_MS, $lane->isAll() ? 1 : 0, $this->config->jobSlots($stage)]);
                    if (is_string($raw)) {
                        return new AuditTask(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
                    }
                    if ($raw !== 0) {
                        throw new \RuntimeException('task_claim_invalid');
                    }
                    $contended = (string) $ids[0];
                }
            }
            }
        }
        return null;
    }

    public function defer(AuditTask $task, int $dueAtMs, string $reason, bool $attempted = false, ?string $operation = null): bool
    {
        return $this->redis->eval(self::DEFER, array_merge(self::keys($task->record), [self::base($task->record['stage'], $task->record['lane']) . ':waiting']),
            [$task->record['lease_owner'], $task->record['fence'], $dueAtMs, $reason, $attempted ? 1 : 0, $operation ?? $task->record['operation']]) === 1;
    }

    public function saveArtifact(AuditTask $task, array $artifact): bool
    {
        return $this->redis->eval(self::ARTIFACT, [self::taskKey($task->id())],
            [$task->record['lease_owner'], $task->record['fence'], json_encode($artifact, JSON_THROW_ON_ERROR)]) === 1;
    }

    public function nowMs(): int
    {
        $time = $this->redis->eval('local t=redis.call("TIME"); return tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)');
        if (!is_int($time)) {
            throw new \RuntimeException('redis_clock_invalid');
        }
        return $time;
    }

    public function maintain(AuditTaskStage $stage, AuditLane $lane): void
    {
        $base = self::base($stage->value, $lane->value);
        foreach (['delayed', 'leases'] as $index) {
            $ids = $this->redis->eval('local t=redis.call("TIME"); return redis.call("ZRANGEBYSCORE",KEYS[1],"-inf",tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000),"LIMIT",0,100)', [$base . ':' . $index]);
            if (!is_array($ids)) {
                throw new \RuntimeException('task_index_invalid');
            }
            foreach ($ids as $id) {
                $task = $this->get($id);
                if ($task === null) {
                    throw new \RuntimeException('task_record_missing');
                }
                $this->redis->eval(self::MAINTAIN, array_merge(self::keys($task->record), [$base . ':waiting']), [$index]);
            }
        }
    }

    /**
     * Contadores e índices de espera se leen en una única operación Lua: los mutadores son atómicos, así que
     * una lectura en dos pasos puede observar un contador adelantado respecto del índice `:waiting`.
     */
    public function metrics(): array
    {
        $keys = [self::COUNTS, self::ALL];
        foreach (AuditTaskStage::cases() as $stage) {
            foreach ([AuditLane::PRIORITY, AuditLane::BATCH] as $lane) {
                $keys[] = self::base($stage->value, $lane->value) . ':waiting';
            }
        }
        $snapshot = $this->redis->eval(self::METRICS, $keys);
        if (!is_array($snapshot) || count($snapshot) !== 2 || !is_array($snapshot[0]) || !is_array($snapshot[1])
            || count($snapshot[0]) % 2 !== 0) {
            throw new \RuntimeException('task_metrics_invalid');
        }
        [$raw, $waitingHeads] = $snapshot;
        $flat = [];
        for ($i = 0; $i < count($raw); $i += 2) {
            if (!is_numeric($raw[$i + 1]) || (int) $raw[$i + 1] < 0) {
                throw new \RuntimeException('task_metrics_invalid');
            }
            $flat[$raw[$i]] = (int) $raw[$i + 1];
        }
        $out = [];
        $now = $this->nowMs();
        $index = 0;
        foreach (AuditTaskStage::cases() as $stage) {
            foreach ([AuditLane::PRIORITY, AuditLane::BATCH] as $lane) {
                $prefix = $stage->value . ':' . $lane->value . ':';
                $oldest = $waitingHeads[$index++] ?? null;
                $waiting = ($flat[$prefix . 'pending'] ?? 0) + ($flat[$prefix . 'delayed'] ?? 0);
                if (!is_array($oldest) || ($waiting > 0 && count($oldest) !== 2)) {
                    throw new \RuntimeException('task_waiting_index_missing');
                }
                $out[$stage->value][$lane->value] = [
                    'queued' => $flat[$prefix . 'pending'] ?? 0, 'active' => ($flat[$prefix . 'active'] ?? 0) + ($flat[$prefix . 'committing'] ?? 0),
                    'delayed' => $flat[$prefix . 'delayed'] ?? 0,
                    'oldest_wait_ms' => $oldest === [] ? 0 : max(0, $now - (int) $oldest[1]),
                ];
            }
        }
        return $out;
    }

    private const METRICS = <<<'LUA'
        if redis.call('SCARD',KEYS[2])>0 and redis.call('EXISTS',KEYS[1])==0 then return redis.error_reply('task_metrics_missing') end
        local heads={}
        for i=3,#KEYS do heads[#heads+1]=redis.call('ZRANGE',KEYS[i],0,0,'WITHSCORES') end
        return {redis.call('HGETALL',KEYS[1]),heads}
    LUA;

    private const ADMIT = <<<'LUA'
        local r=cjson.decode(ARGV[1]); local cp=r.stage..':'..r.lane..':'
        if redis.call('GET',KEYS[14])~='2' then return redis.error_reply('execution_protocol_mismatch') end
        local types={'hash','zset','list','set','hash','zset','zset','hash','set','string','string','hash','stream','string','string','zset','set'}
        for i,k in ipairs(KEYS) do local t=redis.call('TYPE',k).ok; if t~='none' and t~=types[i] then return redis.error_reply('task_key_type_invalid') end end
        local raw=redis.call('GET',KEYS[11]); if not raw then return redis.error_reply('audit_state_missing') end
        local a=cjson.decode(raw); local s=a.status
        if s=='completed' or s=='manual_review' or s=='error' or s=='failed' then redis.call('XACK',KEYS[13],ARGV[3],ARGV[4]); return 2 end
        local old=redis.call('HGET',KEYS[1],'record')
        if old then
            if redis.call('HGET',KEYS[1],'admitted')~='1' then return redis.error_reply('task_admission_repair_required') end
            local previous=cjson.decode(old); if previous.state~='completed' and previous.state~='failed' then redis.call('SADD',KEYS[17],'task:'..r.task_id) end
            redis.call('XACK',KEYS[13],ARGV[3],ARGV[4]); return 1
        end
        local queued=0; for _,l in ipairs({'priority','batch'}) do for _,s in ipairs({'pending','delayed'}) do queued=queued+tonumber(redis.call('HGET',KEYS[8],r.stage..':'..l..':'..s) or '0') end end
        if queued>=tonumber(ARGV[2]) then return 0 end
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        r.enqueued_at_ms=now; r.due_at_ms=now; r.ready_sequence=redis.call('INCR',KEYS[10])
        redis.call('HSET',KEYS[1],'record',cjson.encode(r),'fence',0)
        redis.call('ZADD',KEYS[2],r.ready_sequence,r.task_id)
        if redis.call('SADD',KEYS[4],r.scope)==1 then redis.call('RPUSH',KEYS[3],r.scope) end
        redis.call('SADD',KEYS[9],r.task_id); redis.call('HINCRBY',KEYS[8],cp..'pending',1)
        redis.call('SADD',KEYS[17],'task:'..r.task_id)
        redis.call('HINCRBY',KEYS[12],r.scope,1)
        redis.call('ZADD',KEYS[16],now,r.task_id)
        redis.call('PERSIST',KEYS[11]); if r.job_id and r.job_id~=cjson.null then redis.call('PERSIST',KEYS[15]) end
        redis.call('HSET',KEYS[1],'admitted','1')
        redis.call('XACK',KEYS[13],ARGV[3],ARGV[4]); return 1
    LUA;

    private const CLAIM = <<<'LUA'
        local types={'hash','zset','list','set','hash','zset','zset','hash','set','string','string','hash','string','zset'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('task_key_type_invalid') end end
        local raw=redis.call('HGET',KEYS[1],'record'); if not raw then return redis.error_reply('task_record_missing') end
        -- KEYS[5] guarda por scope el número de turnos activos; un valor legado (task_id) cuenta como un turno.
        local r=cjson.decode(raw); local held=redis.call('HGET',KEYS[5],r.scope); local used=0
        if held then if string.match(held,'^%d+$') and #held<4 then used=tonumber(held) else used=1 end end
        if r.state~='pending' or used>=tonumber(ARGV[4]) then return 0 end
        local head=redis.call('ZRANGE',KEYS[2],0,0); if head[1]~=r.task_id then return 0 end
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        r.fence=tonumber(redis.call('HINCRBY',KEYS[1],'fence',1)); r.state='active'; r.lease_owner=ARGV[1]; r.lease_until_ms=now+tonumber(ARGV[2])
        redis.call('HSET',KEYS[1],'record',cjson.encode(r)); redis.call('ZREM',KEYS[2],r.task_id)
        redis.call('ZREM',KEYS[14],r.task_id)
        redis.call('HSET',KEYS[5],r.scope,used+1); redis.call('ZADD',KEYS[6],r.lease_until_ms,r.task_id)
        redis.call('LREM',KEYS[3],0,r.scope); redis.call('RPUSH',KEYS[3],r.scope)
        local cp=r.stage..':'..r.lane..':'
        redis.call('HINCRBY',KEYS[8],cp..'pending',-1); redis.call('HINCRBY',KEYS[8],cp..'active',1)
        if ARGV[3]=='1' then redis.call('INCR',KEYS[13]) end
        return cjson.encode(r)
    LUA;

    private const DEFER = <<<'LUA'
        local types={'hash','zset','list','set','hash','zset','zset','hash','set','string','string','hash','zset'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('task_key_type_invalid') end end
        local raw=redis.call('HGET',KEYS[1],'record'); if not raw then return 0 end
        local r=cjson.decode(raw); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if r.state~='active' or r.lease_owner~=ARGV[1] or tonumber(r.fence)~=tonumber(ARGV[2]) or tonumber(r.lease_until_ms)<=now then return 0 end
        r.state='delayed'; r.due_at_ms=math.max(now,tonumber(ARGV[3])); r.last_error=ARGV[4]
        if ARGV[5]=='1' then
            if r.operation~=ARGV[6] then r.operation=ARGV[6]; r.operation_attempt=0 end
            r.operation_attempt=r.operation_attempt+1
        end
        r.lease_owner=cjson.null; r.lease_until_ms=cjson.null
        redis.call('HSET',KEYS[1],'record',cjson.encode(r)); do local held=redis.call('HGET',KEYS[5],r.scope); local n=(held and string.match(held,'^%d+$') and #held<4) and tonumber(held) or 1; if n<=1 then redis.call('HDEL',KEYS[5],r.scope) else redis.call('HSET',KEYS[5],r.scope,n-1) end end; redis.call('ZREM',KEYS[6],r.task_id); redis.call('ZADD',KEYS[7],r.due_at_ms,r.task_id)
        redis.call('ZADD',KEYS[13],r.enqueued_at_ms,r.task_id)
        local cp=r.stage..':'..r.lane..':'
        redis.call('HINCRBY',KEYS[8],cp..'active',-1); redis.call('HINCRBY',KEYS[8],cp..'delayed',1); return 1
    LUA;

    private const ARTIFACT = <<<'LUA'
        local raw=redis.call('HGET',KEYS[1],'record'); if not raw then return 0 end
        local r=cjson.decode(raw); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if r.state~='active' or r.lease_owner~=ARGV[1] or tonumber(r.fence)~=tonumber(ARGV[2]) or tonumber(r.lease_until_ms)<=now then return 0 end
        r.stage_artifact=cjson.decode(ARGV[3]); redis.call('HSET',KEYS[1],'record',cjson.encode(r)); return 1
    LUA;

    private const MAINTAIN = <<<'LUA'
        local types={'hash','zset','list','set','hash','zset','zset','hash','set','string','string','hash','zset'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('task_key_type_invalid') end end
        local raw=redis.call('HGET',KEYS[1],'record'); if not raw then return redis.error_reply('task_record_missing') end
        local r=cjson.decode(raw); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        local previous=r.state
        if ARGV[1]=='delayed' then if r.state~='delayed' or r.due_at_ms>now then return 0 end; redis.call('ZREM',KEYS[7],r.task_id)
        else
            if r.state~='active' or r.lease_until_ms>now then return 0 end
            redis.call('ZREM',KEYS[6],r.task_id); do local held=redis.call('HGET',KEYS[5],r.scope); local n=(held and string.match(held,'^%d+$') and #held<4) and tonumber(held) or 1; if n<=1 then redis.call('HDEL',KEYS[5],r.scope) else redis.call('HSET',KEYS[5],r.scope,n-1) end end
            r.recovery_count=r.recovery_count+1; r.lease_owner=cjson.null; r.lease_until_ms=cjson.null
            if r.recovery_count>3 then r.last_error='task_recovery_exhausted' end
        end
        r.state='pending'; r.ready_sequence=redis.call('INCR',KEYS[10])
        redis.call('ZADD',KEYS[13],r.enqueued_at_ms,r.task_id)
        redis.call('HSET',KEYS[1],'record',cjson.encode(r)); redis.call('ZADD',KEYS[2],r.ready_sequence,r.task_id)
        if redis.call('SADD',KEYS[4],r.scope)==1 then redis.call('RPUSH',KEYS[3],r.scope) end
        local cp=r.stage..':'..r.lane..':'
        redis.call('HINCRBY',KEYS[8],cp..previous,-1); redis.call('HINCRBY',KEYS[8],cp..'pending',1); return 1
    LUA;
}
