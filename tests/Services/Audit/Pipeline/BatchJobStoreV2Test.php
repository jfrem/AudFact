<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SDD-BATCH-001 v1.2, sección 13 (`BatchJobStore` v2): claves, fencing tipado, firmas y reglas transversales de los Lua.
 */
final class BatchJobStoreV2Test extends TestCase
{
    /** @var array<int,array{script:string,keys:array<int,string>,args:array<int,mixed>}> */
    private array $calls = [];

    /**
     * @param  mixed|callable  $returns  Valor de retorno de `eval` o callable que lo calcula.
     */
    private function store(mixed $returns = 1): BatchJobStore
    {
        $this->calls = [];
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturnCallback(
            function (string $script, array $keys = [], array $args = []) use ($returns): mixed {
                $this->calls[] = ['script' => $script, 'keys' => $keys, 'args' => $args];
                return $returns instanceof \Closure ? $returns(count($this->calls)) : $returns;
            }
        );

        return new BatchJobStore($redis);
    }

    private static function lua(string $constant): string
    {
        return (string) (new \ReflectionClassConstant(BatchJobStore::class, $constant))->getValue();
    }

    // ── Claves ───────────────────────────────────────────────────────────

    public function testKeyBuildersMatchTheDataModel(): void
    {
        $this->assertSame('job:j1:state', BatchJobStore::jobKey('j1'));
        $this->assertSame('job:j1:audits', BatchJobStore::jobAuditsKey('j1'));
        $this->assertSame('job:j1:by_dispensa', BatchJobStore::jobByDispensaKey('j1'));
        $this->assertSame('job:j1:candidates', BatchJobStore::jobCandidatesKey('j1'));
        $this->assertSame('jobs:active:nit:2624', BatchJobStore::activeNitJobsKey(2624));
        $this->assertSame('audit:reservation:disdetnro:T123', BatchJobStore::auditReservationKey('T123'));
        $this->assertSame('batch:claim:j1', BatchJobStore::jobGenerationLockKey('j1'));
    }

    public function testReleaseReservationFromAuditUsesDisDetNroAndToken(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->releaseAuditReservationFromAudit([
            'dis_det_nro' => ' T999 ',
            'dis_id' => 'ignored-dis-id',
            'reservation_token' => ' tok ',
        ]));
        $this->assertSame([BatchJobStore::auditReservationKey('T999')], $this->calls[0]['keys']);
        $this->assertSame(['tok'], $this->calls[0]['args']);

