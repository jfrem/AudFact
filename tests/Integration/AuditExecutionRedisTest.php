<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditAdmissionGuard;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditTaskRunner;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use App\Services\Audit\Pipeline\SemanticFlightStore;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedAuditRedis;

final class AuditExecutionRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private FairAuditTaskQueue $queue;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $this->redis->eval('return redis.call("SET",KEYS[1],"2")', ['audit:execution:version']);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testAdmissionAcknowledgesOnlyAfterDurableTaskAndIsIdempotent(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        [$stream, $id] = $this->message($event);

        // Act:
        $first = $this->queue->admit(AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event), $stream, 'test', $id);
        $second = $this->queue->admit(AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event), $stream, 'test', $id);
        $lease = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker');

        // Assert:
        self::assertTrue($first);
        self::assertTrue($second);
        self::assertSame($event->eventId, $lease->event()->eventId);
        self::assertSame(1, $lease->record['fence']);
        self::assertSame(0, $this->redis->eval('return redis.call("XPENDING",KEYS[1],"test")[1]', [$stream]));
        self::assertSame(1, $this->queue->metrics()['orchestration']['batch']['active']);
    }

    public function testOrchestrationDrainsAdmittedWorkWhenItsOwnQueueClosesAdmission(): void
    {
        // Arrange:
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2, 100));
        for ($i = 0; $i < 80; $i++) {
            $event = $this->event(AuditTaskStage::ORCHESTRATION);
            [$stream, $id] = $this->message($event);
            $queue->admit(AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event), $stream, 'test', $id);
        }
        $worker = new class($this->redis) extends AuditEventConsumer {
            public int $prepared = 0;
            protected function streams(): array { return [AuditEventPublisher::STREAM_INBOX_BATCH]; }
            protected function group(): string { return AuditEventPublisher::GROUP_ORCHESTRATOR; }
            protected function consumer(): string { return 'capacity-test'; }
            protected function eventTypes(): array { return [AuditEvent::TYPE_AUDIT_CREATED]; }
            protected function handle(AuditEvent $event): void { throw new \LogicException('scheduled_only'); }
            public function taskStage(): ?AuditTaskStage { return AuditTaskStage::ORCHESTRATION; }
            public function prepareTask(AuditTask $task): array
            {
                $this->prepared++;
                return AuditTaskEffects::empty($task);
            }
        };
        $config = new AuditExecutionConfig(2, 100);
        $guard = new AuditAdmissionGuard($this->redis, $config);
        $runner = new AuditTaskRunner($this->redis, $config);

        // Act:
        $admissionBlocked = $guard->atCapacity();
        $processed = $runner->tick($worker, AuditLane::BATCH, 'capacity-test');

        // Assert:
        self::assertTrue($admissionBlocked);
        self::assertTrue($processed);
        self::assertSame(1, $worker->prepared);
        self::assertSame(79, $queue->metrics()['orchestration']['batch']['queued']);
        self::assertFalse($guard->atCapacity());
    }

    public function testJobsAlternateWithOneActiveTurnPerScope(): void
    {
        // Arrange:
        $jobA = AuditEvent::uuidV4();
        $jobB = AuditEvent::uuidV4();
        foreach ([$jobA, $jobA, $jobB, $jobB] as $job) {
            $this->admit($this->event(AuditTaskStage::ORCHESTRATION, $job));
        }

        // Act:
        $first = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'first');
        $second = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'second');
        $third = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'third');

        // Assert:
        self::assertSame($jobA, $first->record['job_id']);
        self::assertSame($jobB, $second->record['job_id']);
        self::assertNull($third);
        self::assertSame(2, $this->queue->metrics()['orchestration']['batch']['queued']);
    }

    public function testJobSlotsAllowConcurrentTurnsUpToTheCapAndFreeThemOnCommit(): void
    {
        // Arrange:
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2, 20000, ['orchestration' => 3]));
        $jobA = AuditEvent::uuidV4();
        $jobB = AuditEvent::uuidV4();
        foreach ([$jobA, $jobA, $jobA, $jobA, $jobA, $jobB] as $job) {
            $this->admit($this->event(AuditTaskStage::ORCHESTRATION, $job));
        }

        // Act:
        $grants = [];
        for ($i = 0; $i < 5; $i++) {
            $grants[] = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker-' . $i);
        }
        $activeAtCap = $queue->metrics()['orchestration']['batch']['active'];
        (new AuditTaskCommitter($this->redis))->commit($grants[0]->record, AuditTaskEffects::empty($grants[0]));
        $next = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker-5');
        $blocked = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker-6');

        // Assert:
        self::assertSame([$jobA, $jobB, $jobA, $jobA], array_map(
            static fn(AuditTask $task): string => $task->record['job_id'],
            array_slice($grants, 0, 4)
        ));
        self::assertNull($grants[4], 'El job A ya ocupa sus 3 turnos y B no tiene más tareas.');
        self::assertSame(4, $activeAtCap);
        self::assertSame($jobA, $next?->record['job_id']);
        self::assertNull($blocked);
    }

    public function testJobSlotsCountLegacyActiveEntryAsOneTurnAndReleaseIt(): void
    {
        // Arrange:
        $job = AuditEvent::uuidV4();
        $this->admit($this->event(AuditTaskStage::ORCHESTRATION, $job));
        $this->admit($this->event(AuditTaskStage::ORCHESTRATION, $job));
        $legacy = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'legacy');
        $active = FairAuditTaskQueue::base('orchestration', 'batch') . ':active';
        $scope = $legacy->record['scope'];
        $this->redis->eval('return redis.call("HSET",KEYS[1],ARGV[1],ARGV[2])', [$active], [$scope, $legacy->id()]);
        $widened = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2, 20000, ['orchestration' => 2]));

        // Act:
        $second = $widened->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'current');
        $held = $this->redis->eval('return redis.call("HGET",KEYS[1],ARGV[1])', [$active], [$scope]);
        $committer = new AuditTaskCommitter($this->redis);
        $committer->commit($legacy->record, AuditTaskEffects::empty($legacy));
        $afterLegacy = $this->redis->eval('return redis.call("HGET",KEYS[1],ARGV[1])', [$active], [$scope]);
        $committer->commit($second->record, AuditTaskEffects::empty($second));
        $afterAll = $this->redis->eval('return redis.call("HEXISTS",KEYS[1],ARGV[1])', [$active], [$scope]);

        // Assert:
        self::assertSame($job, $second->record['job_id']);
        self::assertSame('2', $held);
        self::assertSame('1', $afterLegacy);
        self::assertSame(0, $afterAll);
    }

    public function testCommitIsIdempotentAndStaleFenceCannotMutateAudit(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        $this->admit($event);
        $first = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'first');
        $this->expireLease($first);
        $second = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'second');
        $effects = AuditTaskEffects::empty($second);
        $effects['state_transitions'][] = AuditTaskEffects::transition($second, 'patch_audit_context', null, ['test_result' => 'second']);
        $committer = new AuditTaskCommitter($this->redis);

        // Act:
        $stale = $committer->commit($first->record, AuditTaskEffects::empty($first));
        $committed = $committer->commit($second->record, $effects);
        $replayed = $committer->commit($second->record, $effects);

        // Assert:
        self::assertSame('STALE', $stale);
        self::assertSame(2, $second->record['fence']);
        self::assertSame('COMMITTED', $committed);
        self::assertSame('ALREADY_COMMITTED', $replayed);
        self::assertSame('second', $this->audit($event)['test_result']);
        self::assertSame(0, $this->queue->metrics()['orchestration']['batch']['active']);
    }

    public function testInvalidTransitionFailsBeforeReceiptOrBusinessMutation(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        $this->admit($event);
        $task = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker');
        $effects = AuditTaskEffects::empty($task);
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'patch_audit_context', null, ['must_not_exist' => true]);
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'mark_evaluated', 'missing', []);

        // Act:
        try {
            (new AuditTaskCommitter($this->redis))->commit($task->record, $effects);
            self::fail('La transición inválida debe rechazarse.');
        } catch (\Exception $error) {
            // Assert:
            self::assertStringContainsString('document_state_missing', $error->getMessage());
            self::assertArrayNotHasKey('must_not_exist', $this->audit($event));
            self::assertSame('active', $this->queue->get($task->id())->record['state']);
            self::assertSame(0, $this->redis->eval('return redis.call("SCARD",KEYS[1])', [AuditTaskCommitter::PENDING]));
        }
    }

    public function testRevisionConflictRepreparesWithoutCompletingTask(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        $this->admit($event);
        $task = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker');
        $effects = AuditTaskEffects::empty($task);
        $effects['evaluation_base_revision'] = 1;

        // Act:
        $result = (new AuditTaskCommitter($this->redis))->commit($task->record, $effects);

        // Assert:
        self::assertSame('RETRY_PREPARE', $result);
        self::assertSame('active', $this->queue->get($task->id())->record['state']);
    }

    public function testDelayedTaskReleasesScopeAndPreservesRemoteArtifact(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        $this->admit($event);
        $task = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'first');
        $saved = $this->queue->saveArtifact($task, ['valid_remote_result' => ['test' => true]]);

        // Act:
        $deferred = $this->queue->defer($task, 0, 'fixture', true);
        $recovered = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'second');

        // Assert:
        self::assertTrue($saved);
        self::assertTrue($deferred);
        self::assertSame(2, $recovered->record['fence']);
        self::assertSame(1, $recovered->record['operation_attempt']);
        self::assertSame(['valid_remote_result' => ['test' => true]], $recovered->record['stage_artifact']);
        self::assertFalse($this->queue->saveArtifact($task, []));
    }

    public function testQuotaCoversBothOperationsAndReportedOverrunIsCharged(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::EXTRACTION);
        $this->admit($event);
        $task = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'worker');
        $budget = $this->budget(2, 100);
        $request = ['task' => $task->record, 'operation' => 'count_tokens', 'tokens' => 0, 'body_hash' => hash('sha256', 'body')];

        // Act:
        $count = $budget->reserve($request);
        $budget->finish($count['attempt_id'], []);
        $generation = $budget->reserve(array_replace($request, ['operation' => 'generate_content', 'tokens' => 80]));
        $budget->finish($generation['attempt_id'], ['promptTokenCount' => 90, 'totalTokenCount' => 120]);
        $budget->finish($generation['attempt_id'], ['promptTokenCount' => 90, 'totalTokenCount' => 120]);
        $denied = $budget->reserve($request);

        // Assert:
        self::assertSame('GRANTED', $count['status']);
        self::assertSame('GRANTED', $generation['status']);
        self::assertSame('DEFERRED', $denied['status']);
        self::assertSame(2, $budget->metrics()['rpm_used']);
        self::assertSame(2, $budget->metrics()['rpd_used']);
        self::assertSame(90, $budget->metrics()['tpm_reserved']);
        self::assertSame(0, $budget->metrics()['inflight']);
    }

    public function testDeferredOldDemandDoesNotBlockAnotherActiveTask(): void
    {
        // Arrange:
        $budget = $this->budget(100, 1000, 1);
        foreach ([AuditTaskStage::EXTRACTION, AuditTaskStage::EXTRACTION, AuditTaskStage::EXTRACTION] as $stage) {
            $this->admit($this->event($stage));
        }
        $first = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'first');
        $second = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'second');
        $request = ['operation' => 'count_tokens', 'tokens' => 0, 'body_hash' => hash('sha256', 'body')];
        $granted = $budget->reserve($request + ['task' => $first->record]);
        $waiting = $budget->reserve($request + ['task' => $second->record]);
        $this->queue->defer($second, $waiting['due_at_ms'], 'quota');
        $budget->finish($granted['attempt_id'], []);
        $third = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'third');

        // Act:
        $result = $budget->reserve($request + ['task' => $third->record]);

        // Assert:
        self::assertSame('DEFERRED', $waiting['status']);
        self::assertSame('GRANTED', $result['status']);
    }

    private function event(AuditTaskStage $stage, ?string $job = null): AuditEvent
    {
        $auditId = AuditEvent::uuidV4();
        $payload = ['dis_id' => 'fixture', 'dis_det_nro' => 'fixture'];
        if ($stage === AuditTaskStage::SEMANTIC) {
            $payload += ['plan_id' => hash('sha256', $auditId), 'generation' => 1, 'wave' => 0,
                'purpose' => 'person_name_match', 'pair_ids' => [hash('sha256', 'pair')]];
        }
        return AuditEvent::create($stage->eventTypes()[0], $auditId, $job,
            $stage === AuditTaskStage::ORCHESTRATION ? null : 'document', $payload);
    }

    private function message(AuditEvent $event): array
    {
        $stream = AuditEventPublisher::streamForEvent($event);
        $audit = ['audit_id' => $event->auditId, 'status' => 'processing', 'documents' => new \stdClass(), 'docs_total' => 0];
        $id = $this->redis->eval('redis.call("SET",KEYS[1],ARGV[1]); redis.pcall("XGROUP","CREATE",KEYS[2],"test","0","MKSTREAM"); local id=redis.call("XADD",KEYS[2],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP","test","intake","COUNT",1,"STREAMS",KEYS[2],">"); return id',
            [AuditStateStore::auditKey((string) $event->auditId), $stream], [json_encode($audit, JSON_THROW_ON_ERROR), $event->toJson()]);
        return [$stream, $id];
    }

    private function admit(AuditEvent $event): void
    {
        [$stream, $id] = $this->message($event);
        $stage = match ($event->eventType) {
            AuditEvent::TYPE_AUDIT_CREATED => AuditTaskStage::ORCHESTRATION,
            AuditEvent::TYPE_DOCUMENT_NORMALIZED => AuditTaskStage::POLICY,
            AuditEvent::TYPE_SEMANTIC_REQUESTED => AuditTaskStage::SEMANTIC,
            default => AuditTaskStage::EXTRACTION,
        };
        self::assertTrue($this->queue->admit(AuditTask::fromEvent($stage, $event), $stream, 'test', $id));
    }

    #[DataProvider('scheduledStages')]
    public function testHighVolumeJobsAlternateAndLateJobEntersWithinOneRound(AuditTaskStage $stage): void
    {
        // Arrange:
        $jobs = [AuditEvent::uuidV4(), AuditEvent::uuidV4(), AuditEvent::uuidV4()];
        foreach ([3000, 3000, 69] as $index => $size) {
            for ($i = 0; $i < $size; $i++) {
                $this->admit($this->event($stage, $jobs[$index]));
            }
        }
        $committer = new AuditTaskCommitter($this->redis);
        $grants = [];

        // Act:
        for ($i = 0; $i < 90; $i++) {
            $task = $this->queue->claim($stage, AuditLane::BATCH, 'load-worker');
            $grants[] = $task->record['job_id'];
            $committer->commit($task->record, AuditTaskEffects::empty($task));
        }
        $late = AuditEvent::uuidV4();
        $this->admit($this->event($stage, $late));
        $next = [];
        for ($i = 0; $i < 4; $i++) {
            $task = $this->queue->claim($stage, AuditLane::BATCH, 'load-worker');
            $next[] = $task->record['job_id'];
            $committer->commit($task->record, AuditTaskEffects::empty($task));
        }

        // Assert:
        self::assertSame(array_merge(...array_fill(0, 30, $jobs)), $grants);
        self::assertSame([...$jobs, $late], $next);
        self::assertSame(0, $this->queue->metrics()[$stage->value]['batch']['active']);
        self::assertSame(6069 + 1 - 94, $this->queue->metrics()[$stage->value]['batch']['queued']);
    }

    public static function scheduledStages(): iterable
    {
        foreach (AuditTaskStage::cases() as $stage) {
            yield $stage->value => [$stage];
        }
    }

    public function testInterruptedCommitRepairsFromReceiptWithoutRepeatingEffects(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::ORCHESTRATION);
        $this->admit($event);
        $task = $this->queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'crashed');
        $effects = AuditTaskEffects::empty($task);
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'patch_audit_context', null, ['repaired' => true]);
        $receipt = ['state' => 'committing', 'task' => $task->record, 'effects' => $effects];
        $reference = $task->id() . ':' . $task->record['fence'];
        $this->redis->eval('local r=cjson.decode(redis.call("HGET",KEYS[1],"record")); r.state="committing"; redis.call("HSET",KEYS[1],"record",cjson.encode(r)); redis.call("SET",KEYS[2],ARGV[1]); redis.call("SADD",KEYS[3],ARGV[2]); redis.call("HINCRBY",KEYS[4],"orchestration:batch:active",-1); return redis.call("HINCRBY",KEYS[4],"orchestration:batch:committing",1)',
            [FairAuditTaskQueue::taskKey($task->id()), 'audit:commit:v2:' . $reference, AuditTaskCommitter::PENDING, FairAuditTaskQueue::COUNTS],
            [json_encode($receipt), $reference]);

        // Act:
        $committer = new AuditTaskCommitter($this->redis);
        $first = $committer->repair();
        $second = $committer->repair();

        // Assert:
        self::assertSame(1, $first);
        self::assertSame(0, $second);
        self::assertTrue($this->audit($event)['repaired']);
        self::assertSame(1, $this->audit($event)['evaluation_revision']);
        self::assertSame('completed', $this->queue->get($task->id())->record['state']);
        self::assertSame(0, $this->queue->metrics()['orchestration']['batch']['active']);
    }

    public function testSingleFlightCanRecoverExpiredOwnerAndRejectsItsCacheWrite(): void
    {
        // Arrange:
        $event = $this->event(AuditTaskStage::SEMANTIC);
        $this->admit($event);
        $old = $this->queue->claim(AuditTaskStage::SEMANTIC, AuditLane::BATCH, 'old');
        $flights = new SemanticFlightStore($this->redis);
        $key = 'semantic:match:v6:fixture:person_name_match:fixture';
        self::assertTrue($flights->claim($old, $key));
        $this->expireLease($old);
        $current = $this->queue->claim(AuditTaskStage::SEMANTIC, AuditLane::BATCH, 'current');

        // Act:
        $claimed = $flights->claim($current, $key);
        try {
            $flights->write($old, $key, ['is_match' => true, 'reasoning' => 'Stale fixture']);
            self::fail('Un fence anterior no puede escribir caché.');
        } catch (\RuntimeException $error) {
            self::assertSame('semantic_cache_writer_stale', $error->getMessage());
        }
        $flights->write($current, $key, ['is_match' => false, 'reasoning' => 'Current fixture', 'source' => 'remote']);
        $flights->release($old, $key);

        // Assert:
        self::assertTrue($claimed);
        self::assertSame(['is_match' => false, 'reasoning' => 'Current fixture', 'source' => 'cache'], $flights->read($key));
    }

    private function expireLease(AuditTask $task): void
    {
        $this->redis->eval('local r=cjson.decode(redis.call("HGET",KEYS[1],"record")); r.lease_until_ms=0; redis.call("HSET",KEYS[1],"record",cjson.encode(r)); return redis.call("ZADD",KEYS[2],0,r.task_id)',
            [FairAuditTaskQueue::taskKey($task->id()), FairAuditTaskQueue::base($task->record['stage'], $task->record['lane']) . ':leases']);
    }

    private function audit(AuditEvent $event): array
    {
        return json_decode($this->redis->get(AuditStateStore::auditKey((string) $event->auditId)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function budget(int $rpm, int $tpm, int $inflight = 4): GeminiQuotaBudget
    {
        return new GeminiQuotaBudget($this->redis, ['scope' => 'integration', 'model' => 'fixture', 'rpm' => $rpm,
            'tpm' => $tpm, 'rpd' => 100, 'inflight' => $inflight]);
    }
}
