<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditSeverity;

final class AuditFindingRules
{
    public const FIELD_DELIVERY_VALIDITY = 'VigenciaEntrega';

    private const RESULT_MATCH        = 'COINCIDE';
    private const RESULT_SKIPPED      = 'OMITIDO';
    private const RESULT_INCONCLUSIVE = 'NO_CONCLUYENTE';

    private const FAILURE_RESULTS     = ['VALOR_DISTINTO', 'NO_ENCONTRADO', 'NO_CONCLUYENTE'];
    private const DISCREPANCY_RESULTS = ['VALOR_DISTINTO', 'NO_ENCONTRADO'];

    public static function isFailureResult(string $result): bool
    {
        return in_array($result, self::FAILURE_RESULTS, true);
    }

    public static function isDiscrepancyResult(string $result): bool
    {
        return in_array($result, self::DISCREPANCY_RESULTS, true);
    }

    public static function isCalculatedVisualCheck(string $field): bool
    {
        return $field === self::FIELD_DELIVERY_VALIDITY;
    }

    public static function riskWeight(string $severity): int
    {
        return match ($severity) {
            AuditSeverity::HIGH->value => 10,
            AuditSeverity::LOW->value => 1,
            default => 5,
        };
    }

    public static function findingPriority(string $severity, string $result): int
    {
        $severityWeight = match ($severity) {
            AuditSeverity::HIGH->value => 30,
            AuditSeverity::LOW->value  => 0,
            default                    => 15,
        };

        $resultWeight = self::isDiscrepancyResult($result) ? 10 : 0;

        return $severityWeight + $resultWeight;
    }

    /**
     * Evalúa la regla `OmitirSi`. Acepta string JSON o array.
     *
     * Claves soportadas:
     *   - fdv_has:     [campos del header de FDV que, si están presentes, omiten la auditoría]
     *   - fdv_missing: [campos del header de FDV que, si faltan, omiten la auditoría]
     *   - doc_quality: [calidades documentales que omiten la auditoría]
     *
     * @param  mixed                   $rule            String JSON o array con claves de condición.
     * @param  array<string,mixed>     $sourceTruth     FDV completa (debe contener clave 'header').
     * @param  string                  $documentQuality Calidad documental (legible/parcialmente_legible/ilegible).
     */
    public static function shouldSkipByCondition(mixed $rule, array $sourceTruth, string $documentQuality): bool
    {
        if ($rule === null || $rule === '' || $rule === []) {
            return false;
        }

        if (is_string($rule)) {
            $decoded = json_decode($rule, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(stripslashes($rule), true);
            }
            if (!is_array($decoded)) {
                return false;
            }
            $rule = $decoded;
        }

        if (!is_array($rule)) {
            return false;
        }

        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];

        if (!empty($rule['fdv_has']) && is_array($rule['fdv_has'])) {
            foreach ($rule['fdv_has'] as $key) {
                if (is_string($key) && self::isPresent($header[$key] ?? null)) {
                    return true;
                }
            }
        }

        if (!empty($rule['fdv_missing']) && is_array($rule['fdv_missing'])) {
            foreach ($rule['fdv_missing'] as $key) {
                if (is_string($key) && !self::isPresent($header[$key] ?? null)) {
                    return true;
                }
            }
        }

        if (!empty($rule['doc_quality']) && is_array($rule['doc_quality'])) {
            foreach ($rule['doc_quality'] as $quality) {
                if (is_string($quality) && strtolower($quality) === $documentQuality) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array<string,int>
     */
    public static function summarizeMetrics(array $findings): array
    {
        $metrics = [
            'total_campos' => 0,
            'coincidencias' => 0,
            'discrepancias' => 0,
            'omitidos' => 0,
            'no_concluyentes' => 0,
            'risk_score' => 0,
        ];

        foreach ($findings as $finding) {
            $metrics['total_campos']++;
            $result = (string) ($finding['resultado'] ?? '');
            $severity = (string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value);

            if ($result === self::RESULT_MATCH) {
                $metrics['coincidencias']++;
                continue;
            }

            if ($result === self::RESULT_SKIPPED) {
                $metrics['omitidos']++;
                continue;
            }

            if ($result === self::RESULT_INCONCLUSIVE) {
                $metrics['no_concluyentes']++;
                $metrics['risk_score'] += self::riskWeight($severity);
                continue;
            }

            if (self::isDiscrepancyResult($result)) {
                $metrics['discrepancias']++;
                $metrics['risk_score'] += self::riskWeight($severity);
            }
        }

        return $metrics;
    }

    public static function observationRequiresManualReview(?string $observation): bool
    {
        $normalized = strtolower(trim((string) $observation));

        return $normalized !== ''
            && (
                str_contains($normalized, 'confianza')
                || str_contains($normalized, 'no permite concluir')
                || str_contains($normalized, 'incertidumbre')
            );
    }

    private static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== '';
    }
}
