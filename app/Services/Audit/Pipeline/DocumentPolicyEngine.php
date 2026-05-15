<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\DocumentQuality;
use App\Services\Audit\ArticleSemanticMatchJudge;
use RuntimeException;

class DocumentPolicyEngine
{
    private ?ArticleSemanticMatchJudge $semanticJudge;
    /** @var array<int,array<string,mixed>> */
    private array $semanticMetrics = [];
    private int $semanticCacheHits = 0;

    public function __construct(
        ?ArticleSemanticMatchJudge $semanticJudge = null
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
        $this->resetSemanticMetrics();

        $documentType = $this->resolveDocumentType($documentState, $normalizedPayload);
        $context = $this->buildEvaluationContext($documentState, $documentType);
        $sourceTruth = $this->resolveSourceTruth($documentState);

        $fields        = $this->normalizeAssociative($normalizedPayload['fields_normalized'] ?? []);
        $items         = $this->normalizeRows($normalizedPayload['items_normalized'] ?? []);
        $visualChecks  = $this->normalizeVisualCheckResults($normalizedPayload['visual_checks_resultado'] ?? []);
        $documentQuality = $this->resolveDocumentQuality($normalizedPayload);

        $indexedFields = $this->indexFieldsByCanonicalName($documentState['fields_config'] ?? []);

        $findings = array_merge(
            $this->evaluateDataFields($indexedFields, $fields, $items, $sourceTruth, $documentType, $documentQuality, $context),
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

    private function resetSemanticMetrics(): void
    {
        $this->semanticMetrics = [];
        $this->semanticCacheHits = 0;
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $normalizedPayload
     */
    private function resolveDocumentType(array $documentState, array $normalizedPayload): string
    {
        $documentType = trim((string) ($documentState['tipo_documento'] ?? $normalizedPayload['tipo_documento'] ?? ''));
        if ($documentType === '') {
            throw new RuntimeException('document_normalized sin tipo_documento');
        }

        return $documentType;
    }

    /**
     * @param  array<string,mixed> $documentState
     * @return array<string,mixed>
     */
    private function buildEvaluationContext(array $documentState, string $documentType): array
    {
        return [
            'audit_id'      => $documentState['audit_id'] ?? null,
            'document_id'   => $documentState['document_id'] ?? null,
            'dis_det_nro'   => $documentState['dis_det_nro'] ?? null,
            'document_type' => $documentType,
        ];
    }

    /**
     * @param  array<string,mixed> $documentState
     * @return array<string,mixed>
     */
    private function resolveSourceTruth(array $documentState): array
    {
        $sourceTruth = $documentState['fuente_verdad'] ?? null;
        if (!is_array($sourceTruth)) {
            throw new RuntimeException('document_normalized sin fuente_verdad válida');
        }

        return $sourceTruth;
    }

    /**
     * @param  array<string,mixed> $normalizedPayload
     */
    private function resolveDocumentQuality(array $normalizedPayload): string
    {
        return DocumentQuality::fromString((string) ($normalizedPayload['document_quality'] ?? ''))->value;
    }

    /**
     * @return array<string,ExtractedEvidence>
     */
    private function normalizeAssociative(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $hydrated = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $hydrated[$k] = ExtractedEvidence::fromArray($v);
            } elseif ($v instanceof ExtractedEvidence) {
                $hydrated[$k] = $v;
            }
        }
        return $hydrated;
    }

    /**
     * @return array<int,array<string,ExtractedEvidence>>
     */
    private function normalizeRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hydratedRow = [];
            foreach ($row as $k => $v) {
                if (is_array($v)) {
                    $hydratedRow[$k] = ExtractedEvidence::fromArray($v);
                } elseif ($v instanceof ExtractedEvidence) {
                    $hydratedRow[$k] = $v;
                }
            }
            $rows[] = $hydratedRow;
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

            $indexed[$check] = [
                'check'    => $check,
                'presente' => (bool) ($row['presente'] ?? false),
                'detalle'  => AuditFindingRules::normalizeNullableString($row['detalle'] ?? null),
                'severidad' => AuditSeverity::fromInput((string) ($row['severidad'] ?? ''))->value,
                'valor' => $row['valor'] ?? null,
                'unidad' => $row['unidad'] ?? null,
                'fecha_base' => $row['fecha_base'] ?? null,
            ];
        }

