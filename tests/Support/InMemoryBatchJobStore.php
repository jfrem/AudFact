<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobLockClaimResult;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use RuntimeException;

/**
 * Doble con estado de `BatchJobStore` que reproduce la semántica de los Lua de SDD-BATCH-001 (fencing por
 * `generation_epoch`, guardas de job sellado/en reaping/terminal, checkpoints idempotentes, `by_dispensa`).
 *
 * No toca Redis: cualquier método de `BatchJobStore` NO sobrescrito aquí falla (propiedad `$redis` sin inicializar),
 * por lo que un test nunca llama a Redis por accidente.
 *
 * Inyección de fallos: `$interceptors['metodo'] = function (array $args, self $store): void {...}` se invoca antes de
 * ejecutar el método y puede lanzar una excepción o mutar el estado (p. ej. simular que otro worker robó el lock).
 */
final class InMemoryBatchJobStore extends BatchJobStore
{
    /** @var array<string,array<string,mixed>> */
    public array $jobs = [];
    /** @var array<string,array<string,array{dis_det_nro:string,status:string,failed_stage?:string}>> */
    public array $audits = [];
    /** @var array<string,array<string,array{audit_id:string,dispatched:bool}>> */
    public array $byDispensa = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $candidates = [];
    /** @var array<string,array<string,mixed>> */
    public array $reservations = [];
    /** @var array<string,array{worker_id:string,epoch:int}> */
    public array $locks = [];
    /** @var array<int,array<string,array{date_from:string,date_to:string}>> */
    public array $activeNit = [];
    /** @var array<string,string> */
    public array $idempotency = [];
    /** @var array<int,array{0:string,1:array<int,mixed>}> */
    public array $calls = [];
    /** @var array<string,callable> */
    public array $interceptors = [];
    /** @var array<int,array{job_id:string,message:string,epoch:?int,failed_chunk:int}> */
    public array $failedJobs = [];
    /** @var array<int,string> */
    public array $cancelledUndispatchedFor = [];
    /** Si es `true`, `claimJobGenerationFencedLock()` simula una respuesta inválida del store. */
    public bool $invalidClaimResponse = false;
    /**
     * Doble de `audit:{id}:state` compartido (en Redis son claves del mismo servidor). Lo usan
     * `discardUnregisteredAudit()` (obligatorio si recibe `auditId`) y `cancelUndispatchedAudits()` (opcional).
     */
    public ?InMemoryAuditStateStore $auditStateStore = null;

    public function __construct()
    {
        // Sin Redis.
    }

    // ── Utilidades de test ───────────────────────────────────────────────

