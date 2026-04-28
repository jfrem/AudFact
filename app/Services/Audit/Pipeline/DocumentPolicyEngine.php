<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\SemanticMatchJudge;
use RuntimeException;

class DocumentPolicyEngine
{
    private const RESULT_MATCH        = 'COINCIDE';
    private const RESULT_MISMATCH     = 'VALOR_DISTINTO';
    private const RESULT_NOT_FOUND    = 'NO_ENCONTRADO';
    private const RESULT_SKIPPED      = 'OMITIDO';
    private const RESULT_INCONCLUSIVE = 'NO_CONCLUYENTE';

    
    private ?SemanticMatchJudge $semanticJudge;
    /** @var array<int,array<string,mixed>> */
    private array $semanticMetrics = [];
    private int $semanticCacheHits = 0;

    public function __construct(
        ?SemanticMatchJudge $semanticJudge = null
    ) {
        
        $this->semanticJudge = $semanticJudge;
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $normalizedPayload
     * @return array<string,mixed>
     */
    public function evaluate(array $documentState, array $normalizedPayload): array
    {
        $this->semanticMetrics = [];
        $this->semanticCacheHits = 0;
        $documentType = trim((string) ($documentState['tipo_documento'] ?? $normalizedPayload['tipo_documento'] ?? ''));
        if ($documentType === '') {
            throw new RuntimeException('document_normalized sin tipo_documento');
        }
        $canonicalDocumentType = $this->normalizeDocumentType($documentType);

        $context = [
            'audit_id'      => $documentState['audit_id'] ?? null,
            'document_id'   => $documentState['document_id'] ?? null,
            'dis_det_nro'   => $documentState['dis_det_nro'] ?? null,
            'document_type' => $documentType,
        ];

        $sourceTruth = $documentState['fuente_verdad'] ?? null;
        if (!is_array($sourceTruth)) {
            throw new RuntimeException('document_normalized sin fuente_verdad válida');
        }

        $fields        = $this->normalizeAssociative($normalizedPayload['fields_normalized'] ?? []);
        $items         = $this->normalizeRows($normalizedPayload['items_normalized'] ?? []);
        $visualChecks  = $this->normalizeVisualCheckResults($normalizedPayload['visual_checks_resultado'] ?? []);
        $documentQuality = strtolower(trim((string) ($normalizedPayload['document_quality'] ?? '')));
        if (!in_array($documentQuality, ['legible', 'parcialmente_legible', 'ilegible'], true)) {
            throw new RuntimeException('document_normalized sin document_quality válido');
        }

        $indexedFields = $this->indexFieldsByCanonicalName($documentState['fields_config'] ?? []);

        $findings = array_merge(
            $this->evaluateDataFields($indexedFields, $fields, $items, $sourceTruth, $canonicalDocumentType, $documentType, $documentQuality, $context),
            $this->evaluateVisualChecks($documentType, $documentState['visual_checks'] ?? [], $visualChecks, $documentQuality)
        );

        $metrics = AuditFindingRules::summarizeMetrics($findings);

        return [
            'document_name'     => $documentType,
            'hallazgos'         => ['items' => $findings, 'metrics' => $metrics],
            'document_decision' => $this->buildDocumentDecision($documentType, $findings),
            'gemini_semantic_metrics' => [
                'semantic' => $this->semanticMetrics,
                'semantic_calls' => count($this->semanticMetrics),
                'semantic_cache_hits' => $this->semanticCacheHits,
            ],
        ];
    }

    // ─── Normalizers ──────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function normalizeAssociative(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    private function normalizeVisualCheckResults(mixed $value): array
    {
        $indexed = [];
        if (!is_array($value)) {
            return $indexed;
        }

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $check = trim((string) ($row['check'] ?? ''));
            if ($check === '') {
                continue;
            }

            $canonical           = $check;
            $indexed[$canonical] = [
                'check'    => $canonical,
                'presente' => (bool) ($row['presente'] ?? false),
                'detalle'  => $this->normalizeNullableString($row['detalle'] ?? null),
                'severidad' => $this->normalizeVisualSeverity($row['severidad'] ?? null),
            ];
        }

        return $indexed;
    }

    // ─── Fields indexing & type mapping ───────────────────────────────────────

    private function indexFieldsByCanonicalName(array $fieldsConfig): array
    {
        $indexed = [];
        foreach ($fieldsConfig as $fieldConfig) {
            $indexed[$fieldConfig['campoNombre']] = $fieldConfig;
        }
        return $indexed;
    }

