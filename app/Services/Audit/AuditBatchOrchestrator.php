<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\InvoicesModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobGenerationFailedException;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use Core\Env;
use Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Servicio de orquestación de lotes de auditoría (SDD-BATCH-001 v1.2).
 *
 * Congela UNA sola vez las candidatas del job en Redis (`job:{id}:candidates`) y las procesa por chunks, con:
 * - lock de generación con fencing por `generation_epoch` (`R-EPOCH`): solo el propietario vigente muta el job;
 * - UUID deterministas por `(jobId, disDetNro)` (`R-PREPUB`): una caída entre registro y publicación se recupera sin
 *   duplicar ni perder auditorías;
 * - reservas `1:1` por `DisDetNro` (`INV-04`) y checkpoints idempotentes por chunk (`R-CONT`);
 * - compensación segura (`W-F2-B1`): jamás borra estado/reservas que un propietario vigente pueda reutilizar.
 */
final class AuditBatchOrchestrator
{
    public const DEFAULT_CHUNK_SIZE = 50;
    public const DEFAULT_LOCK_TTL_SECONDS = 1800;
    /** Techo de candidatas congeladas por job (alineado con `InvoicesModel::BATCH_MAX_FREEZE_LIMIT`). */
    public const MAX_FREEZE_LIMIT = 50000;

    public function __construct(
        private readonly AuditStateStore $stateStore,
        private readonly BatchJobStore $jobStore,
        private readonly AuditEventPublisher $publisher,
        private readonly InvoicesModel $invoicesModel
    ) {}

    /**
     * Enmascara el NIT para logs (`R-PII`): `***` + 3 últimos dígitos.
     */
    public static function maskNit(int|string $facNitSec): string
    {
        return '***' . substr((string) $facNitSec, -3);
    }

