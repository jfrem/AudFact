<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de comparación para campos de auditoría.
 *
 * Fuente de verdad: columna `TipoCampo` en `Discolnet.dbo.AudDispCampo`.
 * Este enum centraliza las constantes que antes vivían en FieldClassifier::TYPE_*.
 */
enum AuditComparisonType: string
{
    case EXACT    = 'exact';
    case SEMANTIC = 'semantic';
    case VISUAL   = 'visual';
    case BUSINESS = 'business';

    /**
     * Mapea el código de BD (E/S/B/V) al tipo interno.
     */
    public static function fromTipoCampo(string $tipoCampo): self
    {
        return match (strtoupper(trim($tipoCampo))) {
            'S'     => self::SEMANTIC,
            'B'     => self::BUSINESS,
            'V'     => self::VISUAL,
            default => self::EXACT,
        };
    }
}
