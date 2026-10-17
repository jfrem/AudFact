<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditAdmissionGuard;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
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

    private function redis(array $counts, int $memoryUsed): RedisClient
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('serverInfo')->willReturn(['used_memory' => (string) $memoryUsed, 'maxmemory' => '1000']);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys = []) use ($counts): mixed {
            if ($keys === []) {
                return 1000000;
            }
            if ($keys[0] === FairAuditTaskQueue::COUNTS) {
                $flat = [];
                foreach ($counts as $field => $count) {
                    array_push($flat, $field, (string) $count);
                }
                return $flat;
            }
            $parts = explode(':', $keys[0]);
            $prefix = $parts[3] . ':' . $parts[4] . ':';
            return ($counts[$prefix . 'pending'] ?? 0) + ($counts[$prefix . 'delayed'] ?? 0) > 0
                ? ['synthetic-task', '1000'] : [];
        });
        return $redis;
    }
}
