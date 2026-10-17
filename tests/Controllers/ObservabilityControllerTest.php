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
                if (($keys[0] ?? '') === 'audit:document-cache:{budget}:bytes') {
                    return [0, 0, []];
                }
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
            if (($keys[0] ?? '') === 'audit:document-cache:{budget}:bytes') {
                return [0, 0, []];
            }
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

    public function testV2BacklogIncludesTasksAfterTransportAcknowledgement(): void
    {
        // Arrange:
        $env = ['AUDIT_PIPELINE_EXECUTION_VERSION' => '2', 'GEMINI_QUOTA_SCOPE' => 'synthetic',
            'GEMINI_QUOTA_RPM' => '100', 'GEMINI_QUOTA_TPM' => '10000', 'GEMINI_QUOTA_RPD' => '1000',
            'GEMINI_MODEL' => 'gemini-3.7-flash', 'REDIS_MODE' => 'standalone', 'GEMINI_QUOTA_MAX_INFLIGHT' => '4'];
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = \Core\Env::get($key, null);
            \Core\Env::set($key, $value);
        }
        try {
            $redis = $this->createMock(RedisClient::class);
            $redis->method('get')->willReturnCallback(static fn(string $key): ?string => $key === 'audit:execution:version' ? '2' : null);
            $redis->method('serverConfiguration')->willReturn(['appendonly' => 'yes', 'appendfsync' => 'always', 'maxmemory-policy' => 'noeviction']);
            $redis->method('serverInfo')->willReturn(['aof_last_write_status' => 'ok']);
            $redis->method('eval')->willReturnCallback(static function (string $script, array $keys): mixed {
                $key = $keys[0] ?? '';
                if ($keys === []) { return 1000000; }
                if ($key === 'audit.persistence:{queue}:scopes') { return [0, 0, 0, 1]; }
                if ($key === 'audit:document-cache:{budget}:bytes') { return [0, 0, []]; }
                if ($key === \App\Services\Audit\Pipeline\FairAuditTaskQueue::COUNTS) {
                    return ['policy:batch:pending', 2, 'extraction:batch:active', 1, 'semantic:batch:delayed', 3];
                }
                if (str_ends_with($key, ':waiting')) {
                    return str_contains($key, ':policy:batch:') || str_contains($key, ':semantic:batch:') ? ['fixture', '999000'] : [];
                }
                if ($key === \App\Services\Audit\Pipeline\AuditTaskCommitter::PENDING) { return [0, 0, [], '999999']; }
                if (str_starts_with($key, 'gemini:quota:v2:')) { return str_ends_with($key, ':inflight') ? [] : [0, 0, 0, 0, []]; }
                if ($key === AuditEventPublisher::dlqStream()) { return [0, []]; }
                return [];
            });
            $controller = new TestableObservabilityController($redis);

            // Act:
            $response = self::captureResponse(static fn() => $controller->asyncMetrics());
            // Assert:
            self::assertSame(200, $response->getCode());
            $data = $response->getData()['data'];
            self::assertSame(2, $data['executionVersion']);
            self::assertSame(1, $data['queueDepth']);
            self::assertSame(6, $data['backlogDepth']);
            self::assertSame(3, $data['executionScheduler']['semantic']['batch']['delayed']);
            self::assertInstanceOf(\stdClass::class, $data['executionMaintenance']['semanticPlans']);
            self::assertStringContainsString('"semanticPlans":{}', json_encode($response->getData(), JSON_THROW_ON_ERROR));
        } finally {
            foreach ($previous as $key => $value) {
                \Core\Env::set($key, $value);
            }
        }
    }

    public function testReadsPendingFromXinfoWithoutTheZeroFallback(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('xPending');
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys): array {
            if (($keys[0] ?? '') === 'audit:document-cache:{budget}:bytes') {
                return [0, 0, []];
            }
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
