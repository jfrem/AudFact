<?php

namespace App\Services\Audit;

class RuleEngine
{
    public const MATCH = 'COINCIDE';
    public const MISMATCH = 'VALOR_DISTINTO';
    public const NOT_FOUND = 'NO_ENCONTRADO';
    public const NOT_APPLICABLE = 'NO_APLICA';
    public const SKIPPED = 'OMITIDO';

    private const WEIGHT_HIGH = 10;
    private const WEIGHT_MEDIUM = 5;
    private const WEIGHT_LOW = 1;

    private const THRESHOLD_WARNING = 15;
    private const THRESHOLD_ERROR = 30;

    private const QUANTITY_EXCESS_TOLERANCE = 5;
    private const DOCUMENT_FIELD_KEY_SEPARATOR = '|';
    private const ANY_DOCUMENT_KEY = '*';

    private const REGIME_SKIP_VALUES = ['N/D', 'ARL', 'ND', ''];

    // Per-item: getFdvValue concatena todas las rows SQL para estos campos.
    public const PER_ITEM_FIELDS = [
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

    public function evaluate(
        array $fdvItems,
        array $extractedDocs,
        array $visualChecks,
        array $semanticResults,
        array $auditConfig,
        FieldClassifier $classifier
    ): array {
        $items = [];
        $metrics = $this->initializeMetrics();
        $semanticMap = $this->indexSemanticResults($semanticResults);

        // Indexar extracted fields por tipo de documento
        $docFieldsMap = $this->indexExtractedFields($extractedDocs);

        // Evaluar cada campo configurado
        $fieldsToEvaluate = $this->resolveFields($auditConfig, $classifier);

        foreach ($fieldsToEvaluate as $fieldConfig) {
            $field = $fieldConfig['field'];
            $severity = $fieldConfig['severity'] ?? $classifier->getSeverity($field);
            $type = $classifier->classify($field);
            $configuredDocument = $fieldConfig['document'] ?? null;

            // Obtener valores
            $fdvValue = $this->getFdvValue($field, $fdvItems, $classifier);
            $docValue = $this->getDocValue($field, $docFieldsMap, $classifier, $configuredDocument);

            $result = $this->evaluateField(
                $field,
                $type,
                $fdvValue,
                $docValue,
                $semanticMap,
                $visualChecks,
                $configuredDocument
            );

            $classification = $result['classification'];
            $detail = $result['detail'] ?? null;

            // Construir item
            $item = [
                'campo' => $field,
                'valorFuenteVerdad' => $fdvValue,
                'valorDocumento' => $docValue,
                'resultado' => $classification,
                'severidad' => $severity,
                'documento' => $configuredDocument ?? $classifier->getAuthoritativeDoc($field),
            ];

            if ($detail !== null) {
                $item['detalle'] = $detail;
            }

            if (isset($result['similarity'])) {
                $item['similarity'] = $result['similarity'];
            }

            $items[] = $item;
            $this->updateMetrics($metrics, $classification, $severity);
        }

        // Risk score (§07)
        $riskScore = $this->calculateRiskScore($metrics);
        $response = $this->classifyResponse($riskScore);

        return [
            'response' => $response,
            'severity' => $this->getOverallSeverity($metrics),
            'message' => $this->buildMessage($response, $metrics, $riskScore),
            'documento' => AuditResponseSchema::DOCUMENTO_MULTIPLE,
            'data' => ['items' => $items],
            'metrics' => $metrics,
            'risk_score' => $riskScore,
            'config_used' => $this->buildConfigUsed($metrics, $auditConfig),
        ];
    }

    private function evaluateField(
        string $field,
        string $type,
        ?string $fdvValue,
        ?string $docValue,
        array $semanticMap,
        array $visualChecks,
        ?string $document = null
    ): array {
        if ($type === FieldClassifier::TYPE_VISUAL) {
            return $this->evaluateVisual($field, $visualChecks, $document);
        }

        // Campo no encontrado en documento
        if ($docValue === null || trim($docValue) === '') {
            if ($fdvValue === null || trim($fdvValue) === '') {
                return ['classification' => self::SKIPPED, 'detail' => 'Ambos valores vacíos'];
            }
            return ['classification' => self::NOT_FOUND, 'detail' => 'No encontrado en documento'];
        }

        // FDV vacía → no aplica
        if ($fdvValue === null || trim($fdvValue) === '') {
            return ['classification' => self::NOT_APPLICABLE, 'detail' => 'Sin valor en Fuente de Verdad'];
        }

        return match ($type) {
            FieldClassifier::TYPE_EXACT => $this->evaluateExact($field, $fdvValue, $docValue),
            FieldClassifier::TYPE_SEMANTIC => $this->evaluateSemantic($field, $semanticMap, $document),
            FieldClassifier::TYPE_BUSINESS => $this->evaluateBusiness($field, $fdvValue, $docValue),
            default => $this->evaluateExact($field, $fdvValue, $docValue),
        };
    }

    private function evaluateExact(string $field, string $fdvValue, string $docValue): array
    {
        $normalizedFdv = $this->normalizeForComparison($field, $fdvValue);
        $normalizedDoc = $this->normalizeForComparison($field, $docValue);

        if ($normalizedFdv === $normalizedDoc) {
            return ['classification' => self::MATCH];
        }

        return [
            'classification' => self::MISMATCH,
            'detail' => "FDV: '{$fdvValue}' ≠ Doc: '{$docValue}'",
        ];
    }

    private function evaluateSemantic(string $field, array $semanticMap, ?string $document): array
    {
        $sr = $semanticMap[$this->buildDocumentFieldKey($field, $document)]
            ?? $semanticMap[$this->buildDocumentFieldKey($field, null)]
            ?? null;
        if ($sr === null) {
            return ['classification' => self::NOT_APPLICABLE, 'detail' => 'Sin resultado semántico'];
        }

        if ($sr['match']) {
            return [
                'classification' => self::MATCH,
                'similarity' => $sr['similarity'],
            ];
        }

        return [
            'classification' => self::MISMATCH,
            'detail' => sprintf(
                "Similitud %.2f < umbral %.2f | FDV: '%s' ≠ Doc: '%s'",
                $sr['similarity'],
                $sr['threshold'],
                $sr['fdvValue'] ?? '',
                $sr['docValue'] ?? ''
            ),
            'similarity' => $sr['similarity'],
        ];
    }

    private function evaluateVisual(string $field, array $visualChecks, ?string $document): array
    {
        $check = $visualChecks[$this->buildDocumentFieldKey($field, $document)]
            ?? $visualChecks[$field]
            ?? null;
        if ($check === null) {
            return ['classification' => self::NOT_FOUND, 'detail' => 'Check visual no ejecutado'];
        }

        $present = (bool) ($check['present'] ?? false);
        $evidence = $check['evidence'] ?? null;

        if ($present) {
            return [
                'classification' => self::MATCH,
                'detail' => $evidence,
            ];
        }

        return [
            'classification' => self::MISMATCH,
            'detail' => 'No detectado visualmente' . ($evidence ? ": {$evidence}" : ''),
        ];
    }

    /**
     * Construye una llave estable para resultados por documento + campo.
     */
    private function buildDocumentFieldKey(string $field, ?string $document): string
    {
        return ($document ?? self::ANY_DOCUMENT_KEY) . self::DOCUMENT_FIELD_KEY_SEPARATOR . $field;
    }

    private function evaluateBusiness(string $field, string $fdvValue, string $docValue): array
    {
        return match ($field) {
            'CantidadEntregada', 'CantidadPrescrita' => $this->evaluateQuantities($fdvValue, $docValue),
            'Cliente.Regimen' => $this->evaluateRegimen($fdvValue, $docValue),
            default => $this->evaluateExact($field, $fdvValue, $docValue),
        };
    }

    private function evaluateQuantities(string $fdvValue, string $docValue): array
    {
        $fdvQty = $this->parseQuantity($fdvValue);
        $docQty = $this->parseQuantity($docValue);

        if ($fdvQty === null || $docQty === null) {
            return $this->evaluateExact('cantidad', $fdvValue, $docValue);
        }

        // Exacto
        if ($fdvQty === $docQty) {
            return ['classification' => self::MATCH];
        }

        // Entrega parcial (doc ≤ fdv)
        if ($docQty <= $fdvQty) {
            return [
                'classification' => self::MATCH,
                'detail' => "Entrega parcial: {$docQty}/{$fdvQty}",
            ];
        }

        // Exceso dentro de tolerancia POSITIVA
        $excess = $docQty - $fdvQty;
        if ($excess <= self::QUANTITY_EXCESS_TOLERANCE) {
            return [
                'classification' => self::MATCH,
                'detail' => "Exceso menor tolerado: +{$excess} (tolerancia: " . self::QUANTITY_EXCESS_TOLERANCE . ")",
            ];
        }

        return [
            'classification' => self::MISMATCH,
            'detail' => "Exceso fuera de tolerancia: Doc={$docQty}, FDV={$fdvQty}, exceso={$excess}",
        ];
    }

    private function evaluateRegimen(string $fdvValue, string $docValue): array
    {
        $normalizedFdv = strtoupper(trim($fdvValue));

        // Skip para regímenes excluidos
        if (in_array($normalizedFdv, self::REGIME_SKIP_VALUES, true)) {
            return [
                'classification' => self::SKIPPED,
                'detail' => "Régimen '{$fdvValue}' excluido de validación",
            ];
        }

        $normalizedDoc = strtoupper(trim($docValue));

        if ($normalizedFdv === $normalizedDoc) {
            return ['classification' => self::MATCH];
        }

        // Equivalencias conocidas
        $equivalences = [
            'SUBSIDIADO' => 'S',
            'CONTRIBUTIVO' => 'C',
        ];

        $fdvNorm = $equivalences[$normalizedFdv] ?? $normalizedFdv;
        $docNorm = $equivalences[$normalizedDoc] ?? $normalizedDoc;

        if ($fdvNorm === $docNorm) {
            return ['classification' => self::MATCH];
        }

        return [
            'classification' => self::MISMATCH,
            'detail' => "Régimen: FDV='{$fdvValue}' ≠ Doc='{$docValue}'",
        ];
    }

    private function normalizeForComparison(string $field, string $value): string
    {
        $value = trim($value);

        if (in_array($field, ['NumeroIdentificacion', 'NumeroFactura', 'NumeroFormula', 'Autorizacion'], true)) {
            return $this->normalizeIdentifier($value);
        }

        if (str_contains($field, 'Fecha')) {
            return $this->normalizeDateValue($value);
        }

        if ($field === 'Lote') {
            return $this->normalizeListValue($value);
        }

        if ($field === 'VlrCobrado') {
            return $this->normalizeZeroValue($value);
        }

        // Default: lowercase + trim
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Normaliza identificadores: elimina puntos, guiones, espacios.
     */
    private function normalizeIdentifier(string $value): string
    {
        return preg_replace('/[\.\-\s]/', '', $value);
    }

    /**
     * Normaliza fechas simples o listas de fechas a formato canónico.
     */
    private function normalizeDateValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $dates = $this->splitListValue($value);
        if (count($dates) === 1) {
            return $this->normalizeDate($value);
        }

        $normalized = [];
        foreach ($dates as $date) {
            if ($date === '') {
                continue;
            }

            $normalized[] = $this->normalizeDate($date);
        }

        return implode(', ', $normalized);
    }

    /**
     * Normaliza listas de valores exactos donde el separador no es semántico.
     */
    private function normalizeListValue(string $value): string
    {
        $parts = $this->splitListValue($value);
        if (count($parts) === 1) {
            return mb_strtolower(trim($value), 'UTF-8');
        }

        return mb_strtolower(implode(', ', $parts), 'UTF-8');
    }

    /**
     * Divide listas usando separadores visibles entre valores, no dentro de fechas.
     */
    private function splitListValue(string $value): array
    {
        $parts = preg_split('/\s*(?:,|;|\s\/\s)\s*/', trim($value));
        if ($parts === false) {
            return [trim($value)];
        }

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn(string $part): bool => $part !== ''
        ));
    }

    /**
     * Normaliza una fecha individual a formato Y-m-d.
     */
    private function normalizeDate(string $value): string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd/m/y'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, trim($value));
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return trim($value);
    }

    /**
     * Normaliza valores cero: $0, 0.00, .00 → "0"
     */
    private function normalizeZeroValue(string $value): string
    {
        $clean = preg_replace('/[^\d\.]/', '', $value);
        if (is_numeric($clean) && (float)$clean === 0.0) {
            return "0";
        }
        return $value;
    }

    private function parseQuantity(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // sum comma-separated quantities; reject if value looks like descriptive text
        if (str_contains($value, ',') && !preg_match('/[a-zA-Z]{3,}/', $value)) {
            $parts = array_map('trim', explode(',', $value));
            $total = 0;
            $validParts = 0;

            foreach ($parts as $part) {
                $clean = preg_replace('/[^\d]/', '', $part);
                if ($clean !== '') {
                    $total += (int) $clean;
                    $validParts++;
                }
            }

            return $validParts > 0 ? $total : null;
        }

        if (preg_match_all('/\b(\d+)\b/', $value, $matches)) {
            $numbers = $matches[1];

            // reject posology like "1 TABLETA CADA 12 HORAS" to avoid math artifacts
            if (count($numbers) > 1 && preg_match('/[a-zA-Z]/', $value)) {
                return null;
            }

            if (count($numbers) > 0) {
                return (int) $numbers[0];
            }
        }

        if (!preg_match('/[a-zA-Z]{3,}/', $value)) {
            $clean = preg_replace('/[^\d]/', '', $value);
            if ($clean !== '') {
                return (int) $clean;
            }
        }

        return null;
    }

    private function initializeMetrics(): array
    {
        return [
            'TotalCamposEvaluados' => 0,
            'TotalCoincidentes' => 0,
            'TotalDiscrepancias' => 0,
            'Altas' => 0,
            'Medias' => 0,
            'Bajas' => 0,
        ];
    }

    private function indexSemanticResults(array $semanticResults): array
    {
        $semanticMap = [];

        foreach ($semanticResults as $semanticResult) {
            if (!isset($semanticResult['field'])) {
                continue;
            }

            $semanticMap[$this->buildDocumentFieldKey(
                (string) $semanticResult['field'],
                isset($semanticResult['document']) ? (string) $semanticResult['document'] : null
            )] = $semanticResult;
        }

        return $semanticMap;
    }

    private function getFdvValue(string $field, array $fdvItems, FieldClassifier $classifier): ?string
    {
        $column = $classifier->getSqlColumn($field);
        $isMultiRow = isset($fdvItems[0]) && is_array($fdvItems[0]);

        // ── Per-item field: agregar valores de todas las rows ──
        if ($isMultiRow && count($fdvItems) > 1 && in_array($field, self::PER_ITEM_FIELDS, true)) {
            $values = [];
            foreach ($fdvItems as $row) {
                $val = $this->extractRowValue($row, $field, $column);
                if ($val !== null) {
                    $values[] = $val;
                }
            }

            if (empty($values)) {
                return null;
            }

            // Si todos los valores son idénticos, devolver uno solo
            $unique = array_unique($values);
            if (count($unique) === 1) {
                return $unique[0];
            }

            return implode(', ', $values);
        }

        $row = $isMultiRow ? ($fdvItems[0] ?? null) : $fdvItems;
        if ($row === null) {
            return null;
        }

        return $this->extractRowValue($row, $field, $column);
    }

    private function extractRowValue(array $row, string $field, ?string $column): ?string
    {
        // Buscar por nombre directo del campo
        if (isset($row[$field]) && $this->isNonEmpty($row[$field])) {
            return (string) $row[$field];
        }

        // Buscar por mapeo de columna SQL
        if ($column !== null && $column !== $field && isset($row[$column]) && $this->isNonEmpty($row[$column])) {
            return (string) $row[$column];
        }

        return null;
    }

    private function isNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        return true;
    }

    private function getDocValue(
        string $field,
        array $docFieldsMap,
        FieldClassifier $classifier,
        ?string $preferredDocument = null
    ): ?string
    {
        $preferredValue = $this->getDocumentFieldValue($docFieldsMap, $preferredDocument, $field);
        if ($preferredValue !== null) {
            return $preferredValue;
        }

        // Cuando el config especifica un documento, no buscar en otros docs —
        // un campo ausente en el documento requerido es un hallazgo (NO_ENCONTRADO).
        if ($preferredDocument !== null) {
            return null;
        }

        $authoritativeValue = $this->getDocumentFieldValue($docFieldsMap, $classifier->getAuthoritativeDoc($field), $field);
        if ($authoritativeValue !== null) {
            return $authoritativeValue;
        }

        foreach ($classifier->getAlternativeDocs($field) as $altDoc) {
            $alternativeValue = $this->getDocumentFieldValue($docFieldsMap, $altDoc, $field);
            if ($alternativeValue !== null) {
                return $alternativeValue;
            }
        }

        foreach ($docFieldsMap as $fields) {
            $fallbackValue = $this->normalizeDocumentFieldValue($fields[$field] ?? null);
            if ($fallbackValue !== null) {
                return $fallbackValue;
            }
        }

        return null;
    }

    private function getDocumentFieldValue(array $docFieldsMap, ?string $document, string $field): ?string
    {
        if ($document === null) {
            return null;
        }

        return $this->normalizeDocumentFieldValue($docFieldsMap[$document][$field] ?? null);
    }

    private function normalizeDocumentFieldValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function indexExtractedFields(array $extractedDocs): array
    {
        $map = [];
        foreach ($extractedDocs as $doc) {
            $rawType = $doc['type'] ?? 'UNKNOWN';
            $type = ExtractionResponseSchema::normalizeDocType($rawType);
            $fields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];
            $map[$type] = array_merge($map[$type] ?? [], $fields);
        }
        return $map;
    }

    private function resolveFields(array $auditConfig, FieldClassifier $classifier): array
    {
        $fields = [];
        $seen = [];
        $documents = $auditConfig['documents'] ?? [];

        foreach ($documents as $docName => $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $documentType = is_string($docName)
                ? ExtractionResponseSchema::normalizeDocType($docName)
                : null;

            $docFields = $doc['fields'] ?? [];
            foreach ($docFields as $field) {
                $fieldName = $this->extractConfiguredFieldName($field);
                if ($fieldName === null) {
                    continue;
                }

                $this->appendConfiguredField($fields, $seen, $fieldName, $documentType, $field, $classifier);
            }

            $visualChecks = $doc['visualChecks'] ?? [];
            foreach ($visualChecks as $check) {
                $fieldName = $this->extractConfiguredVisualCheckName($check);
                if ($fieldName === null) {
                    continue;
                }

                $this->appendConfiguredField($fields, $seen, $fieldName, $documentType, $check, $classifier);
            }
        }

        if (empty($fields)) {
            foreach ($classifier->getAllFields() as $name => $meta) {
                $fields[] = ['field' => $name];
            }
        }

        return $fields;
    }

    private function appendConfiguredField(
        array &$fields,
        array &$seen,
        string $fieldName,
        ?string $documentType,
        mixed $fieldConfig,
        FieldClassifier $classifier
    ): void {
        $fieldName = $classifier->normalizeField($fieldName);
        $key = $this->buildDocumentFieldKey($fieldName, $documentType);

        if (isset($seen[$key])) {
            return;
        }

        $fields[] = [
            'field' => $fieldName,
            'document' => $documentType,
            'severity' => $this->resolveConfiguredSeverity($fieldConfig, $classifier->getSeverity($fieldName)),
        ];
        $seen[$key] = true;
    }

    private function extractConfiguredFieldName(mixed $field): ?string
    {
        return $this->extractConfiguredName($field, ['field', 'name', 'campoNombre']);
    }

    private function extractConfiguredVisualCheckName(mixed $check): ?string
    {
        return $this->extractConfiguredName($check, ['check', 'field', 'name']);
    }

    private function extractConfiguredName(mixed $config, array $nameKeys): ?string
    {
        if (is_string($config)) {
            $name = trim($config);
            return $name !== '' ? $name : null;
        }

        if (!is_array($config)) {
            return null;
        }

        foreach ($nameKeys as $key) {
            $name = $config[$key] ?? null;
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    private function resolveConfiguredSeverity(mixed $config, string $fallback): string
    {
        if (!is_array($config)) {
            return $fallback;
        }

        $raw = $config['severity'] ?? $config['SeveridadOverride'] ?? $config['severidad'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return $fallback;
        }

        return $this->normalizeSeverity($raw) ?? $fallback;
    }

    private function normalizeSeverity(string $severity): ?string
    {
        $normalized = strtoupper(trim($severity));

        return match ($normalized) {
            'ALTA', 'HIGH', 'CRITICO', 'CRITICA', 'CRITICAL' => FieldClassifier::SEVERITY_HIGH,
            'MEDIA', 'MEDIUM', 'MODERADO', 'MODERADA' => FieldClassifier::SEVERITY_MEDIUM,
            'BAJA', 'LOW', 'MENOR', 'MINOR' => FieldClassifier::SEVERITY_LOW,
            default => null,
        };
    }

    private function summarizeAuditConfig(array $auditConfig): array
    {
        $documents = is_array($auditConfig['documents'] ?? null) ? $auditConfig['documents'] : [];
        $fieldCount = 0;
        $visualCheckCount = 0;
        $documentNames = [];

        foreach ($documents as $docName => $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $documentNames[] = is_string($docName) ? $docName : (string) ($doc['name'] ?? '');
            $fieldCount += count(is_array($doc['fields'] ?? null) ? $doc['fields'] : []);
            $visualCheckCount += count(is_array($doc['visualChecks'] ?? null) ? $doc['visualChecks'] : []);
        }

        $encoded = json_encode($auditConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'source' => empty($documents) ? 'classifier_defaults' : 'client_audit_config',
            'nitSec' => isset($auditConfig['nitSec']) ? (string) $auditConfig['nitSec'] : null,
            'documents' => array_values(array_filter($documentNames, static fn(string $name): bool => trim($name) !== '')),
            'document_count' => count($documents),
            'field_count' => $fieldCount,
            'visual_check_count' => $visualCheckCount,
            'config_hash' => is_string($encoded) ? hash('sha256', $encoded) : null,
        ];
    }

    private function buildConfigUsed(array $metrics, array $auditConfig): array
    {
        return [
            'weights' => [
                'alta' => self::WEIGHT_HIGH,
                'media' => self::WEIGHT_MEDIUM,
                'baja' => self::WEIGHT_LOW,
            ],
            'thresholds' => [
                'warning' => self::THRESHOLD_WARNING,
                'error' => self::THRESHOLD_ERROR,
            ],
            'max_score' => $metrics['TotalCamposEvaluados'] * self::WEIGHT_HIGH,
            'audit_config' => $this->summarizeAuditConfig($auditConfig),
        ];
    }

    private function updateMetrics(array &$metrics, string $classification, string $severity): void
    {
        $metrics['TotalCamposEvaluados']++;

        if ($classification === self::MATCH || $classification === self::SKIPPED) {
            $metrics['TotalCoincidentes']++;
            return;
        }

        if (!$this->isDiscrepancyClassification($classification)) {
            return;
        }

        $metrics['TotalDiscrepancias']++;
        $this->incrementSeverityMetric($metrics, $severity);
    }

    private function calculateRiskScore(array $metrics): int
    {
        return ($metrics['Altas'] * self::WEIGHT_HIGH)
             + ($metrics['Medias'] * self::WEIGHT_MEDIUM)
             + ($metrics['Bajas'] * self::WEIGHT_LOW);
    }

    private function isDiscrepancyClassification(string $classification): bool
    {
        return in_array($classification, [self::MISMATCH, self::NOT_FOUND], true);
    }

    private function incrementSeverityMetric(array &$metrics, string $severity): void
    {
        match ($severity) {
            FieldClassifier::SEVERITY_HIGH => $metrics['Altas']++,
            FieldClassifier::SEVERITY_MEDIUM => $metrics['Medias']++,
            FieldClassifier::SEVERITY_LOW => $metrics['Bajas']++,
            default => null,
        };
    }

    private function classifyResponse(int $riskScore): string
    {
        if ($riskScore === 0) {
            return AuditResponseSchema::RESPONSE_SUCCESS;
        }
        if ($riskScore < self::THRESHOLD_WARNING) {
            return AuditResponseSchema::RESPONSE_WARNING;
        }
        return AuditResponseSchema::RESPONSE_ERROR;
    }

    private function getOverallSeverity(array $metrics): string
    {
        if ($metrics['Altas'] > 0) {
            return FieldClassifier::SEVERITY_HIGH;
        }
        if ($metrics['Medias'] > 0) {
            return FieldClassifier::SEVERITY_MEDIUM;
        }
        if ($metrics['Bajas'] > 0) {
            return FieldClassifier::SEVERITY_LOW;
        }
        return FieldClassifier::SEVERITY_LOW;
    }

    private function buildMessage(string $response, array $metrics, int $riskScore): string
    {
        if ($response === AuditResponseSchema::RESPONSE_SUCCESS) {
            return sprintf(
                'Auditoría completada: %d campos evaluados, todos coinciden (risk score: %d)',
                $metrics['TotalCamposEvaluados'],
                $riskScore
            );
        }

        return sprintf(
            'Auditoría completada: %d campos evaluados, %d discrepancias (%d alta, %d media, %d baja). Risk score: %d',
            $metrics['TotalCamposEvaluados'],
            $metrics['TotalDiscrepancias'],
            $metrics['Altas'],
            $metrics['Medias'],
            $metrics['Bajas'],
            $riskScore
        );
    }
}
