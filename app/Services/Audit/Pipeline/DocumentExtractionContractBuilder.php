<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use InvalidArgumentException;

final class DocumentExtractionContractBuilder
{
    public const FN_EXTRACT_FIELDS = 'extract_fields';
    public const FN_EXTRACT_ITEMS = 'extract_items';
    public const FN_DETECT_VISUAL_CHECKS = 'detect_visual_checks';
    public const FN_ASSESS_DOCUMENT_QUALITY = 'assess_document_quality';

    private const REQUIRED_FUNCTION_NAMES = [
        self::FN_EXTRACT_FIELDS,
        self::FN_EXTRACT_ITEMS,
        self::FN_DETECT_VISUAL_CHECKS,
        self::FN_ASSESS_DOCUMENT_QUALITY,
    ];

    private const ITEM_FIELD_NAMES = [
        'CantidadEntregada',
        'CantidadPrescrita',
        'CodigoArticulo',
        'CodigoProducto',
        'CUM',
        'FechaVencimiento',
        'Laboratorio',
        'Lote',
        'NombreArticulo',
    ];

    /**
     * Construye el contrato Gemini de extracción paralela para un documento.
     *
     * @param  string                    $documentName  Tipo documental configurado.
     * @param  array<int,array<string,mixed>> $fields   Campos activos del audit-config.
     * @param  array<int,array<string,mixed>> $visualChecks Checks visuales activos.
     * @return array<string,mixed>
     */
    public function build(string $documentName, array $fields, array $visualChecks): array
    {
        $fieldGroups = $this->groupFields($documentName, $fields);

        $declarations = [
            $this->buildExtractFieldsDeclaration($fieldGroups['fields']),
            $this->buildExtractItemsDeclaration($fieldGroups['items']),
            $this->buildDetectVisualChecksDeclaration($visualChecks),
            $this->buildAssessDocumentQualityDeclaration(),
        ];

        return [
            'function_declarations' => $declarations,
            'required_function_names' => self::REQUIRED_FUNCTION_NAMES,
            'field_groups' => [
                'fields' => array_column($fieldGroups['fields'], 'campoNombre'),
                'items' => array_column($fieldGroups['items'], 'campoNombre'),
            ],
            'contract_hash' => self::hashPayload($declarations),
        ];
    }

