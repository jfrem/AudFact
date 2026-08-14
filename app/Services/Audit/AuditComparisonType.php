<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de comparación para campos de auditoría.
 *
 * Fuente de verdad: columna `TipoCampo` en `Discolnet.dbo.AudDispCampo`.
 * Incluye metadata estructural de campos (antes en FieldStructure).
 */
enum AuditComparisonType: string
{
    case EXACT    = 'exact';
    case SEMANTIC = 'semantic';
    case VISUAL   = 'visual';
    case BUSINESS = 'business';
    case INTERNAL = 'internal';

    /**
     * Mapea el código de BD (E/S/B/V/I) al tipo interno.
     */
    public static function fromTipoCampo(string $tipoCampo): self
    {
        return match (strtoupper(trim($tipoCampo))) {
            'S'     => self::SEMANTIC,
            'B'     => self::BUSINESS,
            'V'     => self::VISUAL,
            'I'     => self::INTERNAL,
            default => self::EXACT,
        };
    }

    /** Umbral default para comparación textual local */
    public const DEFAULT_SEMANTIC_THRESHOLD = 0.90;

    /**
     * Umbral de similitud textual derivado del TipoCampo de BD.
     *
     * Campos semánticos (S) usan un umbral más tolerante porque
     * ArticleSemanticMatchJudge puede actuar como fallback para artículos.
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
