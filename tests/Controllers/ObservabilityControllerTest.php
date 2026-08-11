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
    public function testAsyncMetricsUsesPendingEntriesNotXLen(): void
    {
        $redis = $this->createMock(RedisClient::class);

        // xPending retorna pendientes reales por (stream, group)
        $redis->method('xPending')->willReturnMap([
            [AuditEventPublisher::STREAM_INBOX, 'orchestrator', 1],
            [AuditEventPublisher::STREAM_DOCUMENTS, 'downloaders', 2],
            [AuditEventPublisher::STREAM_DOCUMENTS, 'extractors', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS, 'normalizers', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS, 'policy', 0],
            [AuditEventPublisher::STREAM_PERSISTENCE, 'persistence', 5],
            [AuditEventPublisher::STREAM_BATCH_INBOX, 'batch-workers', 4],
        ]);
        // DLQ sigue usando xLen (no tiene consumer group)
        $redis->method('xLen')->willReturn(6);
        $redis->method('hGetAll')->willReturn([]);
        $controller = new TestableObservabilityController($redis);

        $response = self::captureResponse(
            static fn() => $controller->asyncMetrics()
        );
        $data = $response->getData()['data'];

        $this->assertSame(200, $response->getCode());
        $this->assertSame(5, $data['streamDepths']['persistence']);
        $this->assertSame(2, $data['streamDepths']['documents']);
        $this->assertSame(12, $data['queueDepth']); // 1+2+5+0+4
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
