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

        $pendingByGroup = [
            [AuditEventPublisher::STREAM_INBOX_PRIORITY, 'orchestrator', 1],
            [AuditEventPublisher::STREAM_INBOX_BATCH, 'orchestrator', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, 'downloaders', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, 'extractors', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, 'normalizers', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, 'policy', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_BATCH, 'downloaders', 2],
            [AuditEventPublisher::STREAM_DOCUMENTS_BATCH, 'extractors', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_BATCH, 'normalizers', 0],
            [AuditEventPublisher::STREAM_DOCUMENTS_BATCH, 'policy', 0],
            [AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY, 'persistence', 0],
            [AuditEventPublisher::STREAM_PERSISTENCE_BATCH, 'persistence', 5],
            [AuditEventPublisher::STREAM_BATCH_INBOX, 'batch-workers', 4],
        ];
        $redis->expects($this->never())->method('xPending');
        $redis->expects($this->never())->method('xLen');
        $redis->expects($this->never())->method('hGetAll');
        $redis->method('eval')->willReturnCallback(
            static function (string $script, array $keys) use ($pendingByGroup): array {
                if (str_contains($script, 'ZRANGEBYSCORE')) {
                    return [1, 22, 1, 1];
                }
                if (str_contains($script, 'HGETALL')) {
                    return [6, []];
                }
                $groups = [];
                foreach ($pendingByGroup as [$stream, $group, $pending]) {
                    if ($stream === $keys[0]) {
                        $groups[] = ['name', $group, 'pending', $pending];
                    }
                }
                return $groups;
            }
        );
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
        $this->assertSame(['active' => 1, 'pending' => 22, 'scopes' => 1], $data['persistenceScheduler']);
        $this->assertSame(34, $data['backlogDepth']); // PEL 12 + scheduler pending 22; active no se duplica.
        $this->assertFalse($data['streamBacklogs']['inbox_priority']['orchestrator']['lagKnown']);
    }

    public function testReportsUnreadLagSeparatelyFromPendingEntries(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('xPending');
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys): array {
            if (str_contains($script, 'ZRANGEBYSCORE')) {
                return [1, 2, 1, 1];
            }
            if (str_contains($script, 'HGETALL')) {
                return [0, []];
            }
            return $keys[0] === AuditEventPublisher::STREAM_DOCUMENTS_BATCH
                ? [['name', 'extractors', 'pending', 0, 'lag', 11]] : [];
        });
        $controller = new TestableObservabilityController($redis);

        // Act:
        $response = self::captureResponse(static fn() => $controller->asyncMetrics());
        $data = $response->getData()['data'];

        // Assert:
        $this->assertSame(200, $response->getCode());
        $this->assertSame(0, $data['queueDepth']);
        $this->assertSame(13, $data['backlogDepth']);
        $this->assertSame(['pending' => 0, 'lag' => 11, 'lagKnown' => true],
            $data['streamBacklogs']['documents_batch']['extractors']);
    }

    public function testReturns503WhenRedisMetricsFail(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('Redis no disponible'));
        $controller = new TestableObservabilityController($redis);

        // Act:
        $response = self::captureResponse(static fn() => $controller->asyncMetrics());

        // Assert:
        $this->assertSame(503, $response->getCode());
        $this->assertFalse($response->getData()['success']);
    }

    public function testReadsPendingFromXinfoWithoutTheZeroFallback(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('xPending');
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys): array {
            if (str_contains($script, 'ZRANGEBYSCORE')) {
                return [0, 0, 0, 1];
            }
            if (str_contains($script, 'HGETALL')) {
                return [0, []];
            }
            return $keys[0] === AuditEventPublisher::STREAM_PERSISTENCE_BATCH
                ? [['name', 'persistence', 'pending', 7, 'lag', 0]] : [];
        });
        $controller = new TestableObservabilityController($redis);

        // Act:
        $response = self::captureResponse(static fn() => $controller->asyncMetrics());
        $data = $response->getData()['data'];

        // Assert:
        $this->assertSame(200, $response->getCode());
        $this->assertSame(7, $data['queueDepth']);
        $this->assertSame(7, $data['backlogDepth']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partialFailureCases')]
    public function testReturns503ForPartialFailures(string $fault): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys) use ($fault): array {
            if (str_contains($script, 'ZRANGEBYSCORE')) {
                if ($fault === 'scheduler') {
                    throw new \RuntimeException('Scheduler no disponible');
                }
                return [0, 0, 0, 1];
            }
            if (str_contains($script, 'HGETALL')) {
                if ($fault === 'counters') {
                    throw new \RuntimeException('Contadores no disponibles');
                }
                return [0, []];
            }
            if ($keys[0] === AuditEventPublisher::STREAM_PERSISTENCE_BATCH) {
                if ($fault === 'group-query') {
                    throw new \RuntimeException('XINFO no disponible');
                }
                if ($fault === 'group-shape') {
                    return [['name', 'persistence', 'lag', 0]];
                }
            }
            return [];
        });
        $controller = new TestableObservabilityController($redis);

        // Act:
        $response = self::captureResponse(static fn() => $controller->asyncMetrics());

        // Assert:
        $this->assertSame(503, $response->getCode());
        $this->assertFalse($response->getData()['success']);
    }

    public static function partialFailureCases(): iterable
    {
        yield 'lectura de grupo' => ['group-query'];
        yield 'grupo malformado' => ['group-shape'];
        yield 'lectura de contadores' => ['counters'];
        yield 'lectura de scheduler' => ['scheduler'];
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
