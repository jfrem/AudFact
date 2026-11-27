<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDocumentCache;
use App\Services\Audit\Pipeline\AuditLane;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditDocumentCacheTest extends TestCase
{
    public function testUsageIncludesCurrentReservationsAndConfiguredLimits(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([900, 90, 0]);
        $cache = new AuditDocumentCache($redis, 1000, 100);

        // Act:
        $usage = $cache->usage();

        // Assert:
        self::assertSame(['reserved_bytes' => 900, 'reserved_entries' => 90, 'oldest_age_ms' => 0,
            'ttl_ms' => 3600000, 'max_bytes' => 1000, 'max_entries' => 100], $usage);
    }

    public function testInvalidUsageCannotOpenTheDownloadGate(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([-1, 0, 0]);
        $cache = new AuditDocumentCache($redis, 1000, 100);

        // Assert:
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('document_cache_usage_invalid');

        // Act:
        $cache->usage();
    }

    public function testOversizedDocumentBypassesRedisWithoutLosingSourceReference(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('eval')
            ->with($this->anything(), ['telemetry:document_cache'], $this->anything())->willReturn(1);
        $cache = new AuditDocumentCache($redis, maxBytes: 10);

        // Act:
        $stored = $cache->put('audit:blob:fixture', ['mime' => 'application/pdf', 'data' => 'AAAA']);

        // Assert:
        $this->assertFalse($stored);
    }

    public function testCapacityExhaustionIsAnExpectedCacheMiss(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn(0);
        $cache = new AuditDocumentCache($redis);

        // Act:
        $stored = $cache->put('audit:blob:fixture', ['mime' => 'application/pdf', 'data' => 'AAAA']);

        // Assert:
        $this->assertFalse($stored);
    }

    public function testMalformedRedisAdmissionResultCannotMasqueradeAsCapacityExhaustion(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn(null);
        $cache = new AuditDocumentCache($redis);

        // Act:
        $this->expectException(\RuntimeException::class);

        // Assert:
        $this->expectExceptionMessage('no confirmó la admisión');
        $cache->put('audit:blob:fixture', ['mime' => 'application/pdf', 'data' => 'AAAA']);
    }

    public function testForLaneSplitsBudgetAndNeverExceedsTotal(): void
    {
        // Arrange:
        $previous = (string) \Core\Env::get('AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT', '');
        \Core\Env::set('AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT', '25');
        try {
            $base = new AuditDocumentCache($this->createStub(RedisClient::class), 1000, 100);

            // Act:
            $priority = $base->forLane(AuditLane::PRIORITY)->configuration();
            $batch = $base->forLane(AuditLane::BATCH)->configuration();

            // Assert:
            self::assertSame(250, $priority['max_bytes']);
            self::assertSame(750, $batch['max_bytes']);
            self::assertSame(1000, $priority['max_bytes'] + $batch['max_bytes']);
            self::assertSame(25, $priority['max_entries']);
            self::assertSame(75, $batch['max_entries']);
        } finally {
            \Core\Env::set('AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT', $previous === '' ? null : $previous);
            \Core\Env::clearCache();
        }
    }

    public function testPutUsesOnlyTheIndexOfItsLane(): void
    {
        // Arrange:
        $calls = [];
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys) use (&$calls): int {
            $calls[] = $keys;
            return 1;
        });
        $cache = new AuditDocumentCache($redis, 1000, 100, 3600, AuditLane::PRIORITY);

        // Act:
        $cache->put('audit:blob:fixture', ['mime' => 'application/pdf', 'data' => 'AAAA']);

        // Assert: la admisión del BLOB usa solo el índice prioritario.
        self::assertContains(['audit:blob:fixture', 'audit:document-cache:{budget}:priority:expires',
            'audit:document-cache:{budget}:priority:sizes', 'audit:document-cache:{budget}:priority:bytes'], $calls);
    }

    public function testReleaseSendsLaneAndLegacyIndexesToTheScript(): void
    {
        // Arrange:
        $calls = [];
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys) use (&$calls): int {
            $calls[] = $keys;
            return 1;
        });
        $cache = new AuditDocumentCache($redis, 1000, 100);

        // Act:
        $cache->release('audit:blob:fixture', AuditLane::BATCH);

        // Assert: índice del carril primero y heredado como respaldo.
        self::assertContains(['audit:blob:fixture',
            'audit:document-cache:{budget}:batch:expires', 'audit:document-cache:{budget}:batch:sizes',
            'audit:document-cache:{budget}:batch:bytes',
            'audit:document-cache:{budget}:expires', 'audit:document-cache:{budget}:sizes',
            'audit:document-cache:{budget}:bytes'], $calls);
    }

    public function testUsageReportsOldestAgeAndTtl(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([10, 1, 1234]);
        $cache = new AuditDocumentCache($redis, 1000, 100, 60);

        // Act:
        $usage = $cache->usage();

        // Assert:
        self::assertSame(1234, $usage['oldest_age_ms']);
        self::assertSame(60000, $usage['ttl_ms']);
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsCannotCreateAnUnboundedCache(int $bytes, int $entries, int $ttl): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);

        // Act:
        $this->expectException(\InvalidArgumentException::class);

        // Assert:
        $this->expectExceptionMessage('deben ser positivos');
        new AuditDocumentCache($redis, $bytes, $entries, $ttl);
    }

    public static function invalidLimits(): iterable
    {
        yield 'zero bytes' => [0, 1, 1];
        yield 'zero entries' => [1, 0, 1];
        yield 'zero TTL' => [1, 1, 0];
        yield 'negative bytes' => [-1, 1, 1];
    }
}