    /**
     * Procesa un chunk de un lote de dispensas para ser auditado de forma asíncrona.
     *
     * @param  int                  $facNitSec    NIT del cliente/EPS
     * @param  string               $dateFrom     Fecha inicio (Y-m-d)
     * @param  string               $dateTo       Fecha fin (Y-m-d)
     * @param  int                  $limit        Máximo de dispensas a aceptar en todo el job
     * @param  string|null          $jobId        UUID externo del job (creado por controller/cron). Si es null, el
     *                                            orquestador crea el job (compatibilidad) y lo compensa si falla.
     * @param  string               $workerToken  Token del worker para el lock de generación.
     * @param  BatchChunkState|null $chunkState   Estado del chunk actual (cursor, acumulados, offset, tamaño).
     *
     * @return array{
     *     job_id:string,
     *     status:string,
     *     total:int,
     *     accepted:int,
     *     skipped_locked:int,
     *     skipped_existing:int,
     *     has_more:bool,
     *     next_cursor:array<string,mixed>|null,
     *     chunk_index:int,
     *     accumulated_total:int,
     *     accumulated_skipped_locked:int,
     *     accumulated_skipped_existing:int,
     *     next_candidate_offset?:int,
     *     generation_epoch?:int,
     *     locked_busy?:bool
     * }
     * @throws JobGenerationFailedException Toda excepción posterior a adquirir el lock (porta el `epoch` para la DLQ).
     * @throws RuntimeException Antes de adquirir el lock (job inexistente `503`, lock `MISSING`, job no creado).
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

        // Registro de lo creado en ESTE intento (insumo de la compensación, W-F2-B1).
        $run = ['published' => false, 'audits' => [], 'reservations' => [], 'registered' => []];

        // 1. Idempotencia temprana (job externo) o creación del job (modo compatibilidad).
        if ($externalJobId) {
            $earlyResponse = $this->resolveIdempotentEarlyResponse($jobId, $chunkState);
            if ($earlyResponse !== null) {
                return $earlyResponse;
            }
        } else {
            $this->initJobOrFail($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        }

        // 2. Lock de generación con fencing (R-EPOCH).
        $lockTtl = AuditExecutionConfig::batchLockTtlSeconds();
        try {
            $lockResult = $this->jobStore->claimJobGenerationFencedLock($jobId, $workerToken, $chunkState->chunkIndex, $lockTtl);
        } catch (Throwable $claimError) {
            if (!$externalJobId) {
                $this->bestEffortDeleteJob($jobId);
            }
            throw $claimError;
        }

        if ($lockResult->status === JobLockClaimStatus::BUSY) {
            return $this->resolveLockedConcurrentResponse($jobId, $workerToken, $chunkState);
        }
        if ($lockResult->status === JobLockClaimStatus::TERMINAL) {
            return $this->buildTerminalResponse($jobId, $chunkState);
        }
        if ($lockResult->status === JobLockClaimStatus::MISSING) {
            throw new RuntimeException("Job {$jobId} no encontrado al adquirir lock de generación", 503);
        }

        $epoch = (int) $lockResult->epoch;

        try {
            return $this->generateChunk(
                $jobId,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $limit,
                $chunkState,
                $workerToken,
                $epoch,
                $lockTtl,
                $run
            );
        } catch (Throwable $e) {
            $this->handleEnqueueException($e, $jobId, $chunkState->chunkIndex, $externalJobId, $run, $epoch);

            // INV-02: la DLQ solo puede cerrar el job si conoce el epoch del propietario que falló.
            throw $e instanceof JobGenerationFailedException
                ? $e
                : new JobGenerationFailedException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e, $epoch);
        } finally {
            try {
                $this->jobStore->releaseJobGenerationLock($jobId, $workerToken, $epoch);
            } catch (Throwable $releaseError) {
                Logger::warning('AuditBatchOrchestrator: no se pudo liberar el lock de generación (expirará por TTL)', [
                    'job_id' => $jobId,
                    'error' => $releaseError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Respuesta temprana (sin lock ni SQL) cuando el chunk ya fue procesado o el job está cerrado.
     *
     * @return array<string,mixed>|null `null` si el chunk debe procesarse (o recuperar su continuación bajo lock).
     */
    private function resolveIdempotentEarlyResponse(string $jobId, BatchChunkState $chunkState): ?array
    {
        $summary = $this->jobStore->getJobSummary($jobId);
        if ($summary === null) {
            throw new RuntimeException("Job externo {$jobId} no encontrado en Redis", 503);
        }
        $summary['job_id'] = $summary['job_id'] ?? $jobId;

        $chunkIndex = $chunkState->chunkIndex;
        $committed = (int) ($summary['last_committed_chunk'] ?? 0);
        $continuationDispatched = (int) ($summary['last_continuation_dispatched_chunk'] ?? 0);

        $closed = ($summary['sealed'] ?? false) === true
            || ($summary['reaping'] ?? false) === true
            || self::isTerminalJobStatus((string) ($summary['status'] ?? ''));
        $alreadyDone = $committed >= $chunkIndex && $continuationDispatched >= $chunkIndex;

        if ($closed || $alreadyDone) {
            Logger::warning('AuditBatchOrchestrator: chunk ya procesado o job cerrado; se omite re-generación', [
                'job_id' => $jobId,
                'chunk_index' => $chunkIndex,
                'sealed' => ($summary['sealed'] ?? false) === true,
                'status' => (string) ($summary['status'] ?? ''),
            ]);

            return $this->responseFromSummary($summary, $chunkState);
        }

        // committed >= chunk && continuación pendiente && !sealed: se recupera bajo lock (R-CONT).
        return null;
    }

    /**
     * Respuesta ante `JobLockClaimStatus::BUSY`: otro worker genera; el consumidor debe retener el mensaje en PEL.
     *
     * @return array<string,mixed>
     */
    private function resolveLockedConcurrentResponse(string $jobId, string $workerToken, BatchChunkState $chunkState): array
    {
        Logger::warning('AuditBatchOrchestrator: generación de batch en curso por otro worker; se difiere el chunk', [
            'job_id' => $jobId,
            'worker_token' => $workerToken,
            'chunk_index' => $chunkState->chunkIndex,
        ]);
        $summary = $this->jobStore->getJobSummary($jobId) ?? ['status' => BatchJobStore::JOB_STATUS_PENDING];
        $summary['job_id'] = $summary['job_id'] ?? $jobId;

        return $this->responseFromSummary($summary, $chunkState, extra: ['locked_busy' => true]);
    }

