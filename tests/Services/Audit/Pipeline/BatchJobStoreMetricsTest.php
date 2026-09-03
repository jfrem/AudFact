<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class BatchJobStoreMetricsTest extends TestCase
{
    public function testCompletionMetricsTransitionUsesPreviousJobStatus(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $capture = strpos(
                        $script,
                        "local oldJobStatus = tostring(job['status'] or 'pending')"
                    );
                    $jobMutation = strpos($script, "job['status'] =");

                    $this->assertNotFalse($capture);
                    $this->assertNotFalse($jobMutation);
                    $this->assertLessThan($jobMutation, $capture);
                    $this->assertStringNotContainsString(
                        'local oldJobStatus = previousStatus',
                        $script
                    );
                    $this->assertStringContainsString(
                        "if oldJobStatus == 'pending' then",
                        $script
                    );
                    $this->assertStringContainsString(
                        "elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then",
                        $script
                    );

                    return true;
                }),
                [
                    BatchJobStore::jobKey('job-metrics'),
                    'telemetry:async_metrics',
                ],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'audit-metrics'
                        && ($args[1] ?? null) === 'completed'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue(
            $store->markAuditCompletedInJob(
                'job-metrics',
                'audit-metrics',
                'completed',
                1250
            )
        );
    }

    public function testListJobIdsPassesCursorAndLimitToLuaAndReturnsArray(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("redis.call('ZSCAN', indexKey, cursor, 'COUNT', limit)", $script);
                    return true;
                }),
                ['jobs:index'],
                ['123', '25']
            )
            ->willReturn(json_encode([
                'cursor' => '456',
                'job_ids' => ['job-alpha', 'job-beta'],
            ]));

        $store = new BatchJobStore($redis);
        $result = $store->listJobIds(limit: 25, cursor: '123');

        $this->assertSame('456', $result['cursor']);
        $this->assertSame(['job-alpha', 'job-beta'], $result['job_ids']);
    }

    public function testDeleteJobExecutesAtomicLuaScript(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("redis.call('ZREM', indexKey, jobId)", $script);
                    $this->assertStringContainsString("redis.call('DEL', jobKey)", $script);
                    return true;
                }),
                [BatchJobStore::jobKey('job-del-1'), 'jobs:index'],
                ['job-del-1']
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->deleteJob('job-del-1'));
    }

    public function testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("local oldJobStatus = tostring(job['status'] or 'pending')", $script);
                    $this->assertStringContainsString("if oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors' then", $script);
                    $this->assertStringContainsString("job['status'] = 'processing'", $script);
                    $this->assertStringContainsString("redis.call('HINCRBY', KEYS[2], 'jobs_completed', -1)", $script);
                    $this->assertStringContainsString("redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)", $script);
                    $this->assertStringContainsString("job['batch_event_published'] = nil", $script);
                    return true;
                }),
                [
                    BatchJobStore::jobKey('job-reopen-1'),
                    'telemetry:async_metrics',
                ],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'audit-1'
                        && ($args[1] ?? null) === 'event-reopen-1'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->reopenAuditInJob('job-reopen-1', 'audit-1', 'event-reopen-1'));
    }

    public function testRevertAuditReprocessInJobRevertsStatusAndRestoresJobState(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("if tostring(auditState['status'] or '') ~= 'processing' then return 0 end", $script);
                    $this->assertStringContainsString("local prevStatus = tostring(auditState['previous_status'] or 'failed')", $script);
                    $this->assertStringContainsString("auditState['status'] = prevStatus", $script);
                    $this->assertStringContainsString("if prevStatus == 'failed' then", $script);
                    $this->assertStringContainsString("job['failed'] = (tonumber(job['failed']) or 0) + 1", $script);
                    $this->assertStringContainsString("job['done'] = (tonumber(job['done']) or 0) + 1", $script);
                    $this->assertStringContainsString("redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)", $script);
                    $this->assertStringContainsString("redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)", $script);
                    return true;
                }),
                [
                    BatchJobStore::jobKey('job-revert-1'),
                    'telemetry:async_metrics',
                ],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'audit-revert-1'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->revertAuditReprocessInJob('job-revert-1', 'audit-revert-1'));
    }

    public function testReopenAuditInJobDecrementsDoneWhenPreviousStatusIsError(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("if prevStatus == 'failed' then", $script);
                    $this->assertStringContainsString("job['failed'] = math.max(0, (tonumber(job['failed']) or 0) - 1)", $script);
                    $this->assertStringContainsString("elseif prevStatus == 'error' then", $script);
                    $this->assertStringContainsString("job['done'] = math.max(0, (tonumber(job['done']) or 0) - 1)", $script);
                    return true;
                }),
                [
                    BatchJobStore::jobKey('job-reopen-err'),
                    'telemetry:async_metrics',
                ],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'audit-err'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->reopenAuditInJob('job-reopen-err', 'audit-err', 'event-err-1'));
    }

    public function testClaimBatchTerminalEventUsesTokenAndPublishingState(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("job['batch_event_published'] = 'publishing:' .. claimToken", $script);
                    $this->assertStringContainsString("if published == 'batch_completed' or published == 'batch_completed_with_errors' then", $script);
                    return true;
                }),
                [BatchJobStore::jobKey('job-term-1')],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'token-123'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->claimBatchTerminalEvent('job-term-1', 'batch_completed', 'token-123'));
    }

    public function testConfirmBatchTerminalEventUpdatesToDefinitiveEventWithCas(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("if published ~= ('publishing:' .. claimToken) then", $script);
                    $this->assertStringContainsString("job['batch_event_published'] = eventType", $script);
                    return true;
                }),
                [BatchJobStore::jobKey('job-term-1')],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'token-123'
                        && ($args[1] ?? null) === 'batch_completed'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->confirmBatchTerminalEvent('job-term-1', 'batch_completed', 'token-123'));
    }

    public function testReleaseBatchTerminalEventClearsClaimTokenWithCas(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $this->assertStringContainsString("if published ~= ('publishing:' .. claimToken) then", $script);
                    $this->assertStringContainsString("job['batch_event_published'] = nil", $script);
                    return true;
                }),
                [BatchJobStore::jobKey('job-term-1')],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'token-123'
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);
        $this->assertTrue($store->releaseBatchTerminalEvent('job-term-1', 'token-123'));
    }
}
