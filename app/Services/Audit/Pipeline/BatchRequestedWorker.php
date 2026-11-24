<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\InvoicesModel;
use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\BatchChunkState;
use Core\Logger;
use RuntimeException;

/**
 * Worker dedicado para consumir eventos `batch_requested` desde el stream
 * `audit.batch.inbox`.
 *
 * Ejecuta el escaneo pesado de SQL Server y la orquestación de reservas
 * por DisDetNro en background, liberando completamente al pool PHP-FPM.
 *
 * Stream:   audit.batch.inbox  (dedicado, evita head-of-line blocking)
 * Group:    batch-workers
 * Consumer: batch-{hostname}-{pid}
 * Eventos:  batch_requested (ignora cualquier otro)
 */
final class BatchRequestedWorker extends AuditEventConsumer
{
    public const DEFAULT_PENDING_RECLAIM_IDLE_MS = 1800000;
    public const DEFAULT_PENDING_RECLAIM_INTERVAL_MS = 60000;

    private AuditStateStore $stateStore;
    private BatchJobStore $jobStore;
    private string $consumerName;
    private ?AuditBatchOrchestrator $orchestrator;

    public function __construct(
        ?AuditStateStore        $stateStore   = null,
        ?BatchJobStore          $jobStore     = null,
        ?\Core\RedisClient      $redis        = null,
        ?AuditEventPublisher    $publisher    = null,
        ?string                 $consumerName = null,
        ?AuditBatchOrchestrator $orchestrator = null,
        string|AuditLane|null   $lane         = null
    ) {
        parent::__construct($redis, $publisher, $stateStore, $lane);

        $this->stateStore   = $stateStore   ?? new AuditStateStore($this->redis);
        $this->jobStore     = $jobStore     ?? new BatchJobStore($this->redis);
        $this->consumerName = $consumerName ?? self::defaultConsumerName(AuditEventPublisher::GROUP_BATCH, $this->laneEnum);
        $this->orchestrator = $orchestrator;

        // Timeout de inactividad de 30 minutos (1.800.000 ms) exclusivo para consultas batch masivas en SQL Server
        $this->pendingReclaimIdleMs = (int) \Core\Env::get('AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS', self::DEFAULT_PENDING_RECLAIM_IDLE_MS);
        $this->pendingReclaimIntervalMs = (int) \Core\Env::get('AUDIT_BATCH_PENDING_RECLAIM_INTERVAL_MS', self::DEFAULT_PENDING_RECLAIM_INTERVAL_MS);

        $this->assertFreezeTimeoutFitsLockWindow();
    }

    /**
     * DEC-12 / R-SQLTO: una generación puede tardar hasta `4·T + 36 s` (renovaciones solo antes y después de la
     * consulta); debe caber estrictamente dentro de `min(lockTtl, reclaimIdle)` o el lock/PEL expirarían mientras el
     * worker aún escribe. Si no cabe, el worker no arranca.
     *
     * @throws RuntimeException `batch_freeze_timeout_exceeds_lock_window`
     */
    private function assertFreezeTimeoutFitsLockWindow(): void
    {
        $freezeTimeout = AuditExecutionConfig::freezeQueryTimeoutSeconds();
        $lockTtl = AuditExecutionConfig::batchLockTtlSeconds();
        $reclaimIdleSeconds = intdiv($this->pendingReclaimIdleMs, 1000);
        $windowSeconds = min($lockTtl, $reclaimIdleSeconds);
        $requiredSeconds = 4 * $freezeTimeout + 36;

        if ($requiredSeconds >= $windowSeconds) {
            Logger::error('BatchRequestedWorker: configuración inválida, no arranca', [
                'reason' => 'batch_freeze_timeout_exceeds_lock_window',
                'freeze_timeout_seconds' => $freezeTimeout,
                'lock_ttl_seconds' => $lockTtl,
                'reclaim_idle_seconds' => $reclaimIdleSeconds,
                'required_seconds' => $requiredSeconds,
            ]);

            throw new RuntimeException('batch_freeze_timeout_exceeds_lock_window');
        }
    }

    /**
     * La DLQ de `batch_requested` opera con el mismo `BatchJobStore` del worker (misma conexión Redis).
     */
    protected function batchJobStoreForDeadLetter(): BatchJobStore
    {
        return $this->jobStore;
    }