    /**
     * Respuesta terminal NO nula (`JobLockClaimStatus::TERMINAL`, `V-F2-B4`): totales autoritativos del estado.
     *
     * @return array<string,mixed>
     */
    private function buildTerminalResponse(string $jobId, BatchChunkState $chunkState): array
    {
        $summary = $this->jobStore->getJobSummary($jobId);
        if ($summary === null) {
            throw new RuntimeException("Job {$jobId} no encontrado al resolver respuesta terminal", 503);
        }
        $summary['job_id'] = $summary['job_id'] ?? $jobId;

        return $this->responseFromSummary($summary, $chunkState);
    }

    /**
     * Genera un chunk bajo lock con `$epoch` vigente.
     *
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     * @return array<string,mixed>
     */
    private function generateChunk(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        BatchChunkState $chunkState,
        string $workerToken,
        int $epoch,
        int $lockTtl,
        array &$run
    ): array {
        $summary = $this->requireSummary($jobId);

        // 3. Continuación huérfana: el chunk ya fue comprometido pero su continuación no se confirmó (R-CONT).
        if ($this->hasOrphanContinuation($summary, $chunkState->chunkIndex)) {
            return $this->responseFromSummary(
                $summary,
                $chunkState,
                true,
                is_array($summary['last_cursor'] ?? null) ? $summary['last_cursor'] : null,
                ['next_candidate_offset' => (int) ($summary['next_candidate_offset'] ?? 0), 'generation_epoch' => $epoch]
            );
        }

        // 4. Congelación SQL única (H1).
        if (($summary['candidates_frozen'] ?? false) !== true) {
            $emptyResponse = $this->freezeCandidates(
                $jobId,
                $facNitSec,
                $dateFrom,
                $dateTo,
                $limit,
                $chunkState,
                $workerToken,
                $epoch,
                $lockTtl,
                $summary
            );
            if ($emptyResponse !== null) {
                return $emptyResponse;
            }
            $summary = $this->requireSummary($jobId);
        }

        // 5. Recolección idempotente y recuperación pre/post-publicación (R-PREPUB).
        $candidatesCount = (int) ($summary['candidates_count'] ?? 0);
        $startOffset = max($chunkState->candidateOffset, (int) ($summary['next_candidate_offset'] ?? 0));
        // La lista congelada ya está acotada por `limit` (limit - accepted al congelar): el límite no se re-evalúa por
        // slice. Re-evaluarlo con `accepted` leído al inicio del chunk dejaría sin publicar las filas registradas por
        // un intento previo caído (R-PREPUB) cuando `accepted == limit`.
        $remainingCandidates = $candidatesCount - $startOffset;
        $chunkSize = max(1, $chunkState->chunkSizeOverride
            ?? (int) Env::get('AUDIT_BATCH_CHUNK_SIZE', self::DEFAULT_CHUNK_SIZE));
        $slice = $remainingCandidates > 0
            ? $this->jobStore->readFrozenCandidates($jobId, $startOffset, min($chunkSize, $remainingCandidates))
            : [];

        $chunkSkippedLocked = 0;
        $chunkSkippedExisting = 0;
        /** @var array<int,array{event:AuditEvent,dis_det_nro:string,audit_id:string}> $pendingPublish */
        $pendingPublish = [];

        foreach ($slice as $row) {
            $identity = self::resolveInvoiceIdentity($row, $jobId);
            if ($identity === null) {
                continue;
            }
            $disDetNro = $identity['dis_det_nro'];
            $disId = $identity['dis_id'];

            $auditId = AuditEvent::deterministicUuidV4("batch-audit:{$jobId}:{$disDetNro}");
            $reservationToken = AuditEvent::deterministicUuidV4("batch-res:{$jobId}:{$disDetNro}");
            $entry = $this->jobStore->getBatchDispensaEntry($jobId, $disDetNro);

            if ($entry !== null && ($entry['dispatched'] ?? false) === true) {
                continue; // ya publicada: no toca reservas ni cuenta skipped_locked
            }

            if ($entry !== null) {
                // Caída previa entre registro y publicación: se reutiliza la auditoría registrada.
                $auditId = ($entry['audit_id'] ?? '') !== '' ? (string) $entry['audit_id'] : $auditId;
                $audit = $this->stateStore->getAudit($auditId);
                if ($audit === null) {
                    // W-F2-B1: jamás se publica audit_created sin audit:{id}:state.
                    $token = $this->reassertReservation($jobId, $auditId, $disDetNro, $facNitSec, $disId, $reservationToken, $run);
                    if ($token === null) {
                        Logger::warning('batch_prepub_reservation_lost', ['job_id' => $jobId, 'audit_id' => $auditId]);
                        continue;
                    }
                    $this->initAuditState($auditId, $disDetNro, $jobId, $facNitSec, $disId, $token, $epoch, $run);
                } else {
                    $token = (string) ($audit['reservation_token'] ?? '') !== ''
                        ? (string) $audit['reservation_token']
                        : $reservationToken;
                }
                $pendingPublish[] = $this->pendingAuditEvent($auditId, $jobId, $disDetNro, $facNitSec, $disId, $token);
                continue;
            }

            // Fila nueva: reserva 1:1 por DisDetNro.
            $payload = $this->buildReservationPayload($jobId, $auditId, $disDetNro, $facNitSec, $disId);
            if ($this->jobStore->claimAuditReservation($disDetNro, $reservationToken, $payload)) {
                $run['reservations'][] = ['dis_det_nro' => $disDetNro, 'token' => $reservationToken, 'audit_id' => $auditId];
                $token = $reservationToken;
            } else {
                $existing = $this->jobStore->getAuditReservation($disDetNro);
                if ($existing === null
                    || (string) ($existing['job_id'] ?? '') !== $jobId
                    || (string) ($existing['audit_id'] ?? '') !== $auditId
                ) {
                    $chunkSkippedLocked++; // reservada por otro job o por /audit/single
                    continue;
                }
                $token = (string) ($existing['token'] ?? '') !== '' ? (string) $existing['token'] : $reservationToken;
            }

            $this->initAuditState($auditId, $disDetNro, $jobId, $facNitSec, $disId, $token, $epoch, $run);
            $pendingPublish[] = $this->pendingAuditEvent($auditId, $jobId, $disDetNro, $facNitSec, $disId, $token);
        }

        // 6. Publicación causal y confirmación dispatched = true.
        $this->publishChunk($jobId, $facNitSec, $dateFrom, $dateTo, $limit, $epoch, $summary, $pendingPublish, $run);

        // 7. Cierre final o checkpoint intermedio.
        $newOffset = $startOffset + count($slice);
        $fresh = $this->requireSummary($jobId);
        $authoritativeAccepted = (int) ($fresh['accepted'] ?? 0);
        $isFinalChunk = $newOffset >= $candidatesCount || $authoritativeAccepted >= $limit || $slice === [];

        if ($isFinalChunk) {
            if (!$this->jobStore->sealJob($jobId, $chunkSkippedLocked, $chunkSkippedExisting, $epoch, $chunkState->chunkIndex, $newOffset)) {
                throw new RuntimeException('generation_fence_stale_on_seal', 409);
            }

            return $this->responseFromSummary($this->requireSummary($jobId), $chunkState);
        }

        $lastRow = end($slice);
        $nextCursor = is_array($lastRow) ? self::cursorFromInvoice($lastRow) : null;
        if ($nextCursor !== null) {
            $nextCursor['offset'] = $newOffset;
        }

        if (!$this->jobStore->commitChunkCheckpoint(
            $jobId,
            $chunkState->chunkIndex,
            $newOffset,
            $chunkSkippedLocked,
            $chunkSkippedExisting,
            $epoch,
            $nextCursor
        )) {
            throw new RuntimeException('generation_fence_stale_on_checkpoint', 409);
        }
        $this->renewLockOrFail($jobId, $workerToken, $epoch, $lockTtl);

        return $this->responseFromSummary(
            $this->requireSummary($jobId),
            $chunkState,
            true,
            $nextCursor,
            ['next_candidate_offset' => $newOffset, 'generation_epoch' => $epoch]
        );
    }

