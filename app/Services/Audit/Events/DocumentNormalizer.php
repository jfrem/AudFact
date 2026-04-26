<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use App\Services\Audit\FieldClassifier;
use RuntimeException;

class DocumentNormalizer
{
    private const DOCUMENT_QUALITY_ENUM = [
        'legible',
        'parcialmente_legible',
        'ilegible',
    ];

    private FieldClassifier $classifier;

    public function __construct(?FieldClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new FieldClassifier();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $rawType = trim((string) ($payload['tipo_documento'] ?? ''));
        if ($rawType === '') {
            throw new RuntimeException('document_extracted sin tipo_documento');
        }

        $extraction = $payload['extraction_result'] ?? null;
        if (!is_array($extraction)) {
            throw new RuntimeException('document_extracted sin extraction_result válido');
        }

        $normalizationLog = [];
        $fieldsNormalized = $this->normalizeFields($extraction['fields'] ?? [], $normalizationLog);
        $itemsNormalized = $this->normalizeItems($extraction['items'] ?? [], $normalizationLog);
        $visualChecksResultado = $this->normalizeVisualChecks(
            $payload['visual_checks'] ?? [],
            $extraction['visual_checks'] ?? [],
            $normalizationLog
        );
        $documentQuality = $this->normalizeDocumentQuality($extraction['document_quality'] ?? null);
        $qualityNotes = $this->normalizeQualityNotes($extraction['quality_notes'] ?? [], $normalizationLog);

        return [
            'tipo_documento' => $rawType,
            'fields_normalized' => $fieldsNormalized,
            'items_normalized' => $itemsNormalized,
            'visual_checks_resultado' => $visualChecksResultado,
            'document_quality' => $documentQuality,
            'quality_notes' => $qualityNotes,
            'normalization_log' => $normalizationLog,
        ];
    }

    /**
     * @param mixed $fields
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<string,mixed>
     */
    private function normalizeFields(mixed $fields, array &$normalizationLog): array
    {
        if (!is_array($fields)) {
            throw new RuntimeException('extraction_result.fields debe ser array');
        }

        $normalized = [];
        foreach ($fields as $field => $value) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            $originalField = trim($field);
            $canonicalField = $this->normalizeCanonicalField(
                $originalField,
                $normalizationLog,
                'field_alias_normalized'
            );

            [$normalizedValue, $operations] = $this->normalizeScalarWithOperations($value);
            foreach ($operations as $operation) {
                $this->appendLog($normalizationLog, $operation, [
                    'field' => $canonicalField,
                ]);
            }

            if (!array_key_exists($canonicalField, $normalized) || $normalized[$canonicalField] === null) {
                $normalized[$canonicalField] = $normalizedValue;
            }
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param mixed $items
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(mixed $items, array &$normalizationLog): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];
            foreach ($item as $field => $value) {
                if (!is_string($field) || trim($field) === '') {
                    continue;
                }

                $originalField = trim($field);
                $canonicalField = $this->normalizeCanonicalField(
                    $originalField,
                    $normalizationLog,
                    'item_field_alias_normalized',
                    ['item_index' => $index]
                );

                [$normalizedValue, $operations] = $this->normalizeScalarWithOperations($value);
                foreach ($operations as $operation) {
                    $this->appendLog($normalizationLog, $operation, [
                        'item_index' => $index,
                        'field' => $canonicalField,
                    ]);
                }

                $row[$canonicalField] = $normalizedValue;
            }

            if ($this->isEmptyRow($row)) {
                $this->appendLog($normalizationLog, 'empty_item_row_dropped', [
                    'item_index' => $index,
                ]);
                continue;
            }

