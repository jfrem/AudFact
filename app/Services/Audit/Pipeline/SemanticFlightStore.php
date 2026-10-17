<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/** Caché compartida con propietario/fence; no representa estado clínico. */
final class SemanticFlightStore
{
    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function claim(AuditTask $task, string $cacheKey): bool
    {
        $lock = 'semantic:flight:v2:' . hash('sha256', $cacheKey);
        $raw = $this->redis->get($lock);
        $previous = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $keys = [FairAuditTaskQueue::taskKey($task->id()), $lock,
            $previous === null ? FairAuditTaskQueue::taskKey($task->id()) : FairAuditTaskQueue::taskKey($previous['task_id'])];
        return $this->redis->eval(self::CLAIM, $keys, [json_encode($task->record, JSON_THROW_ON_ERROR), $raw ?? '']) === 1;
    }

    public function read(string $cacheKey): ?array
    {
        $raw = $this->redis->get($cacheKey);
        if ($raw === null) {
            return null;
        }
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!self::validResult($result)) {
            throw new \RuntimeException('semantic_cache_invalid');
        }
        return $result + ['source' => 'cache'];
    }

    public function write(AuditTask $task, string $cacheKey, array $result): void
    {
        $cached = array_intersect_key($result, ['is_match' => true, 'reasoning' => true]);
        if (array_diff(array_keys($result), ['is_match', 'reasoning', 'source']) !== []
            || (isset($result['source']) && !in_array($result['source'], ['remote', 'cache'], true))
            || !self::validResult($cached)) {
            throw new \InvalidArgumentException('semantic_cache_result_invalid');
        }
        $result = $cached;
        if ($this->redis->eval(self::WRITE, [FairAuditTaskQueue::taskKey($task->id()),
            'semantic:flight:v2:' . hash('sha256', $cacheKey), $cacheKey],
            [$task->id(), $task->record['fence'], $task->record['lease_owner'], json_encode($result, JSON_THROW_ON_ERROR)]) !== 1) {
            throw new \RuntimeException('semantic_cache_writer_stale');
        }
    }

    public function release(AuditTask $task, string $cacheKey): void
    {
        $this->redis->eval('local raw=redis.call("GET",KEYS[1]); if not raw then return 0 end; local r=cjson.decode(raw); if r.task_id==ARGV[1] and r.fence==tonumber(ARGV[2]) then return redis.call("DEL",KEYS[1]) end; return 0',
            ['semantic:flight:v2:' . hash('sha256', $cacheKey)], [$task->id(), $task->record['fence']]);
    }

    public static function validResult(mixed $result): bool
    {
        if (!is_array($result) || count($result) !== 2 || !is_bool($result['is_match'] ?? null)
            || !is_string($result['reasoning'] ?? null)) {
            return false;
        }
        $length = mb_strlen(trim($result['reasoning']), 'UTF-8');
        return $length >= 1 && $length <= 512;
    }

    private const CLAIM = <<<'LUA'
        local task=cjson.decode(ARGV[1]); local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        local current=cjson.decode(redis.call('HGET',KEYS[1],'record'))
        if current.state~='active' or current.fence~=task.fence or current.lease_owner~=task.lease_owner or current.lease_until_ms<=now then return 0 end
        local raw=redis.call('GET',KEYS[2]); if (raw or '')~=ARGV[2] then return 0 end
        if raw then
            local previous=cjson.decode(raw)
            if previous.task_id==task.task_id and previous.fence==task.fence then return 1 end
            local old=redis.call('HGET',KEYS[3],'record')
            if old then local r=cjson.decode(old); if r.state=='active' and r.fence==previous.fence and r.lease_until_ms>now then return 0 end end
        end
        redis.call('SET',KEYS[2],cjson.encode({task_id=task.task_id,fence=task.fence}),'PX',600000); return 1
    LUA;

    private const WRITE = <<<'LUA'
        local raw=redis.call('GET',KEYS[2]); if not raw then return 0 end
        local lock=cjson.decode(raw); local r=cjson.decode(redis.call('HGET',KEYS[1],'record'))
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if lock.task_id~=ARGV[1] or lock.fence~=tonumber(ARGV[2]) or r.state~='active' or r.fence~=lock.fence or r.lease_owner~=ARGV[3] or r.lease_until_ms<=now then return 0 end
        redis.call('SET',KEYS[3],ARGV[4],'EX',2592000); return 1
    LUA;
}
