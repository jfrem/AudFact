<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;

/** Presupuesto común de todos los intentos; nunca devuelve cargos inciertos. */
final class GeminiQuotaBudget
{
    private array $limits;
    private string $base;

    public function __construct(private readonly RedisClient $redis, ?array $limits = null)
    {
        $this->limits = $limits ?? [
            'scope' => (string) Env::get('GEMINI_QUOTA_SCOPE', ''), 'model' => (string) Env::get('GEMINI_MODEL', ''),
            'rpm' => AuditExecutionConfig::integer('GEMINI_QUOTA_RPM', null, 1),
            'tpm' => AuditExecutionConfig::integer('GEMINI_QUOTA_TPM', null, 1),
            'rpd' => AuditExecutionConfig::integer('GEMINI_QUOTA_RPD', null, 1),
            'inflight' => AuditExecutionConfig::integer('GEMINI_QUOTA_MAX_INFLIGHT', 4, 1, 32),
        ];
        if (strlen($this->limits['scope'] ?? '') < 1 || strlen($this->limits['scope']) > 128
            || $this->limits['scope'] === 'UNCONFIGURED' || !is_string($this->limits['model'] ?? null) || $this->limits['model'] === '') {
            throw new \InvalidArgumentException('gemini_quota_scope_required');
        }
        foreach (['rpm', 'tpm', 'rpd', 'inflight'] as $key) {
            if (!is_int($this->limits[$key] ?? null) || $this->limits[$key] < 1) {
                throw new \InvalidArgumentException('gemini_quota_limits_invalid');
            }
        }
        if ($this->limits['inflight'] > 32) {
            throw new \InvalidArgumentException('gemini_quota_limits_invalid');
        }
        $this->base = 'gemini:quota:v2:' . hash('sha256', $this->limits['scope']) . ':' . hash('sha256', $this->limits['model']);
    }

