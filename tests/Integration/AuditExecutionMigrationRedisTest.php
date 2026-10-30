<?php

declare(strict_types=1);

namespace Tests\Integration;

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
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

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

    public function testReconciliationRecoversReceiptWrittenBeforeTaskCommitIndex(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $this->root($event, []);
        $queue = $this->admitOrchestrationTask($event);
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
        $this->redis->eval('redis.call("ZADD",KEYS[2],1000,ARGV[2]); return redis.call("SET",KEYS[1],ARGV[1])', [
            'job:' . $oldJobId . ':state',
            'jobs:index',
        ], [
            json_encode([
                'job_id' => $oldJobId,
                'status' => 'completed',
                'completed_at' => '2020-01-01T00:00:00Z',
                'audits' => [],
            ]),
            $oldJobId,
        ]);
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
