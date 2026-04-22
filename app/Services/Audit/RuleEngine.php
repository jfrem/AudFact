<?php

namespace App\Services\Audit;

use Core\Logger;

/**
 * Motor de reglas determinista para auditoría (Fase 3).
 *
 * Evalúa campos extraídos (Fase 1) y similitudes semánticas (Fase 2)
 * contra la Fuente de Verdad (BD) usando lógica PHP pura.
 *
 * Implementación en código PHP de las reglas de auditoría.
 * Garantiza 100% reproducibilidad entre corridas.
 *
 * @version 4.0
 */
class RuleEngine
{
    // ── Clasificaciones de resultado ──
    public const MATCH = 'COINCIDE';
    public const MISMATCH = 'VALOR_DISTINTO';
    public const NOT_FOUND = 'NO_ENCONTRADO';
    public const NOT_APPLICABLE = 'NO_APLICA';
    public const SKIPPED = 'OMITIDO';

    // ── Risk score weights (§07) ──
    private const WEIGHT_HIGH = 10;
    private const WEIGHT_MEDIUM = 5;
    private const WEIGHT_LOW = 1;

    // ── Risk thresholds ──
    private const THRESHOLD_WARNING = 15;
    private const THRESHOLD_ERROR = 30;

    // ── Quantity tolerance (§05 excepción POSITIVA) ──
    private const QUANTITY_EXCESS_TOLERANCE = 5;

    // ── Regímenes que omiten validación (§ Exclusión de Régimen) ──
    private const REGIME_SKIP_VALUES = ['N/D', 'ARL', 'ND', ''];

    // ── Campos per-item: varían por línea de dispensación (multi-row SQL) ──
    // Para estos campos, getFdvValue concatena valores de TODAS las rows.
    public const PER_ITEM_FIELDS = [
        'CantidadEntregada',
        'CantidadPrescrita',
        'Lote',
        'NombreArticulo',
        'CUM',
        'Laboratorio',
        'CodigoArticulo',
        'CodigoProducto',
    ];

    /**
     * Evalúa una dispensación completa y genera el resultado de auditoría.
     *
     * @param array $fdvItems Items de la Fuente de Verdad (dispensación BD)
     * @param array $extractedDocs Documentos extraídos (Fase 1)
     * @param array $visualChecks Verificaciones visuales (Fase 1)
     * @param array $semanticResults Resultados de embedding (Fase 2)
     * @param array $auditConfig Configuración del cliente
     * @param FieldClassifier $classifier Clasificador de campos
     * @return array Resultado compatible con AuditResponseSchema
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
        $metrics = [
            'TotalCamposEvaluados' => 0,
            'TotalCoincidentes' => 0,
            'TotalDiscrepancias' => 0,
            'Altas' => 0,
            'Medias' => 0,
            'Bajas' => 0,
        ];

        // Indexar semantic results por campo
        $semanticMap = [];
        foreach ($semanticResults as $sr) {
            $semanticMap[$sr['field']] = $sr;
        }

        // Indexar extracted fields por tipo de documento
        $docFieldsMap = $this->indexExtractedFields($extractedDocs);

        // Evaluar cada campo configurado
        $fieldsToEvaluate = $this->resolveFields($auditConfig, $classifier);

        foreach ($fieldsToEvaluate as $fieldConfig) {
            $field = $fieldConfig['field'];
            $severity = $classifier->getSeverity($field);
            $type = $classifier->classify($field);

            // Obtener valores
            $fdvValue = $this->getFdvValue($field, $fdvItems);
            $docValue = $this->getDocValue($field, $docFieldsMap, $classifier);

            $result = $this->evaluateField(
                $field,
                $type,
                $fdvValue,
                $docValue,
                $semanticMap,
                $visualChecks
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
                'documento' => $classifier->getAuthoritativeDoc($field),
            ];

            if ($detail !== null) {
                $item['detalle'] = $detail;
            }

            if (isset($result['similarity'])) {
                $item['similarity'] = $result['similarity'];
            }

            $items[] = $item;

            // Métricas
            $metrics['TotalCamposEvaluados']++;
            if ($classification === self::MATCH || $classification === self::SKIPPED) {
                $metrics['TotalCoincidentes']++;
            } elseif ($classification === self::MISMATCH) {
                $metrics['TotalDiscrepancias']++;
                match ($severity) {
                    FieldClassifier::SEVERITY_HIGH => $metrics['Altas']++,
                    FieldClassifier::SEVERITY_MEDIUM => $metrics['Medias']++,
                    FieldClassifier::SEVERITY_LOW => $metrics['Bajas']++,
                    default => null,
                };
            }
        }

        // Risk score (§07)
        $riskScore = $this->calculateRiskScore($metrics);
        $response = $this->classifyResponse($riskScore);

        // Config used
        $configUsed = [
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
        ];

        return [
            'response' => $response,
            'severity' => $this->getOverallSeverity($metrics),
            'message' => $this->buildMessage($response, $metrics, $riskScore),
            'documento' => AuditResponseSchema::DOCUMENTO_MULTIPLE,
            'data' => ['items' => $items],
            'metrics' => $metrics,
            'risk_score' => $riskScore,
            'config_used' => $configUsed,
        ];
    }

    // ── Evaluación por campo ──

    private function evaluateField(
        string $field,
        string $type,
        ?string $fdvValue,
        ?string $docValue,
        array $semanticMap,
        array $visualChecks
    ): array {
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
            FieldClassifier::TYPE_SEMANTIC => $this->evaluateSemantic($field, $semanticMap),
            FieldClassifier::TYPE_VISUAL => $this->evaluateVisual($field, $visualChecks),
            FieldClassifier::TYPE_BUSINESS => $this->evaluateBusiness($field, $fdvValue, $docValue),
            default => $this->evaluateExact($field, $fdvValue, $docValue),
        };
    }

    // ── Comparación exacta (§03) ──

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

    // ── Comparación semántica (Fase 2) ──

    private function evaluateSemantic(string $field, array $semanticMap): array
    {
        $sr = $semanticMap[$field] ?? null;
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

    // ── Verificación visual (Fase 1) ──

    private function evaluateVisual(string $field, array $visualChecks): array
    {
        $check = $visualChecks[$field] ?? null;
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

    // ── Reglas de negocio (§05) ──

    private function evaluateBusiness(string $field, string $fdvValue, string $docValue): array
    {
        return match ($field) {
            'CantidadEntregada', 'CantidadPrescrita' => $this->evaluateQuantities($fdvValue, $docValue),
            'Cliente.Regimen' => $this->evaluateRegimen($fdvValue, $docValue),
            default => $this->evaluateExact($field, $fdvValue, $docValue),
        };
    }

    /**
     * Evalúa cantidades (§05).
     * - entregada ≤ prescrita → OK (entrega parcial permitida)
     * - exceso ≤ 5 → OK (excepción POSITIVA)
     * - exceso > 5 → discrepancia
     */
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

