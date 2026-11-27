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
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMaintenanceRedis;

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

    // ── SDD-BATCH-001: delta Fase 2 de gc() ──────────────────────────────

    private const NOW_MS = 1_800_000_000_000;
    private const OLD_ISO = '2026-01-01T00:00:00Z';

    /** `DEC-06`, `W-F2-M2`, `CA-F2-17`: HSCAN de 50.000 entradas cortado por presupuesto y reanudado con el sha1 persistido. */
    public function testGcResumesJobAuditsHscanAcrossBudgetCuts(): void
    {
        [$redis, $jobId, $jobKey] = $this->terminalJobFixture(50000);
        $originalSha1 = sha1($redis->strings[$jobKey]);
        $limit = AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES;

        // 1.er tick: la memoria llega al límite blando tras 30 rebanadas (15.000 entradas).
        $removed = (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2),
            static fn(): int => count($redis->refsCursors) >= 30 ? $limit : 1024))->gc(60000, self::NOW_MS);

        self::assertSame(0, $removed);
        self::assertSame([], $redis->jobDeletes, 'Sin terminar el HSCAN no se borra nada.');
        self::assertSame('0', $redis->refsCursors[0]);
        self::assertSame($jobId, $redis->gcCursors['hscan_job_id']);
        self::assertSame('15000', $redis->gcCursors['hscan_cursor']);
        self::assertSame($originalSha1, $redis->gcCursors['hscan_sha1']);
        self::assertSame('4', $redis->gcCursors['pattern_index']);
        self::assertSame('4', $redis->gcCursors['pending_pattern']);
        self::assertSame(json_encode([$jobKey]), $redis->gcCursors['pending_keys'], 'El job se reencola al frente.');

        // 2.º tick (instancia nueva, estado leído de gc:cursors): reanuda en 15000 y borra con el sha1 persistido.
        $redis->refsCursors = [];
        $removed = (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);

        self::assertSame('15000', $redis->refsCursors[0], 'La 1.ª rebanada del tick siguiente usa hscan_cursor.');
        self::assertCount(70, $redis->refsCursors, '35.000 entradas restantes / 500 por rebanada.');
        self::assertSame(1, $removed);
        self::assertSame([[$originalSha1, $jobId]], $redis->jobDeletes);
        self::assertArrayNotHasKey($jobKey, $redis->strings);
        self::assertSame(['', '0', ''], [$redis->gcCursors['hscan_job_id'], $redis->gcCursors['hscan_cursor'], $redis->gcCursors['hscan_sha1']]);
    }

    /** `W-F2-M2`: si `job:{id}:state` cambia entre ticks, el borrado usa el sha1 del inicio del HSCAN y se aborta. */
    public function testGcAbortsJobDeletionWhenStateChangedBetweenTicks(): void
    {
        [$redis, $jobId, $jobKey] = $this->terminalJobFixture(2000);
        $originalSha1 = sha1($redis->strings[$jobKey]);
        $limit = AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES;
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2),
            static fn(): int => count($redis->refsCursors) >= 2 ? $limit : 1024))->gc(60000, self::NOW_MS);
        self::assertSame('1000', $redis->gcCursors['hscan_cursor']);

        $changed = json_decode($redis->strings[$jobKey], true);
        $changed['updated_at'] = '2026-01-02T00:00:00Z';
        $redis->strings[$jobKey] = (string) json_encode($changed);
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);

        self::assertSame([[$originalSha1, $jobId]], $redis->jobDeletes, 'Nunca se recalcula el sha1 al reanudar.');
        self::assertArrayHasKey($jobKey, $redis->strings, 'Con el sha1 distinto el job no se borra.');
        self::assertSame('', $redis->gcCursors['hscan_job_id']);
    }

    /** `R4-F2-M3`: una referencia activa (miembro sin prefijo `plan:`) aborta el borrado y limpia `hscan_*`. */
    public function testGcAbortsTerminalJobWithActiveReferenceAndSkipsV1State(): void
    {
        [$redis, , $jobKey] = $this->terminalJobFixture(800);
        $redis->activeRefs[BatchJobStore::jobAuditsKey((string) json_decode($redis->strings[$jobKey], true)['job_id'])] = true;
        $v1Key = BatchJobStore::jobKey(AuditEvent::deterministicUuidV4('gc-v1-job'));
        $redis->strings[$v1Key] = (string) json_encode(['status' => 'completed', 'completed_at' => self::OLD_ISO,
            'audits' => array_fill(0, 80, ['status' => 'completed', 'dis_det_nro' => 'X'])]);
        $redis->pages['job:*:state'] = [[$jobKey, $v1Key]];

        $removed = (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);

        self::assertSame(0, $removed);
        self::assertSame([], $redis->jobDeletes);
        self::assertCount(1, $redis->refsCursors, 'Se aborta en la 1.ª rebanada con referencia activa.');
        self::assertSame('', $redis->gcCursors['hscan_job_id']);
        self::assertGreaterThanOrEqual(AuditExecutionMaintenance::JOB_STATE_MAX_BYTES, strlen($redis->strings[$v1Key]));
        self::assertNotContains('get:' . $v1Key, $redis->log, 'Un job v1 residual (>= 2048 B) no se lee ni se decodifica.');
    }

    /** `DEC-10`: el reaper solo actúa sobre jobs vencidos (o en reaping), con el umbral configurado y por rebanadas. */
    public function testGcInvokesReaperForStaleNonTerminalJobsWithinBudget(): void
    {
        $threshold = AuditExecutionConfig::jobStaleAfterSeconds() * 1000;
        $ids = [];
        foreach (['stale', 'fresh', 'reaping', 'unmarked', 'updated-only', 'terminal'] as $label) {
            $ids[$label] = AuditEvent::deterministicUuidV4('gc-reaper-' . $label);
        }
        $redis = new FakeMaintenanceRedis();
        $jobs = [
            'stale' => ['status' => 'processing', 'last_progress_at' => self::NOW_MS - $threshold - 1000],
            'fresh' => ['status' => 'processing', 'last_progress_at' => self::NOW_MS - 1000],
            'reaping' => ['status' => 'queued', 'reaping' => true, 'last_progress_at' => self::NOW_MS - 1000],
            'unmarked' => ['status' => 'processing', 'last_progress_at' => null, 'updated_at' => '###'],
            'updated-only' => ['status' => 'pending', 'last_progress_at' => 0,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv(self::NOW_MS - $threshold - 60000, 1000))],
            'terminal' => ['status' => 'failed', 'completed_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv(self::NOW_MS, 1000))],
        ];
        $keys = [];
        foreach ($jobs as $label => $fields) {
            $keys[] = $key = BatchJobStore::jobKey($ids[$label]);
            $redis->strings[$key] = self::jobJson($ids[$label], $fields);
        }
        $redis->pages['job:*:state'] = [$keys];
        $redis->gcCursors = ['pattern_index' => '4'];
        $calls = [];
        $store = $this->createMock(BatchJobStore::class);
        $store->method('reapStaleJob')->willReturnCallback(
            static function (string $jobId, int $nowMs, int $thresholdMs, ?string $cursor) use (&$calls, $ids): array {
                $calls[] = [$jobId, $nowMs, $thresholdMs, $cursor];
                if ($jobId === $ids['stale'] && $cursor === null) {
                    return ['done' => false, 'cursor' => '77', 'cancelled_audits' => 2, 'reaped' => false];
                }
                return ['done' => true, 'cursor' => null, 'cancelled_audits' => 1, 'reaped' => true];
            }
        );
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024, $store);

        $maintenance->gc(60000, self::NOW_MS);

        self::assertSame([
            [$ids['stale'], self::NOW_MS, $threshold, null],
            [$ids['stale'], self::NOW_MS, $threshold, '77'],
            [$ids['reaping'], self::NOW_MS, $threshold, null],
            [$ids['updated-only'], self::NOW_MS, $threshold, null],
        ], $calls);
        self::assertSame(3, (new \ReflectionProperty(AuditExecutionMaintenance::class, 'lastGcReapedJobs'))->getValue($maintenance));
        self::assertSame('', $redis->gcCursors['reap_job_id']);
        self::assertSame('0', $redis->gcCursors['reap_cursor']);
    }

    /** `DEC-10`, `R5-F2-I2`: con pausa no hay reaper (ni reanudación pendiente); el umbral se mide desde `resumed_at_ms`. */
    public function testReaperSkippedWhilePausedAndThresholdMeasuredFromResume(): void
    {
        $threshold = AuditExecutionConfig::jobStaleAfterSeconds() * 1000;
        $jobId = AuditEvent::deterministicUuidV4('gc-pause-job');
        $run = function (array $strings, array $cursors = []) use ($jobId, $threshold): array {
            $redis = new FakeMaintenanceRedis();
            $redis->strings = $strings + [BatchJobStore::jobKey($jobId) => self::jobJson($jobId,
                ['status' => 'processing', 'last_progress_at' => self::NOW_MS - $threshold - 1000])];
            $redis->pages['job:*:state'] = [[BatchJobStore::jobKey($jobId)]];
            $redis->gcCursors = $cursors + ['pattern_index' => '4'];
            $calls = [];
            $store = $this->createMock(BatchJobStore::class);
            $store->method('reapStaleJob')->willReturnCallback(static function (string $id, int $now, int $t, ?string $cursor) use (&$calls): array {
                $calls[] = [$id, $cursor];
                return ['done' => true, 'cursor' => null, 'cancelled_audits' => 0, 'reaped' => true];
            });
            (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024, $store))->gc(60000, self::NOW_MS);
            return [$calls, $redis];
        };

        [$calls, $redis] = $run(['audit:execution:paused' => '1'], ['reap_job_id' => 'pending-job', 'reap_cursor' => '5']);
        self::assertSame([], $calls, 'En pausa no se invoca el reaper ni se reanuda reap_job_id.');
        self::assertSame('pending-job', $redis->gcCursors['reap_job_id'], 'El cursor pendiente se conserva para después de la pausa.');

        [$calls] = $run(['audit:execution:resumed_at_ms' => (string) (self::NOW_MS - 1000)]);
        self::assertSame([], $calls, 'Reanudado hace 1 s: la pausa no consume el umbral.');

        [$calls] = $run(['audit:execution:resumed_at_ms' => (string) (self::NOW_MS - $threshold - 1)]);
        self::assertSame([[$jobId, null]], $calls);
    }

    /** `W-F2-M7`: un `reap_job_id` pendiente se reanuda al inicio de gc(), antes de cualquier SCAN. */
    public function testPendingReapJobResumedAtGcStart(): void
    {
        $redis = new FakeMaintenanceRedis();
        $redis->gcCursors = ['pattern_index' => '0', 'reap_job_id' => 'job-pendiente', 'reap_cursor' => '42'];
        $store = $this->createMock(BatchJobStore::class);
        $store->expects(self::once())->method('reapStaleJob')
            ->willReturnCallback(static function (string $jobId, int $now, int $t, ?string $cursor) use ($redis): array {
                $redis->log[] = 'reap:' . $jobId . ':' . $cursor;
                return ['done' => true, 'cursor' => null, 'cancelled_audits' => 4, 'reaped' => true];
            });
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024, $store);

        $maintenance->gc(60000, self::NOW_MS);

        $order = array_values(array_filter($redis->log, static fn(string $l): bool => str_starts_with($l, 'reap:') || str_starts_with($l, 'scan:')));
        self::assertSame('reap:job-pendiente:42', $order[0]);
        self::assertSame('', $redis->gcCursors['reap_job_id']);
        self::assertSame(1, (new \ReflectionProperty(AuditExecutionMaintenance::class, 'lastGcReapedJobs'))->getValue($maintenance));
    }

    /** `R5-F2-B2`: un corte de presupuesto al reanudar `reap_job_id` antes del bucle no pisa `pending_pattern`/`pending_keys`. */
    public function testPreLoopReapResumeBudgetCutPreservesPendingKeys(): void
    {
        $redis = new FakeMaintenanceRedis();
        $pendingKeys = json_encode(['audit:pendiente-1:state', 'audit:pendiente-2:state']);
        $redis->gcCursors = ['pattern_index' => '2', 'pending_pattern' => '2', 'pending_keys' => $pendingKeys,
            'reap_job_id' => 'job-grande', 'reap_cursor' => '42'];
        $store = $this->createMock(BatchJobStore::class);
        $store->method('reapStaleJob')->willReturn(['done' => false, 'cursor' => '43', 'cancelled_audits' => 500, 'reaped' => false]);
        $probes = 0;
        $limit = AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES;
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2),
            static function () use (&$probes, $limit): int { return ++$probes <= 1 ? 1024 : $limit; }, $store);

        self::assertSame(0, $maintenance->gc(60000, self::NOW_MS));

        self::assertSame('2', $redis->gcCursors['pending_pattern']);
        self::assertSame($pendingKeys, $redis->gcCursors['pending_keys']);
        self::assertSame('2', $redis->gcCursors['pattern_index']);
        self::assertSame('job-grande', $redis->gcCursors['reap_job_id']);
        self::assertSame('43', $redis->gcCursors['reap_cursor']);
        self::assertSame([], array_filter($redis->log, static fn(string $l): bool => str_starts_with($l, 'scan:')));
    }

    /** `W-F2-I3`: una `disid:*` viva no provoca bucle; en la misma visita del índice 5 se recolectan las `disdetnro:*`. */
    public function testSurvivingLegacyReservationDoesNotLoopAndDisdetnroIsStillCollected(): void
    {
        $redis = new FakeMaintenanceRedis();
        $live = 'audit:reservation:disid:LIVE-1';
        $liveAudit = AuditEvent::deterministicUuidV4('gc-live-audit');
        $redis->strings[$live] = (string) json_encode(['audit_id' => $liveAudit, 'token' => 't',
            'claimed_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv(self::NOW_MS, 1000))]);
        $redis->auditStatuses[$liveAudit] = 'processing';
        $orphans = [BatchJobStore::auditReservationKey('O-1'), BatchJobStore::auditReservationKey('O-2')];
        foreach ($orphans as $i => $key) {
            $redis->strings[$key] = (string) json_encode(['audit_id' => AuditEvent::deterministicUuidV4('gc-orphan-' . $i),
                'token' => 't', 'claimed_at' => self::OLD_ISO]);
        }
        $redis->pages[AuditExecutionMaintenance::LEGACY_RESERVATION_PATTERN] = [[$live]];
        $redis->pages['audit:reservation:disdetnro:*'] = [$orphans];
        $redis->gcCursors = ['pattern_index' => '5'];
        $legacyScans = static fn(): int => count(array_filter($redis->log, static fn(string $l): bool => str_starts_with($l, 'scan:audit:reservation:disid:*')));

        $removed = (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);

        self::assertSame(2, $removed);
        self::assertSame($orphans, $redis->reservationDeletes);
        self::assertArrayHasKey($live, $redis->strings);
        self::assertSame(1, $legacyScans(), 'Una sola pasada SCAN sobre disid:* por visita del índice 5.');
        $firstLegacy = array_search('scan:audit:reservation:disid:*:0', $redis->log, true);
        $firstNew = array_search('scan:audit:reservation:disdetnro:*:0', $redis->log, true);
        self::assertIsInt($firstLegacy);
        self::assertIsInt($firstNew);
        self::assertLessThan($firstNew, $firstLegacy, 'La pasada legada precede a disdetnro:* en el mismo índice.');
        self::assertSame('0', $redis->gcCursors['legacy_done'], 'La pasada vio claves: se repite en el ciclo siguiente.');
        self::assertSame('0', $redis->gcCursors['legacy_pass_done'], 'Completado el índice 5, la pasada se reinicia.');
        self::assertSame('0', $redis->gcCursors['legacy_saw_keys']);

        // Sin claves legadas, la siguiente pasada fija legacy_done = '1' y después no se vuelve a escanear el patrón.
        unset($redis->strings[$live]);
        $redis->pages[AuditExecutionMaintenance::LEGACY_RESERVATION_PATTERN] = [[]];
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);
        self::assertSame('1', $redis->gcCursors['legacy_done']);
        self::assertSame(2, $legacyScans());
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);
        self::assertSame(2, $legacyScans(), 'Con legacy_done = 1 el patrón legado no se visita más.');
    }

    /** `W-F1-I3`, `R5-F2-I1`: las excepciones no saltan el índice 5 (pasada legada) ni el 4 (reaper pendiente). */
    public function testFinallyDoesNotSkipReservationsOrJobsPatternOnException(): void
    {
        // (a) Excepción en la última clave de una página INTERMEDIA de disid:*: no avanza a 6.
        $redis = new FakeMaintenanceRedis();
        $redis->pages[AuditExecutionMaintenance::LEGACY_RESERVATION_PATTERN] = [
            ['audit:reservation:disid:A', 'audit:reservation:disid:BAD'],
            ['audit:reservation:disid:C'],
        ];
        $redis->corrupt['audit:reservation:disid:BAD'] = '{json-invalido';
        $redis->gcCursors = ['pattern_index' => '5'];
        self::assertTrue($this->gcThrowsWith($redis));
        self::assertSame('5', $redis->gcCursors['pattern_index']);
        self::assertSame('1', $redis->gcCursors['cursor:reservations_legacy']);
        self::assertSame('0', $redis->gcCursors['legacy_pass_done']);
        self::assertSame('[]', $redis->gcCursors['pending_keys']);
        // El tick siguiente continúa la pasada legada en la página 1 (no reinicia en '0').
        $redis->log = [];
        unset($redis->corrupt['audit:reservation:disid:BAD']);
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);
        self::assertContains('scan:audit:reservation:disid:*:1', $redis->log);
        self::assertNotContains('scan:audit:reservation:disid:*:0', $redis->log);

        // (b) Excepción en la última clave ya extraída de la página FINAL de disid:*: cierra la pasada sin avanzar.
        $redis = new FakeMaintenanceRedis();
        $redis->pages[AuditExecutionMaintenance::LEGACY_RESERVATION_PATTERN] = [['audit:reservation:disid:BAD2']];
        $redis->corrupt['audit:reservation:disid:BAD2'] = '{json-invalido';
        $redis->gcCursors = ['pattern_index' => '5'];
        self::assertTrue($this->gcThrowsWith($redis));
        self::assertSame('5', $redis->gcCursors['pattern_index']);
        self::assertSame('1', $redis->gcCursors['legacy_pass_done']);
        self::assertSame('0', $redis->gcCursors['legacy_done'], 'La página tenía claves (legacy_saw_keys = 1).');
        $redis->log = [];
        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);
        $first = array_values(array_filter($redis->log, static fn(string $l): bool => str_starts_with($l, 'scan:')))[0] ?? null;
        self::assertSame('scan:audit:reservation:disdetnro:*:0', $first, 'El tick siguiente sigue en disdetnro:*, sin re-escanear disid:*.');

        // (c) Excepción del reaper tras persistir reap_job_id: el índice 4 no avanza.
        $threshold = AuditExecutionConfig::jobStaleAfterSeconds() * 1000;
        $jobId = AuditEvent::deterministicUuidV4('gc-reap-exception');
        $redis = new FakeMaintenanceRedis();
        $redis->strings[BatchJobStore::jobKey($jobId)] = self::jobJson($jobId, ['status' => 'processing', 'last_progress_at' => self::NOW_MS - $threshold - 1]);
        $redis->pages['job:*:state'] = [[BatchJobStore::jobKey($jobId)]];
        $redis->gcCursors = ['pattern_index' => '4'];
        $store = $this->createMock(BatchJobStore::class);
        $slices = 0;
        $store->method('reapStaleJob')->willReturnCallback(static function () use (&$slices): array {
            if (++$slices > 1) {
                throw new \RuntimeException('redis caído');
            }
            return ['done' => false, 'cursor' => '9', 'cancelled_audits' => 500, 'reaped' => false];
        });
        self::assertTrue($this->gcThrowsWith($redis, $store));
        self::assertSame('4', $redis->gcCursors['pattern_index']);
        self::assertSame($jobId, $redis->gcCursors['reap_job_id']);
        self::assertSame('9', $redis->gcCursors['reap_cursor']);
    }

    /** `X-F2-B1`, `R4-F2-B1`: solo se libera la reserva de una auditoría viva de un job inexistente o terminal sin la fila. */
    public function testOrphanReservationReleasedOnlyForMissingOrTerminalJobWithoutRow(): void
    {
        $redis = new FakeMaintenanceRedis();
        $failed = AuditEvent::deterministicUuidV4('orphan-job-failed');
        $processing = AuditEvent::deterministicUuidV4('orphan-job-processing');
        $failedWithRow = AuditEvent::deterministicUuidV4('orphan-job-failed-row');
        $missing = AuditEvent::deterministicUuidV4('orphan-job-missing');
        $redis->strings[BatchJobStore::jobKey($failed)] = self::jobJson($failed, ['status' => 'failed']);
        $redis->strings[BatchJobStore::jobKey($processing)] = self::jobJson($processing, ['status' => 'processing']);
        $redis->strings[BatchJobStore::jobKey($failedWithRow)] = self::jobJson($failedWithRow, ['status' => 'failed']);
        $old = gmdate('Y-m-d\TH:i:s\Z', intdiv(self::NOW_MS, 1000) - 61);
        $young = gmdate('Y-m-d\TH:i:s\Z', intdiv(self::NOW_MS, 1000) - 30);
        $cases = [
            'R1' => [$failed, $old], 'R2' => [$missing, $old], 'R3' => [$processing, $old],
            'R4' => [$failedWithRow, $old], 'R5' => [$failed, $young],
        ];
        $keys = [];
        foreach ($cases as $name => [$jobId, $claimedAt]) {
            $auditId = AuditEvent::deterministicUuidV4('orphan-audit-' . $name);
            $keys[] = $key = BatchJobStore::auditReservationKey('DDN-' . $name);
            $redis->strings[$key] = (string) json_encode(['job_id' => $jobId, 'audit_id' => $auditId, 'token' => 't', 'claimed_at' => $claimedAt]);
            $redis->auditStatuses[$auditId] = 'pending';
            $redis->strings[AuditStateStore::auditKey($auditId)] = (string) json_encode([
                'audit_id' => $auditId, 'job_id' => $jobId, 'status' => 'pending', 'documents' => ['doc-1' => ['status' => 'extracted']],
            ]);
            if ($name === 'R4') {
                $redis->hashes[BatchJobStore::jobAuditsKey($jobId)][$auditId] = '{"status":"pending"}';
            }
        }
        $redis->pages['audit:reservation:disdetnro:*'] = [$keys];
        $redis->gcCursors = ['pattern_index' => '5', 'legacy_done' => '1'];

        (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024))->gc(60000, self::NOW_MS);

        self::assertSame([$keys[0], $keys[1]], $redis->orphanReleases);
        $state = json_decode($redis->strings[AuditStateStore::auditKey(AuditEvent::deterministicUuidV4('orphan-audit-R1'))], true);
        self::assertSame('failed', $state['status']);
        self::assertSame('orphaned_job_terminal', $state['failed_stage']);
        self::assertSame(['doc-1' => ['status' => 'extracted']], $state['documents']);
        foreach ([$keys[2], $keys[3], $keys[4]] as $kept) {
            self::assertArrayHasKey($kept, $redis->strings);
        }
    }

    /** @return array{0:FakeMaintenanceRedis,1:string,2:string} */
    private function terminalJobFixture(int $audits): array
    {
        $jobId = AuditEvent::deterministicUuidV4('gc-hscan-job-' . $audits);
        $jobKey = BatchJobStore::jobKey($jobId);
        $redis = new FakeMaintenanceRedis();
        $redis->strings[$jobKey] = self::jobJson($jobId, ['status' => 'completed', 'completed_at' => self::OLD_ISO, 'total' => $audits]);
        $redis->hashSizes[BatchJobStore::jobAuditsKey($jobId)] = $audits;
        $redis->pages['job:*:state'] = [[$jobKey]];
        $redis->gcCursors = ['pattern_index' => '4'];

        return [$redis, $jobId, $jobKey];
    }

    /** @param array<string,mixed> $fields */
    private static function jobJson(string $jobId, array $fields): string
    {
        return (string) json_encode($fields + [
            'job_id' => $jobId, 'execution_version' => 2, 'fac_nit_sec' => 2624, 'date_from' => '2026-06-01',
            'date_to' => '2026-06-30', 'sealed' => false, 'total' => 0, 'done' => 0, 'failed' => 0,
            'updated_at' => self::OLD_ISO,
        ]);
    }

    private function gcThrowsWith(FakeMaintenanceRedis $redis, ?BatchJobStore $store = null): bool
    {
        try {
            (new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2), static fn(): int => 1024, $store))->gc(60000, self::NOW_MS);
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
