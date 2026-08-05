<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RedisTtlConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $originalEnv = [];

    /** @var array<int,string> */
    private array $keys = [
        'AUDIT_JOB_TTL',
        'AUDIT_STATE_TTL',
        'AUDIT_RESERVATION_TTL',
    ];

    protected function setUp(): void
    {
        foreach ($this->keys as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
        }

        $this->clearEnvCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }

            putenv("{$key}={$value}");
        }

        $this->clearEnvCache();
    }

    public function testBatchJobTtlDefaultIsSevenDays(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('setnx')
            ->with(
                BatchJobStore::jobKey('job-default'),
                $this->isType('string'),
                604800
            )
            ->willReturn(true);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->initJob('job-default', 2426, '2026-06-01', '2026-06-02', 100));
    }

    public function testBatchJobTtlHonorsValidEnvValue(): void
    {
        $this->setEnv('AUDIT_JOB_TTL', '1209600');

        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('setnx')
            ->with(
                BatchJobStore::jobKey('job-override'),
                $this->isType('string'),
                1209600
            )
            ->willReturn(true);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->initJob('job-override', 2426, '2026-06-01', '2026-06-02', 100));
    }

    public function testBatchJobTtlRejectsInvalidValues(): void
    {
        $ttls = [];
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->exactly(3))
            ->method('setnx')
            ->with(
                $this->isType('string'),
                $this->isType('string'),
                $this->callback(function (int $ttl) use (&$ttls): bool {
                    $ttls[] = $ttl;
                    return true;
                })
            )
            ->willReturn(true);

        $store = new BatchJobStore($redis);

        foreach (['0', '-1', 'abc'] as $index => $value) {
            $this->setEnv('AUDIT_JOB_TTL', $value);
            $this->assertTrue($store->initJob("job-invalid-{$index}", 2426, '2026-06-01', '2026-06-02', 100));
        }

        $this->assertSame([604800, 604800, 604800], $ttls);
    }

    public function testAuditStateTtlDefaultIsSevenDays(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('setnx')
            ->with(
                AuditStateStore::auditKey('audit-default'),
                $this->isType('string'),
                604800
            )
            ->willReturn(true);

        $store = new AuditStateStore($redis);

        $this->assertTrue($store->initAudit('audit-default', 'T38250701547'));
    }

    public function testReservationTtlDefaultStaysOneDay(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('setnx')
            ->with(
                BatchJobStore::auditReservationKey('877'),
                $this->isType('string'),
                86400
            )
            ->willReturn(true);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->claimAuditReservation('877', 'owner-token', [
            'audit_id' => 'audit-877',
        ]));
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->clearEnvCache();
    }

    private function clearEnvCache(): void
    {
        $property = new ReflectionProperty(Env::class, 'cache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
