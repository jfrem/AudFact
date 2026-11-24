<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditDocumentCache;
use App\Services\Audit\Pipeline\AuditEvent;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class AuditDocumentCacheRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private string $namespace;
    private const BUDGET = 'audit:document-cache:{budget}';

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone.');
        }
        $this->namespace = 'integration:audit:' . AuditEvent::uuidV4() . ':';
        $this->redis = new IsolatedAuditRedis($this->namespace);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testPriorityLaneSaturationDoesNotAffectBatchLane(): void
    {
        // Arrange: 25 % prioritario de 100 000 bytes y 100 entradas => 25 entradas prioritarias.
        $base = new AuditDocumentCache($this->redis, 100000, 100);
        $priority = $base->forLane(\App\Services\Audit\Pipeline\AuditLane::PRIORITY);
        $batch = $base->forLane(\App\Services\Audit\Pipeline\AuditLane::BATCH);
        for ($i = 0; $i < 23; $i++) {
            $priority->put("audit:blob:p{$i}", ['mime' => 'application/pdf', 'data' => 'a']);
        }
        $noopRecord = static function (): void {};
        $priorityGate = new \App\Services\Audit\Pipeline\AuditDocumentPrefetchGate($priority, $noopRecord, static fn(): int => 0);
        $batchGate = new \App\Services\Audit\Pipeline\AuditDocumentPrefetchGate($batch, $noopRecord, static fn(): int => 0);

        // Act / Assert: INV-F3-01, el carril prioritario cierra y el batch sigue abierto.
        self::assertFalse($priorityGate->canRead());
        self::assertTrue($batchGate->canRead());
    }

    public function testReleaseFallsBackToLegacyIndexWhenLaneIndexMissesEntry(): void
    {
        // Arrange: blob escrito con el índice heredado (previo al despliegue).
        $legacy = new AuditDocumentCache($this->redis, 1000, 100);
        $legacy->put('audit:blob:legacy', ['mime' => 'application/pdf', 'data' => 'AAAA']);

        // Act:
        $legacy->release('audit:blob:legacy', \App\Services\Audit\Pipeline\AuditLane::PRIORITY);

        // Assert: CA-F3-02 y INV-F3-03.
        self::assertNull($legacy->read('audit:blob:legacy'));
        self::assertSame(0, (int) $this->redis->get(self::BUDGET . ':bytes'));
    }

    public function testUsageReportsOldestAgeAndTtl(): void
    {
        // Arrange: TTL 100 s; dos blobs con edades distintas fijadas por el score (expiración) del índice.
        $ttlMs = 100000;
        $cache = new AuditDocumentCache($this->redis, 100000, 100, 100);
        $cache->put('audit:blob:old', ['mime' => 'application/pdf', 'data' => 'AAAA']);
        $cache->put('audit:blob:young', ['mime' => 'application/pdf', 'data' => 'BBBB']);
        $this->setBlobAgeMs('audit:blob:old', 40000, $ttlMs);
        $this->setBlobAgeMs('audit:blob:young', 10000, $ttlMs);

        // Act:
        $first = $cache->usage();
        $this->setBlobAgeMs('audit:blob:old', 55000, $ttlMs);
        $second = $cache->usage();
        $this->redis->eval('return redis.call("ZREM",KEYS[2],KEYS[1])', ['audit:blob:old', self::BUDGET . ':expires']);
        $afterRemoval = $cache->usage();

        // Assert: R-F3-05, la edad es la del blob MÁS antiguo (no 0, no el TTL, no la fórmula invertida).
        self::assertSame($ttlMs, $first['ttl_ms']);
        self::assertSame(2, $first['reserved_entries']);
        self::assertGreaterThanOrEqual(40000, $first['oldest_age_ms']);
        self::assertLessThan(45000, $first['oldest_age_ms']);
        self::assertGreaterThanOrEqual(55000, $second['oldest_age_ms']);
        self::assertLessThan(60000, $second['oldest_age_ms']);
        self::assertGreaterThan($first['oldest_age_ms'], $second['oldest_age_ms']);
        self::assertGreaterThanOrEqual(10000, $afterRemoval['oldest_age_ms']);
        self::assertLessThan(15000, $afterRemoval['oldest_age_ms']);
    }

    public function testUsageReportsZeroAgeWhenIndexIsEmpty(): void
    {
        // Arrange:
        $cache = new AuditDocumentCache($this->redis, 100000, 100, 100);

        // Act:
        $usage = $cache->usage();

        // Assert:
        self::assertSame(0, $usage['reserved_entries']);
        self::assertSame(0, $usage['oldest_age_ms']);
    }

    public function testReleaseWithWrongLaneDeletesBlobImmediatelyAndLeavesLaneIndexUncorrected(): void
    {
        // Arrange: blob admitido en el carril batch, liberado por error con el carril prioritario; TTL largo.
        $document = ['mime' => 'application/pdf', 'data' => 'AAAA'];
        $size = strlen(json_encode($document, JSON_UNESCAPED_SLASHES));
        $base = new AuditDocumentCache($this->redis, 100000, 100, 3600);
        $batch = $base->forLane(\App\Services\Audit\Pipeline\AuditLane::BATCH);
        $batch->put('audit:blob:wrong', $document);
        self::assertSame(1, $this->zcard(self::BUDGET . ':batch:expires'));
        self::assertSame($size, (int) $this->redis->get(self::BUDGET . ':batch:bytes'));

        // Act:
        $base->release('audit:blob:wrong', \App\Services\Audit\Pipeline\AuditLane::PRIORITY);

        // Assert: INV-F3-03, el blob se elimina siempre y de inmediato (sin depender del TTL).
        self::assertNull($base->read('audit:blob:wrong'));
        // Según RELEASE_LUA el carril equivocado cae al índice heredado, que no lo contiene: el índice
        // batch NO se corrige (entrada y bytes persisten) y el heredado queda en 0.
        self::assertSame(1, $this->zcard(self::BUDGET . ':batch:expires'));
        self::assertSame($size, (int) $this->redis->get(self::BUDGET . ':batch:bytes'));
        self::assertSame(0, (int) $this->redis->get(self::BUDGET . ':bytes'));
        self::assertSame(1, $batch->usage()['reserved_entries']);
    }

    public function testWrongLaneReleaseResidueIsSelfHealedByPruneWhenReservationExpires(): void
    {
        // Arrange: residuo del escenario anterior; se vence la reserva forzando el score, sin esperas.
        $document = ['mime' => 'application/pdf', 'data' => 'AAAA'];
        $base = new AuditDocumentCache($this->redis, 100000, 100, 3600);
        $batch = $base->forLane(\App\Services\Audit\Pipeline\AuditLane::BATCH);
        $batch->put('audit:blob:wrong', $document);
        $base->release('audit:blob:wrong', \App\Services\Audit\Pipeline\AuditLane::PRIORITY);
        $before = $batch->usage();
        $this->redis->eval('return redis.call("ZADD",KEYS[2],0,KEYS[1])', ['audit:blob:wrong', self::BUDGET . ':batch:expires']);

        // Act:
        $after = $batch->usage();

        // Assert: INV-F3-04, prune() corrige entradas y bytes del carril.
        self::assertSame(1, $before['reserved_entries']);
        self::assertGreaterThan(0, $before['reserved_bytes']);
        self::assertSame(0, $after['reserved_entries']);
        self::assertSame(0, $after['reserved_bytes']);
        self::assertSame(0, (int) $this->redis->get(self::BUDGET . ':batch:bytes'));
    }

    /** Fija la edad de una reserva moviendo su score (expiración = ahora + TTL - edad). */
    private function setBlobAgeMs(string $blobKey, int $ageMs, int $ttlMs): void
    {
        $this->redis->eval(
            'local t = redis.call("TIME"); local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000); '
            . 'return redis.call("ZADD", KEYS[2], now + tonumber(ARGV[1]) - tonumber(ARGV[2]), KEYS[1])',
            [$blobKey, self::BUDGET . ':expires'],
            [$ttlMs, $ageMs]
        );
    }

    private function zcard(string $key): int
    {
        return (int) $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [$key]);
    }

    public function testMultipleConnectionsShareByteBudgetAndReleaseIsIdempotent(): void
    {
        // Arrange:
        $document = ['mime' => 'application/pdf', 'data' => str_repeat('a', 60)];
        $first = new AuditDocumentCache($this->redis, 150, 100);
        $second = new AuditDocumentCache(new IsolatedAuditRedis($this->namespace), 150, 100);

        // Act:
        $accepted = $first->put('audit:blob:a', $document);
        $denied = $second->put('audit:blob:b', $document);
        $first->release('audit:blob:a');
        $first->release('audit:blob:a');
        $reused = $second->put('audit:blob:b', $document);

        // Assert:
        self::assertTrue($accepted);
        self::assertFalse($denied);
        self::assertTrue($reused);
        self::assertNull($first->read('audit:blob:a'));
        self::assertSame($document, $second->read('audit:blob:b'));
        self::assertSame(strlen(json_encode($document, JSON_UNESCAPED_SLASHES)), (int) $this->redis->get(self::BUDGET . ':bytes'));
    }

    public function testVolumeCannotExceedEntryLimitAndOverwriteDoesNotDoubleCount(): void
    {
        // Arrange:
        $cache = new AuditDocumentCache($this->redis, 100000, 16);
        $document = ['mime' => 'application/pdf', 'data' => 'abc'];
        $accepted = 0;

        // Act:
        for ($i = 0; $i < 3000; $i++) {
            $accepted += (int) $cache->put('audit:blob:' . $i, $document);
        }
        $overwritten = $cache->put('audit:blob:0', ['mime' => 'application/pdf', 'data' => 'abcd']);
        $entries = $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [self::BUDGET . ':expires']);

        // Assert:
        self::assertSame(16, $accepted);
        self::assertTrue($overwritten);
        self::assertSame(16, $entries);
        self::assertLessThanOrEqual(100000, (int) $this->redis->get(self::BUDGET . ':bytes'));
        self::assertSame('abcd', $cache->read('audit:blob:0')['data']);
        self::assertNull($cache->read('audit:blob:2999'));
    }

    public function testExpiredReservationsAreReclaimedWithoutWaitingOrDeletingLiveEntries(): void
    {
        // Arrange:
        $cache = new AuditDocumentCache($this->redis, 1000, 2);
        $document = ['mime' => 'application/pdf', 'data' => 'abc'];
        $cache->put('audit:blob:expired', $document);
        $cache->put('audit:blob:live', $document);
        $this->redis->eval('redis.call("DEL",KEYS[1]); return redis.call("ZADD",KEYS[2],0,KEYS[1])',
            ['audit:blob:expired', self::BUDGET . ':expires']);

        // Act:
        $accepted = $cache->put('audit:blob:new', $document);

        // Assert:
        self::assertTrue($accepted);
        self::assertNull($cache->read('audit:blob:expired'));
        self::assertSame($document, $cache->read('audit:blob:live'));
        self::assertSame($document, $cache->read('audit:blob:new'));
        self::assertSame(2 * strlen(json_encode($document, JSON_UNESCAPED_SLASHES)), (int) $this->redis->get(self::BUDGET . ':bytes'));
    }

    public function testConcurrentProducersCannotOvercommitGlobalCapacity(): void
    {
        // Arrange:
        if (!function_exists('proc_open')) {
            self::markTestSkipped('La prueba concurrente requiere proc_open.');
        }
        $children = [];

        // Act:
        for ($producer = 0; $producer < 4; $producer++) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../Support/write-document-cache.php', $this->namespace, (string) $producer],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
            );
            if (!is_resource($process)) {
                self::fail('No se pudo iniciar productor de integración');
            }
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = [proc_close($process), $output];
        }
        $entries = $this->redis->eval('return redis.call("ZCARD",KEYS[1])', [self::BUDGET . ':expires']);

        // Assert:
        foreach ($results as [$exitCode, $output]) {
            self::assertSame(0, $exitCode, $output);
        }
        $actualBytes = $this->redis->eval('local size=0; for _,key in ipairs(redis.call("ZRANGE",KEYS[1],0,-1)) do size=size+redis.call("STRLEN",key) end; return size', [self::BUDGET . ':expires']);
        self::assertGreaterThan(0, $entries);
        self::assertLessThanOrEqual(32, $entries);
        self::assertLessThanOrEqual(4096, $actualBytes);
        self::assertSame($actualBytes, (int) $this->redis->get(self::BUDGET . ':bytes'));
        self::assertLessThanOrEqual(4096, (int) $this->redis->get(self::BUDGET . ':bytes'));
    }

    public function testUsageReclaimsExpiredReservationsWithoutRequiringAnotherDownload(): void
    {
        // Arrange:
        $cache = new AuditDocumentCache($this->redis, 1000, 2);
        $document = ['mime' => 'application/pdf', 'data' => 'fixture'];
        $cache->put('audit:blob:expired', $document);
        $cache->put('audit:blob:live', $document);
        $this->redis->eval('return redis.call("ZADD",KEYS[1],0,KEYS[2])',
            [self::BUDGET . ':expires', 'audit:blob:expired']);

        // Act:
        $usage = $cache->usage();

        // Assert:
        self::assertSame(1, $usage['reserved_entries']);
        self::assertSame(strlen(json_encode($document, JSON_UNESCAPED_SLASHES)), $usage['reserved_bytes']);
        self::assertNull($cache->read('audit:blob:expired'));
        self::assertSame($document, $cache->read('audit:blob:live'));
    }
}
