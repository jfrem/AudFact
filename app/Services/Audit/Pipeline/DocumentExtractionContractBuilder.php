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
        $declarations = $this->buildFunctionDeclarations(
            $fieldGroups,
            $this->activeVisualChecks($visualChecks)
        );
        $requiredFunctionNames = self::functionNames($declarations);

        return [
            'function_declarations' => $declarations,
            'required_function_names' => $requiredFunctionNames,
            'field_groups' => [
                'fields' => array_column($fieldGroups['fields'], 'campoNombre'),
                'items' => array_column($fieldGroups['items'], 'campoNombre'),
            ],
            'contract_hash' => self::hashPayload([
                'function_declarations' => $declarations,
                'required_function_names' => $requiredFunctionNames,
            ]),
        ];
    }

    /**
     * @param  array{fields:array<int,array<string,mixed>>,items:array<int,array<string,mixed>>} $fieldGroups
     * @param  array<int,array<string,mixed>> $visualChecks
     * @return array<int,array<string,mixed>>
     */
    private function buildFunctionDeclarations(array $fieldGroups, array $visualChecks): array
    {
        $declarations = [];

        if ($fieldGroups['fields'] !== []) {
            $declarations[] = $this->buildExtractFieldsDeclaration($fieldGroups['fields']);
        }

        if ($fieldGroups['items'] !== []) {
            $declarations[] = $this->buildExtractItemsDeclaration($fieldGroups['items']);
        }

        if ($visualChecks !== []) {
            $declarations[] = $this->buildDetectVisualChecksDeclaration($visualChecks);
        }

        $declarations[] = $this->buildAssessDocumentQualityDeclaration();

        return $declarations;
    }

    /**
     * @param  array<int,array<string,mixed>> $declarations
     * @return array<int,string>
     */
    private static function functionNames(array $declarations): array
    {
        return array_values(array_column($declarations, 'name'));
    }

    /**
     * SHA-256 canónico de un payload serializable.
     * Usado para hashes internos de contrato y prompt.
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
            $valueType = $this->fieldValueType($field);
            $target = $this->isItemField($documentName, $name, (string) ($field['tipoCampo'] ?? 'E'), $valueType)
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

    public function isItemField(
        string $documentName,
        string $fieldName,
        string $tipoCampo,
        ?AuditFieldValueType $valueType = null
    ): bool
    {
        if (AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS) {
            return true;
        }

        if ($valueType === AuditFieldValueType::ARTICLE_NAME || $valueType === AuditFieldValueType::TRACE_TOKEN) {
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
        return [
            'name' => self::FN_EXTRACT_FIELDS,
            'description' => 'Extrae campos visibles.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'fields' => $this->buildObjectSchema($fields),
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
        return [
            'name' => self::FN_EXTRACT_ITEMS,
            'description' => 'Extrae filas visibles, una por linea.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => $this->buildObjectSchema($fields),
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
        $descriptions = [];
        foreach ($visualChecks as $check) {
            $name = trim((string) ($check['check'] ?? ''));
            $desc = trim((string) ($check['description'] ?? ''));
            if ($name !== '') {
                $checkNames[] = $name;
                if ($desc !== '') {
                    $descriptions[] = "{$name} ({$desc})";
                }
            }
        }

        $checkProperty = ['type' => 'string'];
        if ($checkNames !== []) {
            $checkProperty['enum'] = array_values(array_unique($checkNames));
        }
        if ($descriptions !== []) {
            $checkProperty['description'] = implode(' | ', $descriptions);
        }

        return [
            'name' => self::FN_DETECT_VISUAL_CHECKS,
            'description' => 'Detecta checks visuales visibles.',
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
                                'detalle' => [
                                    'type' => 'string',
                                    'nullable' => true,
                                ],
                                'severidad' => ['type' => 'string', 'nullable' => true],
                            ],
                            'required' => ['check', 'presente', 'detalle'],
                            'propertyOrdering' => ['check', 'presente', 'detalle', 'valor', 'unidad', 'fecha_base', 'severidad'],
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
            'description' => 'Evalua legibilidad general.',
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
     * Construye un nodo JSON Schema `{type: 'object', properties: {...}}` seguro.
     * Centraliza la garantía de que `properties` se serialice siempre como
     * un objeto JSON (`{}`) y nunca como un array (`[]`), independientemente
     * de si hay campos configurados. Gemini rechaza con 400 si recibe un array.
     * @param  array<int,array<string,mixed>> $fields  Campos del audit-config.
     * @return array<string,mixed>  Nodo schema listo para embebir en la declaración.
     */
    private function buildObjectSchema(array $fields): array
    {
        $properties = $this->buildFieldProperties($fields);

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($properties !== []) {
            $schema['propertyOrdering'] = array_keys($properties);
        }

        return $schema;
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
            $properties[$name] = $this->buildEvidenceFieldSchema($field);
        }

        return $properties;
    }

    /**
     * Construye el JSON Schema de un campo con estructura de evidencia.
     *
     * Shape: {valor, valores, presente, estadoExtraccion}
     * - `valor`: valor principal (tipo derivado del campo)
     * - `valores`: array de tokens individuales (útil para FOUND_IN_LIST)
     * - `presente`: si el dato fue encontrado en el documento
     * - `estadoExtraccion`: clasificación del resultado de búsqueda
     */
    private function buildEvidenceFieldSchema(array $field): array
    {
        $fieldName = $this->fieldName($field);
        $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
        $valueType = $this->fieldValueType($field);
        $valorType = $this->schemaTypeForField($valueType, $tipoCampo);
        $valorProperty = [
            'type' => $valorType,
            'nullable' => true,
        ];
        $valorDescription = $this->fieldValueDescription($fieldName, $valueType);
        if ($valorDescription !== null) {
            $valorProperty['description'] = $valorDescription;
        }

        $valoresProperty = [
            'type' => 'array',
            'items' => ['type' => $valorType],
        ];
        $valoresDescription = $this->fieldValuesDescription($fieldName, $valueType);
        if ($valoresDescription !== null) {
            $valoresProperty['description'] = $valoresDescription;
        }

        return [
            'type' => 'object',
            'properties' => [
                'valor' => $valorProperty,
                'valores' => $valoresProperty,
                'presente' => ['type' => 'boolean'],
                'estadoExtraccion' => [
                    'type' => 'string',
                    'enum' => ['FOUND', 'FOUND_IN_LIST', 'NOT_FOUND', 'ILLEGIBLE'],
                ],
            ],
            'required' => ['valor', 'presente', 'estadoExtraccion'],
            'propertyOrdering' => ['valor', 'valores', 'presente', 'estadoExtraccion'],
        ];
    }

    private function schemaTypeForField(AuditFieldValueType $valueType, string $tipoCampo): string
    {
        if (AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS) {
            return 'number';
        }

        return $valueType->isNumericForSchema() ? 'number' : 'string';
    }

    /**
     * @param  array<int,mixed> $visualChecks
     * @return array<int,array<string,mixed>>
     */
    private function activeVisualChecks(array $visualChecks): array
    {
        $active = [];
        foreach ($visualChecks as $check) {
            if (is_array($check) && trim((string) ($check['check'] ?? '')) !== '') {
                $active[] = $check;
            }
        }

        return $active;
    }

    private function fieldValueDescription(string $fieldName, AuditFieldValueType $valueType): ?string
    {
        if ($valueType === AuditFieldValueType::IDENTITY_DOC_NUMBER) {
            return $this->identityDocumentNumberDescription($fieldName);
        }

        if ($valueType === AuditFieldValueType::PERSON_NAME) {
            return $this->identityPersonNameDescription($fieldName);
        }

        if ($valueType === AuditFieldValueType::IDENTITY_DOC_TYPE) {
            return 'Solo tipo de documento: CC, CE, TI, RC, PA, PE, PPT, MS, AS, NUIP o SC.';
        }

        return null;
    }

    private function fieldValuesDescription(string $fieldName, AuditFieldValueType $valueType): ?string
    {
        if ($valueType === AuditFieldValueType::IDENTITY_DOC_NUMBER) {
            return 'Numero limpio en FOUND; varios tokens solo si hay lista.';
        }

        if ($valueType === AuditFieldValueType::PERSON_NAME) {
            return 'Nombre limpio en FOUND; sin tipo ni numero.';
        }

        return null;
    }

    private function identityDocumentNumberDescription(string $fieldName): string
    {
        if ($fieldName === 'DocumentoMedico') {
            return 'Solo numero del prescriptor; sin nombre, registro ni tipo.';
        }

        return 'Solo numero del paciente; sin tipo ni nombre.';
    }

    private function identityPersonNameDescription(string $fieldName): string
    {
        if ($fieldName === 'Medico') {
            return 'Solo nombres y apellidos del prescriptor; sin documento ni registro.';
        }

        return 'Solo nombres y apellidos; sin tipo ni numero.';
    }

    /**
     * @param  array<string,mixed> $field
     */
    private function fieldValueType(array $field): AuditFieldValueType
    {
        $tipoDato = trim((string) ($field['tipoDato'] ?? ''));
        if ($tipoDato === '') {
            throw new InvalidArgumentException('Campo sin tipoDato en extraction contract');
        }

        return AuditFieldValueType::fromInput($tipoDato);
    }
}
