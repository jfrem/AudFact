<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\DocumentQuality;
use App\Services\Audit\SemanticMatchJudge;
use RuntimeException;

class DocumentPolicyEngine
{
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
            $this->evaluateVisualChecks($documentType, $documentState['visual_checks'] ?? [], $visualChecks, $documentQuality, $sourceTruth)
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

            $rol = strtoupper((string) ($fieldConfig['rol'] ?? 'AUTORITATIVO'));
            if ($rol === 'INFORMATIVO') {
                continue;
            }

            if (AuditFindingRules::shouldSkipByCondition($fieldConfig['omitirSi'] ?? null, $sourceTruth, $documentQuality)) {
                continue;
            }

            [$docValue, $ambiguous, $evidenceMeta] = $this->resolveDocumentValue($canonicalField, $fields, $items);
            $fdvValue = $this->resolveSourceTruthValue($canonicalField, $sourceTruth);

            if ($fdvValue === null && $docValue === null && !$ambiguous) {
                continue;
            }

            $tipoCampo    = $fieldConfig['tipoCampo'] ?? 'E';
            $internalType = AuditComparisonType::fromTipoCampo($tipoCampo)->value;
            $comparison   = $this->evaluateField(
                $canonicalField,
                $fdvValue,
                $docValue,
                $documentQuality,
                $ambiguous,
                $context,
                $internalType,
                $tipoCampo
            );

