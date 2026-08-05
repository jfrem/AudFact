<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;
use RuntimeException;

/**
 * Serializa la persistencia dentro de cada job sin bloquear jobs independientes.
 */
class AuditPersistenceQueue
{
    public const ENQUEUE_DUPLICATE = 0;
    public const ENQUEUE_DISPATCHED = 1;
    public const ENQUEUE_PENDING = 2;

    private const DEFAULT_TTL_SECONDS = 604800;
    private const KEY_PREFIX = 'audit.persistence:{queue}:';

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function enqueue(AuditEvent $event): int
    {
        return $this->enqueueInternal($event, false);
    }

    public function reprocess(AuditEvent $event): int
    {
        return $this->enqueueInternal($event, true);
    }

    public function advance(AuditEvent $event): bool
    {
        $auditId = self::requirePersistenceEvent($event);
        $scope = self::scopeFor($event);
        $ttl = self::ttlSeconds();

        $result = $this->redis->eval(
            self::ADVANCE_LUA,
            [
                self::activeKey($scope),
                self::pendingKey($scope),
                self::commandsKey($scope),
                self::seenKey($scope),
                AuditEventPublisher::STREAM_PERSISTENCE,
            ],
            [$auditId, $ttl]
        );

        if (!is_int($result) && !is_numeric($result)) {
            throw new RuntimeException('Redis devolvió una respuesta inválida al avanzar persistencia');
        }

        return (int) $result > 0;
    }

    private function enqueueInternal(AuditEvent $event, bool $force): int
    {
        $auditId = self::requirePersistenceEvent($event);
        $scope = self::scopeFor($event);
        $ttl = self::ttlSeconds();

        $result = $this->redis->eval(
            self::ENQUEUE_LUA,
            [
                self::activeKey($scope),
                self::pendingKey($scope),
                self::commandsKey($scope),
                self::seenKey($scope),
                self::sequenceKey(),
                AuditEventPublisher::STREAM_PERSISTENCE,
            ],
            [$auditId, $event->toJson(), $event->eventId, $ttl, $force ? 1 : 0]
        );

        if (!is_int($result) && !is_numeric($result)) {
            throw new RuntimeException('Redis devolvió una respuesta inválida al encolar persistencia');
        }

        $result = (int) $result;
        if (!in_array($result, [
            self::ENQUEUE_DUPLICATE,
            self::ENQUEUE_DISPATCHED,
            self::ENQUEUE_PENDING,
        ], true)) {
            throw new RuntimeException("Resultado de encolado de persistencia desconocido: {$result}");
        }

        return $result;
    }

    private static function requirePersistenceEvent(AuditEvent $event): string
    {
        if ($event->eventType !== AuditEvent::TYPE_RULES_EVALUATED) {
            throw new \InvalidArgumentException('La cola de persistencia solo acepta rules_evaluated');
        }
        if ($event->auditId === null) {
            throw new \InvalidArgumentException('rules_evaluated sin audit_id');
        }

        return $event->auditId;
    }

    private static function scopeFor(AuditEvent $event): string
    {
        return $event->jobId !== null
            ? 'job:' . $event->jobId
            : 'audit:' . (string) $event->auditId;
    }

    private static function activeKey(string $scope): string
    {
        return self::KEY_PREFIX . $scope . ':active';
    }

    private static function pendingKey(string $scope): string
    {
        return self::KEY_PREFIX . $scope . ':pending';
    }

    private static function commandsKey(string $scope): string
    {
        return self::KEY_PREFIX . $scope . ':commands';
    }

    private static function seenKey(string $scope): string
    {
        return self::KEY_PREFIX . $scope . ':seen';
    }

    private static function sequenceKey(): string
    {
        return self::KEY_PREFIX . 'sequence';
    }

    private static function ttlSeconds(): int
    {
        $value = (int) Env::get('AUDIT_PERSISTENCE_QUEUE_TTL', self::DEFAULT_TTL_SECONDS);

        return $value > 0 ? $value : self::DEFAULT_TTL_SECONDS;
    }

    private const ENQUEUE_LUA = <<<'LUA'
        local auditId = ARGV[1]
        local eventJson = ARGV[2]
        local eventId = ARGV[3]
        local ttl = tonumber(ARGV[4])
        local force = tonumber(ARGV[5])
        local activeAuditId = redis.call('GET', KEYS[1])

        if force == 1 then
            if activeAuditId == auditId or redis.call('HEXISTS', KEYS[3], auditId) == 1 then
                return 0
            end
            redis.call('HDEL', KEYS[4], auditId)
        end

        if redis.call('HEXISTS', KEYS[4], auditId) == 1 then
            return 0
        end

        redis.call('HSET', KEYS[4], auditId, eventId)
        redis.call('EXPIRE', KEYS[4], ttl)
        redis.call('HSET', KEYS[3], auditId, eventJson)
        redis.call('EXPIRE', KEYS[3], ttl)

        if not activeAuditId then
            redis.call('SET', KEYS[1], auditId, 'EX', ttl)
            redis.call('XADD', KEYS[6], '*', 'event', eventJson)
            return 1
        end

        local sequence = redis.call('INCR', KEYS[5])
        redis.call('EXPIRE', KEYS[5], ttl)
        redis.call('ZADD', KEYS[2], sequence, auditId)
        redis.call('EXPIRE', KEYS[2], ttl)
        return 2
    LUA;

    private const ADVANCE_LUA = <<<'LUA'
        local auditId = ARGV[1]
        local ttl = tonumber(ARGV[2])
        local activeAuditId = redis.call('GET', KEYS[1])

        if activeAuditId ~= auditId then
            if redis.call('HEXISTS', KEYS[4], auditId) == 1 then
                return 3
            end
            return 0
        end

        redis.call('HDEL', KEYS[3], auditId)

        while true do
            local nextEntry = redis.call('ZPOPMIN', KEYS[2], 1)
            if #nextEntry == 0 then
                redis.call('DEL', KEYS[1])
                redis.call('DEL', KEYS[2])
                if redis.call('HLEN', KEYS[3]) == 0 then
                    redis.call('DEL', KEYS[3])
                end
                return 1
            end

            local nextAuditId = nextEntry[1]
            local nextEvent = redis.call('HGET', KEYS[3], nextAuditId)
            if nextEvent then
                redis.call('SET', KEYS[1], nextAuditId, 'EX', ttl)
                redis.call('EXPIRE', KEYS[2], ttl)
                redis.call('EXPIRE', KEYS[3], ttl)
                redis.call('XADD', KEYS[5], '*', 'event', nextEvent)
                return 2
            end
        end
    LUA;
}
