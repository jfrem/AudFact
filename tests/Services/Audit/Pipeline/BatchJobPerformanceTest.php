<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\BatchJobStore;
use DateTimeImmutable;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchJobPerformanceTest extends TestCase
{
    #[DataProvider('terminalStatuses')]
    public function testTerminalThroughputIsFrozenAtCompletion(string $status): void
    {
        // Arrange:
        $state = self::state($status) + ['completed_at' => '2026-10-07T10:05:00Z'];
        $now = new DateTimeImmutable('2026-10-07T12:00:00Z');

        // Act:
        $metrics = BatchJobStore::calculatePerformance($state, $now);

        // Assert:
        $this->assertSame(300000, $metrics['elapsed_ms']);
        $this->assertSame(0.01, $metrics['throughput_per_sec']);
        $this->assertSame(60000, $metrics['accumulated_duration_ms']);
        $this->assertSame(20000, $metrics['avg_duration_ms']);
    }

    public static function terminalStatuses(): iterable
    {
        yield [BatchJobStore::JOB_STATUS_COMPLETED];
        yield [BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR];
        yield [BatchJobStore::JOB_STATUS_FAILED];
    }

    public function testJobListUsesTheSameWallTimeCalculation(): void
    {
        // Arrange:
        $state = self::state(BatchJobStore::JOB_STATUS_COMPLETED)
            + ['job_id' => '10000000-0000-4000-8000-000000000000', 'completed_at' => '2026-10-07T10:05:00Z'];
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(json_encode([$state], JSON_THROW_ON_ERROR));
        $store = new BatchJobStore($redis);

        // Act:
        $jobs = $store->listJobs();

        // Assert:
        $this->assertCount(1, $jobs);
        $this->assertSame(300000, $jobs[0]['elapsed_ms']);
        $this->assertSame(0.01, $jobs[0]['throughput_per_sec']);
    }

    public function testActiveJobIncludesPreparationAndCurrentWait(): void
    {
        // Arrange:
        $state = self::state(BatchJobStore::JOB_STATUS_PROCESSING);
        $now = new DateTimeImmutable('2026-10-07T10:10:00Z');

        // Act:
        $metrics = BatchJobStore::calculatePerformance($state, $now);

        // Assert:
        $this->assertSame(600000, $metrics['elapsed_ms']);
        $this->assertSame(0.005, $metrics['throughput_per_sec']);
    }

    public function testLegacyTerminalUsesUpdatedTimestamp(): void
    {
        // Arrange:
        $state = self::state(BatchJobStore::JOB_STATUS_COMPLETED);

        // Act:
        $metrics = BatchJobStore::calculatePerformance($state, new DateTimeImmutable('2026-10-08T10:00:00Z'));

        // Assert:
        $this->assertSame(360000, $metrics['elapsed_ms']);
        $this->assertSame(0.0083, $metrics['throughput_per_sec']);
    }

    #[DataProvider('invalidTimestamps')]
    public function testUnknownOrNonpositiveElapsedDoesNotInventThroughput(string $createdAt): void
    {
        // Arrange:
        $state = self::state(BatchJobStore::JOB_STATUS_PROCESSING);
        $state['created_at'] = $createdAt;

        // Act:
        $metrics = BatchJobStore::calculatePerformance($state, new DateTimeImmutable('2026-10-07T10:00:00Z'));

        // Assert:
        $this->assertSame(0, $metrics['elapsed_ms']);
        $this->assertSame(0.0, $metrics['throughput_per_sec']);
    }

    public static function invalidTimestamps(): iterable
    {
        yield 'missing' => [''];
        yield 'invalid' => ['invalid-timestamp'];
        yield 'same instant' => ['2026-10-07T10:00:00Z'];
        yield 'future' => ['2026-10-08T10:00:00Z'];
    }

    private static function state(string $status): array
    {
        return ['status' => $status, 'done' => 2, 'failed' => 1,
            'created_at' => '2026-10-07T10:00:00Z', 'updated_at' => '2026-10-07T10:06:00Z',
            'accumulated_duration_ms' => 60000, 'avg_duration_ms' => 20000];
    }
}
