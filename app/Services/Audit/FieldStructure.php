<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Metadata estructural de campos de dispensación farmacéutica.
 *
 * Deriva el tipo de dato de cada campo por CONVENCIÓN DE NOMBRE,
 * no por listas hardcodeadas. Si un campo empieza con "Fecha", es fecha.
 * Si empieza con "Cantidad", es cantidad sumable. Etc.
 *
 * Las reglas de auditoría viven en BD (AudDispCampo).
 */
final class FieldStructure
{
    /** Umbral default para comparación textual local */
    public const DEFAULT_SEMANTIC_THRESHOLD = 0.90;

    // ─── Convention-based type detection ──────────────────────────────────────

    public static function isDateField(string $field): bool
    {
        return str_starts_with($field, 'Fecha');
    }

    public static function isQuantityField(string $field): bool
    {
        return str_starts_with($field, 'Cantidad');
    }

    public static function isNumberField(string $field): bool
    {
        return self::isQuantityField($field) || str_starts_with($field, 'Vlr');
    }

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