        return $indexed;
    }

    private function indexFieldsByCanonicalName(array $fieldsConfig): array
    {
        $indexed = [];
        foreach ($fieldsConfig as $fieldConfig) {
            $indexed[$fieldConfig['campoNombre']] = $fieldConfig;
        }
        return $indexed;
    }

    private function evaluateDataFields(
        array $indexedFields,
        array $fields,
        array $items,
        array $sourceTruth,
        string $documentType,
        string $documentQuality,
        array $context
    ): array {
        $findings = [];

        foreach ($indexedFields as $canonicalField => $fieldConfig) {
            if (strtoupper($fieldConfig['tipoCampo'] ?? '') === 'V') {
                continue;
            }

            $valueType = $this->fieldValueTypeFromConfig($fieldConfig);
            $docResolution = $this->resolveDocumentValue($canonicalField, $valueType, $fields, $items);
            $fdvValue      = $this->resolveSourceTruthValue($canonicalField, $valueType, $sourceTruth);

            $docValue    = $docResolution['displayValue'];
            $ambiguous   = $docResolution['ambiguous'];
            $evidenceMeta = $docResolution['evidenceMeta'];

            if ($fdvValue === null && $docValue === null && !$ambiguous) {
                continue;
            }

            $tipoCampo    = $fieldConfig['tipoCampo'] ?? 'E';
            $internalType = AuditComparisonType::fromTipoCampo($tipoCampo)->value;

            $comparison = $this->evaluateDataFieldComparison(
                $canonicalField,
                $fdvValue,
                $docValue,
                $docResolution,
                $valueType,
                $documentQuality,
                $ambiguous,
                $context,
                $internalType,
                $tipoCampo
            );

            $findings[] = $this->buildDataFinding(
                $canonicalField, $fieldConfig, $comparison, $documentType,
                $fdvValue, $docValue, $internalType, $valueType, $evidenceMeta,
                $docResolution['values']
            );
        }

        return $findings;
    }

    /**
     * @param  array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>} $docResolution
     * @return array{resultado:string,tipo_auditoria?:string,detalle?:string}
     */
    private function evaluateDataFieldComparison(
        string $canonicalField,
        ?string $fdvValue,
        ?string $docValue,
        array $docResolution,
        AuditFieldValueType $valueType,
        string $documentQuality,
        bool $ambiguous,
        array $context,
        string $internalType,
        string $tipoCampo
    ): array {
        if ($this->canEvaluateTraceSet($valueType, $fdvValue, $docValue, $ambiguous)) {
            return $this->evaluateTraceSetField(
                $canonicalField,
                $this->splitTraceDisplayValue($fdvValue),
                $docResolution['values']
            );
        }

        return $this->evaluateField(
            $canonicalField,
            $fdvValue,
            $docValue,
            $documentQuality,
            $ambiguous,
            $context,
            $internalType,
            $tipoCampo,
            $valueType
        );
    }

    private function canEvaluateTraceSet(
        AuditFieldValueType $valueType,
        ?string $fdvValue,
        ?string $docValue,
        bool $ambiguous
    ): bool {
        return $valueType->requiresTraceSetComparison()
            && $fdvValue !== null
            && $docValue !== null
            && !$ambiguous;
    }

    /**
     * @return array<int,string>
     */
    private function splitTraceDisplayValue(string $displayValue): array
    {
        return array_map('trim', explode(', ', $displayValue));
    }

    private function buildDataFinding(
        string $canonicalField,
        array $fieldConfig,
        array $comparison,
        string $documentType,
        ?string $fdvValue,
        ?string $docValue,
        string $internalType,
        AuditFieldValueType $valueType,
        array $evidenceMeta = [],
        array $docValues = []
    ): array {
        $valoresDocumento = $this->resolveFindingDocumentValues($valueType, $docValue, $docValues);

        $finding = [
            'campo'              => $canonicalField,
            'valorFuenteVerdad'  => $fdvValue,
            'valorDocumento'     => $docValue,
            'resultado'          => $comparison['resultado'],
            'severidad'          => AuditSeverity::fromInput($fieldConfig['severity'] ?? 'media')->value,
            'documento'          => $documentType,
            'detalle'            => $comparison['detalle'] ?? null,
            'tipo_auditoria'     => $comparison['tipo_auditoria'] ?? $internalType,
            'valueType'          => $valueType->value,
        ];

        if ($valoresDocumento !== null) {
            $finding['valoresDocumento'] = $valoresDocumento;
        }

        if ($evidenceMeta !== []) {
            $finding['extraction_meta'] = array_filter($evidenceMeta, fn($v) => $v !== null);
        }

        return $finding;
    }

    /**
     * @param  array<int,string> $docValues
     * @return array<int,string>|null
     */
    private function resolveFindingDocumentValues(
        AuditFieldValueType $valueType,
        ?string $docValue,
        array $docValues
    ): ?array {
        if ($valueType === AuditFieldValueType::CODE && $docValue !== null) {
            return AuditFindingRules::tokenizeCodeField($docValue);
        }

        if ($valueType === AuditFieldValueType::TRACE_TOKEN && $docValues !== []) {
            return $docValues;
        }

        return null;
    }

    /**
     * Boundary de conversión DTO→escalar.
     *
     * Este es el ÚNICO punto donde ExtractedEvidence se convierte a escalares.
     * Todo lo que está downstream (evaluateField, evaluateExactField, etc.) recibe solo strings.
     * La metadata de evidencia (estadoExtraccion, valores) se extrae aquí y se propaga
     * al hallazgo canónico por separado vía `extraction_meta`.
     *
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    private function resolveDocumentValue(
        string $field,
        AuditFieldValueType $valueType,
        array $fields,
        array $items
    ): array
    {
        [$itemValues, $evidenceMeta] = $this->extractPresentItemValues($field, $items);

        if ($itemValues !== []) {
            return $this->resolveItemDocumentValue($valueType, $itemValues, $evidenceMeta);
        }

        return $this->resolveHeaderDocumentValue($field, $fields, $evidenceMeta);
    }

    /**
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function extractPresentItemValues(string $field, array $items): array
    {
        $itemValues = [];
        $evidenceMeta = [];

        foreach ($items as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $cell = $row[$field];
            if (!$cell instanceof ExtractedEvidence || !AuditFindingRules::isPresent($cell->valor)) {
                continue;
            }

            $itemValues[] = AuditFindingRules::scalarToString($cell->valor);
            if ($evidenceMeta === []) {
                $evidenceMeta = $cell->extractMeta();
            }
        }

        return [$itemValues, $evidenceMeta];
    }

    /**
     * @param  array<int,string> $itemValues
     * @param  array<string,mixed> $evidenceMeta
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    private function resolveItemDocumentValue(
        AuditFieldValueType $valueType,
        array $itemValues,
        array $evidenceMeta
    ): array {
        if ($valueType->isQuantitySummable()) {
            $total = AuditFindingRules::sumNumericValues($itemValues);
            if ($total !== null) {
                $formatted = AuditFindingRules::formatNumber($total);
                return ['displayValue' => $formatted, 'values' => [$formatted], 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
            }
        }

        $unique = array_values(array_unique($itemValues));
        sort($unique);

        if (count($unique) === 1) {
            return ['displayValue' => $unique[0], 'values' => $unique, 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
        }

        return [
            'displayValue'  => implode(', ', $unique),
            'values'        => $unique,
            'ambiguous'     => !$valueType->requiresTraceSetComparison(),
            'evidenceMeta'  => $evidenceMeta,
        ];
    }

    /**
     * @param  array<string,mixed> $fields
     * @param  array<string,mixed> $evidenceMeta
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    private function resolveHeaderDocumentValue(string $field, array $fields, array $evidenceMeta): array
    {
        if (array_key_exists($field, $fields)) {
            $cell = $fields[$field];
            if ($cell instanceof ExtractedEvidence && AuditFindingRules::isPresent($cell->valor)) {
                $displayValue = AuditFindingRules::scalarToString($cell->valor);
                return [
                    'displayValue' => $displayValue,
                    'values' => [$displayValue],
                    'ambiguous' => false,
                    'evidenceMeta' => $cell->extractMeta(),
                ];
            }
        }

        return ['displayValue' => null, 'values' => [], 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
    }


    /**
     * @param  array<string,mixed> $sourceTruth
     */
    private function resolveSourceTruthValue(
        string $field,
        AuditFieldValueType $valueType,
        array $sourceTruth
    ): ?string
    {
        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];
        $items  = is_array($sourceTruth['items'] ?? null)  ? $sourceTruth['items']  : [];

        $headerValue = $this->extractRowValue($header, $field);
        if ($headerValue !== null) {
            return $headerValue;
        }

        if ($items === []) {
            return null;
        }

        $itemValues = $this->extractItemValues($items, $field);

        if ($valueType->isQuantitySummable()) {
            $total = AuditFindingRules::sumNumericValues($itemValues);
            return $total !== null ? AuditFindingRules::formatNumber($total) : null;
        }

        if ($itemValues === []) {
            return null;
        }

        $unique = array_values(array_unique($itemValues));
        sort($unique);
        if (count($unique) === 1) {
            return $unique[0];
        }

        // TRACE_TOKEN: concatenar para display, los valores individuales se usan en evaluateTraceSetField
        if ($valueType->requiresTraceSetComparison()) {
            return implode(', ', $unique);
        }

        // Otros tipos multi-item con valores distintos: no hay valor único representativo
        return null;
    }

    private function extractItemValues(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = $this->extractRowValue($item, $field);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * @param  array<string,mixed> $row
     */
    private function extractRowValue(array $row, string $field): ?string
    {
        $candidate = $row[$field] ?? null;
        if (!AuditFindingRules::isPresent($candidate)) {
            return null;
        }

        return AuditFindingRules::scalarToString($candidate);
    }

    /**
     * @param array<string,mixed> $fieldConfig
     */
    private function fieldValueTypeFromConfig(array $fieldConfig): AuditFieldValueType
    {
        $field = trim((string) ($fieldConfig['campoNombre'] ?? ''));
        $tipoDato = trim((string) ($fieldConfig['tipoDato'] ?? ''));
        if ($tipoDato === '') {
            throw new RuntimeException("Campo '{$field}' sin tipoDato — verificar audit-config en BD");
        }

        return AuditFieldValueType::fromInput($tipoDato);
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateField(
        string $field,
        ?string $fdvValue,
        ?string $docValue,
        string $documentQuality,
        bool $ambiguous,
        array $context = [],
        ?string $forcedType = null,
        string $tipoCampo = 'E',
        ?AuditFieldValueType $valueType = null
    ): array {
        if ($ambiguous) {
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => 'El campo es ambiguous: el documento contiene múltiples valores distintos para el mismo campo.',
            ];
        }

        if ($documentQuality !== 'legible' && $docValue === null) {
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => 'La calidad documental no permite concluir el valor del campo.',
            ];
        }

        if ($fdvValue === null && $docValue === null) {
            return ['resultado' => AuditFindingResult::SKIPPED->value];
        }

        if ($docValue === null) {
            return [
                'resultado' => AuditFindingResult::NOT_FOUND->value,
                'detalle'   => "El campo '{$field}' no se encontró en el documento. Valor esperado según registro de dispensación: '{$fdvValue}'."
            ];
        }

        if ($fdvValue === null) {
            return ['resultado' => AuditFindingResult::SKIPPED->value, 'detalle' => 'Sin valor auditable en registro de dispensación.'];
        }

        if ($forcedType === null) {
            throw new RuntimeException("Campo '{$field}' sin tipo de comparación — verificar audit-config en BD");
        }

        if ($valueType === null) {
            throw new RuntimeException("Campo '{$field}' sin tipoDato — verificar audit-config en BD");
        }

        if ($valueType->requiresSubsetComparison()) {
            return $this->evaluateSubsetField($field, $fdvValue, $docValue);
        }
        
        if ($valueType->requiresTokenSortComparison() && $forcedType === AuditComparisonType::SEMANTIC->value) {
            if (AuditFindingRules::samePersonNameTokenSet($fdvValue, $docValue)) {
                return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
            }
        }

        return match ($forcedType) {
            AuditComparisonType::SEMANTIC->value => $this->evaluateSemanticField($field, $fdvValue, $docValue, $context, $tipoCampo, $valueType),
            AuditComparisonType::BUSINESS->value => $this->evaluateBusinessField($field, $fdvValue, $docValue, $valueType),
            default                              => $this->evaluateExactField($field, $fdvValue, $docValue, $valueType),
        };
    }

    /**
     * Compara FDV contra un set documental potencialmente multi-código.
     *
     * Normaliza ambos lados, tokeniza el documento y verifica que todos los
     * tokens FDV están presentes en el set documental.
     *
     * @return array{resultado:string,tipo_auditoria:string,detalle?:string}
     */
    private function evaluateSubsetField(string $field, string $fdvValue, string $docValue): array
    {
        $fdvTokens = AuditFindingRules::tokenizeCodeField(AuditFindingRules::normalizeText($fdvValue));
        $docTokens = AuditFindingRules::tokenizeCodeField(AuditFindingRules::normalizeText($docValue));
        $docSet    = array_flip($docTokens); // O(1) lookup

        $missing = [];
        foreach ($fdvTokens as $token) {
            if (!isset($docSet[$token])) {
                $missing[] = $token;
            }
        }

        if ($missing === []) {
            return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
        }

        return [
            'resultado'      => AuditFindingResult::MISMATCH->value,
            'tipo_auditoria' => 'exact',
            'detalle'        => sprintf(
                "Código(s) FDV '%s' no encontrado(s) en el set documental '%s'.",
                implode(', ', $missing),
                $docValue
            ),
        ];
    }

    /**
     * Compara sets de tokens de trazabilidad (Lote, seriales).
     *
     * Reglas:
     *   FDV = Doc       → COINCIDE
     *   Doc ⊂ FDV       → NO_CONCLUYENTE (evidencia parcial)
     *   Doc ⊄ FDV       → VALOR_DISTINTO (token desconocido en documento)
     *   FDV ⊂ Doc       → VALOR_DISTINTO (documento trae extra no registrado)
     *
     * @param  array<int,string> $fdvTokens  Valores individuales de la FDV
     * @param  array<int,string> $docTokens  Valores individuales extraídos por Gemini
     * @return array{resultado:string,tipo_auditoria:string,detalle?:string}
     */
    private function evaluateTraceSetField(string $field, array $fdvTokens, array $docTokens): array
    {
        $fdvNorm = $this->normalizeTraceTokenSet($fdvTokens);
        $docNorm = $this->normalizeTraceTokenSet($docTokens);

        if ($fdvNorm === $docNorm) {
            return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
        }

        $extraInDoc = $this->tokensMissingFromSet($docNorm, $fdvNorm);
        if ($extraInDoc !== []) {
            return [
                'resultado'      => AuditFindingResult::MISMATCH->value,
                'tipo_auditoria' => 'exact',
                'detalle'        => sprintf(
                    "%s documental '%s' no registrado(s) en FDV.",
                    $field,
                    implode(', ', $extraInDoc)
                ),
            ];
        }

        $missingInDoc = $this->tokensMissingFromSet($fdvNorm, $docNorm);

        return [
            'resultado'      => AuditFindingResult::INCONCLUSIVE->value,
            'tipo_auditoria' => 'exact',
            'detalle'        => sprintf(
                "Evidencia documental parcial: falta %s '%s' registrado(s) en FDV.",
                $field,
                implode(', ', $missingInDoc)
            ),
        ];
    }

    /**
     * @param  array<int,string> $tokens
     * @return array<int,string>
     */
    private function normalizeTraceTokenSet(array $tokens): array
    {
        $normalized = array_values(array_unique(array_map(
            fn(string $token): string => AuditFindingRules::normalizeText($token),
            $tokens
        )));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int,string> $candidates
     * @param  array<int,string> $allowed
     * @return array<int,string>
     */
    private function tokensMissingFromSet(array $candidates, array $allowed): array
    {
        $allowedSet = array_flip($allowed);
        $missing = [];

        foreach ($candidates as $token) {
            if (!isset($allowedSet[$token])) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateExactField(
        string $field,
        string $fdvValue,
        string $docValue,
        AuditFieldValueType $valueType
    ): array
    {
        if (AuditFindingRules::normalizeForComparison($valueType, $fdvValue) === AuditFindingRules::normalizeForComparison($valueType, $docValue)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => "Registro de Dispensación '{$fdvValue}' difiere de Documento soporte '{$docValue}'.",
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateSemanticField(
        string $field,
        string $fdvValue,
        string $docValue,
        array $context,
        string $tipoCampo,
        AuditFieldValueType $valueType
    ): array
    {
        $normalizedFdv = AuditFindingRules::normalizeText($fdvValue);
        $normalizedDoc = AuditFindingRules::normalizeText($docValue);

        if ($valueType === AuditFieldValueType::PERSON_NAME && AuditFindingRules::samePersonNameTokenSet($fdvValue, $docValue)) {
            return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
        }

        if (AuditFindingRules::sameTokenSet($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        if (AuditComparisonType::isSubstringMatchAllowed($tipoCampo) && AuditFindingRules::containsNormalizedSubstring($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        $score     = AuditFindingRules::similarity($normalizedFdv, $normalizedDoc);
        $threshold = AuditComparisonType::getSemanticThreshold($tipoCampo);

        if ($score >= $threshold) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        if ($this->semanticJudge !== null && $valueType->allowsSemanticGeminiFallback()) {
            $judgeResult = $this->semanticJudge->evaluate($fdvValue, $docValue, array_merge($context, [
                'field' => $field,
                'tipoCampo' => $tipoCampo,
                'tipoDato' => $valueType->value,
                'call_purpose' => 'article_homologation',
            ]));
            if (is_array($judgeResult['gemini_metrics'] ?? null)) {
                $this->semanticMetrics[] = $judgeResult['gemini_metrics'];
            }
            if (($judgeResult['cache_hit'] ?? false) === true) {
                $this->semanticCacheHits++;
            }
            if ($judgeResult['is_match']) {
                return ['resultado' => AuditFindingResult::MATCH->value,        'detalle' => $judgeResult['reasoning']];
            }
            return ['resultado'     => AuditFindingResult::INCONCLUSIVE->value, 'detalle' => $judgeResult['reasoning']];
        }

        return [
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'detalle'   => sprintf(
                'El valor extraído del documento ("%s") difiere del valor esperado ("%s") en el campo %s. '
                . 'La diferencia no pudo resolverse automáticamente — requiere verificación manual.',
                mb_substr($docValue, 0, 120),
                mb_substr($fdvValue, 0, 120),
                $field
            ),
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateBusinessField(
        string $field,
        string $fdvValue,
        string $docValue,
        AuditFieldValueType $valueType
    ): array
    {
        if (!$valueType->isQuantitySummable()) {
            return $this->evaluateExactField($field, $fdvValue, $docValue, $valueType);
        }

        $fdvNumber = AuditFindingRules::parseNumber($fdvValue);
        $docNumber = AuditFindingRules::parseNumber($docValue);

        if ($fdvNumber === null || $docNumber === null) {
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => 'Valores no numéricos en campo de negocio.',
            ];
        }
        
        if ($fdvNumber <= $docNumber) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => sprintf('Cantidad en documento soporte (%.2f) excede cantidad registrada en registro de dispensación (%.2f).', $docNumber, $fdvNumber),
        ];
    }

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

            if (AuditFindingRules::isCalculatedVisualCheck($checkName)) {
                continue;
            }

            $severity       = AuditSeverity::fromInput((string) ($checkExpected['severity'] ?? ''))->value;

            if ($documentQuality !== 'legible') {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'La calidad documental no permite concluir la validación visual.'
                );
                continue;
            }

            $foundResult = $results[$checkName] ?? null;
            if (!is_array($foundResult)) {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'Check visual esperado no fue evaluado por el modelo.'
                );
                continue;
            }

            $isPresent  = (bool) ($foundResult['presente'] ?? false);
            $findings[] = $this->buildVisualFinding(
                $documentType, $checkName, $severity,
                $isPresent ? 'PRESENTE' : 'AUSENTE',
                $isPresent ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
                AuditFindingRules::normalizeNullableString($foundResult['detalle'] ?? null)
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

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{documentName:string,approved:bool,observation:?string}
     */
    private function buildDocumentDecision(string $documentType, array $findings): array
    {
        $approved     = true;
        $observations = [];

        foreach ($findings as $finding) {
            $resultCase = AuditFindingResult::tryFrom((string) ($finding['resultado'] ?? ''));
            if ($resultCase !== null && $resultCase->isFailure()) {
                $approved = false;
                $detail   = AuditFindingRules::normalizeNullableString($finding['detalle'] ?? null);
                if ($detail !== null) {
                    $observations[] = $detail;
                }
            }
        }

        $observations = array_values(array_unique($observations));
        $observation  = $observations === [] ? null : implode(' | ', array_slice($observations, 0, 3));

        return [
            'documentName' => DocumentExtractionContractBuilder::normalizeDocumentName($documentType),
            'approved'     => $approved,
            'observation'  => $observation,
        ];
    }
}