    private function mapToInternalType(string $tipoCampo): string
    {
        return AuditComparisonType::fromTipoCampo($tipoCampo)->value;
    }

    // ─── Data field evaluation ────────────────────────────────────────────────

    private function evaluateDataFields(
        array $indexedFields,
        array $fields,
        array $items,
        array $sourceTruth,
        string $canonicalDocumentType,
        string $documentType,
        string $documentQuality,
        array $context
    ): array {
        $findings = [];

        foreach ($indexedFields as $canonicalField => $fieldConfig) {
            if (strtoupper($fieldConfig['tipoCampo'] ?? '') === 'V') {
                continue;
            }

            // INFORMATIVO: el campo se incluye en el schema de extracción pero no se audita.
            $rol = strtoupper((string) ($fieldConfig['rol'] ?? 'AUTORITATIVO'));
            if ($rol === 'INFORMATIVO') {
                continue;
            }

            // Skip por regla condicional (OmitirSi).
            if ($this->shouldSkipByCondition($fieldConfig['omitirSi'] ?? null, $sourceTruth, $documentQuality)) {
                continue;
            }

            [$docValue, $ambiguous] = $this->resolveDocumentValue($canonicalField, $fields, $items);
            $fdvValue = $this->resolveSourceTruthValue($canonicalField, $sourceTruth);

            if ($fdvValue === null && $docValue === null) {
                continue;
            }

            $tipoCampo    = $fieldConfig['tipoCampo'] ?? 'E';
            $internalType = $this->mapToInternalType($tipoCampo);
            $comparison   = $this->evaluateField(
                $canonicalDocumentType,
                $canonicalField,
                $fdvValue,
                $docValue,
                $documentQuality,
                $ambiguous,
                $context,
                $internalType,
                $tipoCampo
            );

            $findings[] = $this->buildDataFinding($canonicalField, $fieldConfig, $comparison, $documentType, $fdvValue, $docValue, $internalType, $rol);
        }

        return $findings;
    }

