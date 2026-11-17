<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentPdfRasterizer;
use App\Services\Audit\Pipeline\ExtractionCacheManager;
use Core\RedisClient;
use Core\RedisUnavailableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExtractionCacheManagerTest extends TestCase
{
    public function testComputeCacheKeyIncludesMaxPagesAndValidatesHashes(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $manager10 = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $manager20 = new ExtractionCacheManager($redis, 3600, 'v1', 20);

        $key10 = $manager10->computeCacheKey('docHash', 'contractHash', 'promptHash');
        $key20 = $manager20->computeCacheKey('docHash', 'contractHash', 'promptHash');

        $this->assertNotSame($key10, $key20, 'Claves deben diferir según el límite maxPages');
        $this->assertStringStartsWith('extraction:cache:v1:', $key10);

        $expectedHash = hash('sha256', 'docHashcontractHashpromptHashv1:maxp:10');
        $this->assertSame('extraction:cache:v1:' . $expectedHash, $key10);

        $this->expectException(RuntimeException::class);
        $manager10->computeCacheKey('docHash', '', 'promptHash');
    }

    public function testPutStoresEnvelopeWithCertifiedPageCount(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                'test_key',
                json_encode(['extracted' => ['field' => 'value'], 'page_count' => 3], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                3600
            )
            ->willReturn(true);

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $manager->put('test_key', ['field' => 'value'], 3);
    }

    public function testPutThrowsWhenPageCountIsLessThanOne(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pageCount debe ser >= 1');
        $manager->put('test_key', ['field' => 'value'], 0);
    }

    public function testPutThrowsWhenRedisWriteFails(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('set')->willReturn(false);

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo escribir extraction cache en Redis');
        $manager->put('test_key', ['field' => 'value'], 1);
    }

    public function testGetReturnsNullOnCacheMiss(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(null);

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $this->assertNull($manager->get('test_key'));
    }

    public function testGetReturnsNullOnLegacyPayloadWithoutPageCount(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(json_encode(['field' => 'value']));

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $this->assertNull($manager->get('test_key'), 'Payload legacy sin envelope debe considerarse cache miss');
    }

    public function testGetReturnsNullWhenPageCountExceedsMaxPages(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(json_encode([
            'extracted'  => ['field' => 'value'],
            'page_count' => 15,
        ]));

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $this->assertNull($manager->get('test_key'), 'Entrada en caché con más páginas que maxPages debe invalidarse');
    }

    public function testGetReturnsExtractedAndPageCountWhenValid(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willReturn(json_encode([
            'extracted'  => ['field' => 'value'],
            'page_count' => 5,
        ]));

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);
        $result = $manager->get('test_key');

        $this->assertNotNull($result);
        $this->assertSame(['field' => 'value'], $result['extracted']);
        $this->assertSame(5, $result['page_count']);
    }

    public function testGetThrowsWhenRedisIsUnavailable(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('get')->willThrowException(new RedisUnavailableException('Connection refused'));

        $manager = new ExtractionCacheManager($redis, 3600, 'v1', 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis no disponible al leer extraction cache');
        $manager->get('test_key');
    }

    public function testConstructorClampsOutOfRangeMaxPagesConsistentlyWithRasterizer(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $managerZero = new ExtractionCacheManager($redis, 3600, 'v1', 0);
        $managerOne = new ExtractionCacheManager($redis, 3600, 'v1', 1);
        $managerOver = new ExtractionCacheManager($redis, 3600, 'v1', 500);
        $managerMax = new ExtractionCacheManager($redis, 3600, 'v1', 100);
        $managerNeg = new ExtractionCacheManager($redis, 3600, 'v1', -5);
        $managerDefault = new ExtractionCacheManager($redis, 3600, 'v1', 10);

        $this->assertSame(
            $managerOne->computeCacheKey('doc', 'contract', 'prompt'),
            $managerZero->computeCacheKey('doc', 'contract', 'prompt')
        );
        $this->assertSame(
            $managerMax->computeCacheKey('doc', 'contract', 'prompt'),
            $managerOver->computeCacheKey('doc', 'contract', 'prompt')
        );
        $this->assertSame(
            $managerDefault->computeCacheKey('doc', 'contract', 'prompt'),
            $managerNeg->computeCacheKey('doc', 'contract', 'prompt')
        );
    }
}
