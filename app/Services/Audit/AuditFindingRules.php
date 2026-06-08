<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Reglas de auditoría: scoring, métricas y helpers de normalización.
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

    /**
     * Enriquece el detalle de un hallazgo con el prefijo de código funcional.
     *
     * Formato: "-{codigoCampo}- {detalle}"
     * Idempotente: no duplica el prefijo si ya empieza con él.
     * Retorna el detalle sin cambios si codigoCampo es null/vacío.
     */
    public static function appendConfiguredFieldCodeToDetail(mixed $detail, mixed $codigoCampo): ?string
    {
        $code = self::normalizeNullableString(is_scalar($codigoCampo) ? (string) $codigoCampo : null);
        if ($code === null) {
            return self::normalizeNullableString($detail);
        }

        $prefix = "-{$code}-";
        $normalizedDetail = self::normalizeNullableString($detail);
        if ($normalizedDetail === null) {
            return $prefix;
        }

        if (str_starts_with($normalizedDetail, $prefix)) {
            return $normalizedDetail;
        }

        return "{$prefix} {$normalizedDetail}";
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

        $dateOnly = (string) preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[a-zA-Z.\s]+)?$/i', '', $candidate);
        $dateOnly = trim($dateOnly);
        if ($dateOnly === '') {
            return null;
        }

        // 2. Intentar parsear como fecha numérica separada por espacios/delimitadores variables
        $numericParsed = self::parseNumericDatePattern($dateOnly);
        if ($numericParsed !== null) {
            return $numericParsed;
        }

        // 3. Formatos numéricos estrictos (como fallback si no coincidió en el paso anterior)
        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $dateOnly);
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $dateOnly) {
                return $parsed->format('Y-m-d');
            }
        }

        // 4. Fechas narrativas en español (fallback)
        return self::parseSpanishNarrativeDate($dateOnly);
    }

    /**
     * Parsea fechas puramente numéricas de 3 componentes separadas por espacios, guiones, puntos o barras.
     * Determina inteligentemente día, mes y año según rangos lógicos y posición.
     */
    private static function parseNumericDatePattern(string $value): ?string
    {
        $clean = (string) preg_replace('/[^0-9\s]/', ' ', $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        if ($clean === '') {
            return null;
        }

        $parts = explode(' ', $clean);
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        if (count($parts) !== 3) {
            return null;
        }

        $nums = array_map('intval', $parts);
        $year = null;
        $yearIndex = null;

        // 1. Identificar el año
        // Intentar encontrar primero un año de 4 dígitos (entre 1900 y 2100)
        foreach ($nums as $idx => $num) {
            if ($num >= 1900 && $num <= 2100) {
                $year = $num;
                $yearIndex = $idx;
                break;
            }
        }

        // Si no se encuentra un año de 4 dígitos, asumir por convención que el tercer elemento es el año
        if ($year === null) {
            $lastIndex = 2;
            $num = $nums[$lastIndex];
            if ($num >= 0 && $num <= 99) {
                $year = $num + ($num < 50 ? 2000 : 1900);
                $yearIndex = $lastIndex;
            }
        }

        if ($year === null || $yearIndex === null) {
            return null;
        }

        // 2. Identificar día y mes con los dos elementos restantes
        $remainingIndices = array_values(array_diff([0, 1, 2], [$yearIndex]));
        $valA = $nums[$remainingIndices[0]];
        $valB = $nums[$remainingIndices[1]];

        $day = null;
        $month = null;

        if ($valA > 12 && $valB <= 12) {
            $day = $valA;
            $month = $valB;
        } elseif ($valB > 12 && $valA <= 12) {
            $day = $valB;
            $month = $valA;
        } elseif ($valA <= 12 && $valB <= 12) {
            // Ambigüedad: ambos <= 12. Desempatar usando la posición del año
            if ($yearIndex === 0) {
                // Año al inicio (Y m d) -> valA es mes, valB es día
                $month = $valA;
                $day = $valB;
            } else {
                // Año al final o al medio (d m Y) -> valA es día, valB es mes
                $day = $valA;
                $month = $valB;
            }
        } else {
            // Ambos > 12, lo cual no es posible para una combinación día/mes válida
            return null;
        }

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12,
            // Abreviaciones comunes
            'ene' => 1,
            'feb' => 2,
            'mar' => 3,
            'abr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'ago' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dic' => 12,
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
}
