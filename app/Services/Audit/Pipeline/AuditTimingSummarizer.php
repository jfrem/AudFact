<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\GeminiCallMetrics;

/**
 * Encapsula la recolección y sumarización de métricas de tiempo y uso
 * del pipeline de auditoría.
 */
final class AuditTimingSummarizer
{
    /**
     * Construye los tiempos de las fases de la auditoría.
     * @param  array<string,mixed> $audit
     * @return array<string,mixed>
     */
    public static function buildPhaseTimings(array $audit, ?\DateTimeImmutable $now = null): array
    {
        $samples = self::emptyPhaseTimingSamples();
        $now ??= self::nowUtc();

        foreach ($audit['documents'] ?? [] as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $samples = self::collectDocumentTimingSamples($samples, $doc);
        }

        $allGeminiMetrics = array_merge(
            $samples['gemini_extraction_metrics'],
            $samples['gemini_semantic_metrics']
        );

        return [
            'docs_total'     => $samples['total'],
            'cache_hit_rate' => $samples['total'] > 0 ? round($samples['cache_hits'] / $samples['total'], 2) : 0.0,
            'download'       => self::summarizeTimings($samples['downloads']),
            'gemini'         => self::summarizeTimings($samples['geminis']),
            'gemini_extraction' => GeminiCallMetrics::summarize($samples['gemini_extraction_metrics']),
            'gemini_semantic'   => GeminiCallMetrics::summarize($samples['gemini_semantic_metrics']),
            'gemini_total'      => GeminiCallMetrics::summarize($allGeminiMetrics),
            'semantic_calls'    => self::countRemoteGeminiMetrics($samples['gemini_semantic_metrics']),
            'semantic_cache_hits' => $samples['semantic_cache_hits'],
            'extraction'     => self::summarizeTimings($samples['extractions']),
            'normalization'  => self::summarizeTimings($samples['normalizations']),
            'policy'         => self::summarizeTimings($samples['policies']),
            'processing_duration_ms' => self::resolveDurationMs($audit, $now),
            'queue_wait_ms'          => self::resolveQueueWaitMs($audit),
            'total_elapsed_ms'       => self::resolveTotalElapsedMs($audit, $now),
        ];
    }

    /**
     * Calcula la duración del procesamiento activo en milisegundos.
     *
     * Usa started_at como inicio activo y hace fallback a created_at para
     * auditorías creadas antes de persistir started_at.
     *
     * @param  array<string,mixed> $audit
     */
    public static function resolveDurationMs(array $audit, ?\DateTimeImmutable $now = null): int
    {
        $start = self::readTimestamp($audit, 'started_at') ?? self::readTimestamp($audit, 'created_at');
        if ($start === null) {
            return 0;
        }

        return self::diffMs($start, self::resolveEndTimestamp($audit, $now));
    }

    /**
     * Tiempo de espera en cola: started_at - created_at.
     *
     * Retorna 0 si started_at no está disponible (auditoría aún en cola
     * o single con latencia despreciable).
     *
     * @param  array<string,mixed> $audit
     */
    public static function resolveQueueWaitMs(array $audit): int
    {
        $createdAt = self::readTimestamp($audit, 'created_at');
        $startedAt = self::readTimestamp($audit, 'started_at');
        if ($createdAt === null || $startedAt === null) {
            return 0;
        }

        return self::diffMs($createdAt, $startedAt);
    }

    /**
     * Tiempo total desde encolamiento hasta cierre o hasta el reloj recibido.
     *
     * @param  array<string,mixed> $audit
     */
    public static function resolveTotalElapsedMs(array $audit, ?\DateTimeImmutable $now = null): int
    {
        $createdAt = self::readTimestamp($audit, 'created_at');
        if ($createdAt === null) {
            return 0;
        }

        return self::diffMs($createdAt, self::resolveEndTimestamp($audit, $now));
    }

    private static function resolveEndTimestamp(array $audit, ?\DateTimeImmutable $now): \DateTimeImmutable
    {
        return self::readTimestamp($audit, 'completed_at') ?? $now ?? self::nowUtc();
    }

    private static function readTimestamp(array $audit, string $key): ?\DateTimeImmutable
    {
        $value = $audit[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function diffMs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $diffUs = ((int) $to->format('U') - (int) $from->format('U')) * 1_000_000
            + ((int) $to->format('u') - (int) $from->format('u'));
        return max(0, (int) round($diffUs / 1000));
    }

    /**
     * @return array{
     *   extractions:array<int,int>,
     *   normalizations:array<int,int>,
     *   policies:array<int,int>,
     *   downloads:array<int,int>,
     *   geminis:array<int,int>,
     *   gemini_extraction_metrics:array<int,array<string,mixed>>,
     *   gemini_semantic_metrics:array<int,array<string,mixed>>,
     *   semantic_cache_hits:int,
     *   cache_hits:int,
     *   total:int
     * }
     */
    private static function emptyPhaseTimingSamples(): array
    {
        return [
            'extractions' => [],
            'normalizations' => [],
            'policies' => [],
            'downloads' => [],
            'geminis' => [],
            'gemini_extraction_metrics' => [],
            'gemini_semantic_metrics' => [],
            'semantic_cache_hits' => 0,
            'cache_hits' => 0,
            'total' => 0,
        ];
    }

    /**
     * @param  array<string,mixed> $samples
     * @param  array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function collectDocumentTimingSamples(array $samples, array $doc): array
    {
        $samples['total']++;

        foreach ([
            'extraction_duration_ms' => 'extractions',
            'normalization_duration_ms' => 'normalizations',
            'policy_duration_ms' => 'policies',
            'download_duration_ms' => 'downloads',
            'gemini_duration_ms' => 'geminis',
        ] as $sourceKey => $sampleKey) {
            if (isset($doc[$sourceKey])) {
                $samples[$sampleKey][] = (int) $doc[$sourceKey];
            }
        }

        if (is_array($doc['gemini_metrics'] ?? null)) {
            $samples['gemini_extraction_metrics'][] = $doc['gemini_metrics'];
        }

        if (is_array($doc['gemini_semantic_metrics']['semantic'] ?? null)) {
            foreach ($doc['gemini_semantic_metrics']['semantic'] as $metric) {
                if (is_array($metric)) {
                    $samples['gemini_semantic_metrics'][] = $metric;
                }
            }
        }

        $samples['semantic_cache_hits'] += (int) ($doc['gemini_semantic_metrics']['semantic_cache_hits'] ?? 0);
        if ($doc['cache_hit'] ?? false) {
            $samples['cache_hits']++;
        }

        return $samples;
    }

    /**
     * Cuenta solo llamadas remotas; las muestras cache_hit preservan observabilidad
     * en los resúmenes, pero no representan una llamada a Gemini.
     *
     * @param  array<int,array<string,mixed>> $metrics
     */
    private static function countRemoteGeminiMetrics(array $metrics): int
    {
        return count(array_filter(
            $metrics,
            static fn(array $metric): bool => ($metric['cache_hit'] ?? false) !== true
        ));
    }

    /**
     * Resume los tiempos de un array de valores enteros.
     * @param  array<int,int> $values
     * @return array<string,int|float>
     */
    private static function summarizeTimings(array $values): array
    {
        if ($values === []) {
            return ['count' => 0];
        }

        sort($values);
        $count    = count($values);
        $p95index = max(0, (int) ceil($count * 0.95) - 1);

        return [
            'count'  => $count,
            'avg_ms' => (int) (array_sum($values) / $count),
            'min_ms' => $values[0],
            'max_ms' => $values[$count - 1],
            'p95_ms' => $values[$p95index],
        ];
    }
}
