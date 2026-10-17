<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionInspector;
use App\Services\Audit\Pipeline\AuditExecutionMigration;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class AuditExecutionMigrationRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private AuditExecutionMigration $migration;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real aislado.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->migration = new AuditExecutionMigration($this->redis, new AuditExecutionConfig(2));
        foreach (['audit:execution:paused', 'audit:admission:paused', 'audit:execution:version'] as $key) {
            $this->redis->eval('return redis.call("SET",KEYS[1],"1")', [$key]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testDryRunDoesNotMutateAndApplyCanRepeatWithoutLosingIdentity(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $this->source($event);
        $terminal = AuditEvent::uuidV4();
        $terminalKey = AuditStateStore::auditKey($terminal);
        $terminalRaw = json_encode(['audit_id' => $terminal, 'status' => 'completed', 'documents' => [], 'job_id' => null]);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$terminalKey], [$terminalRaw]);
        $before = $this->keys();

        // Act:
        $dry = $this->migration->migrate('test-cutover', false);
        $afterDry = $this->keys();
        $first = $this->migration->migrate('test-cutover', true);
        $second = $this->migration->migrate('test-cutover', true);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $task = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'worker');

        // Assert:
        self::assertTrue($dry['dry_run']);
        self::assertSame($before, $afterDry);
        self::assertTrue($first['verified']);
        self::assertSame($first, $second);
        self::assertSame($event->eventId, $task->event()->eventId);
        self::assertSame($terminalRaw, $this->redis->get($terminalKey));
        self::assertSame(-1, $this->redis->eval('return redis.call("TTL",KEYS[1])', [AuditStateStore::auditKey($event->auditId)]));
        self::assertSame(0, $queue->metrics()['orchestration']['batch']['queued']);
    }

    public function testRollbackReplaysNormalizedOriginalOnceAndPreservesDocumentCounters(): void
    {
        // Arrange:
        $document = 'existing-document';
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_NORMALIZED, AuditEvent::uuidV4(), documentId: $document,
            payload: ['normalized_fields' => ['synthetic' => 'value'], 'source' => 'single']);
        $this->root($event, [$document => ['status' => 'normalized', 'normalized_fields' => ['synthetic' => 'value']]]);
        $this->source($event);
        $this->migration->migrate('rollback-test', true);
        $rootKey = AuditStateStore::auditKey($event->auditId);
        $this->redis->eval('local a=cjson.decode(redis.call("GET",KEYS[1])); a.documents[ARGV[1]].semantic_plan_id="waiting-plan"; a.documents[ARGV[1]].policy_state="waiting"; redis.call("SET",KEYS[1],cjson.encode(a)); return 1', [$rootKey], [$document]);
        $stream = AuditEventPublisher::streamForEvent($event);
        $before = $this->redis->eval('return redis.call("XLEN",KEYS[1])', [$stream]);

        // Act:
        $first = $this->migration->rollback('rollback-test', true);
        $second = $this->migration->rollback('rollback-test', true);
        $audit = json_decode($this->redis->get($rootKey), true);
        $last = $this->redis->eval('return redis.call("XREVRANGE",KEYS[1],"+","-","COUNT",1)', [$stream]);
        $replay = AuditEvent::fromArray(json_decode($last[0][1][1], true));

        // Assert:
        self::assertTrue($first['verified']);
        self::assertSame($first, $second);
        self::assertSame($before + 1, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [$stream]));
        self::assertSame($event->payload, $replay->payload);
        self::assertSame($event->documentId, $replay->documentId);
        self::assertSame(1, $audit['docs_total']);
        self::assertSame(0, $audit['docs_evaluated']);
        self::assertArrayNotHasKey('semantic_plan_id', $audit['documents'][$document]);
        self::assertSame('1', $this->redis->get('audit:execution:version'));
    }

    public function testCutoverRecoversAcknowledgedPolicyContinuationOnce(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_NORMALIZED, AuditEvent::uuidV4(), documentId: 'existing',
            payload: ['normalized_fields' => ['synthetic' => 'value']]);
        $this->root($event, ['existing' => ['status' => 'normalized']]);
        $stream = AuditEventPublisher::streamForEvent($event);
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); local id=redis.call("XADD",KEYS[1],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP",ARGV[1],"old","STREAMS",KEYS[1],">"); return redis.call("XACK",KEYS[1],ARGV[1],id)',
            [$stream], [AuditEventPublisher::GROUP_POLICY, $event->toJson()]);

        // Act:
        $first = $this->migration->migrate('acked-test', true);
        $second = $this->migration->migrate('acked-test', true);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $task = $queue->claim(AuditTaskStage::POLICY, AuditLane::BATCH, 'resumed');

        // Assert:
        self::assertTrue($first['verified']);
        self::assertSame($first, $second);
        self::assertSame($event->eventId, $task->event()->eventId);
        self::assertSame($event->payload, $task->event()->payload);
        self::assertSame(AuditTask::fromEvent(AuditTaskStage::POLICY, $event)->id(), $task->id());
        self::assertSame(2, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [$stream]));
    }

    public function testRollbackIncludesUnpublishedExtractionAndRepeatsWithoutLosingItsPayload(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, AuditEvent::uuidV4(), documentId: 'existing',
            payload: ['document_hash' => str_repeat('a', 64), 'source' => 'single']);
        $this->root($event, ['existing' => ['status' => 'registered']]);
        $this->source($event);
        $this->migration->migrate('outbox-rollback', true);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $task = $queue->claim(AuditTaskStage::EXTRACTION, AuditLane::PRIORITY, 'extractor');
        $effects = AuditTaskEffects::empty($task);
        $payload = ['extraction_result' => ['synthetic' => 'original-evidence']];
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'mark_extracted', 'existing', $payload);
        $extracted = AuditTaskEffects::followUp($task, AuditEvent::TYPE_DOCUMENT_EXTRACTED, $payload, 'existing', 'extracted');
        $effects['outbox'][] = AuditTaskEffects::event($task, $extracted, 'extracted');
        (new AuditTaskCommitter($this->redis))->commit($task->record, $effects);
        $before = $this->keys();
        $rootBefore = $this->redis->get(AuditStateStore::auditKey($event->auditId));
        $stream = AuditEventPublisher::streamForEvent($extracted);

        // Act:
        $dry = $this->migration->rollback('outbox-rollback', false);
        $afterDry = $this->keys();
        $rootAfterDry = $this->redis->get(AuditStateStore::auditKey($event->auditId));
        $pendingAfterDry = $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]);
        $first = $this->migration->rollback('outbox-rollback', true);
        $second = $this->migration->rollback('outbox-rollback', true);
        $entries = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+")', [$stream]);
        $replayed = AuditEvent::fromArray(json_decode(end($entries)[1][1], true));
        $audit = (new AuditStateStore($this->redis))->getAudit($event->auditId);

        // Assert:
        self::assertTrue($dry['dry_run']);
        self::assertSame(1, $dry['replays']);
        self::assertSame($before, $afterDry);
        self::assertSame($rootBefore, $rootAfterDry);
        self::assertSame(1, $pendingAfterDry);
        self::assertTrue($first['verified']);
        self::assertSame($first, $second);
        self::assertSame($extracted->payload, $replayed->payload);
        self::assertSame($extracted->documentId, $replayed->documentId);
        self::assertSame(1, $audit['docs_extracted']);
        self::assertSame(1, $audit['docs_total']);
        self::assertCount(3, $entries);
        self::assertSame(0, $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]));
    }

    public function testRollbackDoesNotFreezeAnInventoryWithMissingContinuation(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $this->source($event);
        $this->migration->migrate('invalid-rollback', true);
        $this->redis->eval('local a=cjson.decode(redis.call("GET",KEYS[1])); a.documents={existing={status="extracted"}}; a.docs_total=1; return redis.call("SET",KEYS[1],cjson.encode(a))',
            [AuditStateStore::auditKey($event->auditId)]);

        // Assert:
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_original_event_missing');

        // Act:
        try {
            $this->migration->rollback('invalid-rollback', true);
        } finally {
            $manifest = json_decode($this->redis->get('audit:migration:v2:invalid-rollback'), true);
            self::assertArrayNotHasKey('rollback_inventory', $manifest);
            self::assertSame('1', $this->redis->get('audit:execution:paused'));
        }
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
            $this->redis->eval('redis.call("HSET",KEYS[1],"record",ARGV[1],"state","pending"); return redis.call("ZADD",KEYS[2],0,ARGV[2])',
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

    public function testCutoverReplaysAcknowledgedNormalizationWithoutEvaluatingIt(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_EXTRACTED, AuditEvent::uuidV4(), documentId: 'existing',
            payload: ['extracted_fields' => ['synthetic' => 'value']]);
        $this->root($event, ['existing' => ['status' => 'extracted']]);
        $stream = AuditEventPublisher::streamForEvent($event);
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); local id=redis.call("XADD",KEYS[1],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP",ARGV[1],"old","STREAMS",KEYS[1],">"); return redis.call("XACK",KEYS[1],ARGV[1],id)',
            [$stream], [AuditEventPublisher::GROUP_NORMALIZERS, $event->toJson()]);

        // Act:
        $this->migration->migrate('acked-normalization', true);
        $this->migration->migrate('acked-normalization', true);
        $entries = $this->redis->eval('return redis.call("XREADGROUP","GROUP",ARGV[1],"new","STREAMS",KEYS[1],">")', [$stream], [AuditEventPublisher::GROUP_NORMALIZERS]);
        $replayed = AuditEvent::fromArray(json_decode($entries[0][1][0][1][1], true));
        $audit = (new AuditStateStore($this->redis))->getAudit($event->auditId);

        // Assert:
        self::assertSame($event->eventId, $replayed->eventId);
        self::assertSame($event->payload, $replayed->payload);
        self::assertSame('extracted', $audit['documents']['existing']['status']);
        self::assertSame(0, $audit['docs_evaluated']);
        self::assertSame(2, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [$stream]));
    }

    public function testReconciliationRecoversDeletedSchedulerIndexesFromDurableRecords(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $this->source($event);
        $this->migration->migrate('repair-test', true);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
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

    public function testMigrationRefusesOrphanBeforeMutatingMarker(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);

        // Assert:
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('migration_original_event_missing');

        // Act:
        $this->migration->migrate('missing-source', true);
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

    public function testReconciliationRecoversReceiptWrittenBeforeTaskCommitIndex(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $this->source($event);
        $this->migration->migrate('receipt-test', true);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $task = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'crashed');
        $effects = AuditTaskEffects::empty($task);
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'patch_audit_context', null, ['recovered' => true]);
        $receipt = ['state' => 'committing', 'task' => $task->record, 'effects' => $effects];
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])',
            ['audit:commit:v2:' . $task->id() . ':' . $task->record['fence']], [json_encode($receipt)]);

        // Act:
        (new AuditExecutionInspector($this->redis))->reconcile();
        (new AuditExecutionInspector($this->redis))->reconcile();

        // Assert:
        self::assertTrue((new AuditStateStore($this->redis))->getAudit($event->auditId)['recovered']);
        self::assertSame('completed', $queue->get($task->id())->record['state']);
        self::assertSame(0, $this->redis->eval('return redis.call("SCARD",KEYS[1])', [AuditTaskCommitter::PENDING]));
        self::assertSame(0, $queue->metrics()['orchestration']['batch']['active']);
    }

    public function testCutoverInventoryAndReconciliationPreserveSqlQueueSlots(): void
    {
        // Arrange:
        $jobId = AuditEvent::uuidV4();
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4(), $jobId);
        $this->root($event, []);
        $this->source($event);
        $job = ['job_id' => $jobId, 'status' => 'processing', 'total' => 1, 'done' => 0, 'failed' => 0,
            'audits' => [$event->auditId => ['status' => 'pending']]];
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', ['job:' . $jobId . ':state'], [json_encode($job)]);
        $persistence = new AuditPersistenceQueue($this->redis, 2);
        $persistence->enqueue($event->followUp(AuditEvent::TYPE_RULES_EVALUATED, ['synthetic' => true], null));
        $before = $persistence->inventory();

        // Act:
        $dry = $this->migration->migrate('slot-test', false);
        $afterDry = $persistence->inventory();
        $result = $this->migration->migrate('slot-test', true);

        // Assert:
        self::assertTrue($dry['dry_run']);
        self::assertTrue($result['verified']);
        self::assertNotEmpty($before['slots']);
        self::assertSame($before, $afterDry);
        self::assertSame($before, $persistence->inventory());
    }

    public function testAcceptedManifestCannotBeCollectedWhileItsAuditIsActive(): void
    {
        // Arrange:
        $previous = [];
        foreach (['AUDIT_STATE_TTL', 'AUDIT_JOB_TTL', 'AUDIT_PERSISTENCE_QUEUE_TTL'] as $key) {
            $previous[$key] = \Core\Env::get($key, null);
            \Core\Env::set($key, '1');
        }
        try {
            $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
            $this->root($event, []);
            $this->source($event);
            $this->migration->migrate('gc-test', true);
            $this->migration->accept('gc-test');
            $manifestKey = 'audit:migration:v2:gc-test';
            $this->redis->eval('local m=cjson.decode(redis.call("GET",KEYS[1])); m.accepted_at_ms=0; return redis.call("SET",KEYS[1],cjson.encode(m))', [$manifestKey]);
            $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));

            // Act:
            $maintenance->gc();
            $whileActive = $this->redis->get($manifestKey);
            $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
            $task = $queue->claim(AuditTaskStage::ORCHESTRATION, AuditLane::BATCH, 'finish');
            (new AuditTaskCommitter($this->redis))->commit($task->record, AuditTaskEffects::empty($task));
            $this->redis->eval('local a=cjson.decode(redis.call("GET",KEYS[1])); a.status="completed"; a.completed_at="2000-01-01T00:00:00Z"; return redis.call("SET",KEYS[1],cjson.encode(a))', [AuditStateStore::auditKey($event->auditId)]);
            $maintenance->gc();

            // Assert:
            self::assertNotNull($whileActive);
            self::assertNull($this->redis->get($manifestKey));
            self::assertSame(0, $this->redis->eval('return redis.call("SCARD",KEYS[1])', ['audit:migrations:v2:all']));
        } finally {
            foreach ($previous as $key => $value) {
                \Core\Env::set($key, $value);
            }
        }
    }

    private function root(AuditEvent $event, array $documents): void
    {
        $state = ['audit_id' => $event->auditId, 'job_id' => $event->jobId, 'status' => $documents === [] ? 'pending' : 'processing',
            'execution_version' => 1, 'docs_total' => count($documents), 'docs_evaluated' => 0, 'docs_done' => count($documents),
            'documents' => $documents, 'created_at' => '2026-10-08T00:00:00Z'];
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1],"EX",60)', [AuditStateStore::auditKey($event->auditId)], [json_encode($state)]);
    }

    private function source(AuditEvent $event): void
    {
        $this->redis->eval('return redis.call("XADD",KEYS[1],"*","event",ARGV[1])', [AuditEventPublisher::streamForEvent($event)], [$event->toJson()]);
    }

    private function keys(): array
    {
        $out = [];
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scanKeys('*', $cursor);
            $out = array_merge($out, $keys);
        } while ($cursor !== '0');
        sort($out, SORT_STRING);
        return $out;
    }
}
