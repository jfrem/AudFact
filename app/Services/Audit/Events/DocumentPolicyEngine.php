<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use App\Services\Audit\FieldClassifier;
use App\Services\Audit\SemanticMatchJudge;
use RuntimeException;

class DocumentPolicyEngine
{
    private const RESULT_MATCH = 'COINCIDE';
    private const RESULT_MISMATCH = 'VALOR_DISTINTO';
    private const RESULT_NOT_FOUND = 'NO_ENCONTRADO';
    private const RESULT_SKIPPED = 'OMITIDO';
    private const RESULT_INCONCLUSIVE = 'NO_CONCLUYENTE';

    private const PERSON_THRESHOLD = 0.85;
    private const ARTICLE_THRESHOLD = 0.82;
    private const TEXT_THRESHOLD = 0.90;

    private const PER_ITEM_FIELDS = [
        'CantidadEntregada',
        'CantidadPrescrita',
        'Lote',
        'FechaVencimiento',
        'NombreArticulo',
        'CUM',
        'Laboratorio',
        'CodigoArticulo',
        'CodigoProducto',
    ];

    private const NON_MVP_FIELDS = [
        'Tipo',
        'Cliente.Entidad',
    ];

    private const PRODUCT_HINT_FIELDS = [
        'CodigoProducto',
        'CUM',
    ];

    private const NON_SCALAR_MULTI_ITEM_FIELDS = [
        'Lote',
        'FechaVencimiento',
        'CodigoProducto',
        'CUM',
    ];

    private FieldClassifier $classifier;
    private AuditFindingRules $findingRules;
    private ?SemanticMatchJudge $semanticJudge;

    public function __construct(
        ?FieldClassifier $classifier = null,
        ?SemanticMatchJudge $semanticJudge = null
    ) {
        $this->classifier = $classifier ?? new FieldClassifier();
        $this->findingRules = new AuditFindingRules($this->classifier);
        $this->semanticJudge = $semanticJudge;
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $normalizedPayload
     * @return array<string,mixed>
     */
    public function evaluate(array $documentState, array $normalizedPayload): array
    {
        $documentType = trim((string) ($documentState['tipo_documento'] ?? $normalizedPayload['tipo_documento'] ?? ''));
        if ($documentType === '') {
            throw new RuntimeException('document_normalized sin tipo_documento');
        }
        $canonicalDocumentType = $this->normalizeDocumentType($documentType);

        $context = [
            'audit_id' => $documentState['audit_id'] ?? null,
            'document_id' => $documentState['document_id'] ?? null,
            'dis_det_nro' => $documentState['dis_det_nro'] ?? null,
            'document_type' => $documentType,
        ];

        $sourceTruth = $documentState['fuente_verdad'] ?? null;
        if (!is_array($sourceTruth)) {
            throw new RuntimeException('document_normalized sin fuente_verdad válida');
        }

        $fields = $this->normalizeAssociative($normalizedPayload['fields_normalized'] ?? []);
        $items = $this->normalizeRows($normalizedPayload['items_normalized'] ?? []);
        $visualChecks = $this->normalizeVisualCheckResults($normalizedPayload['visual_checks_resultado'] ?? []);
        $documentQuality = strtolower(trim((string) ($normalizedPayload['document_quality'] ?? '')));
        if (!in_array($documentQuality, ['legible', 'parcialmente_legible', 'ilegible'], true)) {
            throw new RuntimeException('document_normalized sin document_quality válido');
        }

        $expectedFields = $this->extractExpectedFields($documentState['extraction_schema'] ?? []);
        if ($expectedFields === []) {
            $expectedFields = array_keys($fields);
        }

        $findings = [];
        foreach ($expectedFields as $field) {
            $canonicalField = $this->classifier->normalizeField($field);
            if ($this->classifier->classify($canonicalField) === FieldClassifier::TYPE_VISUAL) {
                continue;
            }

            if (!$this->shouldAuditField($canonicalDocumentType, $canonicalField, $sourceTruth)) {
                continue;
            }

            [$docValue, $ambiguous] = $this->resolveDocumentValue($canonicalField, $fields, $items);
            $fdvValue = $this->resolveSourceTruthValue($canonicalField, $sourceTruth);
            if ($this->shouldSkipMultiValueField($canonicalField) && $fdvValue === null && $docValue === null) {
                continue;
            }

            $comparison = $this->evaluateField(
                $canonicalDocumentType,
                $canonicalField,
                $fdvValue,
                $docValue,
                $documentQuality,
                $ambiguous,
                $context
            );

            $findings[] = [
                'campo' => $this->classifier->getDisplayField($canonicalField),
                'valorFuenteVerdad' => $fdvValue,
                'valorDocumento' => $docValue,
                'resultado' => $comparison['resultado'],
                'severidad' => $this->classifier->getSeverity($canonicalField),
                'documento' => $documentType,
                'detalle' => $comparison['detalle'] ?? null,
            ];
        }

        foreach ($this->evaluateVisualChecks($documentType, $documentState['visual_checks'] ?? [], $visualChecks, $documentQuality) as $finding) {
            $findings[] = $finding;
        }

        $metrics = $this->findingRules->summarizeMetrics($findings);

        return [
            'document_name' => $documentType,
            'hallazgos' => [
                'items' => $findings,
                'metrics' => $metrics,
            ],
            'document_decision' => $this->buildDocumentDecision($documentType, $findings),
        ];
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

            $canonical = $this->classifier->normalizeField($check);
            $indexed[$canonical] = [
                'check' => $canonical,
                'presente' => (bool) ($row['presente'] ?? false),
                'detalle' => $this->normalizeNullableString($row['detalle'] ?? null),
                'severidad' => $this->normalizeVisualSeverity($row['severidad'] ?? null),
            ];
        }

        return $indexed;
    }

