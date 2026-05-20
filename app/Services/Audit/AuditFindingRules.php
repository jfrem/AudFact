<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Reglas de auditoría: scoring, métricas y helpers de normalización.
 *
 * Post-refactor: esta clase retiene la lógica de scoring/métricas
 * y actúa como fachada de transición para métodos migrados a:
 * - TextNormalization (texto, similitud, tokenización)
 * - IdentityDocNormalizer (docs identidad RIPS/BDUA)
 * - DeliveryValidityEvaluator (vigencia de entrega)
 */
final class AuditFindingRules
{
    public const FIELD_DELIVERY_VALIDITY = 'VigenciaEntrega';



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

        $resultEnum = AuditFindingResult::tryFrom($result);
        $resultWeight = ($resultEnum !== null && $resultEnum->isDiscrepancy()) ? 10 : 0;

        return $severityWeight + $resultWeight;
    }


    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array<string,int>
     */
    public static function summarizeMetrics(array $findings): array
    {
        $metrics = [
            'total_campos'   => 0,
            'coincidencias'  => 0,
            'discrepancias'  => 0,
            'omitidos'       => 0,
            'no_concluyentes' => 0,
            'risk_score'     => 0,
        ];

        foreach ($findings as $finding) {
            $metrics['total_campos']++;
            $rawResult = (string) ($finding['resultado'] ?? '');
            $severity  = (string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value);
            $result    = AuditFindingResult::tryFrom($rawResult);

            if ($result === AuditFindingResult::MATCH) {
                $metrics['coincidencias']++;
                continue;
            }

            if ($result === AuditFindingResult::SKIPPED) {
                $metrics['omitidos']++;
                continue;
            }

            if ($result === AuditFindingResult::INCONCLUSIVE) {
                $metrics['no_concluyentes']++;
                $metrics['risk_score'] += self::riskWeight($severity);
                continue;
            }

            if ($result !== null && $result->isDiscrepancy()) {
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
                str_contains($normalized, 'no permite concluir')
                || str_contains($normalized, 'incertidumbre')
            );
    }

    /**
     * Normaliza un valor a string no-vacío, o null si está vacío.
     */
    public static function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    public static function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'null') {
                return false;
            }
        }

        return true;
    }

    /**
     * Normaliza un valor para comparación según el tipo de dato configurado.
     */
    public static function normalizeForComparison(AuditFieldValueType $valueType, string $value): string
    {
        return match ($valueType) {
            AuditFieldValueType::IDENTITY_DOC_TYPE => IdentityDocNormalizer::normalizeDocType($value),
            AuditFieldValueType::IDENTITY_DOC_NUMBER => IdentityDocNormalizer::normalizeDocNumber($value),
            AuditFieldValueType::DATE => self::normalizeDateToIso($value) ?? TextNormalization::normalizeText($value),
            AuditFieldValueType::QUANTITY,
            AuditFieldValueType::MONEY => self::normalizeNumberForComparison($value),
            default => TextNormalization::normalizeText($value),
        };
    }

    public static function normalizeNumberForComparison(string $value): string
    {
        $number = self::parseNumber($value);
        return $number === null ? TextNormalization::normalizeText($value) : self::formatNumber($number);
    }

    /**
     * Normaliza un string de fecha a formato ISO (Y-m-d).
     *
     * Soporta:
     * - Formatos numéricos: Y-m-d, Y/m/d, d/m/Y, d-m-Y, d.m.Y
     * - Fechas narrativas en español: "4 de mayo de 2026", "Mayo 4, 2026"
     * - Abreviaciones: "4 may 2026", "4-ene-2026"
     */
    public static function normalizeDateToIso(string $value): ?string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        $datePortion = preg_split('/\s+/', $candidate, 2)[0] ?? $candidate;
        if ($datePortion === '') {
            return null;
        }

        // 1. Formatos numéricos estrictos
        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $datePortion);
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $datePortion) {
                return $parsed->format('Y-m-d');
            }
        }

        // 2. Fechas narrativas en español (fallback)
        return self::parseSpanishNarrativeDate($candidate);
    }

    /**
     * Parsea fechas con nombre de mes en español.
     *
     * Soporta variantes:
     * - "4 de mayo de 2026"
     * - "Mayo 4, 2026"
     * - "4-mayo-2026", "4/mayo/2026"
     * - "4 may 2026" (abreviaciones)
     *
     * Retorna null si no puede extraer día + mes + año válidos.
     */
    private static function parseSpanishNarrativeDate(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        // Strip preposición "de": "4 de mayo de 2026" → "4 mayo 2026"
        $normalized = (string) preg_replace('/\bde\b/u', '', $normalized);
        $normalized = (string) preg_replace('/[,.\-\/]+/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));

        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
            // Abreviaciones comunes
            'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4,
            'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
        ];

        $parts = explode(' ', $normalized);
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));

        if (count($parts) < 3) {
            return null;
        }

        $day = $month = $year = null;

        foreach ($parts as $part) {
            if (isset($months[$part])) {
                $month = $months[$part];
            } elseif (is_numeric($part)) {
                $num = (int) $part;
                if ($num >= 1900 && $num <= 2100) {
                    $year = $num;
                } elseif ($num >= 1 && $num <= 31 && $day === null) {
                    $day = $num;
                }
            }
        }

        if ($day === null || $month === null || $year === null) {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function parseNumber(string $value): ?float
    {
        $normalized = str_replace(' ', '', trim($value));
        if ($normalized === '') {
            return null;
        }

        $hasDot   = str_contains($normalized, '.');
        $hasComma = str_contains($normalized, ',');

        if ($hasDot && $hasComma) {
            $lastDot   = strrpos($normalized, '.');
            $lastComma = strrpos($normalized, ',');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $normalized = str_replace(['.', ','], ['', '.'], $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    public static function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.0000001) {
            return (string) (int) round($value);
        }

        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    public static function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return self::formatNumber((float) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    /**
     * Tokeniza un string de códigos separados por coma, punto y coma o barra.
     *
     * @return string[]
     */
    public static function tokenizeCodeField(string $value): array
    {
        $raw    = preg_split('/[,;\/\s]+/', $value) ?: [];
        $tokens = [];
        foreach ($raw as $token) {
            $normalized = trim((string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($token)));
            if ($normalized !== '') {
                $tokens[] = $normalized;
            }
        }
        return array_values(array_unique($tokens));
    }

    public static function sumNumericValues(array $values): ?float
    {
        $sum        = 0.0;
        $hasNumeric = false;
        foreach ($values as $value) {
            $n = self::parseNumber((string) $value);
            if ($n === null) {
                continue;
            }
            $sum        += $n;
            $hasNumeric  = true;
        }
        return $hasNumeric ? $sum : null;
    }

    // ──────────────────────────────────────────────
    // Fachada de transición: delega a clases extraídas
    // Los callers directos se migrarán progresivamente.
    // ──────────────────────────────────────────────

    /** @deprecated Usar TextNormalization::normalizeText() directamente */
    public static function normalizeText(string $value): string
    {
        return TextNormalization::normalizeText($value);
    }

    /** @deprecated Usar TextNormalization::normalizeToken() directamente */
    public static function normalizeToken(string $value): string
    {
        return TextNormalization::normalizeToken($value);
    }

    /** @deprecated Usar TextNormalization::stripAccents() directamente */
    public static function stripAccents(string $value): string
    {
        return TextNormalization::stripAccents($value);
    }

    /** @deprecated Usar TextNormalization::tokenize() directamente */
    public static function tokenize(string $text): array
    {
        return TextNormalization::tokenize($text);
    }

    /** @deprecated Usar TextNormalization::sameTokenSet() directamente */
    public static function sameTokenSet(string $left, string $right): bool
    {
        return TextNormalization::sameTokenSet($left, $right);
    }

    /** @deprecated Usar TextNormalization::samePersonNameTokenSet() directamente */
    public static function samePersonNameTokenSet(string $left, string $right): bool
    {
        return TextNormalization::samePersonNameTokenSet($left, $right);
    }

    /** @deprecated Usar TextNormalization::containsNormalizedSubstring() directamente */
    public static function containsNormalizedSubstring(string $normalizedFdv, string $normalizedDoc): bool
    {
        return TextNormalization::containsNormalizedSubstring($normalizedFdv, $normalizedDoc);
    }

    /** @deprecated Usar TextNormalization::similarity() directamente */
    public static function similarity(string $left, string $right): float
    {
        return TextNormalization::similarity($left, $right);
    }

    /** @deprecated Usar IdentityDocNormalizer::normalizeDocType() directamente */
    public static function normalizeIdentityDocType(string $value): string
    {
        return IdentityDocNormalizer::normalizeDocType($value);
    }

    /** @deprecated Usar IdentityDocNormalizer::normalizeDocNumber() directamente */
    public static function normalizeIdentityDocNumber(string $value): string
    {
        return IdentityDocNormalizer::normalizeDocNumber($value);
    }

    /** @deprecated Usar IdentityDocNormalizer::normalizePersonNameFromMixedIdentityLine() directamente */
    public static function normalizePersonNameFromMixedIdentityLine(string $value): string
    {
        return IdentityDocNormalizer::normalizePersonNameFromMixedIdentityLine($value);
    }

    /** @deprecated Usar DeliveryValidityEvaluator::evaluate() directamente */
    public static function evaluateDeliveryValidity(array $audit, array $findings): array
    {
        return DeliveryValidityEvaluator::evaluate($audit, $findings);
    }
}