    /**
     * @return array<int,array<int,mixed>> Argumentos de cada invocación del método indicado.
     */
    public function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)
        ));
    }

    public function countCalls(string $method): int
    {
        return count($this->callsOf($method));
    }

    /** Simula que el lock de generación expiró por TTL (otro worker podrá adquirirlo y subir el epoch). */
    public function expireLock(string $jobId): void
    {
        unset($this->locks[$jobId]);
    }

    /**
     * Crea un job v2 directamente (sin pasar por `initJobExclusive`) para escenarios preparados.
     *
     * @param array<string,mixed> $overrides
     */
    public function seedJob(string $jobId, array $overrides = []): void
    {
        $state = array_merge(self::baseState($jobId, 2624, '2026-06-01', '2026-06-30', 100), $overrides);
        $this->jobs[$jobId] = $state;
        $this->audits[$jobId] ??= [];
        $this->byDispensa[$jobId] ??= [];
        $this->activeNit[(int) $state['fac_nit_sec']][$jobId] = ['date_from' => (string) $state['date_from'], 'date_to' => (string) $state['date_to']];
    }

    // ── Creación y lectura ───────────────────────────────────────────────

    public function initJobExclusive(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): array
    {
        $this->record(__FUNCTION__, func_get_args());
        if (isset($this->jobs[$jobId])) {
            return ['created' => false, 'conflict_job_id' => null, 'reason' => 'job_exists'];
        }
        foreach ($this->activeNit[$facNitSec] ?? [] as $otherId => $range) {
            $other = $this->jobs[$otherId] ?? null;
            if ($other === null || self::isTerminal($other)) {
                unset($this->activeNit[$facNitSec][$otherId]);
                continue;
            }
            if ($range['date_from'] <= $dateTo && $range['date_to'] >= $dateFrom) {
                return ['created' => false, 'conflict_job_id' => (string) $otherId, 'reason' => 'overlap'];
            }
        }
        $this->jobs[$jobId] = self::baseState($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        $this->audits[$jobId] = [];
        $this->byDispensa[$jobId] = [];
        $this->activeNit[$facNitSec][$jobId] = ['date_from' => $dateFrom, 'date_to' => $dateTo];

        return ['created' => true, 'conflict_job_id' => null, 'reason' => null];
    }

    public function getJobSummary(string $jobId): ?array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->jobs[$jobId] ?? null;
    }

    public function getJob(string $jobId): ?array
    {
        $this->record(__FUNCTION__, func_get_args());
        if (!isset($this->jobs[$jobId])) {
            return null;
        }

        return array_merge($this->jobs[$jobId], ['audits' => $this->audits[$jobId] ?? []]);
    }

    // ── Registro de auditorías ───────────────────────────────────────────

    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $disId = null,
        ?string $reservationToken = null,
        ?int $expectedEpoch = null
    ): bool {
        $this->record(__FUNCTION__, func_get_args());
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null) {
            return false;
        }
        if (self::isClosed($job)) {
            throw new RuntimeException('job_closed_or_reaping', 409);
        }
        if ($expectedEpoch !== null && (int) ($job['generation_epoch'] ?? 0) !== $expectedEpoch) {
            throw new RuntimeException('generation_fence_stale', 409);
        }
        $existing = $this->byDispensa[$jobId][$disDetNro] ?? null;
        if ($existing !== null && $existing['audit_id'] !== $auditId) {
            throw new RuntimeException('audit_dispensa_conflict', 409);
        }
        $this->byDispensa[$jobId][$disDetNro] ??= ['audit_id' => $auditId, 'dispatched' => false];
        $this->audits[$jobId][$auditId] ??= ['dis_det_nro' => $disDetNro, 'status' => 'pending'];
        $total = count($this->audits[$jobId]);
        $this->jobs[$jobId]['total'] = $total;
        $this->jobs[$jobId]['accepted'] = $total;
        if (($this->jobs[$jobId]['status'] ?? 'pending') === self::JOB_STATUS_PENDING) {
            $this->jobs[$jobId]['status'] = self::JOB_STATUS_QUEUED;
        }

        return true;
    }

    public function getBatchDispensaEntry(string $jobId, string $disDetNro): ?array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->byDispensa[$jobId][$disDetNro] ?? null;
    }

    public function markAuditsDispatched(string $jobId, array $pairs, bool $batchCreatedPublished, int $expectedEpoch): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if (!$this->generationGuardPasses($jobId, $expectedEpoch)) {
            return false;
        }
        foreach ($pairs as $ddn => $auditId) {
            $ddn = (string) $ddn;
            $entry = $this->byDispensa[$jobId][$ddn] ?? null;
            if ($entry !== null && ((string) $auditId === '' || $entry['audit_id'] === (string) $auditId)) {
                $this->byDispensa[$jobId][$ddn]['dispatched'] = true;
            }
        }
        if ($batchCreatedPublished) {
            $this->jobs[$jobId]['batch_created_published'] = true;
        }

        return true;
    }

    // ── Congelación y checkpoints ────────────────────────────────────────

    public function storeFrozenCandidates(string $jobId, array $candidates, int $expectedEpoch): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if (!$this->generationGuardPasses($jobId, $expectedEpoch)) {
            return false;
        }
        if (($this->jobs[$jobId]['candidates_frozen'] ?? false) === true) {
            return true;
        }
        $this->candidates[$jobId] = array_values($candidates);
        $this->jobs[$jobId]['candidates_frozen'] = true;
        $this->jobs[$jobId]['candidates_count'] = count($candidates);
        $this->jobs[$jobId]['next_candidate_offset'] = 0;

        return true;
    }

    public function readFrozenCandidates(string $jobId, int $offset, int $count): array
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($count < 1) {
            return [];
        }

        return array_slice($this->candidates[$jobId] ?? [], max(0, $offset), $count);
    }

    public function commitChunkCheckpoint(
        string $jobId,
        int $chunkIndex,
        int $nextOffset,
        int $skippedLockedDelta,
        int $skippedExistingDelta,
        int $expectedEpoch,
        ?array $lastCursor = null
    ): bool {
        $this->record(__FUNCTION__, func_get_args());
        if (!$this->generationGuardPasses($jobId, $expectedEpoch)) {
            return false;
        }
        if ($chunkIndex <= (int) ($this->jobs[$jobId]['last_committed_chunk'] ?? 0)) {
            return true;
        }
        $job = $this->jobs[$jobId];
        $job['next_candidate_offset'] = $nextOffset;
        $job['skipped_locked'] = (int) $job['skipped_locked'] + max(0, $skippedLockedDelta);
        $job['skipped_existing'] = (int) $job['skipped_existing'] + max(0, $skippedExistingDelta);
        if ($lastCursor !== null) {
            $job['last_cursor'] = $lastCursor;
        }
        $job['last_committed_chunk'] = $chunkIndex;
        $this->jobs[$jobId] = $job;

        return true;
    }

    public function markContinuationDispatched(string $jobId, int $chunkIndex): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || $chunkIndex < 1) {
            return false;
        }
        if (($job['sealed'] ?? false) === true || ($job['reaping'] ?? false) === true || ($job['status'] ?? '') === self::JOB_STATUS_FAILED) {
            return true;
        }
        if ((int) ($job['last_committed_chunk'] ?? 0) < $chunkIndex) {
            throw new RuntimeException('continuation_before_checkpoint');
        }
        if ((int) ($job['last_continuation_dispatched_chunk'] ?? 0) < $chunkIndex) {
            $this->jobs[$jobId]['last_continuation_dispatched_chunk'] = $chunkIndex;
        }

        return true;
    }

    // ── Cierre ───────────────────────────────────────────────────────────

    public function deleteJob(string $jobId): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $existed = isset($this->jobs[$jobId]);
        if ($existed) {
            $nit = (int) $this->jobs[$jobId]['fac_nit_sec'];
            unset($this->activeNit[$nit][$jobId]);
        }
        unset($this->jobs[$jobId], $this->audits[$jobId], $this->byDispensa[$jobId], $this->candidates[$jobId], $this->locks[$jobId]);

        return $existed;
    }

    public function sealJob(
        string $jobId,
        int $skippedLockedDelta = 0,
        int $skippedExistingDelta = 0,
        ?int $expectedEpoch = null,
        ?int $chunkIndex = null,
        ?int $nextOffset = null
    ): bool {
        $this->record(__FUNCTION__, func_get_args());
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || self::isClosed($job)) {
            return false;
        }
        if ($expectedEpoch !== null && (int) ($job['generation_epoch'] ?? 0) !== $expectedEpoch) {
            return false;
        }
        $job['skipped_locked'] = (int) $job['skipped_locked'] + max(0, $skippedLockedDelta);
        $job['skipped_existing'] = (int) $job['skipped_existing'] + max(0, $skippedExistingDelta);
        if ($chunkIndex !== null && $chunkIndex > (int) ($job['last_committed_chunk'] ?? 0)) {
            $job['last_committed_chunk'] = $chunkIndex;
        }
        if ($nextOffset !== null) {
            $job['next_candidate_offset'] = $nextOffset;
        }
        unset($this->candidates[$jobId]);
        $total = count($this->audits[$jobId] ?? []);
        $job['sealed'] = true;
        $job['total'] = $total;
        $job['accepted'] = $total;
        if ((int) $job['done'] + (int) $job['failed'] >= $total) {
            $job['status'] = (int) $job['failed'] > 0 ? self::JOB_STATUS_COMPLETED_WITH_ERR : self::JOB_STATUS_COMPLETED;
            unset($this->activeNit[(int) $job['fac_nit_sec']][$jobId]);
        }
        $this->jobs[$jobId] = $job;

        return true;
    }

    public function publishEmptyBatch(string $jobId, int $skippedLocked, int $skippedExisting, ?int $expectedEpoch = null): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || self::isClosed($job)) {
            return false;
        }
        if ($expectedEpoch !== null && (int) ($job['generation_epoch'] ?? 0) !== $expectedEpoch) {
            return false;
        }
        $job['status'] = self::JOB_STATUS_COMPLETED;
        $job['sealed'] = true;
        $job['total'] = 0;
        $job['accepted'] = 0;
        $job['skipped_locked'] = (int) $job['skipped_locked'] + max(0, $skippedLocked);
        $job['skipped_existing'] = (int) $job['skipped_existing'] + max(0, $skippedExisting);
        unset($this->candidates[$jobId], $this->activeNit[(int) $job['fac_nit_sec']][$jobId]);
        $this->jobs[$jobId] = $job;

        return true;
    }

    public function markJobFailed(string $jobId, string $errorMessage, ?int $expectedEpoch = null, int $failedChunkIndex = 0): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->failedJobs[] = ['job_id' => $jobId, 'message' => $errorMessage, 'epoch' => $expectedEpoch, 'failed_chunk' => $failedChunkIndex];
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || self::isClosed($job)) {
            return false;
        }
        $epoch = $expectedEpoch ?? 0;
        if ($epoch > 0 && (int) ($job['generation_epoch'] ?? 0) !== $epoch) {
            return false;
        }
        if ($failedChunkIndex > 0
            && (int) ($job['last_committed_chunk'] ?? 0) >= $failedChunkIndex
            && (int) ($job['last_continuation_dispatched_chunk'] ?? 0) >= $failedChunkIndex
        ) {
            return false;
        }
        $job['status'] = self::JOB_STATUS_FAILED;
        $job['sealed'] = true;
        $job['error_message'] = $errorMessage;
        unset($this->candidates[$jobId], $this->activeNit[(int) $job['fac_nit_sec']][$jobId]);
        $this->jobs[$jobId] = $job;

        return true;
    }

    /**
     * Réplica de `CANCEL_UNDISPATCHED_AUDITS_LUA`: solo jobs `failed`; por cada entrada `by_dispensa` con
     * `dispatched !== true` transiciona a `failed` la entrada del job si NO es terminal (cuenta solo esas transiciones),
     * cierra `audit:{id}:state` propio y no terminal (si hay `$auditStateStore`), libera la reserva propia
     * (`job_id` + `audit_id`) y conserva `dispatched = false`. `job.failed` se incrementa acotado a `total - done`.
     * Sin telemetría (`audits_in_flight`).
     */
    public function cancelUndispatchedAudits(string $jobId, string $failedStage = 'batch_dlq_unpublished'): int
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->cancelledUndispatchedFor[] = $jobId;
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || ($job['status'] ?? '') !== self::JOB_STATUS_FAILED) {
            return 0;
        }
        $cancelled = 0;
        foreach ($this->byDispensa[$jobId] ?? [] as $ddn => $entry) {
            if (($entry['dispatched'] ?? false) === true) {
                continue;
            }
            $auditId = (string) ($entry['audit_id'] ?? '');
            if ($auditId === '') {
                continue;
            }
            $state = $this->auditStateStore?->audits[$auditId] ?? null;
            if ($state !== null && (string) ($state['job_id'] ?? '') === $jobId && !self::isAuditTerminal($state['status'] ?? null)) {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $this->auditStateStore->audits[$auditId] = array_merge($state, [
                    'status' => 'failed',
                    'failed_stage' => $failedStage,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $jobEntry = $this->audits[$jobId][$auditId] ?? null;
            if ($jobEntry !== null && !self::isAuditTerminal($jobEntry['status'] ?? null)) {
                $this->audits[$jobId][$auditId]['status'] = 'failed';
                $this->audits[$jobId][$auditId]['failed_stage'] = $failedStage;
                $cancelled++;
            }
            $reservation = $this->reservations[(string) $ddn] ?? null;
            if ($reservation !== null
                && (string) ($reservation['job_id'] ?? '') === $jobId
                && (string) ($reservation['audit_id'] ?? '') === $auditId
            ) {
                unset($this->reservations[(string) $ddn]);
            }
        }
        if ($cancelled > 0) {
            $total = (int) ($job['total'] ?? 0);
            $done = (int) ($job['done'] ?? 0);
            $this->jobs[$jobId]['failed'] = min((int) ($job['failed'] ?? 0) + $cancelled, max(0, $total - $done));
        }

        return $cancelled;
    }

    /**
     * Réplica de `DISCARD_UNREGISTERED_AUDIT_LUA` (operación atómica: el interceptor corre ANTES, nunca en medio).
     * Guardas: job existente, `generation_epoch === $expectedEpoch` y sin entrada `by_dispensa[$disDetNro]`.
     * Luego borra el estado propio (`job_id`/`audit_id`/`dis_det_nro`) y la reserva con `token` coincidente.
     */
    public function discardUnregisteredAudit(
        string $jobId,
        string $auditId,
        string $disDetNro,
        string $reservationToken,
        int $expectedEpoch
    ): bool {
        $this->record(__FUNCTION__, func_get_args());
        if ($jobId === '' || $disDetNro === '' || $expectedEpoch < 1) {
            return false;
        }
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null) {
            return false;
        }
        if ((int) ($job['generation_epoch'] ?? 0) !== $expectedEpoch) {
            return false;
        }
        if (isset($this->byDispensa[$jobId][$disDetNro])) {
            return false;
        }
        if ($auditId !== '') {
            if ($this->auditStateStore === null) {
                throw new \LogicException('discardUnregisteredAudit requiere enlazar $auditStateStore en el doble.');
            }
            $state = $this->auditStateStore->audits[$auditId] ?? null;
            if ($state !== null
                && (string) ($state['job_id'] ?? '') === $jobId
                && (string) ($state['audit_id'] ?? '') === $auditId
                && (string) ($state['dis_det_nro'] ?? '') === $disDetNro
            ) {
                unset($this->auditStateStore->audits[$auditId]);
            }
        }
        if ($reservationToken !== '' && ($this->reservations[$disDetNro]['token'] ?? null) === $reservationToken) {
            unset($this->reservations[$disDetNro]);
        }

        return true;
    }

    // ── Idempotencia y reservas ──────────────────────────────────────────

    public function claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('idempotency_ttl_invalid');
        }
        if (isset($this->idempotency[$key])) {
            return $this->idempotency[$key];
        }
        $this->idempotency[$key] = $jobId;

        return null;
    }

    public function releaseIdempotencyKeyIfMatches(string $key, string $jobId): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if (($this->idempotency[$key] ?? null) === $jobId) {
            unset($this->idempotency[$key]);

            return true;
        }

        return false;
    }

    public function claimAuditReservation(string $disDetNro, string $ownerToken, array $reservation): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if (isset($this->reservations[$disDetNro])) {
            return false;
        }
        $reservation['dis_det_nro'] = $disDetNro;
        $reservation['token'] = $ownerToken;
        $this->reservations[$disDetNro] = $reservation;

        return true;
    }

    public function getAuditReservation(string $disDetNro): ?array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->reservations[$disDetNro] ?? null;
    }

    public function releaseAuditReservation(string $disDetNro, string $ownerToken): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if (($this->reservations[$disDetNro]['token'] ?? null) === $ownerToken) {
            unset($this->reservations[$disDetNro]);

            return true;
        }

        return false;
    }

    // ── Lock de generación ───────────────────────────────────────────────

    public function claimJobGenerationFencedLock(string $jobId, string $workerId, int $chunkIndex, int $ttlSeconds = 1800): JobLockClaimResult
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($this->invalidClaimResponse) {
            throw new RuntimeException('invalid_generation_lock_claim_response');
        }
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null) {
            return new JobLockClaimResult(JobLockClaimStatus::MISSING);
        }
        $current = (int) ($job['generation_epoch'] ?? 0);
        if (self::isClosed($job)) {
            return new JobLockClaimResult(JobLockClaimStatus::TERMINAL, $current);
        }
        if (isset($this->locks[$jobId])) {
            return new JobLockClaimResult(JobLockClaimStatus::BUSY, $current);
        }
        $epoch = $current + 1;
        $this->jobs[$jobId]['generation_epoch'] = $epoch;
        $this->jobs[$jobId]['generation_owner'] = $workerId;
        if ($chunkIndex > 1
            && (int) ($job['last_committed_chunk'] ?? 0) >= $chunkIndex - 1
            && (int) ($job['last_continuation_dispatched_chunk'] ?? 0) < $chunkIndex - 1
        ) {
            $this->jobs[$jobId]['last_continuation_dispatched_chunk'] = $chunkIndex - 1;
        }
        $this->locks[$jobId] = ['worker_id' => $workerId, 'epoch' => $epoch];

        return new JobLockClaimResult(JobLockClaimStatus::ACQUIRED, $epoch);
    }

    public function renewJobGenerationLock(string $jobId, string $workerId, int $epoch, int $ttlSeconds = 1800): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $lock = $this->locks[$jobId] ?? null;

        return $lock !== null && $lock['worker_id'] === $workerId && $lock['epoch'] === $epoch;
    }

    public function releaseJobGenerationLock(string $jobId, string $workerId, ?int $epoch = null): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $lock = $this->locks[$jobId] ?? null;
        if ($lock === null || $lock['worker_id'] !== $workerId || ($epoch !== null && $lock['epoch'] !== $epoch)) {
            return false;
        }
        unset($this->locks[$jobId]);

        return true;
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /**
     * @param array<int,mixed> $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
        if (isset($this->interceptors[$method])) {
            ($this->interceptors[$method])($args, $this);
        }
    }

    /** Guarda de generación (`W-F2-B3`): job existente, no cerrado y con el epoch esperado. */
    private function generationGuardPasses(string $jobId, int $expectedEpoch): bool
    {
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null || self::isClosed($job)) {
            return false;
        }

        return (int) ($job['generation_epoch'] ?? 0) === $expectedEpoch;
    }

    /** `auditEntryTerminal()` de `LUA_HELPERS`. */
    private static function isAuditTerminal(mixed $status): bool
    {
        return in_array((string) $status, ['completed', 'manual_review', 'error', 'failed'], true);
    }

    /** @param array<string,mixed> $job */
    private static function isClosed(array $job): bool
    {
        return ($job['sealed'] ?? false) === true || ($job['reaping'] ?? false) === true || self::isTerminal($job);
    }

    /** @param array<string,mixed> $job */
    private static function isTerminal(array $job): bool
    {
        return in_array($job['status'] ?? '', [self::JOB_STATUS_COMPLETED, self::JOB_STATUS_COMPLETED_WITH_ERR, self::JOB_STATUS_FAILED], true);
    }

    /**
     * @return array<string,mixed>
     */
    private static function baseState(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): array
    {
        return [
            'job_id' => $jobId,
            'execution_version' => 2,
            'status' => self::JOB_STATUS_PENDING,
            'fac_nit_sec' => $facNitSec,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
            'sealed' => false,
            'total' => 0,
            'accepted' => 0,
            'done' => 0,
            'failed' => 0,
            'skipped_locked' => 0,
            'skipped_existing' => 0,
            'generation_epoch' => 0,
            'candidates_frozen' => false,
            'candidates_count' => 0,
            'next_candidate_offset' => 0,
            'last_committed_chunk' => 0,
            'last_continuation_dispatched_chunk' => 0,
            'batch_created_published' => false,
        ];
    }
}
