<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditExecutionInspector;
use App\Services\Audit\Pipeline\BatchJobStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMaintenanceRedis;

/**
 * SDD-BATCH-001 (IMPL-BATCH-001-C): delta de `AuditExecutionInspector` — `reapStaleJobs()`, `repairCorruptedJobs()`
 * y `snapshot()` contra `HLEN job:{id}:audits`.
 */
final class AuditExecutionInspectorTest extends TestCase
{
    public function testReapStaleJobsSkipsTerminalAndClaimedJobsAndAccumulatesSlices(): void
    {
        $active = AuditEvent::deterministicUuidV4('inspector-active');
        $terminal = AuditEvent::deterministicUuidV4('inspector-terminal');
        $claimed = AuditEvent::deterministicUuidV4('inspector-claimed');
        $reaping = AuditEvent::deterministicUuidV4('inspector-reaping');
        $jobs = [
            $active => ['status' => 'processing'],
            $terminal => ['status' => 'completed'],
            $claimed => ['status' => 'queued'],
            $reaping => ['status' => 'queued', 'reaping' => true],
        ];
        $redis = new FakeMaintenanceRedis();
        foreach ($jobs as $jobId => $fields) {
            $redis->strings[BatchJobStore::jobKey($jobId)] = (string) json_encode($fields + ['job_id' => $jobId]);
        }
        $redis->strings[BatchJobStore::jobGenerationLockKey($claimed)] = '{"worker_id":"w","epoch":3}';
        // El job en reaping también tiene lock: se reanuda igual (el reaper ignora el claim en rebanadas de continuación).
        $redis->strings[BatchJobStore::jobGenerationLockKey($reaping)] = '{"worker_id":"w","epoch":4}';
        $redis->pages['job:*:state'] = [array_map(static fn(string $id): string => BatchJobStore::jobKey($id), array_keys($jobs))];
        $store = $this->createMock(BatchJobStore::class);
        $store->method('getJobSummary')->willReturnCallback(static fn(string $jobId): ?array => isset($jobs[$jobId]) ? $jobs[$jobId] + ['job_id' => $jobId] : null);
        $calls = [];
        $store->method('reapStaleJob')->willReturnCallback(
            static function (string $jobId, int $nowMs, int $thresholdMs, ?string $cursor) use (&$calls, $active): array {
                $calls[] = [$jobId, $thresholdMs, $cursor];
                if ($jobId === $active && $cursor === null) {
                    return ['done' => false, 'cursor' => '500', 'cancelled_audits' => 3, 'reaped' => false];
                }
                return ['done' => true, 'cursor' => null, 'cancelled_audits' => 2, 'reaped' => true];
            }
        );

        $result = (new AuditExecutionInspector($redis, $store))->reapStaleJobs(null, 0);

        self::assertSame(['reaped_jobs' => 2, 'cancelled_audits' => 7, 'skipped_claimed' => 1], $result);
        self::assertSame([[$active, 0, null], [$active, 0, '500'], [$reaping, 0, null]], $calls);
    }

    public function testReapStaleJobsWithForcedJobIdOnlyTouchesThatJob(): void
    {
        $forced = AuditEvent::deterministicUuidV4('inspector-forced');
        $other = AuditEvent::deterministicUuidV4('inspector-other');
        $redis = new FakeMaintenanceRedis();
        $redis->strings[BatchJobStore::jobKey($other)] = (string) json_encode(['job_id' => $other, 'status' => 'processing']);
        $redis->pages['job:*:state'] = [[BatchJobStore::jobKey($other)]];
        $store = $this->createMock(BatchJobStore::class);
        $store->method('getJobSummary')->willReturn(['job_id' => $forced, 'status' => 'processing']);
        $store->expects(self::once())->method('reapStaleJob')
            ->with($forced, self::isType('int'), 0, null)
            ->willReturn(['done' => true, 'cursor' => null, 'cancelled_audits' => 1, 'reaped' => true]);

        $result = (new AuditExecutionInspector($redis, $store))->reapStaleJobs($forced, 0);

        self::assertSame(['reaped_jobs' => 1, 'cancelled_audits' => 1, 'skipped_claimed' => 0], $result);
        self::assertNotContains('scan:job:*:state:0', $redis->log, 'Con jobId explícito no se recorren los demás jobs.');
    }

    public function testRepairCorruptedJobsDelegatesToStoreAndReconcilesTelemetry(): void
    {
        $store = $this->createMock(BatchJobStore::class);
        $store->expects(self::once())->method('repairJobStates')
            ->willReturn(['repaired_jobs' => 3, 'audits_recounted' => 41, 'generation_abandoned' => ['x']]);
        $store->expects(self::once())->method('reconcileJobTelemetryMetrics')
            ->willReturn(['jobs_queued' => 0, 'jobs_active' => 1, 'jobs_running' => 0, 'jobs_completed' => 2, 'jobs_failed' => 0]);

        $result = (new AuditExecutionInspector(new FakeMaintenanceRedis(), $store))->repairCorruptedJobs();

        self::assertSame(['repaired_jobs' => 3, 'audits_recounted' => 41], $result);
    }

    public function testSnapshotValidatesJobTotalsAgainstAuditsHashLength(): void
    {
        $jobId = AuditEvent::deterministicUuidV4('inspector-snapshot');
        $redis = new FakeMaintenanceRedis();
        $redis->strings[BatchJobStore::jobKey($jobId)] = (string) json_encode([
            'job_id' => $jobId, 'status' => 'processing', 'total' => 2, 'done' => 1, 'failed' => 0,
        ]);
        $redis->pages['job:*:state'] = [[BatchJobStore::jobKey($jobId)]];
        $redis->hashSizes[BatchJobStore::jobAuditsKey($jobId)] = 2;

        $snapshot = (new AuditExecutionInspector($redis))->snapshot();
        self::assertSame(['key' => BatchJobStore::jobKey($jobId), 'total' => 2, 'done' => 1, 'failed' => 0], $snapshot['jobs'][$jobId]);

        $redis->hashSizes[BatchJobStore::jobAuditsKey($jobId)] = 1;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job_totals_inconsistent');
        (new AuditExecutionInspector($redis))->snapshot();
    }
}
