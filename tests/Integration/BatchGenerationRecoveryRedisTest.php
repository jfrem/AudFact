<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\BatchChunkState;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\BatchRequestedWorker;
use App\Services\Audit\Pipeline\JobGenerationFailedException;
use App\Services\Audit\Pipeline\JobLockClaimStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeBatchInvoicesModel;
use Tests\Support\IsolatedAuditRedis;
use Tests\Support\RecordingAuditEventPublisher;

/**
 * SDD-BATCH-001 (IMPL-BATCH-001-B2): orquestador + worker contra los Lua REALES de `BatchJobStore` v2 en Redis 7 aislado
 * (`RUN_REDIS_INTEGRATION=1`, `REDIS_PREFIX` no vacío). Cubre fencing por epoch tras expirar el lock, recuperación
 * R-PREPUB (caída post-registro), R-CONT (caída post-checkpoint) y claim de `k+1` antes de confirmar `k`.
 *
 * No cubre `XAUTOCLAIM` real sobre streams ni la parte (d) de migración (`migrateBatchV2`): quedan en
 * `AuditExecutionMigrationRedisTest::testConcurrentXAutoClaimCrashRecoveryContinuationAndDispensaDeduplication` (tarea C).
 */
final class BatchGenerationRecoveryRedisTest extends TestCase
{
    private const NIT = 987655100;

    private IsolatedAuditRedis $redis;
    private BatchJobStore $store;
    private AuditStateStore $states;
    private RecordingAuditEventPublisher $publisher;
    private FakeBatchInvoicesModel $invoices;
    private AuditBatchOrchestrator $orchestrator;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real aislado.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->store = new BatchJobStore($this->redis);
        $this->states = new AuditStateStore($this->redis);
        $this->publisher = new RecordingAuditEventPublisher();
        $this->useRows(FakeBatchInvoicesModel::rows(5, self::NIT));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function useRows(array $rows): void
    {
        $this->invoices = new FakeBatchInvoicesModel($rows);
        $this->orchestrator = new AuditBatchOrchestrator($this->states, $this->store, $this->publisher, $this->invoices);
    }

    private function newJob(int $limit = 100): string
    {
        $jobId = AuditEvent::uuidV4();
        $result = $this->store->initJobExclusive($jobId, self::NIT, '2026-06-01', '2026-06-30', $limit);
        self::assertTrue($result['created']);

        return $jobId;
    }

    /** @return array<string,mixed> */
    private function runChunk(string $jobId, ?BatchChunkState $state = null, string $worker = 'worker-a', int $limit = 100): array
    {
        return $this->orchestrator->enqueueBatch(self::NIT, '2026-06-01', '2026-06-30', $limit, $jobId, $worker, $state);
    }

    /** @return array<string,mixed> */
    private function summary(string $jobId): array
    {
        $summary = $this->store->getJobSummary($jobId);
        self::assertIsArray($summary);

        return $summary;
    }

    private function dropGenerationLock(string $jobId): void
    {
        $this->redis->eval('return redis.call("DEL", KEYS[1])', [BatchJobStore::jobGenerationLockKey($jobId)], []);
    }

    private function workerFor(string $name = 'integration-worker'): BatchRequestedWorker
    {
        return new BatchRequestedWorker($this->states, $this->store, $this->redis, $this->publisher, $name, $this->orchestrator);
    }

