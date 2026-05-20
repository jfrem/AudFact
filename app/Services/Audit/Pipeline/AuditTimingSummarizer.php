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
    public static function buildPhaseTimings(array $audit): array
    {
        $samples = self::emptyPhaseTimingSamples();

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
        ];
    }

    /**
     * Calcula la duración total de la auditoría en milisegundos, restando el tiempo
     * dedicado a llamadas a Gemini desde el tiempo transcurrido entre creación y
     * finalización.
     * @param  array<string,mixed> $audit
     */
    public static function resolveDurationMs(array $audit): int
    {
        $createdAt = $audit['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        try {
            $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
            $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }

        $diffUs = ((int) $now->format('U') - (int) $created->format('U')) * 1_000_000
            + ((int) $now->format('u') - (int) $created->format('u'));
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
