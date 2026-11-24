<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

/**
 * SDD-BATCH-001 (IMPL-BATCH-001-B1): flujos centrales de `BatchJobStore` v2 contra Redis 7 real aislado
 * (`RUN_REDIS_INTEGRATION=1`), con `REDIS_PREFIX` no vacío (namespace del test).
 */
final class BatchJobStoreRedisTest extends TestCase
{
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private IsolatedAuditRedis $redis;
    private BatchJobStore $store;

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
            $this->redis->cleanup();
        }
    }

    public function testGetJobRebuildsAuditsMapFromHashWhileSummaryExcludesIt(): void
    {
        $jobId = $this->newJob(987655001);
        $epoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', 'DIS-1', 'tok', $epoch));
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-b', 'D2', 'DIS-1', 'tok', $epoch));

        $full = $this->store->getJob($jobId);
        $summary = $this->store->getJobSummary($jobId);

        self::assertSame(2, $full['total']);
        self::assertSame(['audit-a', 'audit-b'], $this->sortedKeys($full['audits']));
        self::assertSame('D1', $full['audits']['audit-a']['dis_det_nro']);
        self::assertSame('pending', $full['audits']['audit-a']['status']);
        self::assertArrayNotHasKey('audits', $summary);
        self::assertLessThan(2048, strlen((string) $this->redis->get(BatchJobStore::jobKey($jobId))));
        self::assertNull($this->store->getJob('no-existe'));
        self::assertNull($this->store->getJobSummary('no-existe'));
    }

    public function testRegisterIsIdempotentPerDispensaAndFencesStaleEpoch(): void
    {
        $jobId = $this->newJob(987655002);
        $epoch = $this->claimEpoch($jobId, 1);

        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', null, null, $epoch));
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', null, null, $epoch));
        self::assertSame(1, $this->summary($jobId)['total']);

        // Otra auditoría para la misma dispensa viola INV-01.
        try {
            $this->store->registerAuditInJob($jobId, 'audit-otra', 'D1', null, null, $epoch);
            self::fail('Una dispensa solo admite una auditoría por job.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit_dispensa_conflict', $e->getMessage());
        }

        // Epoch obsoleto tras una nueva adquisición.
        $newEpoch = $this->claimEpoch($jobId, 1);
        self::assertSame($epoch + 1, $newEpoch);
        try {
            $this->store->registerAuditInJob($jobId, 'audit-b', 'D2', null, null, $epoch);
            self::fail('Un epoch obsoleto debe rechazarse.');
        } catch (\RuntimeException $e) {
            self::assertSame('generation_fence_stale', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }
        self::assertSame(1, $this->summary($jobId)['total']);
        self::assertSame(
            ['audit_id' => 'audit-a', 'dispatched' => false],
            $this->store->getBatchDispensaEntry($jobId, 'D1')
        );
        self::assertNull($this->store->getBatchDispensaEntry($jobId, 'D-NO-EXISTE'));
    }

    public function testFencedLockSequenceBusyReleaseRenewTerminalAndMissing(): void
    {
        $jobId = $this->newJob(987655003);

        $first = $this->store->claimJobGenerationFencedLock($jobId, 'worker-a', 1, 120);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $first->status);
        self::assertSame(1, $first->epoch);
        self::assertGreaterThan(0, $this->ttl(BatchJobStore::jobGenerationLockKey($jobId)));

        $busy = $this->store->claimJobGenerationFencedLock($jobId, 'worker-b', 1, 120);
        self::assertSame(JobLockClaimStatus::BUSY, $busy->status);
        self::assertSame(1, $this->summary($jobId)['generation_epoch'], 'BUSY no incrementa el epoch.');

        self::assertTrue($this->store->renewJobGenerationLock($jobId, 'worker-a', 1, 300));
        self::assertFalse($this->store->renewJobGenerationLock($jobId, 'worker-a', 2, 300));
        self::assertFalse($this->store->renewJobGenerationLock($jobId, 'worker-b', 1, 300));
        self::assertFalse($this->store->releaseJobGenerationLock($jobId, 'worker-b', 1));
        self::assertFalse($this->store->releaseJobGenerationLock($jobId, 'worker-a', 99));
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'worker-a', 1));

        $second = $this->store->claimJobGenerationFencedLock($jobId, 'worker-b', 1, 120);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $second->status);
        self::assertSame(2, $second->epoch);
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'worker-b'));

        // Job sellado ⇒ TERMINAL; job inexistente ⇒ MISSING.
        self::assertTrue($this->store->sealJob($jobId));
        self::assertSame(JobLockClaimStatus::TERMINAL, $this->store->claimJobGenerationFencedLock($jobId, 'worker-c', 1)->status);
        self::assertSame(JobLockClaimStatus::MISSING, $this->store->claimJobGenerationFencedLock('no-existe', 'worker-c', 1)->status);
    }

    public function testClaimOfChunkTwoPromotesContinuationOfChunkOne(): void
    {
        $jobId = $this->newJob(987655004);
        $epoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->commitChunkCheckpoint($jobId, 1, 500, 0, 0, $epoch));
        self::assertSame(0, $this->summary($jobId)['last_continuation_dispatched_chunk']);

        // Worker B recibe el chunk 2 antes de que A confirme la continuación del 1 (R-CONT).
        $this->claimEpoch($jobId, 2);
        self::assertSame(1, $this->summary($jobId)['last_continuation_dispatched_chunk']);

        // La confirmación tardía de A (sin epoch) es un no-op monotónico.
        self::assertTrue($this->store->markContinuationDispatched($jobId, 1));
        self::assertSame(1, $this->summary($jobId)['last_continuation_dispatched_chunk']);
    }

    public function testCheckpointIsIdempotentAndContinuationRequiresCommittedChunk(): void
    {
        $jobId = $this->newJob(987655005);
        $epoch = $this->claimEpoch($jobId, 1);

        self::assertTrue($this->store->commitChunkCheckpoint($jobId, 1, 500, 2, 3, $epoch, ['disId' => 'X9']));
        $state = $this->summary($jobId);
        self::assertSame(500, $state['next_candidate_offset']);
        self::assertSame(2, $state['skipped_locked']);
        self::assertSame(3, $state['skipped_existing']);
        self::assertSame(1, $state['last_committed_chunk']);
        self::assertSame(['disId' => 'X9'], $state['last_cursor']);

        // Recuperación: el mismo chunk no duplica deltas ni retrocede el offset.
        self::assertTrue($this->store->commitChunkCheckpoint($jobId, 1, 100, 5, 5, $epoch));
        $again = $this->summary($jobId);
        self::assertSame(2, $again['skipped_locked']);
        self::assertSame(500, $again['next_candidate_offset']);

        // Epoch obsoleto ⇒ rechazo sin mutar.
        self::assertFalse($this->store->commitChunkCheckpoint($jobId, 2, 900, 1, 1, $epoch + 1));
        self::assertSame(1, $this->summary($jobId)['last_committed_chunk']);

        self::assertTrue($this->store->markContinuationDispatched($jobId, 1));
        self::assertSame(1, $this->summary($jobId)['last_continuation_dispatched_chunk']);
        self::assertFalse($this->store->markContinuationDispatched('no-existe', 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('continuation_before_checkpoint');
        $this->store->markContinuationDispatched($jobId, 2);
    }

    public function testFreezeStoresReadsInBatchesAndIsIdempotent(): void
    {
        $jobId = $this->newJob(987655006);
        $epoch = $this->claimEpoch($jobId, 1);
        $candidates = [];
        for ($i = 0; $i < 1100; $i++) {
            $candidates[] = ['NitSec' => 987655006, 'DisId' => 'X' . intdiv($i, 2), 'Dispensa' => 'D' . $i, 'DisFecSol' => '2026-01-02'];
        }

        self::assertFalse($this->store->storeFrozenCandidates($jobId, $candidates, $epoch + 1), 'Epoch obsoleto.');
        self::assertSame(0, $this->exists(BatchJobStore::jobCandidatesKey($jobId)));

        self::assertTrue($this->store->storeFrozenCandidates($jobId, $candidates, $epoch));
        $state = $this->summary($jobId);
        self::assertTrue($state['candidates_frozen']);
        self::assertSame(1100, $state['candidates_count']);
        self::assertSame(0, $state['next_candidate_offset']);
        self::assertSame(1100, (int) $this->redis->eval('return redis.call("LLEN", KEYS[1])', [BatchJobStore::jobCandidatesKey($jobId)]));

        $slice = $this->store->readFrozenCandidates($jobId, 498, 4);
        self::assertSame(['D498', 'D499', 'D500', 'D501'], array_column($slice, 'Dispensa'));
        self::assertCount(100, $this->store->readFrozenCandidates($jobId, 1000, 500));
        self::assertSame([], $this->store->readFrozenCandidates($jobId, 5000, 10));

        // Reentrega: no duplica ni reinicia el offset.
        self::assertTrue($this->store->commitChunkCheckpoint($jobId, 1, 500, 0, 0, $epoch));
        self::assertTrue($this->store->storeFrozenCandidates($jobId, $candidates, $epoch));
        self::assertSame(1100, (int) $this->redis->eval('return redis.call("LLEN", KEYS[1])', [BatchJobStore::jobCandidatesKey($jobId)]));
        self::assertSame(500, $this->summary($jobId)['next_candidate_offset']);
    }

    public function testMarkAuditsDispatchedUpdatesEntriesAndBatchCreatedFlag(): void
    {
        $jobId = $this->newJob(987655007);
        $epoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', null, null, $epoch));
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-b', 'D2', null, null, $epoch));

        self::assertTrue($this->store->markAuditsDispatched($jobId, ['D1' => 'audit-a', 'D2' => 'otra-auditoria'], true, $epoch));

        self::assertSame(true, $this->store->getBatchDispensaEntry($jobId, 'D1')['dispatched']);
        self::assertSame(false, $this->store->getBatchDispensaEntry($jobId, 'D2')['dispatched'], 'Un auditId distinto no marca la entrada.');
        self::assertTrue($this->summary($jobId)['batch_created_published']);
        self::assertFalse($this->store->markAuditsDispatched($jobId, ['D2' => 'audit-b'], false, $epoch + 1));
    }

    /** `R-EMPTY` / cierre al sellar: cierra si `done + failed >= total`, incluso con `total == 0`. */
    public function testSealClosesWhenAllProcessedAndWhenJobIsEmpty(): void
    {
        $nit = 987655008;
        $jobId = $this->newJob($nit);
        $epoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', null, null, $epoch));
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-b', 'D2', null, null, $epoch));
        self::assertTrue($this->store->storeFrozenCandidates($jobId, [['Dispensa' => 'D1']], $epoch));
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, 'audit-a', 'completed', 10));
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, 'audit-b', 'failed', 10, 'extraction'));
        self::assertSame('processing', $this->summary($jobId)['status']);
        $completed = $this->telemetry('jobs_completed');
        $running = $this->telemetry('jobs_running');

        self::assertTrue($this->store->sealJob($jobId, 4, 5, $epoch, 2, 700));

        $state = $this->summary($jobId);
        self::assertSame('completed_with_errors', $state['status']);
        self::assertTrue($state['sealed']);
        self::assertSame(2, $state['total']);
        self::assertSame(2, $state['accepted']);
        self::assertSame(4, $state['skipped_locked']);
        self::assertSame(5, $state['skipped_existing']);
        self::assertSame(700, $state['next_candidate_offset']);
        self::assertSame(2, $state['last_committed_chunk']);
        self::assertMatchesRegularExpression(self::ISO, (string) $state['completed_at']);
        self::assertSame(0, $this->exists(BatchJobStore::jobCandidatesKey($jobId)));
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        self::assertSame($completed + 1, $this->telemetry('jobs_completed'));
        self::assertSame($running - 1, $this->telemetry('jobs_running'));
        self::assertFalse($this->store->sealJob($jobId, 0, 0, $epoch), 'Un job ya sellado/terminal rechaza.');

        // Job multichunk con todo omitido: total == 0 cierra como completed.
        $emptyNit = 987655009;
        $empty = $this->newJob($emptyNit);
        $emptyEpoch = $this->claimEpoch($empty, 1);
        self::assertTrue($this->store->sealJob($empty, 3, 0, $emptyEpoch));
        $emptyState = $this->summary($empty);
        self::assertSame('completed', $emptyState['status']);
        self::assertSame(0, $emptyState['total']);
        self::assertSame(3, $emptyState['skipped_locked']);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($emptyNit), $empty));
    }

    public function testLastCompletionAfterSealClosesJobAndReleasesNit(): void
    {
        $nit = 987655010;
        $jobId = $this->newJob($nit);
        $epoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-a', 'D1', null, null, $epoch));
        self::assertTrue($this->store->registerAuditInJob($jobId, 'audit-b', 'D2', null, null, $epoch));
        self::assertTrue($this->store->sealJob($jobId, 0, 0, $epoch));
        self::assertSame('queued', $this->summary($jobId)['status']);
        $inFlight = $this->telemetry('audits_in_flight');

        self::assertTrue($this->store->markAuditCompletedInJob($jobId, 'audit-a', 'completed', 100));
        self::assertSame('processing', $this->summary($jobId)['status']);
        self::assertSame(1, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        // Idempotente por auditoría.
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, 'audit-a', 'completed', 100));
        self::assertTrue($this->store->markAuditCompletedInJob($jobId, 'audit-b', 'manual_review', 300, 'policy'));

        $state = $this->summary($jobId);
        self::assertSame('completed', $state['status']);
        self::assertSame(2, $state['done']);
        self::assertSame(0, $state['failed']);
        self::assertSame(200, $state['avg_duration_ms']);
        self::assertSame(400, $state['accumulated_duration_ms']);
        self::assertGreaterThan(0, $state['last_progress_at']);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        self::assertSame($inFlight - 2, $this->telemetry('audits_in_flight'));
        $audits = $this->store->getJob($jobId)['audits'];
        self::assertSame('manual_review', $audits['audit-b']['status']);
        self::assertSame('policy', $audits['audit-b']['failed_stage']);
        self::assertFalse($this->store->markAuditCompletedInJob('no-existe', 'audit-x', 'completed'), 'Job inexistente: el Lua retorna 0.');
    }

    public function testPublishEmptyBatchClosesFreshJobAndMarkJobFailedHonorsContinuationDedupe(): void
    {
        $nit = 987655011;
        $empty = $this->newJob($nit);
        $epoch = $this->claimEpoch($empty, 1);
        self::assertFalse($this->store->publishEmptyBatch($empty, 0, 0, $epoch + 1));
        self::assertTrue($this->store->publishEmptyBatch($empty, 2, 1, $epoch));
        $state = $this->summary($empty);
        self::assertSame('completed', $state['status']);
        self::assertSame(0, $state['total']);
        self::assertSame(2, $state['skipped_locked']);
        self::assertSame(1, $state['skipped_existing']);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $empty));
        self::assertFalse($this->store->markJobFailed($empty, 'tarde', $epoch), 'Un job completed no se convierte en failed.');

        // Chunk ya confirmado y continuado: el DLQ de ese chunk se ignora.
        $jobId = $this->newJob(987655012);
        $jobEpoch = $this->claimEpoch($jobId, 1);
        self::assertTrue($this->store->commitChunkCheckpoint($jobId, 1, 10, 0, 0, $jobEpoch));
        self::assertTrue($this->store->markContinuationDispatched($jobId, 1));
        self::assertFalse($this->store->markJobFailed($jobId, 'dlq chunk 1', $jobEpoch, 1));
        self::assertNotSame('failed', $this->summary($jobId)['status']);
        self::assertTrue($this->store->markJobFailed($jobId, 'dlq chunk 2', $jobEpoch, 2));
        self::assertSame('failed', $this->summary($jobId)['status']);
    }

    public function testListJobsReadsStateKeysWithTheEffectivePrefix(): void
    {
        $jobId = $this->newJob(987655013);

        $jobs = $this->store->listJobs(10);

        self::assertContains($jobId, array_column($jobs, 'job_id'));
        $row = $jobs[array_search($jobId, array_column($jobs, 'job_id'), true)];
        self::assertSame('pending', $row['status']);
        self::assertSame(987655013, $row['fac_nit_sec']);
    }

    public function testIdempotencyKeyReleaseOnlyWhenOwnerMatches(): void
    {
        self::assertNull($this->store->claimIdempotencyKey('idem-1', 'job-owner', 300));
        self::assertSame('job-owner', $this->store->claimIdempotencyKey('idem-1', 'job-otro', 300));

        self::assertFalse($this->store->releaseIdempotencyKeyIfMatches('idem-1', 'job-otro'));
        self::assertSame(1, $this->exists(BatchJobStore::idempotencyKey('idem-1')));
        self::assertTrue($this->store->releaseIdempotencyKeyIfMatches('idem-1', 'job-owner'));
        self::assertSame(0, $this->exists(BatchJobStore::idempotencyKey('idem-1')));
        self::assertFalse($this->store->releaseIdempotencyKeyIfMatches('idem-1', 'job-owner'));
    }

    public function testReservationsAreKeyedByDisDetNroAndReleasedByOwnerToken(): void
    {
        $key = BatchJobStore::auditReservationKey('T777');

        self::assertTrue($this->store->claimAuditReservation('T777', 'tok-a', ['job_id' => 'j1', 'audit_id' => 'a1', 'dis_id' => 'D-9']));
        self::assertFalse($this->store->claimAuditReservation('T777', 'tok-b', ['job_id' => 'j2']), 'SET NX: la segunda reserva falla.');
        self::assertTrue($this->store->claimAuditReservation('T778', 'tok-c', ['job_id' => 'j1']), 'Una dispensa hermana (mismo DisId) tiene su propia reserva.');
        self::assertSame(-1, $this->ttl($key), 'Sin TTL automático (INV-04).');

        $reservation = $this->store->getAuditReservation('T777');
        self::assertSame('T777', $reservation['dis_det_nro']);
        self::assertSame('tok-a', $reservation['token']);
        self::assertSame('D-9', $reservation['dis_id']);

        self::assertFalse($this->store->releaseAuditReservation('T777', 'tok-b'));
        self::assertFalse($this->store->releaseAuditReservationFromAudit(['dis_det_nro' => 'T777', 'reservation_token' => 'tok-b']));
        self::assertTrue($this->store->releaseAuditReservationFromAudit(['dis_det_nro' => 'T777', 'reservation_token' => 'tok-a']));
        self::assertNull($this->store->getAuditReservation('T777'));
        self::assertNotNull($this->store->getAuditReservation('T778'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function newJob(int $nit): string
    {
        $jobId = AuditEvent::uuidV4();
        self::assertTrue($this->store->initJobExclusive($jobId, $nit, '2026-01-01', '2026-01-31', 100)['created']);

        return $jobId;
    }

    /** Adquiere el lock del chunk indicado, lo libera y devuelve el epoch obtenido. */
    private function claimEpoch(string $jobId, int $chunkIndex): int
    {
        $worker = 'w-' . AuditEvent::uuidV4();
        $claim = $this->store->claimJobGenerationFencedLock($jobId, $worker, $chunkIndex, 60);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, $worker, $claim->epoch));

        return (int) $claim->epoch;
    }

    /** @return array<string,mixed> */
    private function summary(string $jobId): array
    {
        $state = $this->store->getJobSummary($jobId);
        self::assertNotNull($state, 'job:{id}:state no existe');

        return $state;
    }

    /**
     * @param  array<string,mixed>  $map
     * @return array<int,string>
     */
    private function sortedKeys(array $map): array
    {
        $keys = array_map('strval', array_keys($map));
        sort($keys);

        return $keys;
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
}
