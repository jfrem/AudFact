<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingRules;
use RuntimeException;

/**
 * Encapsula la resolución de valores para campos de datos (DataFields).
 *
 * Extraída de DocumentPolicyEngine para cohesión (SRP).
 * Se encarga de cruzar los valores extraídos por Gemini (ExtractedEvidence)
 * con la fuente de la verdad, resolviendo jerarquías de header/items y
 * consolidaciones especiales (como sumar cantidades).
 */
final class FieldValueResolver
{
    /**
     * @param  array<string,mixed> $documentState
     * @return array<string,mixed>
     */
    public static function resolveSourceTruth(array $documentState): array
    {
        $sourceTruth = $documentState['fuente_verdad'] ?? null;
        if (!is_array($sourceTruth)) {
            throw new RuntimeException('document_normalized sin fuente_verdad válida');
        }

        return $sourceTruth;
    }

    /**
     * @return array<string,ExtractedEvidence>
     */
    public static function normalizeAssociative(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $hydrated = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $hydrated[$k] = ExtractedEvidence::fromArray($v);
            } elseif ($v instanceof ExtractedEvidence) {
                $hydrated[$k] = $v;
            }
        }
        return $hydrated;
    }

    /**
     * @return array<int,array<string,ExtractedEvidence>>
     */
    public static function normalizeRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hydratedRow = [];
            foreach ($row as $k => $v) {
                if (is_array($v)) {
                    $hydratedRow[$k] = ExtractedEvidence::fromArray($v);
                } elseif ($v instanceof ExtractedEvidence) {
                    $hydratedRow[$k] = $v;
                }
            }
            $rows[] = $hydratedRow;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed> $sourceTruth
     */
    public static function resolveSourceTruthValue(
        string $field,
        AuditFieldValueType $valueType,
        array $sourceTruth
    ): ?string {
        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];
        $items  = is_array($sourceTruth['items'] ?? null)  ? $sourceTruth['items']  : [];

        $headerValue = self::extractRowValue($header, $field);
        if ($headerValue !== null) {
            return $headerValue;
        }

        if ($items === []) {
            return null;
        }

        $itemValues = self::extractItemValues($items, $field);

        if ($valueType->isQuantitySummable()) {
            $total = AuditFindingRules::sumNumericValues($itemValues);
            return $total !== null ? AuditFindingRules::formatNumber($total) : null;
        }

        if ($itemValues === []) {
            return null;
        }

        $unique = array_values(array_unique($itemValues));
        sort($unique);
        if (count($unique) === 1) {
            return $unique[0];
        }

        if ($valueType->requiresTraceSetComparison()) {
            return implode(', ', $unique);
        }

        return null;
    }

    /**
     * Boundary de conversión DTO→escalar para el documento.
     *
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    public static function resolveDocumentValue(
        string $field,
        AuditFieldValueType $valueType,
        array $fields,
        array $items
    ): array {
        [$itemValues, $evidenceMeta] = self::extractPresentItemValues($field, $items);

        if ($itemValues !== []) {
            return self::resolveItemDocumentValue($valueType, $itemValues, $evidenceMeta);
        }

        return self::resolveHeaderDocumentValue($field, $fields, $evidenceMeta);
    }

    /**
     * @param  array<int,string> $docValues
     * @return array<int,string>|null
     */
    public static function resolveFindingDocumentValues(
        AuditFieldValueType $valueType,
        ?string $docValue,
        array $docValues
    ): ?array {
        if ($valueType === AuditFieldValueType::CODE && $docValue !== null) {
            return AuditFindingRules::tokenizeCodeField($docValue);
        }

        if ($valueType === AuditFieldValueType::TRACE_TOKEN && $docValues !== []) {
            return $docValues;
        }

        return null;
    }

    private static function extractItemValues(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = self::extractRowValue($item, $field);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * @param  array<string,mixed> $row
     */
    private static function extractRowValue(array $row, string $field): ?string
    {
        $candidate = $row[$field] ?? null;
        if (!AuditFindingRules::isPresent($candidate)) {
            return null;
        }

        return AuditFindingRules::scalarToString($candidate);
    }

    /**
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private static function extractPresentItemValues(string $field, array $items): array
    {
        $itemValues = [];
        $evidenceMeta = [];

        foreach ($items as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $cell = $row[$field];
            if (!$cell instanceof ExtractedEvidence || !AuditFindingRules::isPresent($cell->valor)) {
                continue;
            }

            $itemValues[] = AuditFindingRules::scalarToString($cell->valor);
            if ($evidenceMeta === []) {
                $evidenceMeta = $cell->extractMeta();
            }
        }

        return [$itemValues, $evidenceMeta];
    }

    /**
     * @param  array<int,string> $itemValues
     * @param  array<string,mixed> $evidenceMeta
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    private static function resolveItemDocumentValue(
        AuditFieldValueType $valueType,
        array $itemValues,
        array $evidenceMeta
    ): array {
        if ($valueType->isQuantitySummable()) {
            $total = AuditFindingRules::sumNumericValues($itemValues);
            if ($total !== null) {
                $formatted = AuditFindingRules::formatNumber($total);
                return ['displayValue' => $formatted, 'values' => [$formatted], 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
            }
        }

        $unique = array_values(array_unique($itemValues));
        sort($unique);

        if (count($unique) === 1) {
            return ['displayValue' => $unique[0], 'values' => $unique, 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
        }

        return [
            'displayValue'  => implode(', ', $unique),
            'values'        => $unique,
            'ambiguous'     => !$valueType->requiresTraceSetComparison(),
            'evidenceMeta'  => $evidenceMeta,
        ];
    }

    /**
     * @param  array<string,mixed> $fields
     * @param  array<string,mixed> $evidenceMeta
     * @return array{displayValue:?string, values:array<int,string>, ambiguous:bool, evidenceMeta:array<string,mixed>}
     */
    private static function resolveHeaderDocumentValue(string $field, array $fields, array $evidenceMeta): array
    {
        if (array_key_exists($field, $fields)) {
            $cell = $fields[$field];
            if ($cell instanceof ExtractedEvidence && AuditFindingRules::isPresent($cell->valor)) {
                $displayValue = AuditFindingRules::scalarToString($cell->valor);
                return [
                    'displayValue' => $displayValue,
                    'values' => [$displayValue],
                    'ambiguous' => false,
                    'evidenceMeta' => $cell->extractMeta(),
                ];
            }
        }

        return ['displayValue' => null, 'values' => [], 'ambiguous' => false, 'evidenceMeta' => $evidenceMeta];
    }
}
