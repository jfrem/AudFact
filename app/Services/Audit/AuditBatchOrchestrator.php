<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\InvoicesModel;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\Env;
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
    public const DEFAULT_CHUNK_SIZE = 50;
    public const DEFAULT_LOCK_TTL_SECONDS = 300;
    private const MIN_FETCH_LIMIT = 50;
    private const MAX_FETCH_LIMIT = 200;

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
     * @param  string       $workerToken Token del worker para locking distribuido.
     * @param  array{date:string,disId:string,dispensa:string}|null $cursor Cursor keyset para paginación continua.
     * @param  int          $chunkIndex Índice del chunk actual (1..N).
     * @param  int          $accumulatedTotal Total de facturas aceptadas acumuladas en chunks previos.
     * @param  int                  $facNitSec   NIT del cliente/EPS
     * @param  string               $dateFrom    Fecha inicio (Y-m-d)
     * @param  string               $dateTo      Fecha fin (Y-m-d)
     * @param  int                  $limit       Máximo de facturas a procesar
     * @param  string|null          $jobId       UUID externo del job (del controller via worker).
     *                                           Si null, genera uno nuevo (backward compat).
     * @param  string               $workerToken Token del worker para locking distribuido.
     * @param  BatchChunkState|null $chunkState  Estado inmutable del chunk actual (cursor, acumulados, overrides).
     *
     * @return array{
     *     job_id:string,
     *     status:string,
     *     total:int,
     *     accepted:int,
     *     skipped_locked:int,
     *     skipped_existing:int,
     *     has_more:bool,
     *     next_cursor:array{date:string,disId:string,dispensa:string}|null,
     *     chunk_index:int,
     *     accumulated_total:int,
     *     accumulated_skipped_locked:int,
     *     accumulated_skipped_existing:int
     * }
     * @throws RuntimeException Si hay falla persistiendo estado o publicando eventos
     */
    public function enqueueBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        ?string $jobId = null,
        string $workerToken = '',
        ?BatchChunkState $chunkState = null
    ): array {
        $chunkState = $chunkState ?? BatchChunkState::initial();
        $externalJobId = $jobId !== null;
        $jobId = $jobId ?? AuditEvent::uuidV4();
        $workerToken = $workerToken !== '' ? $workerToken : AuditEvent::uuidV4();
        $jobInitialized = false;
        $publishedAnyEvent = false;
        $hasLock = false;

        $createdAuditIds = [];
        $createdReservations = [];

        // 1. Verificar idempotencia temprana si el job ya existe en Redis
        if ($externalJobId) {
            $earlyResponse = $this->resolveIdempotentEarlyResponse($jobId, $chunkState);
            if ($earlyResponse !== null) {
                return $earlyResponse;
            }
            $jobInitialized = true;
        } else {
            $this->initJobOrFail($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
            $jobInitialized = true;
        }

        // 2. Lock atómico de generación distribuida para prevenir concurrencia entre réplicas
        $lockTtl = (int) Env::get('AUDIT_BATCH_LOCK_TTL_SECONDS', self::DEFAULT_LOCK_TTL_SECONDS);
        $hasLock = $this->jobStore->claimJobGenerationLock($jobId, $workerToken, $lockTtl);
        if (!$hasLock) {
            return $this->resolveLockedConcurrentResponse($jobId, $workerToken, $chunkState);
        }

        try {
            // 3. Recolectar candidatos del chunk y reservar slots por DisId
            $chunk = $this->collectChunkCandidates(
                $jobId,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $limit,
                $chunkState,
                $createdAuditIds,
                $createdReservations
            );

            // 4. Publicación causal de eventos (batch_created en chunk 1 antes de hijas)
            $this->dispatchChunkEvents(
                $jobId,
                $chunkState->chunkIndex,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $limit,
                $chunk['chunk_total'],
                $chunk['events'],
                $publishedAnyEvent
            );

            // 5. Consolidación de métricas acumuladas
            $newAccumulatedTotal = $chunkState->accumulatedTotal + $chunk['chunk_total'];
            $newAccumulatedSkippedLocked = $chunkState->accumulatedSkippedLocked + $chunk['skipped_locked'];
            $newAccumulatedSkippedExisting = $chunkState->accumulatedSkippedExisting + $chunk['skipped_existing'];
            $isFinalChunk = !$chunk['has_more_invoices'] || $newAccumulatedTotal >= $limit;

            // 6. Transición de estado en Redis (parche provisional vs sellado definitivo)
            $responseStatus = $this->finalizeJobState(
                $jobId,
                $chunkState->chunkIndex,
                $isFinalChunk,
                $newAccumulatedTotal,
                $newAccumulatedSkippedLocked,
                $newAccumulatedSkippedExisting
            );

            return $this->buildBatchResponse(
                $jobId,
                $responseStatus,
                $newAccumulatedTotal,
                $newAccumulatedSkippedLocked,
                $newAccumulatedSkippedExisting,
                !$isFinalChunk,
                !$isFinalChunk ? $chunk['next_cursor'] : null,
                $chunkState->chunkIndex,
                $newAccumulatedTotal,
                $newAccumulatedSkippedLocked,
                $newAccumulatedSkippedExisting
            );
        } catch (RuntimeException $e) {
            $this->handleEnqueueException(
                $e,
                $jobId,
                $chunkState->chunkIndex,
                $jobInitialized,
                $externalJobId,
                $publishedAnyEvent,
                $createdAuditIds,
                $createdReservations
            );
            throw $e;
        } finally {
            if ($hasLock) {
                $this->jobStore->releaseJobGenerationLock($jobId, $workerToken);
            }
        }
    }

    private function resolveIdempotentEarlyResponse(
        string $jobId,
        BatchChunkState $chunkState
    ): ?array {
        $existing = $this->jobStore->getJob($jobId);
        if ($existing === null) {
            throw new RuntimeException("Job externo {$jobId} no encontrado en Redis", 503);
        }

        $alreadySealed = ($existing['sealed'] ?? false) === true;
        $hasAuditsOnFirstChunk = $chunkState->chunkIndex === 1 && count($existing['audits'] ?? []) > 0;

        if ($alreadySealed || $hasAuditsOnFirstChunk) {
            Logger::warning('AuditBatchOrchestrator: Job ya inicializado o sellado previamente; se omite re-generación', [
                'job_id' => $jobId,
                'chunk_index' => $chunkState->chunkIndex,
                'sealed' => $alreadySealed,
                'audits_count' => count($existing['audits'] ?? []),
            ]);

            return $this->buildBatchResponse(
                $jobId,
                (string) ($existing['status'] ?? BatchJobStore::JOB_STATUS_PROCESSING),
                (int) ($existing['total'] ?? count($existing['audits'] ?? [])),
                (int) ($existing['skipped_locked'] ?? 0),
                (int) ($existing['skipped_existing'] ?? 0),
                false,
                null,
                $chunkState->chunkIndex,
                $chunkState->accumulatedTotal,
                $chunkState->accumulatedSkippedLocked,
                $chunkState->accumulatedSkippedExisting
            );
        }

        return null;
    }

    private function resolveLockedConcurrentResponse(
        string $jobId,
        string $workerToken,
        BatchChunkState $chunkState
    ): array {
        Logger::warning('AuditBatchOrchestrator: Generación de batch en curso por otro worker; se omite concurrencia', [
            'job_id' => $jobId,
            'worker_token' => $workerToken,
        ]);
        $currentJob = $this->jobStore->getJob($jobId);

        return $this->buildBatchResponse(
            $jobId,
            (string) ($currentJob['status'] ?? BatchJobStore::JOB_STATUS_PENDING),
            (int) ($currentJob['total'] ?? 0),
            0,
            0,
            false,
            null,
            $chunkState->chunkIndex,
            $chunkState->accumulatedTotal,
            $chunkState->accumulatedSkippedLocked,
            $chunkState->accumulatedSkippedExisting
        );
    }

    /**
     * @param array<string> $createdAuditIds
     * @param array<int,array{dis_id:string,token:string}> $createdReservations
     * @return array{
     *     chunk_total: int,
     *     skipped_locked: int,
     *     skipped_existing: int,
     *     has_more_invoices: bool,
     *     next_cursor: array{date:string,disId:string,dispensa:string}|null,
     *     events: array<AuditEvent>
     * }
     */
    private function collectChunkCandidates(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        BatchChunkState $chunkState,
        array &$createdAuditIds,
        array &$createdReservations
    ): array {
        $chunkSize = $chunkState->chunkSizeOverride ?? (int) Env::get('AUDIT_BATCH_CHUNK_SIZE', self::DEFAULT_CHUNK_SIZE);
        $maxThisChunk = max(1, min($chunkSize, $limit - $chunkState->accumulatedTotal));
        $chunkTotal = 0;
        $skippedLocked = 0;
        $skippedExisting = 0;
        $hasMoreInvoices = true;
        $eventsToPublish = [];
        $cursor = $chunkState->cursor;

        while ($chunkTotal < $maxThisChunk) {
            $fetchLimit = min(max($maxThisChunk * 2, self::MIN_FETCH_LIMIT), self::MAX_FETCH_LIMIT);
            $invoices = $this->invoicesModel->getInvoicesForAuditBatch(
                $facNitSec,
                $dateFrom,
                $dateTo,
                $fetchLimit,
                $cursor
            );

            if ($invoices === []) {
                $hasMoreInvoices = false;
                break;
            }

            $previousCursor = $cursor;
            foreach ($invoices as $invoice) {
                if ($chunkTotal >= $maxThisChunk) {
                    break;
                }

                $cursor = self::cursorFromInvoice($invoice);

                $invoiceIdentity = self::resolveInvoiceIdentity($invoice, $jobId);
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

                $createdReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];

                $this->initAuditState(
                    $auditId,
                    $disDetNro,
                    $jobId,
                    $facNitSec,
                    $disId,
                    $reservationToken
                );

                $createdAuditIds[] = $auditId;

                $eventsToPublish[] = $this->buildAuditCreatedEvent(
                    $auditId,
                    $jobId,
                    $disDetNro,
                    $facNitSec,
                    $disId,
                    $reservationToken
                );

                $chunkTotal++;
            }

            if ($cursor === null || $cursor === $previousCursor) {
                $hasMoreInvoices = false;
                break;
            }
        }

        return [
            'chunk_total' => $chunkTotal,
            'skipped_locked' => $skippedLocked,
            'skipped_existing' => $skippedExisting,
            'has_more_invoices' => $hasMoreInvoices,
            'next_cursor' => $cursor,
            'events' => $eventsToPublish,
        ];
    }

    /**
     * @param array<AuditEvent> $eventsToPublish
     */
    private function dispatchChunkEvents(
        string $jobId,
        int $chunkIndex,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        int $chunkTotal,
        array $eventsToPublish,
        bool &$publishedAnyEvent
    ): void {
        if ($chunkIndex === 1 && $chunkTotal > 0) {
            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_CREATED,
                auditId: null,
                jobId: $jobId,
                payload: [
                    'fac_nit_sec' => (string) $facNitSec,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'limit' => $limit,
                    'initial_chunk_size' => $chunkTotal,
                ],
            ));
            $publishedAnyEvent = true;
        }

        foreach ($eventsToPublish as $event) {
            $this->publisher->publish($event);
            $publishedAnyEvent = true;
        }
    }

    private function finalizeJobState(
        string $jobId,
        int $chunkIndex,
        bool $isFinalChunk,
        int $newAccumulatedTotal,
        int $newAccumulatedSkippedLocked,
        int $newAccumulatedSkippedExisting
    ): string {
        if ($isFinalChunk) {
            if ($newAccumulatedTotal === 0 && $chunkIndex === 1) {
                $this->publishEmptyBatch($jobId, $newAccumulatedSkippedLocked, $newAccumulatedSkippedExisting);
                return BatchJobStore::JOB_STATUS_COMPLETED;
            }

            if (!$this->jobStore->sealJob($jobId, $newAccumulatedTotal, [
                'accepted' => $newAccumulatedTotal,
                'skipped_locked' => $newAccumulatedSkippedLocked,
                'skipped_existing' => $newAccumulatedSkippedExisting,
            ])) {
                throw new RuntimeException('No se pudo sellar el job batch en Redis', 503);
            }
            return BatchJobStore::JOB_STATUS_PENDING;
        }

        $this->jobStore->patchJob($jobId, [
            'total' => $newAccumulatedTotal,
            'accepted' => $newAccumulatedTotal,
            'skipped_locked' => $newAccumulatedSkippedLocked,
            'skipped_existing' => $newAccumulatedSkippedExisting,
        ]);

        return BatchJobStore::JOB_STATUS_PENDING;
    }

    /**
     * @param array<string> $createdAuditIds
     * @param array<int,array{dis_id:string,token:string}> $createdReservations
     */
    private function handleEnqueueException(
        RuntimeException $e,
        string $jobId,
        int $chunkIndex,
        bool $jobInitialized,
        bool $externalJobId,
        bool $publishedAnyEvent,
        array $createdAuditIds,
        array $createdReservations
    ): void {
        if ($publishedAnyEvent) {
            Logger::error('AuditBatchOrchestrator::enqueueBatch falló después de publicar eventos; no se ejecuta rollback destructivo', [
                'job_id' => $jobId,
                'chunk_index' => $chunkIndex,
                'published_events_started' => true,
                'error' => $e->getMessage(),
            ]);
        } else {
            $this->cleanupAsyncEnqueueState(
                $jobId,
                $jobInitialized,
                $externalJobId,
                $chunkIndex,
                $createdAuditIds,
                $createdReservations
            );
        }

        Logger::error('AuditBatchOrchestrator::enqueueBatch falló', [
            'job_id' => $jobId,
            'chunk_index' => $chunkIndex,
            'error' => $e->getMessage(),
        ]);
    }

    private function initJobOrFail(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): void
    {
        if (!$this->jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit)) {
            throw new RuntimeException('No se pudo inicializar el job en Redis', 503);
        }
    }

    /**
     * Resuelve y valida la identidad obligatoria de una dispensa/factura candidata.
     *
     * @param  array<string,mixed>  $invoice
     * @return array{dis_det_nro:string,dis_id:string}|null
     */
    private static function resolveInvoiceIdentity(array $invoice, string $jobId): ?array
    {
        $disDetNro = isset($invoice['Dispensa']) ? trim((string) $invoice['Dispensa']) : '';
        $disId = isset($invoice['DisId']) ? trim((string) $invoice['DisId']) : '';

        if ($disDetNro === '' || $disId === '') {
            Logger::warning('AuditBatchOrchestrator::enqueueBatch factura inválida, omitida', [
                'job_id' => $jobId,
                'invoice' => $invoice,
            ]);
            return null;
        }

        return [
            'dis_det_nro' => $disDetNro,
            'dis_id' => $disId,
        ];
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

    private function initAuditState(
        string $auditId,
        string $disDetNro,
        string $jobId,
        int $facNitSec,
        string $disId,
        string $reservationToken
    ): void {
        if (!$this->stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $disId)) {
            Logger::error('AuditBatchOrchestrator::enqueueBatch no se pudo inicializar auditoría', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
            ]);
            throw new RuntimeException('No se pudo inicializar la auditoría en Redis', 503);
        }

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
     * @param array{date:string,disId:string,dispensa:string}|null $nextCursor
     * @return array{
     *     job_id:string,
     *     status:string,
     *     total:int,
     *     accepted:int,
     *     skipped_locked:int,
     *     skipped_existing:int,
     *     has_more:bool,
     *     next_cursor:array{date:string,disId:string,dispensa:string}|null,
     *     chunk_index:int,
     *     accumulated_total:int,
     *     accumulated_skipped_locked:int,
     *     accumulated_skipped_existing:int
     * }
     */
    private function buildBatchResponse(
        string $jobId,
        string $status,
        int $total,
        int $skippedLocked,
        int $skippedExisting,
        bool $hasMore = false,
        ?array $nextCursor = null,
        int $chunkIndex = 1,
        int $accumulatedTotal = 0,
        int $accumulatedSkippedLocked = 0,
        int $accumulatedSkippedExisting = 0
    ): array {
        return [
            'job_id' => $jobId,
            'status' => $status,
            'total' => $total,
            'accepted' => $total,
            'skipped_locked' => $skippedLocked,
            'skipped_existing' => $skippedExisting,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'chunk_index' => $chunkIndex,
            'accumulated_total' => $accumulatedTotal,
            'accumulated_skipped_locked' => $accumulatedSkippedLocked,
            'accumulated_skipped_existing' => $accumulatedSkippedExisting,
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
        bool $externalJobId,
        int $chunkIndex,
        array $createdAuditIds,
        array $createdReservations
    ): void {
        try {
            foreach ($createdAuditIds as $auditId) {
                $this->stateStore->deleteAudit($auditId);
            }
            // Salvaguarda: NUNCA borrar el job en Redis si fue creado externamente o si ya pasó del primer chunk
            if ($jobInitialized && !$externalJobId && $chunkIndex === 1) {
                $this->jobStore->deleteJob($jobId);
            }
            foreach ($createdReservations as $reservation) {
                $this->jobStore->releaseAuditReservation($reservation['dis_id'], $reservation['token']);
            }
        } catch (\Throwable $t) {
            Logger::error('AuditBatchOrchestrator::cleanupAsyncEnqueueState falló durante rollback', [
                'job_id' => $jobId,
                'chunk_index' => $chunkIndex,
                'error'  => $t->getMessage(),
            ]);
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
