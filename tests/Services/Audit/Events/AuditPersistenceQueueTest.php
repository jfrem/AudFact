<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use Core\RedisClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditPersistenceQueueTest extends TestCase
{
    #[DataProvider('routingCases')]
    public function testEnqueuePreservesLaneAndDeduplication(array $routing, string $stream): void
    {
        // Arrange:
        $event = self::persistenceEvent($routing);
        $redis = $this->expectDispatch($event, $stream, resetDeduplication: false);
        $queue = new AuditPersistenceQueue($redis);

        // Act:
        $result = $queue->enqueue($event);

        // Assert:
        $this->assertSame(AuditPersistenceQueue::ENQUEUE_DISPATCHED, $result);
    }

    #[DataProvider('routingCases')]
    public function testReprocessPreservesLaneAndResetsDeduplication(array $routing, string $stream): void
    {
        // Arrange:
        $event = self::persistenceEvent($routing);
        $redis = $this->expectDispatch($event, $stream, resetDeduplication: true);
        $queue = new AuditPersistenceQueue($redis);

        // Act:
        $result = $queue->reprocess($event);

        // Assert:
        $this->assertSame(AuditPersistenceQueue::ENQUEUE_DISPATCHED, $result);
    }

    #[DataProvider('routingCases')]
    public function testAdvanceReleasesTheTurnOnTheSameLane(array $routing, string $stream): void
    {
        // Arrange:
        $event = self::persistenceEvent($routing);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('eval')->with(
            $this->anything(),
            $this->callback(function (array $keys) use ($stream, $event): bool {
                $this->assertSame($stream, $keys[4]);
                $this->assertStringContainsString('audit:' . $event->auditId, $keys[0]);
                return true;
            }),
            $this->callback(function (array $args) use ($event): bool {
                $this->assertSame($event->auditId, $args[0]);
                return true;
            })
        )->willReturn(1);
        $queue = new AuditPersistenceQueue($redis);

        // Act:
        $result = $queue->advance($event);

        // Assert:
        $this->assertTrue($result);
    }

    public static function routingCases(): iterable
    {
        yield 'single' => [['source' => 'single'], AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY];
        yield 'priority cron' => [['source' => 'cron', 'is_priority' => true], AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY];
        yield 'batch' => [['source' => 'batch'], AuditEventPublisher::STREAM_PERSISTENCE_BATCH];
        yield 'absent' => [[], AuditEventPublisher::STREAM_PERSISTENCE_BATCH];
    }

    private function expectDispatch(AuditEvent $event, string $stream, bool $resetDeduplication): RedisClient
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('eval')->with(
            $this->anything(),
            $this->callback(function (array $keys) use ($stream, $event): bool {
                $this->assertSame($stream, $keys[5]);
                $this->assertStringContainsString('audit:' . $event->auditId, $keys[0]);
                return true;
            }),
            $this->callback(function (array $args) use ($event, $resetDeduplication): bool {
                $this->assertSame($event->auditId, $args[0]);
                $this->assertSame($event->toJson(), $args[1]);
                $this->assertSame((int) $resetDeduplication, $args[4]);
                return true;
            })
        )->willReturn(AuditPersistenceQueue::ENQUEUE_DISPATCHED);

        return $redis;
    }

    private static function persistenceEvent(array $routing): AuditEvent
    {
        return AuditEvent::fromArray([
            'event_id' => '11111111-1111-4111-8111-111111111111',
            'audit_id' => '22222222-2222-4222-8222-222222222222',
            'event_type' => AuditEvent::TYPE_RULES_EVALUATED,
            'timestamp' => '2026-09-23T12:00:00Z',
            'payload' => $routing + ['final_status' => 'completed'],
        ]);
    }

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
                        AuditEventPublisher::STREAM_PERSISTENCE_BATCH,
                        $keys[5]
                    );
                    for ($i = 0; $i < 5; $i++) {
                        $this->assertStringContainsString('{queue}', $keys[$i]);
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
                    $this->assertSame(AuditEventPublisher::STREAM_PERSISTENCE_BATCH, $keys[4]);
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
