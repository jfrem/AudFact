<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use Core\RedisClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditPersistenceQueueTest extends TestCase
{
    public function testEnqueueUsesJobScopedKeysAndPersistenceStream(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            payload: ['final_status' => 'completed'],
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('ZADD'),
                $this->callback(function (array $keys) use ($event): bool {
                    $this->assertCount(6, $keys);
                    $this->assertSame(
                        AuditEventPublisher::STREAM_PERSISTENCE,
                        $keys[5]
                    );
                    foreach ($keys as $key) {
                        $this->assertStringContainsString('{queue}', $key);
                    }
                    $this->assertStringContainsString((string) $event->jobId, $keys[0]);
                    return true;
                }),
                $this->callback(function (array $arguments) use ($event): bool {
                    $this->assertSame($event->auditId, $arguments[0]);
                    $this->assertSame($event->eventId, $arguments[2]);
                    $this->assertSame(0, $arguments[4]);
                    $decoded = json_decode($arguments[1], true);
                    $this->assertSame($event->eventId, $decoded['event_id'] ?? null);
                    return true;
                })
            )
            ->willReturn(AuditPersistenceQueue::ENQUEUE_PENDING);

        $queue = new AuditPersistenceQueue($redis);

        $this->assertSame(AuditPersistenceQueue::ENQUEUE_PENDING, $queue->enqueue($event));
    }

    public function testReprocessForcesIdempotencyReset(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $arguments): bool {
                    $this->assertSame(1, $arguments[4]);
                    return true;
                })
            )
            ->willReturn(AuditPersistenceQueue::ENQUEUE_DISPATCHED);

        $queue = new AuditPersistenceQueue($redis);

        $this->assertSame(
            AuditPersistenceQueue::ENQUEUE_DISPATCHED,
            $queue->reprocess($event)
        );
    }

    public function testAdvanceTreatsPreviouslyAdvancedEventAsSuccess(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('HEXISTS'),
                $this->callback(function (array $keys): bool {
                    $this->assertCount(5, $keys);
                    $this->assertSame(AuditEventPublisher::STREAM_PERSISTENCE, $keys[4]);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn(3);

        $queue = new AuditPersistenceQueue($redis);

        $this->assertTrue($queue->advance($event));
    }

    public function testRejectsEventsOutsidePersistenceBoundary(): void
    {
        $queue = new AuditPersistenceQueue($this->createMock(RedisClient::class));
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->expectException(InvalidArgumentException::class);
        $queue->enqueue($event);
    }
}