    /**
     * SHA-256 canónico de un payload serializable.
     * Usado para contract_hash y target_context_hash.
     */
    public static function hashPayload(mixed $payload): string
    {
        $sorted = self::recursiveKsort($payload);
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json !== false ? $json : '');
    }

    /**
     * Ordena keys recursivamente para serialización determinística.
     */
    private static function recursiveKsort(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        // Solo ordenar keys en arrays asociativos
        if (array_values($data) !== $data) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            $data[$key] = self::recursiveKsort($value);
        }

        return $data;
    }

    /**
     * @param  array<int,array<string,mixed>> $fields
     * @return array{fields:array<int,array<string,mixed>>,items:array<int,array<string,mixed>>}
     */
    private function groupFields(string $documentName, array $fields): array
    {
        $groups = [
            'fields' => [],
            'items' => [],
        ];

        foreach ($fields as $field) {
            $name = $this->fieldName($field);
            $target = $this->isItemField($documentName, $name, (string) ($field['tipoCampo'] ?? 'E'))
                ? 'items'
                : 'fields';

            $groups[$target][] = $field;
        }

        return $groups;
    }

    /**
     * @param  array<string,mixed> $field
     */
    private function fieldName(array $field): string
    {
        $name = trim((string) ($field['campoNombre'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Campo sin campoNombre en extraction contract');
        }

        return $name;
    }

    public function isItemField(string $documentName, string $fieldName, string $tipoCampo): bool
    {
        if (AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS) {
            return true;
        }

        if (in_array($fieldName, self::ITEM_FIELD_NAMES, true)) {
            return true;
        }

        return self::isPrescriptionDocument($documentName) && $fieldName === 'NumeroAutorizacion';
    }

    public static function isPrescriptionDocument(string $documentName): bool
    {
        $normalized = self::normalizeDocumentName($documentName);

        foreach (['FORMULA', 'PRESCRIPCION', 'RECETA', 'ORDEN MEDICA'] as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeDocumentName(string $value): string
    {
        $upper = strtoupper(trim($value));
        $ascii = strtr($upper, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
            '_' => ' ',
            '-' => ' ',
        ]);

        $ascii = preg_replace('/\s+/', ' ', $ascii);
        return trim((string) $ascii);
    }

    /**
     * @param  array<int,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function buildExtractFieldsDeclaration(array $fields): array
    {
        $properties = $this->buildFieldProperties($fields);

        return [
            'name' => self::FN_EXTRACT_FIELDS,
            'description' => 'Extrae campos administrativos y documentales visibles del documento. '
                . 'Para cada campo, reporta el valor literal visible, si está presente, '
                . 'y el estado de extracción. '
                . 'Usa estadoExtraccion=FOUND_IN_LIST cuando el campo contiene múltiples valores '
                . 'separados por coma, barra o punto y coma (ej: códigos diagnósticos). '
                . 'Usa FOUND para un único valor claro, NOT_FOUND si no es visible, '
                . 'AMBIGUOUS si hay conflicto y ILLEGIBLE si no es legible.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'propertyOrdering' => array_keys($properties),
                    ],
                ],
                'required' => ['fields'],
                'propertyOrdering' => ['fields'],
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function buildExtractItemsDeclaration(array $fields): array
    {
        $properties = $this->buildFieldProperties($fields);

        return [
            'name' => self::FN_EXTRACT_ITEMS,
            'description' => 'Extrae filas de producto o prescripción visibles, una entrada por línea documental. '
                . 'No colapses cantidades, lotes, fechas de vencimiento ni códigos distintos entre filas. '
                . 'Cada fila del documento debe ser un item independiente.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => $properties,
                            'propertyOrdering' => array_keys($properties),
                        ],
                    ],
                ],
                'required' => ['items'],
                'propertyOrdering' => ['items'],
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $visualChecks
     * @return array<string,mixed>
     */
    private function buildDetectVisualChecksDeclaration(array $visualChecks): array
    {
        $checkNames = [];
        foreach ($visualChecks as $check) {
            $name = trim((string) ($check['check'] ?? ''));
            if ($name !== '') {
                $checkNames[] = $name;
            }
        }

        $checkProperty = ['type' => 'string'];
        if ($checkNames !== []) {
            $checkProperty['enum'] = array_values(array_unique($checkNames));
        }

        return [
            'name' => self::FN_DETECT_VISUAL_CHECKS,
            'description' => 'Detecta checks visuales configurados para el documento.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'visual_checks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'check' => $checkProperty,
                                'presente' => ['type' => 'boolean'],
                                'valor' => ['type' => 'number', 'nullable' => true],
                                'unidad' => [
                                    'type' => 'string',
                                    'enum' => ['dias'],
                                    'nullable' => true,
                                ],
                                'fecha_base' => ['type' => 'string', 'nullable' => true],
                                'detalle' => ['type' => 'string', 'nullable' => true],
                                'severidad' => ['type' => 'string', 'nullable' => true],
                            ],
                            'required' => ['check', 'presente'],
                            'propertyOrdering' => ['check', 'presente', 'valor', 'unidad', 'fecha_base', 'detalle', 'severidad'],
                        ],
                    ],
                ],
                'required' => ['visual_checks'],
                'propertyOrdering' => ['visual_checks'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAssessDocumentQualityDeclaration(): array
    {
        return [
            'name' => self::FN_ASSESS_DOCUMENT_QUALITY,
            'description' => 'Evalúa la legibilidad general del documento para auditoría.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'document_quality' => [
                        'type' => 'string',
                        'enum' => ['legible', 'parcialmente_legible', 'ilegible'],
                    ],
                    'quality_notes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['document_quality', 'quality_notes'],
                'propertyOrdering' => ['document_quality', 'quality_notes'],
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private function buildFieldProperties(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $name = $this->fieldName($field);
            $properties[$name] = $this->buildEvidenceFieldSchema(
                $name,
                (string) ($field['tipoCampo'] ?? 'E')
            );
        }

        return $properties;
    }

    /**
     * Construye el JSON Schema de un campo con estructura de evidencia v1.
     *
     * Shape: {valor, valores, presente, confianza, estadoExtraccion, evidencia, ubicacion}
     * - `valor`: valor principal (tipo derivado del campo)
     * - `valores`: array de tokens individuales (útil para FOUND_IN_LIST)
     * - `presente`: si el dato fue encontrado en el documento
     * - `confianza`: nivel de certeza de la extracción
     * - `estadoExtraccion`: clasificación del resultado de búsqueda
     * - `evidencia`: texto literal visible en el documento
     * - `ubicacion`: posición aproximada en el documento
     */
    private function buildEvidenceFieldSchema(string $fieldName, string $tipoCampo): array
    {
        $valorType = $this->schemaTypeForField($fieldName, $tipoCampo);

        return [
            'type' => 'object',
            'properties' => [
                'valor' => [
                    'type' => $valorType,
                    'nullable' => true,
                    'description' => 'Valor principal extraído del documento tal como aparece visible.',
                ],
                'valores' => [
                    'type' => 'array',
                    'items' => ['type' => $valorType],
                    'description' => 'Tokens individuales cuando el campo contiene múltiples valores (FOUND_IN_LIST). Array de un elemento cuando es FOUND.',
                ],
                'presente' => [
                    'type' => 'boolean',
                    'description' => 'true si el dato fue encontrado visiblemente en el documento.',
                ],
                'confianza' => [
                    'type' => 'string',
                    'enum' => ['alta', 'media', 'baja'],
                ],
                'estadoExtraccion' => [
                    'type' => 'string',
                    'enum' => ['FOUND', 'FOUND_IN_LIST', 'NOT_FOUND', 'AMBIGUOUS', 'ILLEGIBLE'],
                    'description' => 'FOUND: valor único claro. FOUND_IN_LIST: múltiples valores separados por coma/barra/punto y coma. NOT_FOUND: no visible. AMBIGUOUS: conflicto. ILLEGIBLE: no legible.',
                ],
                'evidencia' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Texto literal visible en el documento que sustenta este campo.',
                ],
                'ubicacion' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Posición aproximada en el documento (ej: "sección Diagnóstico", "tabla fila 2").',
                ],
            ],
            'required' => ['valor', 'presente', 'estadoExtraccion'],
            'propertyOrdering' => ['valor', 'valores', 'presente', 'confianza', 'estadoExtraccion', 'evidencia', 'ubicacion'],
        ];
    }

    private function schemaTypeForField(string $fieldName, string $tipoCampo): string
    {
        if (AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS) {
            return 'number';
        }

        return AuditFieldValueType::fromFieldName($fieldName)->isNumericForSchema() ? 'number' : 'string';
    }
}