    /** @param array<string,mixed> $overrides */
    private function batchEvent(string $jobId, array $overrides = []): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: $jobId,
            payload: array_merge([
                'fac_nit_sec' => (string) self::NIT,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'limit' => 100,
                'chunk_size' => 2,
            ], $overrides)
        );
    }

    public function testMultiChunkJobWithRealLuaClosesWithAuthoritativeTotalsAndSingleSql(): void
    {
        $this->useRows([
            ['NitSec' => self::NIT, 'DisId' => '777', 'Dispensa' => 'S1', 'DisFecSol' => '2026-06-12T00:01:00'],
            ['NitSec' => self::NIT, 'DisId' => '777', 'Dispensa' => 'S2', 'DisFecSol' => '2026-06-12T00:02:00'],
            ['NitSec' => self::NIT, 'DisId' => '778', 'Dispensa' => 'S3', 'DisFecSol' => '2026-06-12T00:03:00'],
            ['NitSec' => self::NIT, 'DisId' => '779', 'Dispensa' => 'S4', 'DisFecSol' => '2026-06-12T00:04:00'],
            ['NitSec' => self::NIT, 'DisId' => '780', 'Dispensa' => 'S5', 'DisFecSol' => '2026-06-12T00:05:00'],
        ]);
        $jobId = $this->newJob();
        $state = new BatchChunkState(chunkSizeOverride: 2);
        $results = [];
        for ($guard = 0; $guard < 10; $guard++) {
            $result = $this->runChunk($jobId, $state);
            $results[] = $result;
            if (!$result['has_more']) {
                break;
            }
            $state = new BatchChunkState(
                cursor: $result['next_cursor'],
                chunkIndex: $state->chunkIndex + 1,
                accumulatedTotal: $result['accumulated_total'],
                chunkSizeOverride: 2,
                candidateOffset: (int) $result['next_candidate_offset'],
            );
        }

        self::assertCount(3, $results);
        self::assertSame(1, $this->invoices->getCalls(), 'Una sola consulta SQL para todo el job.');
        $summary = $this->summary($jobId);
        self::assertTrue($summary['sealed']);
        self::assertSame(5, $summary['total']);
        self::assertSame(5, $summary['accepted']);
        self::assertSame(3, $summary['last_committed_chunk']);
        self::assertSame(3, $summary['generation_epoch'], 'Un claim por chunk: epoch 1, 2 y 3.');
        self::assertSame(2, $summary['last_continuation_dispatched_chunk'], 'El claim del chunk k+1 promueve la confirmación de k.');
        self::assertCount(5, $this->store->getJob($jobId)['audits']);
        self::assertSame([], $this->store->readFrozenCandidates($jobId, 0, 10), 'Las candidatas se eliminan al sellar.');
        foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $ddn) {
            self::assertTrue($this->store->getBatchDispensaEntry($jobId, $ddn)['dispatched'], "{$ddn} despachada");
            self::assertSame($jobId, $this->store->getAuditReservation($ddn)['job_id']);
        }
        self::assertSame('777', $this->store->getAuditReservation('S2')['dis_id'], 'Dispensas hermanas con el mismo DisId aceptadas por DisDetNro.');
        self::assertCount(5, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));
        self::assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_BATCH_CREATED));
    }

    /** `CA-F2-07`: un worker con epoch obsoleto (lock expirado + takeover) no altera total, candidatas ni estado. */
    public function testStaleWorkerAfterLockExpiryIsFencedAndCannotCloseTheJobFromDlq(): void
    {
        $jobId = $this->newJob();
        // Mientras A ejecuta la consulta SQL, su lock expira y B adquiere el job (epoch 1 → 2).
        $this->invoices->onQuery = function () use ($jobId): void {
            $this->dropGenerationLock($jobId);
            $claim = $this->store->claimJobGenerationFencedLock($jobId, 'worker-b', 1, 120);
            self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
            self::assertSame(2, $claim->epoch);
        };

        try {
            $this->runChunk($jobId, new BatchChunkState(chunkSizeOverride: 2), 'worker-a');
            self::fail('El worker A obsoleto debía ser rechazado.');
        } catch (JobGenerationFailedException $error) {
            self::assertSame(1, $error->epoch);
            self::assertSame(409, $error->getCode());
            self::assertSame('generation_fence_lost', $error->getMessage());
        }

        $summary = $this->summary($jobId);
        self::assertSame(2, $summary['generation_epoch']);
        self::assertSame(0, $summary['total']);
        self::assertFalse($summary['candidates_frozen']);
        self::assertSame(0, $summary['last_committed_chunk']);
        self::assertSame([], $this->store->readFrozenCandidates($jobId, 0, 10));
        self::assertCount(0, $this->publisher->published);
        self::assertSame(JobLockClaimStatus::BUSY, $this->store->claimJobGenerationFencedLock($jobId, 'worker-c', 1, 120)->status, 'A no libera el lock de B.');

        // La DLQ del worker obsoleto (epoch 1) no puede cerrar el job que ya pertenece al epoch 2.
        self::assertFalse($this->store->markJobFailed($jobId, 'worker obsoleto', 1, 1));
        self::assertSame('pending', $this->summary($jobId)['status']);

        // B termina el trabajo (re-congela y completa) sin interferencia.
        self::assertTrue($this->store->releaseJobGenerationLock($jobId, 'worker-b', 2));
        $this->invoices->onQuery = null;
        $result = $this->runChunk($jobId, new BatchChunkState(chunkSizeOverride: 10), 'worker-b2');

        self::assertFalse($result['has_more']);
        self::assertSame(5, $this->summary($jobId)['total']);
        self::assertSame(3, $this->summary($jobId)['generation_epoch']);
    }

    /** `R-PREPUB`: caída tras `registerAuditInJob` y antes de publicar; la reserva se libera; se recupera sin duplicar. */
    public function testCrashAfterRegisterBeforePublishRecoversWithoutDuplicatesOrLostAudits(): void
    {
        $jobId = $this->newJob();
        $this->publisher->beforePublish = static function (AuditEvent $event): void {
            if ($event->eventType === AuditEvent::TYPE_AUDIT_CREATED) {
                throw new RuntimeException('caída antes de publicar audit_created');
            }
        };

        try {
            $this->runChunk($jobId, new BatchChunkState(chunkSizeOverride: 10), 'worker-a');
            self::fail('Se esperaba la caída simulada.');
        } catch (JobGenerationFailedException $error) {
            self::assertSame('caída antes de publicar audit_created', $error->getMessage());
        }

        // A registró las 5 y creó sus estados, pero no publicó ninguna (dispatched = false).
        self::assertSame(5, $this->summary($jobId)['total']);
        self::assertFalse($this->store->getBatchDispensaEntry($jobId, 'D1')['dispatched']);
        // Una reserva liberada y un estado perdido (simula limpieza parcial) no impiden la recuperación.
        $token = AuditEvent::deterministicUuidV4("batch-res:{$jobId}:D1");
        self::assertTrue($this->store->releaseAuditReservation('D1', $token));

        $this->publisher->beforePublish = null;
        $this->publisher->published = [];
        $result = $this->runChunk($jobId, new BatchChunkState(chunkSizeOverride: 10), 'worker-b');

        self::assertFalse($result['has_more']);
        $created = $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED);
        self::assertCount(5, $created, 'Las 5 auditorías registradas se publican exactamente una vez.');
        self::assertSame(
            array_map(static fn (int $i): string => AuditEvent::deterministicUuidV4("batch-audit:{$jobId}:D{$i}"), [1, 2, 3, 4, 5]),
            array_map(static fn (AuditEvent $event): string => (string) $event->auditId, $created)
        );
        $summary = $this->summary($jobId);
        self::assertSame(5, $summary['total'], '0 duplicadas, 0 faltantes.');
        self::assertCount(5, $this->store->getJob($jobId)['audits']);
        self::assertTrue($summary['batch_created_published']);
        for ($i = 1; $i <= 5; $i++) {
            self::assertTrue($this->store->getBatchDispensaEntry($jobId, "D{$i}")['dispatched']);
        }
        self::assertSame(0, $summary['skipped_locked']);
    }

    /** `R-CONT`: caída entre el checkpoint de k y la publicación de la continuación. */
    public function testCrashAfterCheckpointBeforeContinuationIsRecoveredByRedelivery(): void
    {
        $jobId = $this->newJob();
        $worker = $this->workerFor();
        $event = $this->batchEvent($jobId);
        $crashed = false;
        $this->publisher->beforePublish = function (AuditEvent $e) use (&$crashed): void {
            if ($e->eventType === AuditEvent::TYPE_BATCH_REQUESTED && !$crashed) {
                $crashed = true;
                throw new RuntimeException('caída antes de la continuación');
            }
        };

        try {
            $worker->processEvent($event);
            self::fail('Se esperaba la caída simulada.');
        } catch (RuntimeException $error) {
            self::assertSame('caída antes de la continuación', $error->getMessage());
        }
        $summary = $this->summary($jobId);
        self::assertSame(1, $summary['last_committed_chunk']);
        self::assertSame(0, $summary['last_continuation_dispatched_chunk']);
        self::assertSame([], $this->publisher->ofType(AuditEvent::TYPE_BATCH_REQUESTED));

        $worker->processEvent($event); // reentrega

        $continuations = $this->publisher->ofType(AuditEvent::TYPE_BATCH_REQUESTED);
        self::assertCount(1, $continuations);
        self::assertSame(AuditEvent::deterministicUuidV4("batch-cont:{$jobId}:2"), $continuations[0]->eventId);
        self::assertSame(2, $continuations[0]->payload['chunk_index']);
        self::assertSame(2, $continuations[0]->payload['candidate_offset']);
        self::assertSame(1, $this->summary($jobId)['last_continuation_dispatched_chunk']);
        self::assertSame(1, $this->invoices->getCalls());
        self::assertCount(2, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED), 'Sin auditorías duplicadas.');
        self::assertSame(2, $this->summary($jobId)['total']);
    }

    /**
     * Prepara una fila creada por un intento con epoch 1 y nunca registrada: estado `audit:{id}:state` + reserva propia.
     *
     * @return array{0:string,1:string,2:string} `[jobId, auditId, token]`
     */
    private function unregisteredRow(string $ddn = 'D1'): array
    {
        $jobId = $this->newJob();
        $claim = $this->store->claimJobGenerationFencedLock($jobId, 'worker-a', 1, 120);
        self::assertSame(1, $claim->epoch);
        $auditId = AuditEvent::deterministicUuidV4("batch-audit:{$jobId}:{$ddn}");
        $token = AuditEvent::deterministicUuidV4("batch-res:{$jobId}:{$ddn}");
        self::assertTrue($this->states->initAudit($auditId, $ddn, $jobId, (string) self::NIT, '1001'));
        self::assertTrue($this->states->patchAudit($auditId, ['reservation_token' => $token]));
        self::assertTrue($this->store->claimAuditReservation($ddn, $token, ['job_id' => $jobId, 'audit_id' => $auditId, 'source' => 'batch']));

        return [$jobId, $auditId, $token];
    }

    /** VER-B2-01 (i): epoch vigente y sin `by_dispensa` → borra el estado y la reserva propios en un solo EVAL. */
    public function testDiscardUnregisteredAuditDeletesOwnStateAndReservationWithCurrentEpoch(): void
    {
        [$jobId, $auditId, $token] = $this->unregisteredRow();

        self::assertTrue($this->store->discardUnregisteredAudit($jobId, $auditId, 'D1', $token, 1));

        self::assertNull($this->states->getAudit($auditId));
        self::assertNull($this->store->getAuditReservation('D1'));
        self::assertSame(0, $this->summary($jobId)['total'], 'El job no se modifica.');
    }

    /** VER-B2-01 (ii): tras un takeover (epoch 1 → 2) el propietario obsoleto no borra nada y recibe `false`. */
    public function testDiscardUnregisteredAuditWithStaleEpochDeletesNothing(): void
    {
        [$jobId, $auditId, $token] = $this->unregisteredRow();
        $this->dropGenerationLock($jobId);
        self::assertSame(2, $this->store->claimJobGenerationFencedLock($jobId, 'worker-b', 1, 120)->epoch);

        self::assertFalse($this->store->discardUnregisteredAudit($jobId, $auditId, 'D1', $token, 1));

        self::assertSame($jobId, $this->states->getAudit($auditId)['job_id'] ?? null, 'El estado que B reutilizará sobrevive.');
        self::assertSame($token, $this->store->getAuditReservation('D1')['token'] ?? null, 'La reserva sobrevive.');
        self::assertFalse($this->store->discardUnregisteredAudit(AuditEvent::uuidV4(), $auditId, 'D1', $token, 1), 'Job inexistente: false.');
        self::assertNotNull($this->states->getAudit($auditId));
    }

    /** VER-B2-01 (iii): con entrada `by_dispensa` (registrada por el propietario vigente) no se borra nada. */
    public function testDiscardUnregisteredAuditKeepsRegisteredDispensa(): void
    {
        [$jobId, $auditId, $token] = $this->unregisteredRow();
        self::assertTrue($this->store->registerAuditInJob($jobId, $auditId, 'D1', '1001', $token, 1));

        self::assertFalse($this->store->discardUnregisteredAudit($jobId, $auditId, 'D1', $token, 1));

        self::assertNotNull($this->states->getAudit($auditId));
        self::assertSame($token, $this->store->getAuditReservation('D1')['token'] ?? null);
        self::assertSame(1, $this->summary($jobId)['total']);
        self::assertFalse($this->store->getBatchDispensaEntry($jobId, 'D1')['dispatched']);
    }

    /** VER-B2-01 (iv): una reserva con token ajeno y un estado de otro job se conservan aunque las guardas se cumplan. */
    public function testDiscardUnregisteredAuditKeepsForeignReservationAndStateOfAnotherJob(): void
    {
        [$jobId, $auditId, $token] = $this->unregisteredRow();
        self::assertTrue($this->store->releaseAuditReservation('D1', $token));
        self::assertTrue($this->store->claimAuditReservation('D1', 'token-ajeno', ['job_id' => 'otro-job', 'audit_id' => 'x', 'source' => 'single']));
        $foreignAudit = AuditEvent::uuidV4();
        self::assertTrue($this->states->initAudit($foreignAudit, 'D2', 'otro-job', (string) self::NIT, '1002'));

        self::assertTrue($this->store->discardUnregisteredAudit($jobId, $auditId, 'D1', $token, 1));
        self::assertTrue($this->store->discardUnregisteredAudit($jobId, $foreignAudit, 'D2', '', 1));

        self::assertNull($this->states->getAudit($auditId), 'El estado propio sí se borra.');
        self::assertSame('token-ajeno', $this->store->getAuditReservation('D1')['token'] ?? null, 'La reserva ajena se conserva.');
        self::assertSame('otro-job', $this->states->getAudit($foreignAudit)['job_id'] ?? null, 'El estado de otro job se conserva.');
    }

    /** `R-CONT` (CA-F2-09): el claim de k+1 ocurre entre publish(k+1) y la confirmación de k; no hay 409 ni republicación. */
    public function testNextChunkClaimBeforeConfirmingCurrentChunkDoesNotFailNorRepublish(): void
    {
        $jobId = $this->newJob();
        $this->publisher->beforePublish = function (AuditEvent $e) use ($jobId): void {
            if ($e->eventType === AuditEvent::TYPE_BATCH_REQUESTED) {
                $claim = $this->store->claimJobGenerationFencedLock($jobId, 'worker-b', 2, 120);
                self::assertSame(JobLockClaimStatus::ACQUIRED, $claim->status);
                self::assertSame(2, $claim->epoch);
            }
        };

        $this->workerFor()->processEvent($this->batchEvent($jobId));

        $summary = $this->summary($jobId);
        self::assertSame(1, $summary['last_committed_chunk']);
        self::assertSame(1, $summary['last_continuation_dispatched_chunk']);
        self::assertSame(2, $summary['generation_epoch']);
        self::assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_BATCH_REQUESTED), 'No se republica k+1.');
        self::assertSame(
            JobLockClaimStatus::BUSY,
            $this->store->claimJobGenerationFencedLock($jobId, 'worker-c', 2, 120)->status,
            'El lock de B sigue intacto tras confirmar k.'
        );
    }
}
