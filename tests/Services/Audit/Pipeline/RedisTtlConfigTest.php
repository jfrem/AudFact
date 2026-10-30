<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RedisTtlConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $originalEnv = [];

    /** @var array<int,string> */
    private array $keys = [
        'AUDIT_JOB_TTL',
        'AUDIT_STATE_TTL',
    ];

    protected function setUp(): void
    {
        foreach ($this->keys as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
        }

        Env::clearCache();
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

        Env::clearCache();
    }

    public function testBatchJobTtlDefaultIsSevenDays(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('storeState'),
                [BatchJobStore::jobKey('job-default')],
                $this->callback(static fn (array $args): bool => ($args[1] ?? null) === 604800)
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->patchJob('job-default', ['status' => 'completed']));
    }

    public function testBatchJobTtlHonorsValidEnvValue(): void
    {
        $this->setEnv('AUDIT_JOB_TTL', '1209600');

        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('storeState'),
                [BatchJobStore::jobKey('job-override')],
                $this->callback(static fn (array $args): bool => ($args[1] ?? null) === 1209600)
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->patchJob('job-override', ['status' => 'completed']));
    }

    public function testBatchJobTtlRejectsInvalidValues(): void
    {
        $ttls = [];
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->exactly(3))
            ->method('eval')
            ->with(
                $this->stringContains('storeState'),
                $this->isType('array'),
                $this->callback(function (array $args) use (&$ttls): bool {
                    $ttls[] = $args[1];
                    return true;
                })
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        foreach (['0', '-1', 'abc'] as $index => $value) {
            $this->setEnv('AUDIT_JOB_TTL', $value);
            $this->assertTrue($store->patchJob("job-invalid-{$index}", ['status' => 'completed']));
        }

        $this->assertSame([604800, 604800, 604800], $ttls);
    }

    public function testAuditStateTtlDefaultIsSevenDays(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('storeState'),
                [AuditStateStore::auditKey('audit-default')],
                $this->callback(static fn (array $args): bool => ($args[1] ?? null) === 604800)
            )
            ->willReturn(1);

        $store = new AuditStateStore($redis);

        $this->assertTrue($store->patchAudit('audit-default', ['status' => 'completed']));
    }

    public function testReservationClaimUsesDurableAtomicSetNx(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                'local r=redis.call("SET",KEYS[1],ARGV[1],"NX"); return r and 1 or 0',
                [BatchJobStore::auditReservationKey('877')],
                $this->callback(static fn (array $args): bool => is_string($args[0] ?? null))
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->claimAuditReservation('877', 'owner-token', [
            'audit_id' => 'audit-877',
        ]));
    }

    private function setEnv(string $key, string $value): void
    {
        Env::set($key, $value);
    }
}
