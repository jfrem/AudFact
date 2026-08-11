<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditStatusModel;
use App\Models\InvoicesModel;
use App\Services\Audit\AuditBatchOrchestrator;
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
    private AuditStateStore $stateStore;
    private BatchJobStore $jobStore;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore     $stateStore   = null,
        ?BatchJobStore       $jobStore     = null,
        ?\Core\RedisClient   $redis        = null,
        ?AuditEventPublisher $publisher    = null,
        ?string              $consumerName = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore   = $stateStore   ?? new AuditStateStore($this->redis);
        $this->jobStore     = $jobStore     ?? new BatchJobStore($this->redis);
        $this->consumerName = $consumerName ?? self::defaultConsumerName(AuditEventPublisher::GROUP_BATCH);
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_BATCH_INBOX;
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

        Logger::info('BatchRequestedWorker: procesando batch_requested', [
            'job_id'      => $jobId,
            'fac_nit_sec' => $facNitSec,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'limit'       => $limit,
        ]);

        $orchestrator = new AuditBatchOrchestrator(
            $this->stateStore,
            $this->jobStore,
            $this->publisher,
            new InvoicesModel(),
            new AuditStatusModel()
        );

        $result = $orchestrator->enqueueBatch($facNitSec, $dateFrom, $dateTo, $limit, $jobId);

        Logger::info('BatchRequestedWorker: batch procesado', [
            'job_id'          => $jobId,
            'total'           => $result['total'] ?? 0,
            'accepted'        => $result['accepted'] ?? 0,
            'skipped_locked'  => $result['skipped_locked'] ?? 0,
            'skipped_existing' => $result['skipped_existing'] ?? 0,
        ]);
    }
}
