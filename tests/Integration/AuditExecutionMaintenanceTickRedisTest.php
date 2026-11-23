<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

/**
 * Integración del mantenimiento de ejecución (SDD-BATCH-000, T-I2..T-I15).
 * Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone con AOF y `appendfsync always`; sin ellos se omite.
 * Cada test usa su propio namespace aislado: nunca se ejecuta FLUSHDB ni se toca otra clave.
 */
final class AuditExecutionMaintenanceTickRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->redis->eval('return redis.call("SET",KEYS[1],"2")', ['audit:execution:version']);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    /** T-I2 (CA-F1-04): el borrado de audit:*:state compara sha1; no borra si el valor cambió tras la lectura. */
    public function testGcDeletesExpiredTerminalAuditStateAndSkipsChangedValue(): void
    {
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 864000);
        $deletedId = AuditEvent::uuidV4();
        $this->seedAuditState($deletedId, 'completed', $expired, 100000);
        $changedId = AuditEvent::uuidV4();
        $this->seedAuditState($changedId, 'completed', $expired, 100);
        $replacement = json_encode(['audit_id' => $changedId, 'status' => 'completed', 'completed_at' => $expired, 'pad' => 'changed'], JSON_THROW_ON_ERROR);
        $connection = $this->redis;
        $mutated = false;
        // Solo AUDIT_RETENTION_LUA (la única con `completed_at` y `sha1hex(raw)`) dispara la mutación, una vez.
        $wrapper = new RecordingRedis($connection, static function (string $script, array $keys) use ($connection, $changedId, $replacement, &$mutated): void {
            if (!$mutated && str_contains($script, 'completed_at') && str_contains($script, 'sha1hex(raw)')
                && ($keys[0] ?? '') === AuditStateStore::auditKey($changedId)) {
                $mutated = true;
                $connection->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey($changedId)], [$replacement]);
            }
        });

        $removed = (new AuditExecutionMaintenance($wrapper, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());

        self::assertTrue($mutated, 'La ventana entre lectura de metadatos y borrado debe ejercitarse.');
        self::assertSame(1, $removed, 'Solo la clave no modificada se borra.');
        self::assertNull($this->redis->get(AuditStateStore::auditKey($deletedId)));
        self::assertSame($replacement, $this->redis->get(AuditStateStore::auditKey($changedId)));
    }

    /** T-I3 (CA-F1-03): tras reinstanciar, gc() continúa desde gc:cursors y no repite borrados. */
    public function testGcResumesFromPersistedCursorsAfterReinstantiation(): void
    {
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 864000);
        for ($i = 0; $i < 750; $i++) {
            $this->seedTerminalTask($i, self::uuidFor('audit-' . $i), $expired);
        }

        $total = 0;
        $first = (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());
        $total += $first;
        self::assertGreaterThan(0, $first);
        self::assertLessThan(750, $first, 'La primera llamada debe cortar por GC_MAX_ITEMS.');
        // Tras el corte el SCAN de tareas queda incompleto y el estado queda persistido en gc:cursors.
        self::assertSame('0', $this->cursorField('pattern_index'), 'El corte ocurre dentro del patrón tasks (0).');
        self::assertNotSame('0', $this->cursorField('cursor:tasks') ?? '0', 'El cursor de tasks debe persistirse sin completar el barrido.');
        self::assertIsArray(json_decode($this->cursorField('pending_keys') ?? '[]', true, 512, JSON_THROW_ON_ERROR));

        for ($round = 0; $round < 5 && $this->countKeys('audit:task:v2:*') + $this->countKeys('audit:*:state') > 0; $round++) {
            $total += (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());
        }

        self::assertSame(0, $this->countKeys('audit:task:v2:*'));
        self::assertSame(0, $this->countKeys('audit:*:state'));
        // Cada tarea y cada auditoría terminal vencida se borran exactamente una vez: 750 + 750.
        self::assertSame(1500, $total, 'Ningún borrado debe repetirse entre instancias.');
    }

    /** T-I4 (CA-F1-02): el pico de memoria de gc() se mantiene < 32 MiB con 1.200 auditorías de ~40 KB. */
    public function testGcPeakMemoryStaysBoundedWithLargeAudits(): void
    {
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 864000);
        for ($i = 0; $i < 1200; $i++) {
            // La auditoría de 40 KB se siembra una sola vez, dentro de la tarea terminal (sin sobrescribirla).
            $this->seedTerminalTask($i, self::uuidFor('large-' . $i), $expired, 40000);
        }
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    /** T-I5 (CA-F1-05): job terminal vencido con 3.000 auditorías se borra con ARGV de 40 bytes (sha1 + job_id). */
    public function testGcDeletesLargeExpiredJobWithShortArgv(): void
    {
        $jobId = AuditEvent::uuidV4();
        $audits = [];
        for ($i = 0; $i < 3000; $i++) {
            $audits['audit-' . $i] = ['status' => 'completed', 'pad' => str_repeat('x', 600)];
        }
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 864000);
        $raw = json_encode([
            'job_id' => $jobId, 'status' => 'completed', 'completed_at' => $expired, 'updated_at' => $expired,
            'audits' => $audits,
        ], JSON_THROW_ON_ERROR);
        $this->redis->eval('redis.call("SET",KEYS[1],ARGV[1]); return redis.call("ZADD",KEYS[2],ARGV[2],ARGV[3])',
            [BatchJobStore::jobKey($jobId), 'jobs:index'], [$raw, 1, $jobId]);

        $deleteArgv = [];
        $wrapper = new RecordingRedis($this->redis, static function (string $script, array $keys, array $argv) use (&$deleteArgv): void {
            if (str_contains($script, 'ZREM')) {
                $deleteArgv = $argv;
            }
        });
        (new AuditExecutionMaintenance($wrapper, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());

        self::assertNull($this->redis->get(BatchJobStore::jobKey($jobId)));
        self::assertNull($this->redis->eval('return redis.call("ZSCORE",KEYS[1],ARGV[1])', ['jobs:index'], [$jobId]));
        self::assertCount(2, $deleteArgv);
        self::assertSame(40, strlen((string) $deleteArgv[0]), 'El ARGV del borrado debe ser el sha1 del valor (40 caracteres).');
        self::assertSame($jobId, $deleteArgv[1]);
    }

    /** T-I7 (CA-F1-07, REQ-F1-07): heartbeat TTL ≤ 30 s y meta con los campos base; at_ms no sobrescribible. */
    public function testTickWritesHeartbeatAndMetaWithBaseFields(): void
    {
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));
        $maintenance->tick();

        $ttl = (int) $this->redis->eval('return redis.call("TTL",KEYS[1])', ['audit:execution:heartbeat']);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(AuditExecutionConfig::HEARTBEAT_TTL_SECONDS, $ttl);
        $metaTtl = (int) $this->redis->eval('return redis.call("TTL",KEYS[1])', [AuditExecutionMaintenance::HEARTBEAT_META_KEY]);
        self::assertGreaterThan(0, $metaTtl);
        self::assertLessThanOrEqual(60, $metaTtl);

        $meta = $this->meta();
        foreach (['tick_duration_ms', 'outbox_flushed', 'intake_admitted', 'intake_acked', 'gc_removed', 'memory_bytes',
            'gc_budget_stopped', 'admission_paused', 'execution_paused', 'at_ms'] as $field) {
            self::assertArrayHasKey($field, $meta);
        }
        self::assertSame((string) $this->redis->get('audit:execution:heartbeat'), $meta['at_ms']);

        $method = new \ReflectionMethod(AuditExecutionMaintenance::class, 'writeHeartbeat');
        $method->invoke($maintenance, 1, 0, 0, 0, 0, ['at_ms' => 1]);
        self::assertSame((string) $this->redis->get('audit:execution:heartbeat'), $this->meta()['at_ms'] ?? null,
            'extraMeta no debe sobrescribir at_ms.');
    }

    /**
     * T-I8 (CA-F1-06, INV-F1-04), con carga: 3.000 efectos de outbox + 1.500 no coincidentes.
     * Cota aplicada: tick_duration_ms ≤ TICK_BUDGET_MS + máxima llamada Redis + margen de llamadas fijas.
     * La descomposición en U_outbox, U_intake_forzada, G y F requiere marcas de fase en producción (pendiente).
     */
    public function testTickDurationStaysWithinDeadlineBoundUnderLoad(): void
    {
        $this->seedTickLoad();

        $this->assertTickWithinDeadlineBound();
    }

    /**
     * Variante sin GC de T-I8: gc:cursors.last_gc_ms = nowMs() ⇒ el GC no es elegible en el tick (G = 0 en el término).
     */
    public function testTickDurationStaysWithinDeadlineBoundWithoutGc(): void
    {
        $this->seedTickLoad();
        $this->redis->eval('return redis.call("HSET",KEYS[1],"last_gc_ms",ARGV[1])',
            [AuditExecutionMaintenance::GC_CURSORS_KEY], [(string) $this->nowMs()]);

        $this->assertTickWithinDeadlineBound();
    }

    /** T-I9 (CA-F1-11): 450 efectos de outbox se publican en un solo tick. */
    public function testOneTickPublishesFourHundredFiftyOutboxEffects(): void
    {
        $this->seedOutboxEffects(450);

        (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)))->tick();

        self::assertSame(0, $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]));
    }

    /**
     * T-I10 (CA-F1-08, W-F1-B1, W-F1-M5): 1.500 no coincidentes y 50 coincidentes en el stream del grupo
     * extractors; policy se crea en `$` tras sembrar y no confirma nada. El RecordingRedis cuenta solo las
     * llamadas del grupo extractors.
     */
    public function testIntakeAcksNonMatchingBatchesWithoutStarvingHead(): void
    {
        $stream = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
        $this->seedDocumentsBatch(0);
        $calls = self::newCallCounter();
        $wrapper = new RecordingRedis($this->redis, static function (string $script, array $keys, array $argv) use (&$calls): void {
            self::countExtractorCall($script, $argv, $calls);
        });

        (new AuditExecutionMaintenance($wrapper, new AuditExecutionConfig(2)))->tick();

        self::assertLessThanOrEqual(8, $calls['xreadgroup'], '≤ 8 XREADGROUP de extractors (la 8.ª ronda, con 150 < 200, corta).');
        self::assertLessThanOrEqual(8, $calls['xack'], '≤ 8 XACK-lote de extractors.');
        self::assertSame(50, $calls['admit'], 'Exactamente 50 admisiones de extractors.');
        self::assertSame('1500', $this->meta()['intake_acked'] ?? null);
        self::assertSame(0, $this->pendingCount($stream, 'extractors'));
        self::assertSame(0, $this->pendingCount($stream, 'policy'));
        self::assertSame(0, $this->groupLag($stream, 'extractors'), 'XINFO GROUPS extractors: lag = 0.');
        self::assertSame(0, $this->groupLag($stream, 'policy'), 'XINFO GROUPS policy: lag = 0.');
        self::assertSame(50, $this->queueMetrics()['extraction']['batch']['queued'], '50 tareas en cola extraction:batch.');
    }

    /**
     * T-I10 variante de latencia (CA-F1-12): usleep(20000) antes de cada llamada Redis y las 200 primeras
     * entradas no coincidentes. Debe haber ≥ 1 ronda XREADGROUP de extractors y intake_acked ≥ 200, aunque el
     * deadline venza durante el tick.
     */
    public function testIntakeLatencyVariantAcksLeadingNonMatchingWithinDeadline(): void
    {
        $this->seedDocumentsBatch(200);
        $calls = self::newCallCounter();
        $wrapper = new RecordingRedis(
            $this->redis,
            static function (string $script, array $keys, array $argv) use (&$calls): void {
                self::countExtractorCall($script, $argv, $calls);
            },
            null,
            static function (): void {
                usleep(20000);
            }
        );

        (new AuditExecutionMaintenance($wrapper, new AuditExecutionConfig(2)))->tick();

        self::assertGreaterThanOrEqual(1, $calls['xreadgroup'], 'La ronda forzada de extractors debe ejecutarse.');
        self::assertGreaterThanOrEqual(200, (int) ($this->meta()['intake_acked'] ?? 0));
    }

    /**
     * T-I11 (CA-F1-08, INV-F1-05, H-06): capacidad 10 y 50 coincidentes ⇒ 10 admitidos y 40 en PEL; tras liberar
     * capacidad y forzar IDLE ≥ 5 s, un tick posterior reclama los 40 vía XAUTOCLAIM y los admite.
     */
    public function testCapacityLimitLeavesRemainderInPendingList(): void
    {
        $stream = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
        $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM")', [$stream], ['extractors']);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        for ($i = 0; $i < 50; $i++) {
            $auditId = self::uuidFor('cap-' . $i);
            $this->seedAuditState($auditId, 'processing', $now);
            $this->xadd($stream, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $auditId));
        }
        // 90 tareas ya en cola de extraction (batch) con maxQueued = 100 ⇒ capacidad = min(100, 100 - 90) = 10.
        $this->seedWaitingHead('extraction', 'batch');
        $this->redis->eval('return redis.call("HINCRBY",KEYS[1],ARGV[1],90)', [FairAuditTaskQueue::COUNTS], ['extraction:batch:pending']);

        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2, 100));
        $maintenance->tick();

        self::assertSame(40, $this->pendingCount($stream, 'extractors'));
        self::assertSame(10, $this->tasksInAll(), 'Solo 10 tareas admitidas en el primer tick.');

        // Libera la capacidad (cola vaciada) y fuerza IDLE 6000 en la PEL para que XAUTOCLAIM (5000 ms) la reclame.
        $this->redis->eval('return redis.call("HSET",KEYS[1],ARGV[1],0)', [FairAuditTaskQueue::COUNTS], ['extraction:batch:pending']);
        $this->forceIdle($stream, 'extractors', 6000);

        $maintenance->tick();

        self::assertSame(0, $this->pendingCount($stream, 'extractors'), 'Ninguna entrada coincidente debe quedar en PEL.');
        self::assertSame(50, $this->tasksInAll(), 'Las 50 coincidentes deben terminar admitidas, sin pérdidas ni duplicados.');
    }

    /** T-I12 (CA-F1-13, REQ-F1-12): expireLegacyIdempotency fija TTL legado; gc amplía con GT si el job vive. */
    public function testLegacyIdempotencyKeysGetTtlAndLiveJobsExtendRetention(): void
    {
        $legacy = BatchJobStore::idempotencyKey('legacy-1');
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$legacy], ['job-legacy']);
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));

        $first = $maintenance->expireLegacyIdempotency();
        $ttl = (int) $this->redis->eval('return redis.call("TTL",KEYS[1])', [$legacy]);
        self::assertSame(1, $first['expired']);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(AuditExecutionMaintenance::LEGACY_IDEM_TTL_SECONDS, $ttl);
        self::assertSame(0, $maintenance->expireLegacyIdempotency()['expired']);

        $live = BatchJobStore::idempotencyKey('live-1');
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$live], ['job-live']);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [BatchJobStore::jobKey('job-live')],
            ['{"job_id":"job-live","status":"processing"}']);
        $this->redis->eval('return redis.call("EXPIRE",KEYS[1],100)', [$live]);
        $maintenance->gc(60000, $this->nowMs());

        self::assertGreaterThanOrEqual(86400 - 5, (int) $this->redis->eval('return redis.call("TTL",KEYS[1])', [$live]));
    }

    /**
     * T-I13 (CA-F1-16, H-01): dentro de una etapa, .priority consume la capacidad compartida antes que .batch,
     * con cada valor inicial de intakeOffset (0..3).
     */
    #[DataProvider('intakeOffsets')]
    public function testPriorityStreamConsumesSharedCapacityFirst(int $offset): void
    {
        $priority = AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY;
        $batch = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
        foreach ([$priority, $batch] as $stream) {
            $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM")', [$stream], ['extractors']);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        for ($i = 0; $i < 20; $i++) {
            $priorityAudit = self::uuidFor('p-' . $i);
            $batchAudit = self::uuidFor('b-' . $i);
            $this->seedAuditState($priorityAudit, 'processing', $now);
            $this->seedAuditState($batchAudit, 'processing', $now);
            // is_priority = true es la única señal que fija el carril priority (AuditEventPublisher::isPriorityEvent).
            $this->xadd($priority, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $priorityAudit, null, null, ['is_priority' => true]));
            $this->xadd($batch, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $batchAudit));
        }
        $this->seedWaitingHead('extraction', 'priority');
        $this->redis->eval('return redis.call("HINCRBY",KEYS[1],ARGV[1],90)', [FairAuditTaskQueue::COUNTS], ['extraction:priority:pending']);

        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2, 100));
        (new \ReflectionProperty(AuditExecutionMaintenance::class, 'intakeOffset'))->setValue($maintenance, $offset);
        $maintenance->tick();

        self::assertSame('10', $this->meta()['intake_admitted'] ?? null, 'Los 10 cupos se admiten.');
        self::assertSame(10, $this->tasksInAll());
        $metrics = $this->queueMetrics();
        self::assertSame(100, $metrics['extraction']['priority']['queued'], '90 previas + 10 admitidas del carril priority.');
        self::assertSame(0, $metrics['extraction']['batch']['queued'], 'Ninguna tarea del carril batch.');
        self::assertSame(10, $this->pendingCount($priority, 'extractors'), 'Las 10 restantes de .priority quedan en PEL.');
        self::assertSame(0, $this->pendingCount($batch, 'extractors'), '.batch no debe recibir capacidad.');
    }

    public static function intakeOffsets(): iterable
    {
        foreach ([0, 1, 2, 3] as $offset) {
            yield 'offset ' . $offset => [$offset];
        }
    }

    /**
     * T-I14 (CA-F1-03, DEC-F1-04, H-07): una excepción en la última clave deja pattern_index avanzado y las
     * siguientes instancias reanudan por el patrón siguiente; pending_pattern se castea (int) o descarta (no numérico).
     */
    public function testExceptionOnLastKeyAdvancesPatternAndInvalidPendingIsDiscarded(): void
    {
        $corrupt = AuditStateStore::auditKey('corrupt-' . AuditEvent::uuidV4());
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$corrupt], ['{invalido']);
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));

        // 1.ª instancia: `audits` (patrón 2) lanza en su última clave ⇒ pattern_index avanza a 3 (una caída por ciclo).
        self::assertTrue($this->gcThrows($maintenance), 'El estado corrupto debe provocar una excepción en la última clave.');
        self::assertSame('3', $this->cursorField('pattern_index'));
        self::assertSame('[]', $this->cursorField('pending_keys'));

        // 2.ª instancia con $corrupt aún presente: gemini_attempts (3) se procesa antes y borra su clave;
        // después `audits` vuelve a lanzar como 7.º patrón del ciclo.
        $attempt = 'gemini:attempt:v2:' . AuditEvent::uuidV4();
        $attemptRaw = json_encode([
            'attempt_id' => 'attempt-' . AuditEvent::uuidV4(), 'state' => 'completed',
            'task_id' => str_repeat('a', 64), 'quota_base' => 'q1',
        ], JSON_THROW_ON_ERROR);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$attempt], [$attemptRaw]);
        self::assertTrue($this->gcThrows(new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2))));
        self::assertNull($this->redis->get($attempt), 'gemini_attempts debe borrarse antes de volver a audits.');
        self::assertSame('3', $this->cursorField('pattern_index'));

        // Variante int: pending_pattern = '2' con pending_keys válidas ⇒ tras loadGcState() es int y se procesan.
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [$corrupt]);
        $expiredId = AuditEvent::uuidV4();
        $expiredKey = AuditStateStore::auditKey($expiredId);
        $this->seedAuditState($expiredId, 'completed', gmdate('Y-m-d\TH:i:s\Z', time() - 864000));
        $this->redis->eval('return redis.call("HSET",KEYS[1],"pattern_index",ARGV[1],"pending_pattern",ARGV[2],"pending_keys",ARGV[3])',
            [AuditExecutionMaintenance::GC_CURSORS_KEY], ['2', '2', json_encode([$expiredKey], JSON_THROW_ON_ERROR)]);
        $loadGcState = new \ReflectionMethod(AuditExecutionMaintenance::class, 'loadGcState');
        $state = $loadGcState->invoke(new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)));
        self::assertSame(2, $state['pending_pattern'], 'pending_pattern "2" se castea a int.');
        self::assertSame([$expiredKey], $state['pending_keys']);
        (new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)))->gc(60000, $this->nowMs());
        self::assertNull($this->redis->get($expiredKey), 'La nueva instancia procesa las pending_keys del patrón 2.');

        // Variante inválida: pending_pattern = 'x' ⇒ pending descartadas (observable en loadGcState()).
        $otherId = AuditEvent::uuidV4();
        $this->seedAuditState($otherId, 'completed', gmdate('Y-m-d\TH:i:s\Z', time() - 864000));
        $this->redis->eval('return redis.call("HSET",KEYS[1],"pattern_index",ARGV[1],"pending_pattern",ARGV[2],"pending_keys",ARGV[3])',
            [AuditExecutionMaintenance::GC_CURSORS_KEY], ['2', 'x', json_encode([AuditStateStore::auditKey($otherId)], JSON_THROW_ON_ERROR)]);
        $state = $loadGcState->invoke(new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2)));
        self::assertNull($state['pending_pattern'], 'pending_pattern no numérico se descarta.');
        self::assertSame([], $state['pending_keys'], 'pending_keys se descartan con pending_pattern inválido.');
    }

    /** T-I15 (REQ-F1-13, P-F1-01): banderas de pausa visibles en heartbeat:meta y aviso con throttling. */
    public function testPauseFlagsAreVisibleInHeartbeatMetaAndWarnOnce(): void
    {
        $maintenance = new AuditExecutionMaintenance($this->redis, new AuditExecutionConfig(2));
        $maintenance->tick();
        self::assertSame('0', $this->meta()['admission_paused'] ?? null);
        self::assertSame('0', $this->meta()['execution_paused'] ?? null);

        $this->redis->eval('return redis.call("SET",KEYS[1],"1")', ['audit:admission:paused']);
        $maintenance->tick();
        self::assertSame('1', $this->meta()['admission_paused'] ?? null);
        self::assertSame('0', $this->meta()['execution_paused'] ?? null);
        $property = new \ReflectionProperty(AuditExecutionMaintenance::class, 'lastAdmissionPausedWarnMs');
        $firstWarn = $property->getValue($maintenance);
        self::assertGreaterThan(0, $firstWarn);

        $this->redis->eval('return redis.call("SET",KEYS[1],"1")', ['audit:execution:paused']);
        $maintenance->tick();
        self::assertSame('1', $this->meta()['execution_paused'] ?? null);
        self::assertSame('0', $this->meta()['intake_acked'] ?? null);
        self::assertSame($firstWarn, $property->getValue($maintenance), 'Sin 5 min transcurridos no hay segundo aviso.');

        $this->redis->eval('return redis.call("DEL",KEYS[1],KEYS[2])', ['audit:admission:paused', 'audit:execution:paused']);
        $maintenance->tick();
        self::assertSame('0', $this->meta()['admission_paused'] ?? null);
        self::assertSame('0', $this->meta()['execution_paused'] ?? null);
        self::assertSame(0, $property->getValue($maintenance));
    }

    /** Carga común de T-I8: 3.000 efectos de outbox y 1.500 entradas no coincidentes en documents.batch. */
    private function seedTickLoad(): void
    {
        $this->seedOutboxEffects(3000);
        $stream = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
        $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM")', [$stream], ['extractors']);
        for ($i = 0; $i < 1500; $i++) {
            $this->xadd($stream, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_REGISTERED, AuditEvent::uuidV4()));
        }
    }

    private function assertTickWithinDeadlineBound(): void
    {
        $maxCallMs = 0.0;
        $wrapper = new RecordingRedis($this->redis, null, static function (float $ms) use (&$maxCallMs): void {
            $maxCallMs = max($maxCallMs, $ms);
        });
        (new AuditExecutionMaintenance($wrapper, new AuditExecutionConfig(2)))->tick();

        $duration = (int) ($this->meta()['tick_duration_ms'] ?? PHP_INT_MAX);
        self::assertLessThanOrEqual(
            AuditExecutionMaintenance::TICK_BUDGET_MS + (int) ceil($maxCallMs) + 1000,
            $duration
        );
    }

    /**
     * Stream documents.batch del grupo extractors: 1.500 document_registered y 50 document_downloaded
     * (coincidentes). Los primeros $leading eventos son no coincidentes. policy se crea en `$` tras sembrar:
     * no recibe entradas (W-F1-B1).
     */
    private function seedDocumentsBatch(int $leading): void
    {
        $stream = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;
        $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM")', [$stream], ['extractors']);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        for ($i = 0; $i < 1500; $i++) {
            $this->xadd($stream, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_REGISTERED, AuditEvent::uuidV4()));
            if ($i >= $leading && ($i - $leading) % 30 === 0) {
                $auditId = self::uuidFor('batch-' . $i);
                $this->seedAuditState($auditId, 'processing', $now);
                $this->xadd($stream, AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $auditId));
            }
        }
        $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],ARGV[1],"$")', [$stream], ['policy']);
    }

    /** @return array{xreadgroup:int,xack:int,admit:int} */
    private static function newCallCounter(): array
    {
        return ['xreadgroup' => 0, 'xack' => 0, 'admit' => 0];
    }

    /**
     * Clasifica una llamada Redis del intake del grupo extractors. ADMIT se reconoce por `audit_state_missing`,
     * XREADGROUP por su literal y el XACK-lote por `unpack(ARGV,2)`; en los tres, argv[0] o argv[2] es el grupo.
     *
     * @param array{xreadgroup:int,xack:int,admit:int} $calls
     */
    private static function countExtractorCall(string $script, array $argv, array &$calls): void
    {
        if (str_contains($script, 'audit_state_missing')) {
            if (($argv[2] ?? null) === 'extractors') {
                $calls['admit']++;
            }
            return;
        }
        if (($argv[0] ?? null) !== 'extractors') {
            return;
        }
        if (str_contains($script, 'XREADGROUP')) {
            $calls['xreadgroup']++;
        } elseif (str_contains($script, 'unpack(ARGV,2)')) {
            $calls['xack']++;
        }
    }

    /** Siembra un único elemento en el índice :waiting de (stage, lane), requerido por FairAuditTaskQueue::metrics(). */
    private function seedWaitingHead(string $stage, string $lane): void
    {
        $this->redis->eval('return redis.call("ZADD",KEYS[1],ARGV[1],ARGV[2])',
            [FairAuditTaskQueue::base($stage, $lane) . ':waiting'], [(string) $this->nowMs(), 'seed-waiting-head']);
    }

    /** Libera la PEL de $group en $stream forzando IDLE en las entradas pendientes (XCLAIM ... IDLE). */
    private function forceIdle(string $stream, string $group, int $idleMs): void
    {
        $this->redis->eval(<<<'LUA'
            local pending = redis.call('XPENDING', KEYS[1], ARGV[1], '-', '+', 1000)
            local ids = {}
            for _, entry in ipairs(pending) do ids[#ids + 1] = entry[1] end
            if #ids > 0 then
                redis.call('XCLAIM', KEYS[1], ARGV[1], 'forced-idle', 0, unpack(ids), 'IDLE', ARGV[2])
            end
            return #ids
            LUA, [$stream], [$group, (string) $idleMs]);
    }

    /** Lag de un grupo (XINFO GROUPS, Redis ≥ 7.0). Devuelve null si Redis no lo informa. */
    private function groupLag(string $stream, string $group): ?int
    {
        $lag = $this->redis->eval(<<<'LUA'
            local groups = redis.call('XINFO', 'GROUPS', KEYS[1])
            for _, g in ipairs(groups) do
                local name, lag
                for i = 1, #g, 2 do
                    if g[i] == 'name' then name = g[i + 1] elseif g[i] == 'lag' then lag = g[i + 1] end
                end
                if name == ARGV[1] then return lag end
            end
            return nil
            LUA, [$stream], [$group]);

        return $lag === null || $lag === false ? null : (int) $lag;
    }

    /** @return array<string,array<string,array{queued:int,active:int,delayed:int,oldest_wait_ms:int}>> */
    private function queueMetrics(): array
    {
        return (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->metrics();
    }

    private function tasksInAll(): int
    {
        return (int) $this->redis->eval('return redis.call("SCARD",KEYS[1])', [FairAuditTaskQueue::ALL]);
    }

    /** Ejecuta gc() y devuelve true si lanzó; nunca usa assert dentro del try para no tragarse fallos propios. */
    private function gcThrows(AuditExecutionMaintenance $maintenance): bool
    {
        try {
            $maintenance->gc(60000, $this->nowMs());
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function nowMs(): int
    {
        return (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->nowMs();
    }

    /** UUID v4 determinista para etiquetas legibles (AuditEvent exige UUID v4 en audit_id). */
    private static function uuidFor(string $label): string
    {
        $hex = md5($label);

        return sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3),
            substr($hex, 17, 3), substr($hex, 20, 12));
    }

    private function seedAuditState(string $auditId, string $status, string $completedAt, int $padBytes = 0): string
    {
        $raw = json_encode([
            'audit_id' => $auditId, 'status' => $status, 'completed_at' => $completedAt, 'updated_at' => $completedAt,
            'dis_det_nro' => '1', 'execution_version' => 2, 'pad' => str_repeat('x', $padBytes),
        ], JSON_THROW_ON_ERROR);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey($auditId)], [$raw]);

        return $raw;
    }

    /** Tarea terminal (completed) y auditoría terminal asociada; $padBytes se aplica a la auditoría. */
    private function seedTerminalTask(int $index, string $auditId, string $completedAt, int $padBytes = 0): void
    {
        $this->seedAuditState($auditId, 'failed', $completedAt, $padBytes);
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, $auditId);
        $record = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event)->record;
        $record['task_id'] = hash('sha256', 'task-' . $index);
        $record['audit_id'] = $auditId;
        $record['state'] = 'completed';
        $record['fence'] = 1;
        $key = FairAuditTaskQueue::taskKey($record['task_id']);
        $this->redis->eval('return redis.call("HSET",KEYS[1],"record",ARGV[1])', [$key], [json_encode($record, JSON_THROW_ON_ERROR)]);
    }

    private function seedOutboxEffects(int $count): void
    {
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4());
        $task = AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event);
        for ($i = 0; $i < $count; $i++) {
            $followUp = AuditTaskEffects::followUp($task, AuditEvent::TYPE_DOCUMENT_REGISTERED,
                ['dis_id' => '1', 'dis_det_nro' => '2'], 'doc-' . $i, 'doc-' . $i);
            $effect = AuditTaskEffects::event($task, $followUp, 'doc-' . $i);
            $this->redis->eval('return redis.call("HSET",KEYS[1],"record",ARGV[1])',
                ['audit:outbox:v2:' . $effect['effect_id']], [json_encode($effect, JSON_THROW_ON_ERROR)]);
            $this->redis->eval('return redis.call("ZADD",KEYS[1],ARGV[1],ARGV[2])', [AuditTaskCommitter::OUTBOX], [$i, $effect['effect_id']]);
        }
    }

    private function xadd(string $stream, AuditEvent $event): void
    {
        $this->redis->eval('return redis.call("XADD",KEYS[1],"*","event",ARGV[1])', [$stream], [$event->toJson()]);
    }

    private function pendingCount(string $stream, string $group): int
    {
        return (int) $this->redis->eval('return redis.call("XPENDING",KEYS[1],ARGV[1])[1]', [$stream], [$group]);
    }

    private function countKeys(string $pattern): int
    {
        $count = 0;
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->redis->scanKeys($pattern, $cursor, 100);
            $count += count($keys);
        } while ($cursor !== '0');

        return $count;
    }

    /** @return array<string,string> */
    private function meta(): array
    {
        $raw = $this->redis->eval('return redis.call("HGETALL",KEYS[1])', [AuditExecutionMaintenance::HEARTBEAT_META_KEY]);
        $fields = [];
        for ($i = 0; is_array($raw) && $i < count($raw); $i += 2) {
            $fields[(string) $raw[$i]] = (string) $raw[$i + 1];
        }

        return $fields;
    }

    private function cursorField(string $field): ?string
    {
        $value = $this->redis->eval('return redis.call("HGET",KEYS[1],ARGV[1])', [AuditExecutionMaintenance::GC_CURSORS_KEY], [$field]);

        return $value === null ? null : (string) $value;
    }
}

