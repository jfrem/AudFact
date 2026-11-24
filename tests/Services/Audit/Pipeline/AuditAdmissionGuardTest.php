<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditAdmissionGuard;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditAdmissionGuardTest extends TestCase
{
    #[DataProvider('capacityCases')]
    public function testAdmissionAndOrchestrationApplyTheirOwnCapacityLimits(
        array $counts, int $memoryUsed, bool $admissionBlocked, bool $orchestrationBlocked
    ): void {
        // Arrange:
        $guard = new AuditAdmissionGuard($this->redis($counts, $memoryUsed), new AuditExecutionConfig(2, 100));

        // Act:
        $admission = $guard->atCapacity();
        $orchestration = $guard->orchestrationAtCapacity();

        // Assert:
        self::assertSame($admissionBlocked, $admission);
        self::assertSame($orchestrationBlocked, $orchestration);
    }

    public static function capacityCases(): iterable
    {
        yield 'below admission threshold' => [['orchestration:batch:pending' => 79], 700, false, false];
        yield 'own queue drains at threshold' => [['orchestration:batch:pending' => 80], 700, true, false];
        yield 'both lanes share admission capacity' => [[
            'orchestration:priority:pending' => 40, 'orchestration:batch:delayed' => 40,
        ], 700, true, false];
        foreach ([AuditTaskStage::EXTRACTION, AuditTaskStage::POLICY, AuditTaskStage::SEMANTIC] as $stage) {
            yield $stage->value . ' backpressure' => [[$stage->value . ':batch:pending' => 80], 700, true, true];
        }
        yield 'downstream pending plus delayed' => [[
            'extraction:priority:pending' => 40, 'extraction:batch:delayed' => 40,
        ], 700, true, true];
        yield 'active work is not waiting capacity' => [['orchestration:batch:active' => 80], 700, false, false];
        yield 'memory threshold still blocks fanout' => [[], 800, true, true];
    }

    /** @return iterable<string,array{array<string,int>,?int,bool}> */
    public static function priorityWaitCases(): iterable
    {
        yield 'extraction priority exactly at the limit' => [['extraction:priority' => 1200000], null, true];
        yield 'orchestration priority above the limit' => [['orchestration:priority' => 5000000], null, true];
        yield 'policy priority above the limit' => [['policy:priority' => 1300000], null, true];
        yield 'semantic priority above the limit' => [['semantic:priority' => 1200001], null, true];
        yield 'one millisecond below the limit' => [['extraction:priority' => 1199999], null, false];
        yield 'batch lane never triggers priority backpressure' => [['extraction:batch' => 99999999], null, false];
        yield 'configured limit lowers the threshold' => [['extraction:priority' => 5000], 5000, true];
        yield 'configured limit raises the threshold' => [['extraction:priority' => 1200000], 2000000, false];
    }

    /** @param array<string,int> $waits */
    #[DataProvider('priorityWaitCases')]
    public function testPriorityLaneAtCapacityWhenOldestWaitReachesLimit(array $waits, ?int $limitMs, bool $expected): void
    {
        // Arrange:
        $previous = (string) Env::get('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS', '');
        try {
            Env::set('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS', $limitMs === null ? '' : (string) $limitMs);
            Env::clearCache('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS');
            $guard = new AuditAdmissionGuard($this->waitingRedis($waits), new AuditExecutionConfig(2, 100));

            // Act / Assert:
            self::assertSame($expected, $guard->priorityLaneAtCapacity());
        } finally {
            Env::set('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS', $previous === '' ? null : $previous);
            Env::clearCache('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS');
        }
    }

    public function testPriorityLaneNotAtCapacityWithEmptyQueue(): void
    {
        // Arrange:
        $guard = new AuditAdmissionGuard($this->waitingRedis([]), new AuditExecutionConfig(2, 100));

        // Act / Assert:
        self::assertFalse($guard->priorityLaneAtCapacity());
    }

    public function testPriorityLaneMetricsFailurePropagates(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = []): mixed {
            if ($keys === []) {
                return 1000000;
            }
            throw new \RuntimeException('redis_unavailable');
        });
        $guard = new AuditAdmissionGuard($redis, new AuditExecutionConfig(2, 100));

        // Assert / Act:
        $this->expectException(\RuntimeException::class);
        $guard->priorityLaneAtCapacity();
    }

    /**
     * Redis cuya cola tiene, por `etapa:carril`, una tarea esperando los milisegundos indicados.
     *
     * @param array<string,int> $waitMs
     */
    private function waitingRedis(array $waitMs): RedisClient
    {
        $now = 10000000;
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = []) use ($waitMs, $now): mixed {
            if ($keys === []) {
                return $now;
            }
            $flat = [];
            $heads = [];
            foreach (AuditTaskStage::cases() as $stage) {
                foreach (['priority', 'batch'] as $lane) {
                    $name = $stage->value . ':' . $lane;
                    if (isset($waitMs[$name])) {
                        array_push($flat, $name . ':pending', '1');
                        $heads[] = ['synthetic-task', (string) ($now - $waitMs[$name])];
                    } else {
                        $heads[] = [];
                    }
                }
            }
            return [$flat, $heads];
        });
        return $redis;
    }

    private function redis(array $counts, int $memoryUsed): RedisClient
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('serverInfo')->willReturn(['used_memory' => (string) $memoryUsed, 'maxmemory' => '1000']);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = []) use ($counts): mixed {
            if ($keys === []) {
                return 1000000;
            }
            $flat = [];
            foreach ($counts as $field => $count) {
                array_push($flat, $field, (string) $count);
            }
            $heads = array_map(static function (string $waiting) use ($counts): array {
                $parts = explode(':', $waiting);
                $prefix = $parts[3] . ':' . $parts[4] . ':';
                return ($counts[$prefix . 'pending'] ?? 0) + ($counts[$prefix . 'delayed'] ?? 0) > 0
                    ? ['synthetic-task', '1000'] : [];
            }, array_slice($keys, 2));
            return [$flat, $heads];
        });
        return $redis;
    }
}
