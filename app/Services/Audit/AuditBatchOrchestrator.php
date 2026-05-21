<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\InvoicesModel;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\Logger;
use RuntimeException;

/**
 * Servicio de orquestación de lotes de auditoría.
 * 
 * Centraliza la lógica de encolamiento, reserva de slots (BatchJobStore),
 * inicialización del state store (AuditStateStore), publicación de eventos
 * y manejo transaccional de fallos (rollback de estado).
 */
final class AuditBatchOrchestrator
{
    public function __construct(
        private readonly AuditStateStore $stateStore,
        private readonly BatchJobStore $jobStore,
        private readonly AuditEventPublisher $publisher,
        private readonly InvoicesModel $invoicesModel
    ) {}

    /**
     * Encola un lote de dispensas para ser auditado de forma asíncrona.
     * 
     * @return array{job_id:string, status:string, total:int}
     * @throws RuntimeException Si el lote ya está activo o si hay falla persistiendo estado
     */
    public function enqueueBatch(int $facNitSec, string $dateFrom, string $dateTo, int $limit): array
    {
        $jobId = AuditEvent::uuidV4();
        $batchSlotClaimed = false;
        $jobInitialized = false;
        $createdAuditIds = [];
        $total = 0;
        $responseStatus = BatchJobStore::JOB_STATUS_PENDING;

        try {
            $claimed = $this->jobStore->claimBatchSlot($facNitSec, $dateFrom, $dateTo, $jobId);
            if (!$claimed) {
                throw new RuntimeException('Ya existe un batch activo para el cliente y rango solicitado', 409);
            }
            $batchSlotClaimed = true;

            $invoices = $this->invoicesModel->getInvoices($facNitSec, $dateFrom, $dateTo, $limit);

            if (!$this->jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit)) {
                throw new RuntimeException('No se pudo inicializar el job en Redis', 503);
            }
            $jobInitialized = true;

            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_CREATED,
                auditId: null,
                jobId: $jobId,
                documentId: null,
                payload: [
                    'fac_nit_sec' => (string) $facNitSec,
                    'date_from'   => $dateFrom,
                    'date_to'     => $dateTo,
                    'limit'       => $limit,
                    'total'       => count($invoices),
                ],
            ));

            foreach ($invoices as $invoice) {
                $disDetNro = isset($invoice['Dispensa']) ? trim((string) $invoice['Dispensa']) : '';
                $facSec = isset($invoice['FacSec']) ? trim((string) $invoice['FacSec']) : '';
                if ($disDetNro === '' || $facSec === '') {
                    Logger::warning('AuditBatchOrchestrator::enqueueBatch factura inválida, omitida', [
                        'job_id' => $jobId,
                        'invoice' => $invoice,
                    ]);
                    continue;
                }

                $auditId = AuditEvent::uuidV4();
                if (!$this->stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $facSec)) {
                    Logger::warning('AuditBatchOrchestrator::enqueueBatch no se pudo inicializar auditoría', [
                        'job_id' => $jobId,
                        'audit_id' => $auditId,
                    ]);
                    continue;
                }

                $createdAuditIds[] = $auditId;

                if (!$this->jobStore->registerAuditInJob($jobId, $auditId, $disDetNro)) {
                    throw new RuntimeException('No se pudo registrar la auditoría en el job', 503);
                }

                $this->publisher->publish(AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_CREATED,
                    auditId: $auditId,
                    jobId: $jobId,
                    documentId: null,
                    payload: [
                        'dis_det_nro' => $disDetNro,
                        'fac_nit_sec' => (string) $facNitSec,
                        'fac_sec'     => $facSec,
                        'source'      => 'batch',
                    ],
                ));

                $total++;
            }

            if ($total === 0) {
                $this->jobStore->patchJob($jobId, [
                    'status' => BatchJobStore::JOB_STATUS_COMPLETED,
                    'total'  => 0,
                ]);
                $this->publisher->publish(AuditEvent::create(
                    eventType: AuditEvent::TYPE_BATCH_COMPLETED,
                    auditId: null,
                    jobId: $jobId,
                    payload: [
                        'status' => BatchJobStore::JOB_STATUS_COMPLETED,
                        'total'  => 0,
                        'done'   => 0,
                        'failed' => 0,
                    ],
                ));
                $responseStatus = BatchJobStore::JOB_STATUS_COMPLETED;
            }

            return [
                'job_id' => $jobId,
                'status' => $responseStatus,
                'total'  => $total,
            ];
        } catch (RuntimeException $e) {
            $this->cleanupAsyncEnqueueState(
                $facNitSec,
                $dateFrom,
                $dateTo,
                $jobId,
                $batchSlotClaimed,
                $jobInitialized,
                $createdAuditIds
            );
            
            Logger::error('AuditBatchOrchestrator::enqueueBatch falló', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Limpia el estado parcial de encolamiento si ocurre una excepción
     * para evitar dejar jobs huérfanos o slots bloqueados.
     *
     * @param array<string> $createdAuditIds
     */
    private function cleanupAsyncEnqueueState(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        string $jobId,
        bool $batchSlotClaimed,
        bool $jobInitialized,
        array $createdAuditIds
    ): void {
        try {
            foreach ($createdAuditIds as $auditId) {
                $this->stateStore->deleteAudit($auditId);
            }
            if ($jobInitialized) {
                $this->jobStore->deleteJob($jobId);
            }
            if ($batchSlotClaimed) {
                $this->jobStore->releaseBatchSlot($facNitSec, $dateFrom, $dateTo);
            }
        } catch (\Throwable $t) {
            Logger::error('AuditBatchOrchestrator::cleanupAsyncEnqueueState falló durante rollback', [
                'job_id' => $jobId,
                'error'  => $t->getMessage(),
            ]);
        }
    }
}
