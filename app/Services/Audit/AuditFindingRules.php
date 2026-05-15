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
     * Soporta:
     * - Formatos numéricos: Y-m-d, Y/m/d, d/m/Y, d-m-Y, d.m.Y
     * - Fechas narrativas en español: "4 de mayo de 2026", "Mayo 4, 2026"
     * - Abreviaciones: "4 may 2026", "4-ene-2026"
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
            AuditFieldValueType::IDENTITY_DOC_TYPE => self::normalizeIdentityDocType($value),
            AuditFieldValueType::IDENTITY_DOC_NUMBER => self::normalizeIdentityDocNumber($value),
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

    /**
     * Tabla de aliases para tipos de documento de identidad colombianos.
     *
     * Cubre los 11 tipos oficiales del sistema RIPS/BDUA.
     * Keys: versión normalizada (sin acentos, sin espacios, uppercase).
     * Values: código canónico de 2-4 letras.
     */
    private const IDENTITY_DOC_ALIASES = [
        // Cédula de Ciudadanía
        'CC' => 'CC',
        'CEDULA' => 'CC',
        'CEDULACIUDADANIA' => 'CC',
        'CEDULADECIUDADANIA' => 'CC',
        // Tarjeta de Identidad
        'TI' => 'TI',
        'TARJETAIDENTIDAD' => 'TI',
        'TARJETADEIDENTIDAD' => 'TI',
        // Cédula de Extranjería
        'CE' => 'CE',
        'CEDULAEXTRANJERIA' => 'CE',
        'CEDULADEEXTRANJERIA' => 'CE',
        // Registro Civil
        'RC' => 'RC',
        'REGISTROCIVIL' => 'RC',
        'REGISTROCIVILNACIMIENTO' => 'RC',
        'REGISTROCIVILDENACIMIENTO' => 'RC',
        // Pasaporte
        'PA' => 'PA',
        'PASAPORTE' => 'PA',
        'PT' => 'PA',
        // Permiso Especial de Permanencia
        'PE' => 'PE',
        'PEP' => 'PE',
        'PERMISOESPECIALPERMANENCIA' => 'PE',
        'PERMISOESPECIALDEPERMANENCIA' => 'PE',
        // Permiso por Protección Temporal
        'PPT' => 'PPT',
        'PERMISOPROTECCIONTEMPORAL' => 'PPT',
        'PERMISODEPROTECCIONTEMPORAL' => 'PPT',
        'PERMISOPORPROTECCIONTEMPORAL' => 'PPT',
        // Menor sin identificación
        'MS' => 'MS',
        'MENORSINIDENTIFICACION' => 'MS',
        // Adulto sin identificación
        'AS' => 'AS',
        'ADULTOSINIDENTIFICACION' => 'AS',
        // Número Único de Identificación Personal
        'NUIP' => 'NUIP',
        'NUMEROUNICODEIDENTIFICACION' => 'NUIP',
        'NUMEROUNICODEIDENTIFICACIONPERSONAL' => 'NUIP',
        // Salvoconducto
        'SC' => 'SC',
        'SALVOCONDUCTO' => 'SC',
    ];

    /**
     * Normaliza un tipo de documento de identidad al código canónico RIPS/BDUA.
     *
     * Resuelve tanto abreviaciones ("CC", "TI") como texto completo
     * ("Cédula de Ciudadanía", "Tarjeta de Identidad").
     */
    public static function normalizeIdentityDocType(string $value): string
    {
        $token = self::normalizeToken($value);

        return self::IDENTITY_DOC_ALIASES[$token] ?? $token;
    }

    /**
     * Normaliza número/token de identificación sin conservar nombres concatenados.
     *
     * Es deliberadamente conservador: solo extrae tokens al inicio del valor
     * después de etiquetas/tipos documentales esperados. Si no hay patrón claro,
     * conserva el valor original para que la comparación exacta falle de forma
     * visible en vez de aprobar por inferencia.
     */
    public static function normalizeIdentityDocNumber(string $value): string
    {
        $candidate = strtoupper(self::stripAccents(trim($value)));
        if ($candidate === '') {
            return '';
        }

        $labelPattern = self::identityLabelPattern();
        $typePattern = self::identityDocTypePattern(includeNit: false);
        $candidate = (string) preg_replace(
            "/^\\s*(?:(?:{$labelPattern})\\s*[:#\\.\\-]+\\s*)?(?:(?:{$typePattern})\\s*[:#\\.\\-]?\\s*)?/u",
            '',
            $candidate,
            1
        );

        if (preg_match('/^\\s*(\\d{1,3}(?:[\\.\\s]\\d{3}){1,4})(?=\\D|$)/', $candidate, $matches) === 1) {
            return (string) preg_replace('/\\D+/', '', $matches[1]);
        }

        if (preg_match('/^\\s*([A-Z0-9]{4,20})(?=[\\s:;,\\-\\/]|$)/', $candidate, $matches) === 1) {
            $token = $matches[1];
            if (preg_match('/\\d/', $token) === 1) {
                return $token;
            }
        }

        $token = self::normalizeToken($candidate);
        if (preg_match('/^(?=.*\\d)[A-Z0-9]{4,20}$/', $token) === 1) {
            return $token;
        }

        return trim($value);
    }

    /**
     * Limpia nombres cuando Gemini concatena tipo/documento con la persona.
     *
     * Ej: "CC 94229637 NORENA AGUDELO" -> "NORENA AGUDELO".
     * Si al retirar el prefijo no queda texto alfabético, conserva el original.
     */
    public static function normalizePersonNameFromMixedIdentityLine(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        $labelPattern = self::identityLabelPattern();
        $typePattern = self::identityDocTypePattern(includeNit: true);
        $numberPattern = '(?:\\d{1,3}(?:[\\.\\s]\\d{3}){1,4}|(?=[A-Z0-9]{0,19}\\d)[A-Z0-9]{4,20})';
        $cleaned = (string) preg_replace(
            "/^\\s*(?:(?:{$labelPattern})\\s*[:#\\.\\-]+\\s*)?(?:(?:{$typePattern})\\s*[:#\\.\\-]?\\s*)?{$numberPattern}\\s*[-:\\/\\s]+\\s*/iu",
            '',
            $candidate,
            1,
            $count
        );

        $cleaned = trim($cleaned);
        if ($count === 1 && $cleaned !== '' && preg_match('/[[:alpha:]]/u', $cleaned) === 1) {
            return $cleaned;
        }

        return $candidate;
    }

    private static function identityDocTypePattern(bool $includeNit): string
    {
        $types = 'PPT|NUIP|PEP|CC|CE|TI|RC|PA|PE|MS|AS|SC';
        return $includeNit ? $types . '|NIT' : $types;
    }

    private static function identityLabelPattern(): string
    {
        return 'PACIENTE|M[EÉ]DICO|MEDICO|DOCUMENTO|DOC|IDENTIFICACI[OÓ]N|IDENTIDAD|NUMERO|N[Oº°]?';
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

    public static function samePersonNameTokenSet(string $left, string $right): bool
    {
        $leftTokens = self::tokenize(self::normalizePersonNameForTokenSet($left));
        $rightTokens = self::tokenize(self::normalizePersonNameForTokenSet($right));

        if ($leftTokens === [] || count($leftTokens) !== count($rightTokens)) {
            return false;
        }

        sort($leftTokens);
        sort($rightTokens);

        foreach ($leftTokens as $index => $leftToken) {
            if (!self::tokensMatchWithWildcard($leftToken, $rightTokens[$index])) {
                return false;
            }
        }

        return true;
    }

    private static function normalizePersonNameForTokenSet(string $value): string
    {
        $withoutAccents = self::stripAccents(strtoupper(trim($value)));
        $normalized = (string) preg_replace('/[^A-Z0-9?]+/', ' ', $withoutAccents);
        return (string) preg_replace('/\s+/', ' ', trim($normalized));
    }

    private static function tokensMatchWithWildcard(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (strlen($left) !== strlen($right)) {
            return false;
        }

        $length = strlen($left);
        for ($i = 0; $i < $length; $i++) {
            if ($left[$i] !== $right[$i] && $left[$i] !== '?' && $right[$i] !== '?') {
                return false;
            }
        }

        return true;
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

    /**
     * Elimina acentos y diacríticos de un string.
     *
     * Usa strtr determinístico (no depende de locale del servidor) como estrategia
     * primaria para los caracteres comunes en español/portugués. Solo recurre a
     * iconv como fallback para caracteres Unicode exóticos fuera de la tabla.
     */
    public static function stripAccents(string $value): string
    {
        // Estrategia primaria: strtr determinístico (independiente de locale)
        $stripped = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ç' => 'C', 'ç' => 'c',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'Ã' => 'A', 'Õ' => 'O',
            'ã' => 'a', 'õ' => 'o',
        ]);

        // Si strtr eliminó todo o no quedan non-ASCII, listo
        if (!preg_match('/[^\x00-\x7F]/', $stripped)) {
            return $stripped;
        }

        // Fallback: iconv para caracteres Unicode fuera de la tabla
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stripped);
        return $converted !== false ? $converted : $stripped;
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
