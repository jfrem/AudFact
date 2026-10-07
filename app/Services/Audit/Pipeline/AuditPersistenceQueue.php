<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;
use RuntimeException;

/**
 * Limita la persistencia por slots estables de cada job.
 */
class AuditPersistenceQueue
{
    public const ENQUEUE_DUPLICATE = 0;
    public const ENQUEUE_DISPATCHED = 1;
    public const ENQUEUE_PENDING = 2;

    private const DEFAULT_TTL_SECONDS = 604800;
    private const DEFAULT_JOB_SLOTS = 2;
    private const MAX_JOB_SLOTS = 16;
    private const MAX_RECONCILIATION_PAGES = 1000;
    private const KEY_PREFIX = 'audit.persistence:{queue}:';

    private RedisClient $redis;
    private int $jobSlots;

    public function __construct(?RedisClient $redis = null, ?int $jobSlots = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
        $configured = $jobSlots ?? Env::get('AUDIT_PERSISTENCE_JOB_SLOTS', self::DEFAULT_JOB_SLOTS);
        $validated = filter_var($configured, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_JOB_SLOTS],
        ]);
        if ($validated === false) {
            throw new \InvalidArgumentException('AUDIT_PERSISTENCE_JOB_SLOTS debe ser un entero entre 1 y 16');
        }
        $this->jobSlots = $validated;
    }

    /** @return array{job_slots:int,queue_ttl:int} */
    public function configuration(): array
    {
        return ['job_slots' => $this->jobSlots, 'queue_ttl' => self::ttlSeconds()];
    }

    /** @return array{active:int,pending:int,scopes:int} */
    public function metrics(): array
    {
        $result = $this->readMetrics();
        if ((int) $result[3] !== 1) {
            $this->reconcileScopes();
            $result = $this->readMetrics();
            if ($result[3] !== 1) {
                throw new RuntimeException('Índice de persistencia incompleto');
            }
        }
        return ['active' => (int) $result[0], 'pending' => (int) $result[1], 'scopes' => (int) $result[2]];
    }

    /** @return list<int> */
    private function readMetrics(): array
    {
        $result = $this->redis->eval(self::METRICS_LUA, [self::KEY_PREFIX . 'scopes']);
        if (!is_array($result) || !array_is_list($result) || count($result) !== 4) {
            throw new RuntimeException('Respuesta inválida de métricas del scheduler de persistencia');
        }
        foreach ($result as $i => $value) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($validated === false || ($i === 3 && $validated > 1)) {
                throw new RuntimeException('Contador de persistencia inválido');
            }
            $result[$i] = $validated;
        }
        return $result;
    }

    private function reconcileScopes(): void
    {
        $cursor = '0';
        $token = '__reconciling:' . bin2hex(random_bytes(8));
        for ($page = 0; $page < self::MAX_RECONCILIATION_PAGES; $page++) {
            $cursor = $this->redis->eval(self::RECONCILE_PAGE_LUA,
                [self::KEY_PREFIX . 'scopes'], [$cursor, self::ttlSeconds(), $token]);
            if ((!is_string($cursor) && !is_int($cursor)) || !ctype_digit((string) $cursor)) {
                throw new RuntimeException('Cursor inválido al reconciliar persistencia');
            }
            $cursor = (string) $cursor;
            if ($cursor === '0') {
                $ready = $this->redis->eval(self::MARK_INDEX_READY_LUA,
                    [self::KEY_PREFIX . 'scopes'], [self::ttlSeconds(), $token]);
                if ($ready !== 1 && $ready !== '1') {
                    throw new RuntimeException('Redis no confirmó la reconciliación de persistencia');
                }
                return;
            }
        }
        throw new RuntimeException('Reconciliación de persistencia excedió el límite de páginas');
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
        return $this->advanceInternal($event, false);
    }

    /** Resuelve un turno fallido, incluso si su estado/turno ya expiró. */
    public function advanceAfterFailure(AuditEvent $event): bool
    {
        return $this->advanceInternal($event, true);
    }

    private function advanceInternal(AuditEvent $event, bool $terminal): bool
    {
        $auditId = self::requirePersistenceEvent($event);
        ['scope' => $scope, 'slots' => $slots] = $this->scopeFor($event);
        $ttl = self::ttlSeconds();

        $stream = AuditEventPublisher::isPriorityEvent($event)
            ? AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY
            : AuditEventPublisher::STREAM_PERSISTENCE_BATCH;

        $result = $this->redis->eval(
            self::ADVANCE_LUA,
            [
                self::activeKey($scope),
                self::pendingKey($scope),
                self::commandsKey($scope),
                self::seenKey($scope),
                $stream,
                self::slotsKey($event),
                self::KEY_PREFIX . 'scopes',
            ],
            [$auditId, $ttl, $terminal ? 1 : 0, $scope, $slots]
        );

        if (!is_int($result) && !is_numeric($result)) {
            throw new RuntimeException('Redis devolvió una respuesta inválida al avanzar persistencia');
        }

        return (int) $result > 0;
    }

    private function enqueueInternal(AuditEvent $event, bool $force): int
    {
        $auditId = self::requirePersistenceEvent($event);
        ['scope' => $scope, 'slots' => $slots] = $this->scopeFor($event);
        $ttl = self::ttlSeconds();
        $stream = AuditEventPublisher::isPriorityEvent($event)
            ? AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY
            : AuditEventPublisher::STREAM_PERSISTENCE_BATCH;

        $result = $this->redis->eval(
            self::ENQUEUE_LUA,
            [
                self::activeKey($scope),
                self::pendingKey($scope),
                self::commandsKey($scope),
                self::seenKey($scope),
                self::sequenceKey(),
                $stream,
                self::slotsKey($event),
                self::KEY_PREFIX . 'scopes',
            ],
            [$auditId, $event->toJson(), $event->eventId, $ttl, $force ? 1 : 0, $scope, $slots]
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

    /** @return array{scope:string,slots:int} */
    private function scopeFor(AuditEvent $event): array
    {
        if ($event->jobId === null) {
            return ['scope' => 'audit:' . $event->auditId, 'slots' => 0];
        }
        $legacyScope = 'job:' . $event->jobId;
        $keys = [self::slotsKey($event)];
        $scopes = [$legacyScope];
        for ($slot = 0; $slot < self::MAX_JOB_SLOTS; $slot++) {
            $scopes[] = $legacyScope . ':slot:' . $slot;
        }
        foreach ($scopes as $scope) {
            array_push($keys, self::activeKey($scope), self::pendingKey($scope),
                self::commandsKey($scope), self::seenKey($scope));
        }
        try {
            $result = $this->redis->eval(self::RESOLVE_SLOTS_LUA, $keys, [
                $this->jobSlots, self::ttlSeconds(), $event->auditId,
                (int) hexdec(substr(hash('sha256', (string) $event->auditId), 0, 7)), $legacyScope,
            ]);
        } catch (\Exception $error) {
            throw new RuntimeException('No se pudo resolver el scope de persistencia: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($result) || count($result) !== 2) {
            throw new RuntimeException('Resolución de slots inválida en Redis');
        }
        $slots = filter_var($result[0], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_JOB_SLOTS],
        ]);
        if ($slots === false || !in_array($result[1], $scopes, true)) {
            throw new RuntimeException('Scope de persistencia inválido en Redis');
        }
        return ['scope' => $result[1], 'slots' => $slots];
    }

    private static function slotsKey(AuditEvent $event): string
    {
        return self::KEY_PREFIX . ($event->jobId !== null
            ? 'job:' . $event->jobId
            : 'audit:' . $event->auditId) . ':slots';
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

    private const RESOLVE_SLOTS_LUA = <<<'LUA'
        local function validate(value)
            local n = tonumber(value)
            if not n or n < 1 or n > 16 or n ~= math.floor(n) then
                error('Cantidad de slots almacenada invalida')
            end
            return n
        end
        local function scopeFor(n)
            if n == 1 then return ARGV[5] end
            return ARGV[5] .. ':slot:' .. (tonumber(ARGV[4]) % n)
        end
        local stored = redis.call('GET', KEYS[1])
        if stored then
            local n = validate(stored)
            return {n, scopeFor(n)}
        end
        local slots = nil
        local knownScope = nil
        local legacy = false
        local partitioned = false
        for group = 0, 16 do
            local i = 2 + group * 4
            local scope = group == 0 and ARGV[5] or (ARGV[5] .. ':slot:' .. (group - 1))
            if redis.call('EXISTS', KEYS[i], KEYS[i+1], KEYS[i+2], KEYS[i+3]) > 0 then
                if group == 0 then legacy = true else partitioned = true end
                local recorded = redis.call('HGET', KEYS[i+3], '__job_slots')
                if recorded then
                    local n = validate(recorded)
                    if slots and slots ~= n then error('Metadata de slots contradictoria') end
                    slots = n
                end
                if redis.call('GET', KEYS[i]) == ARGV[3]
                    or redis.call('ZSCORE', KEYS[i+1], ARGV[3])
                    or redis.call('HEXISTS', KEYS[i+2], ARGV[3]) == 1
                    or redis.call('HEXISTS', KEYS[i+3], ARGV[3]) == 1 then
                    if knownScope and knownScope ~= scope then error('Auditoria en varios scopes') end
                    knownScope = scope
                end
            end
        end
        if legacy and partitioned then error('Scopes legacy y particionados incompatibles') end
        if legacy then
            if slots and slots ~= 1 then error('Metadata legacy contradictoria') end
            slots = 1
        elseif partitioned and not slots then
            if knownScope then return {0, knownScope} end
            error('Job particionado sin metadata: no se admiten auditorias nuevas')
        end
        slots = slots or tonumber(ARGV[1])
        local scope = scopeFor(slots)
        if knownScope and knownScope ~= scope then error('Scope incompatible con slots recuperados') end
        redis.call('SET', KEYS[1], slots, 'EX', tonumber(ARGV[2]))
        return {slots, scope}
    LUA;

    private const RECONCILE_PAGE_LUA = <<<'LUA'
        local prefix = string.sub(KEYS[1], 1, -7)
        local now = tonumber(redis.call('TIME')[1])
        if ARGV[1] == '0' then redis.call('ZADD', KEYS[1], now + tonumber(ARGV[2]), ARGV[3]) end
        if not redis.call('ZSCORE', KEYS[1], ARGV[3]) then error('Indice perdido durante reconciliacion') end
        local page = redis.call('SCAN', ARGV[1], 'MATCH', prefix .. '*', 'COUNT', 200)
        for _, key in ipairs(page[2]) do
            local suffix = nil
            if string.sub(key, -7) == ':active' then suffix = 7 end
            if string.sub(key, -8) == ':pending' then suffix = 8 end
            if suffix then
                local scope = string.sub(key, #prefix + 1, -suffix - 1)
                local active = prefix .. scope .. ':active'
                local pending = prefix .. scope .. ':pending'
                if redis.call('EXISTS', active) > 0 or redis.call('ZCARD', pending) > 0 then
                    local ttl = math.max(redis.call('TTL', active), redis.call('TTL', pending))
                    if ttl < 1 then ttl = tonumber(ARGV[2]) end
                    redis.call('ZADD', KEYS[1], now + ttl, scope)
                end
            end
        end
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
        return page[1]
    LUA;

    private const MARK_INDEX_READY_LUA = <<<'LUA'
        if not redis.call('ZSCORE', KEYS[1], ARGV[2]) then error('Indice perdido durante reconciliacion') end
        local now = tonumber(redis.call('TIME')[1])
        redis.call('ZADD', KEYS[1], now + tonumber(ARGV[1]), '__ready')
        redis.call('ZREM', KEYS[1], ARGV[2])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
        return 1
    LUA;

    private const METRICS_LUA = <<<'LUA'
        local now = tonumber(redis.call('TIME')[1])
        local ready = tonumber(redis.call('ZSCORE', KEYS[1], '__ready')) or 0
        if ready <= now then return {0, 0, 0, 0} end
        local scopes = redis.call('ZRANGEBYSCORE', KEYS[1], now, '+inf')
        local prefix = string.sub(KEYS[1], 1, -7)
        local active = 0
        local pending = 0
        local liveScopes = 0
        for _, scope in ipairs(scopes) do
            local owner = redis.call('EXISTS', prefix .. scope .. ':active')
            local waiting = redis.call('ZCARD', prefix .. scope .. ':pending')
            active = active + owner
            pending = pending + waiting
            if owner > 0 or waiting > 0 then liveScopes = liveScopes + 1 end
        end
        return {active, pending, liveScopes, 1}
    LUA;

    private const REFRESH_SLOTS_LUA = <<<'LUA'
        local function refreshSlots(slotsKey, seenKey, slots, ttl)
            if slots > 0 then
                local stored = redis.call('GET', slotsKey)
                if stored and tonumber(stored) ~= slots then error('Slots cambiaron durante la operacion') end
                redis.call('SET', slotsKey, slots, 'EX', ttl)
                redis.call('HSET', seenKey, '__job_slots', slots)
                redis.call('EXPIRE', seenKey, ttl)
            end
        end
    LUA;

    private const ENQUEUE_LUA = self::REFRESH_SLOTS_LUA . "\n" . <<<'LUA'
        local auditId = ARGV[1]
        local eventJson = ARGV[2]
        local eventId = ARGV[3]
        local ttl = tonumber(ARGV[4])
        local force = tonumber(ARGV[5])
        local activeAuditId = redis.call('GET', KEYS[1])
        refreshSlots(KEYS[7], KEYS[4], tonumber(ARGV[7]), ttl)

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
        local now = tonumber(redis.call('TIME')[1])
        redis.call('ZREMRANGEBYSCORE', KEYS[8], '-inf', now)
        redis.call('ZADD', KEYS[8], now + ttl, ARGV[6])
        redis.call('EXPIRE', KEYS[8], ttl)

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

    private const ADVANCE_LUA = self::REFRESH_SLOTS_LUA . "\n" . <<<'LUA'
        local auditId = ARGV[1]
        local ttl = tonumber(ARGV[2])
        local activeAuditId = redis.call('GET', KEYS[1])
        refreshSlots(KEYS[6], KEYS[4], tonumber(ARGV[5]), ttl)

        if activeAuditId ~= auditId then
            if tonumber(ARGV[3]) == 1 then
                -- Retirar sólo la auditoría fallida; nunca liberar otro dueño.
                redis.call('ZREM', KEYS[2], auditId)
                redis.call('HDEL', KEYS[3], auditId)
                if activeAuditId then return 3 end
                -- Sin dueño, recuperar el siguiente pendiente en este mismo Lua.
            elseif redis.call('HEXISTS', KEYS[4], auditId) == 1 then
                return 3
            else
                return 0
            end
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
                redis.call('ZREM', KEYS[7], ARGV[4])
                return 1
            end

            local nextAuditId = nextEntry[1]
            local nextEvent = redis.call('HGET', KEYS[3], nextAuditId)
            if nextEvent then
                redis.call('SET', KEYS[1], nextAuditId, 'EX', ttl)
                redis.call('EXPIRE', KEYS[2], ttl)
                redis.call('EXPIRE', KEYS[3], ttl)
                local now = tonumber(redis.call('TIME')[1])
                redis.call('ZREMRANGEBYSCORE', KEYS[7], '-inf', now)
                redis.call('ZADD', KEYS[7], now + ttl, ARGV[4])
                redis.call('EXPIRE', KEYS[7], ttl)
                redis.call('XADD', KEYS[5], '*', 'event', nextEvent)
                return 2
            end
        end
    LUA;
}