    /**
     * Evalúa régimen (§03/§05 Exclusión).
     * - ARL/ND/N/D → skip
     * - S ≠ C → discrepancia alta
     */
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

    // ── Normalización (§03) ──

    private function normalizeForComparison(string $field, string $value): string
    {
        $value = trim($value);

        // Normalización por tipo de campo
        if (in_array($field, ['NumeroIdentificacion', 'NumeroFactura', 'NumeroFormula', 'Autorizacion'], true)) {
            return $this->normalizeIdentifier($value);
        }

        if (str_contains($field, 'Fecha')) {
            return $this->normalizeDate($value);
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
     * Normaliza fechas a formato Y-m-d.
     */
    private function normalizeDate(string $value): string
    {
        // Intentar parsear diferentes formatos
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd/m/y'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, trim($value));
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        // Fallback: devolver limpio
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

    /**
     * Parsea un valor de cantidad. Soporta valores simples y delimitados
     * por coma para dispensaciones multi-item (ej: "20, 30" → [20, 30] → suma 50).
     *
     * @param string $value Valor crudo (ej: "20", "20, 30")
     * @return int|null Cantidad total o null si no parseable
     */
    private function parseQuantity(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Multi-valor: "20, 30" → sumar componentes (solo si no parece texto descriptivo)
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

        // Detectar si es posología (múltiples números separados por texto)
        // Ej: "1 TABLETA CADA 12 HORAS"
        if (preg_match_all('/\b(\d+)\b/', $value, $matches)) {
            $numbers = $matches[1];
            
            // Si hay múltiples números y también letras, probablemente es posología o texto complejo.
            // Rechazamos el parseo numérico para evitar "excesos matemáticos falsos".
            if (count($numbers) > 1 && preg_match('/[a-zA-Z]/', $value)) {
                return null; 
            }
            
            if (count($numbers) > 0) {
                return (int) $numbers[0];
            }
        }

        // Single value agresivo solo si no tiene letras que indiquen posología
        if (!preg_match('/[a-zA-Z]{3,}/', $value)) {
            $clean = preg_replace('/[^\d]/', '', $value);
            if ($clean !== '') {
                return (int) $clean;
            }
        }

        return null;
   }

    // ── Helpers para extracción de valores ──

    /**
     * Obtiene el valor de un campo desde la Fuente de Verdad (dispensationData).
     *
     * Para campos per-item (cantidades, lote, artículo, CUM, laboratorio),
     * concatena valores de TODAS las rows SQL separados por coma.
     * Para campos compartidos (paciente, médico, fechas), usa row[0].
     *
     * @param string $field Nombre del campo (FieldClassifier key)
     * @param array $fdvItems Dispensation data (array of SQL rows)
     * @return string|null Valor o null si no existe
     */
    private function getFdvValue(string $field, array $fdvItems): ?string
    {
        // Mapeo: FieldClassifier field name → SQL column alias de DispensationModel
        // Mantener sincronizado con app/Models/DispensationModel.php
        static $fieldToColumn = [
            // Exactos — alias SQL directo
            'NumeroFactura'        => 'NumeroFactura',
            'NumeroFormula'        => 'NumeroFormula',       // No existe en SQL, solo en docs
            'Autorizacion'         => 'NumeroAutorizacion',
            'TipoIdentificacion'   => 'TipoDocumentoPaciente',
            'NumeroIdentificacion' => 'DocumentoPaciente',
            'FechaFormula'         => 'FechaFormula',
            'FechaAutorizacion'    => 'FechaAutorizacion',
            'FechaEntrega'         => 'FechaEntrega',
            'VlrCobrado'           => 'VlrCobrado',
            'Mipres'               => 'Mipres',
            'IdPrincipal'          => 'IdPrincipal',
            'IdDirec'              => 'IdDirec',
            'IdProg'               => 'IdProg',
            'IdEntr'               => 'IdEntr',
            'IdRepEnt'             => 'IdRepEnt',
            'Lote'                 => 'Lote',

            // Semánticos
            'NombrePaciente'       => 'NombrePaciente',
            'NombreArticulo'       => 'NombreArticulo',
            'Medico'               => 'Medico',
            'Laboratorio'          => 'Laboratorio',
            'IPS'                  => 'IPS',
            'Cliente.Entidad'      => 'Cliente',
            'Cliente.Regimen'      => 'RegimenPaciente',

            // Visuales
            'FirmaActaEntrega'     => 'FirmaActaEntrega',
            'SelloRecepcion'       => null, // No existe en BD, solo verificación visual

            // Negocio
            'CantidadEntregada'    => 'CantidadEntregada',
            'CantidadPrescrita'    => 'CantidadPrescrita',
        ];

        $column = $fieldToColumn[$field] ?? $field;
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

        // ── Campo compartido: usar row[0] ──
        $row = $isMultiRow ? ($fdvItems[0] ?? null) : $fdvItems;
        if ($row === null) {
            return null;
        }

        return $this->extractRowValue($row, $field, $column);
    }

    /**
     * Extrae un valor de una row SQL individual.
     */
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

    /**
     * Verifica que un valor no sea vacío ni solo espacios.
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

    private function getDocValue(string $field, array $docFieldsMap, FieldClassifier $classifier): ?string
    {
        // Buscar en documento autoritativo primero
        $authDoc = $classifier->getAuthoritativeDoc($field);
        if (isset($docFieldsMap[$authDoc][$field])) {
            $val = $docFieldsMap[$authDoc][$field];
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }

        // Buscar en documentos alternativos
        foreach ($classifier->getAlternativeDocs($field) as $altDoc) {
            if (isset($docFieldsMap[$altDoc][$field])) {
                $val = $docFieldsMap[$altDoc][$field];
                if (is_string($val) && trim($val) !== '') {
                    return $val;
                }
            }
        }

        // Buscar en cualquier documento como último recurso
        foreach ($docFieldsMap as $fields) {
            if (isset($fields[$field])) {
                $val = $fields[$field];
                if (is_string($val) && trim($val) !== '') {
                    return $val;
                }
            }
        }

        return null;
    }

    private function indexExtractedFields(array $extractedDocs): array
    {
        $map = [];
        foreach ($extractedDocs as $doc) {
            $rawType = $doc['type'] ?? 'UNKNOWN';
            $type = ExtractionResponseSchema::normalizeDocType($rawType);
            $fields = $doc['fields'] ?? [];
            $map[$type] = array_merge($map[$type] ?? [], $fields);
        }
        return $map;
    }

    private function resolveFields(array $auditConfig, FieldClassifier $classifier): array
    {
        $fields = [];
        $documents = $auditConfig['documents'] ?? [];

        foreach ($documents as $doc) {
            $docFields = $doc['fields'] ?? [];
            foreach ($docFields as $field) {
                $fieldName = $field['field'] ?? $field['name'] ?? null;
                if ($fieldName !== null) {
                    $fields[] = ['field' => $fieldName];
                }
            }
        }

        if (empty($fields)) {
            foreach ($classifier->getAllFields() as $name => $meta) {
                $fields[] = ['field' => $name];
            }
        }

        return $fields;
    }

    // ── Risk Score y clasificación (§07) ──

    private function calculateRiskScore(array $metrics): int
    {
        return ($metrics['Altas'] * self::WEIGHT_HIGH)
             + ($metrics['Medias'] * self::WEIGHT_MEDIUM)
             + ($metrics['Bajas'] * self::WEIGHT_LOW);
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
