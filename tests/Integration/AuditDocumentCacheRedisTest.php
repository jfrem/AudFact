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
