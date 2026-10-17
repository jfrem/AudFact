<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

final class AuditTaskOutbox
{
    public function __construct(private readonly RedisClient $redis, private readonly AuditPersistenceQueue $persistence)
    {
    }

    public function flush(int $limit = 100): int
    {
        $ids = $this->redis->eval('if redis.call("SCARD",KEYS[1])>0 then return redis.error_reply("commit_repair_required") end; return redis.call("ZRANGE",KEYS[2],0,ARGV[1]-1)',
            [AuditTaskCommitter::PENDING, AuditTaskCommitter::OUTBOX], [$limit]);
        if (!is_array($ids)) {
            throw new \RuntimeException('outbox_index_invalid');
        }
        $published = 0;
        foreach ($ids as $id) {
            $key = 'audit:outbox:v2:' . $id;
            $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [$key]);
            if (!is_string($raw)) {
                throw new \RuntimeException('outbox_record_missing');
            }
            $effect = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $event = AuditEvent::fromArray($effect['event']);
            $sent = 'audit:outbox:v2:sent:' . $id;
            if (in_array($effect['destination'], ['persistence_queue', 'failure_finalize', 'dead_letter'], true)) {
                $owner = AuditEvent::uuidV4();
                $claimed = $this->redis->eval(self::CLAIM, [$key, $sent], [$owner]);
                if ($claimed !== 1) {
                    continue;
                }
                if ($effect['destination'] === 'persistence_queue') {
                    $this->persistence->enqueue($event);
                } elseif ($effect['destination'] === 'failure_finalize') {
                    (new AuditFailureFinalizer($this->redis))->finalize($event);
                } else {
                    (new AuditEventPublisher($this->redis))->publishDeadLetter($event);
                }
                $confirmed = $this->redis->eval(self::CONFIRM, [$key, $sent, AuditTaskCommitter::OUTBOX, 'audit:execution:refs:' . $event->auditId], [$owner, $id]);
                if ($confirmed !== 1) {
                    throw new \RuntimeException('outbox_confirmation_stale');
                }
            } else {
                $stream = AuditEventPublisher::streamForEvent($event);
                if ($effect['destination'] !== 'stream:' . $stream) {
                    throw new \RuntimeException('outbox_destination_invalid');
                }
                $confirmed = $this->redis->eval(self::PUBLISH, [$key, $sent, AuditTaskCommitter::OUTBOX, $stream, 'audit:execution:refs:' . $event->auditId], [$event->toJson(), $id]);
                if (!is_string($confirmed)) {
                    throw new \RuntimeException('outbox_publication_invalid');
                }
            }
            $published++;
        }
        return $published;
    }

    private const PUBLISH = <<<'LUA'
        local types={'hash','string','zset','stream','set'}
        for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('outbox_key_type_invalid') end end
        local existing=redis.call('GET',KEYS[2]); if existing then redis.call('ZREM',KEYS[3],ARGV[2]); redis.call('SREM',KEYS[5],'outbox:'..ARGV[2]); return existing end
        local ty=redis.call('TYPE',KEYS[4]).ok; if ty~='none' and ty~='stream' then return redis.error_reply('outbox_stream_invalid') end
        local id=redis.call('XADD',KEYS[4],'*','event',ARGV[1])
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        redis.call('SET',KEYS[2],id); redis.call('HSET',KEYS[1],'state','sent','sent_at_ms',now); redis.call('ZREM',KEYS[3],ARGV[2]); redis.call('SREM',KEYS[5],'outbox:'..ARGV[2]); return id
    LUA;

    private const CLAIM = <<<'LUA'
        local types={'hash','string'}; for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('outbox_key_type_invalid') end end
        if redis.call('EXISTS',KEYS[2])==1 then return 0 end
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        local untilMs=tonumber(redis.call('HGET',KEYS[1],'lease_until_ms') or '0'); if untilMs>now then return 0 end
        redis.call('HSET',KEYS[1],'owner',ARGV[1],'lease_until_ms',now+600000); return 1
    LUA;

    private const CONFIRM = <<<'LUA'
        local types={'hash','string','zset','set'}; for i,k in ipairs(KEYS) do local ty=redis.call('TYPE',k).ok; if ty~='none' and ty~=types[i] then return redis.error_reply('outbox_key_type_invalid') end end
        if redis.call('HGET',KEYS[1],'owner')~=ARGV[1] then return 0 end
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        if tonumber(redis.call('HGET',KEYS[1],'lease_until_ms') or '0')<=now then return 0 end
        redis.call('SET',KEYS[2],'persistence_queue'); redis.call('HSET',KEYS[1],'state','sent','sent_at_ms',now); redis.call('ZREM',KEYS[3],ARGV[2]); redis.call('SREM',KEYS[4],'outbox:'..ARGV[2]); return 1
    LUA;
}
