<?php
declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditComparisonType;
use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\AuditFindingRules;

final class FdvItemAggregator
{
    /**
     * @param  array<int,array<string,mixed>> $items        Ítems crudos de la FDV.
     * @param  array<int,array<string,mixed>> $fieldsConfig Campos del audit-config para este documento.
     * @param  string                         $documentType Tipo documental (para isItemField).
     * @return array<int,array<string,mixed>>               Ítems consolidados.
     */
    public static function aggregate(
        array $items,
        array $fieldsConfig,
        string $documentType,
        ?DocumentExtractionContractBuilder $contractBuilder = null
    ): array {
        if (count($items) <= 1) {
            return $items;
        }

        $contractBuilder ??= new DocumentExtractionContractBuilder();
        [$groupingKeys, $summableKeys] = self::classifyItemFields($fieldsConfig, $contractBuilder, $documentType);

        if ($groupingKeys === []) {
            return $items;
        }

        return self::mergeGroups(
            self::groupItems($items, $groupingKeys),
            $summableKeys
        );
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private static function classifyItemFields(
        array $fieldsConfig,
        DocumentExtractionContractBuilder $contractBuilder,
        string $documentType
    ): array {
        $groupingKeys = [];
        $summableKeys = [];

        foreach ($fieldsConfig as $field) {
            $name      = trim((string) ($field['campoNombre'] ?? ''));
            $tipoCampo = (string) ($field['tipoCampo'] ?? 'E');
            $tipoDato  = strtolower(trim((string) ($field['tipoDato'] ?? 'text')));

            if ($name === '') {
                continue;
            }

            $valueType = AuditFieldValueType::tryFrom($tipoDato);
            if (!$contractBuilder->isItemField($documentType, $name, $tipoCampo, $valueType)) {
                continue;
            }

            $comparison = AuditComparisonType::fromTipoCampo($tipoCampo);

            if ($valueType !== null && $valueType->isQuantitySummable()) {
                $summableKeys[] = $name;
            } elseif ($comparison === AuditComparisonType::EXACT || $comparison === AuditComparisonType::SEMANTIC) {
                $groupingKeys[] = $name;
            }
        }

        return [$groupingKeys, $summableKeys];
    }

    /**
     * @param  array<int,array<string,mixed>> $items
     * @param  array<int,string>              $groupingKeys
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function groupItems(array $items, array $groupingKeys): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = self::buildGroupKey($item, $groupingKeys);
            $groups[$key][] = $item;
        }
        return $groups;
    }

    /**
     * @param  array<string,mixed> $item
     * @param  array<int,string>   $groupingKeys
     */
    private static function buildGroupKey(array $item, array $groupingKeys): string
    {
        $parts = [];
        foreach ($groupingKeys as $field) {
            $value = array_key_exists($field, $item)
                ? AuditFindingRules::scalarToString($item[$field])
                : '';
            $parts[] = $field . '=' . $value;
        }
        return implode('|', $parts);
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>> $groups
     * @param  array<int,string>                            $summableKeys
     * @return array<int,array<string,mixed>>
     */
    private static function mergeGroups(array $groups, array $summableKeys): array
    {
        $result = [];
        foreach ($groups as $members) {
            $merged = $members[0];

            if (count($members) > 1) {
                foreach ($summableKeys as $field) {
                    $values = [];
                    foreach ($members as $member) {
                        if (array_key_exists($field, $member) && AuditFindingRules::isPresent($member[$field])) {
                            $values[] = AuditFindingRules::scalarToString($member[$field]);
                        }
                    }
                    $sum = AuditFindingRules::sumNumericValues($values);
                    if ($sum !== null) {
                        $merged[$field] = AuditFindingRules::formatNumber($sum);
                    }
                }
            }

            $result[] = $merged;
        }
        return $result;
    }
}
