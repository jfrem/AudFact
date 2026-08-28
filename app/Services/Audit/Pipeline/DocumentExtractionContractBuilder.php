<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use InvalidArgumentException;

final class DocumentExtractionContractBuilder
{
    /**
     * Construye el contrato Gemini de Structured Output para un documento.
     *
     * @param  string                    $documentName  Tipo documental configurado.
     * @param  array<int,array<string,mixed>> $fields   Campos activos del audit-config.
     * @param  array<int,array<string,mixed>> $visualChecks Checks visuales activos.
     * @return array<string,mixed>
     */
    public function build(string $documentName, array $fields, array $visualChecks): array
    {
        $fieldGroups = $this->groupFields($documentName, $fields);
        $responseSchema = $this->buildResponseSchema(
            $fieldGroups,
            $this->activeVisualChecks($visualChecks)
        );

        return [
            'response_schema' => $responseSchema,
            'field_groups' => [
                'fields' => array_column($fieldGroups['fields'], 'campoNombre'),
                'items' => array_column($fieldGroups['items'], 'campoNombre'),
            ],
            'contract_hash' => self::hashPayload([
                'response_schema' => $responseSchema,
            ]),
        ];
    }

    /**
     * @param  array{fields:array<int,array<string,mixed>>,items:array<int,array<string,mixed>>} $fieldGroups
     * @param  array<int,array<string,mixed>> $visualChecks
     * @return array<string,mixed>
     */
    private function buildResponseSchema(array $fieldGroups, array $visualChecks): array
    {
        $properties = [
            'document_conformity' => [
                'type' => 'object',
                'properties' => [
                    'matches_expected_type' => [
                        'type' => 'boolean',
                        'description' => 'True si el formato y estructura del documento corresponden genuinamente al tipo documental objetivo. False si el archivo corresponde a otra tipología documental distinta.',
                    ],
                    'detected_type' => [
                        'type' => 'string',
                        'nullable' => true,
                        'description' => 'El tipo o categoría de documento identificado en la imagen (ej: Cédula, Recibo de caja, Historia clínica, Fórmula médica, etc.).',
                    ],
                    'justification' => [
                        'type' => 'string',
                        'nullable' => true,
                        'description' => 'Breve explicación objetiva de por qué coincide o no con la tipología requerida.',
                    ],
                ],
                'required' => ['matches_expected_type', 'detected_type', 'justification'],
                'propertyOrdering' => ['matches_expected_type', 'detected_type', 'justification'],
            ],
        ];
        $required = ['document_conformity'];

        if ($fieldGroups['fields'] !== []) {
            $properties['fields'] = $this->buildFlatObjectSchema($fieldGroups['fields']);
            $required[] = 'fields';
        }

        if ($fieldGroups['items'] !== []) {
            $properties['items'] = [
                'type' => 'array',
                'items' => $this->buildFlatObjectSchema($fieldGroups['items']),
            ];
            $required[] = 'items';
        }

        if ($visualChecks !== []) {
            $properties['visual_checks'] = $this->buildVisualChecksSchema($visualChecks);
            $required[] = 'visual_checks';
        }

        $properties['document_quality'] = [
            'type' => 'string',
            'enum' => ['legible', 'parcialmente_legible', 'ilegible'],
        ];
        $properties['quality_notes'] = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
        $required[] = 'document_quality';
        $required[] = 'quality_notes';

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];

        if ($properties !== []) {
            $schema['propertyOrdering'] = array_keys($properties);
        }

        return $schema;
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
            $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
            if ($tipoCampo === 'I') {
                continue; // Excluir campos de uso interno (backend) del prompt de IA
            }

            $name = $this->fieldName($field);
            $valueType = $this->fieldValueType($field);
            $target = $this->isItemField($tipoCampo, $valueType, $field)
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

    /**
     * @param array<string,mixed> $fieldConfig
     */
    public function isItemField(
        string $tipoCampo,
        ?AuditFieldValueType $valueType = null,
        array $fieldConfig = []
    ): bool {
        if ((bool) ($fieldConfig['esMultiItem'] ?? false)) {
            return true;
        }

        if (AuditComparisonType::fromTipoCampo($tipoCampo) === AuditComparisonType::BUSINESS) {
            return true;
        }

        if ($valueType !== null && $valueType->isItemScoped()) {
            return true;
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
     * @param  array<int,array<string,mixed>> $visualChecks
     * @return array<string,mixed>
     */
    private function buildVisualChecksSchema(array $visualChecks): array
    {
        $checkNames = [];
        $descriptions = [];
        $requiresDeliveryValidityFields = false;
        foreach ($visualChecks as $check) {
            $name = trim((string) ($check['check'] ?? ''));
            $desc = trim((string) ($check['description'] ?? ''));
            if ($name !== '') {
                $checkNames[] = $name;
                if ($name === 'VigenciaEntrega') {
                    $requiresDeliveryValidityFields = true;
                }
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

        $visualProperties = [
            'check' => $checkProperty,
            'presente' => ['type' => 'boolean'],
            'detalle' => [
                'type' => 'string',
                'nullable' => true,
            ],
            'severidad' => ['type' => 'string', 'nullable' => true],
        ];
        $visualOrdering = ['check', 'presente', 'detalle'];
        if ($requiresDeliveryValidityFields) {
            $visualProperties['valor'] = ['type' => 'number', 'nullable' => true];
            $visualProperties['unidad'] = [
                'type' => 'string',
                'enum' => ['dias'],
                'nullable' => true,
            ];
            $visualProperties['fecha_base'] = ['type' => 'string', 'nullable' => true];
            $visualOrdering = ['check', 'presente', 'detalle', 'valor', 'unidad', 'fecha_base'];
        }
        $visualOrdering[] = 'severidad';

        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => $visualProperties,
                'required' => ['check', 'presente', 'detalle'],
                'propertyOrdering' => $visualOrdering,
            ],
        ];
    }

    /**
     * Construye un schema de objeto plano {type: 'object', properties: {...}}.
     *
     * @param  array<int,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function buildFlatObjectSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $name = $this->fieldName($field);
            $properties[$name] = $this->buildFlatFieldSchema($field);
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($properties !== []) {
            $schema['required'] = array_keys($properties);
            $schema['propertyOrdering'] = array_keys($properties);
        }

        return $schema;
    }

    /**
     * Construye el schema plano para un campo individual (primitivo nullable).
     *
     * @param  array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function buildFlatFieldSchema(array $field): array
    {
        $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
        $valueType = $this->fieldValueType($field);

        if ($valueType === AuditFieldValueType::TRACE_TOKEN) {
            $schema = [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'nullable' => true,
            ];
        } else {
            $valorType = $this->schemaTypeForField($valueType);
            $schema = [
                'type' => $valorType,
                'nullable' => true,
            ];
        }

        $configuredDescription = isset($field['description']) ? trim((string) $field['description']) : '';
        $fallbackDescription = $valueType->fieldDescriptionFallback();
        $valorDescription = $configuredDescription !== '' ? $configuredDescription : $fallbackDescription;

        if ($valorDescription !== null && $valorDescription !== '') {
            $schema['description'] = $valorDescription;
        }

        return $schema;
    }

    private function schemaTypeForField(AuditFieldValueType $valueType): string
    {
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