            ksort($row);
            $normalized[] = $row;
        }

        return array_values($normalized);
    }

    /**
     * @param mixed $configuredChecks
     * @param mixed $extractedChecks
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<int,array{check:string,presente:bool,detalle:?string,severidad:string}>
     */
    private function normalizeVisualChecks(
        mixed $configuredChecks,
        mixed $extractedChecks,
        array &$normalizationLog
    ): array {
        $configMap = [];
        if (is_array($configuredChecks)) {
            foreach ($configuredChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $canonical = $this->normalizeCanonicalField(
                    $name,
                    $normalizationLog,
                    'visual_check_alias_normalized',
                    [],
                    'check_original',
                    'check_normalized'
                );

                $configMap[$canonical] = [
                    'check' => $canonical,
                    'presente' => false,
                    'detalle' => $this->normalizeNullableString($check['description'] ?? null),
                    'severidad' => $this->normalizeSeverity($check['severity'] ?? null),
                ];

                $this->appendLog($normalizationLog, 'visual_check_defaulted', [
                    'check' => $canonical,
                    'presente' => false,
                ]);
            }
        }

        $result = $configMap;
        if (is_array($extractedChecks)) {
            foreach ($extractedChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $canonical = $this->classifier->normalizeField($name);
                $base = $result[$canonical] ?? [
                    'check' => $canonical,
                    'presente' => false,
                    'detalle' => null,
                    'severidad' => 'CRITICO',
                ];

                $base['presente'] = (bool) ($check['presente'] ?? false);
                $detail = $this->normalizeNullableString($check['detalle'] ?? null);
                if ($detail !== null) {
                    $base['detalle'] = $detail;
                }

                $base['severidad'] = $this->normalizeSeverity($check['severidad'] ?? null);
                $result[$canonical] = $base;

                $this->appendLog($normalizationLog, 'visual_check_result_normalized', [
                    'check' => $canonical,
                    'presente' => $base['presente'],
                ]);
            }
        }

        ksort($result);
        return array_values($result);
    }

    private function normalizeDocumentQuality(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, self::DOCUMENT_QUALITY_ENUM, true)) {
            throw new RuntimeException("document_quality inválido para normalización: {$normalized}");
        }

        return $normalized;
    }

    /**
     * @param mixed $notes
     * @param array<int,array<string,mixed>> $normalizationLog
     * @return array<int,string>
     */
    private function normalizeQualityNotes(mixed $notes, array &$normalizationLog): array
    {
        if (!is_array($notes)) {
            return [];
        }

        $normalized = [];
        foreach ($notes as $index => $note) {
            $string = $this->normalizeNullableString($note);
            if ($string === null) {
                if (is_string($note) && trim($note) === '') {
                    $this->appendLog($normalizationLog, 'quality_note_empty_dropped', [
                        'note_index' => $index,
                    ]);
                }
                continue;
            }

            $normalized[] = $string;
        }

        $unique = array_values(array_unique($normalized));
        if (count($unique) !== count($normalized)) {
            $this->appendLog($normalizationLog, 'quality_notes_deduplicated');
        }

        return $unique;
    }

    /**
     * @return array{0:mixed,1:array<int,string>}
     */
    private function normalizeScalarWithOperations(mixed $value): array
    {
        if ($value === null) {
            return [null, []];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            $operations = [];
            if ($trimmed !== $value) {
                $operations[] = 'string_trimmed';
            }
            if ($trimmed === '') {
                $operations[] = 'empty_string_to_null';
                return [null, $operations];
            }

            if (strtolower($trimmed) === 'null') {
                $operations[] = 'literal_null_string_to_null';
                return [null, $operations];
            }

            return [$trimmed, $operations];
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return [$value, []];
        }

        return [null, ['unsupported_value_to_null']];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeSeverity(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        return $normalized !== '' ? $normalized : 'CRITICO';
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @param array<string,mixed> $context
     */
    private function normalizeCanonicalField(
        string $originalField,
        array &$normalizationLog,
        string $operation,
        array $context = [],
        string $originalKey = 'field_original',
        string $normalizedKey = 'field_normalized'
    ): string {
        $canonicalField = $this->classifier->normalizeField($originalField);
        if ($canonicalField !== $originalField) {
            $this->appendLog($normalizationLog, $operation, array_merge($context, [
                $originalKey => $originalField,
                $normalizedKey => $canonicalField,
            ]));
        }

        return $canonicalField;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $normalizationLog
     * @param array<string,mixed> $context
     */
    private function appendLog(array &$normalizationLog, string $operation, array $context = []): void
    {
        $normalizationLog[] = array_merge(['operation' => $operation], $context);
    }
}
