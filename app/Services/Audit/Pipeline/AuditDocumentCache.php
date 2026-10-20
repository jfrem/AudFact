<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;
use RuntimeException;

/** Caché prescindible de bytes; los eventos conservan la fuente y el hash esperado. */
final class AuditDocumentCache
{
    public const DEFAULT_TTL_SECONDS = 3600;
    private const INDEX = 'audit:document-cache:{budget}';
    private const PRUNE_LIMIT = 64;

    private RedisClient $redis;
    private int $maxBytes;
    private int $maxEntries;

    public function __construct(
        ?RedisClient $redis = null,
        ?int $maxBytes = null,
        ?int $maxEntries = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
        $this->maxBytes = $maxBytes ?? self::positiveEnvInt('AUDIT_DOCUMENT_CACHE_MAX_BYTES', 268435456);
        $this->maxEntries = $maxEntries ?? self::positiveEnvInt('AUDIT_DOCUMENT_CACHE_MAX_ENTRIES', 1024);
        if ($this->maxBytes < 1 || $this->maxEntries < 1 || $ttlSeconds < 1) {
            throw new \InvalidArgumentException('Los límites de caché documental deben ser positivos');
        }
    }

    /** @param array{mime:string,data:string,duration_ms?:int} $document */
    public function put(string $key, array $document): bool
    {
        self::assertKey($key);
        if (is_string($document['data'] ?? null) && strlen($document['data']) > $this->maxBytes) {
            (new AuditDocumentMetrics($this->redis))->record('bypassed');
            return false;
        }
        $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Un documento grande se recuperará desde su origen al tomar un turno de extracción.
        if (strlen($encoded) > $this->maxBytes) {
            (new AuditDocumentMetrics($this->redis))->record('bypassed');
            return false;
        }
        $result = $this->redis->eval(self::PUT_LUA, $this->keys($key), [
            $encoded, strlen($encoded), $this->maxBytes, $this->maxEntries, $this->ttlSeconds, self::PRUNE_LIMIT,
        ]);
        if ($result !== 0 && $result !== 1) {
            throw new RuntimeException('Redis no confirmó la admisión de caché documental');
        }
        (new AuditDocumentMetrics($this->redis))->record($result === 1 ? 'admitted' : 'bypassed');
        return $result === 1;
    }

    public function read(string $key): ?array
    {
        self::assertKey($key);
        $raw = $this->redis->get($key);
        if ($raw === null || $raw === '') {
            (new AuditDocumentMetrics($this->redis))->record('misses');
            return null;
        }
        (new AuditDocumentMetrics($this->redis))->record('hits');
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new RuntimeException('Formato de BLOB inválido en Redis');
        }
        return $document;
    }

    /** Libera tanto entradas presupuestadas como BLOB de productores anteriores. */
    public function release(string $key): void
    {
        self::assertKey($key);
        $result = $this->redis->eval(self::RELEASE_LUA, $this->keys($key), []);
        if ($result !== 1) {
            throw new RuntimeException('Redis no confirmó la liberación de caché documental');
        }
        (new AuditDocumentMetrics($this->redis))->record('released');
    }

    public function configuration(): array
    {
        return ['max_bytes' => $this->maxBytes, 'max_entries' => $this->maxEntries, 'ttl_seconds' => $this->ttlSeconds];
    }

    /** Lee capacidad y recupera reservas vencidas aun cuando las descargas estén pausadas. */
    public function usage(): array
    {
        $raw = $this->redis->eval(self::USAGE_LUA, $this->keys('audit:blob:budget-inspection'), [self::PRUNE_LIMIT]);
        if (!is_array($raw) || count($raw) !== 2
            || !is_numeric($raw[0]) || !is_numeric($raw[1])
            || (int) $raw[0] < 0 || (int) $raw[1] < 0) {
            throw new RuntimeException('document_cache_usage_invalid');
        }

        return ['reserved_bytes' => (int) $raw[0], 'reserved_entries' => (int) $raw[1],
            'max_bytes' => $this->maxBytes, 'max_entries' => $this->maxEntries];
    }

    private function keys(string $key): array
    {
        return [$key, self::INDEX . ':expires', self::INDEX . ':sizes', self::INDEX . ':bytes'];
    }

    private static function assertKey(string $key): void
    {
        if (!str_starts_with($key, 'audit:blob:') || strlen($key) > 512) {
            throw new \InvalidArgumentException('Referencia de BLOB documental inválida');
        }
    }

    private static function positiveEnvInt(string $key, int $default): int
    {
        $raw = (string) Env::get($key, (string) $default);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("{$key} debe ser un entero positivo");
        }
        return (int) $raw;
    }

    private const PRUNE_LUA = <<<'LUA'
        local function prune(limit)
            local time = redis.call('TIME')
            local now = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
            local expired = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', now, 'LIMIT', 0, tonumber(limit))
            local used = tonumber(redis.call('GET', KEYS[4]) or '0')
            for _, key in ipairs(expired) do
                used = used - tonumber(redis.call('HGET', KEYS[3], key) or '0')
                redis.call('HDEL', KEYS[3], key)
                redis.call('ZREM', KEYS[2], key)
                redis.call('DEL', key)
            end
            used = math.max(0, used)
            redis.call('SET', KEYS[4], used)
            return used, now
        end
    LUA;

    private const USAGE_LUA = self::PRUNE_LUA . <<<'LUA'

        local used = prune(ARGV[1])
        return {used, redis.call('ZCARD', KEYS[2])}
    LUA;

    private const PUT_LUA = self::PRUNE_LUA . <<<'LUA'

        local used, now = prune(ARGV[6])
        local previous = tonumber(redis.call('HGET', KEYS[3], KEYS[1]) or '0')
        local size = tonumber(ARGV[2])
        local entries = redis.call('ZCARD', KEYS[2])
        if used - previous + size > tonumber(ARGV[3])
        or (previous == 0 and entries >= tonumber(ARGV[4])) then
            return 0
        end
        redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[5]))
        redis.call('HSET', KEYS[3], KEYS[1], size)
        redis.call('ZADD', KEYS[2], now + tonumber(ARGV[5]) * 1000, KEYS[1])
        redis.call('SET', KEYS[4], used - previous + size)
        return 1
    LUA;

    private const RELEASE_LUA = <<<'LUA'
        local size = tonumber(redis.call('HGET', KEYS[3], KEYS[1]) or '0')
        local used = tonumber(redis.call('GET', KEYS[4]) or '0')
        redis.call('DEL', KEYS[1])
        redis.call('HDEL', KEYS[3], KEYS[1])
        redis.call('ZREM', KEYS[2], KEYS[1])
        redis.call('SET', KEYS[4], math.max(0, used - size))
        return 1
    LUA;
}
