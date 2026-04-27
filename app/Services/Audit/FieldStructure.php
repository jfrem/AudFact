<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Constantes estructurales de la dispensación farmacéutica.
 *
 * Define la ESTRUCTURA de los datos (qué campos son items, fechas, cantidades),
 * NO las reglas de auditoría (severidades, tipos de comparación, roles).
 * Las reglas de auditoría viven en BD (AudDispCampo).
 */
final class FieldStructure
{
    /** Campos que se resuelven desde items[] en vez de fields{} */
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

    /** Campos numéricos que se suman cuando hay múltiples items */
    public const QUANTITY_FIELDS = [
        'CantidadEntregada',
        'CantidadPrescrita',
    ];

    /** Campos que requieren normalización de fecha para comparación */
    public const DATE_FIELDS = [
        'FechaFormula',
        'FechaAutorizacion',
        'FechaEntrega',
        'FechaVencimiento',
    ];

    /** Campos numéricos para normalización en comparación exacta */
    public const NUMBER_FIELDS = [
        'CantidadEntregada',
        'CantidadPrescrita',
        'VlrCobrado',
    ];

    /** Campos multi-valor no escalares que se omiten si son ambiguos */
    public const NON_SCALAR_MULTI_ITEM_FIELDS = [
        'Lote',
        'FechaVencimiento',
        'CodigoProducto',
        'CUM',
    ];

    public static function isPerItemField(string $field): bool
    {
        return in_array($field, self::PER_ITEM_FIELDS, true);
    }

    public static function isQuantityField(string $field): bool
    {
        return in_array($field, self::QUANTITY_FIELDS, true);
    }

    public static function isDateField(string $field): bool
    {
        return in_array($field, self::DATE_FIELDS, true);
    }

    public static function isNumberField(string $field): bool
    {
        return in_array($field, self::NUMBER_FIELDS, true);
    }

    public static function isNonScalarMultiItemField(string $field): bool
    {
        return in_array($field, self::NON_SCALAR_MULTI_ITEM_FIELDS, true);
    }

    /** Umbral default para comparación textual local */
    public const DEFAULT_SEMANTIC_THRESHOLD = 0.90;

    /**
     * Umbral de similitud textual derivado del TipoCampo de BD.
     *
     * Campos semánticos (S) usan un umbral más tolerante porque
     * el SemanticMatchJudge (Gemini) actúa como fallback.
     */
    public static function getSemanticThreshold(string $tipoCampo): float
    {
        return match (strtoupper($tipoCampo)) {
            'S' => 0.82,
            default => self::DEFAULT_SEMANTIC_THRESHOLD,
        };
    }

    /**
     * Define si un tipo de campo permite coincidencia por contención (substring).
     * Los campos semánticos (S) permiten substring match como heurística
     * rápida antes de delegar a Gemini.
     */
    public static function isSubstringMatchAllowed(string $tipoCampo): bool
    {
        return strtoupper($tipoCampo) === 'S';
    }
}
