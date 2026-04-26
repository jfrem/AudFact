<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use InvalidArgumentException;

final class SchemaBuilder
{
    public function build(array $auditConfig): array
    {
        $documents = $auditConfig['documents'] ?? null;
        if (!is_array($documents) || $documents === []) {
            throw new InvalidArgumentException('audit-config.documents es requerido');
        }

        $normalized = [];
        foreach ($documents as $documentName => $documentConfig) {
            if (!is_string($documentName) || !is_array($documentConfig)) {
                throw new InvalidArgumentException('Documento inválido en audit-config');
            }

            $docId = (int) ($documentConfig['docId'] ?? 0);
            if ($docId < 1) {
                throw new InvalidArgumentException("Documento sin docId válido: {$documentName}");
            }

            $fields = $this->normalizeFields($documentConfig['fields'] ?? []);
            $visualChecks = $this->normalizeVisualChecks($documentConfig['visualChecks'] ?? []);

            $normalized[] = [
                'doc_id' => $docId,
                'document_name' => trim($documentName),
                'document_name_normalized' => self::normalizeName($documentName),
                'fields' => $fields,
                'extraction_schema' => $this->buildExtractionSchema($fields, $visualChecks),
                'visual_checks' => $visualChecks,
            ];
        }

        usort(
            $normalized,
            static fn(array $a, array $b): int => $a['doc_id'] <=> $b['doc_id']
        );

        return $normalized;
    }

    private function buildExtractionSchema(array $fields, array $visualChecks = []): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = [
                'type' => 'string',
            ];
        }

        return [
            'name' => 'extract_document_data',
            'description' => 'Extrae datos estructurados y verificaciones visuales de un documento de auditoria farmaceutica.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'object',
                        'properties' => $properties,
                    ],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => $properties,
                        ],
                    ],
                    'visual_checks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'check' => ['type' => 'string'],
                                'presente' => ['type' => 'boolean'],
                                'detalle' => ['type' => 'string'],
                                'severidad' => ['type' => 'string'],
                            ],
                            'required' => ['check', 'presente'],
                        ],
                    ],
                    'document_quality' => [
                        'type' => 'string',
                        'enum' => ['legible', 'parcialmente_legible', 'ilegible'],
                    ],
                    'quality_notes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'required' => ['fields', 'visual_checks', 'document_quality'],
            ],
        ];
    }

    public static function normalizeName(string $value): string
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

    private function normalizeFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException('fields debe ser array');
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException('Campo inválido en fields');
            }
            $normalized[] = trim($field);
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeVisualChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            throw new InvalidArgumentException('visualChecks debe ser array');
        }

        $normalized = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !is_string($check['check'] ?? null) || trim((string) $check['check']) === '') {
                throw new InvalidArgumentException('Visual check inválido');
            }

            $normalized[] = [
                'check' => trim((string) $check['check']),
                'description' => isset($check['description']) && is_string($check['description'])
                    ? trim($check['description'])
                    : '',
                'severity' => isset($check['severity']) && is_string($check['severity']) && trim($check['severity']) !== ''
                    ? strtoupper(trim($check['severity']))
                    : 'CRITICO',
            ];
        }

        return $normalized;
    }
}
