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
}
