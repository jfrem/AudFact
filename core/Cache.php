<?php

namespace Core;

use Core\RedisUnavailableException;

/**
 * Cache — Abstracción de caché read-through sobre RedisClient.
 *
 * Provee una interfaz simplificada para cacheo de resultados con
 * estrategias de read-through (lazy load), hash-based invalidation
 * y protección anti-stampede con mutex distribuido.
 *
 * @since 3.1
 */
class Cache
{
    private static ?RedisClient $redis = null;

    /** Máximo de reintentos para adquirir datos tras lock ajeno */
    private const LOCK_RETRY_ATTEMPTS = 3;

    /** Milisegundos entre reintentos de lectura */
    private const LOCK_RETRY_WAIT_MS = 50;

    /** TTL del lock distribuido en segundos (auto-release) */
    private const LOCK_TTL_SECONDS = 10;

    /**
     * Obtiene la instancia de RedisClient (lazy init).
     */
    private static function redis(): RedisClient
    {
        if (self::$redis === null) {
            self::$redis = RedisClient::getInstance();
        }
        return self::$redis;
    }

    /**
     * Verifica si el backend de caché está disponible.
     */
    public static function isAvailable(): bool
    {
        return self::redis()->isAvailable();
    }

    /**
     * Obtiene un valor de caché. Retorna null si no existe o Redis no disponible.
     *
     * @param string $key Clave sin prefijo (el prefijo Redis se aplica internamente)
     * @return mixed Datos deserializados o null
     */
    public static function get(string $key): mixed
    {
        try {
            $raw = self::redis()->get($key);
        } catch (RedisUnavailableException $e) {
            Logger::warning('Cache::get degradado — Redis no disponible', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        return $data !== null ? $data : $raw;
    }

    /**
     * Almacena un valor en caché con TTL.
     *
     * @param string   $key   Clave de caché
     * @param mixed    $value Datos a cachear (se serializa a JSON)
     * @param int|null $ttl   TTL en segundos (null = sin expiración)
     */
    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        return self::redis()->set($key, $serialized, $ttl);
    }

    /**
     * Read-through con mutex anti-stampede: busca en caché, si no existe
     * adquiere lock distribuido, ejecuta callback, y cachea el resultado.
     *
     * Si otro worker ya tiene el lock, espera brevemente y reintenta leer
     * del caché. Solo ejecuta el callback como último recurso. (Fix: REDIS-004)
     *
     * @param string   $key      Clave de caché
     * @param callable $callback Función que genera el valor si no existe en caché
     * @param int|null $ttl      TTL en segundos
     * @return mixed Datos del caché o del callback
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        // 1. Lectura rápida del caché
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Intentar adquirir mutex distribuido
        $lockKey = "lock:{$key}";
        $lockAcquired = self::redis()->setnx($lockKey, '1', self::LOCK_TTL_SECONDS);

        if ($lockAcquired) {
            // Lock adquirido: este worker recalcula
            try {
                $value = $callback();
                if ($value !== null) {
                    self::set($key, $value, $ttl);
                }
                return $value;
            } finally {
                // Liberar lock (best-effort, el TTL es safety net)
                self::redis()->del($lockKey);
            }
        }

        // 3. Lock NO adquirido: otro worker está recalculando.
        //    Esperar brevemente y reintentar leer del caché.
        for ($i = 0; $i < self::LOCK_RETRY_ATTEMPTS; $i++) {
            usleep(self::LOCK_RETRY_WAIT_MS * 1000);
            $cached = self::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 4. Último recurso: ejecutar callback sin lock (evitar deadlock por timeout)
        Logger::warning('Cache::remember — ejecutando callback sin lock (reintentos agotados)', [
            'key' => $key,
        ]);
        $value = $callback();
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Elimina una clave de caché.
     */
    public static function forget(string $key): bool
    {
        return self::redis()->del($key);
    }

    /**
     * Verifica si una clave existe en caché.
     */
    public static function has(string $key): bool
    {
        return self::redis()->exists($key);
    }

    /**
     * Genera un hash para detección de cambios en datos de dispensación.
     *
     * Usado para determinar si una factura necesita re-auditoría:
     * si el hash de los datos no cambió desde la última auditoría, skip Gemini.
     *
     * @param array $dispensationData Datos de dispensación normalizados
     * @param array $attachmentIds   IDs de adjuntos disponibles
     * @return string Hash SHA-256
     */
    public static function computeDispensationHash(array $dispensationData, array $attachmentIds = []): string
    {
        // Campos relevantes para el hash (excluir metadata como timestamps)
        $hashFields = [
            'CUM'                => $dispensationData['CUM'] ?? '',
            'CantidadPrescrita'  => $dispensationData['CantidadPrescrita'] ?? '',
            'CodigoDiagnostico'  => $dispensationData['CodigoDiagnostico'] ?? '',
            'DocumentoMedico'    => $dispensationData['DocumentoMedico'] ?? '',
            'FechaNacimiento'    => $dispensationData['FechaNacimiento'] ?? '',
            'NombrePaciente'     => $dispensationData['NombrePaciente'] ?? '',
        ];

        // Incluir IDs de adjuntos (si cambian los documentos, re-auditar)
        sort($attachmentIds);
        $hashFields['_attachments'] = implode(',', $attachmentIds);

        return hash('sha256', json_encode($hashFields, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verifica si los datos de una factura cambiaron desde la última auditoría.
     *
     * @param string $facSec           Identificador de factura
     * @param string $currentHash      Hash actual de los datos
     * @return bool true si los datos cambiaron o no hay hash previo
     */
    public static function hasDispensationChanged(string $facSec, string $currentHash): bool
    {
        try {
            $storedHash = self::redis()->get("audit:hash:{$facSec}");
        } catch (RedisUnavailableException $e) {
            // Redis caído: asumir que los datos cambiaron (conservador, fuerza re-auditoría)
            Logger::warning('Cache::hasDispensationChanged degradado — asumiendo cambio', [
                'facSec' => $facSec,
                'error'  => $e->getMessage(),
            ]);
            return true;
        }
        return $storedHash !== $currentHash;
    }

    /**
     * Almacena el hash de dispensación para futuras comparaciones.
     */
    public static function storeDispensationHash(string $facSec, string $hash, ?int $ttl = null): bool
    {
        $cacheTtl = $ttl ?? (int) Env::get('AUDIT_CACHE_TTL', 86400);
        return self::redis()->set("audit:hash:{$facSec}", $hash, $cacheTtl);
    }

    /**
     * Invalida caché de resultados de consulta (para cuando se persisten nuevos resultados).
     *
     * Borra las keys que matcheen el patrón query:results:* para un NIT dado.
     * Nota: Usa DEL directo por key conocida, no SCAN/KEYS (seguro para producción).
     *
     * @param string $facNitSec NIT del cliente para invalidar
     */
    public static function invalidateQueryResults(string $facNitSec): void
    {
        // Invalidar caché de query results para este NIT
        self::forget("query:results:{$facNitSec}");
        // Invalidar también la caché general sin filtros
        self::forget('query:results:all');
        
        self::incrementQueryResultsVersion($facNitSec);
        self::incrementQueryResultsVersion('all');
    }

    /**
     * Obtiene la versión actual de los resultados cacheados para un scope.
     *
     * @param string $scope Identificador de alcance (ej. 'all' o un NIT)
     * @return int Versión actual
     */
    public static function getQueryResultsVersion(string $scope): int
    {
        $v = self::get("query:results:version:{$scope}");
        return $v !== null ? (int) $v : 0;
    }

    /**
     * Incrementa la versión de los resultados para forzar re-cálculo en caché versionada.
     *
     * @param string $scope Identificador de alcance
     * @return int Nueva versión
     */
    public static function incrementQueryResultsVersion(string $scope): int
    {
        try {
            return (int) self::redis()->incr("query:results:version:{$scope}");
        } catch (RedisUnavailableException $e) {
            return 0;
        }
    }
}
