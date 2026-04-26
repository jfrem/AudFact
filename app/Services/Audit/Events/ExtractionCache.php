<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use Core\Env;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class ExtractionCache
{
    private const DEFAULT_TTL = 86400;
    private const DEFAULT_EXTRACTOR_VERSION = 'gemini-3.x-items-v2';

    private RedisClient $redis;
    private int $ttl;

    public function __construct(?RedisClient $redis = null, ?int $ttl = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();

        $resolvedTtl = $ttl ?? (int) Env::get('AUDIT_EXTRACTION_CACHE_TTL', self::DEFAULT_TTL);
        $this->ttl = $resolvedTtl > 0 ? $resolvedTtl : self::DEFAULT_TTL;
    }

    public function get(string $documentHash): ?array
    {
        self::assertHash($documentHash);

        try {
            $raw = $this->redis->get(self::key($documentHash));
        } catch (RedisUnavailableException $e) {
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

    public function put(string $documentHash, array $payload): bool
    {
        self::assertHash($documentHash);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar extraction cache');
        }

        if (!$this->redis->set(self::key($documentHash), $encoded, $this->ttl)) {
            throw new RuntimeException('No se pudo escribir extraction cache en Redis');
        }

        return true;
    }

    public static function key(string $documentHash): string
    {
        self::assertHash($documentHash);
        $version = trim((string) Env::get('AUDIT_VERSION_EXTRACTOR', self::DEFAULT_EXTRACTOR_VERSION));
        $sanitizedVersion = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $version);
        if (!is_string($sanitizedVersion) || $sanitizedVersion === '') {
            $sanitizedVersion = self::DEFAULT_EXTRACTOR_VERSION;
        }

        return "extraction:cache:{$sanitizedVersion}:{$documentHash}";
    }

    private static function assertHash(string $documentHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $documentHash)) {
            throw new RuntimeException("document_hash inválido: {$documentHash}");
        }
    }
}
