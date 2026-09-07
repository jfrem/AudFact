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
 * por DisId en background, liberando completamente al pool PHP-FPM.
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

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_BATCH_REQUESTED) {
            return;
        }

        $this->handleBatchRequested($event);
    }

    /**
     * Procesa un evento batch_requested delegando al AuditBatchOrchestrator.
     *
     * El orquestador ejecuta:
     * 1. Consulta pesada a SQL Server (getInvoicesForAuditBatch)
     * 2. Reservas atómicas SETNX por DisId
     * 3. Publicación de N eventos audit_created en audit.inbox
     * 4. Sellado del job
     *
     * Si falla, la excepción se propaga a AuditEventConsumer para
     * reintentos automáticos o envío al DLQ.
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
            'fac_nit_sec'       => $facNitSec,
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
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $result
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
        ));
    }
}


