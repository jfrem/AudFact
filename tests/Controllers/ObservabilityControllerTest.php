<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\ObservabilityController;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\Exceptions\HttpResponseException;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class ObservabilityControllerTest extends TestCase
{
    public function testAsyncMetricsIncludesPersistenceQueueDepth(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('xLen')->willReturnMap([
            [AuditEventPublisher::STREAM_INBOX, 1],
            [AuditEventPublisher::STREAM_DOCUMENTS, 2],
            [AuditEventPublisher::STREAM_PERSISTENCE, 5],
            [AuditEventPublisher::STREAM_RESULTS, 3],
            [AuditEventPublisher::STREAM_BATCH_INBOX, 4],
            [AuditEventPublisher::dlqStream(), 6],
        ]);
        $redis->method('hGetAll')->willReturn([]);
        $controller = new TestableObservabilityController($redis);

        $response = self::captureResponse(
            static fn() => $controller->asyncMetrics()
        );
        $data = $response->getData()['data'];

        $this->assertSame(200, $response->getCode());
        $this->assertSame(5, $data['streamDepths']['persistence']);
        $this->assertSame(15, $data['queueDepth']);
        $this->assertSame(6, $data['deadLetterDepth']);
    }

    private static function captureResponse(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $response) {
            return $response;
        }

        self::fail('Se esperaba HttpResponseException');
    }
}

final class TestableObservabilityController extends ObservabilityController
{
    public function __construct(private RedisClient $redis)
    {
    }

    protected function buildRedisClient(): RedisClient
    {
        return $this->redis;
    }
}
