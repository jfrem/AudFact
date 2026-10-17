<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\Env;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class AuditEventRetentionRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private ?string $previousLimit;
    private const STREAM = AuditEventPublisher::STREAM_DOCUMENTS_BATCH;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis standalone.');
        }
        $this->previousLimit = getenv('AUDIT_STREAM_MAXLEN') === false ? null : getenv('AUDIT_STREAM_MAXLEN');
        Env::set('AUDIT_STREAM_MAXLEN', '100');
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
            Env::set('AUDIT_STREAM_MAXLEN', $this->previousLimit);
        }
    }

    public function testNeverTrimsUndeliveredMessagesForTheSlowestGroup(): void
    {
        // Arrange:
        $ids = $this->seed(1200);
        $this->createRequiredGroups();
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],"fast","$"); return redis.call("XGROUP","CREATE",KEYS[1],"slow","0")', [self::STREAM]);
        $publisher = new AuditEventPublisher($this->redis);

        // Act:
        $publisher->publish(self::event());
        $remaining = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+","COUNT",1)', [self::STREAM]);

        // Assert:
        self::assertSame($ids[0], $remaining[0][0]);
        self::assertSame(1201, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [self::STREAM]));
    }

    public function testPreservesOldestPendingMessageWhileRemovingAcknowledgedHistory(): void
    {
        // Arrange:
        $ids = $this->seed(1200);
        $this->createRequiredGroups();
        $this->redis->eval('redis.call("XGROUP","CREATE",KEYS[1],"worker",ARGV[1]); return redis.call("XREADGROUP","GROUP","worker","replica","COUNT",1,"STREAMS",KEYS[1],">")', [self::STREAM], [$ids[599]]);
        $this->redis->eval('return redis.call("XGROUP","SETID",KEYS[1],"worker","$")', [self::STREAM]);
        $publisher = new AuditEventPublisher($this->redis);

        // Act:
        $publisher->publish(self::event());
        $oldest = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+","COUNT",1)', [self::STREAM]);
        $pendingMessage = $this->redis->eval('return redis.call("XRANGE",KEYS[1],ARGV[1],ARGV[1])', [self::STREAM], [$ids[600]]);

        // Assert:
        self::assertNotEmpty($pendingMessage);
        self::assertSame($ids[600], $pendingMessage[0][0]);
        self::assertNotSame($ids[0], $oldest[0][0]);
        self::assertLessThan(1201, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [self::STREAM]));
    }

    public function testPreservesStreamsWithoutConsumersAndDurableDeadLetters(): void
    {
        // Arrange:
        $this->seed(1200);
        $publisher = new AuditEventPublisher($this->redis);
        $first = null;

        // Act:
        $publisher->publish(self::event());
        for ($i = 0; $i < 1200; $i++) {
            $dlqId = $publisher->publishDeadLetter(AuditEvent::create(AuditEvent::TYPE_DEAD_LETTER, null));
            $first ??= $dlqId;
        }
        $dlqOldest = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+","COUNT",1)', [AuditEventPublisher::dlqStream()]);

        // Assert:
        self::assertSame(1201, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [self::STREAM]));
        self::assertSame($first, $dlqOldest[0][0]);
        self::assertSame(1200, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::dlqStream()]));
    }

    public function testNeverTrimsBeforeAllMandatoryConsumerGroupsExist(): void
    {
        // Arrange:
        $ids = $this->seed(1200);
        $this->redis->eval('return redis.call("XGROUP","CREATE",KEYS[1],"downloaders","$")', [self::STREAM]);
        $publisher = new AuditEventPublisher($this->redis);

        // Act:
        $publisher->publish(self::event());
        $oldest = $this->redis->eval('return redis.call("XRANGE",KEYS[1],"-","+","COUNT",1)', [self::STREAM]);

        // Assert:
        self::assertSame($ids[0], $oldest[0][0]);
        self::assertSame(1201, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [self::STREAM]));
    }

    private function createRequiredGroups(): void
    {
        $this->redis->eval('for _,group in ipairs(cjson.decode(ARGV[1])) do redis.call("XGROUP","CREATE",KEYS[1],group,"$") end; return 1',
            [self::STREAM], [json_encode(['downloaders', 'extractors', 'normalizers', 'policy'], JSON_THROW_ON_ERROR)]);
    }

    private function seed(int $count): array
    {
        return $this->redis->eval('local ids={}; for i=1,tonumber(ARGV[1]) do ids[i]=redis.call("XADD",KEYS[1],"*","event","seed") end; return ids', [self::STREAM], [$count]);
    }

    private static function event(): AuditEvent
    {
        return AuditEvent::create(AuditEvent::TYPE_DOCUMENT_REGISTERED, AuditEvent::uuidV4(), documentId: '1');
    }
}
