<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use Core\RedisClient;
use Core\RedisUnavailableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditEventConsumerTest extends TestCase
{
    public function testEnsureGroupCreatesFromStreamOrigin(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects($this->once())
            ->method('xGroupCreate')
            ->with('test.stream', 'test-group', '0')
            ->willReturn(true);

        $consumer = new MinimalConsumer(redis: $redis);
        $consumer->requestStop();
        $consumer->run();
    }

    public function testEnsureGroupFailurePropagatesOriginalError(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')
            ->willThrowException(
                new RedisUnavailableException("Redis XGROUP CREATE falló para stream 'test.stream': ACL error")
            );

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RedisUnavailableException::class);
        $this->expectExceptionMessage('ACL error');
        $consumer->run();
    }

    public function testNogroupInRuntimeThrowsRatherThanSilentLoop(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroup')->willThrowException(
            new RuntimeException("NOGROUP No such consumer group 'test-group' for key name 'audfact:test.stream'")
        );

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/desapareció en runtime/');
        $consumer->run();
    }

    public function testRunProcessesMessageAndAcks(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000001']
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroup')->willReturn([[
            'id'     => '1700000000000-0',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000000-0');

        $consumer = new MinimalConsumer(redis: $redis);
        $processed = $consumer->run(1);

        $this->assertSame(1, $processed);
        $this->assertCount(1, $consumer->handled);
        $this->assertSame($event->eventId, $consumer->handled[0]->eventId);
    }

    public function testRedisUnavailableAtStartupThrows(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $consumer = new MinimalConsumer(redis: $redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis no disponible al iniciar consumer');
        $consumer->run();
    }
}

final class MinimalConsumer extends AuditEventConsumer
{
    /** @var AuditEvent[] */
    public array $handled = [];

    protected function stream(): string
    {
        return 'test.stream';
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return 'test-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
        $this->handled[] = $event;
    }
}
