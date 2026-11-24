<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\Pipeline\AuditBatchMaintenanceCommands;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionInspector;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\BatchRequestedWorker;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBatchInvoicesModel;
use Tests\Support\IsolatedAuditRedis;
use Tests\Support\RecordingAuditEventPublisher;

final class AuditExecutionMigrationRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real aislado.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->redis->eval('redis.call("SET",KEYS[1],"1"); redis.call("SET",KEYS[2],"1"); return redis.call("SET",KEYS[3],"2")',
            ['audit:execution:paused', 'audit:admission:paused', 'audit:execution:version']);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    /** `V-F2-I7`: la recuperación `R-PREPUB` re-invoca `registerAuditInJob()` y no debe contar dos veces en vuelo. */
    public function testRegisterAuditInJobIncrementsInFlightOnlyOnce(): void
    {
        // Arrange:
        $store = new BatchJobStore($this->redis);
        $jobId = AuditEvent::uuidV4();
        self::assertTrue($store->initJobExclusive($jobId, 987654100, '2026-02-01', '2026-02-28', 10)['created']);
        $claim = $store->claimJobGenerationFencedLock($jobId, 'worker-inflight', 1, 60);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
        $auditId = AuditEvent::deterministicUuidV4($jobId . ':D-INFLIGHT-1');
        $inFlight = fn (): int => (int) $this->redis->eval(
            'return redis.call("HGET", KEYS[1], ARGV[1])',
            [BatchJobStore::TELEMETRY_KEY],
            ['audits_in_flight']
        );
        $baseline = $inFlight();

        // Act: el mismo (jobId, disDetNro, auditId) se registra dos veces (recuperación tras caída pre-publicación).
        $first = $store->registerAuditInJob($jobId, $auditId, 'D-INFLIGHT-1', 'DIS-1', 'tok', $claim->epoch);
        $second = $store->registerAuditInJob($jobId, $auditId, 'D-INFLIGHT-1', 'DIS-1', 'tok', $claim->epoch);

        // Assert:
        self::assertTrue($first);
        self::assertTrue($second);
        self::assertSame($baseline + 1, $inFlight());
        self::assertSame(1, (int) $this->redis->eval('return redis.call("HLEN", KEYS[1])', [BatchJobStore::jobAuditsKey($jobId)]));
        self::assertSame(1, (int) $this->redis->eval('return redis.call("HLEN", KEYS[1])', [BatchJobStore::jobByDispensaKey($jobId)]));
        $state = $store->getJobSummary($jobId);
        self::assertSame(1, $state['total']);
        self::assertSame(1, $state['accepted']);
        self::assertSame('queued', $state['status']);

        // Otra dispensa sí cuenta como una auditoría adicional en vuelo.
        $other = AuditEvent::deterministicUuidV4($jobId . ':D-INFLIGHT-2');
        self::assertTrue($store->registerAuditInJob($jobId, $other, 'D-INFLIGHT-2', 'DIS-1', 'tok2', $claim->epoch));
        self::assertSame($baseline + 2, $inFlight());
    }

    public function testMaintenanceDrainsMoreThanOnePageBeforeReportingFlushComplete(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $task = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event);
        for ($i = 0; $i < 101; $i++) {
            $next = AuditTaskEffects::followUp($task, AuditEvent::TYPE_DOCUMENT_REGISTERED,
                ['synthetic' => $i], 'document-' . $i, 'document-' . $i);
            $effect = AuditTaskEffects::event($task, $next, 'document-' . $i);
            $this->redis->eval('redis.call("HSET",KEYS[1],"record",ARGV[1]); return redis.call("ZADD",KEYS[2],0,ARGV[2])',
                ['audit:outbox:v2:' . $effect['effect_id'], AuditTaskCommitter::OUTBOX], [json_encode($effect), $effect['effect_id']]);
        }
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));

        // Act:
        $published = $maintenance->flush();
        $repeated = $maintenance->flush();

        // Assert:
        self::assertSame(101, $published);
        self::assertSame(0, $repeated);
        self::assertSame(0, $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]));
        self::assertSame(101, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::STREAM_DOCUMENTS_BATCH]));
    }

    public function testReconciliationRecoversDeletedSchedulerIndexesFromDurableRecords(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $queue = $this->admitOrchestrationTask($event);
        $this->redis->eval('return redis.call("DEL",KEYS[1],KEYS[2])', [FairAuditTaskQueue::COUNTS,
            FairAuditTaskQueue::base('orchestration', 'batch') . ':waiting']);

        // Act:
        $result = (new AuditExecutionInspector($this->redis))->reconcile();
        $task = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'recovered');

        // Assert:
        self::assertSame(1, $result['tasks']);
        self::assertSame($event->eventId, $task->event()->eventId);
        self::assertSame(1, $queue->metrics()['orchestration']['batch']['active']);
    }

    public function testReconciliationPreservesPersistenceAndLegacyIndexes(): void
    {
        // Arrange:
        $keys = ['audit:persistence:batch:active', 'audit:persistence:batch:pending',
            'audit:legacy:jobs', 'audit:legacy:waiting'];
        foreach ($keys as $key) {
            $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$key], ['must-survive']);
        }

        // Act:
        (new AuditExecutionInspector($this->redis))->reconcile();

        // Assert:
        foreach ($keys as $key) {
            self::assertSame('must-survive', $this->redis->get($key));
        }
    }

    public function testGcCollectsPriorTerminalRecordsAndOrphanedReservationsWhilePreservingActiveOnes(): void
    {
        $oldAuditId = AuditEvent::uuidV4();
        $activeAuditId = AuditEvent::uuidV4();
        $oldJobId = AuditEvent::uuidV4();

        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey($oldAuditId)], [
            json_encode([
                'audit_id' => $oldAuditId,
                'status' => 'completed',
                'completed_at' => '2020-01-01T00:00:00Z',
            ]),
        ]);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey($activeAuditId)], [
            json_encode([
                'audit_id' => $activeAuditId,
                'execution_version' => 2,
                'status' => 'processing',
                'created_at' => '2026-10-08T00:00:00Z',
            ]),
        ]);
        // SDD-BATCH-001: job v2 (sin `audits` inline); sus auditorías viven en job:{id}:audits.
        $this->redis->eval('redis.call("ZADD",KEYS[2],1000,ARGV[2]); redis.call("HSET",KEYS[3],ARGV[3],ARGV[4]); return redis.call("SET",KEYS[1],ARGV[1])', [
            'job:' . $oldJobId . ':state',
            'jobs:index',
            BatchJobStore::jobAuditsKey($oldJobId),
        ], [
            json_encode([
                'job_id' => $oldJobId,
                'execution_version' => 2,
                'status' => 'completed',
                'completed_at' => '2020-01-01T00:00:00Z',
                'total' => 1,
                'done' => 1,
            ]),
            $oldJobId,
            $oldAuditId,
            json_encode(['dis_det_nro' => 'OLD-1', 'status' => 'completed']),
        ]);
        foreach (['ORPHAN-2' => $oldAuditId, 'ACTIVE-2' => $activeAuditId] as $ddn => $auditId) {
            $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [BatchJobStore::auditReservationKey($ddn)], [
                json_encode([
                    'dis_det_nro' => $ddn,
                    'audit_id' => $auditId,
                    'token' => 'tok-' . $ddn,
                    'claimed_at' => $ddn === 'ORPHAN-2' ? '2020-01-01T00:00:00Z' : gmdate('Y-m-d\TH:i:s\Z'),
                ]),
            ]);
        }
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', ['audit:reservation:disid:ORPHAN-1'], [
            json_encode([
                'dis_id' => 'ORPHAN-1',
                'audit_id' => $oldAuditId,
                'token' => 'tok-orphan',
                'claimed_at' => '2020-01-01T00:00:00Z',
            ]),
        ]);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', ['audit:reservation:disid:ACTIVE-1'], [
            json_encode([
                'dis_id' => 'ACTIVE-1',
                'audit_id' => $activeAuditId,
                'token' => 'tok-active',
                'claimed_at' => '2026-10-08T00:00:00Z',
            ]),
        ]);

        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));
        $collected = $maintenance->gc();

        self::assertGreaterThanOrEqual(3, $collected);
        self::assertNull($this->redis->get(AuditStateStore::auditKey($oldAuditId)));
        self::assertNull($this->redis->get('job:' . $oldJobId . ':state'));
        self::assertNull($this->redis->eval('return redis.call("ZSCORE",KEYS[1],ARGV[1])', ['jobs:index'], [$oldJobId]));
        self::assertNull($this->redis->get('audit:reservation:disid:ORPHAN-1'));
        self::assertNotNull($this->redis->get(AuditStateStore::auditKey($activeAuditId)));
        self::assertNotNull($this->redis->get('audit:reservation:disid:ACTIVE-1'));
        self::assertSame(0, (int) $this->redis->eval('return redis.call("EXISTS",KEYS[1])', [BatchJobStore::jobAuditsKey($oldJobId)]));
        self::assertNull($this->redis->get(BatchJobStore::auditReservationKey('ORPHAN-2')));
        self::assertNotNull($this->redis->get(BatchJobStore::auditReservationKey('ACTIVE-2')));
    }

    /** `H3`, `R-SEAL`, `R-ROLL`: 0 auditorías, `failed` sellado preservado, locks y reservas legadas, reconcile y ADMIT. */
    public function testMigrateBatchV2RepairsZeroAuditAndPreservesFailedSealedJob(): void
    {
        $store = new BatchJobStore($this->redis);
        $zero = AuditEvent::uuidV4();
        $failed = AuditEvent::uuidV4();
        $a1 = AuditEvent::uuidV4();
        $this->seedV1Job($zero, ['status' => 'queued', 'sealed' => true, 'total' => 5, 'accepted' => 5, 'fac_nit_sec' => 987654200], []);
        $this->seedV1Job($failed, ['status' => 'failed', 'sealed' => true, 'total' => 1, 'fac_nit_sec' => 987654201,
            'updated_at' => '2026-11-01T00:00:00Z'], [$a1 => ['dis_det_nro' => 'FD1', 'status' => 'completed']]);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1],"PX",50000)', [BatchJobStore::jobGenerationLockKey($failed)], ['worker-legacy']);
        $a2 = AuditEvent::uuidV4();
        $this->setJson(AuditStateStore::auditKey($a2), ['audit_id' => $a2, 'job_id' => $zero, 'dis_det_nro' => 'RD2', 'status' => 'processing', 'execution_version' => 2]);
        $this->setJson('audit:reservation:disid:777', ['dis_id' => '777', 'audit_id' => $a2, 'job_id' => $zero, 'token' => 'tok-2', 'claimed_at' => '2026-11-01T00:00:00Z']);

        $result = $store->migrateBatchV2(false);

        self::assertSame(2, $result['migrated_jobs']);
        self::assertSame(1, $result['migrated_reservations']);
        self::assertSame(1, $result['claims_converted']);
        self::assertSame(0, $result['claims_cleared']);
        $z = $store->getJobSummary($zero);
        self::assertSame([0, 0, 0, 0], [$z['total'], $z['accepted'], $z['done'], $z['failed']]);
        self::assertSame('completed', $z['status']);
        self::assertTrue($z['sealed']);
        self::assertArrayNotHasKey('audits', $z);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey(987654200), $zero));
        $f = $store->getJobSummary($failed);
        self::assertSame('failed', $f['status'], 'El estado failed se preserva.');
        self::assertTrue($f['sealed']);
        self::assertSame([1, 1, 0], [$f['total'], $f['done'], $f['failed']]);
        self::assertSame('2026-11-01T00:00:00Z', $f['completed_at']);
        self::assertSame(['audit_id' => $a1, 'dispatched' => true], $store->getBatchDispensaEntry($failed, 'FD1'));
        // cjson no garantiza el orden de claves: se compara por contenido.
        self::assertEquals(['dis_det_nro' => 'FD1', 'status' => 'completed'],
            json_decode((string) $this->redis->eval('return redis.call("HGET",KEYS[1],ARGV[1])', [BatchJobStore::jobAuditsKey($failed)], [$a1]), true));
        foreach ([$zero, $failed] as $jobId) {
            self::assertLessThan(2048, (int) $this->redis->eval('return redis.call("STRLEN",KEYS[1])', [BatchJobStore::jobKey($jobId)]));
        }
        self::assertEquals(['worker_id' => 'worker-legacy', 'epoch' => 0], json_decode((string) $this->redis->get(BatchJobStore::jobGenerationLockKey($failed)), true));
        self::assertGreaterThan(0, (int) $this->redis->eval('return redis.call("PTTL",KEYS[1])', [BatchJobStore::jobGenerationLockKey($failed)]));
        self::assertNull($this->redis->get('audit:reservation:disid:777'));
        $migrated = json_decode((string) $this->redis->get(BatchJobStore::auditReservationKey('RD2')), true);
        self::assertSame(['RD2', 'tok-2', $a2], [$migrated['dis_det_nro'], $migrated['token'], $migrated['audit_id']]);
        self::assertSame(['jobs_queued' => 0, 'jobs_active' => 0, 'jobs_running' => 0, 'jobs_completed' => 1, 'jobs_failed' => 1], $result['telemetry']);

        // Aborto -1: reserva legada sin identidad resoluble; la clave origen queda intacta.
        $this->setJson('audit:reservation:disid:888', ['dis_id' => '888', 'audit_id' => AuditEvent::uuidV4(), 'token' => 't']);
        $orphanRaw = $this->redis->get('audit:reservation:disid:888');
        try {
            $store->migrateBatchV2(false);
            self::fail('Una identidad irresuelta debe abortar la migración.');
        } catch (\RuntimeException $error) {
            self::assertSame('migrate_reservation_failed', $error->getMessage());
        }
        self::assertSame($orphanRaw, $this->redis->get('audit:reservation:disid:888'));
        $this->redis->eval('return redis.call("DEL",KEYS[1])', ['audit:reservation:disid:888']);

        // reconcile() sin job_totals_inconsistent y ADMIT compatible con el job migrado (job:{id}:state sigue siendo string).
        self::assertIsArray((new AuditExecutionInspector($this->redis))->reconcile());
        $this->admitOrchestrationTask(AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, $a2, $zero));
    }

    /** `R-SEAL` (`CA-F2-10`): un job v1 `completed` con auditorías pendientes queda `processing` + sellado y cierra al completar. */
    public function testMigrateKeepsSealedCompletedJobWithPendingAuditsAndClosesOnCompletion(): void
    {
        $store = new BatchJobStore($this->redis);
        $nit = 987654210;
        $jobId = AuditEvent::uuidV4();
        [$a, $b, $c] = [AuditEvent::uuidV4(), AuditEvent::uuidV4(), AuditEvent::uuidV4()];
        $this->seedV1Job($jobId, ['status' => 'completed', 'sealed' => true, 'total' => 3, 'done' => 3, 'failed' => 0,
            'completed_at' => '2026-11-01T00:00:00Z', 'fac_nit_sec' => $nit], [
            $a => ['dis_det_nro' => 'S1', 'status' => 'completed'],
            $b => ['dis_det_nro' => 'S2', 'status' => 'pending'],
            $c => ['dis_det_nro' => 'S3', 'status' => 'failed', 'failed_stage' => 'extraction'],
        ]);
        $closed = AuditEvent::uuidV4();
        $this->seedV1Job($closed, ['status' => 'completed', 'sealed' => false, 'total' => 1, 'fac_nit_sec' => 987654211],
            [AuditEvent::uuidV4() => ['dis_det_nro' => 'S9', 'status' => 'completed']]);

        $store->migrateBatchV2(true);

        $state = $store->getJobSummary($jobId);
        self::assertSame('processing', $state['status']);
        self::assertTrue($state['sealed']);
        self::assertSame([3, 1, 1], [$state['total'], $state['done'], $state['failed']]);
        self::assertNull($state['completed_at']);
        self::assertSame(1, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
        self::assertSame('extraction', json_decode((string) $this->redis->eval('return redis.call("HGET",KEYS[1],ARGV[1])',
            [BatchJobStore::jobAuditsKey($jobId)], [$c]), true)['failed_stage']);
        $other = $store->getJobSummary($closed);
        self::assertTrue($other['sealed'], 'Ningún job completed* queda con sealed = false.');
        self::assertSame('completed', $other['status']);

        self::assertTrue($store->markAuditCompletedInJob($jobId, $b, 'completed', 10));
        $state = $store->getJobSummary($jobId);
        self::assertSame('completed_with_errors', $state['status']);
        self::assertSame([2, 1], [$state['done'], $state['failed']]);
        self::assertSame(0, $this->hexists(BatchJobStore::activeNitJobsKey($nit), $jobId));
    }

    /** Generación v1 abandonada: sin `batch_requested` en PEL ni pendiente de entrega en `audit.batch.inbox`. */
    public function testMigrateFlagsAbandonedGenerationWithoutPendingBatchRequested(): void
    {
        $store = new BatchJobStore($this->redis);
        $abandoned = AuditEvent::uuidV4();
        $undelivered = AuditEvent::uuidV4();
        $inPel = AuditEvent::uuidV4();
        foreach ([$abandoned => 987654220, $undelivered => 987654221, $inPel => 987654222] as $jobId => $nit) {
            $this->seedV1Job($jobId, ['status' => 'processing', 'sealed' => false, 'total' => 1, 'fac_nit_sec' => $nit],
                [AuditEvent::uuidV4() => ['dis_det_nro' => 'P-' . $nit, 'status' => 'pending']]);
        }
        $stream = AuditEventPublisher::STREAM_BATCH_INBOX;
        $group = AuditEventPublisher::GROUP_BATCH;
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); return redis.call("XADD",KEYS[1],"*","event",ARGV[2])',
            [$stream], [$group, $this->batchRequested($inPel, 987654222)->toJson()]);
        $this->redis->eval('return redis.call("XREADGROUP","GROUP",ARGV[1],"c1","COUNT",1,"STREAMS",KEYS[1],">")', [$stream], [$group]);
        $this->xadd($stream, $this->batchRequested($undelivered, 987654221));
        // Un evento de otro tipo con el job abandonado no cuenta como generación pendiente.
        $this->xadd($stream, AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4(), $abandoned));

        $before = $this->nowMs();
        $result = $store->migrateBatchV2(true);

        self::assertSame([$abandoned], $result['generation_abandoned']);
        $state = $store->getJobSummary($abandoned);
        self::assertTrue($state['generation_abandoned']);
        self::assertGreaterThanOrEqual($before, $state['last_progress_at']);
        self::assertArrayNotHasKey('generation_abandoned', $store->getJobSummary($undelivered));
        self::assertArrayNotHasKey('generation_abandoned', $store->getJobSummary($inPel));
    }

    /** `V-F2-M3`, `W-F2-M5`, `R4-F2-I2`: el rollback no toca jobs v1 (aunque digan execution_version 2) y limpia Fase 2. */
    public function testRollbackIgnoresV1JobsWithExecutionVersion2AndCleansPhase2CursorFields(): void
    {
        $store = new BatchJobStore($this->redis);
        $v1 = AuditEvent::uuidV4();
        $this->seedV1Job($v1, ['status' => 'completed', 'sealed' => true, 'total' => 1, 'done' => 1, 'execution_version' => 2,
            'fac_nit_sec' => 987654240], [AuditEvent::uuidV4() => ['dis_det_nro' => 'V1', 'status' => 'completed']]);
        $v1Raw = $this->redis->get(BatchJobStore::jobKey($v1));
        $v2 = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $this->seedV2TerminalJob($v2, [$auditId => ['dis_det_nro' => 'V2', 'status' => 'completed']]);
        $staleOnly = AuditEvent::uuidV4();
        $this->setJson(BatchJobStore::jobKey($staleOnly), ['job_id' => $staleOnly, 'status' => 'failed', 'sealed' => true, 'total' => 0,
            'stale_reason' => 'stale_no_progress', 'error_message' => 'stale_no_progress']);
        $phase2 = [];
        foreach (BatchJobStore::PHASE2_GC_CURSOR_FIELDS as $field) {
            $phase2[] = $field;
            $phase2[] = '1';
        }
        $this->redis->eval('return redis.call("HSET",KEYS[1],"pattern_index","3","cursor:tasks","0",unpack(ARGV))',
            [AuditExecutionMaintenance::GC_CURSORS_KEY], $phase2);
        $this->redis->eval('redis.call("SET",KEYS[1],"123"); redis.call("HSET",KEYS[2],"at_ms","1"); return redis.call("HSET",KEYS[3],ARGV[1],"{}")',
            ['audit:execution:resumed_at_ms', AuditExecutionMaintenance::HEARTBEAT_META_KEY, BatchJobStore::activeNitJobsKey(5)], [$v2]);

        $result = $store->rollbackBatchV2();

        self::assertSame(2, $result['reverted_jobs']);
        self::assertSame($v1Raw, $this->redis->get(BatchJobStore::jobKey($v1)), 'Un job v1 no se reescribe.');
        $reverted = json_decode((string) $this->redis->get(BatchJobStore::jobKey($v2)), true);
        self::assertEquals([$auditId => ['dis_det_nro' => 'V2', 'status' => 'completed']], $reverted['audits']);
        foreach (['execution_version', 'generation_epoch', 'last_progress_at', 'candidates_frozen', 'last_committed_chunk'] as $field) {
            self::assertArrayNotHasKey($field, $reverted);
        }
        self::assertSame(0, (int) $this->redis->eval('return redis.call("EXISTS",KEYS[1],KEYS[2])',
            [BatchJobStore::jobAuditsKey($v2), BatchJobStore::jobByDispensaKey($v2)]));
        $staleState = json_decode((string) $this->redis->get(BatchJobStore::jobKey($staleOnly)), true);
        self::assertSame([], $staleState['audits']);
        self::assertArrayNotHasKey('stale_reason', $staleState);
        self::assertArrayNotHasKey('error_message', $staleState);
        $cursors = $this->hgetall(AuditExecutionMaintenance::GC_CURSORS_KEY);
        self::assertSame(['cursor:tasks' => '0', 'pattern_index' => '3'], $cursors, 'Solo se eliminan los 9 campos de Fase 2 (hgetall ordena por clave).');
        self::assertNull($this->redis->get('audit:execution:resumed_at_ms'));
        self::assertSame(['at_ms' => '1'], $this->hgetall(AuditExecutionMaintenance::HEARTBEAT_META_KEY));
        self::assertSame(0, (int) $this->redis->eval('return redis.call("EXISTS",KEYS[1])', [BatchJobStore::activeNitJobsKey(5)]));
    }

    /** `W-F2-M3`: `repair-jobs` recuenta contadores sin reiniciar `last_progress_at` ni quitar `sealed`. */
    public function testRepairJobsDoesNotResetLastProgressAt(): void
    {
        $store = new BatchJobStore($this->redis);
        $old = $this->v2JobWithAudits($store, 987654230, 1);
        $this->patchJson(BatchJobStore::jobKey($old), ['last_progress_at' => 12345, 'done' => 5]);
        $zero = $this->v2JobWithAudits($store, 987654231, 0);
        $this->patchJson(BatchJobStore::jobKey($zero), ['last_progress_at' => 0]);
        $sealed = $this->v2JobWithAudits($store, 987654232, 2);
        $sealedAudit = AuditEvent::deterministicUuidV4($sealed . ':R-0');
        self::assertTrue($store->markAuditCompletedInJob($sealed, $sealedAudit, 'completed', 1));
        $this->patchJson(BatchJobStore::jobKey($sealed), ['sealed' => true]);
        $before = $this->nowMs();

        $out = (new AuditExecutionInspector($this->redis))->repairCorruptedJobs();

        self::assertSame(3, $out['repaired_jobs']);
        self::assertSame(3, $out['audits_recounted']);
        $oldState = $store->getJobSummary($old);
        self::assertSame(12345, $oldState['last_progress_at'], 'repair-jobs no reinicia el reloj del reaper.');
        self::assertSame([1, 0], [$oldState['total'], $oldState['done']], 'done + failed > total se recuenta.');
        self::assertGreaterThanOrEqual($before, $store->getJobSummary($zero)['last_progress_at'], 'Solo se inicializa si falta.');
        $sealedState = $store->getJobSummary($sealed);
        self::assertTrue($sealedState['sealed']);
        self::assertSame('processing', $sealedState['status']);
    }

    /** `W-F1-I4(a)`: la CLI `migrate-batch-v2` añade `legacy_idempotency`; `BatchJobStore` no depende del mantenimiento. */
    public function testMigrateCliExpiresLegacyIdempotencyAfterMigration(): void
    {
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [BatchJobStore::idempotencyKey('legacy-1')], ['job-x']);
        $claimJob = AuditEvent::uuidV4();
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1],"EX",600)', [BatchJobStore::jobGenerationLockKey($claimJob)], ['{"worker_id":"w","epoch":1}']);
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));
        $arguments = AuditBatchMaintenanceCommands::parseArguments(['bin', 'migrate-batch-v2', '--clear-claims']);

        $first = AuditBatchMaintenanceCommands::run($arguments, $this->redis, $maintenance);

        self::assertSame(['scanned' => 1, 'expired' => 1], $first['legacy_idempotency']);
        self::assertGreaterThan(0, (int) $this->redis->eval('return redis.call("TTL",KEYS[1])', [BatchJobStore::idempotencyKey('legacy-1')]));
        self::assertSame(1, $first['claims_cleared']);
        self::assertNull($this->redis->get(BatchJobStore::jobGenerationLockKey($claimJob)));
        $second = AuditBatchMaintenanceCommands::run($arguments, $this->redis, $maintenance);
        self::assertSame(0, $second['legacy_idempotency']['expired'], 'Tras la migración: expired = 0.');
        self::assertStringNotContainsString('AuditExecutionMaintenance',
            (string) file_get_contents((new \ReflectionClass(BatchJobStore::class))->getFileName()));
    }

    /** `R-ROLL` (`CA-F2-13`): reentrante en reposo; con un job activo o una reserva disdetnro:* aborta sin mutar nada. */
    public function testRollbackBatchV2IsReentrantAtRestAndAbortsWithActiveJobs(): void
    {
        $store = new BatchJobStore($this->redis);
        $jobId = AuditEvent::uuidV4();
        $this->seedV2TerminalJob($jobId, [
            AuditEvent::uuidV4() => ['dis_det_nro' => 'X1', 'status' => 'completed'],
            AuditEvent::uuidV4() => ['dis_det_nro' => 'X2', 'status' => 'failed'],
        ]);

        self::assertSame(1, $store->rollbackBatchV2()['reverted_jobs']);
        $afterFirst = $this->redis->get(BatchJobStore::jobKey($jobId));
        $second = $store->rollbackBatchV2();
        self::assertSame(0, $second['reverted_jobs']);
        self::assertSame(0, $second['reverted_reservations']);
        self::assertSame($afterFirst, $this->redis->get(BatchJobStore::jobKey($jobId)));
        self::assertCount(2, json_decode((string) $afterFirst, true)['audits']);

        $active = AuditEvent::uuidV4();
        self::assertTrue($store->initJobExclusive($active, 987654250, '2026-03-01', '2026-03-31', 10)['created']);
        $before = $this->fingerprint();
        try {
            $store->rollbackBatchV2();
            self::fail('Con un job no terminal el rollback debe abortar.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback_requires_no_active_jobs', $error->getMessage());
        }
        self::assertSame($before, $this->fingerprint(), '0 claves modificadas.');

        self::assertTrue($store->markJobFailed($active, 'cierre'));
        self::assertTrue($store->claimAuditReservation('ACTIVE-DDN', 'tok', ['job_id' => $active, 'audit_id' => AuditEvent::uuidV4()]));
        $before = $this->fingerprint();
        try {
            $store->rollbackBatchV2();
            self::fail('Con una reserva disdetnro:* el rollback debe abortar.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback_requires_no_active_reservations', $error->getMessage());
        }
        self::assertSame($before, $this->fingerprint(), '0 claves modificadas.');
    }

    /**
     * (a) `XAUTOCLAIM` real tras la caída de A y rechazo `generation_fence_stale` del worker obsoleto; (d) mensaje v1
     * pendiente con `cursor` continuado sin duplicados tras `migrateBatchV2()`. Los escenarios (b), (c) y (e) los cubre
     * `BatchGenerationRecoveryRedisTest` (B2) con los mismos Lua reales.
     */
    public function testConcurrentXAutoClaimCrashRecoveryContinuationAndDispensaDeduplication(): void
    {
        $this->redis->eval('return redis.call("DEL",KEYS[1],KEYS[2])', ['audit:execution:paused', 'audit:admission:paused']);
        $store = new BatchJobStore($this->redis);
        $states = new AuditStateStore($this->redis);
        $stream = AuditEventPublisher::STREAM_BATCH_INBOX;
        $group = AuditEventPublisher::GROUP_BATCH;

        // (a)
        $nit = 987655300;
        $publisher = new RecordingAuditEventPublisher();
        $invoices = new FakeBatchInvoicesModel(FakeBatchInvoicesModel::rows(5, $nit));
        $orchestrator = new AuditBatchOrchestrator($states, $store, $publisher, $invoices);
        $jobA = AuditEvent::uuidV4();
        self::assertTrue($store->initJobExclusive($jobA, $nit, '2026-06-01', '2026-06-30', 100)['created']);
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); return redis.call("XADD",KEYS[1],"*","event",ARGV[2])',
            [$stream], [$group, $this->batchRequested($jobA, $nit, ['chunk_size' => 10])->toJson()]);
        $read = $this->redis->eval('return redis.call("XREADGROUP","GROUP",ARGV[1],"worker-a","COUNT",1,"STREAMS",KEYS[1],">")', [$stream], [$group]);
        $messageId = (string) $read[0][1][0][0];
        $claimA = $store->claimJobGenerationFencedLock($jobA, 'worker-a', 1, 60);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claimA->status);
        self::assertSame(1, $claimA->epoch);
        // A cae: su lock expira y B reclama el mensaje de la PEL con XAUTOCLAIM.
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [BatchJobStore::jobGenerationLockKey($jobA)]);
        $auto = $this->redis->eval('return redis.call("XAUTOCLAIM",KEYS[1],ARGV[1],"worker-b",0,"0-0","COUNT",10)', [$stream], [$group]);
        self::assertSame($messageId, (string) $auto[1][0][0]);
        $fields = $auto[1][0][1];
        $reclaimed = AuditEvent::fromArray(json_decode((string) $fields[array_search('event', $fields, true) + 1], true, 512, JSON_THROW_ON_ERROR));
        $staleError = null;
        $invoices->onQuery = static function () use ($store, $jobA, &$staleError): void {
            try {
                $store->registerAuditInJob($jobA, AuditEvent::uuidV4(), 'DX-STALE', null, null, 1);
            } catch (\RuntimeException $error) {
                $staleError = $error;
            }
        };
        (new BatchRequestedWorker($states, $store, $this->redis, $publisher, 'worker-b', $orchestrator))->processEvent($reclaimed);

        self::assertInstanceOf(\RuntimeException::class, $staleError, 'A (epoch 1) intenta registrar mientras B tiene el epoch 2.');
        self::assertSame('generation_fence_stale', $staleError->getMessage());
        self::assertSame(409, $staleError->getCode());
        $summaryA = $store->getJobSummary($jobA);
        self::assertSame(5, $summaryA['total']);
        self::assertSame(2, $summaryA['generation_epoch']);
        self::assertTrue($summaryA['sealed']);
        self::assertNull($store->getBatchDispensaEntry($jobA, 'DX-STALE'));
        self::assertFalse($store->markJobFailed($jobA, 'dlq obsoleto', 1, 1), 'La DLQ de A no cierra el job.');
        self::assertSame(1, (int) $this->redis->eval('return redis.call("XACK",KEYS[1],ARGV[1],ARGV[2])', [$stream], [$group, $messageId]));
        self::assertSame(0, (int) $this->redis->eval('return redis.call("XPENDING",KEYS[1],ARGV[1])[1]', [$stream], [$group]));
        self::assertCount(5, $publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));

        // Los subcasos (a)-(c) dejan reservas vivas de D3..D5 (clave global por DisDetNro): se liberan para que (d)
        // mida solo la continuación migrada y no el bloqueo legítimo entre jobs.
        foreach (['D3', 'D4', 'D5'] as $leftover) {
            $this->redis->eval('return redis.call("DEL", KEYS[1])', [BatchJobStore::auditReservationKey($leftover)], []);
        }
        // (d)
        $nitD = 987655301;
        $jobD = AuditEvent::uuidV4();
        [$d1, $d2] = [AuditEvent::uuidV4(), AuditEvent::uuidV4()];
        $this->seedV1Job($jobD, ['status' => 'processing', 'sealed' => false, 'total' => 2, 'accepted' => 2, 'done' => 1,
            'fac_nit_sec' => $nitD, 'limit' => 100], [
            $d1 => ['dis_det_nro' => 'D1', 'status' => 'completed'],
            $d2 => ['dis_det_nro' => 'D2', 'status' => 'pending'],
        ]);
        $continuation = $this->batchRequested($jobD, $nitD, [
            'chunk_index' => 2,
            'chunk_size' => 10,
            'cursor' => ['date' => '2026-06-12T00:02:00', 'disId' => '1002', 'dispensa' => 'D2'],
        ]);
        $this->xadd($stream, $continuation);

        $migration = $store->migrateBatchV2(true);

        self::assertNotContains($jobD, $migration['generation_abandoned'], 'Hay un batch_requested pendiente para el job.');
        self::assertSame(['audit_id' => $d1, 'dispatched' => true], $store->getBatchDispensaEntry($jobD, 'D1'));
        self::assertSame(['audit_id' => $d2, 'dispatched' => true], $store->getBatchDispensaEntry($jobD, 'D2'));
        $publisherD = new RecordingAuditEventPublisher();
        $invoicesD = new FakeBatchInvoicesModel(FakeBatchInvoicesModel::rows(5, $nitD));
        (new BatchRequestedWorker($states, $store, $this->redis, $publisherD, 'worker-d',
            new AuditBatchOrchestrator($states, $store, $publisherD, $invoicesD)))->processEvent($continuation);

        self::assertSame(1, $invoicesD->getCalls());
        self::assertSame('D2', $invoicesD->calls[0]['cursor']['dispensa'], 'La congelación parte del cursor v1.');
        self::assertSame(
            array_map(static fn (string $ddn): string => AuditEvent::deterministicUuidV4("batch-audit:{$jobD}:{$ddn}"), ['D3', 'D4', 'D5']),
            array_map(static fn (AuditEvent $event): string => (string) $event->auditId, $publisherD->ofType(AuditEvent::TYPE_AUDIT_CREATED))
        );
        $summaryD = $store->getJobSummary($jobD);
        self::assertSame(5, $summaryD['total']);
        self::assertSame(1, $summaryD['done']);
        self::assertSame(5, (int) $this->redis->eval('return redis.call("HLEN",KEYS[1])', [BatchJobStore::jobAuditsKey($jobD)]));
        self::assertSame($d1, $store->getBatchDispensaEntry($jobD, 'D1')['audit_id'], 'Sin auditorías duplicadas para D1/D2.');
    }

    /** @param array<string,mixed> $fields @param array<string,array<string,mixed>> $audits */
    private function seedV1Job(string $jobId, array $fields, array $audits): void
    {
        $state = $fields + [
            'job_id' => $jobId, 'execution_version' => 2, 'date_from' => '2026-06-01', 'date_to' => '2026-06-30',
            'accepted' => $fields['total'] ?? 0, 'done' => 0, 'failed' => 0, 'skipped_locked' => 0, 'skipped_existing' => 0,
            'created_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-01T00:00:00Z',
        ];
        $state['audits'] = $audits === [] ? new \stdClass() : $audits;
        $this->setJson(BatchJobStore::jobKey($jobId), $state);
    }

    /** @param array<string,array<string,mixed>> $audits */
    private function seedV2TerminalJob(string $jobId, array $audits): void
    {
        $failed = count(array_filter($audits, static fn (array $a): bool => $a['status'] === 'failed'));
        $this->setJson(BatchJobStore::jobKey($jobId), [
            'job_id' => $jobId, 'execution_version' => 2, 'status' => 'completed', 'sealed' => true, 'fac_nit_sec' => 987654299,
            'total' => count($audits), 'accepted' => count($audits), 'done' => count($audits) - $failed, 'failed' => $failed,
            'generation_epoch' => 3, 'candidates_frozen' => true, 'last_committed_chunk' => 2, 'last_progress_at' => 123,
            'completed_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z',
        ]);
        foreach ($audits as $auditId => $entry) {
            $this->redis->eval('redis.call("HSET",KEYS[1],ARGV[1],ARGV[2]); return redis.call("HSET",KEYS[2],ARGV[3],ARGV[4])',
                [BatchJobStore::jobAuditsKey($jobId), BatchJobStore::jobByDispensaKey($jobId)],
                [$auditId, json_encode($entry), $entry['dis_det_nro'], json_encode(['audit_id' => $auditId, 'dispatched' => true])]);
        }
    }

    /** Job v2 real (initJobExclusive + claim) con `$count` auditorías registradas `R-{i}`. */
    private function v2JobWithAudits(BatchJobStore $store, int $nit, int $count): string
    {
        $jobId = AuditEvent::uuidV4();
        self::assertTrue($store->initJobExclusive($jobId, $nit, '2026-04-01', '2026-04-30', 10)['created']);
        $claim = $store->claimJobGenerationFencedLock($jobId, 'w', 1, 60);
        self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
        for ($i = 0; $i < $count; $i++) {
            self::assertTrue($store->registerAuditInJob($jobId, AuditEvent::deterministicUuidV4($jobId . ':R-' . $i), 'R-' . $nit . '-' . $i, null, null, $claim->epoch));
        }
        self::assertTrue($store->releaseJobGenerationLock($jobId, 'w', $claim->epoch));

        return $jobId;
    }

    /** @param array<string,mixed> $payload */
    private function batchRequested(string $jobId, int $nit, array $payload = []): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: $jobId,
            payload: array_merge([
                'fac_nit_sec' => (string) $nit,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'limit' => 100,
                'chunk_size' => 2,
            ], $payload)
        );
    }

    private function xadd(string $stream, AuditEvent $event): void
    {
        $this->redis->eval('return redis.call("XADD",KEYS[1],"*","event",ARGV[1])', [$stream], [$event->toJson()]);
    }

    /** @param array<string,mixed> $value */
    private function setJson(string $key, array $value): void
    {
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$key], [json_encode($value)]);
    }

    /** @param array<string,mixed> $patch */
    private function patchJson(string $key, array $patch): void
    {
        $this->setJson($key, array_merge(json_decode((string) $this->redis->get($key), true), $patch));
    }

    private function hexists(string $key, string $field): int
    {
        return (int) $this->redis->eval('return redis.call("HEXISTS",KEYS[1],ARGV[1])', [$key], [$field]);
    }

    /** @return array<string,string> */
    private function hgetall(string $key): array
    {
        $raw = $this->redis->eval('return redis.call("HGETALL",KEYS[1])', [$key]);
        $out = [];
        for ($i = 0; is_array($raw) && $i < count($raw); $i += 2) {
            $out[(string) $raw[$i]] = (string) $raw[$i + 1];
        }
        ksort($out);

        return $out;
    }

    /** Volcado (DUMP) de todas las claves del namespace del test, para verificar «0 claves modificadas». */
    private function fingerprint(): array
    {
        $out = [];
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scanKeys('*', $cursor, 1000);
            foreach ($keys as $key) {
                $out[$key] = $this->redis->eval('return redis.call("DUMP",KEYS[1])', [$key]);
            }
        } while ($cursor !== '0');
        ksort($out);

        return $out;
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function root(AuditEvent $event, array $documents): void
    {
        $state = ['audit_id' => $event->auditId, 'job_id' => $event->jobId, 'status' => $documents === [] ? 'pending' : 'processing',
            'execution_version' => 2, 'docs_total' => count($documents), 'docs_evaluated' => 0, 'docs_done' => count($documents),
            'documents' => $documents, 'created_at' => '2026-10-08T00:00:00Z'];
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey($event->auditId)], [json_encode($state)]);
    }

    private function admitOrchestrationTask(AuditEvent $event): FairAuditTaskQueue
    {
        $stream = AuditEventPublisher::streamForEvent($event);
        $group = AuditEventPublisher::GROUP_ORCHESTRATOR;
        $id = (string) $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); local id=redis.call("XADD",KEYS[1],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP",ARGV[1],"test-intake","STREAMS",KEYS[1],">"); return id',
            [$stream], [$group, $event->toJson()]);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        self::assertTrue($queue->admit(AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event), $stream, $group, $id));
        return $queue;
    }
}
