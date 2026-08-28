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
    public static function resolveSourceTruthField(
        string $field,
        AuditFieldValueType $valueType,
        array $sourceTruth
    ): ResolvedAuditValue {
        $header = is_array($sourceTruth['header'] ?? null) ? $sourceTruth['header'] : [];
        $items  = is_array($sourceTruth['items'] ?? null)  ? $sourceTruth['items']  : [];

        $headerValue = self::extractRowValue($header, $field, $valueType);
        if ($headerValue !== null) {
            return self::singleValue(ResolvedAuditValue::SOURCE_FDV, $valueType, $headerValue);
        }

        if ($items === []) {
            return self::emptyValue(ResolvedAuditValue::SOURCE_FDV);
        }

        $itemValues = self::extractItemValues($items, $field, $valueType);
        if ($itemValues === []) {
            return self::emptyValue(ResolvedAuditValue::SOURCE_FDV);
        }

        return self::resolveItemValues(ResolvedAuditValue::SOURCE_FDV, $valueType, $itemValues);
    }

    /**
     * Boundary de conversión DTO→escalar para el documento.
     *
     * @param  array<string,mixed> $fields
     * @param  array<int,array<string,mixed>> $items
     */
    public static function resolveDocumentValue(
        string $field,
        AuditFieldValueType $valueType,
        array $fields,
        array $items
    ): ResolvedAuditValue {
        [$itemValues, $evidenceMeta, $ambiguousItemValues] = self::extractPresentItemValues($field, $valueType, $items);

        if ($ambiguousItemValues !== []) {
            return self::ambiguousValue(
                ResolvedAuditValue::SOURCE_DOCUMENT,
                $valueType,
                $ambiguousItemValues,
                $evidenceMeta
            );
        }

        if ($itemValues !== []) {
            return self::resolveItemValues(ResolvedAuditValue::SOURCE_DOCUMENT, $valueType, $itemValues, $evidenceMeta);
        }

        return self::resolveHeaderDocumentValue($field, $valueType, $fields, $evidenceMeta);
    }

    /**
     * @return array<int,string>|null
     */
    public static function resolveFindingValues(
        AuditFieldValueType $valueType,
        ResolvedAuditValue $resolvedValue
    ): ?array {
        if ($valueType === AuditFieldValueType::CODE && $resolvedValue->displayValue !== null) {
            return AuditFindingRules::tokenizeCodeField($resolvedValue->displayValue);
        }

        if ($valueType === AuditFieldValueType::TRACE_TOKEN && $resolvedValue->values !== []) {
            return $resolvedValue->values;
        }

        return null;
    }

    private static function extractItemValues(array $items, string $field, AuditFieldValueType $valueType): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = self::extractRowValue($item, $field, $valueType);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * @param  array<string,mixed> $row
     */
    private static function extractRowValue(array $row, string $field, AuditFieldValueType $valueType): ?string
    {
        $candidate = $row[$field] ?? null;
        if (!AuditFindingRules::isMeaningfulFdvValue($candidate, $valueType)) {
            return null;
        }

        return AuditFindingRules::scalarToString($candidate);
    }

    /**
     * @param  array<int,array<string,mixed>> $items
     * @return array{0:array<int,string>,1:array<string,mixed>,2:array<int,string>}
     */
    private static function extractPresentItemValues(
        string $field,
        AuditFieldValueType $valueType,
        array $items
    ): array
    {
        $itemValues = [];
        $evidenceMeta = [];
        $ambiguousValues = [];

        foreach ($items as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $cell = $row[$field];
            if (!$cell instanceof ExtractedEvidence) {
                continue;
            }

            if ($evidenceMeta === []) {
                $evidenceMeta = $cell->extractMeta();
            }

            $candidateValues = self::extractEvidenceCandidateValues($cell, $valueType);
            if ($candidateValues === []) {
                continue;
            }

            if (count($candidateValues) > 1 && !$valueType->allowsMultiValueDocument()) {
                array_push($ambiguousValues, ...$candidateValues);
                continue;
            }

            array_push($itemValues, ...$candidateValues);
        }

        if ($ambiguousValues !== []) {
            array_push($ambiguousValues, ...$itemValues);
        }

        return [$itemValues, $evidenceMeta, self::uniqueSortedValues($ambiguousValues)];
    }

    /**
     * @param  array<int,string> $itemValues
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function resolveItemValues(
        string $source,
        AuditFieldValueType $valueType,
        array $itemValues,
        array $evidenceMeta = []
    ): ResolvedAuditValue {
        if ($valueType->isQuantitySummable()) {
            $total = AuditFindingRules::sumNumericValues($itemValues);
            if ($total !== null) {
                $formatted = AuditFindingRules::formatNumber($total);
                return self::resolvedValue($source, $valueType, $formatted, [$formatted], false, $evidenceMeta);
            }
        }

        $unique = self::uniqueSortedValues($itemValues);

        if (count($unique) === 1) {
            return self::resolvedValue($source, $valueType, $unique[0], $unique, false, $evidenceMeta);
        }

        return self::resolvedValue(
            $source,
            $valueType,
            implode(', ', $unique),
            $unique,
            !$valueType->allowsMultiValueDocument(),
            $evidenceMeta
        );
    }

    /**
     * @param  array<string,mixed> $fields
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function resolveHeaderDocumentValue(
        string $field,
        AuditFieldValueType $valueType,
        array $fields,
        array $evidenceMeta
    ): ResolvedAuditValue
    {
        if (array_key_exists($field, $fields)) {
            $cell = $fields[$field];
            if ($cell instanceof ExtractedEvidence) {
                $candidateValues = self::extractEvidenceCandidateValues($cell, $valueType);
                if ($candidateValues === []) {
                    return self::emptyValue(ResolvedAuditValue::SOURCE_DOCUMENT, $cell->extractMeta());
                }

                return self::resolveCandidateValues(
                    ResolvedAuditValue::SOURCE_DOCUMENT,
                    $valueType,
                    $candidateValues,
                    $cell->extractMeta()
                );
            }
        }

        return self::emptyValue(ResolvedAuditValue::SOURCE_DOCUMENT, $evidenceMeta);
    }

    /**
     * Convierte la evidencia normalizada en candidatos auditables.
     *
     * `FOUND_IN_LIST` o `valor=null` obliga a leer `valores`; para CODE/TRACE_TOKEN
     * se prefiere la lista porque representa tokens comparables.
     *
     * @return array<int,string>
     */
    private static function extractEvidenceCandidateValues(
        ExtractedEvidence $cell,
        AuditFieldValueType $valueType
    ): array {
        $valorPresent = AuditFindingRules::isPresent($cell->valor);
        $useListValues = $cell->estadoExtraccion === ExtractionState::FOUND_IN_LIST
            || !$valorPresent
            || $valueType->allowsMultiValueDocument();

        $values = [];
        if ($useListValues) {
            foreach ($cell->valores as $value) {
                if (AuditFindingRules::isPresent($value)) {
                    $values[] = AuditFindingRules::scalarToString($value);
                }
            }
        }

        if ($values === [] && $valorPresent) {
            $values[] = AuditFindingRules::scalarToString($cell->valor);
        }

        return self::uniqueSortedValues($values);
    }

    /**
     * @param  array<int,string> $candidateValues
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function resolveCandidateValues(
        string $source,
        AuditFieldValueType $valueType,
        array $candidateValues,
        array $evidenceMeta = []
    ): ResolvedAuditValue {
        $unique = self::uniqueSortedValues($candidateValues);
        return self::resolvedValue(
            $source,
            $valueType,
            implode(', ', $unique),
            $unique,
            count($unique) > 1 && !$valueType->allowsMultiValueDocument(),
            $evidenceMeta
        );
    }

    /**
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function singleValue(
        string $source,
        AuditFieldValueType $valueType,
        string $value,
        array $evidenceMeta = []
    ): ResolvedAuditValue {
        return self::resolvedValue($source, $valueType, $value, [$value], false, $evidenceMeta);
    }

    /**
     * @param  array<int,string> $values
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function ambiguousValue(
        string $source,
        AuditFieldValueType $valueType,
        array $values,
        array $evidenceMeta = []
    ): ResolvedAuditValue {
        $unique = self::uniqueSortedValues($values);
        return self::resolvedValue($source, $valueType, implode(', ', $unique), $unique, true, $evidenceMeta);
    }

    /**
     * @param  array<int,string> $values
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function resolvedValue(
        string $source,
        AuditFieldValueType $valueType,
        ?string $displayValue,
        array $values,
        bool $ambiguous,
        array $evidenceMeta = []
    ): ResolvedAuditValue {
        return new ResolvedAuditValue(
            source: $source,
            displayValue: $displayValue,
            values: array_values($values),
            normalizedValues: self::normalizeValues($valueType, $values),
            ambiguous: $ambiguous,
            evidenceMeta: $evidenceMeta
        );
    }

    /**
     * @param  array<string,mixed> $evidenceMeta
     */
    private static function emptyValue(string $source, array $evidenceMeta = []): ResolvedAuditValue
    {
        return new ResolvedAuditValue(
            source: $source,
            displayValue: null,
            values: [],
            normalizedValues: [],
            ambiguous: false,
            evidenceMeta: $evidenceMeta
        );
    }

    /**
     * @param  array<int,string> $values
     * @return array<int,string>
     */
    private static function normalizeValues(AuditFieldValueType $valueType, array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = AuditFindingRules::normalizeForComparison($valueType, $value);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<int,string> $values
     * @return array<int,string>
     */
    private static function uniqueSortedValues(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
