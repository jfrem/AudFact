<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Tipos de almacenamiento físico con contenido descargable en el pipeline de auditoría.
 */
enum AttachmentStorageType: string
{
    case Url = 'URL';
    case Blob = 'BLOB';

    /**
     * Normaliza y valida una cadena de transporte (BD, payload de evento o metadata).
     * Retorna null si es vacío, SIN_DOCUMENTOS o no reconocido.
     */
    public static function fromTransport(mixed $raw): ?self
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $raw));
        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized);
    }
}