        $this->calls = [];
        $this->assertFalse($store->releaseAuditReservationFromAudit(['dis_id' => 'D-1', 'reservation_token' => 'tok']));
        $this->assertSame([], $this->calls, 'Sin dis_det_nro no se invoca Redis.');
    }

    // ── Fencing tipado ───────────────────────────────────────────────────

    public function testFencedClaimAcquiredReturnsTypedResultAndSendsExpectedArguments(): void
    {
        $store = $this->store(['acquired', 3]);

        $result = $store->claimJobGenerationFencedLock('j1', 'worker-a', 2, 900);

        $this->assertSame(JobLockClaimStatus::ACQUIRED, $result->status);
        $this->assertSame(3, $result->epoch);
        $this->assertSame([BatchJobStore::jobGenerationLockKey('j1'), BatchJobStore::jobKey('j1')], $this->calls[0]['keys']);
        $this->assertSame(['worker-a', '900', '2'], $this->calls[0]['args']);
        $this->assertStringContainsString("return {'acquired', epoch}", $this->calls[0]['script']);
    }

    /**
     * @return array<string,array{0:mixed,1:JobLockClaimStatus,2:int}>
     */
    public static function nonAcquiredClaims(): array
    {
        return [
            'busy' => [['busy', 4], JobLockClaimStatus::BUSY, 4],
            'terminal' => [['terminal', 5], JobLockClaimStatus::TERMINAL, 5],
            'missing' => [['missing', 0], JobLockClaimStatus::MISSING, 0],
        ];
    }

    #[DataProvider('nonAcquiredClaims')]
    public function testFencedClaimDecodesBusyTerminalAndMissing(mixed $raw, JobLockClaimStatus $status, int $epoch): void
    {
        $result = $this->store($raw)->claimJobGenerationFencedLock('j1', 'worker-a', 1);

        $this->assertSame($status, $result->status);
        $this->assertSame($epoch, $result->epoch);
    }

    public function testFencedClaimRejectsUnknownStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_generation_lock_claim_response');

        $this->store(['weird', 1])->claimJobGenerationFencedLock('j1', 'worker-a', 1);
    }

    public function testFencedClaimRejectsNonArrayResponse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_generation_lock_claim_response');

        $this->store(1)->claimJobGenerationFencedLock('j1', 'worker-a', 1);
    }

    public function testFencedClaimWithEmptyIdentifiersIsMissingWithoutCallingRedis(): void
    {
        $store = $this->store(['acquired', 1]);

        $this->assertSame(JobLockClaimStatus::MISSING, $store->claimJobGenerationFencedLock('', 'w', 1)->status);
        $this->assertSame(JobLockClaimStatus::MISSING, $store->claimJobGenerationFencedLock('j', '', 1)->status);
        $this->assertSame([], $this->calls);
    }

    public function testFencedClaimWrapsRedisFailuresInRuntimeException(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        (new BatchJobStore($redis))->claimJobGenerationFencedLock('j1', 'worker-a', 1);
    }

    public function testRenewAndReleaseSendWorkerAndEpoch(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->renewJobGenerationLock('j1', 'worker-a', 7, 120));
        $this->assertSame([BatchJobStore::jobGenerationLockKey('j1')], $this->calls[0]['keys']);
        $this->assertSame(['worker-a', '7', '120'], $this->calls[0]['args']);

        $this->assertTrue($store->releaseJobGenerationLock('j1', 'worker-a', 7));
        $this->assertSame(['worker-a', '7'], $this->calls[1]['args']);

        $this->assertTrue($store->releaseJobGenerationLock('j1', 'worker-a'));
        $this->assertSame(['worker-a', ''], $this->calls[2]['args'], 'Sin epoch no se valida el epoch.');
    }

    public function testRenewReturnsFalseWhenClaimDoesNotMatch(): void
    {
        $this->assertFalse($this->store(0)->renewJobGenerationLock('j1', 'worker-a', 7));
    }

    public function testReleaseAcceptsLegacyPlainWorkerValueAndJsonClaim(): void
    {
        $script = self::lua('RELEASE_JOB_GENERATION_LOCK_LUA');

        $this->assertStringContainsString('tostring(raw) == workerId', $script);
        $this->assertStringContainsString('cjson.decode', $script);
        $this->assertStringContainsString('claim.epoch', $script);
    }

    // ── initJobExclusive ─────────────────────────────────────────────────

    public function testInitJobExclusiveCreated(): void
    {
        $store = $this->store(['created', '']);

        $result = $store->initJobExclusive('j1', 2624, '2026-01-01', '2026-01-31', 500);

        $this->assertSame(['created' => true, 'conflict_job_id' => null, 'reason' => null], $result);
        $this->assertSame(
            [BatchJobStore::jobKey('j1'), 'jobs:index', BatchJobStore::activeNitJobsKey(2624), BatchJobStore::TELEMETRY_KEY],
            $this->calls[0]['keys']
        );
        $state = json_decode((string) $this->calls[0]['args'][4], true);
        $this->assertSame('pending', $state['status']);
        $this->assertArrayNotHasKey('audits', $state, 'El estado v2 no incluye el mapa de auditorías.');
        $this->assertSame(0, $state['generation_epoch']);
        $this->assertGreaterThan(0, $state['last_progress_at']);
        $this->assertSame(
            ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'],
            json_decode((string) $this->calls[0]['args'][5], true)
        );
    }

    public function testInitJobExclusiveConflictReportsOverlappingJob(): void
    {
        $result = $this->store(['conflict', 'job-activo'])->initJobExclusive('j2', 2624, '2026-01-10', '2026-02-10', 10);

        $this->assertSame(['created' => false, 'conflict_job_id' => 'job-activo', 'reason' => 'overlap'], $result);
    }

    public function testInitJobExclusiveExistingJobId(): void
    {
        $result = $this->store(['exists', ''])->initJobExclusive('j1', 2624, '2026-01-01', '2026-01-31', 10);

        $this->assertSame(['created' => false, 'conflict_job_id' => null, 'reason' => 'job_exists'], $result);
    }

    // ── registerAuditInJob ───────────────────────────────────────────────

    public function testRegisterAuditInJobUsesFourKeysAndEpoch(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->registerAuditInJob('j1', 'a1', 'D1', 'DIS1', 'tok', 4));
        $this->assertSame(
            [
                BatchJobStore::jobKey('j1'),
                BatchJobStore::TELEMETRY_KEY,
                BatchJobStore::jobAuditsKey('j1'),
                BatchJobStore::jobByDispensaKey('j1'),
            ],
            $this->calls[0]['keys']
        );
        $this->assertSame('a1', $this->calls[0]['args'][0]);
        $this->assertSame('D1', $this->calls[0]['args'][1]);
        $this->assertSame('4', $this->calls[0]['args'][6]);
    }

    public function testRegisterAuditInJobWithoutEpochSkipsFencing(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->registerAuditInJob('j1', 'a1', 'D1'));
        $this->assertSame('', $this->calls[0]['args'][6]);
    }

    public function testRegisterAuditInJobStaleEpochThrows409(): void
    {
        try {
            $this->store(-1)->registerAuditInJob('j1', 'a1', 'D1', null, null, 1);
            $this->fail('Un epoch obsoleto debe lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame('generation_fence_stale', $e->getMessage());
            $this->assertSame(409, $e->getCode());
        }
    }

    public function testRegisterAuditInJobClosedOrReapingThrows409(): void
    {
        try {
            $this->store(-2)->registerAuditInJob('j1', 'a1', 'D1', null, null, 1);
            $this->fail('Un job cerrado debe lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame('job_closed_or_reaping', $e->getMessage());
            $this->assertSame(409, $e->getCode());
        }
    }

    public function testRegisterAuditInJobMissingJobReturnsFalse(): void
    {
        $this->assertFalse($this->store(0)->registerAuditInJob('j1', 'a1', 'D1'));
    }

    // ── Candidatas congeladas y checkpoints ──────────────────────────────

    public function testStoreFrozenCandidatesSendsEpochAndCompactTuples(): void
    {
        $store = $this->store(1);
        $candidates = [
            ['NitSec' => 2624, 'DisId' => 'X1', 'Dispensa' => 'D1', 'DisFecSol' => '2026-01-02'],
            ['NitSec' => 2624, 'DisId' => 'X1', 'Dispensa' => 'D2', 'DisFecSol' => '2026-01-02'],
        ];

        $this->assertTrue($store->storeFrozenCandidates('j1', $candidates, 3));
        $this->assertSame([BatchJobStore::jobKey('j1'), BatchJobStore::jobCandidatesKey('j1')], $this->calls[0]['keys']);
        $this->assertSame('3', $this->calls[0]['args'][0]);
        $this->assertCount(4, $this->calls[0]['args']);
        $this->assertSame('D2', json_decode((string) $this->calls[0]['args'][3], true)['Dispensa']);
    }

    public function testStoreFrozenCandidatesReturnsFalseOnStaleEpochOrClosedJob(): void
    {
        $this->assertFalse($this->store(-1)->storeFrozenCandidates('j1', [], 1));
        $this->assertFalse($this->store(-2)->storeFrozenCandidates('j1', [], 1));
        $this->assertFalse($this->store(0)->storeFrozenCandidates('j1', [], 1));
    }

    public function testReadFrozenCandidatesUsesInclusiveLrangeAndDecodesRows(): void
    {
        $store = $this->store(['{"Dispensa":"D1"}', '{"Dispensa":"D2"}', 'no-json']);

        $rows = $store->readFrozenCandidates('j1', 10, 3);

        $this->assertSame([['Dispensa' => 'D1'], ['Dispensa' => 'D2']], $rows);
        $this->assertSame([BatchJobStore::jobCandidatesKey('j1')], $this->calls[0]['keys']);
        $this->assertSame(['10', '12'], $this->calls[0]['args']);
        $this->assertStringContainsString('LRANGE', $this->calls[0]['script']);
    }

    public function testReadFrozenCandidatesWithNonPositiveCountDoesNotCallRedis(): void
    {
        $store = $this->store([]);

        $this->assertSame([], $store->readFrozenCandidates('j1', 0, 0));
        $this->assertSame([], $this->calls);
    }

    public function testGetBatchDispensaEntryDecodesAuditIdAndDispatchedFlag(): void
    {
        $store = $this->store('{"audit_id":"a1","dispatched":true}');

        $this->assertSame(['audit_id' => 'a1', 'dispatched' => true], $store->getBatchDispensaEntry('j1', 'D1'));
        $this->assertSame([BatchJobStore::jobByDispensaKey('j1')], $this->calls[0]['keys']);
        $this->assertSame(['D1'], $this->calls[0]['args']);
    }

    public function testGetBatchDispensaEntryReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->store(null)->getBatchDispensaEntry('j1', 'D1'));
        $this->assertNull($this->store(false)->getBatchDispensaEntry('j1', 'D1'));
    }

    public function testMarkAuditsDispatchedAcceptsMapAndListFormats(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->markAuditsDispatched('j1', ['D1' => 'a1', '2002' => 'a2'], true, 5));
        $this->assertSame([BatchJobStore::jobKey('j1'), BatchJobStore::jobByDispensaKey('j1')], $this->calls[0]['keys']);
        $args = $this->calls[0]['args'];
        $this->assertSame('5', $args[0]);
        $this->assertSame('1', $args[1]);
        $this->assertSame(['D1', 'a1', '2002', 'a2'], array_slice($args, 4));

        $this->assertTrue($store->markAuditsDispatched(
            'j1',
            [['dis_det_nro' => 'D3', 'audit_id' => 'a3']],
            false,
            5
        ));
        $this->assertSame('0', $this->calls[1]['args'][1]);
        $this->assertSame(['D3', 'a3'], array_slice($this->calls[1]['args'], 4));
    }

    public function testMarkAuditsDispatchedFalseOnStaleEpochOrClosedJob(): void
    {
        $this->assertFalse($this->store(-1)->markAuditsDispatched('j1', [], false, 1));
        $this->assertFalse($this->store(-2)->markAuditsDispatched('j1', [], false, 1));
    }

    public function testCommitChunkCheckpointSendsDeltasEpochAndCursor(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->commitChunkCheckpoint('j1', 2, 500, 3, 4, 6, ['disId' => 'X9']));
        $this->assertSame([BatchJobStore::jobKey('j1')], $this->calls[0]['keys']);
        $args = $this->calls[0]['args'];
        $this->assertSame(['2', '500', '3', '4', '6'], array_slice($args, 0, 5));
        $this->assertSame(['disId' => 'X9'], json_decode((string) $args[5], true));

        $this->assertTrue($store->commitChunkCheckpoint('j1', 3, 600, 0, 0, 6));
        $this->assertSame('', $this->calls[1]['args'][5]);
    }

    public function testCommitChunkCheckpointFalseOnRejection(): void
    {
        $this->assertFalse($this->store(-1)->commitChunkCheckpoint('j1', 1, 0, 0, 0, 1));
        $this->assertFalse($this->store(-2)->commitChunkCheckpoint('j1', 1, 0, 0, 0, 1));
        $this->assertFalse($this->store(0)->commitChunkCheckpoint('j1', 1, 0, 0, 0, 1));
    }

    public function testMarkContinuationDispatchedMapsResults(): void
    {
        $this->assertTrue($this->store(1)->markContinuationDispatched('j1', 2));
        $this->assertFalse($this->store(0)->markContinuationDispatched('j1', 0));
        $this->assertSame([BatchJobStore::jobKey('j1')], $this->calls[0]['keys']);
        $this->assertSame(['0'], $this->calls[0]['args']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('continuation_before_checkpoint');
        $this->store(-1)->markContinuationDispatched('j1', 2);
    }

    public function testMarkContinuationDispatchedScriptDoesNotValidateEpoch(): void
    {
        $script = self::lua('MARK_CONTINUATION_DISPATCHED_LUA');

        $this->assertStringNotContainsString('generation_epoch', $script);
        $this->assertStringContainsString('last_committed_chunk', $script);
        $this->assertStringContainsString('return -1', $script);
    }

    // ── Cierre y finalización ────────────────────────────────────────────

    public function testSealJobV2SendsDeltasEpochAndChunkInfo(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->sealJob('j1', 2, 3, 7, 4, 900));
        $this->assertSame(
            [
                BatchJobStore::jobKey('j1'),
                BatchJobStore::TELEMETRY_KEY,
                BatchJobStore::jobAuditsKey('j1'),
                BatchJobStore::jobCandidatesKey('j1'),
            ],
            $this->calls[0]['keys']
        );
        $this->assertSame(['2', '3', '7', '4', '900'], array_slice($this->calls[0]['args'], 0, 5));
    }

    public function testSealJobV2DefaultsDoNotFenceAndRejectionReturnsFalse(): void
    {
        $store = $this->store(1);
        $this->assertTrue($store->sealJob('j1'));
        $this->assertSame(['0', '0', '', '', ''], array_slice($this->calls[0]['args'], 0, 5));

        $this->assertFalse($this->store(-2)->sealJob('j1', 0, 0, 1));
        $this->assertFalse($this->store(-1)->sealJob('j1', 0, 0, 1));
    }

    public function testSealJobLegacySignatureStillMergesTotalThroughPatch(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->sealJob('j1', 8, ['skipped_locked' => 2]));
        $this->assertSame([BatchJobStore::jobKey('j1')], $this->calls[0]['keys']);
        $patch = $this->calls[0]['args'][0];
        $this->assertIsArray($patch);
        $this->assertTrue($patch['sealed']);
        $this->assertSame(8, $patch['total']);
        $this->assertSame(2, $patch['skipped_locked']);
    }

    public function testPublishEmptyBatchArgumentsAndRejection(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->publishEmptyBatch('j1', 1, 2, 3));
        $this->assertSame(
            [BatchJobStore::jobKey('j1'), BatchJobStore::TELEMETRY_KEY, BatchJobStore::jobCandidatesKey('j1')],
            $this->calls[0]['keys']
        );
        $this->assertSame(['1', '2', '3'], array_slice($this->calls[0]['args'], 0, 3));
        $this->assertFalse($this->store(-2)->publishEmptyBatch('j1', 0, 0, 1));
    }

    public function testMarkJobFailedReturnsFalseWithoutThrowingOnRejection(): void
    {
        $this->assertTrue($this->store(1)->markJobFailed('j1', 'boom', 2, 3));
        $this->assertSame(['boom', '2', '3'], array_slice($this->calls[0]['args'], 0, 3));

        $this->assertFalse($this->store(-1)->markJobFailed('j1', 'boom', 1));
        $this->assertFalse($this->store(0)->markJobFailed('j1', 'boom', 1));
    }

    public function testMarkJobFailedWithoutEpochSendsZero(): void
    {
        $store = $this->store(1);

        $store->markJobFailed('j1', 'boom');

        $this->assertSame('0', $this->calls[0]['args'][1]);
    }

    public function testDeleteJobUsesSevenKeysInDocumentedOrder(): void
    {
        $store = $this->store(1);

        $this->assertTrue($store->deleteJob('j1'));
        $this->assertSame(
            [
                BatchJobStore::jobKey('j1'),
                BatchJobStore::jobAuditsKey('j1'),
                BatchJobStore::jobByDispensaKey('j1'),
                BatchJobStore::jobCandidatesKey('j1'),
                BatchJobStore::jobGenerationLockKey('j1'),
                'jobs:index',
                BatchJobStore::TELEMETRY_KEY,
            ],
            $this->calls[0]['keys']
        );
        $this->assertSame(['j1'], $this->calls[0]['args']);
        $this->assertFalse($this->store(0)->deleteJob('missing'));
    }

    public function testCancelUndispatchedAuditsIteratesSlicesUntilCursorZero(): void
    {
        $store = $this->store(static fn (int $call): array => match ($call) {
            1 => ['17', 2],
            2 => ['23', 1],
            default => ['0', 4],
        });

        $this->assertSame(7, $store->cancelUndispatchedAudits('j1'));
        $this->assertCount(3, $this->calls);
        $this->assertSame('0', $this->calls[0]['args'][0]);
        $this->assertSame('17', $this->calls[1]['args'][0]);
        $this->assertSame('23', $this->calls[2]['args'][0]);
        $this->assertSame('batch_dlq_unpublished', $this->calls[0]['args'][1]);
        $this->assertSame(
            [
                BatchJobStore::jobKey('j1'),
                BatchJobStore::jobByDispensaKey('j1'),
                BatchJobStore::jobAuditsKey('j1'),
                BatchJobStore::TELEMETRY_KEY,
            ],
            $this->calls[0]['keys']
        );
    }

    // ── Lectura ──────────────────────────────────────────────────────────

    public function testGetJobSummaryReadsOnlyTheStateKey(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('get')->with('job:j1:state')->willReturn('{"job_id":"j1","status":"queued"}');
        $redis->expects($this->never())->method('eval');

        $this->assertSame(['job_id' => 'j1', 'status' => 'queued'], (new BatchJobStore($redis))->getJobSummary('j1'));
    }

    public function testGetJobSummaryReturnsNullWhenMissing(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(null);

        $this->assertNull((new BatchJobStore($redis))->getJobSummary('j1'));
    }

    public function testGetJobRebuildsAuditsFromHashViaLua(): void
    {
        $store = $this->store('{"job_id":"j1","audits":{"a1":{"dis_det_nro":"D1","status":"pending"}}}');

        $job = $store->getJob('j1');

        $this->assertSame('pending', $job['audits']['a1']['status']);
        $this->assertSame([BatchJobStore::jobKey('j1'), BatchJobStore::jobAuditsKey('j1')], $this->calls[0]['keys']);
        $this->assertNull($this->store(null)->getJob('missing'));
    }

    // ── Reaper ───────────────────────────────────────────────────────────

    public function testReapStaleJobBuildsKeysAndArgumentsFromSummary(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(json_encode([
            'job_id' => 'j1',
            'fac_nit_sec' => 2624,
            'updated_at' => '2026-01-02T03:04:05Z',
        ]));
        $captured = [];
        $redis->method('eval')->willReturnCallback(
            static function (string $script, array $keys, array $args) use (&$captured): array {
                $captured = ['keys' => $keys, 'args' => $args];
                return [1, '0', 3];
            }
        );

        $result = (new BatchJobStore($redis))->reapStaleJob('j1', 1_700_000_000_000, 21_600_000);

        $this->assertSame(
            [
                BatchJobStore::jobKey('j1'),
                BatchJobStore::jobAuditsKey('j1'),
                BatchJobStore::activeNitJobsKey(2624),
                BatchJobStore::TELEMETRY_KEY,
                BatchJobStore::jobCandidatesKey('j1'),
                BatchJobStore::jobGenerationLockKey('j1'),
            ],
            $captured['keys']
        );
        $this->assertSame('1700000000000', $captured['args'][0]);
        $this->assertSame('21600000', $captured['args'][1]);
        $this->assertSame('stale_no_progress', $captured['args'][2]);
        $this->assertSame('0', $captured['args'][3]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $captured['args'][4]);
        $this->assertSame((string) (strtotime('2026-01-02T03:04:05Z') * 1000), $captured['args'][5]);
        $this->assertSame(['done' => true, 'cursor' => null, 'cancelled_audits' => 3, 'reaped' => true], $result);
    }

    public function testReapStaleJobReportsPendingSlicesAndNoOp(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(json_encode(['job_id' => 'j1', 'fac_nit_sec' => 1, 'updated_at' => 'no-date']));
        $returns = [[0, '1234', 5], [-1, '0', 0]];
        $redis->method('eval')->willReturnCallback(static function () use (&$returns): array {
            return array_shift($returns);
        });
        $store = new BatchJobStore($redis);

        $this->assertSame(
            ['done' => false, 'cursor' => '1234', 'cancelled_audits' => 5, 'reaped' => false],
            $store->reapStaleJob('j1', 1, 0, null)
        );
        $this->assertSame(
            ['done' => true, 'cursor' => null, 'cancelled_audits' => 0, 'reaped' => false],
            $store->reapStaleJob('j1', 1, 0, '1234')
        );
    }

    public function testReapStaleJobOfMissingJobIsNoOp(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(null);
        $redis->expects($this->never())->method('eval');

        $this->assertSame(
            ['done' => true, 'cursor' => null, 'cancelled_audits' => 0, 'reaped' => false],
            (new BatchJobStore($redis))->reapStaleJob('missing', 1, 1)
        );
    }

    // ── Reglas transversales de los Lua ──────────────────────────────────

    /**
     * @return array<string,array{0:string}>
     */
    public static function prefixDerivingScripts(): array
    {
        $names = [
            'INIT_JOB_EXCLUSIVE_LUA', 'SEAL_JOB_LUA', 'PUBLISH_EMPTY_BATCH_LUA', 'MARK_JOB_FAILED_LUA',
            'DELETE_JOB_LUA', 'MARK_AUDIT_COMPLETED_IN_JOB_LUA', 'REAP_STALE_JOB_LUA', 'CANCEL_UNDISPATCHED_AUDITS_LUA',
        ];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    /**
     * Regla 0 (`W-F2-B2`): todo script que construye claves dinámicas deriva `prefix` de `KEYS[1]` y no usa claves sin prefijo.
     */
    #[DataProvider('prefixDerivingScripts')]
    public function testDynamicKeyScriptsDerivePrefixFromFirstKey(string $constant): void
    {
        $script = self::lua($constant);

        $this->assertStringContainsString(
            "local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state\$') or ''",
            $script
        );

        // Ninguna clave dinámica se construye sin `prefix ..` (se ignoran los comentarios Lua).
        $code = (string) preg_replace('/^\s*--.*$/m', '', $script);
        foreach (["'jobs:active:nit:'", "'audit:reservation:disdetnro:'", "'audit:'", "'job:'"] as $literal) {
            $this->assertSame(
                substr_count($code, $literal),
                substr_count($code, 'prefix .. ' . $literal),
                "{$constant}: {$literal} debe construirse siempre con prefix"
            );
        }
    }

    public function testListJobsScriptSupportsEmptyPrefix(): void
    {
        $script = self::lua('LIST_JOBS_LUA');

        $this->assertStringContainsString('"^(.*)jobs:index$"', $script);
        $this->assertStringContainsString(' or ""', $script);
        $this->assertStringNotContainsString('audfact:', $script);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function generationGuardedScripts(): array
    {
        $names = [
            'STORE_FROZEN_CANDIDATES_LUA', 'REGISTER_AUDIT_IN_JOB_LUA', 'MARK_AUDITS_DISPATCHED_LUA',
            'COMMIT_CHUNK_CHECKPOINT_LUA', 'SEAL_JOB_LUA', 'PUBLISH_EMPTY_BATCH_LUA',
        ];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    /**
     * Regla 0 (`W-F2-B3`): la guarda de generación precede a la validación del epoch y a cualquier escritura.
     */
    #[DataProvider('generationGuardedScripts')]
    public function testGenerationScriptsRejectClosedJobsBeforeCheckingEpoch(string $constant): void
    {
        $script = self::lua($constant);
        $guard = "if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end";

        $guardPos = strpos($script, $guard);
        $epochPos = strpos($script, 'generation_epoch');
        $hasWrite = preg_match(
            "/redis\\.call\\('(SET|DEL|HSET|HSETNX|HINCRBY|RPUSH|HDEL)'/",
            $script,
            $write,
            PREG_OFFSET_CAPTURE
        );

        $this->assertNotFalse($guardPos, "{$constant}: falta la guarda de generación");
        $this->assertNotFalse($epochPos);
        $this->assertSame(1, $hasWrite);
        $this->assertLessThan($epochPos, $guardPos);
        $this->assertLessThan($write[0][1], $guardPos);
    }

    public function testMarkJobFailedRejectsClosedJobsAndKeepsPrefix(): void
    {
        $script = self::lua('MARK_JOB_FAILED_LUA');

        $this->assertStringContainsString("st == 'failed' or job.sealed == true or job.reaping == true then return 0 end", $script);
        $this->assertStringContainsString('return -1', $script);
        $this->assertStringContainsString('last_continuation_dispatched_chunk', $script);
        $this->assertStringContainsString("prefix .. 'jobs:active:nit:'", $script);
    }

    public function testRegisterScriptCountsInFlightOnlyForNewAuditsAndDerivesTotalFromHash(): void
    {
        $script = self::lua('REGISTER_AUDIT_IN_JOB_LUA');

        $this->assertStringContainsString("local added = redis.call('HSETNX', KEYS[3]", $script);
        $this->assertStringContainsString("if added == 1 then", $script);
        $this->assertStringContainsString("redis.call('HLEN', KEYS[3])", $script);
        $this->assertStringContainsString("redis.call('HSETNX', KEYS[4], disDetNro", $script);
        $this->assertStringContainsString('return -4', $script);
    }

    public function testFencedClaimScriptMatchesSpecContract(): void
    {
        $script = self::lua('CLAIM_JOB_GENERATION_FENCED_LOCK_LUA');

        $this->assertStringContainsString("redis.call('EXISTS', KEYS[1]) == 1", $script);
        $this->assertStringContainsString('local epoch = cur + 1', $script);
        $this->assertStringContainsString("job.last_continuation_dispatched_chunk = c - 1", $script);
        $this->assertStringContainsString("'EX', math.max(60, tonumber(ARGV[2]) or 1800)", $script);
        $this->assertStringContainsString("redis.error_reply('invalid_job_state')", $script);
    }

    public function testFreezeScriptBatchesRpushInTailPositionAndStaysIdempotent(): void
    {
        $script = self::lua('STORE_FROZEN_CANDIDATES_LUA');

        // unpack() solo al final de los argumentos (en medio se trunca a un valor).
        $this->assertStringContainsString("redis.call('RPUSH', KEYS[2], unpack(batch))", $script);
        $this->assertStringContainsString('i + 499', $script);
        $this->assertStringContainsString('if job.candidates_frozen == true then return 1 end', $script);
    }

    public function testReaperScriptIncrementsEpochOnFirstSliceAndHonoursClaimAndThresholdSemantics(): void
    {
        $script = self::lua('REAP_STALE_JOB_LUA');

        $this->assertStringContainsString('job.generation_epoch = (tonumber(job.generation_epoch) or 0) + 1', $script);
        $this->assertStringContainsString("redis.call('EXISTS', KEYS[6]) == 1", $script);
        $this->assertStringContainsString('if threshold > 0 then', $script);
        $this->assertStringContainsString('local lp = tonumber(job.last_progress_at) or 0', $script);
        $this->assertStringContainsString("redis.call('HSCAN', KEYS[2], cursor, 'COUNT', 500)", $script);
        $this->assertStringContainsString('job.reaping = nil', $script);
        $this->assertStringContainsString('job.completed_at = ARGV[5]', $script);
        $this->assertStringNotContainsString("'EX'", $script, 'El estado del job se escribe sin EX (INV-07).');
    }

    public function testEveryWriteScriptStoresJobStateWithoutExpiry(): void
    {
        foreach ([
            'SEAL_JOB_LUA', 'PUBLISH_EMPTY_BATCH_LUA', 'MARK_JOB_FAILED_LUA', 'COMMIT_CHUNK_CHECKPOINT_LUA',
            'MARK_AUDITS_DISPATCHED_LUA', 'REGISTER_AUDIT_IN_JOB_LUA', 'MARK_AUDIT_COMPLETED_IN_JOB_LUA',
            'STORE_FROZEN_CANDIDATES_LUA', 'MARK_CONTINUATION_DISPATCHED_LUA', 'CANCEL_UNDISPATCHED_AUDITS_LUA',
        ] as $constant) {
            $script = self::lua($constant);
            $this->assertStringNotContainsString("'EX'", $script, $constant);
            $this->assertStringNotContainsString('storeState', $script, $constant);
        }
    }
}
