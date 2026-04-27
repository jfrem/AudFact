<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\ExtractionCache;
use Core\RedisClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExtractionCacheTest extends TestCase
{
    private MockObject&RedisClient $redis;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(RedisClient::class);
    }

    public function testGetReturnsDecodedPayload(): void
    {
        $hash = hash('sha256', 'doc-base64');
        $payload = ['fields' => ['NumeroFactura' => 'T38250701547']];

        $this->redis
            ->expects($this->once())
            ->method('get')
            ->with(ExtractionCache::key($hash))
            ->willReturn(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $cache = new ExtractionCache($this->redis, 120);

        $this->assertSame($payload, $cache->get($hash));
    }

    public function testPutPersistsPayloadWithConfiguredTtl(): void
    {
        $hash = hash('sha256', 'doc-base64');

        $this->redis
            ->expects($this->once())
            ->method('set')
            ->with(
                ExtractionCache::key($hash),
                $this->callback(function (string $json): bool {
                    $decoded = json_decode($json, true);
                    return isset($decoded['document_quality']) && $decoded['document_quality'] === 'legible';
                }),
                300
            )
            ->willReturn(true);

        $cache = new ExtractionCache($this->redis, 300);

        $this->assertTrue($cache->put($hash, [
            'fields' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
        ]));
    }

    public function testInvalidHashThrowsRuntimeException(): void
    {
        $cache = new ExtractionCache($this->redis, 120);

        $this->expectException(RuntimeException::class);
        $cache->get('bad-hash');
    }
}
