<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FairAuditTaskQueueMetricsTest extends TestCase
{
    public function testCountersAndWaitingHeadsComeFromASingleAtomicRead(): void
    {
        // Arrange:
        $calls = [];
        $queue = new FairAuditTaskQueue($this->redis($calls, ['extraction:batch:pending', '3', 'extraction:batch:active', '2'], 'extraction:batch'), new AuditExecutionConfig(2));

        // Act:
        $metrics = $queue->metrics();

        // Assert:
        $reads = array_values(array_filter($calls, static fn(array $call): bool => $call['keys'] !== []));
        self::assertCount(1, $reads, 'Contadores e índices de espera deben leerse en una sola evaluación.');
        self::assertSame(FairAuditTaskQueue::COUNTS, $reads[0]['keys'][0]);
        self::assertSame(FairAuditTaskQueue::ALL, $reads[0]['keys'][1]);
        self::assertCount(2 + 8, $reads[0]['keys']);
        self::assertSame(3, $metrics['extraction']['batch']['queued']);
        self::assertSame(2, $metrics['extraction']['batch']['active']);
        self::assertSame(1000000 - 900000, $metrics['extraction']['batch']['oldest_wait_ms']);
        self::assertSame(0, $metrics['orchestration']['priority']['queued']);
    }

    public function testWaitingWorkWithoutAnIndexHeadIsStillReportedAsCorruption(): void
    {
        // Arrange:
        $calls = [];
        $queue = new FairAuditTaskQueue($this->redis($calls, ['policy:priority:pending', '1'], null), new AuditExecutionConfig(2));

        // Act / Assert:
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task_waiting_index_missing');
        $queue->metrics();
    }

    public function testMalformedSnapshotIsRejected(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn(['not-a-pair']);
        $queue = new FairAuditTaskQueue($redis, new AuditExecutionConfig(2));

        // Act / Assert:
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task_metrics_invalid');
        $queue->metrics();
    }

    /**
     * @param array<int,array{keys:array<int,string>}> $calls
     * @param list<string> $flat
     */
    private function redis(array &$calls, array $flat, ?string $headOf): RedisClient
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = []) use (&$calls, $flat, $headOf): mixed {
            $calls[] = ['keys' => $keys];
            if ($keys === []) {
                return 1000000;
            }
            $heads = array_map(
                static fn(string $waiting): array => $headOf !== null && str_contains($waiting, ':' . $headOf . ':') ? ['task', '900000'] : [],
                array_slice($keys, 2)
            );
            return [$flat, $heads];
        });

        return $redis;
    }
}

