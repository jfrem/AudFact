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
 * - Calcular la clave compuesta (documento + contrato + prompt + versión + maxPages)
 * - Leer extracción desde Redis validando el envelope certificado con `page_count`
 * - Escribir extracción en Redis junto con `page_count` y TTL (cache put)
 *
 * @see DocumentExtractionWorker — delegante principal
 */
final class ExtractionCacheManager
{
    private const CACHE_PREFIX = 'extraction:cache:v1:';
    private readonly int $maxPages;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $cacheTtl,
        private readonly string $extractorVersion,
        int $maxPages = DocumentPdfRasterizer::DEFAULT_MAX_PAGES
    ) {
        $this->maxPages = DocumentPdfRasterizer::normalizeMaxPages($maxPages);
    }

    /**
     * Computa la cache key compuesta.
     * Cualquier cambio en documento, contrato, prompt efectivo, versión o límite de páginas invalida el cache.
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
            $documentHash . $contractHash . $promptContextHash . $this->extractorVersion . ':maxp:' . $this->maxPages
        );

        return self::CACHE_PREFIX . $composite;
    }

    /**
     * Lee la extracción verificando integridad de conteo de páginas.
     * Retorna null si es un cache miss, si la entrada es legacy (sin page_count válido >= 1)
     * o si page_count > maxPages.
     *
     * @return array{extracted: array<string,mixed>, page_count: int}|null
     * @throws RuntimeException si Redis no está disponible o el JSON está corrupto
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

        // Validación estricta del envelope:
        if (!isset($decoded['page_count']) || !is_int($decoded['page_count']) || $decoded['page_count'] < 1) {
            return null; // Formato legacy o inválido: cache miss seguro para forzar re-evaluación
        }

        if (!isset($decoded['extracted']) || !is_array($decoded['extracted'])) {
            return null;
        }

        if ($decoded['page_count'] > $this->maxPages) {
            return null; // Excede límite actual: cache miss para forzar rechazo
        }

        return [
            'extracted'  => $decoded['extracted'],
            'page_count' => $decoded['page_count'],
        ];
    }

    /**
     * Escribe la extracción junto con el conteo certificado de páginas.
     *
     * @param array<string,mixed> $payload
     * @param int $pageCount Número real de páginas del documento (>= 1)
     * @throws RuntimeException si la serialización o escritura falla en Redis
     */
    public function put(string $cacheKey, array $payload, int $pageCount): void
    {
        if ($pageCount < 1) {
            throw new RuntimeException('ExtractionCacheManager: pageCount debe ser >= 1');
        }

        $envelope = [
            'extracted'  => $payload,
            'page_count' => $pageCount,
        ];

        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar extraction cache');
        }

        if (!$this->redis->set($cacheKey, $encoded, $this->cacheTtl)) {
            throw new RuntimeException('No se pudo escribir extraction cache en Redis');
        }
    }
}
