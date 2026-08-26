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
use App\Services\Audit\TextNormalization;
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
    public function evaluate(array $documentState, array $normalizedPayload, string $facNro = ''): array
    {
        $this->resetSemanticMetrics();

        $documentType = $this->resolveDocumentType($documentState, $normalizedPayload);
        $context = $this->buildEvaluationContext($documentState, $documentType);
        $sourceTruth = $this->resolveSourceTruth($documentState);

        $fields        = FieldValueResolver::normalizeAssociative($normalizedPayload['fields_normalized'] ?? []);
        $items         = FieldValueResolver::normalizeRows($normalizedPayload['items_normalized'] ?? []);
        $visualChecks  = VisualCheckEvaluator::normalizeVisualCheckResults($normalizedPayload['visual_checks_resultado'] ?? []);
        $documentQuality = $this->resolveDocumentQuality($normalizedPayload);

        $indexedFields = $this->indexFieldsByCanonicalName($documentState['fields_config'] ?? []);

        $extractionWarnings = $normalizedPayload['extraction_warnings'] ?? [];

        $findings = array_merge(
            $this->evaluateDataFields($indexedFields, $fields, $items, $sourceTruth, $documentType, $documentQuality, $context, $extractionWarnings),
            VisualCheckEvaluator::evaluate($documentType, $documentState['visual_checks'] ?? [], $visualChecks, $documentQuality)
        );

        $internalFields = array_filter(
            $indexedFields,
            static fn(array $cfg): bool => strtoupper(trim((string) ($cfg['tipoCampo'] ?? ''))) === 'I'
        );
        if ($internalFields !== []) {
            $findings = array_merge(
                $findings,
                InternalIntegrityEvaluator::evaluate($sourceTruth, $documentType, $internalFields)
            );
        }

        $metrics = AuditFindingRules::summarizeMetrics($findings);

        return [
            'document_name'     => $documentType,
            'hallazgos'         => ['items' => $findings, 'metrics' => $metrics],
            'document_decision' => $this->buildDocumentDecision(
                $documentType, 
                $findings, 
                $facNro,
                $documentState['doc_id'] ?? null,
                $documentState['attachment_id'] ?? null
            ),
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
            'fac_nit_sec'   => $documentState['fac_nit_sec'] ?? null,
            'fuente_verdad' => $documentState['fuente_verdad'] ?? null,
            'usa_factor_conv' => (bool) ($documentState['factor_conv'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed> $documentState
     * @return array<string,mixed>
     */
    private function resolveSourceTruth(array $documentState): array
    {
        return FieldValueResolver::resolveSourceTruth($documentState);
    }

    /**
     * @param  array<string,mixed> $normalizedPayload
     */
    private function resolveDocumentQuality(array $normalizedPayload): string
    {
        return DocumentQuality::fromString((string) ($normalizedPayload['document_quality'] ?? ''))->value;
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
        array $context,
        array $extractionWarnings = []
    ): array {
        $findings = [];
        $itemSegmentationWarning = $this->findItemSegmentationWarning($extractionWarnings);

        foreach ($indexedFields as $canonicalField => $fieldConfig) {
            $tipoCampo = strtoupper(trim((string)($fieldConfig['tipoCampo'] ?? '')));
            if ($tipoCampo === 'V' || $tipoCampo === 'I') {
                continue;
            }

            $valueType = $this->fieldValueTypeFromConfig($fieldConfig);
            $docResolution = FieldValueResolver::resolveDocumentValue($canonicalField, $valueType, $fields, $items);
            $fdvResolution = FieldValueResolver::resolveSourceTruthField($canonicalField, $valueType, $sourceTruth);

            if (
                !$fdvResolution->hasValue()
                && !$docResolution->hasValue()
                && !$fdvResolution->ambiguous
                && !$docResolution->ambiguous
            ) {
                continue;
            }

            $tipoCampo    = $fieldConfig['tipoCampo'] ?? 'E';
            $internalType = AuditComparisonType::fromTipoCampo($tipoCampo)->value;
            $isItemSourced = $this->isItemSourcedField($canonicalField, $sourceTruth);

            if ($isItemSourced && $itemSegmentationWarning !== null) {
                $findings[] = $this->buildItemSegmentationFinding(
                    $canonicalField,
                    $fieldConfig,
                    $documentType,
                    $fdvResolution,
                    $docResolution,
                    $internalType,
                    $valueType,
                    $itemSegmentationWarning
                );
                continue;
            }

            $comparison = $this->evaluateDataFieldComparison(
                $canonicalField,
                $fdvResolution,
                $docResolution,
                $valueType,
                $documentQuality,
                $context,
                $internalType,
                $tipoCampo
            );

            $findings[] = $this->buildDataFinding(
                $canonicalField,
                $fieldConfig,
                $comparison,
                $documentType,
                $fdvResolution,
                $docResolution,
                $internalType,
                $valueType
            );
        }

        return $findings;
    }

    private function findItemSegmentationWarning(array $extractionWarnings): ?array
    {
        foreach ($extractionWarnings as $warning) {
            if (($warning['code'] ?? '') === 'ITEM_SEGMENTATION_INCOMPLETE') {
                return $warning;
            }
        }
        return null;
    }

    private function isItemSourcedField(string $field, array $sourceTruth): bool
    {
        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];
        if (array_key_exists($field, $header)) {
            return false;
        }

        $items = is_array($sourceTruth['items'] ?? null) ? $sourceTruth['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists($field, $item)) {
                return true;
            }
        }
        return false;
    }

    private function buildItemSegmentationFinding(
        string $canonicalField,
        array $fieldConfig,
        string $documentType,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution,
        string $internalType,
        AuditFieldValueType $valueType,
        array $warning
    ): array {
        $finding = $this->buildDataFinding(
            $canonicalField,
            $fieldConfig,
            [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle' => sprintf(
                    "La extracción de líneas del documento fue incompleta: se extrajeron %d de %d items esperados. No se confirma el total de %s.",
                    $warning['extracted_items_count'] ?? 0,
                    $warning['expected_items_count'] ?? 0,
                    TextNormalization::humanizeFieldName($canonicalField)
                ),
            ],
            $documentType,
            $fdvResolution,
            $docResolution,
            $internalType,
            $valueType
        );

        $finding['extraction_meta'] = $finding['extraction_meta'] ?? [];
        $finding['extraction_meta']['item_segmentation'] = $warning;

        return $finding;
    }

    /**
     * @return array{resultado:string,tipo_auditoria?:string,detalle?:string}
     */
    private function evaluateDataFieldComparison(
        string $canonicalField,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution,
        AuditFieldValueType $valueType,
        string $documentQuality,
        array $context,
        string $internalType,
        string $tipoCampo
    ): array {
        if ($this->canEvaluateTraceSet($valueType, $fdvResolution, $docResolution)) {
            return $this->evaluateTraceSetField(
                $canonicalField,
                $fdvResolution->values,
                $docResolution->values
            );
        }

        if ($this->canEvaluateArticleSet($valueType, $fdvResolution, $docResolution)) {
            return $this->evaluateArticleSetField(
                $canonicalField,
                $fdvResolution->values,
                $docResolution->values,
                $context,
                $tipoCampo
            );
        }

        if ($fdvResolution->ambiguous || $docResolution->ambiguous) {
            return $this->evaluateAmbiguousField($canonicalField, $fdvResolution, $docResolution);
        }

        return $this->evaluateField(
            $canonicalField,
            $fdvResolution->displayValue,
            $docResolution->displayValue,
            $documentQuality,
            $context,
            $internalType,
            $tipoCampo,
            $valueType
        );
    }

    private function canEvaluateTraceSet(
        AuditFieldValueType $valueType,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution
    ): bool {
        return $valueType->requiresTraceSetComparison()
            && $fdvResolution->hasValue()
            && $docResolution->hasValue()
            && !$fdvResolution->ambiguous
            && !$docResolution->ambiguous;
    }

    private function canEvaluateArticleSet(
        AuditFieldValueType $valueType,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution
    ): bool {
        return $valueType->requiresArticleSetComparison()
            && $fdvResolution->hasValue()
            && $docResolution->hasValue()
            && !$fdvResolution->ambiguous
            && !$docResolution->ambiguous
            && (count($fdvResolution->values) >= 2 || count($docResolution->values) >= 2);
    }

    private function buildDataFinding(
        string $canonicalField,
        array $fieldConfig,
        array $comparison,
        string $documentType,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution,
        string $internalType,
        AuditFieldValueType $valueType
    ): array {
        $valoresFuenteVerdad = FieldValueResolver::resolveFindingValues($valueType, $fdvResolution);
        $valoresDocumento = FieldValueResolver::resolveFindingValues($valueType, $docResolution);

        $finding = [
            'campo'              => $canonicalField,
            'valorFuenteVerdad'  => $fdvResolution->displayValue,
            'valorDocumento'     => $docResolution->displayValue,
            'resultado'          => $comparison['resultado'],
            'severidad'          => AuditSeverity::fromInput($fieldConfig['severity'] ?? 'media')->value,
            'documento'          => $documentType,
            'detalle'            => $comparison['detalle'] ?? null,
            'tipo_auditoria'     => $comparison['tipo_auditoria'] ?? $internalType,
            'valueType'          => $valueType->value,
            'codigoCampo'        => $fieldConfig['codigoCampo'] ?? null,
        ];

        if ($valoresFuenteVerdad !== null) {
            $finding['valoresFuenteVerdad'] = $valoresFuenteVerdad;
        }

        if ($valoresDocumento !== null) {
            $finding['valoresDocumento'] = $valoresDocumento;
        }

        if ($docResolution->evidenceMeta !== []) {
            $finding['extraction_meta'] = array_filter($docResolution->evidenceMeta, fn($v) => $v !== null);
        }

        return $finding;
    }

    /**
     * @return array{resultado:string,detalle:string}
     */
    private function evaluateAmbiguousField(
        string $field,
        ResolvedAuditValue $fdvResolution,
        ResolvedAuditValue $docResolution
    ): array {
        $humanField = TextNormalization::humanizeFieldName($field);
        $values = $docResolution->ambiguous ? $docResolution->values : $fdvResolution->values;

        return [
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'detalle'   => sprintf(
                "Se encontraron multiples valores distintos para '%s' (%s), lo que impide determinar cual es el correcto.",
                $humanField,
                implode(', ', $values)
            ),
        ];
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
        array $context = [],
        ?string $forcedType = null,
        string $tipoCampo = 'E',
        ?AuditFieldValueType $valueType = null
    ): array {
        $humanField = TextNormalization::humanizeFieldName($field);

        if ($documentQuality !== 'legible' && $docValue === null) {
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => "No fue posible verificar '{$humanField}' porque la calidad de la imagen del documento no permite leer el valor con certeza.",
            ];
        }

        if ($fdvValue === null && $docValue === null) {
            return ['resultado' => AuditFindingResult::SKIPPED->value];
        }

        if ($docValue === null) {
            return [
                'resultado' => AuditFindingResult::NOT_FOUND->value,
                'detalle'   => "No se encontró '{$humanField}' en el documento soporte. Según el registro de dispensación debería figurar: '{$fdvValue}'."
            ];
        }

        if ($fdvValue === null) {
            return ['resultado' => AuditFindingResult::SKIPPED->value, 'detalle' => "El registro de dispensación no contiene un valor de referencia para '{$humanField}', por lo que no se evaluó."];
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
            if (TextNormalization::samePersonNameTokenSet($fdvValue, $docValue)) {
                return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
            }
        }

        return match ($forcedType) {
            AuditComparisonType::SEMANTIC->value => $this->evaluateSemanticField($field, $fdvValue, $docValue, $context, $tipoCampo, $valueType),
            AuditComparisonType::BUSINESS->value => $this->evaluateBusinessField($field, $fdvValue, $docValue, $valueType, $context),
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
        $fdvTokens = AuditFindingRules::tokenizeCodeField(TextNormalization::normalizeText($fdvValue));
        $docTokens = AuditFindingRules::tokenizeCodeField(TextNormalization::normalizeText($docValue));
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
                "El código '%s' del registro de dispensación no aparece en el documento soporte, donde solo se encontró: '%s'.",
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
                    "El documento soporte contiene %s '%s' que no figura en el registro de dispensación.",
                    TextNormalization::humanizeFieldName($field),
                    implode(', ', $extraInDoc)
                ),
            ];
        }

        $missingInDoc = $this->tokensMissingFromSet($fdvNorm, $docNorm);

        return [
            'resultado'      => AuditFindingResult::INCONCLUSIVE->value,
            'tipo_auditoria' => 'exact',
            'detalle'        => sprintf(
                "El documento soporte solo muestra parte de la información: falta %s '%s' que sí aparece en el registro de dispensación.",
                TextNormalization::humanizeFieldName($field),
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
            fn(string $token): string => TextNormalization::normalizeText($token),
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
     * Emparejamiento biyectivo greedy de artículos FDV vs documento.
     *
     * Cascada de 3 niveles por par (f_i, d_j):
     *   1. Normalización léxica directa o contención de substring.
     *   2. Similitud léxica ≥ umbral semántico.
     *   3. ArticleSemanticMatchJudge (Gemini con caché Redis 30d).
     *
     * Cada d_j solo puede emparejarse con un único f_i (consumo).
     *
     * @param  array<int,string> $fdvArticles  Artículos de la FDV
     * @param  array<int,string> $docArticles  Artículos extraídos del documento
     * @param  array<string,mixed> $context
     * @return array{resultado:string,tipo_auditoria?:string,detalle?:string}
     */
    private function evaluateArticleSetField(
        string $field,
        array $fdvArticles,
        array $docArticles,
        array $context,
        string $tipoCampo
    ): array {
        $threshold = AuditComparisonType::getSemanticThreshold($tipoCampo);

        // Normalizar ambos sets
        $fdvNorm = array_map(fn(string $a): string => TextNormalization::normalizeText($a), $fdvArticles);
        $docNorm = array_map(fn(string $a): string => TextNormalization::normalizeText($a), $docArticles);

        /** @var array<int,true> */
        $usedDoc = [];
        /** @var array<int,int> Mapa fdvIdx => docIdx emparejado */
        $matchedFdv = [];

        // Fase 1: Match léxico directo (normalización exacta o contención de substring)
        foreach ($fdvNorm as $fi => $fNorm) {
            if (isset($matchedFdv[$fi])) {
                continue;
            }
            foreach ($docNorm as $di => $dNorm) {
                if (isset($usedDoc[$di])) {
                    continue;
                }
                if ($fNorm === $dNorm || TextNormalization::containsNormalizedSubstring($fNorm, $dNorm)) {
                    $matchedFdv[$fi] = $di;
                    $usedDoc[$di] = true;
                    break;
                }
            }
        }

        // Fase 2: Similitud léxica para los no emparejados
        foreach ($fdvNorm as $fi => $fNorm) {
            if (isset($matchedFdv[$fi])) {
                continue;
            }
            foreach ($docNorm as $di => $dNorm) {
                if (isset($usedDoc[$di])) {
                    continue;
                }
                if (TextNormalization::similarity($fNorm, $dNorm) >= $threshold) {
                    $matchedFdv[$fi] = $di;
                    $usedDoc[$di] = true;
                    break;
                }
            }
        }

        // Fase 3: ArticleSemanticMatchJudge para los no emparejados
        if ($this->semanticJudge !== null) {
            foreach ($fdvArticles as $fi => $fdvOriginal) {
                if (isset($matchedFdv[$fi])) {
                    continue;
                }
                foreach ($docArticles as $di => $docOriginal) {
                    if (isset($usedDoc[$di])) {
                        continue;
                    }
                    $judgeResult = $this->semanticJudge->evaluate($fdvOriginal, $docOriginal, array_merge($context, [
                        'field' => $field,
                        'tipoCampo' => $tipoCampo,
                        'tipoDato' => AuditFieldValueType::ARTICLE_NAME->value,
                        'call_purpose' => 'article_homologation',
                    ]));
                    if (is_array($judgeResult['gemini_metrics'] ?? null)) {
                        $this->semanticMetrics[] = $judgeResult['gemini_metrics'];
                    }
                    if (($judgeResult['cache_hit'] ?? false) === true) {
                        $this->semanticCacheHits++;
                    }
                    if ($judgeResult['is_match']) {
                        $matchedFdv[$fi] = $di;
                        $usedDoc[$di] = true;
                        break;
                    }
                }
            }
        }

        // Resultado: evaluar cobertura de la FDV
        if (count($matchedFdv) === count($fdvArticles)) {
            return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'semantic'];
        }

        // Artículos FDV no emparejados
        $unmatchedNames = [];
        foreach ($fdvArticles as $fi => $name) {
            if (!isset($matchedFdv[$fi])) {
                $unmatchedNames[] = $name;
            }
        }

        $humanField = TextNormalization::humanizeFieldName($field);

        return [
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'tipo_auditoria' => 'semantic',
            'detalle' => sprintf(
                "En '%s', el documento soporte no contiene evidencia para %d de %d artículos de la dispensación: '%s'.",
                $humanField,
                count($unmatchedNames),
                count($fdvArticles),
                implode(', ', $unmatchedNames)
            ),
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateExactField(
        string $field,
        string $fdvValue,
        string $docValue,
        AuditFieldValueType $valueType
    ): array {
        if (
            AuditFindingRules::normalizeForComparison($valueType, $fdvValue)
            === AuditFindingRules::normalizeForComparison($valueType, $docValue)
        ) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => "El valor en el documento soporte ('{$docValue}') no coincide con el registro de dispensación ('{$fdvValue}').",
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
    ): array {
        $normalizedFdv = TextNormalization::normalizeText($fdvValue);
        $normalizedDoc = TextNormalization::normalizeText($docValue);

        if ($valueType === AuditFieldValueType::PERSON_NAME && TextNormalization::samePersonNameTokenSet($fdvValue, $docValue)) {
            return ['resultado' => AuditFindingResult::MATCH->value, 'tipo_auditoria' => 'exact'];
        }

        if (TextNormalization::sameTokenSet($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        if (AuditComparisonType::isSubstringMatchAllowed($tipoCampo) && TextNormalization::containsNormalizedSubstring($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        $score     = TextNormalization::similarity($normalizedFdv, $normalizedDoc);
        $threshold = AuditComparisonType::getSemanticThreshold($tipoCampo);

        if ($score >= $threshold) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        if ($this->semanticJudge !== null && $valueType->allowsSemanticGeminiFallback()) {
            $callPurpose = match ($valueType) {
                AuditFieldValueType::PERSON_NAME  => 'person_name_match',
                AuditFieldValueType::ARTICLE_NAME => 'article_homologation',
                default                           => 'generic_semantic',
            };
            $judgeResult = $this->semanticJudge->evaluate($fdvValue, $docValue, array_merge($context, [
                'field' => $field,
                'tipoCampo' => $tipoCampo,
                'tipoDato' => $valueType->value,
                'call_purpose' => $callPurpose,
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
                'En el campo %s, el documento soporte indica "%s" mientras que el registro de dispensación tiene "%s". '
                    . 'La diferencia requiere verificación por parte del auditor.',
                TextNormalization::humanizeFieldName($field),
                mb_substr($docValue, 0, 120),
                mb_substr($fdvValue, 0, 120)
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
        AuditFieldValueType $valueType,
        array $context
    ): array {
        if (!$valueType->isQuantitySummable()) {
            return $this->evaluateExactField($field, $fdvValue, $docValue, $valueType);
        }

        $fdvNumber = AuditFindingRules::parseNumber($fdvValue);
        $docNumber = AuditFindingRules::parseNumber($docValue);

        if ($fdvNumber === null || $docNumber === null) {
            $humanField = TextNormalization::humanizeFieldName($field);
            return [
                'resultado' => AuditFindingResult::INCONCLUSIVE->value,
                'detalle'   => "No fue posible comparar '{$humanField}' porque uno de los valores no es numérico. Registro de dispensación: '{$fdvValue}', documento soporte: '{$docValue}'.",
            ];
        }

        $factorConv = 0.0;
        $items = $context['fuente_verdad']['items'] ?? [];
        foreach ($items as $item) {
            $factorConv += (float)($item['FactorConv'] ?? 0);
        }

        $factorConv = max(1.0, $factorConv);

        $isMatch = ($context['usa_factor_conv'] ?? false)
            ? $this->isQuantityWithinTolerance($docNumber, $fdvNumber, $factorConv)
            : $docNumber >= $fdvNumber;

        if ($isMatch) {
            return ['resultado' => AuditFindingResult::MATCH->value];
        }

        $usaFactorConv = (bool) ($context['usa_factor_conv'] ?? false);

        return [
            'resultado' => AuditFindingResult::MISMATCH->value,
            'detalle'   => $usaFactorConv
                ? sprintf(
                    'La cantidad en el documento soporte (%.2f) con Factor de Conversion (%.2f) no justifica la cantidad dispensada (%.2f).',
                    $docNumber,
                    $factorConv,
                    $fdvNumber
                )
                : sprintf(
                    'La cantidad en el documento soporte (%.2f) es menor a la cantidad dispensada (%.2f).',
                    $docNumber,
                    $fdvNumber
                ),
        ];
    }

    /**
     * Banda simétrica ±1 factor de conversión.
     *
     * Aprueba si |Autorizado − Facturado| < Factor, rechaza parciales
     * grandes y excesos grandes por igual.
     */
    private function isQuantityWithinTolerance(float $docNumber, float $fdvNumber, float $factorConv): bool
    {
        $lowerBound = $fdvNumber - $factorConv;
        $upperBound = $fdvNumber + $factorConv;

        return ($docNumber > $lowerBound && $docNumber <= $fdvNumber)
            || ($docNumber < $upperBound && $docNumber >= $fdvNumber);
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{documentName:string,approved:bool,payload?:array<string,mixed>}
     */
    private function buildDocumentDecision(
        string $documentType, 
        array $findings, 
        string $facNro,
        mixed $docId = null,
        mixed $attachmentId = null
    ): array
    {
        $approved  = true;
        $hallazgos = [];

        foreach ($findings as $finding) {
            $resultCase = AuditFindingResult::tryFrom((string) ($finding['resultado'] ?? ''));
            if ($resultCase !== null && $resultCase->isFailure()) {
                $approved = false;
                $detail   = AuditFindingRules::normalizeNullableString($finding['detalle'] ?? null);
                if ($detail !== null) {
                    $codigo = trim((string) ($finding['codigoCampo'] ?? 'DATA'));
                    $hallazgos[] = [
                        'Codigo' => $codigo,
                        'Descripcion' => $detail,
                    ];
                }
            }
        }

        // Deduplicate hallazgos array by Description and Code
        $uniqueHallazgos = [];
        $seen = [];
        foreach ($hallazgos as $h) {
            $key = $h['Codigo'] . '|' . $h['Descripcion'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueHallazgos[] = $h;
            }
        }

        $payload = null;
        if (!$approved) {
            $payload = AuditFindingRules::buildRejectionPayload($facNro, $uniqueHallazgos);
        }

        return [
            'documentName' => DocumentExtractionContractBuilder::normalizeDocumentName($documentType),
            'approved'     => $approved,
            'payload'      => $payload,
            'doc_id'       => is_scalar($docId) ? (string)$docId : null,
            'attachment_id'=> is_scalar($attachmentId) ? (string)$attachmentId : null,
        ];
    }
}
