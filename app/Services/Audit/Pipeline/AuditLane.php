<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Carriles de ejecución y procesamiento de auditoría en Redis Streams.
 *
 * Fuente única de verdad para normalización de carriles y pertenencia de streams.
 */
enum AuditLane: string
{
    case ALL = 'all';
    case PRIORITY = 'priority';
    case BATCH = 'batch';

    /**
     * Resuelve y normaliza una cadena de texto a un AuditLane válido.
     * Insensible a mayúsculas/minúsculas y espacios en blanco.
     * Retorna ALL si el valor es nulo, vacío o desconocido.
     */
    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::ALL;
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return self::ALL;
        }

        return self::tryFrom($trimmed) ?? self::ALL;
    }

    /**
     * Determina si un stream específico debe ser consumido en este carril.
     *
     * - ALL: Consume cualquier stream declarado.
     * - PRIORITY: Consume streams prioritarios ('.priority' o ':priority') y corrientes neutras (sin sufijo de lote).
     * - BATCH: Consume streams de lote ('.batch' o ':batch') y corrientes neutras (sin sufijo de prioridad).
     */
    public function matchesStream(string $stream): bool
    {
        if ($this === self::ALL) {
            return true;
        }

        $isPriority = str_ends_with($stream, '.priority') || str_ends_with($stream, ':priority');
        $isBatch = str_ends_with($stream, '.batch') || str_ends_with($stream, ':batch');

        if ($this === self::PRIORITY) {
            return $isPriority || !$isBatch;
        }

        return $isBatch || !$isPriority;
    }

    public function isPriority(): bool
    {
        return $this === self::PRIORITY;
    }

    public function isBatch(): bool
    {
        return $this === self::BATCH;
    }

    public function isAll(): bool
    {
        return $this === self::ALL;
    }
}
