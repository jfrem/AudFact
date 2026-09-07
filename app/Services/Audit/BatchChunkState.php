<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Estado inmutable y configuración de un chunk de ingesta batch para planificación round-robin.
 *
 * Encapsula la posición del cursor keyset, métricas de avance y límites operativos
 * para evitar la proliferación de parámetros ("Long Parameter List") en el orquestador.
 */
final readonly class BatchChunkState
{
    /**
     * @param array{date:string,disId:string,dispensa:string}|null $cursor Cursor keyset de la última fila leída.
     * @param int $chunkIndex Índice del chunk actual en el ciclo de vida del job (1..N).
     * @param int $accumulatedTotal Facturas aceptadas acumuladas en chunks previos.
     * @param int $accumulatedSkippedLocked Facturas omitidas por bloqueo en chunks previos.
     * @param int $accumulatedSkippedExisting Facturas omitidas por auditoría preexistente en chunks previos.
     * @param int|null $chunkSizeOverride Sobrescritura opcional del tamaño de chunk (para tests o tuning específico).
     */
    public function __construct(
        public ?array $cursor = null,
        public int $chunkIndex = 1,
        public int $accumulatedTotal = 0,
        public int $accumulatedSkippedLocked = 0,
        public int $accumulatedSkippedExisting = 0,
        public ?int $chunkSizeOverride = null,
    ) {}

    /**
     * Construye el estado inicial para el primer chunk de un lote.
     */
    public static function initial(?int $chunkSizeOverride = null): self
    {
        return new self(
            cursor: null,
            chunkIndex: 1,
            accumulatedTotal: 0,
            accumulatedSkippedLocked: 0,
            accumulatedSkippedExisting: 0,
            chunkSizeOverride: $chunkSizeOverride,
        );
    }

    /**
     * Desempaqueta y valida el estado del chunk a partir del payload de un evento batch_requested.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $cursor = is_array($payload['cursor'] ?? null) ? $payload['cursor'] : null;
        $chunkIndex = max(1, (int) ($payload['chunk_index'] ?? 1));
        $accumulatedTotal = max(0, (int) ($payload['accumulated_total'] ?? 0));
        $accumulatedSkippedLocked = max(0, (int) ($payload['accumulated_skipped_locked'] ?? 0));
        $accumulatedSkippedExisting = max(0, (int) ($payload['accumulated_skipped_existing'] ?? 0));
        $chunkSize = isset($payload['chunk_size']) && (int) $payload['chunk_size'] > 0 ? (int) $payload['chunk_size'] : null;

        return new self(
            cursor: $cursor,
            chunkIndex: $chunkIndex,
            accumulatedTotal: $accumulatedTotal,
            accumulatedSkippedLocked: $accumulatedSkippedLocked,
            accumulatedSkippedExisting: $accumulatedSkippedExisting,
            chunkSizeOverride: $chunkSize,
        );
    }
}