    /**
     * @return array<int,string>
     */
    private function extractExpectedFields(mixed $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }

        $properties = $schema['parameters']['properties']['fields']['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $fields = [];
        foreach ($properties as $field => $config) {
            if (is_string($field) && trim($field) !== '') {
                $fields[] = trim($field);
            }
        }

        return $fields;
    }

    /**
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:?string,1:bool}
     */
    private function resolveDocumentValue(string $field, array $fields, array $items): array
    {
        if (in_array($field, self::PER_ITEM_FIELDS, true)) {
            $values = [];
            foreach ($items as $row) {
                if (!array_key_exists($field, $row) || !$this->isPresent($row[$field])) {
                    continue;
                }

                $values[] = $this->scalarToString($row[$field]);
            }

            if ($values !== []) {
                if (in_array($field, ['CantidadEntregada', 'CantidadPrescrita'], true)) {
                    $sum = 0.0;
                    $hasNumeric = false;
                    foreach ($values as $value) {
                        $number = $this->parseNumber($value);
                        if ($number === null) {
                            continue;
                        }

                        $sum += $number;
                        $hasNumeric = true;
                    }

                    if ($hasNumeric) {
                        return [$this->formatNumber($sum), false];
                    }
                }

                $unique = array_values(array_unique($values));
                if (count($unique) === 1) {
                    return [$unique[0], false];
                }

                if ($this->shouldSkipMultiValueField($field)) {
                    return [null, false];
                }

                return [null, true];
            }
        }

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
        $items = is_array($sourceTruth['items'] ?? null) ? $sourceTruth['items'] : [];
        $column = $this->classifier->getSqlColumn($field);

        if ($column !== null) {
            $headerValue = $this->extractRowValue($header, $field, $column);
            if ($headerValue !== null) {
                return $headerValue;
            }
        }

        if ($items === []) {
            return null;
        }

        if (in_array($field, ['CantidadEntregada', 'CantidadPrescrita'], true)) {
            $sum = 0.0;
            $hasNumeric = false;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $value = $this->extractRowValue($item, $field, $column);
                if ($value === null) {
                    continue;
                }

                $number = $this->parseNumber($value);
                if ($number === null) {
                    continue;
                }

                $sum += $number;
                $hasNumeric = true;
            }

            return $hasNumeric ? $this->formatNumber($sum) : null;
        }

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

        if ($values === []) {
            return null;
        }

        $unique = array_values(array_unique($values));
        if (count($unique) === 1) {
            return $unique[0];
        }

