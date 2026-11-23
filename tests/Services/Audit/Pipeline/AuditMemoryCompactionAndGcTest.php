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
        $statusEvalCalls = 0;

        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(null);
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
            ->willReturnCallback(static function (string $script, array $keys = []) use ($taskKey, $taskRecord, $planKey, $planRecord, $auditId, &$deletedTask, &$deletedPlan, &$statusEvalCalls): mixed {
                if ($keys === []) {
                    return 1700000000000;
                }
                // REQ-F1-01: el status se lee con un script compacto (AUDIT_STATUS_LUA), nunca con un get completo.
                if (str_contains($script, "type(a.status) ~= 'string'") && ($keys[0] ?? '') === AuditStateStore::auditKey($auditId)) {
                    $statusEvalCalls++;
                    return 'failed';
                }
                if (($keys[0] ?? '') === 'audit:execution:gc:cursors') {
                    return str_contains($script, 'HGETALL') ? [] : 1;
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
        self::assertSame(1, $statusEvalCalls, 'gc() debe cachear el status de audit:{id}:state por llamada.');
        self::assertSame(2, $removed);
    }

    /** Registro de tarea válido (AuditTask::fromEvent) con identidad, auditoría y estado terminal controlados. */
    private static function terminalTaskRecord(string $taskId, string $auditId): array
    {
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $record = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event)->record;
        $record['task_id'] = $taskId;
        $record['audit_id'] = $auditId;
        $record['state'] = 'completed';
        $record['fence'] = 1;

        return $record;
    }

    /** T-U1 (CA-F1-01): gc() no lee audit:*:state completo, cachea status y corta en GC_MAX_ITEMS. */
    public function testGcCachesOnlyStatusAndBoundsCache(): void
    {
        $statusEvals = 0;
        $tasksScanned = 0;
        $taskDeletes = 0;
        $getStateCalls = 0;
        $pages = [];
        // 600 tareas terminales en 6 páginas de 100; las 500 primeras referencian 250 auditorías (2 tareas cada una).
        for ($page = 0; $page < 6; $page++) {
            $keys = [];
            for ($j = 0; $j < 100; $j++) {
                $i = $page * 100 + $j;
                $taskId = str_pad(dechex($i + 1), 64, '0', STR_PAD_LEFT);
                $auditId = $i < 500 ? 'a-' . intdiv($i, 2) : 'b-' . $i;
                $keys[] = ['key' => 'audit:task:v2:' . $taskId, 'audit_id' => $auditId, 'task_id' => $taskId];
            }
            $pages[] = $keys;
        }
        $tasksRecords = [];
        foreach ($pages as $keys) {
            foreach ($keys as $entry) {
                $tasksRecords[$entry['key']] = json_encode(
                    self::terminalTaskRecord($entry['task_id'], $entry['audit_id']),
                    JSON_THROW_ON_ERROR
                );
            }
        }

        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$getStateCalls): ?string {
            if (str_starts_with($key, 'audit:') && str_ends_with($key, ':state')) {
                $getStateCalls++;
            }
            return null;
        });
        $redis->method('scanKeys')->willReturnCallback(static function (string $pattern, string $cursor) use ($pages, &$tasksScanned): array {
            if ($pattern !== 'audit:task:v2:*') {
                return ['0', []];
            }
            $tasksScanned++;
            $page = (int) $cursor;
            $next = $page + 1 < count($pages) ? (string) ($page + 1) : '0';
            return [$next, array_column($pages[$page], 'key')];
        });
        $cursorsArgv = null;
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = [], array $argv = []) use ($tasksRecords, &$statusEvals, &$taskDeletes, &$cursorsArgv): mixed {
            if ($keys === []) {
                return 1700000000000;
            }
            if (str_contains($script, "type(a.status) ~= 'string'")) {
                $statusEvals++;
                return 'failed';
            }
            if (str_contains($script, 'HGET",KEYS[1],"record"') && isset($tasksRecords[$keys[0]])) {
                return $tasksRecords[$keys[0]];
            }
            if (($keys[0] ?? '') === 'audit:execution:gc:cursors') {
                if (str_contains($script, 'HSET')) {
                    $cursorsArgv = $argv;
                    return 1;
                }
                return str_contains($script, 'HGETALL') ? [] : 1;
            }
            if (str_contains($script, "'DEL'") || str_contains($script, '"DEL"')) {
                $taskDeletes++;
                return 1;
            }
            return 0;
        });

        // memoryProbe por debajo del límite: solo actúa el corte por ítems.
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024);
        $maintenance->gc(60000, 1700000000000);

        $reflection = new \ReflectionProperty(AuditExecutionMaintenance::class, 'gcBudgetStopped');
        self::assertSame(0, $getStateCalls, 'gc() no debe leer audit:*:state completo (REQ-F1-02).');
        self::assertSame(250, $statusEvals, 'La caché de status debe reutilizarse dentro de la llamada: 250 auditorías distintas.');
        self::assertSame(5, $tasksScanned, 'La 6.ª página no debe leerse: el corte llega en GC_MAX_ITEMS.');
        self::assertTrue($reflection->getValue($maintenance) === true, 'gc() debe marcar gcBudgetStopped al cortar por ítems.');
        self::assertSame(AuditExecutionMaintenance::GC_MAX_ITEMS, $taskDeletes, 'Se procesan exactamente GC_MAX_ITEMS tareas.');

        self::assertNotNull($cursorsArgv, 'gc() debe persistir gc:cursors con un HSET.');
        $saved = [];
        for ($i = 0; $i < count($cursorsArgv); $i += 2) {
            $saved[$cursorsArgv[$i]] = $cursorsArgv[$i + 1];
        }
        self::assertSame('[]', $saved['pending_keys'] ?? null, 'Corte en frontera de página: sin claves pendientes.');
        self::assertSame('5', $saved['cursor:tasks'] ?? null, 'El cursor de tasks debe ser el de la 5.ª página (siguiente: 5).');
        self::assertSame('0', $saved['pattern_index'] ?? null, 'pattern_index debe seguir en tasks (0).');
    }

    /** T-U5 (CA-F1-17): gc() corta por budgetMs y deja pending_keys persistido. */
    public function testGcStopsWhenTimeBudgetExpires(): void
    {
        $keys = [];
        for ($i = 0; $i < 300; $i++) {
            $keys[] = 'audit:task:v2:' . str_pad(dechex($i + 1), 64, '0', STR_PAD_LEFT);
        }
        $hgetallSaved = null;
        $statusEvals = 0;
        $redis = $this->createStub(RedisClient::class);
        $redis->method('scanKeys')->willReturnCallback(static function (string $pattern, string $cursor) use ($keys): array {
            if ($pattern !== 'audit:task:v2:*') {
                return ['0', []];
            }
            $page = (int) $cursor;
            $next = $page + 100 < count($keys) ? (string) ($page + 100) : '0';
            return [$next, array_slice($keys, $page, 100)];
        });
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keysArg = [], array $argv = []) use (&$hgetallSaved, &$statusEvals, $keys): mixed {
            if ($keysArg === []) {
                return 1700000000000;
            }
            if (str_contains($script, "type(a.status) ~= 'string'")) {
                usleep(5000);
                $statusEvals++;
                return 'failed';
            }
            if (str_contains($script, 'HGET",KEYS[1],"record"')) {
                // Tarea terminal de una auditoría distinta por clave.
                $index = array_search($keysArg[0], $keys, true);
                return json_encode(self::terminalTaskRecord(
                    str_pad(dechex((int) $index + 1), 64, '0', STR_PAD_LEFT),
                    'audit-' . $index
                ), JSON_THROW_ON_ERROR);
            }
            if (($keysArg[0] ?? '') === 'audit:execution:gc:cursors') {
                if (str_contains($script, 'HSET')) {
                    $hgetallSaved = $argv;
                    return 1;
                }
                return [];
            }
            return 0;
        });

        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024);
        $maintenance->gc(20, 1700000000000);

        $map = [];
        for ($i = 0; $i < count($hgetallSaved ?? []); $i += 2) {
            $map[$hgetallSaved[$i]] = $hgetallSaved[$i + 1];
        }
        self::assertLessThan(300, $statusEvals, 'gc() debe detenerse antes de procesar las 300 tareas.');
        self::assertNotSame('[]', $map['pending_keys'] ?? '[]', 'pending_keys debe quedar persistido.');
        self::assertSame('0', $map['pending_pattern'] ?? '', 'pending_pattern debe apuntar al patrón tasks (0).');
        self::assertSame('0', $map['pattern_index'] ?? '', 'pattern_index debe seguir en tasks.');
        $reflection = new \ReflectionProperty(AuditExecutionMaintenance::class, 'gcBudgetStopped');
        self::assertTrue($reflection->getValue($maintenance) === true, 'gcBudgetStopped debe ser true tras el corte por tiempo.');
    }

    /** T-U6 (CA-F1-17): gc() reencola un job v1 sin leerlo cuando la memoria supera el límite blando. */
    public function testGcRequeuesJobWhenMemorySoftLimitReached(): void
    {
        $jobKeys = ['job:aaa:state', 'job:bbb:state'];
        $jobGets = 0;
        $savedArgv = null;
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$jobGets): ?string {
            if (str_starts_with($key, 'job:')) {
                $jobGets++;
            }
            return null;
        });
        $redis->method('scanKeys')->willReturnCallback(static function (string $pattern) use ($jobKeys): array {
            return $pattern === 'job:*:state' ? ['0', $jobKeys] : ['0', []];
        });
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keysArg = [], array $argv = []) use (&$savedArgv): mixed {
            if ($keysArg === []) {
                return 1700000000000;
            }
            if (($keysArg[0] ?? '') === 'audit:execution:gc:cursors') {
                if (str_contains($script, 'HSET')) {
                    $savedArgv = $argv;
                    return 1;
                }
                if (str_contains($script, 'HGETALL')) {
                    return [
                        'pattern_index', '4', 'cursor:jobs', '0',
                    ];
                }
            }
            return 0;
        });

        // Las dos primeras comprobaciones (presupuesto antes del SCAN y antes de la 1.ª clave) están bajo el límite;
        // la comprobación dentro de la rama `jobs` ve el límite y reencola la clave sin leerla.
        $probeCalls = 0;
        $limit = AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES;
        $probe = static function () use (&$probeCalls, $limit): int {
            $probeCalls++;
            return $probeCalls <= 2 ? $limit - 1 : $limit;
        };
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), $probe);
        $removed = $maintenance->gc(60000, 1700000000000);

        self::assertSame(0, $jobGets, 'Con memoria sobre el límite blando no se debe leer ningún job:*:state.');
        self::assertSame(0, $removed);
        $reflection = new \ReflectionProperty(AuditExecutionMaintenance::class, 'gcBudgetStopped');
        self::assertTrue($reflection->getValue($maintenance) === true, 'gc_budget_stopped debe quedar a verdadero.');
        self::assertNotNull($savedArgv);
        $map = [];
        for ($i = 0; $i < count($savedArgv); $i += 2) {
            $map[$savedArgv[$i]] = $savedArgv[$i + 1];
        }
        self::assertSame('4', $map['pending_pattern'] ?? '', 'La clave debe quedar pendiente en el patrón jobs (4).');
        self::assertSame(json_encode($jobKeys, JSON_THROW_ON_ERROR), $map['pending_keys'] ?? '', 'La clave no leída debe reencolarse en primera posición.');
    }

    /** Variante de T-U6: con la sonda siempre sobre el límite, gc() no hace SCAN y retorna 0. */
    public function testGcDoesNotScanWhenMemoryIsAlwaysAboveSoftLimit(): void
    {
        $scans = 0;
        $redis = $this->createStub(RedisClient::class);
        $redis->method('scanKeys')->willReturnCallback(static function () use (&$scans): array {
            $scans++;
            return ['0', []];
        });
        $heartbeatArgv = null;
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keysArg = [], array $argv = []) use (&$heartbeatArgv): mixed {
            if ($keysArg === []) {
                return 1700000000000;
            }
            if (in_array(AuditExecutionMaintenance::HEARTBEAT_META_KEY, $keysArg, true)) {
                $heartbeatArgv = $argv;
                return 1700000000000;
            }
            return str_contains($script, 'HGETALL') ? [] : 0;
        });
        $limit = AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES;
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => $limit);

        self::assertSame(0, $maintenance->gc(60000, 1700000000000));
        self::assertSame(0, $scans, 'Sobre el límite blando, gc() no debe hacer SCAN.');
        $reflection = new \ReflectionProperty(AuditExecutionMaintenance::class, 'gcBudgetStopped');
        self::assertTrue($reflection->getValue($maintenance) === true);

        // Tras el corte, el heartbeat (writeHeartbeat, invocado tal como lo hace tick()) lleva gc_budget_stopped = 1.
        (new \ReflectionMethod(AuditExecutionMaintenance::class, 'writeHeartbeat'))->invoke($maintenance, 0, 0, 0, 0, 0);
        self::assertNotNull($heartbeatArgv, 'El heartbeat debe escribir heartbeat:meta.');
        $meta = [];
        // argv: [TTL heartbeat, TTL meta, campo, valor, ...]
        for ($i = 2; $i < count($heartbeatArgv); $i += 2) {
            $meta[$heartbeatArgv[$i]] = $heartbeatArgv[$i + 1];
        }
        self::assertSame(1, $meta['gc_budget_stopped'] ?? null, 'heartbeat:meta.gc_budget_stopped = 1 tras el corte.');
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