    public function reserve(array $request): array
    {
        $task = new AuditTask($request['task']);
        if (!in_array($request['operation'] ?? '', ['count_tokens', 'generate_content'], true)
            || !is_int($request['tokens'] ?? null) || $request['tokens'] < 0
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($request['body_hash'] ?? '')) !== 1) {
            throw new \DomainException('gemini_quota_request_invalid');
        }
        if ($request['tokens'] > $this->limits['tpm']) {
            throw new \DomainException($task->record['stage'] === AuditTaskStage::SEMANTIC->value
                ? 'semantic_input_too_large' : 'gemini_quota_request_too_large');
        }
        $this->recoverExpired();
        $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig()))->nowMs();
        [$date, $start, $end] = self::dayWindow($now);
        $attempt = AuditEvent::uuidV4();
        $demand = hash('sha256', $task->id() . ':' . $request['operation'] . ':' . $request['body_hash']);
        $value = [
            'id' => $demand, 'task_id' => $task->id(), 'stage' => $task->record['stage'], 'lane' => $task->record['lane'],
            'scope' => $task->record['scope'], 'tokens' => $request['tokens'], 'lease_until_ms' => $task->record['lease_until_ms'],
            'owner' => $task->record['lease_owner'], 'fence' => $task->record['fence'], 'operation' => $request['operation'],
            'audit_id' => $task->record['audit_id'], 'document_id' => $task->record['document_id'],
            'job_id' => $task->record['job_id'],
            'quota_base' => $this->base,
            'request_id' => hash('sha256', $task->id() . ':' . $request['body_hash']),
        ];
        $keys = $this->keys($date);
        $keys = array_merge($keys, [FairAuditTaskQueue::taskKey($task->id()), 'gemini:attempt:v2:' . $attempt,
            'audit:gemini:v2:attempts:' . $task->record['audit_id']]);
        $waiters = $this->redis->eval('return redis.call("HVALS",KEYS[1])', [$this->base . ':waiters']);
        if (!is_array($waiters)) {
            throw new \RuntimeException('quota_waiters_invalid');
        }
        $indices = [$task->id() => 8];
        foreach ($waiters as $rawWaiter) {
            $waiter = json_decode($rawWaiter, true, 512, JSON_THROW_ON_ERROR);
            $id = $waiter['task_id'];
            if (!isset($indices[$id])) {
                $keys[] = FairAuditTaskQueue::taskKey($id);
                $indices[$id] = count($keys);
            }
        }
        $raw = $this->redis->eval(self::RESERVE, $keys,
            [json_encode($value, JSON_THROW_ON_ERROR), $this->limits['rpm'], $this->limits['tpm'], $this->limits['rpd'],
                $this->limits['inflight'], $start, $end, $attempt, $request['body_hash'], json_encode($indices, JSON_THROW_ON_ERROR)]);
        if (!is_string($raw)) {
            throw new \RuntimeException('gemini_quota_response_invalid');
        }
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($result['status'] ?? '', ['GRANTED', 'DEFERRED'], true)) {
            throw new \RuntimeException('gemini_quota_response_invalid');
        }
        return $result;
    }

    public static function dayWindow(int $milliseconds): array
    {
        $time = (new \DateTimeImmutable('@' . intdiv($milliseconds, 1000)))->setTimezone(new \DateTimeZone('America/Los_Angeles'));
        $start = $time->setTime(0, 0);
        $end = $start->modify('+1 day');
        return [$start->format('Y-m-d'), $start->getTimestamp() * 1000, $end->getTimestamp() * 1000];
    }

    public function finish(string $attemptId, array $usage): void
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $attemptId) !== 1) {
            throw new \InvalidArgumentException('attempt_id_invalid');
        }
        $raw = $this->redis->eval('return redis.call("GET",KEYS[1])', ['gemini:attempt:v2:' . $attemptId]);
        if (!is_string($raw)) {
            throw new \RuntimeException('gemini_attempt_missing');
        }
        $attempt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $date = self::dayWindow((int) $attempt['started_at_ms'])[0];
        $result = $this->redis->eval(self::FINISH, array_merge($this->keys($date), ['gemini:attempt:v2:' . $attemptId]),
            [$attemptId, json_encode($usage, JSON_THROW_ON_ERROR)]);
        if ($result !== 1) {
            throw new \RuntimeException('gemini_attempt_finish_invalid');
        }
    }

    public function metrics(): array
    {
        $this->recoverExpired();
        $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig()))->nowMs();
        $date = self::dayWindow($now)[0];
        $raw = $this->redis->eval('local t=redis.call("TIME"); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); for _,id in ipairs(redis.call("ZRANGEBYSCORE",KEYS[1],"-inf",now-60000)) do redis.call("HDEL",KEYS[2],id) end; redis.call("ZREMRANGEBYSCORE",KEYS[1],"-inf",now-60000); local sum=0; for _,v in ipairs(redis.call("HVALS",KEYS[2])) do sum=sum+tonumber(v) end; return {redis.call("ZCARD",KEYS[1]),sum,tonumber(redis.call("GET",KEYS[3]) or "0"),redis.call("ZCARD",KEYS[4]),redis.call("HGETALL",KEYS[7])}', $this->keys($date));
        if (!is_array($raw) || count($raw) !== 5 || !is_array($raw[4])) {
            throw new \RuntimeException('gemini_budget_metrics_invalid');
        }
        $metrics = [];
        for ($i = 0; $i < count($raw[4]); $i += 2) {
            $metrics[$raw[4][$i]] = (int) $raw[4][$i + 1];
        }
        return ['scope_hash' => hash('sha256', $this->limits['scope']), 'model' => $this->limits['model'],
            'rpm_used' => (int) $raw[0], 'tpm_reserved' => (int) $raw[1], 'rpd_used' => (int) $raw[2], 'inflight' => (int) $raw[3]] + $metrics;
    }

    public function failContract(string $attemptId): void
    {
        if (!AuditEvent::isUuidV4($attemptId)) {
            throw new \InvalidArgumentException('attempt_id_invalid');
        }
        $this->redis->eval('local types={"string","hash"}; for i,k in ipairs(KEYS) do local ty=redis.call("TYPE",k).ok; if ty~="none" and ty~=types[i] then return redis.error_reply("quota_key_type_invalid") end end; local raw=redis.call("GET",KEYS[1]); if not raw then return redis.error_reply("gemini_attempt_missing") end; local r=cjson.decode(raw); local field=r.stage.."_request_failures"; local previous=redis.call("HGET",KEYS[2],field); if previous and not tonumber(previous) then return redis.error_reply("quota_metric_invalid") end; if r.state=="completed" then r.state="failed"; r.contract_invalid=true; redis.call("SET",KEYS[1],cjson.encode(r)); redis.call("HINCRBY",KEYS[2],field,1) end; return 1',
            ['gemini:attempt:v2:' . $attemptId, $this->base . ':metrics']);
    }

    /** Un lease vencido libera concurrencia, conserva cargos y deja evidencia unknown. */
    public function recoverExpired(int $limit = 100): int
    {
        $limit = max(1, min(100, $limit));
        $ids = $this->redis->eval('local t=redis.call("TIME"); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); return redis.call("ZRANGEBYSCORE",KEYS[1],"-inf",now,"LIMIT",0,ARGV[1])', [$this->base . ':inflight'], [$limit]);
        if (!is_array($ids)) {
            throw new \RuntimeException('quota_inflight_index_invalid');
        }
        if ($ids === []) {
            return 0;
        }
        $keys = [$this->base . ':inflight', $this->base . ':metrics'];
        foreach ($ids as $id) {
            $keys[] = 'gemini:attempt:v2:' . $id;
        }
        return (int) $this->redis->eval(self::RECOVER_EXPIRED, $keys, [json_encode($ids, JSON_THROW_ON_ERROR)]);
    }

    private function keys(string $date): array
    {
        return [$this->base . ':minute', $this->base . ':tokens', $this->base . ':day:' . $date,
            $this->base . ':inflight', $this->base . ':waiters', $this->base . ':turn', $this->base . ':metrics'];
    }

    private const RESERVE = <<<'LUA'
        local d=cjson.decode(ARGV[1]); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if now<tonumber(ARGV[6]) or now>=tonumber(ARGV[7]) then return redis.error_reply('quota_day_window_changed') end
        local taskRaw=redis.call('HGET',KEYS[8],'record'); if not taskRaw then return redis.error_reply('quota_task_missing') end
        local task=cjson.decode(taskRaw)
        if task.state~='active' or task.lease_owner~=d.owner or task.fence~=d.fence or task.lease_until_ms<=now then return redis.error_reply('quota_task_stale') end
        local types={'zset','hash','string','zset','hash','hash','hash','hash','string','set'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=(types[i] or 'hash') then return redis.error_reply('quota_key_type_invalid') end end
        for _,id in ipairs(redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',now-60000)) do redis.call('HDEL',KEYS[2],id) end
        redis.call('ZREMRANGEBYSCORE',KEYS[1],'-inf',now-60000)
        local old=redis.call('HGET',KEYS[5],d.id)
        d.sequence=old and cjson.decode(old).sequence or now
        redis.call('HSET',KEYS[5],d.id,cjson.encode(d))
        local demands={}; local lanes={}
        local indices=cjson.decode(ARGV[10])
        local entries=redis.call('HGETALL',KEYS[5])
        for i=1,#entries,2 do
            local v=cjson.decode(entries[i+1]); local index=indices[v.task_id]
            local record=index and redis.call('HGET',KEYS[index],'record') or nil
            if record then
                local waiting=cjson.decode(record)
                if waiting.state=='completed' or waiting.state=='failed' then redis.call('HDEL',KEYS[5],entries[i])
                elseif waiting.state=='active' and waiting.fence==v.fence and waiting.lease_owner==v.owner and waiting.lease_until_ms>now then
                    table.insert(demands,v); lanes[v.lane]=true
                end
            elseif index then redis.call('HDEL',KEYS[5],entries[i]) end
        end
        local turn=tonumber(redis.call('HGET',KEYS[6],'lane_turn') or '0')
        local lane=(turn%4==3) and 'batch' or 'priority'; if not lanes[lane] then lane=(lane=='batch') and 'priority' or 'batch' end
        local lastStage=redis.call('HGET',KEYS[6],'stage:'..lane)
        local stage=(lastStage=='extraction') and 'semantic' or 'extraction'; local available=false
        for _,v in ipairs(demands) do if v.lane==lane and v.stage==stage then available=true end end
        if not available then stage=(stage=='extraction') and 'semantic' or 'extraction' end
        local winner=nil; local lastScope=redis.call('HGET',KEYS[6],'scope:'..lane..':'..stage)
        for _,v in ipairs(demands) do
            if v.lane==lane and v.stage==stage then
                if not winner or (winner.scope==lastScope and v.scope~=lastScope) or (v.scope~=lastScope and winner.scope~=lastScope and v.sequence<winner.sequence) then winner=v end
            end
        end
        if winner and winner.id~=d.id then return cjson.encode({status='DEFERRED',due_at_ms=now+250,reason='quota_turn'}) end
        local sum=0; for _,v in ipairs(redis.call('HVALS',KEYS[2])) do sum=sum+tonumber(v) end
        local day=tonumber(redis.call('GET',KEYS[3]) or '0'); local due=now+250; local reason=nil
        if day>=tonumber(ARGV[4]) then due=tonumber(ARGV[7]); reason='quota_rpd'
        elseif redis.call('ZCARD',KEYS[4])>=tonumber(ARGV[5]) then local first=redis.call('ZRANGE',KEYS[4],0,0,'WITHSCORES'); due=math.min(now+250,tonumber(first[2])+1); reason='quota_inflight'
        elseif redis.call('ZCARD',KEYS[1])>=tonumber(ARGV[2]) or sum+d.tokens>tonumber(ARGV[3]) then local first=redis.call('ZRANGE',KEYS[1],0,0,'WITHSCORES'); due=tonumber(first[2])+60001; reason='quota_minute' end
        if reason then return cjson.encode({status='DEFERRED',due_at_ms=due,reason=reason}) end
        redis.call('ZADD',KEYS[1],now,ARGV[8]); redis.call('HSET',KEYS[2],ARGV[8],d.tokens)
        redis.call('INCR',KEYS[3])
        redis.call('ZADD',KEYS[4],d.lease_until_ms,ARGV[8]); redis.call('HDEL',KEYS[5],d.id)
        redis.call('HINCRBY',KEYS[6],'lane_turn',1); redis.call('HSET',KEYS[6],'stage:'..lane,stage,'scope:'..lane..':'..stage,d.scope)
        local receipt={attempt_id=ARGV[8],request_id=d.request_id,task_id=d.task_id,audit_id=d.audit_id,document_id=d.document_id,job_id=d.job_id,stage=d.stage,quota_base=d.quota_base,operation=d.operation,body_hash=ARGV[9],started_at_ms=now,expires_at_ms=d.lease_until_ms,state='inflight',reserved_input_tokens=d.tokens}
        redis.call('SET',KEYS[9],cjson.encode(receipt))
        redis.call('SADD',KEYS[10],ARGV[8])
        redis.call('HINCRBY',KEYS[7],d.stage..'_http_attempts',1)
        redis.call('PEXPIREAT',KEYS[3],tonumber(ARGV[7])+604800000)
        return cjson.encode({status='GRANTED',attempt_id=ARGV[8],request_id=d.request_id,expires_at_ms=d.lease_until_ms,operation=d.operation,body_hash=ARGV[9]})
    LUA;

    private const FINISH = <<<'LUA'
        local types={'zset','hash','string','zset','hash','hash','hash','string'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('quota_key_type_invalid') end end
        local raw=redis.call('GET',KEYS[8]); if not raw then return redis.error_reply('gemini_attempt_missing') end
        local receipt=cjson.decode(raw); if receipt.state~='inflight' and receipt.state~='unknown' then return 1 end
        local usage=cjson.decode(ARGV[2]); local reported=tonumber(usage.promptTokenCount or 0)
        if receipt.state=='unknown' and usage.unknown then return 1 end
        local reserved=tonumber(redis.call('HGET',KEYS[2],ARGV[1]) or '0')
        if receipt.operation=='generate_content' and reported>reserved and redis.call('ZSCORE',KEYS[1],ARGV[1]) then
            redis.call('HSET',KEYS[2],ARGV[1],reported); redis.call('HINCRBY',KEYS[7],'input_estimation_overruns',1)
        end
        redis.call('HINCRBY',KEYS[7],'tpm_reported_total',reported)
        if usage.unknown then
            redis.call('HINCRBY',KEYS[7],'unknown_attempts',1); redis.call('HINCRBY',KEYS[7],'unknown_attempts_total',1)
            redis.call('HINCRBY',KEYS[7],(receipt.stage or 'extraction')..'_unknown_attempts',1)
        elseif receipt.state=='unknown' then redis.call('HINCRBY',KEYS[7],'unknown_attempts',-1); redis.call('HINCRBY',KEYS[7],(receipt.stage or 'extraction')..'_unknown_attempts',-1) end
        if usage.error and not receipt.usage then redis.call('HINCRBY',KEYS[7],(receipt.stage or 'extraction')..'_request_failures',1) end
        local t=redis.call('TIME'); receipt.finished_at_ms=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        receipt.state=usage.unknown and 'unknown' or (usage.error and 'failed' or 'completed'); receipt.usage=usage
        redis.call('SET',KEYS[8],cjson.encode(receipt)); redis.call('ZREM',KEYS[4],ARGV[1]); return 1
    LUA;

    private const RECOVER_EXPIRED = <<<'LUA'
        for i,k in ipairs(KEYS) do local expected=i==1 and 'zset' or (i==2 and 'hash' or 'string'); local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=expected then return redis.error_reply('quota_key_type_invalid') end end
        local ids=cjson.decode(ARGV[1]); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); local count=0
        for i,id in ipairs(ids) do
            local score=redis.call('ZSCORE',KEYS[1],id)
            if score and tonumber(score)<=now then
                local raw=redis.call('GET',KEYS[i+2]); if not raw then return redis.error_reply('gemini_attempt_missing') end
                local r=cjson.decode(raw)
                if r.state=='inflight' then
                    r.state='unknown'; r.finished_at_ms=now; redis.call('SET',KEYS[i+2],cjson.encode(r))
                    redis.call('HINCRBY',KEYS[2],'unknown_attempts',1); redis.call('HINCRBY',KEYS[2],'unknown_attempts_total',1)
                    redis.call('HINCRBY',KEYS[2],(r.stage or 'extraction')..'_unknown_attempts',1)
                end
                redis.call('ZREM',KEYS[1],id); count=count+1
            end
        end
        return count
    LUA;
}
