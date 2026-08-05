<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

/**
 * Gestiona la caché de extracción Gemini en Redis.
 *
 * Responsabilidades:
 * - Calcular la clave compuesta (documento + contrato + prompt + versión)
 * - Leer extracción desde Redis (cache get)
 * - Escribir extracción en Redis con TTL (cache put)
 *
 * @see DocumentExtractionWorker — delegante principal
 */
final class ExtractionCacheManager
{
    private const CACHE_PREFIX = 'extraction:cache:v1:';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $cacheTtl,
        private readonly string $extractorVersion
    ) {}

    /**
     * Computa la cache key compuesta.
     * Cualquier cambio en documento, contrato, prompt efectivo o versión invalida el cache.
     *
     * INVARIANTE: Produce el mismo valor que DocumentExtractionWorker::compositeCacheKey()
     * para los mismos inputs. Verificar con test unitario antes de desplegar.
     */
    public function computeCacheKey(
        string $documentHash,
        string $contractHash,
        string $promptContextHash
    ): string {
        if ($contractHash === '' || $promptContextHash === '') {
            throw new RuntimeException(
                "Faltan hashes de contrato o prompt para documentHash {$documentHash}"
            );
        }

        $composite = hash(
            'sha256',
            $documentHash . $contractHash . $promptContextHash . $this->extractorVersion
        );

        return self::CACHE_PREFIX . $composite;
    }

    /**
     * Lee la extracción desde Redis.
     *
     * @return array<string,mixed>|null  null = cache miss
     * @throws RuntimeException si Redis no está disponible (crítico para el pipeline)
     * @throws RuntimeException si el JSON en Redis está corrupto
     */
    public function get(string $cacheKey): ?array
    {
        try {
            $raw = $this->redis->get($cacheKey);
        } catch (RedisUnavailableException $e) {
            // Redis no disponible = fallo crítico: NO silenciar.
            // El worker fallará y el evento se reencola via XAUTOCLAIM.
            throw new RuntimeException('Redis no disponible al leer extraction cache', 0, $e);
        }

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Extraction cache corrupto en Redis');
        }

        return $decoded;
    }

    /**
     * Escribe la extracción en Redis con TTL.
     *
     * @param array<string,mixed> $payload
     * @throws RuntimeException si la serialización o escritura falla
     */
    public function put(string $cacheKey, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar extraction cache');
        }

        if (!$this->redis->set($cacheKey, $encoded, $this->cacheTtl)) {
            throw new RuntimeException('No se pudo escribir extraction cache en Redis');
        }
    }
}