    /**
     * Ejecuta la consulta SQL de congelación (una sola vez por job) y persiste las candidatas.
     *
     * @param array<string,mixed> $summary
     * @return array<string,mixed>|null Respuesta final si el snapshot es vacío (job cerrado); `null` si quedó congelado.
     */
    private function freezeCandidates(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        BatchChunkState $chunkState,
        string $workerToken,
        int $epoch,
        int $lockTtl,
        array $summary
    ): ?array {
        // Permite migrar y completar jobs v1 en curso sin re-procesar páginas previas.
        $freezeCursor = ($chunkState->chunkIndex > 1 && $chunkState->cursor !== null) ? $chunkState->cursor : null;
        $accepted = (int) ($summary['accepted'] ?? 0);

        // R-SQLTO: el lock no se puede renovar durante execute(); se renueva antes y después.
        $this->renewLockOrFail($jobId, $workerToken, $epoch, $lockTtl);
        $sqlStartNs = hrtime(true);
        $snapshot = $this->invoicesModel->getInvoicesForAuditBatch(
            $facNitSec,
            $dateFrom,
            $dateTo,
            max(1, min($limit, self::MAX_FREEZE_LIMIT) - $accepted),
            $freezeCursor,
            $jobId,
            AuditExecutionConfig::freezeQueryTimeoutSeconds()
        );
        $sqlMs = (int) round((hrtime(true) - $sqlStartNs) / 1_000_000);

        $logContext = [
            'job_id' => $jobId,
            'fac_nit_sec_masked' => self::maskNit($facNitSec),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
            'candidates_count' => count($snapshot),
            'batch_generation_sql_ms' => $sqlMs,
        ];
        Logger::info('AuditBatchOrchestrator: snapshot SQL batch congelado', $logContext);
        if (count($snapshot) === self::MAX_FREEZE_LIMIT) {
            Logger::warning('batch_freeze_limit_reached', $logContext);
        }
        $this->renewLockOrFail($jobId, $workerToken, $epoch, $lockTtl);

        if ($snapshot === []) {
            if ($accepted === 0 && $chunkState->chunkIndex === 1) {
                if (!$this->jobStore->publishEmptyBatch($jobId, 0, 0, $epoch)) {
                    throw new RuntimeException('generation_fence_stale_on_empty_batch', 409);
                }

                return $this->responseFromSummary($this->requireSummary($jobId), $chunkState);
            }

            // Job v1 migrado (chunkIndex > 1): publishEmptyBatch sobrescribiría a 0 los skipped_* acumulados (R4-F2-M2).
            $offset = max($chunkState->candidateOffset, (int) ($summary['next_candidate_offset'] ?? 0));
            if (!$this->jobStore->sealJob($jobId, 0, 0, $epoch, $chunkState->chunkIndex, $offset)) {
                throw new RuntimeException('generation_fence_stale_on_seal', 409);
            }

            return $this->responseFromSummary($this->requireSummary($jobId), $chunkState);
        }

        $compact = array_map(static fn (array $row): array => [
            'NitSec' => $row['NitSec'] ?? null,
            'DisId' => $row['DisId'] ?? null,
            'Dispensa' => $row['Dispensa'] ?? null,
            'DisFecSol' => $row['DisFecSol'] ?? null,
        ], $snapshot);
        unset($snapshot);

        if (!$this->jobStore->storeFrozenCandidates($jobId, $compact, $epoch)) {
            throw new RuntimeException('generation_fence_stale_or_job_missing', 409);
        }

        return null;
    }