            $findings[] = $this->buildDataFinding($canonicalField, $fieldConfig, $comparison, $documentType, $fdvValue, $docValue, $internalType, $rol, $evidenceMeta);
        }

        return $findings;
    }

    private function buildDataFinding(
        string $canonicalField,
        array $fieldConfig,
        array $comparison,
        string $documentType,
        ?string $fdvValue,
        ?string $docValue,
        string $internalType,
        string $rol = 'AUTORITATIVO',
        array $evidenceMeta = []
    ): array {
        $valueType = AuditFieldValueType::fromFieldName($canonicalField);

        $valoresDocumento = null;
        if ($valueType === AuditFieldValueType::CODE && $docValue !== null) {
            $valoresDocumento = AuditFindingRules::tokenizeCodeField($docValue);
        }

        $finding = [
            'campo'              => $canonicalField,
            'valorFuenteVerdad'  => $fdvValue,
            'valorDocumento'     => $docValue,
            'resultado'          => $comparison['resultado'],
            'severidad'          => AuditSeverity::fromInput($fieldConfig['severity'] ?? 'media')->value,
            'documento'          => $documentType,
            'detalle'            => $comparison['detalle'] ?? null,
            'tipo_auditoria'     => $comparison['tipo_auditoria'] ?? $internalType,
            'rol'                => $rol,
            'valueType'          => $valueType->value,
        ];

        if ($valoresDocumento !== null) {
            $finding['valoresDocumento'] = $valoresDocumento;
        }

        if ($evidenceMeta !== []) {
            $finding['_evidencia'] = array_filter($evidenceMeta, fn($v) => $v !== null);
        }

        return $finding;
    }

    /**
     * Boundary de conversión v1→escalar.
     *
     * Este es el ÚNICO punto donde shapes v1 de evidencia se convierten a escalares.
     * Todo lo que está downstream (evaluateField, evaluateExactField, etc.) recibe solo strings.
     * La metadata de evidencia (confianza, estadoExtraccion, evidencia, ubicacion) se extrae
     * aquí y se propaga al hallazgo canónico por separado.
     *
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:?string, 1:bool, 2:array<string,mixed>}  [valor, ambiguous, evidenceMeta]
     */
    private function resolveDocumentValue(string $field, array $fields, array $items): array
    {
        $valueType = AuditFieldValueType::fromFieldName($field);
        $evidenceMeta = [];

        $itemValues = [];
        foreach ($items as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $v1Shape = $row[$field];
            if (!is_array($v1Shape) || !array_key_exists('valor', $v1Shape)) {
                continue;
            }
            if (!AuditFindingRules::isPresent($v1Shape['valor'])) {
                continue;
            }
            $itemValues[] = AuditFindingRules::scalarToString($v1Shape['valor']);
            if ($evidenceMeta === []) {
                $evidenceMeta = [
                    'confianza'        => $v1Shape['confianza'] ?? null,
                    'estadoExtraccion' => $v1Shape['estadoExtraccion'] ?? null,
                    'evidencia'        => $v1Shape['evidencia'] ?? null,
                    'ubicacion'        => $v1Shape['ubicacion'] ?? null,
                    'valores'          => $v1Shape['valores'] ?? null,
                ];
            }
        }

        if ($itemValues !== []) {
            if ($valueType->isQuantitySummable()) {
                $total = AuditFindingRules::sumNumericValues($itemValues);
                if ($total !== null) {
                    return [AuditFindingRules::formatNumber($total), false, $evidenceMeta];
                }
            }

            $unique = array_values(array_unique($itemValues));
            if (count($unique) === 1) {
                return [$unique[0], false, $evidenceMeta];
            }

            return [null, true, $evidenceMeta];
        }

        if (array_key_exists($field, $fields)) {
            $v1Shape = $fields[$field];
            if (is_array($v1Shape) && array_key_exists('valor', $v1Shape) && AuditFindingRules::isPresent($v1Shape['valor'])) {
                $meta = [
                    'confianza'        => $v1Shape['confianza'] ?? null,
                    'estadoExtraccion' => $v1Shape['estadoExtraccion'] ?? null,
                    'evidencia'        => $v1Shape['evidencia'] ?? null,
                    'ubicacion'        => $v1Shape['ubicacion'] ?? null,
                    'valores'          => $v1Shape['valores'] ?? null,
                ];
                return [AuditFindingRules::scalarToString($v1Shape['valor']), false, $meta];
            }
        }

        return [null, false, $evidenceMeta];
    }



    /**
     * @param  array<string,mixed> $sourceTruth
     */
    private function resolveSourceTruthValue(string $field, array $sourceTruth): ?string
    {
        $valueType = AuditFieldValueType::fromFieldName($field);

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
        if (count($unique) === 1) {
            return $unique[0];
        }

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
        string $tipoCampo = 'E'
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
                'detalle'   => 'La calidad documental no permite concluir el valor con confianza suficiente.',
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

        $valueType = AuditFieldValueType::fromFieldName($field);

        if ($valueType->requiresSubsetComparison()) {
            return $this->evaluateSubsetField($field, $fdvValue, $docValue);
        }
        
        if ($valueType->requiresTokenSortComparison() && $forcedType === AuditComparisonType::SEMANTIC->value) {
            $normalizedFdv = AuditFindingRules::normalizeText($fdvValue);
            $normalizedDoc = AuditFindingRules::normalizeText($docValue);
            if (AuditFindingRules::sameTokenSet($normalizedFdv, $normalizedDoc)) {
                return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
            }
        }

        return match ($forcedType) {
            AuditComparisonType::SEMANTIC->value => $this->evaluateSemanticField($field, $fdvValue, $docValue, $context, $tipoCampo),
            AuditComparisonType::BUSINESS->value => $this->evaluateBusinessField($field, $fdvValue, $docValue),
            default                              => $this->evaluateExactField($field, $fdvValue, $docValue),
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
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateExactField(string $field, string $fdvValue, string $docValue): array
    {
        if (AuditFindingRules::normalizeForComparison($field, $fdvValue) === AuditFindingRules::normalizeForComparison($field, $docValue)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => "Registro de Dispensación '{$fdvValue}' difiere de Documento '{$docValue}'.",
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateSemanticField(string $field, string $fdvValue, string $docValue, array $context, string $tipoCampo = 'S'): array
    {
        $normalizedFdv = AuditFindingRules::normalizeText($fdvValue);
        $normalizedDoc = AuditFindingRules::normalizeText($docValue);

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

        if ($this->semanticJudge !== null) {
            $judgeResult = $this->semanticJudge->evaluate($fdvValue, $docValue, $context);
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
                'Similitud %.2f por debajo del umbral %.2f para comparación semántica.',
                $score,
                $threshold
            ),
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateBusinessField(string $field, string $fdvValue, string $docValue): array
    {
        if (!AuditFieldValueType::fromFieldName($field)->isQuantitySummable()) {
            return $this->evaluateExactField($field, $fdvValue, $docValue);
        }

        $fdvNumber = AuditFindingRules::parseNumber($fdvValue);
        $docNumber = AuditFindingRules::parseNumber($docValue);

        if ($fdvNumber === null || $docNumber === null) {
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => 'Valores no numéricos en campo de negocio.',
            ];
        }

        if ($docNumber <= $fdvNumber) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => sprintf('Cantidad en documento (%.2f) excede FDV (%.2f).', $docNumber, $fdvNumber),
        ];
    }

    private function evaluateVisualChecks(
        string $documentType,
        mixed $visualChecksExpected,
        mixed $visualChecksResults,
        string $documentQuality,
        array $sourceTruth
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

            $rol = strtoupper((string) ($checkExpected['rol'] ?? 'AUTORITATIVO'));
            if ($rol === 'INFORMATIVO') {
                continue;
            }

            if (AuditFindingRules::shouldSkipByCondition($checkExpected['omitirSi'] ?? null, $sourceTruth, $documentQuality)) {
                continue;
            }

            $severity       = AuditSeverity::fromInput((string) ($checkExpected['severity'] ?? ''))->value;

            if ($documentQuality !== 'legible') {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'La calidad documental no permite concluir la validación visual.',
                    $rol
                );
                continue;
            }

            $foundResult = $results[$checkName] ?? null;
            if (!is_array($foundResult)) {
                $findings[] = $this->buildVisualFinding(
                    $documentType, $checkName, $severity,
                    'NO_EVALUADO', AuditFindingResult::INCONCLUSIVE->value,
                    'Check visual esperado no fue evaluado por el modelo.',
                    $rol
                );
                continue;
            }

            $isPresent  = (bool) ($foundResult['presente'] ?? false);
            $findings[] = $this->buildVisualFinding(
                $documentType, $checkName, $severity,
                $isPresent ? 'PRESENTE' : 'AUSENTE',
                $isPresent ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
                AuditFindingRules::normalizeNullableString($foundResult['detalle'] ?? null),
                $rol
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
        ?string $detalle,
        string $rol
    ): array {
        return [
            'valorFuenteVerdad' => 'OBLIGATORIO',
            'valorDocumento'    => $valorDocumento,
            'resultado'         => $resultado,
            'detalle'           => $detalle,
            'campo'             => $displayField,
            'severidad'         => $severity,
            'documento'         => $documentType,
            'rol'               => $rol,
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
