<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class AuditDlqRecoveryRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private array $previousEnvironment = [];
    private const ORIGINAL_ID = '10000000-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        foreach (['AUDIT_PIPELINE_EXECUTION_VERSION' => '2', 'AUDIT_EXECUTION_MAX_QUEUED_PER_STAGE' => '20000',
            'REDIS_MODE' => 'standalone', 'GEMINI_QUOTA_SCOPE' => 'integration-test',
            'GEMINI_MODEL' => 'fixture', 'GEMINI_QUOTA_RPM' => '100', 'GEMINI_QUOTA_TPM' => '10000',
            'GEMINI_QUOTA_RPD' => '1000', 'GEMINI_QUOTA_MAX_INFLIGHT' => '4'] as $key => $value) {
            $this->previousEnvironment[$key] = Env::get($key, null);
            Env::set($key, $value);
        }
        $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->nowMs();
        $this->redis->eval('redis.call("SET",KEYS[1],"2"); return redis.call("SET",KEYS[2],ARGV[1])',
            ['audit:execution:version', 'audit:execution:heartbeat'], [$now]);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->redis)) {
                $this->redis->cleanup();
            }
        } finally {
            foreach ($this->previousEnvironment as $key => $value) {
                Env::set($key, $value);
            }
        }
    }

    public function testRecoveryIsAtomicAndIdempotentAcrossDifferentDeadLettersOfOneAudit(): void
    {
        // Arrange:
        $original = $this->seed('failed');
        $store = new AuditStateStore($this->redis);
        $first = self::recovery();

        // Act:
        $accepted = $store->recoverFailedAudit($first, self::ORIGINAL_ID);
        $duplicate = $store->recoverFailedAudit(self::recovery(), self::ORIGINAL_ID);
        $messages = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+")', [AuditEventPublisher::STREAM_INBOX_BATCH]);
        $event = json_decode($messages[0][1][1], true, flags: JSON_THROW_ON_ERROR);

        // Assert:
        self::assertSame($accepted, $duplicate);
        self::assertSame($first->auditId, $accepted['audit_id']);
        self::assertSame(self::ORIGINAL_ID, $accepted['original_audit_id']);
        self::assertCount(1, $messages);
        self::assertSame($first->eventId, $event['event_id']);
        self::assertSame('invoice-test', $event['payload']['dis_det_nro']);
        self::assertSame('dispensation-test', $event['payload']['dis_id']);
        self::assertNull($event['job_id']);
        self::assertSame('historical-job', $event['payload']['recovery_of_job_id']);
        self::assertSame($original, $this->redis->get(AuditStateStore::auditKey(self::ORIGINAL_ID)));
        self::assertSame('pending', $store->getAudit((string) $first->auditId)['status']);
    }

    #[DataProvider('protectedStates')]
    public function testCannotReopenActiveOrSuccessfullyCompletedAudits(string $status): void
    {
        // Arrange:
        $this->seed($status);
        $store = new AuditStateStore($this->redis);

        // Act / Assert:
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $store->recoverFailedAudit(self::recovery(), self::ORIGINAL_ID);
    }

    public static function protectedStates(): iterable
    {
        foreach (['pending', 'processing', 'completed', 'manual_review', 'error'] as $status) {
            yield $status => [$status];
        }
    }

    public function testPublicationFailureCompensatesNewStateAndReceiptAndAllowsRetry(): void
    {
        // Arrange:
        $this->seed('failed');
        $this->redis->eval('return redis.call("SET",KEYS[1],"wrongtype")', [AuditEventPublisher::STREAM_INBOX_BATCH]);
        $store = new AuditStateStore($this->redis);
        $recovery = self::recovery();

        // Act:
        try {
            $store->recoverFailedAudit($recovery, self::ORIGINAL_ID);
            self::fail('Se esperaba fallo al publicar');
        } catch (\Predis\Response\ServerException $error) {
            self::assertStringContainsString('WRONGTYPE', $error->getMessage());
        }
        $newState = $store->getAudit((string) $recovery->auditId);
        $receipt = $this->redis->get('audit:recovery:' . self::ORIGINAL_ID);
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [AuditEventPublisher::STREAM_INBOX_BATCH]);
        $accepted = $store->recoverFailedAudit($recovery, self::ORIGINAL_ID);

        // Assert:
        self::assertNull($newState);
        self::assertNull($receipt);
        self::assertSame($recovery->auditId, $accepted['audit_id']);
    }

    #[DataProvider('admissionBarriers')]
    public function testAdmissionBarrierPreventsRecoveryStateReceiptAndPublication(string $barrier): void
    {
        // Arrange:
        $original = $this->seed('failed');
        $recovery = self::recovery();
        if (in_array($barrier, ['audit:admission:paused', 'audit:execution:paused'], true)) {
            $this->redis->eval('return redis.call("SET",KEYS[1],"1")', [$barrier]);
        } elseif ($barrier === 'protocol') {
            $this->redis->eval('return redis.call("SET",KEYS[1],"99")', ['audit:execution:version']);
        } else {
            $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->nowMs();
            if ($barrier === 'missing_heartbeat') {
                $this->redis->eval('return redis.call("DEL",KEYS[1])', ['audit:execution:heartbeat']);
            } else {
                $heartbeat = $barrier === 'stale_heartbeat' ? $now - 60000
                    : ($barrier === 'future_heartbeat' ? $now + 60000 : $now);
                $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', ['audit:execution:heartbeat'], [$heartbeat]);
            }
            if ($barrier === 'capacity') {
                $this->redis->eval('redis.call("HSET",KEYS[1],"orchestration:batch:pending",16000); return redis.call("ZADD",KEYS[2],ARGV[1],"waiting-task")',
                    [FairAuditTaskQueue::COUNTS, FairAuditTaskQueue::base('orchestration', 'batch') . ':waiting'], [$now]);
            }
        }
        $store = new AuditStateStore($this->redis);

        // Assert:
        $this->expectException(\RuntimeException::class);

        // Act:
        try {
            $store->recoverFailedAudit($recovery, self::ORIGINAL_ID);
        } finally {
            self::assertNull($this->redis->get(AuditStateStore::auditKey($recovery->auditId)));
            self::assertNull($this->redis->get('audit:recovery:' . self::ORIGINAL_ID));
            self::assertSame(0, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::STREAM_INBOX_BATCH]));
            self::assertSame($original, $this->redis->get(AuditStateStore::auditKey(self::ORIGINAL_ID)));
        }
    }

    public static function admissionBarriers(): iterable
    {
        foreach (['audit:admission:paused', 'audit:execution:paused', 'protocol',
            'missing_heartbeat', 'stale_heartbeat', 'future_heartbeat', 'capacity'] as $barrier) {
            yield $barrier => [$barrier];
        }
    }

    public function testHealthyV2RecoveryPreservesDurabilityAndIdempotency(): void
    {
        // Arrange:
        Env::set('AUDIT_PIPELINE_EXECUTION_VERSION', '2');
        $this->seed('failed');
        $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->nowMs();
        $this->redis->eval('redis.call("SET",KEYS[1],"2"); return redis.call("SET",KEYS[2],ARGV[1])',
            ['audit:execution:version', 'audit:execution:heartbeat'], [$now]);
        $store = new AuditStateStore($this->redis);
        $recovery = self::recovery();

        // Act:
        $first = $store->recoverFailedAudit($recovery, self::ORIGINAL_ID);
        $duplicate = $store->recoverFailedAudit(self::recovery(), self::ORIGINAL_ID);

        // Assert:
        self::assertSame($first, $duplicate);
        self::assertSame(2, $store->getAudit($recovery->auditId)['execution_version']);
        self::assertSame(-1, $this->redis->eval('return redis.call("TTL",KEYS[1])', [AuditStateStore::auditKey($recovery->auditId)]));
        self::assertSame(1, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::STREAM_INBOX_BATCH]));
    }

    #[DataProvider('atomicBarrierChanges')]
    public function testBarrierChangingAfterPreflightIsStillEnforcedAtomically(string $key, string $value): void
    {
        // Arrange:
        $this->seed('failed');
        $recovery = self::recovery();
        $redis = new class($this->redis, $key, $value) extends RedisClient {
            public function __construct(private IsolatedAuditRedis $connection, private string $barrier, private string $value) {}
            public function get(string $key): ?string { return $this->connection->get($key); }
            public function eval(string $script, array $keys = [], array $argv = []): mixed
            {
                if (in_array('audit:recovery:10000000-0000-4000-8000-000000000000', $keys, true)) {
                    $this->connection->eval('return redis.call("SET",KEYS[1],ARGV[1])', [$this->barrier], [$this->value]);
                }
                return $this->connection->eval($script, $keys, $argv);
            }
        };
        $store = new AuditStateStore($redis);

        // Assert:
        $this->expectException(\Predis\Response\ServerException::class);
        $this->expectExceptionMessage('audit_capacity_unavailable');

        // Act:
        try {
            $store->recoverFailedAudit($recovery, self::ORIGINAL_ID);
        } finally {
            self::assertNull($this->redis->get(AuditStateStore::auditKey($recovery->auditId)));
            self::assertNull($this->redis->get('audit:recovery:' . self::ORIGINAL_ID));
            self::assertSame(0, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::STREAM_INBOX_BATCH]));
        }
    }

    public static function atomicBarrierChanges(): iterable
    {
        yield 'admission paused during request' => ['audit:admission:paused', '1'];
        yield 'execution paused during request' => ['audit:execution:paused', '1'];
        yield 'protocol changed during request' => ['audit:execution:version', '99'];
    }

    private function seed(string $status): string
    {
        $state = json_encode(['audit_id' => self::ORIGINAL_ID, 'status' => $status,
            'dis_det_nro' => 'invoice-test', 'dis_id' => 'dispensation-test',
            'fac_nit_sec' => 'client-test', 'job_id' => 'historical-job'], JSON_THROW_ON_ERROR);
        $this->redis->eval('return redis.call("SET",KEYS[1],ARGV[1])', [AuditStateStore::auditKey(self::ORIGINAL_ID)], [$state]);
        return $state;
    }

    private static function recovery(): AuditEvent
    {
        return AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, AuditEvent::uuidV4(),
            payload: ['source' => 'dlq_recovery', 'is_priority' => false]);
    }
}