    /**
     * Publica `batch_created` (primer chunk que acepta) y los `audit_created` pendientes, y confirma `dispatched`.
     *
     * @param array<string,mixed> $summary
     * @param array<int,array{event:AuditEvent,dis_det_nro:string,audit_id:string}> $pendingPublish
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     */
    private function publishChunk(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit,
        int $epoch,
        array $summary,
        array $pendingPublish,
        array &$run
    ): void {
        if ($pendingPublish === []) {
            return;
        }

        $batchCreatedNow = false;
        if (($summary['batch_created_published'] ?? false) !== true) {
            // event_id determinista + batch_created_published evitan duplicados (W-F2-M4).
            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_CREATED,
                auditId: null,
                jobId: $jobId,
                payload: [
                    'fac_nit_sec' => (string) $facNitSec,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'limit' => $limit,
                    'initial_chunk_size' => count($pendingPublish),
                ],
                eventId: AuditEvent::deterministicUuidV4("batch-created:{$jobId}"),
            ));
            $run['published'] = true;
            $batchCreatedNow = true;
        }

        $pairs = [];
        foreach ($pendingPublish as $pending) {
            $this->publisher->publish($pending['event']);
            $run['published'] = true;
            $pairs[$pending['dis_det_nro']] = $pending['audit_id'];
        }

        if (!$this->jobStore->markAuditsDispatched($jobId, $pairs, $batchCreatedNow, $epoch)) {
            throw new RuntimeException('generation_fence_stale_on_dispatch', 409);
        }
    }

    /**
     * Re-asegura la reserva de una auditoría ya registrada cuyo estado desapareció (`W-F2-B1`).
     *
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     * @return string|null Token de la reserva vigente, o `null` si pertenece a otro job/single.
     */
    private function reassertReservation(
        string $jobId,
        string $auditId,
        string $disDetNro,
        int $facNitSec,
        string $disId,
        string $reservationToken,
        array &$run
    ): ?string {
        $payload = $this->buildReservationPayload($jobId, $auditId, $disDetNro, $facNitSec, $disId);
        if ($this->jobStore->claimAuditReservation($disDetNro, $reservationToken, $payload)) {
            $run['reservations'][] = ['dis_det_nro' => $disDetNro, 'token' => $reservationToken, 'audit_id' => $auditId];

            return $reservationToken;
        }

        $existing = $this->jobStore->getAuditReservation($disDetNro);
        if ($existing !== null
            && (string) ($existing['job_id'] ?? '') === $jobId
            && (string) ($existing['audit_id'] ?? '') === $auditId
        ) {
            return (string) ($existing['token'] ?? '') !== '' ? (string) $existing['token'] : $reservationToken;
        }

        return null;
    }

    /**
     * Inicializa (idempotente) el estado Redis de la auditoría y la registra en el job.
     *
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     */
    private function initAuditState(
        string $auditId,
        string $disDetNro,
        string $jobId,
        int $facNitSec,
        string $disId,
        string $reservationToken,
        int $epoch,
        array &$run
    ): void {
        if ($this->stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $disId)) {
            // X-F2-M2: se registra inmediatamente, antes de patchAudit()/registerAuditInJob().
            $run['audits'][$auditId] = $disDetNro;
            if (!$this->stateStore->patchAudit($auditId, ['reservation_token' => $reservationToken])) {
                throw new RuntimeException('No se pudo asociar la reserva a la auditoría', 503);
            }
        } else {
            // V-F2-B5: estado preexistente del MISMO job (caída previa): se continúa sin error.
            $existing = $this->stateStore->getAudit($auditId);
            if (($existing['job_id'] ?? null) !== $jobId) {
                Logger::error('AuditBatchOrchestrator::enqueueBatch no se pudo inicializar auditoría', [
                    'job_id' => $jobId,
                    'audit_id' => $auditId,
                ]);
                throw new RuntimeException('No se pudo inicializar la auditoría en Redis', 503);
            }
            if ((string) ($existing['reservation_token'] ?? '') === ''
                && !$this->stateStore->patchAudit($auditId, ['reservation_token' => $reservationToken])
            ) {
                throw new RuntimeException('No se pudo asociar la reserva a la auditoría', 503);
            }
        }

        if (!$this->jobStore->registerAuditInJob($jobId, $auditId, $disDetNro, $disId, $reservationToken, $epoch)) {
            throw new RuntimeException('No se pudo registrar la auditoría en el job', 503);
        }
        $run['registered'][$disDetNro] = true;
    }

    /**
     * @return array{event:AuditEvent,dis_det_nro:string,audit_id:string}
     */
    private function pendingAuditEvent(
        string $auditId,
        string $jobId,
        string $disDetNro,
        int $facNitSec,
        string $disId,
        string $reservationToken
    ): array {
        return [
            'event' => AuditEvent::create(
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
                eventId: AuditEvent::deterministicUuidV4("audit-created:{$jobId}:{$auditId}"),
            ),
            'dis_det_nro' => $disDetNro,
            'audit_id' => $auditId,
        ];
    }

    /**
     * Compensación de un chunk fallido (`W-F2-B1`). Solo actúa si no se publicó ningún evento.
     *
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     * @param int $epoch Epoch adquirido por este intento (protege la compensación frente a un takeover, `INV-02`).
     */
    private function handleEnqueueException(
        Throwable $e,
        string $jobId,
        int $chunkIndex,
        bool $externalJobId,
        array $run,
        int $epoch
    ): void {
        if ($run['published']) {
            Logger::error('AuditBatchOrchestrator::enqueueBatch falló después de publicar eventos; no se ejecuta rollback destructivo', [
                'job_id' => $jobId,
                'chunk_index' => $chunkIndex,
                'published_events_started' => true,
                'error' => $e->getMessage(),
            ]);
        } else {
            $this->cleanupAsyncEnqueueState($e, $jobId, $externalJobId, $chunkIndex, $run, $epoch);
        }

        Logger::error('AuditBatchOrchestrator::enqueueBatch falló', [
            'job_id' => $jobId,
            'chunk_index' => $chunkIndex,
            'error' => $e->getMessage(),
        ]);
    }

    private function initJobOrFail(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): void
    {
        $result = $this->jobStore->initJobExclusive($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        if (($result['created'] ?? false) === true) {
            return;
        }

        if (($result['reason'] ?? null) === 'overlap') {
            throw new RuntimeException('Ya existe un job activo para el cliente y rango de fechas especificado', 409);
        }

        throw new RuntimeException('No se pudo inicializar el job en Redis', 503);
    }

    /**
     * Resuelve y valida la identidad obligatoria de una candidata congelada.
     *
     * @param  array<string,mixed>  $row
     * @return array{dis_det_nro:string,dis_id:string}|null
     */
    private static function resolveInvoiceIdentity(array $row, string $jobId): ?array
    {
        $disDetNro = isset($row['Dispensa']) ? trim((string) $row['Dispensa']) : '';
        $disId = isset($row['DisId']) ? trim((string) $row['DisId']) : '';

        if ($disDetNro === '' || $disId === '') {
            // R-PII: no se vuelca la fila (contiene el NIT).
            Logger::warning('AuditBatchOrchestrator::enqueueBatch candidata inválida, omitida', [
                'job_id' => $jobId,
                'has_dispensa' => $disDetNro !== '',
                'has_dis_id' => $disId !== '',
            ]);
            return null;
        }

        return ['dis_det_nro' => $disDetNro, 'dis_id' => $disId];
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
     * Construye la respuesta del chunk a partir del estado autoritativo del job.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $extra
     * @param array<string,mixed>|null $nextCursor
     * @return array<string,mixed>
     */
    private function responseFromSummary(
        array $summary,
        BatchChunkState $chunkState,
        bool $hasMore = false,
        ?array $nextCursor = null,
        array $extra = []
    ): array {
        $total = (int) ($summary['total'] ?? 0);

        return array_merge($this->buildBatchResponse(
            (string) ($summary['job_id'] ?? ''),
            (string) ($summary['status'] ?? BatchJobStore::JOB_STATUS_PENDING),
            $total,
            (int) ($summary['skipped_locked'] ?? 0),
            (int) ($summary['skipped_existing'] ?? 0),
            $hasMore,
            $hasMore ? $nextCursor : null,
            $chunkState->chunkIndex,
            (int) ($summary['accepted'] ?? $total),
            (int) ($summary['skipped_locked'] ?? 0),
            (int) ($summary['skipped_existing'] ?? 0)
        ), $extra);
    }

    /**
     * @param array<string,mixed>|null $nextCursor
     * @return array{
     *     job_id:string,
     *     status:string,
     *     total:int,
     *     accepted:int,
     *     skipped_locked:int,
     *     skipped_existing:int,
     *     has_more:bool,
     *     next_cursor:array<string,mixed>|null,
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
     * Compensa el estado parcial de un chunk fallido SIN destruir lo que el propietario vigente puede reutilizar.
     *
     * Como `auditId` y `reservationToken` son deterministas por `(jobId, disDetNro)`, un token coincidente no prueba
     * propiedad: tras perder el lock el propietario `epoch + 1` reutiliza exactamente la misma auditoría y reserva.
     * Casos (en este orden):
     *  (b) fencing → no se borra nada;
     *  (a) job creado por este orquestador en el chunk 1 → limpieza total;
     *  (c) job externo (o chunk > 1) sin fencing → solo filas creadas en este intento SIN entrada en `by_dispensa`,
     *      mediante `BatchJobStore::discardUnregisteredAudit()`: comprobar epoch/`by_dispensa` y borrar ocurre en un
     *      único Lua, de modo que si el propietario `epoch + 1` registra y publica la fila entretanto, no se borra nada
     *      (un 503/timeout no es un rechazo de fencing aunque el lock ya haya cambiado de dueño).
     *
     * @param array{published:bool,audits:array<string,string>,reservations:array<int,array{dis_det_nro:string,token:string,audit_id:string}>,registered:array<string,bool>} $run
     * @param int $epoch Epoch adquirido por este intento.
     */
    private function cleanupAsyncEnqueueState(
        Throwable $error,
        string $jobId,
        bool $externalJobId,
        int $chunkIndex,
        array $run,
        int $epoch
    ): void {
        try {
            if (self::isFencingFailure($error)) {
                return;
            }

            if (!$externalJobId && $chunkIndex === 1) {
                foreach (array_keys($run['audits']) as $auditId) {
                    $this->stateStore->deleteAudit($auditId);
                }
                $this->jobStore->deleteJob($jobId);
                foreach ($run['reservations'] as $reservation) {
                    $this->jobStore->releaseAuditReservation($reservation['dis_det_nro'], $reservation['token']);
                }

                return;
            }

            $tokens = [];
            foreach ($run['reservations'] as $reservation) {
                $tokens[$reservation['dis_det_nro']] = $reservation['token'];
            }
            $handled = [];
            foreach ($run['audits'] as $auditId => $disDetNro) {
                $handled[$disDetNro] = true;
                if (($run['registered'][$disDetNro] ?? false) === true) {
                    continue;
                }
                // Estado (y reserva, si se creó en este intento) en un solo Lua protegido por epoch y by_dispensa.
                $this->jobStore->discardUnregisteredAudit($jobId, $auditId, $disDetNro, $tokens[$disDetNro] ?? '', $epoch);
            }
            foreach ($run['reservations'] as $reservation) {
                $disDetNro = $reservation['dis_det_nro'];
                if (isset($handled[$disDetNro]) || ($run['registered'][$disDetNro] ?? false) === true) {
                    continue;
                }
                // Reserva creada en este intento sin estado propio: solo se libera la reserva (auditId vacío).
                $this->jobStore->discardUnregisteredAudit($jobId, '', $disDetNro, $reservation['token'], $epoch);
            }
        } catch (Throwable $t) {
            Logger::error('AuditBatchOrchestrator::cleanupAsyncEnqueueState falló durante rollback', [
                'job_id' => $jobId,
                'chunk_index' => $chunkIndex,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * `true` si la excepción (o su causa directa) es un rechazo de fencing (`generation_fence_*`, `job_closed_or_reaping`).
     */
    private static function isFencingFailure(Throwable $error): bool
    {
        $candidate = $error instanceof JobGenerationFailedException && $error->getPrevious() !== null
            ? $error->getPrevious()
            : $error;
        $message = $candidate->getMessage();

        return str_starts_with($message, 'generation_fence_') || $message === 'job_closed_or_reaping';
    }

    private function bestEffortDeleteJob(string $jobId): void
    {
        try {
            $this->jobStore->deleteJob($jobId);
        } catch (Throwable $t) {
            Logger::error('AuditBatchOrchestrator: no se pudo eliminar el job tras fallar el claim del lock', [
                'job_id' => $jobId,
                'error' => $t->getMessage(),
            ]);
        }
    }

    private function renewLockOrFail(string $jobId, string $workerToken, int $epoch, int $lockTtl): void
    {
        if (!$this->jobStore->renewJobGenerationLock($jobId, $workerToken, $epoch, $lockTtl)) {
            throw new RuntimeException('generation_fence_lost', 409);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function requireSummary(string $jobId): array
    {
        $summary = $this->jobStore->getJobSummary($jobId);
        if ($summary === null) {
            throw new RuntimeException("Job {$jobId} no encontrado en Redis", 503);
        }
        $summary['job_id'] = $summary['job_id'] ?? $jobId;

        return $summary;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function hasOrphanContinuation(array $summary, int $chunkIndex): bool
    {
        return (int) ($summary['last_committed_chunk'] ?? 0) >= $chunkIndex
            && (int) ($summary['last_continuation_dispatched_chunk'] ?? 0) < $chunkIndex
            && ($summary['sealed'] ?? false) !== true;
    }

    private static function isTerminalJobStatus(string $status): bool
    {
        return in_array($status, [
            BatchJobStore::JOB_STATUS_COMPLETED,
            BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR,
            BatchJobStore::JOB_STATUS_FAILED,
        ], true);
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
