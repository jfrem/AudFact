<?php

namespace Core;

use Core\RedisUnavailableException;

/**
 * RedisClient — Singleton thread-safe para conexión centralizada a Redis.
 *
 * Provee operaciones atómicas para caché, circuit breaker y rate limiting
 * compartidos entre las réplicas PHP-FPM.
 *
 * Soporta modos de conexión: standalone, sentinel, cluster (REDIS_MODE).
 *
 * @since 3.1
 */
class RedisClient
{
    private static ?self $instance = null;

    private ?\Predis\Client $client = null;
    private string $prefix;
    private bool $connected = false;

    private function __construct()
    {
        $this->prefix = (string) Env::get('REDIS_PREFIX', 'audfact:');
    }

    /**
     * Obtiene la instancia singleton del cliente Redis.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Conexión lazy a Redis. No conecta hasta el primer uso.
     */
    private function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $mode = strtolower((string) Env::get('REDIS_MODE', 'standalone'));
        $password = Env::get('REDIS_PASSWORD', '');
        $persistent = (bool) Env::get('REDIS_PERSISTENT', false);

        try {
            switch ($mode) {
                case 'sentinel':
                    $this->client = $this->connectSentinel($password, $persistent);
                    break;

                case 'cluster':
                    $this->client = $this->connectCluster($password, $persistent);
                    break;

                default: // standalone
                    $this->client = $this->connectStandalone($password, $persistent);
                    break;
            }

            $this->client->ping();
            $this->connected = true;
        } catch (\Exception $e) {
            Logger::error('Redis: Conexión fallida', [
                'mode'  => $mode,
                'error' => $e->getMessage(),
            ]);
            $this->client = null;
            $this->connected = false;
        }
    }

    /**
     * Conexión standalone (single node).
     */
    private function connectStandalone(string $password, bool $persistent): \Predis\Client
    {
        $host = (string) Env::get('REDIS_HOST', 'redis');
        $port = (int) Env::get('REDIS_PORT', 6379);

        $params = [
            'scheme' => 'tcp',
            'host'   => $host,
            'port'   => $port,
            'read_write_timeout' => 10,
            'timeout' => 5,
        ];

        if ($password !== '' && $password !== null) {
            $params['password'] = $password;
        }
        if ($persistent) {
            $params['persistent'] = true;
        }

        return new \Predis\Client($params);
    }

    /**
     * Conexión Redis Sentinel (HA con failover automático).
     *
     * Requiere: REDIS_SENTINELS (host1:port1,host2:port2,...) y REDIS_SENTINEL_SERVICE.
     */
    private function connectSentinel(string $password, bool $persistent): \Predis\Client
    {
        $sentinelsRaw = (string) Env::get('REDIS_SENTINELS', '');
        $service = (string) Env::get('REDIS_SENTINEL_SERVICE', 'mymaster');

        $sentinels = array_map(function ($s) {
            $parts = explode(':', trim($s));
            return [
                'scheme' => 'tcp',
                'host'   => $parts[0],
                'port'   => (int) ($parts[1] ?? 26379),
            ];
        }, array_filter(explode(',', $sentinelsRaw)));

        if (empty($sentinels)) {
            throw new \RuntimeException('REDIS_SENTINELS no configurado para modo sentinel');
        }

        $options = [
            'replication' => 'sentinel',
            'service'     => $service,
        ];

        if ($password !== '' && $password !== null) {
            $options['parameters'] = ['password' => $password];
        }

        return new \Predis\Client($sentinels, $options);
    }

    /**
     * Conexión Redis Cluster (sharding nativo).
     *
     * Requiere: REDIS_CLUSTER_NODES (host1:port1,host2:port2,...)
     */
    private function connectCluster(string $password, bool $persistent): \Predis\Client
    {
        $nodesRaw = (string) Env::get('REDIS_CLUSTER_NODES', '');

        $nodes = array_map(function ($n) {
            $parts = explode(':', trim($n));
            return [
                'scheme' => 'tcp',
                'host'   => $parts[0],
                'port'   => (int) ($parts[1] ?? 6379),
            ];
        }, array_filter(explode(',', $nodesRaw)));

        if (empty($nodes)) {
            throw new \RuntimeException('REDIS_CLUSTER_NODES no configurado para modo cluster');
        }

        $options = ['cluster' => 'redis'];

        if ($password !== '' && $password !== null) {
            $options['parameters'] = ['password' => $password];
        }

        return new \Predis\Client($nodes, $options);
    }

    /**
     * Verifica si Redis está disponible.
     */
    public function isAvailable(): bool
    {
        if (!$this->connected) {
            $this->connect();
        }
        return $this->connected && $this->client !== null;
    }

    /**
     * GET con prefijo.
     *
     * @return string|null Valor almacenado o null si no existe
     * @throws RedisUnavailableException Si Redis no está disponible o la operación falla
     */
    public function get(string $key): ?string
    {
        if (!$this->isAvailable()) {
            throw new RedisUnavailableException("Redis no disponible para GET key: {$key}");
        }

        try {
            $result = $this->client->get($this->prefix . $key);
            return $result !== null ? (string) $result : null;
        } catch (\Exception $e) {
            Logger::warning('Redis GET falló', ['key' => $key, 'error' => $e->getMessage()]);
            throw new RedisUnavailableException("Redis GET falló para key: {$key}", 0, $e);
        }
    }

    /**
     * SET con prefijo y TTL opcional.
     *
     * @param int|null $ttl Segundos de expiración (null = sin expiración)
     */
    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $prefixedKey = $this->prefix . $key;
            if ($ttl !== null && $ttl > 0) {
                $this->client->setex($prefixedKey, $ttl, $value);
            } else {
                $this->client->set($prefixedKey, $value);
            }
            return true;
        } catch (\Exception $e) {
            Logger::warning('Redis SET falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * INCR atómico con TTL (para contadores de CB y rate limiting).
     *
     * Si se provee TTL, delega a incrWithExpire() para garantizar atomicidad.
     *
     * @return int|null Valor incrementado o null si Redis no disponible
     */
    public function incr(string $key, ?int $ttl = null): ?int
    {
        // Si TTL presente, usar la versión atómica Lua (REDIS-005)
        if ($ttl !== null && $ttl > 0) {
            return $this->incrWithExpire($key, $ttl);
        }

        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $prefixedKey = $this->prefix . $key;
            return (int) $this->client->incr($prefixedKey);
        } catch (\Exception $e) {
            Logger::warning('Redis INCR falló', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * INCR + EXPIRE atómico vía Lua script.
     *
     * Previene keys huérfanas (sin TTL) que ocurren si el proceso muere
     * entre INCR y EXPIRE separados. (Fix: REDIS-005)
     *
     * @param string $key Clave (sin prefijo)
     * @param int    $ttl TTL en segundos
     * @return int|null Valor incrementado o null si Redis no disponible
     */
    public function incrWithExpire(string $key, int $ttl): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $lua = <<<'LUA'
local val = redis.call('INCR', KEYS[1])
if val == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
return val
LUA;

        try {
            $result = $this->eval($lua, [$key], [(string) $ttl]);
            return (int) $result;
        } catch (\Exception $e) {
            Logger::warning('Redis INCR+EXPIRE Lua falló', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * SETNX — SET if Not eXists con TTL atómico.
     *
     * Primitiva para distributed mutex locks (Cache stampede prevention).
     * Usa SET key value NX EX ttl internamente. (Fix: REDIS-004)
     *
     * @param string $key   Clave (sin prefijo)
     * @param string $value Valor a setear
     * @param int    $ttl   TTL en segundos (auto-release del lock)
     * @return bool true si el lock fue adquirido, false si ya existía o Redis caído
     */
    public function setnx(string $key, string $value, int $ttl): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $prefixedKey = $this->prefix . $key;
            // SET key value NX EX ttl → retorna 'OK' o null
            $result = $this->client->set($prefixedKey, $value, 'EX', $ttl, 'NX');
            return $result !== null && (string) $result === 'OK';
        } catch (\Exception $e) {
            Logger::warning('Redis SETNX falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * DELETE con prefijo.
     */
    public function del(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $this->client->del([$this->prefix . $key]);
            return true;
        } catch (\Exception $e) {
            Logger::warning('Redis DEL falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * EXISTS con prefijo.
     */
    public function exists(string $key): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return (bool) $this->client->exists($this->prefix . $key);
        } catch (\Exception $e) {
            Logger::warning('Redis EXISTS falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * TTL restante de una key (en segundos).
     *
     * @return int -2 si no existe, -1 si no tiene TTL, >0 si tiene TTL
     */
    public function ttl(string $key): int
    {
        if (!$this->isAvailable()) {
            return -2;
        }

        try {
            return (int) $this->client->ttl($this->prefix . $key);
        } catch (\Exception $e) {
            return -2;
        }
    }

    // ── Redis Lists (Queue operations) ──────────────────────────────

    /**
     * LPUSH — Agrega un elemento al inicio de la lista.
     *
     * @param string $key   Lista Redis
     * @param string $value Valor a insertar
     * @return int|null Longitud de la lista después del push, o null si falla
     */
    public function lpush(string $key, string $value): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            return (int) $this->client->lpush($this->prefix . $key, [$value]);
        } catch (\Exception $e) {
            Logger::warning('Redis LPUSH falló', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * BRPOP — Bloquea hasta obtener un elemento del final de la lista (o timeout).
     *
     * @param string $key     Lista Redis
     * @param int    $timeout Segundos de espera (0 = indefinido)
     * @return string|null Valor extraído o null si timeout/falla
     */
    public function brpop(string $key, int $timeout = 5): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $result = $this->client->brpop([$this->prefix . $key], $timeout);
            // brpop retorna [key, value] o null
            return is_array($result) ? (string) $result[1] : null;
        } catch (\Exception $e) {
            Logger::warning('Redis BRPOP falló', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * EVAL — Ejecuta un script Lua atómico en Redis.
     *
     * @param string $script  Código Lua a ejecutar
     * @param array  $keys    Array de KEYS (se les aplica prefix automáticamente)
     * @param array  $argv    Array de ARGV (se pasan como string/JSON)
     * @return mixed Resultado del script
     * @throws \Exception Si Redis no está disponible o el script falla
     */
    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Redis no disponible para eval');
        }

        // Aplicar prefix a todas las KEYS
        $prefixedKeys = array_map(fn($k) => $this->prefix . $k, $keys);

        // Serializar ARGV complejos a JSON string
        $serializedArgv = array_map(function ($v) {
            return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        }, $argv);

        // Predis EVAL: numkeys, keys..., args...
        $numKeys = count($prefixedKeys);
        $evalArgs = array_merge([$script, $numKeys], $prefixedKeys, $serializedArgv);

        return $this->client->eval(...$evalArgs);
    }

    /**
     * LLEN — Longitud de una lista.
     */
    public function llen(string $key): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            return (int) $this->client->llen($this->prefix . $key);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function xAdd(string $stream, array $fields): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($fields === []) {
            throw new \InvalidArgumentException('xAdd requiere al menos un campo');
        }

        try {
            $args = [$this->prefix . $stream, '*'];
            foreach ($fields as $key => $value) {
                $args[] = (string) $key;
                $args[] = (string) $value;
            }

            $id = $this->client->executeRaw(array_merge(['XADD'], $args));
            return is_string($id) ? $id : null;
        } catch (\Exception $e) {
            Logger::error('Redis XADD falló', ['stream' => $stream, 'error' => $e->getMessage()]);
            throw new RedisUnavailableException("Redis XADD falló para stream: {$stream}", 0, $e);
        }
    }

    public function xGroupCreate(string $stream, string $group, string $id = '$'): bool
    {
        if (!$this->isAvailable()) {
            throw new RedisUnavailableException("Redis no disponible para XGROUP CREATE stream: {$stream}");
        }

        try {
            $this->client->executeRaw([
                'XGROUP', 'CREATE', $this->prefix . $stream, $group, $id, 'MKSTREAM',
            ]);
            return true;
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'BUSYGROUP') !== false) {
                return true;
            }
            throw new RedisUnavailableException(
                "Redis XGROUP CREATE falló para stream '{$stream}', group '{$group}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function xReadGroup(
        string $group,
        string $consumer,
        string $stream,
        int $count = 1,
        int $blockMs = 5000
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $raw = $this->client->executeRaw([
                'XREADGROUP',
                'GROUP', $group, $consumer,
                'COUNT', (string) $count,
                'BLOCK', (string) $blockMs,
                'STREAMS', $this->prefix . $stream, '>',
            ]);

            return $this->parseStreamsResponse($raw);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'NOGROUP') !== false) {
                throw $e;
            }
            Logger::warning('Redis XREADGROUP falló', [
                'stream' => $stream,
                'group' => $group,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function xAck(string $stream, string $group, string $id): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            $result = $this->client->executeRaw([
                'XACK', $this->prefix . $stream, $group, $id,
            ]);
            return (int) $result;
        } catch (\Exception $e) {
            Logger::warning('Redis XACK falló', [
                'stream' => $stream,
                'group' => $group,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * @return array{next: string, messages: array<int,array{id:string,fields:array<string,string>}>}
     */
    public function xAutoClaim(
        string $stream,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start = '0-0',
        int $count = 10
    ): array {
        $empty = ['next' => '0-0', 'messages' => []];

        if (!$this->isAvailable()) {
            return $empty;
        }

        try {
            $raw = $this->client->executeRaw([
                'XAUTOCLAIM',
                $this->prefix . $stream,
                $group, $consumer,
                (string) $minIdleMs,
                $start,
                'COUNT', (string) $count,
            ]);

            $normalized = $this->normalizeRedisTree($raw);
            // Redis 7 retorna [next-cursor, [[id, fields], ...], [deleted-ids]]
            $next    = (isset($normalized[0]) && is_string($normalized[0])) ? $normalized[0] : '0-0';
            $entries = (isset($normalized[1]) && is_array($normalized[1]))  ? $normalized[1] : [];

            return ['next' => $next, 'messages' => $this->parseStreamEntriesResponse($entries)];
        } catch (\Exception $e) {
            Logger::warning('Redis XAUTOCLAIM falló', ['stream' => $stream, 'error' => $e->getMessage()]);
            return $empty;
        }
    }

    public function xLen(string $stream): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        try {
            $result = $this->client->executeRaw(['XLEN', $this->prefix . $stream]);
            return (int) $result;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * XRANGE — Lee mensajes de un stream sin consumer group.
     *
     * @return array<int,array{id:string,fields:array<string,string>}>
     */
    public function xRange(
        string $stream,
        string $start = '-',
        string $end = '+',
        ?int $count = null
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $args = ['XRANGE', $this->prefix . $stream, $start, $end];
            if ($count !== null && $count > 0) {
                $args[] = 'COUNT';
                $args[] = (string) $count;
            }

            $raw = $this->client->executeRaw($args);
            return $this->parseStreamEntriesResponse($raw);
        } catch (\Exception $e) {
            Logger::warning('Redis XRANGE falló', [
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function parseStreamsResponse(mixed $raw): array
    {
        $normalized = $this->normalizeRedisTree($raw);
        if ($normalized === []) {
            return [];
        }

        $messages = [];
        foreach ($normalized as $streamBlock) {
            if (!is_array($streamBlock) || !isset($streamBlock[1]) || !is_array($streamBlock[1])) {
                continue;
            }

            foreach ($streamBlock[1] as $entry) {
                if (!is_array($entry) || count($entry) < 2) {
                    continue;
                }

                $id = (string) $entry[0];
                $fieldsArray = is_array($entry[1]) ? $entry[1] : [];

                $fields = [];
                $count = count($fieldsArray);
                for ($i = 0; $i + 1 < $count; $i += 2) {
                    $fields[(string) $fieldsArray[$i]] = (string) $fieldsArray[$i + 1];
                }

                $messages[] = ['id' => $id, 'fields' => $fields];
            }
        }

        return $messages;
    }

    private function parseStreamEntriesResponse(mixed $raw): array
    {
        $normalized = $this->normalizeRedisTree($raw);
        if ($normalized === []) {
            return [];
        }

        $messages = [];
        foreach ($normalized as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }

            $messages[] = [
                'id' => (string) $entry[0],
                'fields' => $this->parseFieldPairs($entry[1] ?? []),
            ];
        }

        return $messages;
    }

    /**
     * Materializa por completo la respuesta de Redis para evitar perder datos
     * cuando Predis devuelve iteradores MultiBulk no-rewindable.
     *
     * @return array<int,mixed>
     */
    private function normalizeRedisTree(mixed $raw): array
    {
        if ($raw === null || $raw === false || $raw === []) {
            return [];
        }

        $normalized = $this->normalizeRedisValue($raw);
        return is_array($normalized) ? $normalized : [];
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    private function parseFieldPairs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $fields = [];
        $count = count($value);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $fields[(string) $value[$i]] = (string) $value[$i + 1];
        }

        return $fields;
    }

    private function normalizeRedisValue(mixed $value): mixed
    {
        if ($value instanceof \Traversable) {
            $result = [];
            foreach ($value as $item) {
                $result[] = $this->normalizeRedisValue($item);
            }
            return $result;
        }

        if (is_array($value)) {
            return array_map([$this, 'normalizeRedisValue'], $value);
        }

        return $value;
    }

    /**
     * Previene clonación del singleton.
     */
    private function __clone() {}

    /**
     * Previene deserialización del singleton.
     */
    public function __wakeup()
    {
        throw new \RuntimeException('No se puede deserializar un singleton');
    }
}
