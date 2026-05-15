<?php

declare(strict_types=1);

namespace App\Services\Audit;

use RuntimeException;

/**
 * Calidades documentales reconocidas por el pipeline de auditoría.
 *
 * Fuente de verdad que reemplaza la constante privada `DOCUMENT_QUALITY_ENUM`
 * que existía duplicada en DocumentExtractionWorker, DocumentNormalizer y
 * DocumentPolicyEngine.
 */
enum DocumentQuality: string
{
    case LEGIBLE           = 'legible';
    case PARTIAL           = 'parcialmente_legible';
    case ILLEGIBLE         = 'ilegible';

    /**
     * Normaliza y valida una calidad documental proveniente de payload o BD.
     *
     * @throws RuntimeException si el valor no corresponde a ningún case.
     */
    public static function fromString(string $raw): self
    {
        $normalized = strtolower(trim($raw));
        $case = self::tryFrom($normalized);

        if ($case === null) {
            throw new RuntimeException("document_quality inválido: '{$normalized}'");
        }

        return $case;
    }
}
