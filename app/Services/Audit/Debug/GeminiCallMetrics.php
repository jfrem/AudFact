<?php

declare(strict_types=1);

namespace App\Services\Audit\Debug;

/**
 * Normaliza metricas no sensibles de llamadas a Gemini.
 */
final class GeminiCallMetrics
{
    /**
     * Construye metricas desde la respuesta de Gemini y el contexto operacional.
     *
     * @param  array<string,mixed> $responseBody
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function fromResponse(array $responseBody, int $durationMs, array $context = []): array
    {
        $usage = is_array($responseBody['usageMetadata'] ?? null) ? $responseBody['usageMetadata'] : [];

        return self::withoutNulls([
            'duration_ms' => max(0, $durationMs),
            'model' => self::stringOrNull($responseBody['modelVersion'] ?? null),
            'task_type' => self::stringOrNull($context['task_type'] ?? null),
            'document_type' => self::stringOrNull($context['document_type'] ?? null),
            'finish_reason' => self::stringOrNull($responseBody['candidates'][0]['finishReason'] ?? null),
            'cache_hit' => false,
            'prompt_tokens' => self::intOrNull($usage['promptTokenCount'] ?? null),
            'output_tokens' => self::intOrNull($usage['candidatesTokenCount'] ?? null),
            'thoughts_tokens' => self::intOrNull($usage['thoughtsTokenCount'] ?? null),
            'total_tokens' => self::intOrNull($usage['totalTokenCount'] ?? null),
        ]);
    }

    /**
     * Construye una metrica local de fallo cuando no hay respuesta Gemini usable.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function failed(array $context = []): array
    {
        return self::withoutNulls([
            'duration_ms' => 0,
            'task_type' => self::stringOrNull($context['task_type'] ?? null),
            'document_type' => self::stringOrNull($context['document_type'] ?? null),
            'cache_hit' => false,
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>> $metrics
     * @return array<string,mixed>
     */
    public static function summarize(array $metrics): array
    {
        $filtered = array_values(array_filter($metrics, 'is_array'));
        $calls = count($filtered);

        if ($calls === 0) {
            return ['count' => 0];
        }

        $durations = [];
        $promptTokens = 0;
        $outputTokens = 0;
        $thoughtsTokens = 0;
        $totalTokens = 0;
        $cacheHits = 0;
        $finishReasons = [];

        foreach ($filtered as $metric) {
            $durations[] = (int) ($metric['duration_ms'] ?? 0);
            $promptTokens += (int) ($metric['prompt_tokens'] ?? 0);
            $outputTokens += (int) ($metric['output_tokens'] ?? 0);
            $thoughtsTokens += (int) ($metric['thoughts_tokens'] ?? 0);
            $totalTokens += (int) ($metric['total_tokens'] ?? 0);
            $finishReason = self::stringOrNull($metric['finish_reason'] ?? null);
            if ($finishReason !== null) {
                $finishReasons[$finishReason] = ($finishReasons[$finishReason] ?? 0) + 1;
            }
            if (($metric['cache_hit'] ?? false) === true) {
                $cacheHits++;
            }
        }

        sort($durations);
        ksort($finishReasons);
        $p95Index = max(0, (int) ceil($calls * 0.95) - 1);

        return self::withoutEmptyArrays([
            'count' => $calls,
            'cache_hits' => $cacheHits,
            'avg_ms' => (int) (array_sum($durations) / $calls),
            'min_ms' => $durations[0],
            'max_ms' => $durations[$calls - 1],
            'p95_ms' => $durations[$p95Index],
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'thoughts_tokens' => $thoughtsTokens,
            'total_tokens' => $totalTokens,
            'finish_reasons' => $finishReasons,
        ]);
    }

    /**
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function withoutEmptyArrays(array $values): array
    {
        return array_filter(
            $values,
            static fn(mixed $value): bool => !is_array($value) || $value !== []
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