        return $this->shouldSkipMultiValueField($field) ? null : $unique[0];
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
        array $context = []
    ): array {
        if ($ambiguous || $documentQuality !== 'legible') {
            if ($docValue === null || $ambiguous) {
                return [
                    'resultado' => self::RESULT_INCONCLUSIVE,
                    'detalle' => $ambiguous
                        ? 'El documento contiene multiples candidatos plausibles para el campo.'
                        : 'La calidad documental no permite concluir el valor con confianza suficiente.',
                ];
            }
        }

        if ($fdvValue === null && $docValue === null) {
            return ['resultado' => self::RESULT_SKIPPED];
        }

        if ($docValue === null) {
            if ($this->missingValueShouldBeInconclusive($documentType, $field)) {
                return [
                    'resultado' => self::RESULT_INCONCLUSIVE,
                    'detalle' => $this->missingValueDetail($documentType, $field),
                ];
            }

            return ['resultado' => self::RESULT_NOT_FOUND, 'detalle' => 'Campo esperado ausente en el documento.'];
        }

        if ($fdvValue === null) {
            return ['resultado' => self::RESULT_SKIPPED, 'detalle' => 'Sin valor auditable en fuente de verdad.'];
        }

        $type = $this->classifier->classify($field);
        return match ($type) {
            FieldClassifier::TYPE_SEMANTIC => $this->evaluateSemanticField($field, $fdvValue, $docValue, $context),
            FieldClassifier::TYPE_BUSINESS => $this->evaluateBusinessField($field, $fdvValue, $docValue),
            default => $this->evaluateExactField($field, $fdvValue, $docValue),
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
            'detalle' => "FDV '{$fdvValue}' difiere de Documento '{$docValue}'.",
        ];
    }

    /**
     * @return array{resultado:string,detalle?:string}
     */
    private function evaluateSemanticField(string $field, string $fdvValue, string $docValue, array $context): array
    {
        $normalizedFdv = $this->normalizeText($fdvValue);
        $normalizedDoc = $this->normalizeText($docValue);

        if ($this->sameTokenSet($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => self::RESULT_MATCH];
        }

        if ($field === 'NombreArticulo' && $this->containsNormalizedArticle($normalizedFdv, $normalizedDoc)) {
            return ['resultado' => self::RESULT_MATCH];
        }

        $score = $this->similarity($normalizedFdv, $normalizedDoc);
        $threshold = match ($field) {
            'NombrePaciente' => self::PERSON_THRESHOLD,
            'NombreArticulo' => self::ARTICLE_THRESHOLD,
            default => self::TEXT_THRESHOLD,
        };

        if ($score >= $threshold) {
            return ['resultado' => self::RESULT_MATCH];
        }

        if ($this->semanticJudge !== null) {
            $judgeResult = $this->semanticJudge->evaluate($fdvValue, $docValue, $context);
            if ($judgeResult['is_match']) {
                return [
                    'resultado' => self::RESULT_MATCH,
                    'detalle' => $judgeResult['reasoning']
                ];
            }
            return [
                'resultado' => self::RESULT_INCONCLUSIVE,
                'detalle' => $judgeResult['reasoning']
            ];
        }

        return [
            'resultado' => self::RESULT_INCONCLUSIVE,
            'detalle' => sprintf(
                'Similitud %.2f por debajo del umbral %.2f para comparación semántica.',
                $score,
                $threshold
            ),
        ];
    }

    private function containsNormalizedArticle(string $normalizedFdv, string $normalizedDoc): bool
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
        if (!in_array($field, ['CantidadEntregada', 'CantidadPrescrita'], true)) {
            return $this->evaluateExactField($field, $fdvValue, $docValue);
        }

        $fdvNumber = $this->parseNumber($fdvValue);
        $docNumber = $this->parseNumber($docValue);

        if ($fdvNumber === null || $docNumber === null) {
            return [
                'resultado' => self::RESULT_INCONCLUSIVE,
                'detalle' => 'Valores no numéricos en campo de negocio.',
            ];
        }

        if ($docNumber <= $fdvNumber) {
            return ['resultado' => self::RESULT_MATCH];
        }

        return [
            'resultado' => self::RESULT_MISMATCH,
            'detalle' => sprintf('Cantidad en documento (%.2f) excede FDV (%.2f).', $docNumber, $fdvNumber),
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

        $results = is_array($visualChecksResults) ? $visualChecksResults : [];
        $findings = [];

        foreach ($visualChecksExpected as $checkExpected) {
            if (!is_array($checkExpected)) {
                continue;
            }

            $checkName = trim((string) ($checkExpected['check'] ?? ''));
            if ($checkName === '') {
                continue;
            }

            $canonicalField = $this->classifier->normalizeField($checkName);
            $displayField = $this->classifier->getDisplayField($canonicalField);
            $severity = $this->normalizeVisualSeverity($checkExpected['severity'] ?? null);

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

            $actualPresent = (bool) ($foundResult['presente'] ?? false);
            $findings[] = $this->buildVisualFinding(
                $documentType, $displayField, $severity,
                $actualPresent ? 'PRESENTE' : 'AUSENTE',
                $actualPresent ? self::RESULT_MATCH : self::RESULT_MISMATCH,
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
            'valorDocumento' => $valorDocumento,
            'resultado' => $resultado,
            'detalle' => $detalle,
            'campo' => $displayField,
            'severidad' => $severity,
            'documento' => $documentType,
        ];
    }

    private function normalizeDocumentType(string $documentType): string
    {
        $upper = strtoupper(trim($documentType));
        $ascii = $this->stripAccents($upper);
        $normalized = str_replace([' ', '-'], '_', $ascii);
        $normalized = (string) preg_replace('/_+/', '_', $normalized);

        return match ($normalized) {
            'FORMULA', 'FORMULA_MEDICA' => 'FORMULA_MEDICA',
            'AUTORIZACION' => 'AUTORIZACION',
            'DISPENSA' => 'DISPENSA',
            'FACTURA' => 'FACTURA',
            default => $normalized,
        };
    }

    /**
     * @param  array<string,mixed> $sourceTruth
     */
    private function shouldAuditField(string $documentType, string $field, array $sourceTruth): bool
    {
        if (in_array($field, self::NON_MVP_FIELDS, true)) {
            return false;
        }

        if ($documentType === 'FORMULA_MEDICA' && $field === 'Autorizacion') {
            return false;
        }

        if ($documentType === 'FORMULA_MEDICA' && $field === 'NombreArticulo') {
            return false;
        }

        if (
            $documentType === 'FORMULA_MEDICA'
            && $field === 'CantidadPrescrita'
            && $this->isPresent($sourceTruth['header']['NumeroAutorizacion'] ?? null)
        ) {
            return false;
        }

        $authoritative = $this->normalizeDocumentType($this->classifier->getAuthoritativeDoc($field));
        if ($authoritative === 'MULTIPLE') {
            return true;
        }

        if ($authoritative === $documentType) {
            return true;
        }

        foreach ($this->classifier->getAlternativeDocs($field) as $alternative) {
            if ($this->normalizeDocumentType($alternative) === $documentType) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkipMultiValueField(string $field): bool
    {
        return in_array($field, self::NON_SCALAR_MULTI_ITEM_FIELDS, true);
    }

    private function missingValueShouldBeInconclusive(string $documentType, string $field): bool
    {
        if ($documentType === 'FORMULA_MEDICA' && $field === 'CodigoDiagnostico') {
            return true;
        }

        return false;
    }

    private function missingValueDetail(string $documentType, string $field): string
    {
        if ($documentType === 'FORMULA_MEDICA' && $field === 'CodigoDiagnostico') {
            return 'No fue posible confirmar el codigo diagnostico en la formula medica.';
        }

        return 'Campo esperado ausente en el documento.';
    }

    private function normalizeForComparison(string $field, string $value): string
    {
        if (in_array($field, ['CantidadEntregada', 'CantidadPrescrita', 'VlrCobrado'], true)) {
            $number = $this->parseNumber($value);
            return $number === null ? $this->normalizeText($value) : $this->formatNumber($number);
        }

        return $this->normalizeText($value);
    }

    private function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $ascii = $this->stripAccents(strtoupper($trimmed));
        $alnum = (string) preg_replace('/[^A-Z0-9]+/', ' ', $ascii);
        $compact = (string) preg_replace('/\s+/', ' ', trim($alnum));

        return $compact;
    }

    private function sameTokenSet(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $leftTokens = array_values(array_unique(array_filter(explode(' ', $left), static fn(string $token): bool => $token !== '')));
        $rightTokens = array_values(array_unique(array_filter(explode(' ', $right), static fn(string $token): bool => $token !== '')));

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

        $maxLength = max(strlen($left), strlen($right));
        $levenshteinScore = $maxLength > 0
            ? max(0.0, 1 - (levenshtein($left, $right) / $maxLength))
            : 0.0;

        $leftTokens = array_values(array_unique(array_filter(explode(' ', $left), static fn(string $token): bool => $token !== '')));
        $rightTokens = array_values(array_unique(array_filter(explode(' ', $right), static fn(string $token): bool => $token !== '')));
        $intersection = array_intersect($leftTokens, $rightTokens);
        $union = array_unique(array_merge($leftTokens, $rightTokens));
        $jaccard = $union === [] ? 0.0 : (count($intersection) / count($union));

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
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);
        $hasDot = str_contains($normalized, '.');
        $hasComma = str_contains($normalized, ',');

        if ($hasDot && $hasComma) {
            $lastDot = strrpos($normalized, '.');
            $lastComma = strrpos($normalized, ',');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
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

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{documentName:string,approved:bool,observation:?string}
     */
    private function buildDocumentDecision(string $documentType, array $findings): array
    {
        $approved = true;
        $observations = [];

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (in_array($result, [self::RESULT_MISMATCH, self::RESULT_NOT_FOUND, self::RESULT_INCONCLUSIVE], true)) {
                $approved = false;
                $detail = $this->normalizeNullableString($finding['detalle'] ?? null);
                if ($detail !== null) {
                    $observations[] = $detail;
                }
            }
        }

        $observations = array_values(array_unique($observations));
        $observation = $observations === [] ? null : implode(' | ', array_slice($observations, 0, 3));

        return [
            'documentName' => str_replace('_', ' ', $this->normalizeDocumentType($documentType)),
            'approved' => $approved,
            'observation' => $observation,
        ];
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
            'CRITICO', 'CRÍTICO', 'ALTA', 'HIGH' => FieldClassifier::SEVERITY_HIGH,
            'BAJA', 'LOW' => FieldClassifier::SEVERITY_LOW,
            default => FieldClassifier::SEVERITY_MEDIUM,
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
