<?php

declare(strict_types=1);

namespace App\Services\Audit;

final class AuditFindingRules
{
    public const FIELD_DELIVERY_VALIDITY = 'VigenciaEntrega';

    /**
     * Indica si el resultado representa un fallo auditable.
     * Delega a AuditFindingResult para evitar listas hardcoded.
     */
    public static function isFailureResult(string $result): bool
    {
        $case = AuditFindingResult::tryFrom($result);
        return $case !== null && $case->isFailure();
    }

    /**
     * Indica si el resultado representa una discrepancia directa de datos.
     * Delega a AuditFindingResult para evitar listas hardcoded.
     */
    public static function isDiscrepancyResult(string $result): bool
    {
        $case = AuditFindingResult::tryFrom($result);
        return $case !== null && $case->isDiscrepancy();
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
                str_contains($normalized, 'confianza')
                || str_contains($normalized, 'no permite concluir')
                || str_contains($normalized, 'incertidumbre')
            );
    }

    /**
     * Normaliza un valor a string no-vacío, o null si está vacío.
     * Helper compartido para DocumentPolicyEngine y DocumentNormalizer.
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
     * Normaliza un token de texto: elimina acentos, pasa a mayúsculas y
     * suprime caracteres no alfanuméricos.
     *
     * Helper compartido para DocumentPolicyEngine y DocumentNormalizer.
     */
    public static function normalizeToken(string $value): string
    {
        $ascii = strtr(trim($value), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($ascii));
    }

    /**
     * Normaliza un string de fecha a formato ISO (Y-m-d).
     *
     * Helper compartido para DocumentPolicyEngine y DocumentNormalizer.
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

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $datePortion);
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $datePortion) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
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
     * Normaliza un valor para comparación según el tipo semántico del campo.
     */
    public static function normalizeForComparison(string $field, string $value): string
    {
        return match (AuditFieldValueType::fromFieldName($field)) {
            AuditFieldValueType::IDENTITY_DOC_TYPE => self::normalizeIdentityDocType($value),
            AuditFieldValueType::DATE => self::normalizeDateToIso($value) ?? self::normalizeText($value),
            AuditFieldValueType::QUANTITY,
            AuditFieldValueType::MONEY => self::normalizeNumberForComparison($value),
            default => self::normalizeText($value),
        };
    }

    public static function normalizeNumberForComparison(string $value): string
    {
        $number = self::parseNumber($value);
        return $number === null ? self::normalizeText($value) : self::formatNumber($number);
    }

    public static function normalizeIdentityDocType(string $value): string
    {
        $normalized = self::normalizeToken($value);

        return match ($normalized) {
            'CC', 'CEDULACIUDADANIA', 'CEDULADECIUDADANIA' => 'CC',
            default => $normalized,
        };
    }

    /**
     * Normaliza texto para comparación: uppercase, sin acentos, solo alfanumérico con espacios.
     *
     * Nota: diferente de normalizeToken() que elimina TODO lo no-alfanumérico (sin espacios).
     */
    public static function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $withoutAccents   = self::stripAccents(strtoupper($trimmed));
        $alphanumericOnly = (string) preg_replace('/[^A-Z0-9]+/', ' ', $withoutAccents);
        $normalized       = (string) preg_replace('/\s+/', ' ', trim($alphanumericOnly));

        return $normalized;
    }

    /**
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        return array_values(array_unique(
            array_filter(explode(' ', $text), static fn(string $t): bool => $t !== '')
        ));
    }

    public static function sameTokenSet(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $leftTokens  = self::tokenize($left);
        $rightTokens = self::tokenize($right);
        sort($leftTokens);
        sort($rightTokens);

        return $leftTokens === $rightTokens;
    }

    public static function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $similarPercent);
        $similarScore = $similarPercent / 100;

        $maxLength        = max(strlen($left), strlen($right));
        $levenshteinScore = $maxLength > 0
            ? max(0.0, 1 - (levenshtein($left, $right) / $maxLength))
            : 0.0;

        $leftTokens  = self::tokenize($left);
        $rightTokens = self::tokenize($right);
        $intersection = array_intersect($leftTokens, $rightTokens);
        $union        = array_unique(array_merge($leftTokens, $rightTokens));
        $jaccard      = $union === [] ? 0.0 : (count($intersection) / count($union));

        $composite = ($levenshteinScore * 0.6) + ($jaccard * 0.4);
        return max($similarScore, $composite);
    }

    public static function containsNormalizedSubstring(string $normalizedFdv, string $normalizedDoc): bool
    {
        if ($normalizedFdv === '' || $normalizedDoc === '') {
            return false;
        }

        return str_contains($normalizedDoc, $normalizedFdv)
            || str_contains($normalizedFdv, $normalizedDoc);
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

    public static function stripAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted === false) {
            return $value;
        }

        return $converted;
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

    /**
     * Evalúa hallazgos calculados de vigencia de entrega para una auditoría completa.
     *
     * @param  array<string,mixed>            $audit    Estado completo del audit en Redis.
     * @param  array<int,array<string,mixed>> $findings Hallazgos recopilados de todos los documentos.
     * @return array<int,array<string,mixed>>
     */
    public static function evaluateDeliveryValidity(array $audit, array $findings): array
    {
        $candidate = self::resolveDeliveryValidityCandidate($audit);
        if ($candidate === null) {
            return [];
        }

        $visual = $candidate['visual'];
        if (!is_array($visual) || ($visual['presente'] ?? false) !== true) {
            return [self::buildDeliveryValidityInconclusiveFinding($candidate, 'No se encontró una vigencia de entrega visible y estructurada.')];
        }

        $days = self::resolvePositiveInteger($visual['valor'] ?? null);
        $unit = (string) ($visual['unidad'] ?? '');
        $baseField = trim((string) ($visual['fecha_base'] ?? ''));
        if ($days === null || $unit !== 'dias' || $baseField === '') {
            return [self::buildDeliveryValidityInconclusiveFinding($candidate, 'La vigencia visible no contiene valor, unidad o fecha base suficiente para calcular.')];
        }

        $deliveryDate = self::resolveMatchedDate($findings, 'FechaEntrega');
        $baseDate = self::resolveMatchedDate($findings, $baseField);
        if ($deliveryDate === null || $baseDate === null) {
            return [self::buildDeliveryValidityInconclusiveFinding($candidate, 'FechaEntrega o fecha base no tienen resultado COINCIDE para validar la vigencia.')];
        }

        return [self::buildDeliveryValidityFinding($candidate, $days, $baseField, $baseDate, $deliveryDate)];
    }

    /**
     * @return array{document_name:string,expected:array<string,mixed>,visual:?array<string,mixed>}|null
     */
    private static function resolveDeliveryValidityCandidate(array $audit): ?array
    {
        $fallback = null;

        foreach (($audit['documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $documentName = (string) ($document['tipo_documento'] ?? '');
            $visualResults = self::indexVisualResults($document['normalized_result']['visual_checks_resultado'] ?? []);
            $sourceTruth = is_array($document['fuente_verdad'] ?? null) ? $document['fuente_verdad'] : [];
            $documentQuality = (string) ($document['normalized_result']['document_quality'] ?? '');

            foreach (($document['visual_checks'] ?? []) as $expected) {
                if (!is_array($expected)) {
                    continue;
                }

                $checkName = trim((string) ($expected['check'] ?? ''));
                if (!self::isCalculatedVisualCheck($checkName)) {
                    continue;
                }


                $candidate = [
                    'document_name' => $documentName,
                    'expected' => $expected,
                    'visual' => $visualResults[$checkName] ?? null,
                ];

                if (is_array($candidate['visual']) && ($candidate['visual']['presente'] ?? false) === true) {
                    return $candidate;
                }

                $fallback ??= $candidate;
            }
        }

        return $fallback;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function indexVisualResults(mixed $visualResults): array
    {
        if (!is_array($visualResults)) {
            return [];
        }

        $indexed = [];
        foreach ($visualResults as $visual) {
            if (!is_array($visual)) {
                continue;
            }

            $check = trim((string) ($visual['check'] ?? ''));
            if ($check !== '') {
                $indexed[$check] = $visual;
            }
        }

        return $indexed;
    }

    private static function resolvePositiveInteger(mixed $value): ?int
    {
        if (!is_int($value) || $value < 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private static function resolveMatchedDate(array $findings, string $field): ?\DateTimeImmutable
    {
        foreach ($findings as $finding) {
            if (($finding['campo'] ?? null) !== $field || ($finding['resultado'] ?? null) !== AuditFindingResult::MATCH->value) {
                continue;
            }

            foreach (['valorFuenteVerdad', 'valorDocumento'] as $key) {
                $date = self::parseIsoDate($finding[$key] ?? null);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private static function parseIsoDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $candidate = preg_split('/\s+/', trim($value), 2)[0] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $candidate) {
            return null;
        }

        return $date;
    }

    private static function buildDeliveryValidityFinding(
        array $candidate,
        int $days,
        string $baseField,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $deliveryDate
    ): array {
        $limitDate = $baseDate->modify("+{$days} days");
        $matches = $deliveryDate <= $limitDate;
        $baseDateText = $baseDate->format('Y-m-d');
        $deliveryDateText = $deliveryDate->format('Y-m-d');
        $limitDateText = $limitDate->format('Y-m-d');
        $severity = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        return [
            'campo' => self::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => "{$baseField} {$baseDateText} + {$days} dias = {$limitDateText}",
            'valorDocumento' => "{$deliveryDateText} dentro de {$days} dias",
            'resultado' => $matches ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
            'severidad' => $severity,
            'documento' => $candidate['document_name'],
            'detalle' => $matches
                ? "FechaEntrega {$deliveryDateText} dentro de la vigencia hasta {$limitDateText}."
                : "FechaEntrega {$deliveryDateText} supera la vigencia hasta {$limitDateText}.",
            'tipo_auditoria' => 'visual',
        ];
    }

    private static function buildDeliveryValidityInconclusiveFinding(array $candidate, string $detail): array
    {
        $severity = AuditSeverity::fromInput((string) ($candidate['expected']['severity'] ?? AuditSeverity::MEDIUM->value))->value;

        return [
            'campo' => self::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => 'Vigencia calculable requerida',
            'valorDocumento' => null,
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'severidad' => $severity,
            'documento' => $candidate['document_name'],
            'detalle' => $detail,
            'tipo_auditoria' => 'visual',
        ];
    }
}
