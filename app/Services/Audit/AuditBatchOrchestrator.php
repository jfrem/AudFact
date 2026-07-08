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
 * Centraliza la lógica de encolamiento, reservas idempotentes por DisId,
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
     * @param  int          $facNitSec  NIT del cliente/EPS
     * @param  string       $dateFrom   Fecha inicio (Y-m-d)
     * @param  string       $dateTo     Fecha fin (Y-m-d)
     * @param  int          $limit      Máximo de facturas a procesar
     * @param  string|null  $jobId      UUID externo del job (del controller via worker).
     *                                  Si null, genera uno nuevo (backward compat).
     *
     * @return array{job_id:string, status:string, total:int, accepted:int, skipped_locked:int, skipped_existing:int}
     * @throws RuntimeException Si hay falla persistiendo estado o publicando eventos
     */
    public function enqueueBatch(int $facNitSec, string $dateFrom, string $dateTo, int $limit, ?string $jobId = null): array
    {
        $externalJobId = $jobId !== null;
        $jobId = $jobId ?? AuditEvent::uuidV4();
        $jobInitialized = false;
        $createdAuditIds = [];
        $createdReservations = [];
        $eventsToPublish = [];
        $total = 0;
        $skippedLocked = 0;
        $skippedExisting = 0;
        $responseStatus = BatchJobStore::JOB_STATUS_PENDING;
        $publishedAnyEvent = false;

        try {
            if ($externalJobId) {
                // Job ya inicializado por el controller — verificar que existe
                $existing = $this->jobStore->getJob($jobId);
                if ($existing === null) {
                    throw new RuntimeException("Job externo {$jobId} no encontrado en Redis", 503);
                }
                $jobInitialized = true;
            } else {
                $this->initJobOrFail($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
                $jobInitialized = true;
            }

            $cursor = null;
            $pageLimit = self::resolvePageLimit($limit);

            while ($total < $limit) {
                $invoices = $this->invoicesModel->getInvoicesForAuditBatch(
                    $facNitSec,
                    $dateFrom,
                    $dateTo,
                    $pageLimit,
                    $cursor
                );

                if ($invoices === []) {
                    break;
                }

                foreach ($invoices as $invoice) {
                    $cursor = self::cursorFromInvoice($invoice);
                    if ($total >= $limit) {
                        break;
                    }

                    $invoiceIdentity = $this->resolveInvoiceIdentity($invoice, $jobId);
                    if ($invoiceIdentity === null) {
                        continue;
                    }

                    $disDetNro = $invoiceIdentity['dis_det_nro'];
                    $disId = $invoiceIdentity['dis_id'];

                    $auditId = AuditEvent::uuidV4();
                    $reservationToken = AuditEvent::uuidV4();
                    if (!$this->jobStore->claimAuditReservation(
                        $disId,
                        $reservationToken,
                        $this->buildReservationPayload($jobId, $auditId, $disDetNro, $facNitSec, $disId)
                    )) {
                        $skippedLocked++;
                        continue;
                    }

                    $reservation = ['dis_id' => $disId, 'token' => $reservationToken];
                    $createdReservations[] = $reservation;

                    $this->initAuditState(
                        $auditId,
                        $disDetNro,
                        $jobId,
                        $facNitSec,
                        $disId,
                        $reservationToken,
                        $reservation,
                        $createdReservations,
                        $createdAuditIds
                    );

                    $eventsToPublish[] = $this->buildAuditCreatedEvent(
                        $auditId,
                        $jobId,
                        $disDetNro,
                        $facNitSec,
                        $disId,
                        $reservationToken
                    );

                    $total++;
                }

                if ($cursor === null) {
                    break;
                }
            }

            if ($total === 0) {
                $this->publishEmptyBatch($jobId, $skippedLocked, $skippedExisting);
                $responseStatus = BatchJobStore::JOB_STATUS_COMPLETED;
            } else {
                $this->sealAndPublishBatch(
                    $jobId,
                    $facNitSec,
                    $dateFrom,
                    $dateTo,
                    $limit,
                    $total,
                    $skippedLocked,
                    $skippedExisting,
                    $eventsToPublish,
                    $publishedAnyEvent
                );
            }

            return $this->buildBatchResponse($jobId, $responseStatus, $total, $skippedLocked, $skippedExisting);
        } catch (RuntimeException $e) {
            if ($publishedAnyEvent) {
                Logger::error('AuditBatchOrchestrator::enqueueBatch falló después de publicar eventos; no se ejecuta rollback destructivo', [
                    'job_id' => $jobId,
                    'published_events_started' => true,
                    'error' => $e->getMessage(),
                ]);
            } else {
                $this->cleanupAsyncEnqueueState(
                    $jobId,
                    $jobInitialized,
                    $createdAuditIds,
                    $createdReservations
                );
            }
            
            Logger::error('AuditBatchOrchestrator::enqueueBatch falló', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    private function initJobOrFail(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): void
    {
        if (!$this->jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit)) {
            throw new RuntimeException('No se pudo inicializar el job en Redis', 503);
        }
    }

    private static function resolvePageLimit(int $limit): int
    {
        return min(max($limit * 2, 100), 1000);
    }

    private function resolveInvoiceIdentity(array $invoice, string $jobId): ?array
    {
        $invoiceIdentity = self::invoiceIdentity($invoice);
        if ($invoiceIdentity === null) {
            Logger::warning('AuditBatchOrchestrator::enqueueBatch factura inválida, omitida', [
                'job_id' => $jobId,
                'invoice' => $invoice,
            ]);
            return null;
        }

        return $invoiceIdentity;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildReservationPayload(
        string $jobId,
        string $auditId,
        string $disDetNro,
        int $facNitSec,
        string $disId
    ): array {
        return [
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'dis_det_nro' => $disDetNro,
            'fac_nit_sec' => (string) $facNitSec,
            'dis_id' => $disId,
            'source' => 'batch',
        ];
    }

    /**
     * @param  array<int,array{dis_id:string,token:string}>  $createdReservations
     */
    private function initAuditState(
        string $auditId,
        string $disDetNro,
        string $jobId,
        int $facNitSec,
        string $disId,
        string $reservationToken,
        array $reservation,
        array &$createdReservations,
        array &$createdAuditIds
    ): void {
        if (!$this->stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $disId)) {
            Logger::error('AuditBatchOrchestrator::enqueueBatch no se pudo inicializar auditoría', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
            ]);
            $this->jobStore->releaseAuditReservation($disId, $reservationToken);
            self::forgetReservation($reservation, $createdReservations);
            throw new RuntimeException('No se pudo inicializar la auditoría en Redis', 503);
        }

        $createdAuditIds[] = $auditId;

        if (!$this->stateStore->patchAudit($auditId, ['reservation_token' => $reservationToken])) {
            throw new RuntimeException('No se pudo asociar la reserva a la auditoría', 503);
        }

        if (!$this->jobStore->registerAuditInJob($jobId, $auditId, $disDetNro, $disId, $reservationToken)) {
            throw new RuntimeException('No se pudo registrar la auditoría en el job', 503);
        }

    }

    private function buildAuditCreatedEvent(
        string $auditId,
        string $jobId,
        string $disDetNro,
        int $facNitSec,
        string $disId,
        string $reservationToken
    ): AuditEvent {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            documentId: null,
            payload: [
                'dis_det_nro' => $disDetNro,
                'fac_nit_sec' => (string) $facNitSec,
                'dis_id' => $disId,
                'reservation_token' => $reservationToken,
                'source' => 'batch',
            ],
        );
    }

    private function publishEmptyBatch(string $jobId, int $skippedLocked, int $skippedExisting): void
    {
        if (!$this->jobStore->patchJob($jobId, [
            'status' => BatchJobStore::JOB_STATUS_COMPLETED,
            'sealed' => true,
            'total' => 0,
            'accepted' => 0,
            'skipped_locked' => $skippedLocked,
            'skipped_existing' => $skippedExisting,
        ])) {
            throw new RuntimeException('No se pudo cerrar el job batch vacío en Redis', 503);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_COMPLETED,
            auditId: null,
            jobId: $jobId,
            payload: [
                'status' => BatchJobStore::JOB_STATUS_COMPLETED,
                'total' => 0,
                'done' => 0,
                'failed' => 0,
                'accepted' => 0,
                'skipped_locked' => $skippedLocked,
                'skipped_existing' => $skippedExisting,
            ],
        ));
    }

    /**
     * @param  AuditEvent[]  $eventsToPublish
     */
    private function sealAndPublishBatch(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        int $total,
        int $skippedLocked,
        int $skippedExisting,
        array $eventsToPublish,
        bool &$publishedAnyEvent
    ): void {
        if (!$this->jobStore->sealJob($jobId, $total, [
            'accepted' => $total,
            'skipped_locked' => $skippedLocked,
            'skipped_existing' => $skippedExisting,
        ])) {
            throw new RuntimeException('No se pudo sellar el job batch en Redis', 503);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_CREATED,
            auditId: null,
            jobId: $jobId,
            documentId: null,
            payload: [
                'fac_nit_sec' => (string) $facNitSec,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'total' => $total,
                'accepted' => $total,
                'skipped_locked' => $skippedLocked,
                'skipped_existing' => $skippedExisting,
            ],
        ));
        $publishedAnyEvent = true;

        foreach ($eventsToPublish as $event) {
            $this->publisher->publish($event);
            $publishedAnyEvent = true;
        }
    }

    private function buildBatchResponse(
        string $jobId,
        string $status,
        int $total,
        int $skippedLocked,
        int $skippedExisting
    ): array {
        return [
            'job_id' => $jobId,
            'status' => $status,
            'total' => $total,
            'accepted' => $total,
            'skipped_locked' => $skippedLocked,
            'skipped_existing' => $skippedExisting,
        ];
    }

    /**
     * Limpia el estado parcial de encolamiento si ocurre una excepción
     * para evitar dejar jobs huérfanos o slots bloqueados.
     *
     * @param array<string> $createdAuditIds
     * @param array<int,array{dis_id:string,token:string}> $createdReservations
     */
    private function cleanupAsyncEnqueueState(
        string $jobId,
        bool $jobInitialized,
        array $createdAuditIds,
        array $createdReservations
    ): void {
        try {
            foreach ($createdAuditIds as $auditId) {
                $this->stateStore->deleteAudit($auditId);
            }
            if ($jobInitialized) {
                $this->jobStore->deleteJob($jobId);
            }
            foreach ($createdReservations as $reservation) {
                $this->jobStore->releaseAuditReservation($reservation['dis_id'], $reservation['token']);
            }
        } catch (\Throwable $t) {
            Logger::error('AuditBatchOrchestrator::cleanupAsyncEnqueueState falló durante rollback', [
                'job_id' => $jobId,
                'error'  => $t->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $invoice
     * @return array{dis_det_nro:string,dis_id:string}|null
     */
    private static function invoiceIdentity(array $invoice): ?array
    {
        $disDetNro = isset($invoice['Dispensa']) ? trim((string) $invoice['Dispensa']) : '';
        $disId = isset($invoice['DisId']) ? trim((string) $invoice['DisId']) : '';

        if ($disDetNro === '' || $disId === '') {
            return null;
        }

        return [
            'dis_det_nro' => $disDetNro,
            'dis_id' => $disId,
        ];
    }

    /**
     * @param  array{dis_id:string,token:string}  $reservation
     * @param  array<int,array{dis_id:string,token:string}>  $reservations
     */
    private static function forgetReservation(array $reservation, array &$reservations): void
    {
        foreach ($reservations as $index => $tracked) {
            if ($tracked['dis_id'] === $reservation['dis_id'] && $tracked['token'] === $reservation['token']) {
                unset($reservations[$index]);
                return;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $invoice
     * @return array{date:string,disId:string,dispensa:string}|null
     */
    private static function cursorFromInvoice(array $invoice): ?array
    {
        $date = trim((string) ($invoice['DisFecSol'] ?? ''));
        $disId = trim((string) ($invoice['DisId'] ?? ''));
        $dispensa = trim((string) ($invoice['Dispensa'] ?? ''));

        if ($date === '' || $disId === '' || $dispensa === '') {
            return null;
        }

        return [
            'date' => $date,
            'disId' => $disId,
            'dispensa' => $dispensa,
        ];
    }
}
