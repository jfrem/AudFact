<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Estados posibles de la extracción de un campo documental por Gemini.
 *
 * Reemplaza la validación por in_array() con strings mágicos
 * que existía en DocumentNormalizer::normalizeEstadoExtraccion().
 */
enum ExtractionState: string
{
    case FOUND         = 'FOUND';
    case FOUND_IN_LIST = 'FOUND_IN_LIST';
    case NOT_FOUND     = 'NOT_FOUND';
    case ILLEGIBLE     = 'ILLEGIBLE';

    /**
     * Hidrata desde input arbitrario (Gemini output, cache, etc.) con fallback seguro.
     */
    public static function fromInput(mixed $value): self
    {
        if (!is_string($value)) {
            return self::FOUND;
        }

        $upper = strtoupper(trim($value));
        return self::tryFrom($upper) ?? self::FOUND;
    }
}
