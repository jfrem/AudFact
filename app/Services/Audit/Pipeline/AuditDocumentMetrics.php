<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use Core\RedisClient;

/** Contadores agregados, sin referencias documentales ni contenido clínico. */
final class AuditDocumentMetrics
{
    private const KEY = 'telemetry:document_cache';
    private const COUNTERS = ['admitted', 'bypassed', 'hits', 'misses', 'released', 'recovered', 'recovery_failed', 'hash_mismatch', 'prefetch_pauses'];

    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function record(string $counter, int $durationMs = 0): void
    {
        if (!in_array($counter, self::COUNTERS, true) || $durationMs < 0) {
            throw new \InvalidArgumentException('Contador documental inválido');
        }
        try {
            $result = $this->redis->eval(
                'redis.call("HINCRBY",KEYS[1],ARGV[1],1); if ARGV[1]=="recovered" then redis.call("HINCRBY",KEYS[1],"recovery_duration_ms",ARGV[2]) end; return 1',
                [self::KEY], [$counter, $durationMs]
            );
            if ($result !== 1) {
                throw new \RuntimeException('Contador documental no confirmado');
            }
        } catch (\Throwable $error) {
            Logger::warning('AuditDocumentMetrics: contador no registrado', ['counter' => $counter, 'error_class' => $error::class]);
        }
    }

    public function snapshot(): array
    {
        $raw = $this->redis->eval(
            'return {redis.call("GET",KEYS[1]) or "0",redis.call("ZCARD",KEYS[2]),redis.call("HGETALL",KEYS[3])}',
            ['audit:document-cache:{budget}:bytes', 'audit:document-cache:{budget}:expires', self::KEY]
        );
        if (!is_array($raw) || count($raw) !== 3 || !is_numeric($raw[0]) || !is_numeric($raw[1])
            || !is_array($raw[2]) || count($raw[2]) % 2 !== 0 || (int) $raw[0] < 0 || (int) $raw[1] < 0) {
            throw new \RuntimeException('document_cache_metrics_invalid');
        }
        $counters = array_fill_keys(array_merge(self::COUNTERS, ['recovery_duration_ms', 'prefetch_wait_ms']), 0);
        for ($i = 0; $i < count($raw[2]); $i += 2) {
            if (!is_numeric($raw[2][$i + 1]) || (int) $raw[2][$i + 1] < 0) {
                throw new \RuntimeException('document_cache_metrics_invalid');
            }
            if (array_key_exists((string) $raw[2][$i], $counters)) {
                $counters[(string) $raw[2][$i]] = (int) $raw[2][$i + 1];
            }
        }
        $base = new AuditDocumentCache($this->redis);
        $priority = $base->forLane(AuditLane::PRIORITY)->usage();
        $batch = $base->forLane(AuditLane::BATCH)->usage();

        return [
            'reserved_bytes' => (int) $raw[0] + $priority['reserved_bytes'] + $batch['reserved_bytes'],
            'reserved_entries' => (int) $raw[1] + $priority['reserved_entries'] + $batch['reserved_entries'],
            'limits' => $base->configuration(),
            'counters' => $counters,
            'lanes' => [
                'priority' => self::laneView($priority),
                'batch' => self::laneView($batch),
            ],
        ];
    }

    /** @param array<string,int> $usage Salida de `AuditDocumentCache::usage()` de un carril. */
    private static function laneView(array $usage): array
    {
        return [
            'reserved_bytes' => $usage['reserved_bytes'],
            'reserved_entries' => $usage['reserved_entries'],
            'max_bytes' => $usage['max_bytes'],
            'max_entries' => $usage['max_entries'],
            'oldest_age_ms' => $usage['oldest_age_ms'],
        ];
    }

    public function recordPrefetchWait(int $durationMs): void
    {
        if ($durationMs < 0) {
            throw new \InvalidArgumentException('Duración de espera inválida');
        }
        try {
            $result = $this->redis->eval('return redis.call("HINCRBY",KEYS[1],"prefetch_wait_ms",ARGV[1])',
                [self::KEY], [$durationMs]);
            if (!is_numeric($result) || (int) $result < 0) {
                throw new \RuntimeException('Espera de prefetch no confirmada');
            }
        } catch (\Throwable $error) {
            Logger::warning('AuditDocumentMetrics: espera no registrada', ['error_class' => $error::class]);
        }
    }
}
