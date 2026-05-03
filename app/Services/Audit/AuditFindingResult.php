<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Resultados posibles de la evaluación de un hallazgo de auditoría.
 * Los valores de string son el contrato público del pipeline (almacenados
 * en Redis y en BD). No modificar sin versionar el contrato de eventos.
 */
enum AuditFindingResult: string
{
    case MATCH        = 'COINCIDE';
    case MISMATCH     = 'VALOR_DISTINTO';
    case NOT_FOUND    = 'NO_ENCONTRADO';
    case SKIPPED      = 'OMITIDO';
    case INCONCLUSIVE = 'NO_CONCLUYENTE';

    /**
     * Indica si el resultado representa un fallo auditable (afecta risk_score).
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::MISMATCH, self::NOT_FOUND, self::INCONCLUSIVE => true,
            default => false,
        };
    }

    /**
     * Indica si el resultado representa una discrepancia directa de datos.
     */
    public function isDiscrepancy(): bool
    {
        return match ($this) {
            self::MISMATCH, self::NOT_FOUND => true,
            default => false,
        };
    }

    /**
     * Indica si el resultado implica que no se puede emitir un veredicto.
     */
    public function isInconclusive(): bool
    {
        return $this === self::INCONCLUSIVE;
    }

    /**
     * Indica si el campo no fue evaluado (omitido por regla condicional).
     */
    public function isSkipped(): bool
    {
        return $this === self::SKIPPED;
    }
}