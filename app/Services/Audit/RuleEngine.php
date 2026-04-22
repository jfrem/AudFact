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

    /**
     * Evalúa documentos extraídos contra la Fuente de Verdad y produce el resultado final.
     *
     * @param  array<int, array<string, mixed>>|array<string, mixed> $fdvItems  Datos de dispensación.
     * @param  array<int, array<string, mixed>> $extractedDocs  Documentos y campos extraídos por Gemini.
     * @param  array<string, mixed> $visualChecks  Verificaciones visuales extraídas.
     * @param  array<int, array<string, mixed>> $semanticResults  Resultados de comparación semántica.
     * @param  array<string, mixed> $auditConfig  Configuración dinámica aplicada.
     * @param  FieldClassifier $classifier  Clasificador de campos.
     * @return array<string, mixed> Respuesta final con hallazgos, métricas, riesgo y configuración usada.
     */
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

        $docFieldsMap = $this->indexExtractedFields($extractedDocs);
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

    /**
     * Evalúa un campo individual delegando según su tipo de comparación.
     *
     * @param  string $field  Campo canónico.
     * @param  string $type  Tipo de evaluación del campo.
     * @param  string|null $fdvValue  Valor en Fuente de Verdad.
     * @param  string|null $docValue  Valor extraído del documento.
     * @param  array<string, array<string, mixed>> $semanticMap  Resultados semánticos indexados.
     * @param  array<string, mixed> $visualChecks  Checks visuales indexados.
     * @param  string|null $document  Documento configurado para el campo.
     * @return array<string, mixed> Clasificación y detalle opcional.
     */
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

        if ($docValue === null || trim($docValue) === '') {
            if ($fdvValue === null || trim($fdvValue) === '') {
                return ['classification' => self::SKIPPED, 'detail' => 'Ambos valores vacíos'];
            }
            return ['classification' => self::NOT_FOUND, 'detail' => 'No encontrado en documento'];
        }

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

    /**
     * Compara valores exactos después de normalizarlos según el campo.
     *
     * @param  string $field  Campo evaluado.
     * @param  string $fdvValue  Valor de Fuente de Verdad.
     * @param  string $docValue  Valor documental.
     * @return array<string, mixed> Resultado de coincidencia o discrepancia.
     */
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

    /**
     * Evalúa un campo semántico usando un resultado previamente calculado por embeddings.
     *
     * @param  string $field  Campo semántico.
     * @param  array<string, array<string, mixed>> $semanticMap  Resultados indexados por documento/campo.
     * @param  string|null $document  Documento esperado.
     * @return array<string, mixed> Resultado de similitud semántica.
     */
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

    /**
     * Evalúa un check visual reportado por Gemini.
     *
     * @param  string $field  Check visual canónico.
     * @param  array<string, mixed> $visualChecks  Checks visuales extraídos.
     * @param  string|null $document  Documento esperado.
     * @return array<string, mixed> Resultado visual.
     */
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
     * Construye la llave interna para valores dependientes de documento y campo.
     *
     * @param  string $field  Campo canónico.
     * @param  string|null $document  Documento canónico o null para fallback global.
     * @return string Llave compuesta.
     */
    private function buildDocumentFieldKey(string $field, ?string $document): string
    {
        return ($document ?? self::ANY_DOCUMENT_KEY) . self::DOCUMENT_FIELD_KEY_SEPARATOR . $field;
    }

    /**
     * Evalúa campos con reglas de negocio específicas.
     *
     * @param  string $field  Campo evaluado.
     * @param  string $fdvValue  Valor FDV.
     * @param  string $docValue  Valor documental.
     * @return array<string, mixed> Resultado de regla de negocio.
     */
    private function evaluateBusiness(string $field, string $fdvValue, string $docValue): array
    {
        return match ($field) {
            'CantidadEntregada', 'CantidadPrescrita' => $this->evaluateQuantities($fdvValue, $docValue),
            'Cliente.Regimen' => $this->evaluateRegimen($fdvValue, $docValue),
            default => $this->evaluateExact($field, $fdvValue, $docValue),
        };
    }

    /**
     * Evalúa cantidades permitiendo entregas parciales y una tolerancia menor de exceso.
     *
     * @param  string $fdvValue  Cantidad registrada en FDV.
     * @param  string $docValue  Cantidad observada en documento.
     * @return array<string, mixed> Resultado de comparación de cantidades.
     */
    private function evaluateQuantities(string $fdvValue, string $docValue): array
    {
        $fdvQty = $this->parseQuantity($fdvValue);
        $docQty = $this->parseQuantity($docValue);

        if ($fdvQty === null || $docQty === null) {
            return $this->evaluateExact('cantidad', $fdvValue, $docValue);
        }

        if ($fdvQty === $docQty) {
            return ['classification' => self::MATCH];
        }

        if ($docQty <= $fdvQty) {
            return [
                'classification' => self::MATCH,
                'detail' => "Entrega parcial: {$docQty}/{$fdvQty}",
            ];
        }

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

    /**
     * Evalúa régimen del cliente con exclusiones y equivalencias de negocio.
     *
     * @param  string $fdvValue  Régimen de Fuente de Verdad.
     * @param  string $docValue  Régimen extraído del documento.
     * @return array<string, mixed> Resultado de comparación de régimen.
     */
    private function evaluateRegimen(string $fdvValue, string $docValue): array
    {
        $normalizedFdv = strtoupper(trim($fdvValue));

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

    /**
     * Normaliza un valor para comparación exacta según reglas del campo.
     *
     * @param  string $field  Campo evaluado.
     * @param  string $value  Valor original.
     * @return string Valor normalizado.
     */
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

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Normaliza identificadores removiendo separadores comunes.
     *
     * @param  string $value  Identificador original.
     * @return string Identificador compacto.
     */
    private function normalizeIdentifier(string $value): string
    {
        return preg_replace('/[\.\-\s]/', '', $value);
    }

    /**
     * Normaliza una fecha o lista de fechas a formato comparable.
     *
     * @param  string $value  Valor de fecha original.
     * @return string Fecha normalizada o valor original si no parsea.
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
     * Normaliza listas textuales conservando orden de componentes.
     *
     * @param  string $value  Valor potencialmente separado por coma, punto y coma o slash.
     * @return string Lista normalizada en minúscula.
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
     * Divide valores compuestos por separadores controlados.
     *
     * @param  string $value  Valor a dividir.
     * @return array<int, string> Partes limpias no vacías.
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
     * Convierte fechas conocidas al formato ISO Y-m-d.
     *
     * @param  string $value  Fecha textual.
     * @return string Fecha ISO o valor limpio original si no coincide con formatos conocidos.
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
     * Normaliza valores monetarios cero ignorando símbolos y separadores.
     *
     * @param  string $value  Valor monetario original.
     * @return string "0" para ceros detectados, o valor original.
     */
    private function normalizeZeroValue(string $value): string
    {
        $clean = preg_replace('/[^\d\.]/', '', $value);
        if (is_numeric($clean) && (float)$clean === 0.0) {
            return "0";
        }
        return $value;
    }

    /**
     * Extrae una cantidad entera evitando interpretar posologías como cantidades auditables.
     *
     * @param  string $value  Texto con cantidad o descripción.
     * @return int|null Cantidad parseada, o null si no es confiable.
     */
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

    /**
     * Inicializa contadores de métricas para una evaluación.
     *
     * @return array<string, int> Métricas en cero.
     */
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

    /**
     * Indexa resultados semánticos por llave documento/campo para acceso O(1).
     *
     * @param  array<int, array<string, mixed>> $semanticResults  Resultados de SemanticComparator.
     * @return array<string, array<string, mixed>> Resultados indexados.
     */
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

    /**
     * Obtiene un valor FDV para campo simple o per-item.
     *
     * @param  string $field  Campo canónico.
     * @param  array<int, array<string, mixed>>|array<string, mixed> $fdvItems  Filas FDV.
     * @param  FieldClassifier $classifier  Clasificador para resolver columna SQL.
     * @return string|null Valor FDV encontrado.
     */
    private function getFdvValue(string $field, array $fdvItems, FieldClassifier $classifier): ?string
    {
        $column = $classifier->getSqlColumn($field);
        $isMultiRow = isset($fdvItems[0]) && is_array($fdvItems[0]);

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

    /**
     * Extrae un valor no vacío desde una fila FDV.
     *
     * @param  array<string, mixed> $row  Fila FDV.
     * @param  string $field  Campo canónico.
     * @param  string|null $column  Columna SQL alternativa.
     * @return string|null Valor como string, o null si está ausente.
     */
    private function extractRowValue(array $row, string $field, ?string $column): ?string
    {
        if (isset($row[$field]) && $this->isNonEmpty($row[$field])) {
            return (string) $row[$field];
        }

        if ($column !== null && $column !== $field && isset($row[$column]) && $this->isNonEmpty($row[$column])) {
            return (string) $row[$column];
        }

        return null;
    }

    /**
     * Determina si un valor FDV/documental debe considerarse presente.
     *
     * @param  mixed $value  Valor a evaluar.
     * @return bool True si no es null ni string vacío.
     */
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

    /**
     * Resuelve el valor documental priorizando documento configurado, autoritativo y alternativos.
     *
     * @param  string $field  Campo canónico.
     * @param  array<string, array<string, mixed>> $docFieldsMap  Campos extraídos por documento.
     * @param  FieldClassifier $classifier  Clasificador para prioridades documentales.
     * @param  string|null $preferredDocument  Documento definido por audit-config.
     * @return string|null Valor documental encontrado.
     */
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

    /**
     * Obtiene un campo desde un documento específico ya indexado.
     *
     * @param  array<string, array<string, mixed>> $docFieldsMap  Campos por documento.
     * @param  string|null $document  Documento a consultar.
     * @param  string $field  Campo canónico.
     * @return string|null Valor documental no vacío.
     */
    private function getDocumentFieldValue(array $docFieldsMap, ?string $document, string $field): ?string
    {
        if ($document === null) {
            return null;
        }

        return $this->normalizeDocumentFieldValue($docFieldsMap[$document][$field] ?? null);
    }

    /**
     * Normaliza un valor extraído de documento a string utilizable.
     *
     * @param  mixed $value  Valor retornado por Gemini.
     * @return string|null Valor string no vacío.
     */
    private function normalizeDocumentFieldValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Agrupa campos extraídos por tipo documental canónico.
     *
     * @param  array<int, array<string, mixed>> $extractedDocs  Documentos devueltos por la extracción.
     * @return array<string, array<string, mixed>> Campos indexados por documento.
     */
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

    /**
     * Resuelve los campos a evaluar desde audit-config o desde el catálogo default.
     *
     * @param  array<string, mixed> $auditConfig  Configuración dinámica del cliente.
     * @param  FieldClassifier $classifier  Clasificador de campos.
     * @return array<int, array<string, mixed>> Campos con documento y severidad.
     */
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

    /**
     * Agrega un campo configurado evitando duplicados por documento/campo.
     *
     * @param  array<int, array<string, mixed>> $fields  Lista acumulada de campos.
     * @param  array<string, bool> $seen  Índice de campos ya agregados.
     * @param  string $fieldName  Nombre de campo recibido desde configuración.
     * @param  string|null $documentType  Documento canónico asociado.
     * @param  mixed $fieldConfig  Configuración original del campo o check.
     * @param  FieldClassifier $classifier  Clasificador para normalizar y resolver severidad.
     * @return void
     */
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

    /**
     * Extrae el nombre de campo desde configuración dinámica.
     *
     * @param  mixed $field  Entrada simple o estructurada.
     * @return string|null Nombre de campo.
     */
    private function extractConfiguredFieldName(mixed $field): ?string
    {
        return $this->extractConfiguredName($field, ['field', 'name', 'campoNombre']);
    }

    /**
     * Extrae el nombre de check visual desde configuración dinámica.
     *
     * @param  mixed $check  Entrada simple o estructurada.
     * @return string|null Nombre de check.
     */
    private function extractConfiguredVisualCheckName(mixed $check): ?string
    {
        return $this->extractConfiguredName($check, ['check', 'field', 'name']);
    }

    /**
     * Lee un nombre desde una entrada de configuración usando llaves candidatas.
     *
     * @param  mixed $config  String o arreglo de configuración.
     * @param  array<int, string> $nameKeys  Llaves aceptadas para extraer nombre.
     * @return string|null Nombre limpio.
     */
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

    /**
     * Resuelve override de severidad desde configuración, con fallback del clasificador.
     *
     * @param  mixed $config  Configuración del campo.
     * @param  string $fallback  Severidad por defecto.
     * @return string Severidad normalizada.
     */
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

    /**
     * Normaliza nombres de severidad en español/inglés al catálogo interno.
     *
     * @param  string $severity  Severidad textual.
     * @return string|null Severidad interna o null si no es reconocida.
     */
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

    /**
     * Resume la configuración de auditoría aplicada sin exponer todo el payload.
     *
     * @param  array<string, mixed> $auditConfig  Configuración dinámica aplicada.
     * @return array<string, mixed> Resumen con origen, conteos y hash.
     */
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

    /**
     * Construye el bloque config_used que acompaña la respuesta final.
     *
     * @param  array<string, int> $metrics  Métricas finales de evaluación.
     * @param  array<string, mixed> $auditConfig  Configuración dinámica aplicada.
     * @return array<string, mixed> Pesos, umbrales, score máximo y resumen de config.
     */
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

    /**
     * Actualiza métricas acumuladas con la clasificación de un campo.
     *
     * @param  array<string, int> $metrics  Métricas acumuladas por referencia.
     * @param  string $classification  Clasificación del campo.
     * @param  string $severity  Severidad del campo.
     * @return void
     */
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

    /**
     * Calcula el puntaje de riesgo ponderando discrepancias por severidad.
     *
     * @param  array<string, int> $metrics  Métricas finales.
     * @return int Puntaje de riesgo.
     */
    private function calculateRiskScore(array $metrics): int
    {
        return ($metrics['Altas'] * self::WEIGHT_HIGH)
             + ($metrics['Medias'] * self::WEIGHT_MEDIUM)
             + ($metrics['Bajas'] * self::WEIGHT_LOW);
    }

    /**
     * Determina si una clasificación debe contar como discrepancia.
     *
     * @param  string $classification  Clasificación del campo.
     * @return bool True si incrementa TotalDiscrepancias.
     */
    private function isDiscrepancyClassification(string $classification): bool
    {
        return in_array($classification, [self::MISMATCH, self::NOT_FOUND], true);
    }

    /**
     * Incrementa el contador de severidad correspondiente.
     *
     * @param  array<string, int> $metrics  Métricas acumuladas por referencia.
     * @param  string $severity  Severidad normalizada.
     * @return void
     */
    private function incrementSeverityMetric(array &$metrics, string $severity): void
    {
        match ($severity) {
            FieldClassifier::SEVERITY_HIGH => $metrics['Altas']++,
            FieldClassifier::SEVERITY_MEDIUM => $metrics['Medias']++,
            FieldClassifier::SEVERITY_LOW => $metrics['Bajas']++,
            default => null,
        };
    }

    /**
     * Clasifica la respuesta final a partir del puntaje de riesgo.
     *
     * @param  int $riskScore  Puntaje calculado.
     * @return string Estado final: success, warning o error.
     */
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

    /**
     * Determina la severidad global más alta presente en las métricas.
     *
     * @param  array<string, int> $metrics  Métricas finales.
     * @return string Severidad global.
     */
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

    /**
     * Construye el mensaje resumido de auditoría para la respuesta final.
     *
     * @param  string $response  Estado final clasificado.
     * @param  array<string, int> $metrics  Métricas finales.
     * @param  int $riskScore  Puntaje de riesgo.
     * @return string Mensaje de salida.
     */
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