    protected function streams(): array
    {
        return [AuditEventPublisher::STREAM_BATCH_INBOX];
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_BATCH;
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function eventTypes(): array
    {
        return [AuditEvent::TYPE_BATCH_REQUESTED];
    }

    protected function handle(AuditEvent $event): void
    {
        $this->handleBatchRequested($event);
    }

    /**
     * Procesa un evento batch_requested delegando al AuditBatchOrchestrator.
     *
     * El orquestador ejecuta (bajo lock con fencing por epoch):
     * 1. Una única consulta a SQL Server por job (congelación de candidatas, `getInvoicesForAuditBatch`)
     * 2. Reservas atómicas SETNX por DisDetNro
     * 3. Publicación de los eventos audit_created del chunk en audit.inbox
     * 4. Checkpoint del chunk (y continuación) o sellado del job
     *
     * Si el lock está ocupado lanza JobGenerationBusyException (el mensaje queda en PEL). Si falla, la excepción
     * (JobGenerationFailedException con el epoch del propietario) se propaga a AuditEventConsumer para reintentos
     * automáticos o envío al DLQ.
     */
    private function handleBatchRequested(AuditEvent $event): void
    {
        $jobId = $event->jobId;
        if ($jobId === null) {
            throw new RuntimeException('batch_requested sin job_id');
        }

        $payload = $event->payload;

        $facNitSec = (int) ($payload['fac_nit_sec'] ?? 0);
        $dateFrom  = trim((string) ($payload['date_from'] ?? ''));
        $dateTo    = trim((string) ($payload['date_to'] ?? ''));
        $limit     = (int) ($payload['limit'] ?? 100);

        if ($facNitSec < 1 || $dateFrom === '' || $dateTo === '') {
            throw new RuntimeException('batch_requested con parámetros incompletos: '
                . "fac_nit_sec={$facNitSec}, date_from={$dateFrom}, date_to={$dateTo}");
        }

        $chunkState = BatchChunkState::fromPayload($payload);

        Logger::info('BatchRequestedWorker: procesando batch_requested', [
            'job_id'            => $jobId,
            'fac_nit_sec_masked' => AuditBatchOrchestrator::maskNit($facNitSec),
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            'limit'             => $limit,
            'chunk_index'       => $chunkState->chunkIndex,
            'accumulated_total' => $chunkState->accumulatedTotal,
        ]);

        $orchestrator = $this->orchestrator ?? new AuditBatchOrchestrator(
            $this->stateStore,
            $this->jobStore,
            $this->publisher,
            new InvoicesModel()
        );

        $result = $orchestrator->enqueueBatch(
            facNitSec: $facNitSec,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            limit: $limit,
            jobId: $jobId,
            workerToken: $this->consumerName,
            chunkState: $chunkState
        );

        // INV-02: lock ocupado → NO retornar normal (el consumidor haría XACK); el mensaje queda en PEL sin quemar intentos.
        if (($result['locked_busy'] ?? false) === true) {
            throw new JobGenerationBusyException($jobId, $chunkState->chunkIndex);
        }

        if (($result['has_more'] ?? false) === true) {
            $this->publishContinuationEvent(
                $event,
                $jobId,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $limit,
                $payload,
                $result,
                $chunkState
            );
        }

        Logger::info('BatchRequestedWorker: batch chunk procesado', [
            'job_id'          => $jobId,
            'chunk_index'     => $chunkState->chunkIndex,
            'has_more'        => $result['has_more'] ?? false,
            'total'           => $result['total'] ?? 0,
            'accepted'        => $result['accepted'] ?? 0,
            'skipped_locked'  => $result['skipped_locked'] ?? 0,
            'skipped_existing' => $result['skipped_existing'] ?? 0,
        ]);
    }

    /**
     * Publica la continuación `k+1` con `event_id` determinista y confirma `markContinuationDispatched(k)` ANTES de
     * permitir el `XACK` de `k` (`R-CONT`). La confirmación NO valida epoch: el lock ya se liberó y el consumidor de
     * `k+1` pudo incrementar el epoch legítimamente.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $result
     * @throws RuntimeException `job_missing_on_continuation` (503) si el job desapareció.
     */
    private function publishContinuationEvent(
        AuditEvent $parentEvent,
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        array $payload,
        array $result,
        BatchChunkState $chunkState
    ): void {
        $continuationPayload = [
            'fac_nit_sec' => (string) $facNitSec,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
            'source' => $payload['source'] ?? 'batch_continuation',
            'cursor' => $result['next_cursor'] ?? null,
            'chunk_index' => $chunkState->chunkIndex + 1,
            'accumulated_total' => (int) ($result['accumulated_total'] ?? 0),
            'accumulated_skipped_locked' => (int) ($result['accumulated_skipped_locked'] ?? 0),
            'accumulated_skipped_existing' => (int) ($result['accumulated_skipped_existing'] ?? 0),
            // Fuente primaria del offset: sin él la continuación releería el mismo slice indefinidamente (V-F2-B3).
            'candidate_offset' => (int) ($result['next_candidate_offset'] ?? $result['next_cursor']['offset'] ?? $chunkState->candidateOffset),
        ];

        if ($chunkState->chunkSizeOverride !== null) {
            $continuationPayload['chunk_size'] = $chunkState->chunkSizeOverride;
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: $jobId,
            payload: $continuationPayload,
            parentEventId: $parentEvent->eventId,
            eventId: AuditEvent::deterministicUuidV4("batch-cont:{$jobId}:" . ($chunkState->chunkIndex + 1)),
        ));

        if (!$this->jobStore->markContinuationDispatched($jobId, $chunkState->chunkIndex)) {
            throw new RuntimeException('job_missing_on_continuation', 503);
        }
    }
}


