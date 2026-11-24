<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

/**
 * SDD-BATCH-001 (IMPL-BATCH-001-B1): `REAP_STALE_JOB_LUA`, `MARK_JOB_FAILED_LUA`, `CANCEL_UNDISPATCHED_AUDITS_LUA`,
 * prefijo efectivo de `REDIS_PREFIX` y guarda de generación. Requiere Redis 7 real aislado (`RUN_REDIS_INTEGRATION=1`).
 *
 * Los casos de `gc()` / CLI / pausa (`resumed_at_ms`) pertenecen a la tarea de mantenimiento y no se cubren aquí.
 */
final class AuditStaleJobReaperRedisTest extends TestCase
{
    private const STALE_MS = 21_600_000;
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private IsolatedAuditRedis $redis;
    private BatchJobStore $store;

    /** @var array<int,string> Claves SIN prefijo que un script defectuoso podría crear fuera del namespace. */
    private array $unprefixedProbe = [];

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real aislado.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->store = new BatchJobStore($this->redis);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->unprefixedProbe as $key) {
                $this->redis->eval('return redis.call("DEL", ARGV[1])', [], [$key]);
            }
            $this->redis->cleanup();
        }
    }

    // ── Casos ────────────────────────────────────────────────────────────

    /** `V-F2-B1`, `V-F2-B2`: fechas ISO, prefijo efectivo y omisión de jobs sin marca de progreso. */
    public function testReaperWritesIsoTimestampsUsesKeyPrefixAndSkipsJobsWithoutProgressMark(): void
    {
        $nit = 987654001;
        $jobId = $this->newJob($nit);
        $epoch = $this->acquireEpoch($jobId);
        [$auditId, $ddn] = $this->registerAudits($jobId, 1, $epoch)[0];
        $this->probeUnprefixed('jobs:active:nit:' . $nit);
        $this->probeUnprefixed(BatchJobStore::auditReservationKey($ddn));
        $this->probeUnprefixed(AuditStateStore::auditKey($auditId));

        // Sin marca de progreso interpretable (null) y sin updated_at parseable: el reaper automático no actúa.
        $this->setState($jobId, ['last_progress_at' => null, 'updated_at' => '###']);
        $skipped = $this->store->reapStaleJob($jobId, $this->nowMs(), self::STALE_MS);
        self::assertFalse($skipped['reaped']);
        self::assertSame('queued', $this->summary($jobId)['status']);

        // Con marca vencida: cierra con ISO-8601 y limpia con el prefijo efectivo.
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);
        $result = $this->reapFully($jobId, self::STALE_MS);

        self::assertTrue($result['reaped']);
        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertSame('stale_no_progress', $state['stale_reason']);
        self::assertMatchesRegularExpression(self::ISO, (string) $state['completed_at']);
        self::assertMatchesRegularExpression(self::ISO, (string) $state['updated_at']);
        self::assertArrayNotHasKey('reaping', $state);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        self::assertSame(0, $this->exists(BatchJobStore::auditReservationKey($ddn)));
        self::assertSame(0, $this->unprefixedExists('jobs:active:nit:' . $nit));
        self::assertSame(0, $this->unprefixedExists(BatchJobStore::auditReservationKey($ddn)));
        self::assertSame(-1, $this->ttl(BatchJobStore::jobKey($jobId)), 'El estado del job se escribe sin EX.');
    }

    /** `V-F2-I8`: un job `reaping` (corte tras la 1.ª rebanada) se reanuda de inmediato sin comprobar umbral ni claim. */
    public function testReapingJobResumedImmediatelyAfterCrashAndResume(): void
    {
        $jobId = $this->newJob(987654002);
        $epoch = $this->acquireEpoch($jobId);
        $this->registerAudits($jobId, 3, $epoch);
        // Estado que deja la 1.ª rebanada: reaping=true y epoch incrementado; el progreso es reciente.
        $this->setState($jobId, ['reaping' => true, 'generation_epoch' => $epoch + 1]);

        $result = $this->reapFully($jobId, self::STALE_MS);

        self::assertTrue($result['reaped']);
        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertSame(3, $state['total']);
        self::assertSame(3, $state['failed']);
        self::assertSame(0, $state['done']);
        self::assertArrayNotHasKey('reaping', $state);
    }

    /** `V-F2-I5`: `MARK_JOB_FAILED_LUA` retorna `false` con epoch obsoleto o durante `reaping`, sin mutar. */
    public function testMarkJobFailedReturnsFalseOnStaleEpochAndDuringReaping(): void
    {
        $jobId = $this->newJob(987654003);
        $this->acquireEpoch($jobId);
        $epoch = $this->acquireEpoch($jobId);
        self::assertSame(2, $epoch);

        self::assertFalse($this->store->markJobFailed($jobId, 'dlq', 1));
        self::assertSame('pending', $this->summary($jobId)['status']);

        $this->setState($jobId, ['reaping' => true]);
        self::assertFalse($this->store->markJobFailed($jobId, 'dlq', $epoch));
        self::assertSame('pending', $this->summary($jobId)['status']);

        $this->setState($jobId, ['reaping' => null]);
        self::assertTrue($this->store->markJobFailed($jobId, 'dlq', $epoch));
        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertTrue($state['sealed']);
        self::assertMatchesRegularExpression(self::ISO, (string) $state['completed_at']);

        // Ya terminal: nueva invocación no muta ni lanza.
        self::assertFalse($this->store->markJobFailed($jobId, 'otra vez', $epoch));
    }

    public function testReapsStaleJobCancelsLiveAuditsReleasesReservationsAndUnblocksNit(): void
    {
        $nit = 987654004;
        $jobId = $this->newJob($nit, '2026-05-01', '2026-05-31');
        $epoch = $this->acquireEpoch($jobId);
        $audits = $this->registerAudits($jobId, 3, $epoch);
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 100));
        self::assertSame('processing', $this->summary($jobId)['status']);
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);

        $running = $this->telemetry('jobs_running');
        $failedJobs = $this->telemetry('jobs_failed');
        $inFlight = $this->telemetry('audits_in_flight');

        $result = $this->reapFully($jobId, self::STALE_MS);

        self::assertTrue($result['reaped']);
        self::assertSame(2, $result['cancelled']);
        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertSame('stale_no_progress', $state['stale_reason']);
        self::assertSame(1, $state['done']);
        self::assertSame(2, $state['failed']);
        self::assertSame(3, $state['total']);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        self::assertSame($running - 1, $this->telemetry('jobs_running'));
        self::assertSame($failedJobs + 1, $this->telemetry('jobs_failed'));
        self::assertSame($inFlight - 2, $this->telemetry('audits_in_flight'));
        self::assertSame(0, $this->exists(BatchJobStore::auditReservationKey($audits[1][1])));
        self::assertSame(0, $this->exists(BatchJobStore::auditReservationKey($audits[2][1])));

        // El NIT queda libre: nueva solicitud sobre el mismo rango es aceptada.
        $again = $this->store->initJobExclusive(AuditEvent::uuidV4(), $nit, '2026-05-01', '2026-05-31', 10);
        self::assertTrue($again['created']);
    }

    public function testDoesNotReapWhenBelowThresholdOrGenerationLockAlive(): void
    {
        $jobId = $this->newJob(987654005);
        $epoch = $this->acquireEpoch($jobId);
        $this->registerAudits($jobId, 1, $epoch);

        // Progreso reciente: no actúa.
        self::assertFalse($this->reapFully($jobId, self::STALE_MS)['reaped']);

        // Progreso vencido pero propietario de generación vivo: no actúa.
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);
        $claim = $this->store->claimJobGenerationFencedLock($jobId, 'holder', 1, 600);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
        self::assertFalse($this->reapFully($jobId, self::STALE_MS)['reaped']);
        self::assertNotSame('failed', $this->summary($jobId)['status']);

        // Liberado el lock, el reaper actúa.
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'holder', $claim->epoch));
        self::assertTrue($this->reapFully($jobId, self::STALE_MS)['reaped']);
        self::assertSame('failed', $this->summary($jobId)['status']);
    }

    /** `INV-08`: una auditoría tardía no reabre ni contabiliza un job cerrado por el reaper. */
    public function testLateCompletionDoesNotReopenReapedJob(): void
    {
        $jobId = $this->newJob(987654006);
        $epoch = $this->acquireEpoch($jobId);
        $audits = $this->registerAudits($jobId, 2, $epoch);
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);
        self::assertTrue($this->reapFully($jobId, self::STALE_MS)['reaped']);
        $before = $this->summary($jobId);
        $completed = $this->telemetry('jobs_completed');

        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 50));

        $after = $this->summary($jobId);
        self::assertSame('failed', $after['status']);
        self::assertSame($before['done'], $after['done']);
        self::assertSame($before['failed'], $after['failed']);
        self::assertSame($completed, $this->telemetry('jobs_completed'));
    }

    /** Umbral `0` (`--force-stale`): cierra aunque el progreso sea reciente, pero respeta `batch:claim` vivo. */
    public function testForceStaleThresholdZeroClosesJobButRespectsClaim(): void
    {
        $jobId = $this->newJob(987654007);
        $epoch = $this->acquireEpoch($jobId);
        $this->registerAudits($jobId, 1, $epoch);
        $claim = $this->store->claimJobGenerationFencedLock($jobId, 'holder', 1, 600);

        self::assertFalse($this->reapFully($jobId, 0)['reaped']);
        self::assertNotSame('failed', $this->summary($jobId)['status']);

        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'holder', $claim->epoch));
        self::assertTrue($this->reapFully($jobId, 0)['reaped']);
        self::assertSame('failed', $this->summary($jobId)['status']);
    }

    /** `W-F2-I1`: por rebanadas, `REGISTER`/claim/finalización rechazan o no mutan hasta la rebanada final. */
    public function testReapInSlicesBlocksRegisterClaimAndCompletionCloseUntilFinalSlice(): void
    {
        $jobId = $this->newJob(987654008);
        $epoch = $this->acquireEpoch($jobId);
        $inFlightBefore = $this->telemetry('audits_in_flight');
        $failedJobsBefore = $this->telemetry('jobs_failed');
        $audits = $this->registerAudits($jobId, 1200, $epoch, false);
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);

        $first = $this->store->reapStaleJob($jobId, $this->nowMs(), self::STALE_MS);
        self::assertFalse($first['done'], 'Con 1.200 entradas y HSCAN COUNT 500 debe haber más de una rebanada.');
        self::assertNotNull($first['cursor']);
        $mid = $this->summary($jobId);
        self::assertNotSame('failed', $mid['status'], 'El job solo pasa a failed en la rebanada final.');
        self::assertTrue($mid['reaping']);
        self::assertSame(2, $mid['generation_epoch']);

        // Entre rebanadas: el registro rechaza, el claim es terminal y una finalización no cierra el job.
        try {
            $this->store->registerAuditInJob($jobId, AuditEvent::uuidV4(), 'EXTRA-1', null, null, $epoch + 1);
            self::fail('Un job en reaping debe rechazar el registro.');
        } catch (\RuntimeException $e) {
            self::assertSame('job_closed_or_reaping', $e->getMessage());
        }
        self::assertSame(
            JobLockClaimStatus::TERMINAL,
            $this->store->claimJobGenerationFencedLock($jobId, 'late', 9)->status
        );
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 1));
        $afterCompletion = $this->summary($jobId);
        self::assertSame(0, $afterCompletion['done']);
        self::assertNotSame('completed', $afterCompletion['status']);

        // Continúa hasta la rebanada final.
        $cursor = $first['cursor'];
        $guard = 0;
        do {
            $slice = $this->store->reapStaleJob($jobId, $this->nowMs(), self::STALE_MS, $cursor);
            $cursor = $slice['cursor'];
        } while (!$slice['done'] && ++$guard < 20);

        $final = $this->summary($jobId);
        self::assertSame('failed', $final['status']);
        self::assertSame(1200, $final['total']);
        self::assertSame(1200, $final['failed']);
        self::assertSame($failedJobsBefore + 1, $this->telemetry('jobs_failed'), 'La telemetría del job se ajusta una sola vez.');
        self::assertSame($inFlightBefore, $this->telemetry('audits_in_flight'));
    }

    /** `R-SEAL`: un job migrado `processing` + `sealed = true` es elegible (terminal ⇔ status). */
    public function testSealedProcessingMigratedJobIsEligibleForReap(): void
    {
        $jobId = $this->newJob(987654009);
        $epoch = $this->acquireEpoch($jobId);
        $audits = $this->registerAudits($jobId, 3, $epoch, false);
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 10));
        $this->setState($jobId, [
            'sealed' => true,
            'last_progress_at' => $this->nowMs() - self::STALE_MS - 1000,
        ]);
        self::assertSame('processing', $this->summary($jobId)['status']);

        self::assertTrue($this->reapFully($jobId, self::STALE_MS)['reaped']);

        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertSame(1, $state['done']);
        self::assertSame(2, $state['failed']);
    }

    /** `I7`: una finalización tardía sobre un job cerrado por DLQ decrementa `audits_in_flight` sin reabrirlo. */
    public function testAuditsInFlightDecrementsForLateCompletionOnTerminalJob(): void
    {
        $jobId = $this->newJob(987654010);
        $epoch = $this->acquireEpoch($jobId);
        $baseline = $this->telemetry('audits_in_flight');
        $audits = $this->registerAudits($jobId, 2, $epoch, false);
        self::assertSame($baseline + 2, $this->telemetry('audits_in_flight'));
        self::assertTrue($this->store->markJobFailed($jobId, 'dlq', $epoch));
        $completed = $this->telemetry('jobs_completed');

        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 5));

        self::assertSame($baseline + 1, $this->telemetry('audits_in_flight'));
        $state = $this->summary($jobId);
        self::assertSame('failed', $state['status']);
        self::assertSame(0, $state['done']);
        self::assertSame($completed, $this->telemetry('jobs_completed'));
    }

    /** `W-F2-B4`a: el reaper lleva `audit:{id}:state` vivo a `failed` conservando `documents`, sin TTL. */
    public function testReaperMarksLiveAuditStatesFailedPreservingDocuments(): void
    {
        $jobId = $this->newJob(987654011);
        $epoch = $this->acquireEpoch($jobId);
        $documents = ['doc-1' => ['status' => 'extracted', 'attempts' => 2], 'doc-2' => ['status' => 'pending']];
        $audits = $this->registerAudits($jobId, 1, $epoch, true, $documents);
        $this->setState($jobId, ['last_progress_at' => $this->nowMs() - self::STALE_MS - 1000]);

        self::assertTrue($this->reapFully($jobId, self::STALE_MS)['reaped']);

        $key = AuditStateStore::auditKey($audits[0][0]);
        $audit = json_decode((string) $this->redis->get($key), true);
        self::assertSame('failed', $audit['status']);
        self::assertSame('stale_no_progress', $audit['failed_stage']);
        self::assertMatchesRegularExpression(self::ISO, (string) $audit['completed_at']);
        self::assertMatchesRegularExpression(self::ISO, (string) $audit['updated_at']);
        self::assertEquals($documents, $audit['documents'], 'documents debe quedar intacto (el orden de claves puede variar).');
        self::assertSame(-1, $this->ttl($key));
        $entry = json_decode((string) $this->hget(BatchJobStore::jobAuditsKey($jobId), $audits[0][0]), true);
        self::assertSame('failed', $entry['status']);
        self::assertSame('stale_no_progress', $entry['failed_stage']);
    }

    /** `W-F2-B4`b: cancela solo lo no despachado de un job `failed`; libera reservas propias y descuenta `in_flight`. */
    public function testCancelUndispatchedAuditsReleasesReservationsAndInFlight(): void
    {
        $jobId = $this->newJob(987654012);
        $epoch = $this->acquireEpoch($jobId);
        $baseline = $this->telemetry('audits_in_flight');
        $audits = $this->registerAudits($jobId, 10, $epoch);
        $dispatched = array_slice($audits, 0, 4);
        $pairs = [];
        foreach ($dispatched as [$auditId, $ddn]) {
            $pairs[$ddn] = $auditId;
        }
        self::assertTrue($this->store->markAuditsDispatched($jobId, $pairs, true, $epoch));
        // Reserva ajena (otro job) que no debe tocarse.
        self::assertTrue($this->store->claimAuditReservation('FOREIGN-1', 'tok', ['job_id' => 'otro-job', 'audit_id' => 'otra']));

        // Con el job aún activo no muta nada.
        self::assertSame(0, $this->store->cancelUndispatchedAudits($jobId));
        self::assertTrue($this->store->markJobFailed($jobId, 'dlq', $epoch));

        self::assertSame(6, $this->store->cancelUndispatchedAudits($jobId));
        self::assertSame($baseline + 4, $this->telemetry('audits_in_flight'));
        foreach (array_slice($audits, 4) as [$auditId, $ddn]) {
            $entry = json_decode((string) $this->hget(BatchJobStore::jobAuditsKey($jobId), $auditId), true);
            self::assertSame('failed', $entry['status']);
            self::assertSame('batch_dlq_unpublished', $entry['failed_stage']);
            $state = json_decode((string) $this->redis->get(AuditStateStore::auditKey($auditId)), true);
            self::assertSame('failed', $state['status']);
            self::assertSame('batch_dlq_unpublished', $state['failed_stage']);
            self::assertSame(0, $this->exists(BatchJobStore::auditReservationKey($ddn)));
        }
        foreach ($dispatched as [$auditId, $ddn]) {
            self::assertSame(1, $this->exists(BatchJobStore::auditReservationKey($ddn)));
        }
        self::assertSame(1, $this->exists(BatchJobStore::auditReservationKey('FOREIGN-1')));
        self::assertSame(6, $this->summary($jobId)['failed']);

        // Idempotente.
        self::assertSame(0, $this->store->cancelUndispatchedAudits($jobId));
        self::assertSame($baseline + 4, $this->telemetry('audits_in_flight'));
    }

    /** `W-F2-B3`: tras el reaper, los Lua de generación rechazan con `-2` (epoch viejo o nuevo) sin mutar. */
    public function testReapIncrementsEpochAndGenerationLuaRejectAfterReap(): void
    {
        $jobId = $this->newJob(987654013);
        $epoch = $this->acquireEpoch($jobId);
        self::assertSame(1, $epoch);
        $audits = $this->registerAudits($jobId, 1, $epoch, false);
        self::assertTrue($this->reapFully($jobId, 0)['reaped']);
        $before = $this->summary($jobId);
        self::assertSame(2, $before['generation_epoch']);

        foreach ([1, 2] as $e) {
            self::assertFalse($this->store->publishEmptyBatch($jobId, 0, 0, $e));
            self::assertFalse($this->store->sealJob($jobId, 0, 0, $e));
            self::assertFalse($this->store->storeFrozenCandidates($jobId, [['NitSec' => 1, 'Dispensa' => 'X']], $e));
            self::assertFalse($this->store->markAuditsDispatched($jobId, [$audits[0][1] => $audits[0][0]], true, $e));
            self::assertFalse($this->store->commitChunkCheckpoint($jobId, 1, 10, 0, 0, $e));
        }
        self::assertSame($before, $this->summary($jobId));
        self::assertSame(0, $this->exists(BatchJobStore::jobCandidatesKey($jobId)));

        // Durante reaping (sealed = false) SEAL también rechaza.
        $other = $this->newJob(987654014);
        $otherEpoch = $this->acquireEpoch($other);
        $this->setState($other, ['reaping' => true]);
        self::assertFalse($this->store->sealJob($other, 0, 0, $otherEpoch));
        $state = $this->summary($other);
        self::assertTrue($state['reaping']);
        self::assertFalse($state['sealed']);
    }

    /** `W-F2-I1`: una finalización entre rebanadas no muta `audits` ni `audits_in_flight`; la final no re-escanea. */
    public function testCompletionDuringReapingIsNoOpAndFinalSliceDoesNotRescan(): void
    {
        $jobId = $this->newJob(987654015);
        $epoch = $this->acquireEpoch($jobId);
        $audits = $this->registerAudits($jobId, 3, $epoch, false);
        $this->setState($jobId, ['reaping' => true, 'generation_epoch' => $epoch + 1]);
        $inFlight = $this->telemetry('audits_in_flight');

        self::assertTrue($this->store->markAuditCompletedInJob($jobId, $audits[0][0], 'completed', 10));

        $entry = json_decode((string) $this->hget(BatchJobStore::jobAuditsKey($jobId), $audits[0][0]), true);
        self::assertSame('pending', $entry['status']);
        self::assertSame($inFlight, $this->telemetry('audits_in_flight'));

        self::assertTrue($this->reapFully($jobId, self::STALE_MS)['reaped']);
        $state = $this->summary($jobId);
        self::assertSame(3, $state['failed']);
        self::assertSame(0, $state['done']);
        self::assertSame($state['total'], $state['done'] + $state['failed']);
    }

    /** `W-F2-I2`: `last_progress_at = null/0` no aborta; con umbral > 0 no actúa y con umbral 0 cierra. */
    public function testForceStaleClosesJobWithoutProgressMarkAndToleratesNullTimestamp(): void
    {
        foreach ([null, 0] as $index => $mark) {
            $jobId = $this->newJob(987654020 + $index);
            $epoch = $this->acquireEpoch($jobId);
            $this->registerAudits($jobId, 1, $epoch, false);
            $this->setState($jobId, ['last_progress_at' => $mark, 'updated_at' => '###']);

            self::assertFalse($this->reapFully($jobId, self::STALE_MS)['reaped'], 'Umbral > 0 sin marca válida: no actúa.');
            self::assertNotSame('failed', $this->summary($jobId)['status']);

            self::assertTrue($this->reapFully($jobId, 0)['reaped'], 'Umbral 0 (force-stale) cierra sin marca de progreso.');
            self::assertSame('failed', $this->summary($jobId)['status']);
        }
    }

    /** `W-F2-B2`: scripts de inicio/cierre con `REDIS_PREFIX` no vacío; sin claves fuera del prefijo. */
    public function testInitExclusiveAndCloseScriptsHonorNonEmptyPrefix(): void
    {
        $nit = 987654030;
        $a = $this->newJob($nit, '2026-03-01', '2026-03-31');
        $this->probeUnprefixed('jobs:active:nit:' . $nit);

        // Un solapamiento con un job activo existente se rechaza y NO lo poda.
        $overlap = $this->store->initJobExclusive(AuditEvent::uuidV4(), $nit, '2026-03-15', '2026-04-15', 10);
        self::assertSame(['created' => false, 'conflict_job_id' => $a, 'reason' => 'overlap'], $overlap);
        self::assertSame(1, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $a));

        // Fechas colindantes sin solapamiento: aceptado y conviven en el hash.
        $adjacent = AuditEvent::uuidV4();
        self::assertTrue($this->store->initJobExclusive($adjacent, $nit, '2026-04-01', '2026-04-30', 10)['created']);
        self::assertSame(1, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $adjacent));

        // SEAL_JOB_LUA (0 auditorías ⇒ cierra) y PUBLISH_EMPTY_BATCH_LUA limpian su entrada con el prefijo efectivo.
        self::assertTrue($this->store->sealJob($a));
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $a));
        self::assertTrue($this->store->publishEmptyBatch($adjacent, 0, 0));
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $adjacent));

        // MARK_JOB_FAILED_LUA.
        $failedNit = 987654031;
        $this->probeUnprefixed('jobs:active:nit:' . $failedNit);
        $c = $this->newJob($failedNit);
        self::assertTrue($this->store->markJobFailed($c, 'dlq'));
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($failedNit), $c));

        // DELETE_JOB_LUA descuenta audits_in_flight y el contador del estado leído.
        $deleteNit = 987654032;
        $this->probeUnprefixed('jobs:active:nit:' . $deleteNit);
        $queued = $this->telemetry('jobs_queued');
        $active = $this->telemetry('jobs_active');
        $inFlight = $this->telemetry('audits_in_flight');
        $d = $this->newJob($deleteNit);
        $epoch = $this->acquireEpoch($d);
        $this->registerAudits($d, 1, $epoch, false);
        self::assertTrue($this->store->deleteJob($d));
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($deleteNit), $d));
        self::assertSame(0, $this->exists(BatchJobStore::jobKey($d)));
        self::assertSame(0, $this->exists(BatchJobStore::jobAuditsKey($d)));
        self::assertSame(0, $this->exists(BatchJobStore::jobByDispensaKey($d)));
        self::assertSame($queued, $this->telemetry('jobs_queued'), 'jobs_queued vuelve al valor previo.');
        self::assertSame($active, $this->telemetry('jobs_active'));
        self::assertSame($inFlight, $this->telemetry('audits_in_flight'));

        foreach ([$nit, $failedNit, $deleteNit] as $n) {
            self::assertSame(0, $this->unprefixedExists('jobs:active:nit:' . $n), 'Ningún script crea claves sin prefijo.');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function newJob(int $nit, string $from = '2026-01-01', string $to = '2026-01-31'): string
    {
        $jobId = AuditEvent::uuidV4();
        $result = $this->store->initJobExclusive($jobId, $nit, $from, $to, 100);
        self::assertTrue($result['created'], 'No se pudo crear el job de prueba.');

        return $jobId;
    }

    /** Adquiere el lock, obtiene el epoch y libera el lock (el job queda sin propietario vivo). */
    private function acquireEpoch(string $jobId): int
    {
        $claim = $this->store->claimJobGenerationFencedLock($jobId, 'w-' . $jobId, 1, 60);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'w-' . $jobId, $claim->epoch));

        return (int) $claim->epoch;
    }

    /**
     * Registra `$count` auditorías (con reserva y, opcionalmente, estado `audit:{id}:state`).
     *
     * @param  array<string,mixed>|null  $documents
     * @return array<int,array{0:string,1:string}>  Lista de `[auditId, disDetNro]`.
     */
    private function registerAudits(string $jobId, int $count, int $epoch, bool $withState = true, ?array $documents = null): array
    {
        $audits = [];
        for ($i = 0; $i < $count; $i++) {
            $ddn = 'DDN-' . substr($jobId, 0, 8) . '-' . $i;
            $auditId = AuditEvent::deterministicUuidV4($jobId . ':' . $ddn);
            if ($withState) {
                $token = 'tok-' . $i;
                self::assertTrue($this->store->claimAuditReservation($ddn, $token, ['job_id' => $jobId, 'audit_id' => $auditId]));
                $this->redis->eval(
                    'return redis.call("SET", KEYS[1], ARGV[1])',
                    [AuditStateStore::auditKey($auditId)],
                    [json_encode([
                        'audit_id' => $auditId,
                        'job_id' => $jobId,
                        'dis_det_nro' => $ddn,
                        'status' => 'pending',
                        'documents' => $documents ?? ['doc-1' => ['status' => 'pending']],
                    ])]
                );
            }
            self::assertTrue($this->store->registerAuditInJob($jobId, $auditId, $ddn, 'DIS-' . $i, $withState ? 'tok-' . $i : null, $epoch));
            $audits[] = [$auditId, $ddn];
        }

        return $audits;
    }

    /**
     * @return array{reaped:bool,cancelled:int}
     */
    private function reapFully(string $jobId, int $thresholdMs): array
    {
        $cursor = null;
        $cancelled = 0;
        $reaped = false;
        $guard = 0;
        do {
            $slice = $this->store->reapStaleJob($jobId, $this->nowMs(), $thresholdMs, $cursor);
            $cancelled += $slice['cancelled_audits'];
            $reaped = $reaped || $slice['reaped'];
            $cursor = $slice['cursor'];
        } while (!$slice['done'] && ++$guard < 100);

        return ['reaped' => $reaped, 'cancelled' => $cancelled];
    }

    /** @return array<string,mixed> */
    private function summary(string $jobId): array
    {
        $state = $this->store->getJobSummary($jobId);
        self::assertNotNull($state, 'job:{id}:state no existe');

        return $state;
    }

    /** @param array<string,mixed> $patch  Un valor `null` escribe JSON `null`. */
    private function setState(string $jobId, array $patch): void
    {
        $state = array_merge($this->summary($jobId), $patch);
        $this->redis->eval(
            'return redis.call("SET", KEYS[1], ARGV[1])',
            [BatchJobStore::jobKey($jobId)],
            [json_encode($state)]
        );
    }

    private function telemetry(string $field): int
    {
        return (int) $this->redis->eval('return redis.call("HGET", KEYS[1], ARGV[1])', [BatchJobStore::TELEMETRY_KEY], [$field]);
    }

    private function exists(string $key): int
    {
        return (int) $this->redis->eval('return redis.call("EXISTS", KEYS[1])', [$key]);
    }

    private function ttl(string $key): int
    {
        return (int) $this->redis->eval('return redis.call("TTL", KEYS[1])', [$key]);
    }

    private function hexists(string $key, string $field): int
    {
        return (int) $this->redis->eval('return redis.call("HEXISTS", KEYS[1], ARGV[1])', [$key], [$field]);
    }

    private function hget(string $key, string $field): ?string
    {
        $value = $this->redis->eval('return redis.call("HGET", KEYS[1], ARGV[1])', [$key], [$field]);

        return $value === null || $value === false ? null : (string) $value;
    }

    private function probeUnprefixed(string $key): void
    {
        $this->unprefixedProbe[] = $key;
    }

    /** Existencia de una clave literal (sin el namespace del test) pasada por ARGV, que `eval` no prefija. */
    private function unprefixedExists(string $key): int
    {
        return (int) $this->redis->eval('return redis.call("EXISTS", ARGV[1])', [], [$key]);
    }
}