    /**
     * Evalúa la regla `OmitirSi`. Acepta string JSON o array.
     *
     * Claves soportadas:
     *   - fdv_has:     [campos del header de FDV que, si están presentes, omiten la auditoría]
     *   - fdv_missing: [campos del header de FDV que, si faltan, omiten la auditoría]
     *   - doc_quality: [calidades documentales que omiten la auditoría]
     */
    private function shouldSkipByCondition(mixed $rule, array $sourceTruth, string $documentQuality): bool
    {
        if ($rule === null || $rule === '' || $rule === []) {
            return false;
        }

        if (is_string($rule)) {
            $decoded = json_decode($rule, true);
            if (!is_array($decoded)) {
                // Fallback: el valor puede estar double-encoded (backslashes literales desde DB)
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
                if (is_string($key) && $this->isPresent($header[$key] ?? null)) {
                    return true;
                }
            }
        }

        if (!empty($rule['fdv_missing']) && is_array($rule['fdv_missing'])) {
            foreach ($rule['fdv_missing'] as $key) {
                if (is_string($key) && !$this->isPresent($header[$key] ?? null)) {
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

    private function buildDataFinding(
        string $canonicalField,
        array $fieldConfig,
        array $comparison,
        string $documentType,
        ?string $fdvValue,
        ?string $docValue,
        string $internalType,
        string $rol = 'AUTORITATIVO'
    ): array {
        return [
            'campo'              => $canonicalField,
            'valorFuenteVerdad'  => $fdvValue,
            'valorDocumento'     => $docValue,
            'resultado'          => $comparison['resultado'],
            'severidad'          => AuditSeverity::fromInput($fieldConfig['severity'] ?? 'media')->value,
            'documento'          => $documentType,
            'detalle'            => $comparison['detalle'] ?? null,
            'tipo_auditoria'     => $internalType,
            'rol'                => $rol,
        ];
    }

    // ─── Value resolution ─────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:?string,1:bool}
     */
    private function resolveDocumentValue(string $field, array $fields, array $items): array
    {
        // Intentar resolver desde items[] primero (el dato dice dónde está)
        $itemValues = [];
        foreach ($items as $row) {
            if (!array_key_exists($field, $row) || !$this->isPresent($row[$field])) {
                continue;
            }
            $itemValues[] = $this->scalarToString($row[$field]);
        }

        if ($itemValues !== []) {
            if (AuditComparisonType::isQuantityField($field)) {
                $total = $this->sumNumericValues($itemValues);
                if ($total !== null) {
                    return [$this->formatNumber($total), false];
                }
            }

            $unique = array_values(array_unique($itemValues));
            if (count($unique) === 1) {
                return [$unique[0], false];
            }

            // Multi-valor no-sumable: skipear silenciosamente
            return AuditComparisonType::isQuantityField($field) ? [null, true] : [null, false];
        }

        // Fallback: resolver desde fields{}
        if (array_key_exists($field, $fields) && $this->isPresent($fields[$field])) {
            return [$this->scalarToString($fields[$field]), false];
        }

        return [null, false];
    }

    /**
     * @param  array<string,mixed> $sourceTruth
     */
    private function resolveSourceTruthValue(string $field, array $sourceTruth): ?string
    {
        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];
        $items  = is_array($sourceTruth['items'] ?? null)  ? $sourceTruth['items']  : [];

        $headerValue = $this->extractRowValue($header, $field, $field);
        if ($headerValue !== null) {
            return $headerValue;
        }

        if ($items === []) {
            return null;
        }

        $itemValues = $this->extractItemValues($items, $field, $field);

        if (AuditComparisonType::isQuantityField($field)) {
            $total = $this->sumNumericValues($itemValues);
            return $total !== null ? $this->formatNumber($total) : null;
        }

        if ($itemValues === []) {
            return null;
        }

        $unique = array_values(array_unique($itemValues));
        if (count($unique) === 1) {
            return $unique[0];
        }

        // Multi-valor no-sumable: skipear silenciosamente
        return AuditComparisonType::isQuantityField($field) ? $unique[0] : null;
    }

    private function extractItemValues(array $items, string $field, ?string $column): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = $this->extractRowValue($item, $field, $column);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    private function sumNumericValues(array $values): ?float
    {
        $sum        = 0.0;
        $hasNumeric = false;
        foreach ($values as $value) {
            $n = $this->parseNumber((string) $value);
            if ($n === null) {
                continue;
            }
            $sum        += $n;
            $hasNumeric  = true;
        }
        return $hasNumeric ? $sum : null;
    }

    /**
     * @param  array<string,mixed> $row
     */
    private function extractRowValue(array $row, string $field, ?string $column): ?string
    {
        $candidate = $row[$field] ?? ($column !== null ? ($row[$column] ?? null) : null);
        if (!$this->isPresent($candidate)) {
            return null;
        }

        return $this->scalarToString($candidate);
    }

    // ─── Field comparison ─────────────────────────────────────────────────────

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateField(
        string $documentType,
        string $field,
        ?string $fdvValue,
        ?string $docValue,
        string $documentQuality,
        bool $ambiguous,
        array $context = [],
        ?string $forcedType = null,
        string $tipoCampo = 'E'
    ): array {
        if ($ambiguous || $documentQuality !== 'legible') {
            if ($docValue === null || $ambiguous) {
                return [
                    'resultado' => self::RESULT_INCONCLUSIVE,
                    'detalle'   => $ambiguous
                        ? 'El documento contiene multiples candidatos plausibles para el campo.'
                        : 'La calidad documental no permite concluir el valor con confianza suficiente.',
                ];
            }
        }

        if ($fdvValue === null && $docValue === null) {
            return ['resultado' => self::RESULT_SKIPPED];
        }

        if ($docValue === null) {
            return [
                'resultado' => self::RESULT_NOT_FOUND, 
                'detalle'   => "El campo '{$field}' no se encontró en el documento. Valor esperado según registro de dispensación: '{$fdvValue}'."
            ];
        }

        if ($fdvValue === null) {
            return ['resultado' => self::RESULT_SKIPPED, 'detalle' => 'Sin valor auditable en registro de dispensación.'];
        }

        if ($forcedType === null) {
            throw new RuntimeException("Campo '{$field}' sin tipo de comparación — verificar audit-config en BD");
        }
        $type = $forcedType;
        return match ($type) {
            AuditComparisonType::SEMANTIC->value => $this->evaluateSemanticField($field, $fdvValue, $docValue, $context, $tipoCampo),
            AuditComparisonType::BUSINESS->value => $this->evaluateBusinessField($field, $fdvValue, $docValue),
            default                        => $this->evaluateExactField($field, $fdvValue, $docValue),
        };
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateExactField(string $field, string $fdvValue, string $docValue): array
    {
        if ($this->normalizeForComparison($field, $fdvValue) === $this->normalizeForComparison($field, $docValue)) {
            return ['resultado' => self::RESULT_MATCH];
        }

        return [
            'resultado' => self::RESULT_MISMATCH,
            'detalle'   => "FDV '{$fdvValue}' difiere de Documento '{$docValue}'.",
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateSemanticField(string $field, string $fdvValue, string $docValue, array $context, string $tipoCampo = 'S'): array
    {
        $normalizedFdv = $this->normalizeText($fdvValue);
        $normalizedDoc = $this->normalizeText($docValue);

        if ($this->sameTokenSet($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => self::RESULT_MATCH];
        }

        if (AuditComparisonType::isSubstringMatchAllowed($tipoCampo) && $this->containsNormalizedSubstring($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => self::RESULT_MATCH];
        }

        $score     = $this->similarity($normalizedFdv, $normalizedDoc);
        $threshold = AuditComparisonType::getSemanticThreshold($tipoCampo);

        if ($score >= $threshold) {
            return ['resultado' => self::RESULT_MATCH];
        }

        if ($this->semanticJudge !== null) {
            $judgeResult = $this->semanticJudge->evaluate($fdvValue, $docValue, $context);
            if (is_array($judgeResult['gemini_metrics'] ?? null)) {
                $this->semanticMetrics[] = $judgeResult['gemini_metrics'];
            }
            if (($judgeResult['cache_hit'] ?? false) === true) {
                $this->semanticCacheHits++;
            }
            if ($judgeResult['is_match']) {
                return ['resultado' => self::RESULT_MATCH,        'detalle' => $judgeResult['reasoning']];
            }
            return [    'resultado' => self::RESULT_INCONCLUSIVE, 'detalle' => $judgeResult['reasoning']];
        }

        return [
            'resultado' => self::RESULT_INCONCLUSIVE,
            'detalle'   => sprintf(
                'Similitud %.2f por debajo del umbral %.2f para comparación semántica.',
                $score,
                $threshold
            ),
        ];
    }

    private function containsNormalizedSubstring(string $normalizedFdv, string $normalizedDoc): bool
    {
        if ($normalizedFdv === '' || $normalizedDoc === '') {
            return false;
        }

        return str_contains($normalizedDoc, $normalizedFdv)
            || str_contains($normalizedFdv, $normalizedDoc);
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateBusinessField(string $field, string $fdvValue, string $docValue): array
    {
        if (!AuditComparisonType::isQuantityField($field)) {
            return $this->evaluateExactField($field, $fdvValue, $docValue);
        }

        $fdvNumber = $this->parseNumber($fdvValue);
        $docNumber = $this->parseNumber($docValue);

        if ($fdvNumber === null || $docNumber === null) {
            return [
                'resultado' => self::RESULT_INCONCLUSIVE,
                'detalle'   => 'Valores no numéricos en campo de negocio.',
            ];
        }

        if ($docNumber <= $fdvNumber) {
            return ['resultado' => self::RESULT_MATCH];
        }

        return [
            'resultado' => self::RESULT_MISMATCH,
            'detalle'   => sprintf('Cantidad en documento (%.2f) excede FDV (%.2f).', $docNumber, $fdvNumber),
        ];
    }

    // ─── Visual checks ────────────────────────────────────────────────────────

    private function evaluateVisualChecks(
        string $documentType,
        mixed $visualChecksExpected,
        mixed $visualChecksResults,
        string $documentQuality
    ): array {
        if (!is_array($visualChecksExpected)) {
            return [];
        }

        $results  = is_array($visualChecksResults) ? $visualChecksResults : [];
        $findings = [];

        foreach ($visualChecksExpected as $checkExpected) {
            if (!is_array($checkExpected)) {
                continue;
            }

            $checkName = trim((string) ($checkExpected['check'] ?? ''));
            if ($checkName === '') {
                continue;
            }

            $canonicalField = $checkName;
            $displayField   = $canonicalField;
            $severity       = $this->normalizeVisualSeverity($checkExpected['severity'] ?? null);

            if ($documentQuality !== 'legible') {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $displayField, $severity,
                    'NO_EVALUADO', self::RESULT_INCONCLUSIVE,
                    'La calidad documental no permite concluir la validación visual.'
                );
                continue;
            }

            $foundResult = $results[$canonicalField] ?? null;
            if (!is_array($foundResult)) {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $displayField, $severity,
                    'NO_EVALUADO', self::RESULT_INCONCLUSIVE,
                    'Check visual esperado no fue evaluado por el modelo.'
                );
                continue;
            }

            $isPresent  = (bool) ($foundResult['presente'] ?? false);
            $findings[] = $this->buildVisualFinding(
                $documentType, $displayField, $severity,
                $isPresent ? 'PRESENTE' : 'AUSENTE',
                $isPresent ? self::RESULT_MATCH : self::RESULT_MISMATCH,
                $this->normalizeNullableString($foundResult['detalle'] ?? null)
            );
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVisualFinding(
        string $documentType,
        string $displayField,
        string $severity,
        string $valorDocumento,
        string $resultado,
        ?string $detalle
    ): array {
        return [
            'valorFuenteVerdad' => 'OBLIGATORIO',
            'valorDocumento'    => $valorDocumento,
            'resultado'         => $resultado,
            'detalle'           => $detalle,
            'campo'             => $displayField,
            'severidad'         => $severity,
            'documento'         => $documentType,
        ];
    }

    // ─── Decisions & metrics ──────────────────────────────────────────────────

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{documentName:string,approved:bool,observation:?string}
     */
    private function buildDocumentDecision(string $documentType, array $findings): array
    {
        $approved     = true;
        $observations = [];

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (in_array($result, [self::RESULT_MISMATCH, self::RESULT_NOT_FOUND, self::RESULT_INCONCLUSIVE], true)) {
                $approved = false;
                $detail   = $this->normalizeNullableString($finding['detalle'] ?? null);
                if ($detail !== null) {
                    $observations[] = $detail;
                }
            }
        }

        $observations = array_values(array_unique($observations));
        $observation  = $observations === [] ? null : implode(' | ', array_slice($observations, 0, 3));

        return [
            'documentName' => str_replace('_', ' ', $this->normalizeDocumentType($documentType)),
            'approved'     => $approved,
            'observation'  => $observation,
        ];
    }

    // ─── Normalization helpers ─────────────────────────────────────────────────

    private function normalizeDocumentType(string $documentType): string
    {
        $upper      = strtoupper(trim($documentType));
        $ascii      = $this->stripAccents($upper);
        $normalized = str_replace([' ', '-'], '_', $ascii);
        $normalized = (string) preg_replace('/_+/', '_', $normalized);

        return $normalized;
    }

    private function normalizeForComparison(string $field, string $value): string
    {
        if (AuditComparisonType::isDateField($field)) {
            return $this->normalizeDateForComparison($value) ?? $this->normalizeText($value);
        }

        if (AuditComparisonType::isNumberField($field)) {
            $number = $this->parseNumber($value);
            return $number === null ? $this->normalizeText($value) : $this->formatNumber($number);
        }

        return $this->normalizeText($value);
    }

    private function normalizeDateForComparison(string $value): ?string
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

    private function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $withoutAccents   = $this->stripAccents(strtoupper($trimmed));
        $alphanumericOnly = (string) preg_replace('/[^A-Z0-9]+/', ' ', $withoutAccents);
        $normalized       = (string) preg_replace('/\s+/', ' ', trim($alphanumericOnly));

        return $normalized;
    }

    private function tokenize(string $text): array
    {
        return array_values(array_unique(
            array_filter(explode(' ', $text), static fn(string $t): bool => $t !== '')
        ));
    }

    private function sameTokenSet(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $leftTokens  = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);
        sort($leftTokens);
        sort($rightTokens);

        return $leftTokens === $rightTokens;
    }

    private function similarity(string $left, string $right): float
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

        $leftTokens  = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);
        $intersection = array_intersect($leftTokens, $rightTokens);
        $union        = array_unique(array_merge($leftTokens, $rightTokens));
        $jaccard      = $union === [] ? 0.0 : (count($intersection) / count($union));

        $composite = ($levenshteinScore * 0.6) + ($jaccard * 0.4);
        return max($similarScore, $composite);
    }

    private function isPresent(mixed $value): bool
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

    private function scalarToString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return $this->formatNumber((float) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function parseNumber(string $value): ?float
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

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.0000001) {
            return (string) (int) round($value);
        }

        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeVisualSeverity(mixed $severity): string
    {
        $normalized = strtoupper(trim((string) $severity));
        return match ($normalized) {
            'CRITICO', 'CRÍTICO', 'ALTA', 'HIGH' => AuditSeverity::HIGH->value,
            'BAJA', 'LOW'                         => AuditSeverity::LOW->value,
            default                               => AuditSeverity::MEDIUM->value,
        };
    }

    private function stripAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted === false) {
            return $value;
        }

        return $converted;
    }
}
