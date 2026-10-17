<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDocumentCache;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditDocumentCacheTest extends TestCase
{
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
