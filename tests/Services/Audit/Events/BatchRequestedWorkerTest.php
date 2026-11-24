<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\BatchRequestedWorker;
use App\Services\Audit\Pipeline\JobGenerationBusyException;
use Core\Env;
use Core\RedisClient;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\CapturesLogs;
use Tests\Support\FakeBatchInvoicesModel;
use Tests\Support\InMemoryAuditStateStore;
use Tests\Support\InMemoryBatchJobStore;
use Tests\Support\RecordingAuditEventPublisher;

/**
 * SDD-BATCH-001 v1.2, Sección 13/16 (`BatchRequestedWorker` + DLQ de `AuditEventConsumer`).
 */
final class BatchRequestedWorkerTest extends TestCase
{
    use CapturesLogs;

    /** UUID v4 fijo sin la secuencia `2624` (para verificar ausencia de NIT crudo en logs). */
    private const JOB = '22222222-2222-4222-8222-222222222222';
    private const NIT = 2624;

    private InMemoryBatchJobStore $store;
    private InMemoryAuditStateStore $states;
    private RecordingAuditEventPublisher $publisher;
    private FakeBatchInvoicesModel $invoices;
    private AuditBatchOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->buildWorld(FakeBatchInvoicesModel::rows(4));
    }

    protected function tearDown(): void
    {
        $this->stopLogCapture();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function buildWorld(array $rows): void
    {
        $this->store = new InMemoryBatchJobStore();
        $this->states = new InMemoryAuditStateStore();
        $this->publisher = new RecordingAuditEventPublisher();
        $this->invoices = new FakeBatchInvoicesModel($rows);
        $this->orchestrator = new AuditBatchOrchestrator($this->states, $this->store, $this->publisher, $this->invoices);
    }

    private function createJob(int $limit = 4): void
    {
        $result = $this->store->initJobExclusive(self::JOB, self::NIT, '2026-06-01', '2026-06-30', $limit);
        $this->assertTrue($result['created']);
    }

    private function redisMock(): RedisClient&MockObject
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);

        return $redis;
    }

    private function makeWorker(?RedisClient $redis = null): BatchRequestedWorker
    {
        return new BatchRequestedWorker(
            $this->states,
            $this->store,
            $redis ?? $this->redisMock(),
            $this->publisher,
            'test-batch-consumer',
            $this->orchestrator
        );
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function batchEvent(array $overrides = []): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: self::JOB,
            payload: array_merge([
                'fac_nit_sec' => (string) self::NIT,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'limit' => 4,
                'source' => 'cron',
                'chunk_size' => 1,
            ], $overrides)
        );
    }

    /** Invoca el `dispatchMessage()` real del consumidor (ACK, reintentos, DLQ). */
    private function dispatch(BatchRequestedWorker $worker, AuditEvent $event, string $streamId = '1700000000000-0'): void
    {
        $method = new \ReflectionMethod(AuditEventConsumer::class, 'dispatchMessage');
        $method->setAccessible(true);
        $method->invoke($worker, [
            'id' => $streamId,
            'stream' => AuditEventPublisher::STREAM_BATCH_INBOX,
            'fields' => ['event' => $event->toJson()],
        ]);
    }

    /**
     * @param array<string,string|null> $vars
     */
    private function withEnv(array $vars, callable $callback): void
    {
        $previous = [];
        foreach ($vars as $key => $value) {
            $previous[$key] = getenv($key);
            Env::set($key, $value);
        }
        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                Env::set($key, $value === false ? null : $value);
                Env::clearCache($key);
            }
        }
    }

    /**
     * @return array<int,AuditEvent> Eventos `batch_requested` publicados (continuaciones).
     */
    private function continuations(): array
    {
        return $this->publisher->ofType(AuditEvent::TYPE_BATCH_REQUESTED);
    }

    // ── Estructura del worker ────────────────────────────────────────────

    public function testBatchRequestedWorkerStreamsAndGroup(): void
    {
        $worker = new BatchRequestedWorker(
            $this->createMock(AuditStateStore::class),
            $this->createMock(BatchJobStore::class),
            $this->redisMock(),
            $this->createMock(AuditEventPublisher::class),
            'test-batch-consumer'
        );

        $refMethod = new \ReflectionMethod($worker, 'streams');
        $refMethod->setAccessible(true);
        $this->assertSame([AuditEventPublisher::STREAM_BATCH_INBOX], $refMethod->invoke($worker));

        $refGroup = new \ReflectionMethod($worker, 'group');
        $refGroup->setAccessible(true);
        $this->assertSame(AuditEventPublisher::GROUP_BATCH, $refGroup->invoke($worker));

        $refConsumer = new \ReflectionMethod($worker, 'consumer');
        $refConsumer->setAccessible(true);
        $this->assertSame('test-batch-consumer', $refConsumer->invoke($worker));
    }

    public function testBatchRequestedWorkerSets30MinuteReclaimTimeout(): void
    {
        $worker = $this->makeWorker();

        $refIdle = new ReflectionProperty(AuditEventConsumer::class, 'pendingReclaimIdleMs');
        $refIdle->setAccessible(true);
        $this->assertSame(1800000, $refIdle->getValue($worker));

        $refInterval = new ReflectionProperty(AuditEventConsumer::class, 'pendingReclaimIntervalMs');
        $refInterval->setAccessible(true);
        $this->assertSame(60000, $refInterval->getValue($worker));
    }

    public function testHandleIgnoresNonBatchRequestedEvent(): void
    {
        $worker = $this->makeWorker();

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            payload: []
        ));

        $this->assertCount(0, $this->publisher->published);
        $this->assertSame([], $this->store->calls);
    }

    public function testBatchRequestedWithoutJobIdIsRejected(): void
    {
        $worker = $this->makeWorker();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('batch_requested sin job_id');
        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: null,
            payload: ['fac_nit_sec' => '2426', 'date_from' => '2026-06-01', 'date_to' => '2026-06-30']
        ));
    }

    // ── Continuaciones ───────────────────────────────────────────────────

    public function testHandleBatchRequestedPublishesContinuationWhenHasMore(): void
    {
        $this->createJob(2);
        $initialEvent = $this->batchEvent(['limit' => 2]);

        $this->makeWorker()->processEvent($initialEvent);

        $continuations = $this->continuations();
        $this->assertCount(1, $continuations);
        $continuation = $continuations[0];
        $this->assertSame(self::JOB, $continuation->jobId);
        $this->assertSame($initialEvent->eventId, $continuation->parentEventId);
        $this->assertSame(AuditEvent::deterministicUuidV4('batch-cont:' . self::JOB . ':2'), $continuation->eventId);
        $this->assertSame(2, $continuation->payload['chunk_index']);
        $this->assertSame(1, $continuation->payload['accumulated_total']);
        $this->assertSame(1, $continuation->payload['candidate_offset']);
        $this->assertSame('1001', $continuation->payload['cursor']['disId']);
        $this->assertSame(1, $continuation->payload['cursor']['offset']);
        $this->assertSame(1, $continuation->payload['chunk_size']);
        $this->assertSame('cron', $continuation->payload['source']);
        $this->assertSame([[self::JOB, 1]], $this->store->callsOf('markContinuationDispatched'));
        $this->assertSame(1, $this->store->jobs[self::JOB]['last_continuation_dispatched_chunk']);
        $this->assertSame(1, $this->store->jobs[self::JOB]['last_committed_chunk']);
    }

    public function testHandleBatchRequestedDoesNotPublishContinuationWhenFinished(): void
    {
        $this->buildWorld(FakeBatchInvoicesModel::rows(1));
        $this->createJob(1);

        $this->makeWorker()->processEvent($this->batchEvent(['limit' => 1, 'chunk_size' => 50]));

        $this->assertSame([], $this->continuations());
        $this->assertSame(0, $this->store->countCalls('markContinuationDispatched'));
        $this->assertTrue($this->store->jobs[self::JOB]['sealed']);
        $this->assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));
    }

    public function testContinuationCarriesNextCandidateOffsetAndNeverRereadsSlice(): void
    {
        $this->createJob(4);
        $worker = $this->makeWorker();
        $event = $this->batchEvent();

        for ($step = 0; $step < 6; $step++) {
            $before = count($this->continuations());
            $worker->processEvent($event);
            $continuations = $this->continuations();
            if (count($continuations) === $before) {
                break;
            }
            $event = $continuations[count($continuations) - 1];
        }

        $continuations = $this->continuations();
        $this->assertCount(3, $continuations);
        $this->assertSame([1, 2, 3], array_map(static fn (AuditEvent $e): int => $e->payload['candidate_offset'], $continuations));
        $this->assertSame([1, 2, 3], array_map(static fn (AuditEvent $e): int => $e->payload['cursor']['offset'], $continuations));
        $this->assertSame([2, 3, 4], array_map(static fn (AuditEvent $e): int => $e->payload['chunk_index'], $continuations));
        $this->assertSame(
            [
                AuditEvent::deterministicUuidV4('batch-cont:' . self::JOB . ':2'),
                AuditEvent::deterministicUuidV4('batch-cont:' . self::JOB . ':3'),
                AuditEvent::deterministicUuidV4('batch-cont:' . self::JOB . ':4'),
            ],
            array_map(static fn (AuditEvent $e): string => $e->eventId, $continuations)
        );
        $this->assertSame(1, $this->invoices->getCalls(), 'Una sola consulta SQL para todo el job multi-chunk (CA-F2-03).');
        $offsetsRead = array_map(static fn (array $args): int => $args[1], $this->store->callsOf('readFrozenCandidates'));
        $this->assertSame([0, 1, 2, 3], $offsetsRead, 'Cada chunk lee su propio slice: nunca relee el anterior.');
        $dispensas = array_map(static fn (AuditEvent $e): string => $e->payload['dis_det_nro'], $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED));
        $this->assertSame(['D1', 'D2', 'D3', 'D4'], $dispensas, 'Cada dispensa se audita exactamente una vez, en orden.');
        $this->assertSame(4, $this->store->jobs[self::JOB]['total']);
        $this->assertTrue($this->store->jobs[self::JOB]['sealed']);
    }

    public function testContinuationConfirmedWithoutEpochAfterNextChunkClaimed(): void
    {
        $this->createJob(3);
        // R-CONT: tras publicar k+1, otro worker adquiere el lock de k+1 (epoch E+1) antes de que A confirme k.
        $this->publisher->beforePublish = function (AuditEvent $event): void {
            if ($event->eventType === AuditEvent::TYPE_BATCH_REQUESTED) {
                $claim = $this->store->claimJobGenerationFencedLock(self::JOB, 'worker-b', 2, 1800);
                $this->assertSame(2, $claim->epoch);
            }
        };
        $redis = $this->redisMock();
        $redis->expects($this->once())->method('xAck')
            ->with(AuditEventPublisher::STREAM_BATCH_INBOX, AuditEventPublisher::GROUP_BATCH, '1700000000000-0')
            ->willReturn(1);
        $redis->expects($this->never())->method('incr');

        $this->dispatch($this->makeWorker($redis), $this->batchEvent(['limit' => 3]));

        $this->assertSame([[self::JOB, 1]], $this->store->callsOf('markContinuationDispatched'), 'La confirmación no recibe epoch.');
        $job = $this->store->jobs[self::JOB];
        $this->assertSame(1, $job['last_continuation_dispatched_chunk']);
        $this->assertSame(2, $job['generation_epoch'], 'El epoch del claim de k+1 no se ve afectado.');
        $this->assertSame('worker-b', $this->store->locks[self::JOB]['worker_id'], 'El lock de B sigue intacto.');
        $this->assertCount(1, $this->continuations(), 'No se republica k+1.');
        $this->assertSame([], $this->publisher->deadLetters);
    }

    public function testCheckpointContinuationCrashRecoversPendingContinuationBeforeAck(): void
    {
        $this->createJob(4);
        $worker = $this->makeWorker();
        $event = $this->batchEvent();
        $publishes = 0;
        // Caída entre commitChunkCheckpoint(1) y la publicación de la continuación.
        $this->publisher->beforePublish = function (AuditEvent $e) use (&$publishes): void {
            if ($e->eventType === AuditEvent::TYPE_BATCH_REQUESTED && ++$publishes === 1) {
                throw new RuntimeException('crash antes de publicar la continuación');
            }
        };

        try {
            $worker->processEvent($event);
            $this->fail('Se esperaba la caída simulada.');
        } catch (RuntimeException $error) {
            $this->assertSame('crash antes de publicar la continuación', $error->getMessage());
        }
        $this->assertSame(1, $this->store->jobs[self::JOB]['last_committed_chunk']);
        $this->assertSame(0, $this->store->jobs[self::JOB]['last_continuation_dispatched_chunk']);
        $this->assertSame([], $this->continuations());

        // Reentrega del mismo mensaje `k`: recupera la continuación sin re-ejecutar SQL ni duplicar auditorías.
        $worker->processEvent($event);

        $continuations = $this->continuations();
        $this->assertCount(1, $continuations);
        $this->assertSame(AuditEvent::deterministicUuidV4('batch-cont:' . self::JOB . ':2'), $continuations[0]->eventId);
        $this->assertSame(2, $continuations[0]->payload['chunk_index']);
        $this->assertSame(1, $continuations[0]->payload['candidate_offset']);
        $this->assertSame(1, $continuations[0]->payload['accumulated_total']);
        $this->assertSame(1, $this->store->jobs[self::JOB]['last_continuation_dispatched_chunk']);
        $this->assertSame(1, $this->invoices->getCalls());
        $this->assertCount(1, $this->publisher->ofType(AuditEvent::TYPE_AUDIT_CREATED), '0 auditorías duplicadas.');
        $this->assertSame(1, $this->store->jobs[self::JOB]['total']);
    }

    public function testMarkContinuationDispatchedReturningFalseFailsTheChunkSoItIsRetried(): void
    {
        $this->createJob(4);
        $this->store->interceptors['markContinuationDispatched'] = function (): void {
            unset($this->store->jobs[self::JOB]);
        };

        try {
            $this->makeWorker()->processEvent($this->batchEvent());
            $this->fail('Se esperaba job_missing_on_continuation.');
        } catch (RuntimeException $error) {
            $this->assertSame('job_missing_on_continuation', $error->getMessage());
            $this->assertSame(503, $error->getCode());
        }
    }

    // ── Contención del lock (INV-02) ─────────────────────────────────────

    public function testLockContentionThrowsJobGenerationBusyAndLeavesMessagePendingInConsumer(): void
    {
        $this->createJob(4);
        $this->store->locks[self::JOB] = ['worker_id' => 'otro-worker', 'epoch' => 1];
        $event = $this->batchEvent(['chunk_index' => 3]);

        // 1) El worker lanza la excepción tipada (no retorna normal).
        try {
            $this->makeWorker()->processEvent($event);
            $this->fail('Se esperaba JobGenerationBusyException.');
        } catch (JobGenerationBusyException $busy) {
            $this->assertSame(self::JOB, $busy->jobId);
            $this->assertSame(3, $busy->chunkIndex);
        }
        $this->assertCount(0, $this->publisher->published);
        $this->assertSame(0, $this->invoices->getCalls());
        $this->assertSame(0, $this->store->countCalls('markContinuationDispatched'));

        // 2) El consumidor no hace XACK, no cuenta intentos y no envía a DLQ.
        $redis = $this->redisMock();
        $redis->expects($this->never())->method('xAck');
        $redis->expects($this->never())->method('incr');
        $redis->expects($this->never())->method('eval');
        $this->startLogCapture();

        $this->dispatch($this->makeWorker($redis), $event);

        $this->assertSame([], $this->publisher->deadLetters);
        $this->assertSame([], $this->store->failedJobs, 'BUSY nunca quema DLQ ni cierra el job.');
        $this->assertSame('pending', $this->store->jobs[self::JOB]['status']);
        $warning = $this->logContextsContaining('lock de generación ocupado');
        $this->assertCount(1, $warning);
        $this->assertSame(self::JOB, $warning[0]['job_id']);
        $this->assertSame(3, $warning[0]['chunk_index']);
        $this->assertSame($event->eventId, $warning[0]['event_id']);
    }

    // ── DLQ con fencing por epoch ────────────────────────────────────────

    /** Redis simulado para el camino de fallo terminal (agota reintentos). */
    private function redisForTerminalFailure(int $attempts = 3): RedisClient&MockObject
    {
        $redis = $this->redisMock();
        $redis->method('incr')->willReturn($attempts);
        $redis->method('eval')->willReturn(1);

        return $redis;
    }

    public function testDeadLetterBatchRequestedRespectsEpochFencingAndMarksActiveJobFailed(): void
    {
        // (a) Epoch vigente: el job pasa a failed y se libera el NIT.
        $this->createJob(4);
        $this->invoices->onQuery = static function (): void {
            throw new RuntimeException('falla persistente de congelación');
        };
        $this->dispatch($this->makeWorker($this->redisForTerminalFailure()), $this->batchEvent());

        $this->assertSame(
            [['job_id' => self::JOB, 'message' => 'falla persistente de congelación', 'epoch' => 1, 'failed_chunk' => 1]],
            $this->store->failedJobs
        );
        $this->assertSame('failed', $this->store->jobs[self::JOB]['status']);
        $this->assertCount(1, $this->publisher->deadLetters);
        $this->assertSame(AuditEvent::TYPE_DEAD_LETTER, $this->publisher->deadLetters[0]->eventType);

        // (b) Epoch obsoleto (el job ya pertenece al epoch 2): markJobFailed no lo destruye.
        $this->buildWorld(FakeBatchInvoicesModel::rows(4));
        $this->createJob(4);
        $this->invoices->onQuery = function (): void {
            $this->store->jobs[self::JOB]['generation_epoch'] = 2;
            $this->store->jobs[self::JOB]['status'] = 'processing';
            throw new RuntimeException('falla de un worker obsoleto');
        };
        $this->dispatch($this->makeWorker($this->redisForTerminalFailure()), $this->batchEvent());

        $this->assertSame(1, $this->store->failedJobs[0]['epoch'], 'La DLQ propaga el epoch del worker que falló.');
        $this->assertSame('processing', $this->store->jobs[self::JOB]['status'], 'Un epoch obsoleto no cierra el job vigente.');
        $this->assertSame(2, $this->store->jobs[self::JOB]['generation_epoch']);
        $this->assertCount(1, $this->publisher->deadLetters, 'El mensaje sí se mueve a DLQ y se confirma.');
        $this->assertSame([], $this->store->cancelledUndispatchedFor);

        // (c) Sin epoch (fallo antes de adquirir el lock): no se muta el job y se registra la alerta.
        $this->buildWorld(FakeBatchInvoicesModel::rows(4));
        $this->createJob(4);
        $this->store->interceptors['getJobSummary'] = static function (): void {
            throw new RuntimeException('Redis no disponible antes del lock');
        };
        $this->startLogCapture();
        $this->dispatch($this->makeWorker($this->redisForTerminalFailure()), $this->batchEvent());

        $this->assertSame(0, $this->store->countCalls('markJobFailed'), 'Sin epoch no se llama markJobFailed.');
        $this->assertSame('pending', $this->store->jobs[self::JOB]['status']);
        $critical = array_values(array_filter(
            $this->capturedLogs(),
            static fn (array $entry): bool => ($entry['message'] ?? '') === 'batch_requested_dlq_without_epoch'
        ));
        $this->assertCount(1, $critical);
        $this->assertSame('CRITICAL', $critical[0]['level']);
        $this->assertSame(self::JOB, $critical[0]['context']['job_id']);
        $this->assertCount(1, $this->publisher->deadLetters);
    }

    public function testSqlTimeoutWrappedIsNonRetryableAndGoesToDlqOnFirstAttempt(): void
    {
        $this->createJob(4);
        $timeout = new SqlServerOperationException('db2', 'execute', SqlServerOperationMode::READ, 1, 'HYT00', false, new \PDOException('Query timeout expired'));
        $this->invoices->onQuery = static function () use ($timeout): void {
            throw $timeout;
        };

        // Primer intento (incr = 1): el envoltorio con epoch NO anula la regla de no reintentar SQL Server.
        $this->dispatch($this->makeWorker($this->redisForTerminalFailure(1)), $this->batchEvent());

        $this->assertCount(1, $this->publisher->deadLetters, 'HYT00 va directo a DLQ en el primer intento.');
        $this->assertSame('failed', $this->store->jobs[self::JOB]['status']);
        $this->assertSame(1, $this->store->failedJobs[0]['epoch']);
        $this->assertSame([], $this->store->locks);
        $this->assertSame(0, count($this->publisher->published), '0 auditorías parciales.');

        // Control: un fallo envuelto NO-SQL en el primer intento sí se reintenta (sin DLQ ni cierre del job).
        $this->buildWorld(FakeBatchInvoicesModel::rows(4));
        $this->createJob(4);
        $this->invoices->onQuery = static function (): void {
            throw new RuntimeException('falla transitoria');
        };
        $this->dispatch($this->makeWorker($this->redisForTerminalFailure(1)), $this->batchEvent());

        $this->assertSame([], $this->publisher->deadLetters);
        $this->assertSame(0, $this->store->countCalls('markJobFailed'));
        $this->assertSame('pending', $this->store->jobs[self::JOB]['status']);
    }

    /**
     * @return array<string,array{0:string,1:array<string,mixed>,2:bool}>
     */
    public static function jobStatesAtDeadLetter(): array
    {
        return [
            'cierre con epoch vigente invoca la cancelación' => ['vigente', [], true],
            'epoch obsoleto con job processing no la invoca' => ['obsoleto', ['generation_epoch' => 2, 'status' => 'processing'], false],
            'job failed por el reaper (stale_reason) no la invoca' => ['reaper', ['status' => 'failed', 'sealed' => true, 'stale_reason' => 'stale_no_progress'], false],
            'job failed por DLQ previa sin stale_reason la invoca (reentrega)' => ['reentrega', ['status' => 'failed', 'sealed' => true], true],
        ];
    }

    /**
     * @param array<string,mixed> $jobState Mutación del estado del job justo antes del fallo.
     */
    #[DataProvider('jobStatesAtDeadLetter')]
    public function testDeadLetterCancelsUndispatchedAuditsOnlyWhenJobWasClosed(string $label, array $jobState, bool $expectCancel): void
    {
        $this->createJob(4);
        $this->invoices->onQuery = function () use ($jobState): void {
            $this->store->jobs[self::JOB] = array_merge($this->store->jobs[self::JOB], $jobState);
            throw new RuntimeException('falla persistente');
        };

        $this->dispatch($this->makeWorker($this->redisForTerminalFailure()), $this->batchEvent());

        $this->assertSame($expectCancel ? [self::JOB] : [], $this->store->cancelledUndispatchedFor, $label);
        if ($expectCancel) {
            $this->assertSame([self::JOB, 'batch_dlq_unpublished'], $this->store->callsOf('cancelUndispatchedAudits')[0]);
        }
        $this->assertSame(0, $this->store->countCalls('getJob'), 'La lectura usa getJobSummary(), nunca getJob() (R4-F2-I3).');
        $this->assertCount(1, $this->publisher->deadLetters);
    }

    public function testDeadLetterFailureToCloseJobKeepsMessageForRecovery(): void
    {
        $this->createJob(4);
        $this->invoices->onQuery = static function (): void {
            throw new RuntimeException('falla persistente');
        };
        $this->store->interceptors['markJobFailed'] = static function (): void {
            throw new RuntimeException('Redis caído en DLQ');
        };
        $redis = $this->redisMock();
        $redis->method('incr')->willReturn(3);
        // Sin recibo DLQ no hay ACK terminal: el mensaje queda en PEL para recuperación.
        $redis->expects($this->never())->method('eval');

        $this->dispatch($this->makeWorker($redis), $this->batchEvent());

        $this->assertSame([], $this->publisher->deadLetters, 'No se publica la DLQ si no se pudo cerrar el job.');
        $this->assertSame('pending', $this->store->jobs[self::JOB]['status']);
    }

    // ── Configuración (DEC-12) ───────────────────────────────────────────

    /**
     * @return array<string,array{0:array<string,string>,1:bool}> [variables, debe lanzar]
     */
    public static function freezeWindowConfigurations(): array
    {
        return [
            'defaults (1236 < 1800)' => [[], false],
            'lock justo por encima de 4T+36' => [['AUDIT_BATCH_LOCK_TTL_SECONDS' => '1237'], false],
            'lock igual a 4T+36' => [['AUDIT_BATCH_LOCK_TTL_SECONDS' => '1236'], true],
            'lock menor que 4T+36' => [['AUDIT_BATCH_LOCK_TTL_SECONDS' => '1200'], true],
            'reclaim idle justo por encima' => [['AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS' => '1237000'], false],
            'reclaim idle igual a 4T+36' => [['AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS' => '1236000'], true],
            'timeout de consulta mayor' => [['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => '600'], true],
            'timeout menor con lock corto' => [['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => '100', 'AUDIT_BATCH_LOCK_TTL_SECONDS' => '437'], false],
        ];
    }

    /**
     * @param array<string,string> $vars
     */
    #[DataProvider('freezeWindowConfigurations')]
    public function testWorkerRefusesToStartWhenFreezeTimeoutExceedsLockWindow(array $vars, bool $mustThrow): void
    {
        $this->startLogCapture();
        $this->withEnv($vars, function () use ($mustThrow): void {
            try {
                $this->makeWorker();
                $this->assertFalse($mustThrow, 'El worker debía negarse a arrancar.');
            } catch (RuntimeException $error) {
                $this->assertTrue($mustThrow, 'El worker no debía negarse a arrancar: ' . $error->getMessage());
                $this->assertSame('batch_freeze_timeout_exceeds_lock_window', $error->getMessage());
            }
        });

        if ($mustThrow) {
            $logs = $this->logContextsContaining('configuración inválida');
            $this->assertCount(1, $logs);
            $this->assertSame('batch_freeze_timeout_exceeds_lock_window', $logs[0]['reason']);
        }
    }

    public function testFreezeTimeoutConfigEmptyStringUsesDefaults(): void
    {
        $this->withEnv([
            'AUDIT_BATCH_LOCK_TTL_SECONDS' => '',
            'AUDIT_JOB_STALE_AFTER_SECONDS' => '',
            'AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => '',
        ], function (): void {
            $this->assertSame(1800, AuditExecutionConfig::batchLockTtlSeconds());
            $this->assertSame(21600, AuditExecutionConfig::jobStaleAfterSeconds());
            $this->assertSame(300, AuditExecutionConfig::freezeQueryTimeoutSeconds());
            $this->makeWorker(); // arranca con los defaults
        });

        $this->withEnv(['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => 'abc'], function (): void {
            $this->expectException(\InvalidArgumentException::class);
            AuditExecutionConfig::freezeQueryTimeoutSeconds();
        });
    }

    // ── PII (R-PII) ──────────────────────────────────────────────────────

    public function testProcessingLogsMaskTheNitAndNeverContainTheRawValue(): void
    {
        $this->createJob(2);
        $this->startLogCapture();

        $this->makeWorker()->processEvent($this->batchEvent(['limit' => 2]));

        $processing = $this->logContextsContaining('procesando batch_requested');
        $this->assertCount(1, $processing);
        $this->assertSame('***624', $processing[0]['fac_nit_sec_masked']);
        $this->assertArrayNotHasKey('fac_nit_sec', $processing[0]);
        $this->assertStringNotContainsString('2624', $this->capturedContextsJson());
    }
}
