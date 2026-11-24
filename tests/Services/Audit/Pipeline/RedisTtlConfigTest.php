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
                $this->callback(static fn (array $args): bool => ($args[1] ?? null) === 86400)
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

        $this->assertSame([86400, 86400, 86400], $ttls);
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
                $this->callback(static fn (array $args): bool => ($args[1] ?? null) === 86400)
            )
            ->willReturn(1);

        $store = new AuditStateStore($redis);

        $this->assertTrue($store->patchAudit('audit-default', ['status' => 'completed']));
    }

    /** T-U3 (CA-F1-10, REQ-F1-11): la llave de idempotencia es un único eval atómico con TTL efectivo. */
    public function testIdempotencyClaimIsSingleAtomicEvalWithEffectiveTtl(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd($this->stringContains('"NX","EX"'), $this->stringContains('GET')),
                [BatchJobStore::idempotencyKey('key-ttl')],
                ['job-ttl', 86400]
            )
            ->willReturn(null);
        $redis->expects($this->never())->method('get');

        $store = new BatchJobStore($redis);

        $this->assertNull($store->claimIdempotencyKey('key-ttl', 'job-ttl', 300));
    }

    public function testIdempotencyClaimUsesConfiguredTtlWhenLarger(): void
    {
        $this->setEnv('AUDIT_JOB_TTL', '100');

        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with($this->anything(), [BatchJobStore::idempotencyKey('key-small')], ['job-small', 300])
            ->willReturn(null);

        $store = new BatchJobStore($redis);

        $this->assertNull($store->claimIdempotencyKey('key-small', 'job-small', 300));
    }

    public function testIdempotencyClaimReturnsExistingJobIdWhenKeyAlreadyClaimed(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn('job-previo');

        $store = new BatchJobStore($redis);

        $this->assertSame('job-previo', $store->claimIdempotencyKey('key-dup', 'job-nuevo', 300));
    }

    public function testIdempotencyClaimRejectsNonPositiveTtlWithoutRedisCall(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('eval');

        $store = new BatchJobStore($redis);

        foreach ([0, -1] as $ttl) {
            try {
                $store->claimIdempotencyKey('key-bad', 'job-bad', $ttl);
                $this->fail('Un TTL no positivo debe lanzar InvalidArgumentException.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('idempotency_ttl_invalid', $error->getMessage());
            }
        }
    }

    public function testIdempotencyClaimWrapsPredisErrorAsRuntimeException(): void
    {
        $server = new \Predis\Response\ServerException('ERR x');
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willThrowException($server);

        $store = new BatchJobStore($redis);

        try {
            $store->claimIdempotencyKey('key-fail', 'job-fail', 300);
            $this->fail('Un fallo de Predis debe envolverse en RuntimeException.');
        } catch (\RuntimeException $error) {
            $this->assertSame($server, $error->getPrevious());
        }
    }

    public function testReservationClaimUsesDurableAtomicSetNx(): void
    {
        $payload = null;
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                'local r=redis.call("SET",KEYS[1],ARGV[1],"NX"); return r and 1 or 0',
                ['audit:reservation:disdetnro:877'],
                $this->callback(static function (array $args) use (&$payload): bool {
                    $payload = $args[0] ?? null;
                    return is_string($payload);
                })
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertSame('audit:reservation:disdetnro:877', BatchJobStore::auditReservationKey('877'));
        $this->assertTrue($store->claimAuditReservation('877', 'owner-token', [
            'audit_id' => 'audit-877',
            'dis_id' => 'D-1',
        ]));

        $decoded = json_decode((string) $payload, true);
        $this->assertSame('877', $decoded['dis_det_nro']);
        $this->assertSame('owner-token', $decoded['token']);
        $this->assertSame('audit-877', $decoded['audit_id']);
        $this->assertSame('D-1', $decoded['dis_id']);
        $this->assertArrayHasKey('claimed_at', $decoded);
        // Sin TTL automático (INV-04): el script no usa EX.
    }

    public function testReleaseIdempotencyKeyIfMatchesDeletesOnlyWhenValueMatches(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd($this->stringContains('GET'), $this->stringContains('DEL')),
                [BatchJobStore::idempotencyKey('key-release')],
                ['job-release']
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue($store->releaseIdempotencyKeyIfMatches('key-release', 'job-release'));
    }

    public function testReleaseIdempotencyKeyIfMatchesReturnsFalseWhenValueDiffers(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(0);

        $store = new BatchJobStore($redis);

        $this->assertFalse($store->releaseIdempotencyKeyIfMatches('key-other', 'job-release'));
    }

    private function setEnv(string $key, string $value): void
    {
        Env::set($key, $value);
    }
}
