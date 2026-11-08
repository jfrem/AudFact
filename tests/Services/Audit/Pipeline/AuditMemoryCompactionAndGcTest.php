<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskOutbox;
use App\Services\Audit\Pipeline\AuditTaskStage;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class AuditMemoryCompactionAndGcTest extends TestCase
{
    public function testCommitExecutesAtomicLuaWithoutCommitReceiptsOrOutboxStateField(): void
    {
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $task = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event);
        $lease = $task->record;
        $lease['state'] = 'active';
        $lease['fence'] = 1;
        $lease['lease_owner'] = 'worker-1';
        $lease['lease_until_ms'] = 2000000;

        $capturedScript = null;
        $capturedKeys = null;
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::never())->method('get');
        $redis->expects(self::once())
            ->method('eval')
            ->willReturnCallback(static function (string $script, array $keys) use (&$capturedScript, &$capturedKeys): string {
                $capturedScript = $script;
                $capturedKeys = $keys;
                return 'COMMITTED';
            });

        $committer = new AuditTaskCommitter($redis);
        $result = $committer->commit($lease, AuditTaskEffects::empty(new AuditTask($lease)));

        self::assertSame('COMMITTED', $result);
        self::assertNotNull($capturedScript);
        self::assertNotNull($capturedKeys);
        self::assertSame(AuditTaskCommitter::OUTBOX, $capturedKeys[12]);
        self::assertStringContainsString("if current.state=='completed' and current.fence==task.fence then return 'ALREADY_COMMITTED' end", $capturedScript);
        self::assertStringContainsString("if plan.state=='consumed' then", $capturedScript);
        self::assertStringContainsString("current.stage_artifact=cjson.null", $capturedScript);
        self::assertStringContainsString("current.original_event.payload=cjson.decode('{}')", $capturedScript);
        self::assertStringContainsString("document.extraction_contract=nil; document.fields_config=nil; document.system_prompt=nil", $capturedScript);
        self::assertStringNotContainsString('audit:commit:v2:', $capturedScript);
        self::assertStringNotContainsString("'committing'", $capturedScript);
        self::assertStringNotContainsString("'sent'", $capturedScript);
    }

    public function testOutboxFlushDeletesOutboxRecordImmediatelyInPublishLua(): void
    {
        $auditId = AuditEvent::uuidV4();
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, $auditId);
        $task = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event);
        $followUp = AuditTaskEffects::followUp(
            $task,
            AuditEvent::TYPE_DOCUMENT_REGISTERED,
            ['dis_id' => '1', 'dis_det_nro' => '2', 'heavy' => str_repeat('X', 4096)],
            'doc-1',
            'doc-1'
        );
        $effect = AuditTaskEffects::event($task, $followUp, 'doc-1');
        $effectId = $effect['effect_id'];

        $publishKeys = null;
        $publishArgs = null;
        $publishScript = null;

        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')
            ->willReturnCallback(static function (string $script, array $keys = [], array $argv = []) use ($effect, $effectId, &$publishKeys, &$publishArgs, &$publishScript): mixed {
                if (str_contains($script, 'ZRANGE')) {
                    return [$effectId];
                }
                if (str_contains($script, 'HGET') && count($keys) === 1) {
                    return json_encode($effect, JSON_THROW_ON_ERROR);
                }
                if (str_contains($script, 'XADD')) {
                    $publishScript = $script;
                    $publishKeys = $keys;
                    $publishArgs = $argv;
                    return '1700000000000-0';
                }
                return 1;
            });

        $outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
        $published = $outbox->flush();

        self::assertSame(1, $published);
        self::assertNotNull($publishKeys);
        self::assertCount(4, $publishKeys);
        self::assertSame('audit:outbox:v2:' . $effectId, $publishKeys[0]);
        self::assertNotNull($publishArgs);
        self::assertSame($effectId, $publishArgs[1]);
        self::assertNotNull($publishScript);
        self::assertStringContainsString("redis.call('DEL',KEYS[1])", $publishScript);
    }

    public function testGcImmediatelyDeletesTerminalAuditTasksAndSemanticPlansInSingleStep(): void
    {
        $auditId = AuditEvent::uuidV4();
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, $auditId);
        $task = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event);
        $taskRecord = $task->record;
        $taskRecord['state'] = 'completed';
        $taskRecord['fence'] = 1;
        $taskRecord['completed_at_ms'] = 1700000000000;
        $taskKey = 'audit:task:v2:' . $task->id();

        $planId = str_repeat('b', 64);
        $planKey = 'audit:semantic:v2:' . $auditId . ':doc-1:' . $planId;
        $planRecord = [
            'audit_id' => $auditId,
            'document_id' => 'doc-1',
            'plan_id' => $planId,
            'state' => 'waiting',
            'revision' => 2,
        ];

        $deletedTask = false;
        $deletedPlan = false;
        $getAuditCalls = 0;

        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')
            ->willReturnCallback(static function (string $key) use ($auditId, &$getAuditCalls): ?string {
                if ($key === AuditStateStore::auditKey($auditId)) {
                    $getAuditCalls++;
                    return json_encode(['audit_id' => $auditId, 'status' => 'failed'], JSON_THROW_ON_ERROR);
                }
                return null;
            });
        $redis->method('scanKeys')
            ->willReturnCallback(static function (string $pattern) use ($taskKey, $planKey): array {
                if ($pattern === 'audit:task:v2:*') {
                    return ['0', [$taskKey]];
                }
                if ($pattern === 'audit:semantic:v2:*') {
                    return ['0', [$planKey]];
                }
                return ['0', []];
            });

        $redis->method('eval')
            ->willReturnCallback(static function (string $script, array $keys = []) use ($taskKey, $taskRecord, $planKey, $planRecord, &$deletedTask, &$deletedPlan): mixed {
                if ($keys === []) {
                    return 1700000000000;
                }
                if (($keys[0] ?? '') === $taskKey && str_contains($script, 'return redis.call("HGET"')) {
                    return json_encode($taskRecord, JSON_THROW_ON_ERROR);
                }
                if (($keys[0] ?? '') === $taskKey && str_contains($script, 'DEL')) {
                    self::assertStringNotContainsString("SCARD', KEYS[4]", $script);
                    self::assertStringContainsString("redis.call('SREM', KEYS[4], 'task:' .. r.task_id)", $script);
                    $deletedTask = true;
                    return 1;
                }
                if (($keys[0] ?? '') === $planKey && str_contains($script, 'return redis.call("HGET"')) {
                    return json_encode($planRecord, JSON_THROW_ON_ERROR);
                }
                if (($keys[0] ?? '') === $planKey && str_contains($script, 'DEL')) {
                    $deletedPlan = true;
                    return 1;
                }
                return 0;
            });

        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2));
        $removed = $maintenance->gc();

        self::assertTrue($deletedTask, 'gc() debe eliminar inmediatamente la tarea completada cuya auditoría raíz ya es terminal.');
        self::assertTrue($deletedPlan, 'gc() debe eliminar en un solo paso el plan semántico de una auditoría terminal.');
        self::assertSame(1, $getAuditCalls, 'gc() debe cachear la lectura de audit:{id}:state por ciclo.');
        self::assertSame(2, $removed);
    }

    public function testCompleteAuditAndPersistenceAdvanceCompactTerminalFootprint(): void
    {
        $auditId = AuditEvent::uuidV4();
        $capturedStateScript = null;
        $capturedAdvanceScript = null;

        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')
            ->willReturnCallback(static function (string $script, array $keys = []) use ($auditId, &$capturedStateScript, &$capturedAdvanceScript): int {
                if (($keys[0] ?? '') === AuditStateStore::auditKey($auditId)) {
                    $capturedStateScript = $script;
                }
                if (str_contains($script, 'ZPOPMIN')) {
                    $capturedAdvanceScript = $script;
                }
                return 1;
            });

        $store = new AuditStateStore($redis);
        self::assertTrue($store->completeAudit($auditId, ['status' => 'completed']));
        self::assertNotNull($capturedStateScript);
        self::assertStringContainsString("audit['documents'] = nil", $capturedStateScript);
        self::assertStringContainsString("audit['rules_evaluated_result'] = nil", $capturedStateScript);
        self::assertStringContainsString("audit['audit_result'] = nil", $capturedStateScript);

        $queue = new AuditPersistenceQueue($redis);
        $event = AuditEvent::create(AuditEvent::TYPE_RULES_EVALUATED, $auditId);
        self::assertTrue($queue->advance($event));
        self::assertNotNull($capturedAdvanceScript);
        self::assertStringContainsString("if tonumber(ARGV[5]) == 0 then", $capturedAdvanceScript);
        self::assertStringContainsString("redis.call('DEL', KEYS[4], KEYS[6])", $capturedAdvanceScript);
    }
}
