<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\BatchChunkState;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\JobGenerationFailedException;
use Core\Env;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CapturesLogs;
use Tests\Support\FakeBatchInvoicesModel;
use Tests\Support\InMemoryAuditStateStore;
use Tests\Support\InMemoryBatchJobStore;
use Tests\Support\RecordingAuditEventPublisher;

/**
 * SDD-BATCH-001 v1.2, Sección 13 (`AuditBatchOrchestrator`) y Sección 16: congelación única, fencing por epoch,
 * recuperación R-PREPUB/R-CONT/R-EMPTY y compensación segura (W-F2-B1).
 */
final class AuditBatchOrchestratorTest extends TestCase
{
    use CapturesLogs;

    /** UUID v4 fijo (los eventos exigen UUID v4) sin la secuencia `2624`, para poder verificar ausencia de NIT en logs. */
    private const JOB = '11111111-1111-4111-8111-111111111111';
    private const NIT = 2624;
    private const FROM = '2026-06-01';
    private const TO = '2026-06-30';

    private InMemoryBatchJobStore $store;
    private InMemoryAuditStateStore $states;
    private RecordingAuditEventPublisher $publisher;
    private FakeBatchInvoicesModel $invoices;
    private AuditBatchOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->useRows([]);
    }

    protected function tearDown(): void
    {
        $this->stopLogCapture();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function useRows(array $rows): void
    {
        $this->store = new InMemoryBatchJobStore();
        $this->states = new InMemoryAuditStateStore();
        $this->store->auditStateStore = $this->states;
        $this->publisher = new RecordingAuditEventPublisher();
        $this->invoices = new FakeBatchInvoicesModel($rows);
        $this->orchestrator = new AuditBatchOrchestrator($this->states, $this->store, $this->publisher, $this->invoices);
    }

    private function createJob(int $limit = 100, string $jobId = self::JOB): string
    {
        $result = $this->store->initJobExclusive($jobId, self::NIT, self::FROM, self::TO, $limit);
        $this->assertTrue($result['created']);

        return $jobId;
    }

    /**
     * Prepara un job con candidatas ya congeladas (sin pasar por SQL).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $jobOverrides
     */
    private function seedFrozen(array $rows, int $limit = 100, array $jobOverrides = [], string $jobId = self::JOB): string
    {
        $this->store->seedJob($jobId, array_merge([
            'limit' => $limit,
            'candidates_frozen' => true,
            'candidates_count' => count($rows),
        ], $jobOverrides));
        $this->store->candidates[$jobId] = $rows;

        return $jobId;
    }

    /**
     * @return array<string,mixed>
     */
    private function runChunk(
        ?BatchChunkState $state = null,
        string $worker = 'w1',
        int $limit = 100,
        string $jobId = self::JOB
    ): array {
        return $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, $limit, $jobId, $worker, $state);
    }

    /**
     * Simula el bucle del worker: encadena chunks con el estado de continuación hasta `has_more = false`.
     *
     * @return array<int,array<string,mixed>> Resultado de cada chunk.
     */
    private function drain(int $chunkSize, int $limit = 100, string $jobId = self::JOB): array
    {
        $state = new BatchChunkState(chunkSizeOverride: $chunkSize);
        $results = [];
        for ($guard = 0; $guard < 50; $guard++) {
            $result = $this->runChunk($state, 'w1', $limit, $jobId);
            $results[] = $result;
            if (($result['has_more'] ?? false) !== true) {
                return $results;
            }
            $state = new BatchChunkState(
                cursor: $result['next_cursor'],
                chunkIndex: $state->chunkIndex + 1,
                accumulatedTotal: $result['accumulated_total'],
                accumulatedSkippedLocked: $result['accumulated_skipped_locked'],
                accumulatedSkippedExisting: $result['accumulated_skipped_existing'],
                chunkSizeOverride: $chunkSize,
                candidateOffset: (int) $result['next_candidate_offset'],
            );
        }
        $this->fail('El bucle de chunks no terminó.');
    }

    private static function auditIdFor(string $disDetNro, string $jobId = self::JOB): string
    {
        return AuditEvent::deterministicUuidV4("batch-audit:{$jobId}:{$disDetNro}");
    }

    private static function tokenFor(string $disDetNro, string $jobId = self::JOB): string
    {
        return AuditEvent::deterministicUuidV4("batch-res:{$jobId}:{$disDetNro}");
    }

    /**
     * @return array<string,string> `Dispensa => auditId` de los `audit_created` publicados.
     */
    private function publishedAuditIds(): array
    {
        $ids = [];
        foreach ($this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED) as $event) {
            $ids[(string) $event->payload['dis_det_nro']] = (string) $event->auditId;
        }

        return $ids;
    }

    // ── Flujo base (modo compatibilidad: el orquestador crea el job) ─────

    public function testEnqueueBatchPublishesCompletedEventWhenNoCandidates(): void
    {
        $result = $this->orchestrator->enqueueBatch(2426, '2026-06-12', '2026-06-12', 1);

        $this->assertSame(BatchJobStore::JOB_STATUS_COMPLETED, $result['status']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['accepted']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(0, $this->store->countCalls('claimAuditReservation'));
        $this->assertSame(1, $this->store->countCalls('publishEmptyBatch'), 'Chunk 1 sin candidatas usa publishEmptyBatch (R-EMPTY).');
        $this->assertSame(0, $this->store->countCalls('sealJob'));
        $this->assertSame(0, $this->store->countCalls('storeFrozenCandidates'));
        $this->assertCount(0, $this->publisher->published);
        $jobId = (string) $result['job_id'];
        $this->assertSame('completed', $this->store->jobs[$jobId]['status']);
        $this->assertTrue($this->store->jobs[$jobId]['sealed']);
        $this->assertSame([1], array_slice($this->store->callsOf('publishEmptyBatch')[0], 3), 'publishEmptyBatch recibe el epoch del claim.');
    }

    public function testEnqueueBatchClaimsReservationAndPublishesAuditCreated(): void
    {
        $this->useRows([
            ['NitSec' => 2426, 'DisId' => '87723098', 'Dispensa' => 'T38250701547', 'DisFecSol' => '2026-06-12T00:00:00'],
        ]);

        $result = $this->orchestrator->enqueueBatch(2426, '2026-06-12', '2026-06-12', 1);
        $jobId = (string) $result['job_id'];
        $auditId = self::auditIdFor('T38250701547', $jobId);

        $this->assertSame(BatchJobStore::JOB_STATUS_QUEUED, $result['status']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $this->store->countCalls('claimAuditReservation'));
        $this->assertSame('87723098', $this->store->reservations['T38250701547']['dis_id']);
        $this->assertSame($auditId, $this->store->reservations['T38250701547']['audit_id']);
        $this->assertSame(self::tokenFor('T38250701547', $jobId), $this->store->reservations['T38250701547']['token']);

        $this->assertCount(2, $this->publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $this->publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::deterministicUuidV4("batch-created:{$jobId}"), $this->publisher->published[0]->eventId);
        $audit = $this->publisher->published[1];
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $audit->eventType);
        $this->assertSame($auditId, $audit->auditId);
        $this->assertSame(AuditEvent::deterministicUuidV4("audit-created:{$jobId}:{$auditId}"), $audit->eventId);
        $this->assertSame('87723098', $audit->payload['dis_id']);
        $this->assertSame('T38250701547', $audit->payload['dis_det_nro']);
        $this->assertSame(self::tokenFor('T38250701547', $jobId), $audit->payload['reservation_token']);
        $this->assertSame(self::tokenFor('T38250701547', $jobId), $this->states->audits[$auditId]['reservation_token']);

        $this->assertTrue($this->store->byDispensa[$jobId]['T38250701547']['dispatched'], 'Tras publicar queda dispatched = true.');
        $this->assertTrue($this->store->jobs[$jobId]['batch_created_published']);
        $this->assertTrue($this->store->jobs[$jobId]['sealed']);
    }

    public function testEnqueueBatchDoesNotCleanupStateAfterPublishingAnEvent(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->publisher->beforePublish = function (AuditEvent $event, RecordingAuditEventPublisher $publisher): void {
            if (count($publisher->published) >= 1) {
                throw new RuntimeException('publish failed');
            }
        };

        try {
            $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, 1);
            $this->fail('Se esperaba una excepción por fallo de publicación.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('publish failed', $error->getMessage());
        }

        $this->assertCount(1, $this->publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $this->publisher->published[0]->eventType);
        $this->assertSame([], $this->states->deleted);
        $this->assertSame(0, $this->store->countCalls('deleteJob'));
        $this->assertSame(0, $this->store->countCalls('releaseAuditReservation'));
        $this->assertArrayHasKey('D1', $this->store->reservations);
    }

    public function testEnqueueBatchReturnsExistingStateIfJobAlreadySealed(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->store->seedJob('job-sealed', [
            'sealed' => true,
            'status' => BatchJobStore::JOB_STATUS_COMPLETED,
            'total' => 10,
            'accepted' => 10,
            'skipped_locked' => 2,
            'skipped_existing' => 1,
        ]);

        $result = $this->runChunk(null, 'worker-c', 100, 'job-sealed');

        $this->assertSame(BatchJobStore::JOB_STATUS_COMPLETED, $result['status']);
        $this->assertSame(10, $result['total']);
        $this->assertSame(2, $result['skipped_locked']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertFalse($result['has_more']);
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->invoices->getCalls());
        $this->assertSame(0, $this->store->countCalls('claimJobGenerationFencedLock'), 'Respuesta temprana: sin lock ni SQL.');
    }

    public function testChunkAlreadyCommittedAndContinuationDispatchedReturnsEarlyWithoutLock(): void
    {
        $this->seedFrozen(FakeBatchInvoicesModel::rows(4), 100, [
            'last_committed_chunk' => 1,
            'last_continuation_dispatched_chunk' => 1,
            'next_candidate_offset' => 2,
            'accepted' => 2,
            'total' => 2,
        ]);

        $result = $this->runChunk(new BatchChunkState(chunkIndex: 1, chunkSizeOverride: 2));

        $this->assertFalse($result['has_more']);
        $this->assertSame(0, $this->store->countCalls('claimJobGenerationFencedLock'));
        $this->assertSame(0, $this->store->countCalls('readFrozenCandidates'));
        $this->assertCount(0, $this->publisher->published);
    }

    public function testExternalJobMissingFromRedisThrows503BeforeLocking(): void
    {
        try {
            $this->runChunk(null, 'w1', 100, 'job-inexistente');
            $this->fail('Se esperaba RuntimeException 503.');
        } catch (RuntimeException $error) {
            $this->assertNotInstanceOf(JobGenerationFailedException::class, $error);
            $this->assertSame(503, $error->getCode());
            $this->assertStringContainsString('job-inexistente', $error->getMessage());
        }
        $this->assertSame(0, $this->store->countCalls('claimJobGenerationFencedLock'));
    }

    // ── Congelación única, chunks y fencing (REQ-01, REQ-02) ─────────────

    public function testFreezeExecutesSqlOnceAndChunksFromCandidatesAndEnforcesFencingEpoch(): void
    {
        $rows = [
            ['NitSec' => self::NIT, 'DisId' => '777', 'Dispensa' => 'S1', 'DisFecSol' => '2026-06-12T00:01:00'],
            ['NitSec' => self::NIT, 'DisId' => '777', 'Dispensa' => 'S2', 'DisFecSol' => '2026-06-12T00:02:00'], // hermana del mismo DisId
            ['NitSec' => self::NIT, 'DisId' => '778', 'Dispensa' => 'S3', 'DisFecSol' => '2026-06-12T00:03:00'],
            ['NitSec' => self::NIT, 'DisId' => '779', 'Dispensa' => 'S4', 'DisFecSol' => '2026-06-12T00:04:00'],
            ['NitSec' => self::NIT, 'DisId' => '780', 'Dispensa' => 'S5', 'DisFecSol' => '2026-06-12T00:05:00'],
        ];
        $this->useRows($rows);
        $this->createJob(100);
        $sequence = [];
        $this->invoices->onQuery = function () use (&$sequence): void {
            $sequence[] = 'sql';
        };
        $this->store->interceptors['renewJobGenerationLock'] = function () use (&$sequence): void {
            $sequence[] = 'renew';
        };
        $this->startLogCapture();

        $results = $this->drain(2);

        $this->assertCount(3, $results);
        $this->assertSame([true, true, false], array_column($results, 'has_more'));
        $this->assertSame([2, 4], [$results[0]['next_candidate_offset'], $results[1]['next_candidate_offset']]);
        $this->assertSame([1, 2, 3], array_column($results, 'chunk_index'));
        $this->assertSame(1, $this->invoices->getCalls(), 'SQL se ejecuta una sola vez para todo el job (H1).');
        $call = $this->invoices->calls[0];
        $this->assertSame(AuditExecutionConfig::freezeQueryTimeoutSeconds(), $call['queryTimeoutSeconds']);
        $this->assertSame(self::JOB, $call['jobId']);
        $this->assertSame(100, $call['limit']);
        $this->assertNull($call['cursor']);
        $this->assertSame(['renew', 'sql', 'renew'], array_slice($sequence, 0, 3), 'El lock se renueva antes y después de la consulta.');
        $this->assertSame(1, $this->store->countCalls('storeFrozenCandidates'));

        // Ambas dispensas hermanas (mismo DisId) se aceptan por DisDetNro.
        $this->assertArrayHasKey('S1', $this->store->reservations);
        $this->assertArrayHasKey('S2', $this->store->reservations);
        $this->assertSame('777', $this->store->reservations['S1']['dis_id']);
        $this->assertSame('777', $this->store->reservations['S2']['dis_id']);
        $this->assertSame(0, $this->store->jobs[self::JOB]['skipped_locked']);
        $this->assertCount(5, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));
        $this->assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_BATCH_CREATED));

        // Cierre total = 5 coherente en todo el estado.
        $job = $this->store->jobs[self::JOB];
        $this->assertSame(5, $job['total']);
        $this->assertSame(5, $job['accepted']);
        $this->assertSame(5, count($this->store->audits[self::JOB]));
        $this->assertTrue($job['sealed']);
        $this->assertSame(5, $results[2]['total']);
        $this->assertSame([1, 2], array_map(static fn (array $args): int => $args[1], $this->store->callsOf('commitChunkCheckpoint')));
        $seal = $this->store->callsOf('sealJob')[0];
        $this->assertSame([self::JOB, 0, 0, 3, 3, 5], [$seal[0], $seal[1], $seal[2], $seal[3], $seal[4], $seal[5]]);
        $this->assertSame(3, count($this->store->callsOf('claimJobGenerationFencedLock')));

        // Log de congelación con NIT enmascarado y sin NIT crudo (R-OBS, R-PII).
        $contexts = $this->logContextsContaining('snapshot SQL batch congelado');
        $this->assertCount(1, $contexts);
        $this->assertSame('***624', $contexts[0]['fac_nit_sec_masked']);
        $this->assertSame(5, $contexts[0]['candidates_count']);
        $this->assertIsInt($contexts[0]['batch_generation_sql_ms']);
        $this->assertSame(self::JOB, $contexts[0]['job_id']);
        $this->assertArrayNotHasKey('fac_nit_sec', $contexts[0]);
        $this->assertStringNotContainsString('2624', $this->capturedContextsJson());
    }

    public function testStaleEpochIsRejectedWithoutMutatingJobOrTouchingLiveRows(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(3));
        $this->createJob();
        // Otro worker toma el job (epoch 1 → 2) mientras este worker ejecuta la consulta SQL.
        $this->invoices->onQuery = function (): void {
            $this->store->jobs[self::JOB]['generation_epoch'] = 2;
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 2));
            $this->fail('Se esperaba rechazo por epoch obsoleto.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame(1, $error->epoch);
            $this->assertSame(409, $error->getCode());
            $this->assertSame('generation_fence_stale_or_job_missing', $error->getMessage());
        }

        $job = $this->store->jobs[self::JOB];
        $this->assertSame(0, $job['total']);
        $this->assertFalse($job['candidates_frozen']);
        $this->assertSame(0, $job['last_committed_chunk']);
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame([], $this->store->reservations);
        $this->assertSame(0, $this->store->countCalls('deleteJob'));
        $this->assertSame([], $this->store->callsOf('commitChunkCheckpoint'));
    }

    public function testFreezeQueryLimitIsCappedByJobLimitAndByAcceptedAuditsOfAMigratedJob(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(3));
        $this->createJob(3);

        $this->drain(50, 3);

        $this->assertSame(3, $this->invoices->calls[0]['limit']);

        // Job v1 migrado en curso: 2 ya aceptadas, el cursor sigue desde la página previa.
        $this->useRows(FakeBatchInvoicesModel::rows(4));
        $this->store->seedJob(self::JOB, [
            'limit' => 10,
            'accepted' => 2,
            'total' => 2,
            'last_committed_chunk' => 1,
            'last_continuation_dispatched_chunk' => 1,
            'candidates_frozen' => false,
        ]);
        $this->store->audits[self::JOB] = ['a1' => ['dis_det_nro' => 'X1', 'status' => 'pending'], 'a2' => ['dis_det_nro' => 'X2', 'status' => 'pending']];
        $this->store->byDispensa[self::JOB] = ['X1' => ['audit_id' => 'a1', 'dispatched' => true], 'X2' => ['audit_id' => 'a2', 'dispatched' => true]];
        $cursor = ['date' => '2026-06-12T00:01:00', 'disId' => '1001', 'dispensa' => 'D1'];

        $this->runChunk(new BatchChunkState(cursor: $cursor, chunkIndex: 2, accumulatedTotal: 2), 'w1', 10);

        $this->assertSame(8, $this->invoices->calls[0]['limit'], 'limit - accepted para no exceder el límite del job.');
        $this->assertSame($cursor, $this->invoices->calls[0]['cursor'], 'Un job v1 migrado congela desde su cursor.');
    }

    public function testFreezeLimitReachedWarnsAndCompactsStoredCandidates(): void
    {
        $rows = [];
        for ($i = 1; $i <= AuditBatchOrchestrator::MAX_FREEZE_LIMIT; $i++) {
            $rows[] = ['NitSec' => self::NIT, 'DisId' => (string) $i, 'Dispensa' => 'M' . $i, 'DisFecSol' => '2026-06-12T00:00:00', 'Extra' => 'x'];
        }
        $this->useRows($rows);
        $this->createJob(60000);
        $this->startLogCapture();

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 1), 'w1', 60000);

        $this->assertTrue($result['has_more']);
        $this->assertSame(AuditBatchOrchestrator::MAX_FREEZE_LIMIT, $this->invoices->calls[0]['limit'], 'La congelación nunca pide más de MAX_FREEZE_LIMIT.');
        $this->assertCount(1, $this->logContextsContaining('batch_freeze_limit_reached'));
        $this->assertSame(AuditBatchOrchestrator::MAX_FREEZE_LIMIT, $this->store->jobs[self::JOB]['candidates_count']);
        $this->assertSame(
            ['NitSec', 'DisId', 'Dispensa', 'DisFecSol'],
            array_keys($this->store->candidates[self::JOB][0]),
            'Las candidatas se guardan compactas (4 columnas).'
        );
    }

    // ── Recuperación R-PREPUB ────────────────────────────────────────────

    public function testRecoveryBeforePublishAndAfterReservationReleasePreservesUniqueDeterministicAudits(): void
    {
        $rows = FakeBatchInvoicesModel::rows(3);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $a1 = self::auditIdFor('D1');
        $a2 = self::auditIdFor('D2');
        $a3 = self::auditIdFor('D3');

        // (a) D1: reserva existente del mismo (jobId, auditId) con token propio, sin entrada en by_dispensa.
        $this->store->reservations['D1'] = ['job_id' => self::JOB, 'audit_id' => $a1, 'dis_det_nro' => 'D1', 'token' => 'token-previo'];
        // (b) D2: registrada pero no publicada (dispatched = false) con su estado intacto.
        $this->store->byDispensa[self::JOB]['D2'] = ['audit_id' => $a2, 'dispatched' => false];
        $this->store->audits[self::JOB][$a2] = ['dis_det_nro' => 'D2', 'status' => 'pending'];
        $this->store->jobs[self::JOB]['accepted'] = 1;
        $this->store->jobs[self::JOB]['total'] = 1;
        $this->states->audits[$a2] = ['job_id' => self::JOB, 'dis_det_nro' => 'D2', 'reservation_token' => 'token-d2', 'status' => 'pending'];
        // (c) D3: ya publicada (dispatched = true) y su reserva ya fue liberada.
        $this->store->byDispensa[self::JOB]['D3'] = ['audit_id' => $a3, 'dispatched' => true];
        $this->store->audits[self::JOB][$a3] = ['dis_det_nro' => 'D3', 'status' => 'completed'];

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 10));

        $published = $this->publishedAuditIds();
        $this->assertSame(['D1' => $a1, 'D2' => $a2], $published, 'D1 y D2 se publican con su auditId determinista; D3 no se re-audita.');
        $reservationTokens = [];
        foreach ($this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED) as $event) {
            $reservationTokens[(string) $event->payload['dis_det_nro']] = $event->payload['reservation_token'];
        }
        $this->assertSame('token-previo', $reservationTokens['D1'], 'Reutiliza el token de la reserva propia existente.');
        $this->assertSame('token-d2', $reservationTokens['D2'], 'Reutiliza el token guardado en el estado de la auditoría.');
        $this->assertSame(0, $result['skipped_locked'], 'La recuperación nunca cuenta skipped_locked.');
        $this->assertTrue($this->store->byDispensa[self::JOB]['D1']['dispatched']);
        $this->assertTrue($this->store->byDispensa[self::JOB]['D2']['dispatched']);
        $this->assertSame(3, $this->store->jobs[self::JOB]['total'], 'Exactamente 3 auditorías únicas, 0 duplicadas.');
        $this->assertSame(3, count($this->store->audits[self::JOB]));
        $this->assertSame(0, $this->store->countCalls('releaseAuditReservation'));
        $claimed = array_map(static fn (array $args): string => $args[0], $this->store->callsOf('claimAuditReservation'));
        $this->assertSame(['D1'], $claimed, 'Solo D1 intenta reclamar reserva: D2 ya tiene estado y D3 está despachada.');
    }

    public function testInitAuditStateAcceptsExistingStateOfSameJobAfterCrash(): void
    {
        $rows = FakeBatchInvoicesModel::rows(1);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $auditId = self::auditIdFor('D1');
        // Caída tras initAudit() y antes de patchAudit()/registerAuditInJob(): el estado existe sin reservation_token.
        $this->states->audits[$auditId] = ['job_id' => self::JOB, 'dis_det_nro' => 'D1', 'status' => 'pending'];

        $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));

        $this->assertSame(self::tokenFor('D1'), $this->states->audits[$auditId]['reservation_token'], 'Se completa el token faltante.');
        $this->assertSame(['D1' => $auditId], $this->publishedAuditIds());
        $this->assertSame(1, $this->store->jobs[self::JOB]['total']);
    }

    public function testInitAuditStateRejectsExistingStateOfAnotherJob(): void
    {
        $rows = FakeBatchInvoicesModel::rows(1);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $this->states->audits[self::auditIdFor('D1')] = ['job_id' => 'otro-job', 'dis_det_nro' => 'D1', 'status' => 'pending'];

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba 503.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('No se pudo inicializar la auditoría en Redis', $error->getMessage());
            $this->assertSame(503, $error->getCode());
        }
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->store->countCalls('registerAuditInJob'));
    }

    public function testReservationOwnedByAnotherJobCountsAsSkippedLockedWithoutRegistering(): void
    {
        $rows = FakeBatchInvoicesModel::rows(2);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $this->store->reservations['D1'] = ['job_id' => 'otro-job', 'audit_id' => 'otra', 'dis_det_nro' => 'D1', 'token' => 'ajeno'];

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));

        $this->assertSame(1, $result['skipped_locked']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(['D2' => self::auditIdFor('D2')], $this->publishedAuditIds());
        $this->assertArrayNotHasKey('D1', $this->store->byDispensa[self::JOB]);
        $this->assertSame('ajeno', $this->store->reservations['D1']['token'], 'La reserva ajena no se toca.');
    }

    // ── Cierres (R-EMPTY) ────────────────────────────────────────────────

    public function testMultiChunkAllSkippedClosesJobWithTotalZero(): void
    {
        $rows = FakeBatchInvoicesModel::rows(4);
        $this->useRows($rows);
        $this->createJob();
        foreach (['D1', 'D2', 'D3', 'D4'] as $ddn) {
            $this->store->reservations[$ddn] = ['job_id' => 'otro-job', 'audit_id' => 'x', 'dis_det_nro' => $ddn, 'token' => 't-' . $ddn];
        }

        $results = $this->drain(2);

        $this->assertCount(2, $results);
        $this->assertSame(BatchJobStore::JOB_STATUS_COMPLETED, $results[1]['status']);
        $this->assertSame(0, $results[1]['total']);
        $job = $this->store->jobs[self::JOB];
        $this->assertSame('completed', $job['status']);
        $this->assertTrue($job['sealed']);
        $this->assertSame(0, $job['total']);
        $this->assertSame(4, $job['skipped_locked'], 'Los skipped de ambos chunks se acumulan (2 + 2).');
        $this->assertSame(0, $this->store->countCalls('publishEmptyBatch'), 'publishEmptyBatch solo existe para el chunk 1 sin candidatas.');
        $this->assertArrayNotHasKey(self::NIT, array_filter($this->store->activeNit, static fn (array $jobs): bool => $jobs !== []), 'El NIT queda liberado.');
        $this->assertCount(0, $this->publisher->published, 'Sin auditorías aceptadas no hay batch_created.');
    }

    public function testFinalChunkWithZeroAcceptedUsesSealJobAndKeepsAccumulatedSkipped(): void
    {
        $rows = FakeBatchInvoicesModel::rows(3);
        $this->useRows($rows);
        $this->seedFrozen($rows, 100, [
            'last_committed_chunk' => 1,
            'last_continuation_dispatched_chunk' => 1,
            'next_candidate_offset' => 2,
            'skipped_locked' => 3,
        ]);
        $this->store->reservations['D3'] = ['job_id' => 'otro-job', 'audit_id' => 'x', 'dis_det_nro' => 'D3', 'token' => 'ajeno'];

        $result = $this->runChunk(new BatchChunkState(chunkIndex: 2, chunkSizeOverride: 2, candidateOffset: 2));

        $this->assertFalse($result['has_more']);
        $this->assertSame(1, $this->store->countCalls('sealJob'));
        $this->assertSame(0, $this->store->countCalls('publishEmptyBatch'));
        $seal = $this->store->callsOf('sealJob')[0];
        $this->assertSame([1, 0, 1, 2, 3], array_slice($seal, 1), 'sealJob(job, +1 skipped, 0, epoch 1, chunk 2, offset 3).');
        $job = $this->store->jobs[self::JOB];
        $this->assertSame(4, $job['skipped_locked'], '3 acumulados + 1 de este chunk.');
        $this->assertSame(0, $job['total']);
        $this->assertSame('completed', $job['status']);
    }

    public function testEmptySnapshotOnLaterChunkSealsInsteadOfPublishEmptyBatch(): void
    {
        $this->useRows([]);
        $this->store->seedJob(self::JOB, [
            'limit' => 10,
            'accepted' => 2,
            'total' => 2,
            'skipped_locked' => 5,
            'last_committed_chunk' => 1,
            'last_continuation_dispatched_chunk' => 1,
            'next_candidate_offset' => 0,
            'candidates_frozen' => false,
            'done' => 2,
        ]);
        $this->store->audits[self::JOB] = ['a1' => ['dis_det_nro' => 'X1', 'status' => 'completed'], 'a2' => ['dis_det_nro' => 'X2', 'status' => 'completed']];
        $cursor = ['date' => '2026-06-12T00:01:00', 'disId' => '1001', 'dispensa' => 'D1'];

        $result = $this->runChunk(new BatchChunkState(cursor: $cursor, chunkIndex: 2, accumulatedTotal: 2, candidateOffset: 7), 'w1', 10);

        $this->assertFalse($result['has_more']);
        $this->assertSame(0, $this->store->countCalls('publishEmptyBatch'));
        $seal = $this->store->callsOf('sealJob')[0];
        $this->assertSame([self::JOB, 0, 0, 1, 2, 7], $seal, 'Offset = max(pista del mensaje, next_candidate_offset).');
        $job = $this->store->jobs[self::JOB];
        $this->assertSame(5, $job['skipped_locked'], 'No se sobrescriben los skipped_* acumulados.');
        $this->assertSame(2, $job['total']);
        $this->assertSame('completed', $job['status']);
    }

    public function testBatchCreatedEmittedByFirstChunkThatAccepts(): void
    {
        $rows = FakeBatchInvoicesModel::rows(6);
        $this->useRows($rows);
        $this->createJob();
        foreach (['D1', 'D2'] as $ddn) {
            $this->store->reservations[$ddn] = ['job_id' => 'otro-job', 'audit_id' => 'x', 'dis_det_nro' => $ddn, 'token' => 'ajeno'];
        }

        $results = $this->drain(2);

        $this->assertCount(3, $results);
        $this->assertSame(2, $results[0]['skipped_locked']);
        $created = $this->publisher->ofType(AuditEvent::TYPE_BATCH_CREATED);
        $this->assertCount(1, $created, 'batch_created se emite una sola vez, aunque el chunk 1 no haya aceptado nada.');
        $this->assertSame(2, $created[0]->payload['initial_chunk_size']);
        $this->assertSame(AuditEvent::deterministicUuidV4('batch-created:' . self::JOB), $created[0]->eventId);
        $firstAuditIndex = array_search(AuditEvent::TYPE_AUDIT_CREATED, array_map(static fn (AuditEvent $e): string => $e->eventType, $this->publisher->published), true);
        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $this->publisher->published[$firstAuditIndex - 1]->eventType, 'batch_created precede a los audit_created.');
        $this->assertTrue($this->store->jobs[self::JOB]['batch_created_published']);
        $this->assertSame(4, $this->store->jobs[self::JOB]['total']);
    }

    // ── Lock (R-EPOCH) ───────────────────────────────────────────────────

    public function testClaimLockHandlesTerminalBusyMissingAndInvalidStatuses(): void
    {
        $rows = FakeBatchInvoicesModel::rows(2);
        $this->useRows($rows);

        // BUSY → locked_busy y sin SQL ni escrituras.
        $this->seedFrozen($rows, 100, ['total' => 1, 'accepted' => 1]);
        $this->store->locks[self::JOB] = ['worker_id' => 'otro', 'epoch' => 1];
        $busy = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5), 'w2');
        $this->assertTrue($busy['locked_busy']);
        $this->assertFalse($busy['has_more']);
        $this->assertSame(1, $busy['total']);
        $this->assertSame(0, $this->invoices->getCalls());
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->store->countCalls('releaseJobGenerationLock'), 'No se libera un lock que no se adquirió.');
        $this->assertSame(['worker_id' => 'otro', 'epoch' => 1], $this->store->locks[self::JOB]);

        // TERMINAL (el job se cierra entre la lectura temprana y el claim) → respuesta no nula.
        $this->useRows($rows);
        $this->seedFrozen($rows, 100, ['total' => 2, 'accepted' => 2]);
        $this->store->interceptors['claimJobGenerationFencedLock'] = function (): void {
            $this->store->jobs[self::JOB]['status'] = 'completed';
        };
        $terminal = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
        $this->assertSame('completed', $terminal['status']);
        $this->assertFalse($terminal['has_more']);
        $this->assertArrayNotHasKey('locked_busy', $terminal);

        // MISSING → 503.
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $this->store->interceptors['claimJobGenerationFencedLock'] = function (): void {
            unset($this->store->jobs[self::JOB]);
        };
        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba 503 por job inexistente.');
        } catch (RuntimeException $error) {
            $this->assertNotInstanceOf(JobGenerationFailedException::class, $error);
            $this->assertSame(503, $error->getCode());
            $this->assertStringContainsString('no encontrado al adquirir lock', $error->getMessage());
        }

        // Respuesta inválida del store → se propaga sin envolver (no hay epoch).
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $this->store->invalidClaimResponse = true;
        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba invalid_generation_lock_claim_response.');
        } catch (RuntimeException $error) {
            $this->assertNotInstanceOf(JobGenerationFailedException::class, $error);
            $this->assertSame('invalid_generation_lock_claim_response', $error->getMessage());
        }
    }

    public function testTerminalClaimDuringReapingReturnsNonNullTerminalResponse(): void
    {
        $rows = FakeBatchInvoicesModel::rows(2);
        $this->useRows($rows);
        $this->seedFrozen($rows, 100, ['total' => 3, 'accepted' => 3, 'skipped_locked' => 4, 'status' => 'processing']);
        // El reaper marca `reaping = true` (sealed = false) justo antes del claim.
        $this->store->interceptors['claimJobGenerationFencedLock'] = function (): void {
            $this->store->jobs[self::JOB]['reaping'] = true;
        };

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));

        $this->assertSame(3, $result['total']);
        $this->assertSame(4, $result['skipped_locked']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(0, $this->invoices->getCalls());
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->store->countCalls('registerAuditInJob'));
    }

    public function testJobInReapingIsAnsweredEarlyWithoutLock(): void
    {
        $this->seedFrozen(FakeBatchInvoicesModel::rows(2), 100, ['reaping' => true, 'status' => 'processing', 'total' => 1, 'accepted' => 1]);

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));

        $this->assertFalse($result['has_more']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $this->store->countCalls('claimJobGenerationFencedLock'));
    }

    public function testExceptionAfterAcquireIsWrappedWithEpoch(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(2));
        $this->createJob();
        $timeout = new SqlServerOperationException('db2', 'execute', SqlServerOperationMode::READ, 1, 'HYT00', false, new \PDOException('Query timeout expired'));
        $this->invoices->onQuery = static function () use ($timeout): void {
            throw $timeout;
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba JobGenerationFailedException.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame(1, $error->epoch, 'El epoch del claim viaja en la excepción (INV-02).');
            $this->assertSame($timeout, $error->getPrevious());
            $this->assertSame($timeout->getMessage(), $error->getMessage());
        }

        $this->assertSame('pending', $this->store->jobs[self::JOB]['status'], 'El orquestador no cierra el job: lo hace la DLQ con fencing.');
        $this->assertSame([], $this->store->locks, 'El lock se libera aun ante excepción.');
        $this->assertSame([self::JOB, 'w1', 1], $this->store->callsOf('releaseJobGenerationLock')[0]);
        $this->assertCount(0, $this->publisher->published);
    }

    public function testNonJobExceptionsAreAlsoWrappedPreservingMessageAndCode(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->createJob();
        $this->invoices->onQuery = static function (): void {
            throw new \LogicException('inesperado', 77);
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba JobGenerationFailedException.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('inesperado', $error->getMessage());
            $this->assertSame(77, $error->getCode());
            $this->assertSame(1, $error->epoch);
            $this->assertInstanceOf(\LogicException::class, $error->getPrevious());
        }
    }

    // ── R-CONT ───────────────────────────────────────────────────────────

    public function testOrphanContinuationIsRecoveredUnderLockWithoutTouchingSqlOrPublishing(): void
    {
        $rows = FakeBatchInvoicesModel::rows(4);
        $this->useRows($rows);
        $cursor = ['date' => '2026-06-12T00:02:00', 'disId' => '1002', 'dispensa' => 'D2', 'offset' => 2];
        $this->seedFrozen($rows, 100, [
            'last_committed_chunk' => 1,
            'last_continuation_dispatched_chunk' => 0,
            'next_candidate_offset' => 2,
            'last_cursor' => $cursor,
            'accepted' => 2,
            'total' => 2,
            'skipped_locked' => 1,
        ]);

        $result = $this->runChunk(new BatchChunkState(chunkIndex: 1, chunkSizeOverride: 2));

        $this->assertTrue($result['has_more']);
        $this->assertSame($cursor, $result['next_cursor']);
        $this->assertSame(2, $result['next_candidate_offset']);
        $this->assertSame(1, $result['generation_epoch']);
        $this->assertSame(2, $result['accumulated_total']);
        $this->assertSame(1, $result['accumulated_skipped_locked']);
        $this->assertSame(0, $this->invoices->getCalls());
        $this->assertSame(0, $this->store->countCalls('readFrozenCandidates'));
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->store->countCalls('commitChunkCheckpoint'));
        $this->assertSame([], $this->store->locks, 'El lock se libera tras recuperar.');
    }

    // ── Compensación (W-F2-B1) ───────────────────────────────────────────

    public function testFencingFailureCleanupNeverDeletesStateOrReservationOfLiveAudit(): void
    {
        $rows = FakeBatchInvoicesModel::rows(3);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $registers = 0;
        $this->store->interceptors['registerAuditInJob'] = function () use (&$registers): void {
            if (++$registers === 2) {
                throw new RuntimeException('generation_fence_stale', 409);
            }
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba fencing.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('generation_fence_stale', $error->getMessage());
            $this->assertSame(1, $error->epoch);
        }

        $this->assertSame([], $this->states->deleted, '0 llamadas a deleteAudit.');
        $this->assertSame(0, $this->store->countCalls('releaseAuditReservation'));
        $this->assertSame(0, $this->store->countCalls('discardUnregisteredAudit'), 'Un 409 de fencing no intenta descartar nada.');
        $this->assertSame(0, $this->store->countCalls('deleteJob'));
        $this->assertArrayHasKey('D1', $this->store->reservations);
        $this->assertArrayHasKey('D2', $this->store->reservations);
        $this->assertArrayHasKey(self::auditIdFor('D1'), $this->states->audits);
        $this->assertArrayHasKey(self::auditIdFor('D2'), $this->states->audits);
    }

    public function testExternalJobRetryAfter503KeepsRegisteredRowsAndRecreatesMissingState(): void
    {
        $rows = FakeBatchInvoicesModel::rows(5);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $registers = 0;
        $this->store->interceptors['registerAuditInJob'] = function () use (&$registers): void {
            if (++$registers === 5) {
                throw new RuntimeException('Redis no disponible', 503);
            }
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));
            $this->fail('Se esperaba 503.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame(503, $error->getCode());
        }

        // D1..D4 ya registradas: conservan estado, reserva y entrada by_dispensa (caso c).
        foreach (['D1', 'D2', 'D3', 'D4'] as $ddn) {
            $this->assertArrayHasKey($ddn, $this->store->reservations, "reserva de {$ddn} conservada");
            $this->assertArrayHasKey(self::auditIdFor($ddn), $this->states->audits, "estado de {$ddn} conservado");
            $this->assertFalse($this->store->byDispensa[self::JOB][$ddn]['dispatched']);
        }
        $this->assertSame(0, $this->store->countCalls('deleteJob'), 'deleteJob nunca se invoca con job externo.');
        // D5 se creó en este intento sin llegar a registrarse: se compensa (caso c).
        $this->assertArrayNotHasKey('D5', $this->store->reservations);
        $this->assertArrayNotHasKey(self::auditIdFor('D5'), $this->states->audits);
        $this->assertArrayNotHasKey('D5', $this->store->byDispensa[self::JOB]);
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(
            [[self::JOB, self::auditIdFor('D5'), 'D5', self::tokenFor('D5'), 1]],
            $this->store->callsOf('discardUnregisteredAudit'),
            'Solo D5 se descarta, en una única operación atómica protegida por el epoch adquirido (1).'
        );
        $this->assertSame([], $this->states->deleted, 'La compensación externa no usa deleteAudit (DEL sin guarda).');

        // Reintento (otro worker toma el lock): A1 pierde su estado y D2 su reserva pasa a otro job.
        unset($this->states->audits[self::auditIdFor('D1')]);
        $this->store->reservations['D2'] = ['job_id' => 'otro-job', 'audit_id' => 'x', 'dis_det_nro' => 'D2', 'token' => 'ajeno'];
        unset($this->states->audits[self::auditIdFor('D2')]);
        $this->store->interceptors = [];
        $this->store->expireLock(self::JOB);
        $this->startLogCapture();

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5), 'w2');

        $published = $this->publishedAuditIds();
        $this->assertSame(['D1', 'D3', 'D4', 'D5'], array_keys($published), 'D2 no se publica: su reserva no es recuperable.');
        $this->assertArrayHasKey(self::auditIdFor('D1'), $this->states->audits, 'Step 5 recreó el estado de A1 antes de publicar.');
        $this->assertSame(self::JOB, $this->states->audits[self::auditIdFor('D1')]['job_id']);
        $this->assertCount(1, $this->logContextsContaining('batch_prepub_reservation_lost'));
        $this->assertFalse($this->store->byDispensa[self::JOB]['D2']['dispatched'], 'D2 queda para cancelUndispatched/reaper.');
        $this->assertSame(4, count($this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED)));
        $this->assertSame(0, $result['skipped_locked']);
        $this->assertSame(5, $this->store->jobs[self::JOB]['total'], '0 duplicados: exactamente 5 auditorías registradas.');
        $this->assertSame('ajeno', $this->store->reservations['D2']['token'], 'La reserva ajena no se toca.');
    }

    /**
     * VER-B2-01 (W-F2-B1, INV-02): A (epoch 1) falla con un 503 que NO es rechazo de fencing después de que B reclamó
     * el job (epoch 2). B registra, publica y confirma D1 DESPUÉS de cualquier lectura de `by_dispensa` de A y ANTES de
     * su borrado. La compensación de A no debe borrar el estado ni la reserva de la auditoría viva de B.
     */
    public function testStaleOwnerCompensationNeverDeletesStateRegisteredAndPublishedByNewOwner(): void
    {
        $rows = FakeBatchInvoicesModel::rows(1);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $auditId = self::auditIdFor('D1');
        $token = self::tokenFor('D1');

        // A crea estado y reserva de D1; al asociar el token su lock vence, B reclama (epoch 2) y A recibe un 503.
        $this->states->interceptors['patchAudit'] = function (): void {
            $this->store->expireLock(self::JOB);
            $claim = $this->store->claimJobGenerationFencedLock(self::JOB, 'worker-b', 1);
            $this->assertSame(2, $claim->epoch);
            throw new RuntimeException('Respuesta de Redis perdida tras el patch', 503);
        };
        // B completa su trabajo sobre D1 en el último instante antes del borrado de A: si A borra con
        // deleteAudit() (tras su lectura de by_dispensa) o con la operación atómica, B ya actuó antes.
        $takenOver = false;
        $takeover = function () use (&$takenOver, $auditId, $token): void {
            if ($takenOver) {
                return;
            }
            $takenOver = true;
            $this->states->audits[$auditId]['reservation_token'] = $token;
            $this->assertTrue($this->store->registerAuditInJob(self::JOB, $auditId, 'D1', '1001', $token, 2));
            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_CREATED,
                auditId: $auditId,
                jobId: self::JOB,
                payload: ['dis_det_nro' => 'D1', 'reservation_token' => $token],
            ));
            $this->assertTrue($this->store->markAuditsDispatched(self::JOB, ['D1' => $auditId], true, 2));
        };
        $this->store->interceptors['discardUnregisteredAudit'] = $takeover;
        $this->states->interceptors['deleteAudit'] = $takeover;

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5), 'worker-a');
            $this->fail('Se esperaba el 503 de A.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame(1, $error->epoch, 'La excepción de A conserva su epoch obsoleto.');
        }

        $this->assertTrue($takenOver, 'B actuó entre la decisión de compensar de A y su borrado.');
        $this->assertSame(2, $this->store->jobs[self::JOB]['generation_epoch']);
        $this->assertSame(['worker_id' => 'worker-b', 'epoch' => 2], $this->store->locks[self::JOB], 'A no libera el lock de B.');
        $this->assertTrue($this->store->byDispensa[self::JOB]['D1']['dispatched']);
        $this->assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED), 'Solo el audit_created de B.');
        // ADMIT necesita audit:{id}:state: sobrevive con la identidad del job y el token de B.
        $this->assertArrayHasKey($auditId, $this->states->audits, 'El estado de la auditoría viva de B sobrevive.');
        $this->assertSame(self::JOB, $this->states->audits[$auditId]['job_id']);
        $this->assertSame($token, $this->states->audits[$auditId]['reservation_token']);
        $this->assertSame([], $this->states->deleted, 'A nunca usa deleteAudit en la compensación externa.');
        $this->assertArrayHasKey('D1', $this->store->reservations, 'La reserva de B sobrevive.');
        $this->assertSame($token, $this->store->reservations['D1']['token']);
        $this->assertSame([[self::JOB, $auditId, 'D1', $token, 1]], $this->store->callsOf('discardUnregisteredAudit'));
    }

    /**
     * VER-B2-01, variante sin registro: B reclamó el job (epoch 2) pero todavía no registró D1. B reutilizará el estado y
     * la reserva deterministas de A (V-F2-B5), así que A (epoch 1) no debe descartarlos; luego B publica con estado.
     */
    public function testStaleOwnerCompensationKeepsUnregisteredRowWhenAnotherOwnerAlreadyClaimedTheJob(): void
    {
        $rows = FakeBatchInvoicesModel::rows(1);
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $auditId = self::auditIdFor('D1');
        $token = self::tokenFor('D1');
        $this->states->interceptors['patchAudit'] = function (): void {
            $this->store->expireLock(self::JOB);
            $this->assertSame(2, $this->store->claimJobGenerationFencedLock(self::JOB, 'worker-b', 1)->epoch);
            throw new RuntimeException('timeout de Redis', 503);
        };

        try {
            $this->runChunk(new BatchChunkState(chunkSizeOverride: 5), 'worker-a');
            $this->fail('Se esperaba el 503 de A.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame(1, $error->epoch);
        }

        $this->assertSame([[self::JOB, $auditId, 'D1', $token, 1]], $this->store->callsOf('discardUnregisteredAudit'));
        $this->assertArrayHasKey($auditId, $this->states->audits, 'Epoch obsoleto: el estado no se borra.');
        $this->assertSame($token, $this->store->reservations['D1']['token'] ?? null, 'Epoch obsoleto: la reserva no se libera.');

        // El siguiente propietario reutiliza estado y reserva y publica D1 con audit:{id}:state presente.
        $this->states->interceptors = [];
        $this->store->expireLock(self::JOB);
        $this->runChunk(new BatchChunkState(chunkSizeOverride: 5), 'worker-c');

        $this->assertSame(['D1' => $auditId], $this->publishedAuditIds());
        $this->assertSame($token, $this->states->audits[$auditId]['reservation_token']);
        $this->assertTrue($this->store->byDispensa[self::JOB]['D1']['dispatched']);
        $this->assertSame(1, $this->store->jobs[self::JOB]['total']);
    }

    public function testFirstChunkNonExternalFailureStillCleansUpFully(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(2));
        $registers = 0;
        $this->store->interceptors['registerAuditInJob'] = function () use (&$registers): void {
            if (++$registers === 2) {
                throw new RuntimeException('boom', 503);
            }
        };

        try {
            $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, 5);
            $this->fail('Se esperaba 503.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('boom', $error->getMessage());
        }

        $this->assertSame(1, $this->store->countCalls('deleteJob'));
        $this->assertSame([], $this->store->jobs, 'El job creado por el orquestador se elimina.');
        $this->assertCount(2, $this->states->deleted, 'Se borran los estados de ambas auditorías creadas (D1 y la fila fallida D2).');
        $this->assertSame([], $this->store->reservations, 'Se liberan ambas reservas.');
        $this->assertSame(2, $this->store->countCalls('releaseAuditReservation'));
    }

    public function testNonExternalFailureWithFencingKeepsJobAndRows(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->store->interceptors['registerAuditInJob'] = static function (): void {
            throw new RuntimeException('job_closed_or_reaping', 409);
        };

        try {
            $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, 5);
            $this->fail('Se esperaba fencing.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame('job_closed_or_reaping', $error->getMessage());
        }

        $this->assertSame(0, $this->store->countCalls('deleteJob'), 'Un rechazo de fencing jamás borra el job (el propietario vigente lo usa).');
        $this->assertSame([], $this->states->deleted);
        $this->assertSame(0, $this->store->countCalls('releaseAuditReservation'));
    }

    public function testNonExternalJobIsDeletedWhenTheLockClaimFails(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->store->interceptors['claimJobGenerationFencedLock'] = static function (): void {
            throw new RuntimeException('Redis caído');
        };

        try {
            $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, 5);
            $this->fail('Se esperaba RuntimeException.');
        } catch (RuntimeException $error) {
            $this->assertSame('Redis caído', $error->getMessage());
        }

        $this->assertSame([], $this->store->jobs);
        $this->assertSame(1, $this->store->countCalls('deleteJob'));
    }

    public function testNonExternalJobCreationFailsWith409OnOverlapAnd503OtherwiseWithoutLocking(): void
    {
        $this->useRows(FakeBatchInvoicesModel::rows(1));
        $this->createJob(10, 'job-activo');

        try {
            $this->orchestrator->enqueueBatch(self::NIT, '2026-06-15', '2026-07-15', 5);
            $this->fail('Se esperaba 409.');
        } catch (RuntimeException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertStringContainsString('Ya existe un job activo', $error->getMessage());
        }
        $this->assertSame(0, $this->store->countCalls('claimJobGenerationFencedLock'));
        $this->assertSame(0, $this->invoices->getCalls());
    }

    /**
     * W-F2-B3: un Lua de generación que rechaza con el epoch coincidente (job sellado/en reaping) equivale a fencing.
     *
     * @return array<string,array{0:string,1:string,2:int,3:string}>
     */
    public static function generationRejections(): array
    {
        return [
            'publishEmptyBatch' => ['publishEmptyBatch', 'generation_fence_stale_on_empty_batch', 0, 'no-external'],
            'storeFrozenCandidates' => ['storeFrozenCandidates', 'generation_fence_stale_or_job_missing', 2, 'no-external'],
            'markAuditsDispatched' => ['markAuditsDispatched', 'generation_fence_stale_on_dispatch', 2, 'external'],
            'sealJob' => ['sealJob', 'generation_fence_stale_on_seal', 1, 'external'],
            'commitChunkCheckpoint' => ['commitChunkCheckpoint', 'generation_fence_stale_on_checkpoint', 3, 'external'],
        ];
    }

    #[DataProvider('generationRejections')]
    public function testGenerationLuaRejectionAfterReapIsTreatedAsFencing(string $method, string $message, int $rows, string $mode): void
    {
        $candidateRows = FakeBatchInvoicesModel::rows($rows);
        $this->useRows($candidateRows);
        $chunkSize = $method === 'commitChunkCheckpoint' ? 1 : 5;
        // El reaper cierra el job (epoch coincidente) justo antes del Lua de generación.
        $this->store->interceptors[$method] = function (): void {
            foreach (array_keys($this->store->jobs) as $jobId) {
                $this->store->jobs[$jobId]['reaping'] = true;
            }
        };

        try {
            if ($mode === 'external') {
                $this->seedFrozen($candidateRows);
                $this->runChunk(new BatchChunkState(chunkSizeOverride: $chunkSize));
            } else {
                $this->orchestrator->enqueueBatch(self::NIT, self::FROM, self::TO, 5, null, 'w1', new BatchChunkState(chunkSizeOverride: $chunkSize));
            }
            $this->fail('Se esperaba rechazo de fencing.');
        } catch (JobGenerationFailedException $error) {
            $this->assertSame($message, $error->getMessage());
            $this->assertSame(409, $error->getCode());
            $this->assertSame(1, $error->epoch);
        }

        $this->assertSame(0, $this->store->countCalls('deleteJob'), 'Un rechazo de fencing nunca borra el job.');
        $this->assertSame([], $this->states->deleted);
        $this->assertSame(0, $this->store->countCalls('markContinuationDispatched'));
    }

    // ── PII (R-PII) ──────────────────────────────────────────────────────

    public function testInvalidCandidateRowIsSkippedWithoutLoggingTheRowOrTheRawNit(): void
    {
        $rows = [
            ['NitSec' => self::NIT, 'DisId' => '', 'Dispensa' => 'D1', 'DisFecSol' => '2026-06-12T00:00:00'],
            ['NitSec' => self::NIT, 'DisId' => '1002', 'Dispensa' => 'D2', 'DisFecSol' => '2026-06-12T00:00:01'],
        ];
        $this->useRows($rows);
        $this->seedFrozen($rows);
        $this->startLogCapture();

        $result = $this->runChunk(new BatchChunkState(chunkSizeOverride: 5));

        $this->assertSame(1, $result['total']);
        $this->assertSame(['D2' => self::auditIdFor('D2')], $this->publishedAuditIds());
        $warning = $this->logContextsContaining('candidata inválida');
        $this->assertCount(1, $warning);
        $this->assertSame(self::JOB, $warning[0]['job_id']);
        $this->assertArrayNotHasKey('invoice', $warning[0]);
        $this->assertStringNotContainsString('2624', $this->capturedContextsJson());
    }

    public function testMaskNitKeepsOnlyLastThreeDigits(): void
    {
        $this->assertSame('***624', AuditBatchOrchestrator::maskNit(2624));
        $this->assertSame('***890', AuditBatchOrchestrator::maskNit('900123890'));
        $this->assertSame('***12', AuditBatchOrchestrator::maskNit(12));
    }

    public function testDefaultChunkSizeComesFromEnvironmentWhenNoOverride(): void
    {
        $previous = Env::get('AUDIT_BATCH_CHUNK_SIZE');
        try {
            Env::set('AUDIT_BATCH_CHUNK_SIZE', '2');
            $rows = FakeBatchInvoicesModel::rows(5);
            $this->useRows($rows);
            $this->seedFrozen($rows);

            $result = $this->runChunk(null);

            $this->assertTrue($result['has_more']);
            $this->assertSame(2, $result['next_candidate_offset']);
            $this->assertCount(2, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));
        } finally {
            Env::set('AUDIT_BATCH_CHUNK_SIZE', $previous === false ? null : $previous);
            Env::clearCache('AUDIT_BATCH_CHUNK_SIZE');
        }
    }
}