/**
 * Envoltorio de RedisClient que delega en la conexión aislada del test y permite observar las llamadas eval
 * (ARGV de cada script), medir su duración e inyectar latencia antes de cada llamada Redis.
 */
class RecordingRedis extends RedisClient
{
    /**
     * @param IsolatedAuditRedis                         $connection conexión aislada del test
     * @param (\Closure(string,array,array):void)|null   $after      observador tras cada eval
     * @param (\Closure(float):void)|null                $timer      recibe la duración en ms de cada eval
     * @param (\Closure():void)|null                     $before     se ejecuta antes de cada llamada Redis
     */
    public function __construct(
        private readonly IsolatedAuditRedis $connection,
        private readonly ?\Closure $after = null,
        private readonly ?\Closure $timer = null,
        private readonly ?\Closure $before = null
    ) {
    }

    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        $this->beforeCall();
        $start = hrtime(true);
        $result = $this->connection->eval($script, $keys, $argv);
        if ($this->timer !== null) {
            ($this->timer)((hrtime(true) - $start) / 1e6);
        }
        if ($this->after !== null) {
            ($this->after)($script, $keys, $argv);
        }

        return $result;
    }

    public function get(string $key): ?string
    {
        $this->beforeCall();

        return $this->connection->get($key);
    }

    public function scanKeys(string $pattern, string $cursor = '0', int $count = 100): array
    {
        $this->beforeCall();

        return $this->connection->scanKeys($pattern, $cursor, $count);
    }

    public function serverInfo(string $section): array
    {
        return $this->connection->serverInfo($section);
    }

    public function serverConfiguration(array $names): array
    {
        return $this->connection->serverConfiguration($names);
    }

    private function beforeCall(): void
    {
        if ($this->before !== null) {
            ($this->before)();
        }
    }
}
