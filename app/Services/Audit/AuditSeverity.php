<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Severidades de auditoría normalizadas.
 *
 * Fuente de verdad: columna `SeveridadOverride` en `Discolnet.dbo.AudDispCampo`.
 * Este enum centraliza las constantes que antes vivían dispersas en FieldClassifier.
 */
enum AuditSeverity: string
{
    case HIGH   = 'alta';
    case MEDIUM = 'media';
    case LOW    = 'baja';

    /**
     * Normaliza un string de severidad proveniente de BD o payload.
     */
    public static function fromInput(string $value): self
    {
        return match (strtoupper(trim($value))) {
            'CRITICO', 'CRÍTICO', 'ALTA', 'HIGH' => self::HIGH,
            'BAJA', 'LOW'                         => self::LOW,
            default                               => self::MEDIUM,
        };
    }
}
