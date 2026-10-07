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
        $redis->expects($this->exactly(2))->method('eval')->willReturnCallback(
            function (string $script, array $keys, array $arguments) use ($event): mixed {
                if (str_ends_with($keys[0], ':slots')) {
                    $this->assertCount(69, $keys);
                    $this->assertSame(2, $arguments[0]);
                    return [1, 'job:' . $event->jobId]; // Job legacy: conserva el scope anterior.
                }
                $this->assertStringContainsString('ZADD', $script);
                $this->assertCount(8, $keys);
                $this->assertSame(AuditEventPublisher::STREAM_PERSISTENCE_BATCH, $keys[5]);
                for ($i = 0; $i < 5; $i++) {
                    $this->assertStringContainsString('{queue}', $keys[$i]);
                }
                $this->assertStringContainsString((string) $event->jobId, $keys[0]);
                $this->assertSame($event->auditId, $arguments[0]);
                $this->assertSame($event->eventId, $arguments[2]);
                $this->assertSame(0, $arguments[4]);
                $decoded = json_decode($arguments[1], true);
                $this->assertSame($event->eventId, $decoded['event_id'] ?? null);
                return AuditPersistenceQueue::ENQUEUE_PENDING;
            }
        );

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
        $redis->expects($this->exactly(2))->method('eval')->willReturnCallback(
            function (string $script, array $keys) use ($event): mixed {
                if (str_ends_with($keys[0], ':slots')) {
                    return [1, 'job:' . $event->jobId];
                }
                $this->assertStringContainsString('HEXISTS', $script);
                $this->assertCount(7, $keys);
                $this->assertSame(AuditEventPublisher::STREAM_PERSISTENCE_BATCH, $keys[4]);
                return 3;
            }
        );

        $queue = new AuditPersistenceQueue($redis);

        $this->assertTrue($queue->advance($event));
    }

    public function testAdvanceAfterFailureUsesTerminalLuaMode(): void
    {
        $event = self::persistenceEvent(['source' => 'batch']);
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('eval')->with(
            $this->stringContains('ZPOPMIN'),
            $this->callback(function (array $keys): bool {
                $this->assertSame(AuditEventPublisher::STREAM_PERSISTENCE_BATCH, $keys[4]);
                return true;
            }),
            $this->callback(function (array $args) use ($event): bool {
                $this->assertSame($event->auditId, $args[0]);
                $this->assertSame(1, $args[2]);
                return true;
            })
        )->willReturn(2);

        $this->assertTrue((new AuditPersistenceQueue($redis))->advanceAfterFailure($event));
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

    #[DataProvider('invalidSlotCounts')]
    public function testRejectsInvalidSlotCounts(int $slots): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('eval');
        $this->expectException(InvalidArgumentException::class);

        // Act:
        new AuditPersistenceQueue($redis, $slots);

        // Assert: la excepción evita cualquier acceso a Redis.
    }

    public static function invalidSlotCounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'over limit' => [17];
    }

    public function testUsesPersistedSlotsInsteadOfCurrentConfiguration(): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_RULES_EVALUATED,
            '00000001-0000-4000-8000-000000000000', jobId: '10000000-0000-4000-8000-000000000000');
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->exactly(2))->method('eval')->willReturnCallback(
            function (string $script, array $keys, array $args) use ($event): mixed {
                if (str_ends_with($keys[0], ':slots')) {
                    $this->assertSame(4, $args[0]);
                    return [2, 'job:' . $event->jobId . ':slot:1'];
                }
                $this->assertSame('audit.persistence:{queue}:job:' . $event->jobId . ':slot:1:active', $keys[0]);
                return AuditPersistenceQueue::ENQUEUE_DISPATCHED;
            }
        );
        $queue = new AuditPersistenceQueue($redis, 4);

        // Act:
        $result = $queue->enqueue($event);

        // Assert:
        $this->assertSame(AuditPersistenceQueue::ENQUEUE_DISPATCHED, $result);
    }

    public function testRejectsIncompleteReconciliationInsteadOfReportingZeroBacklog(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script): mixed {
            return str_contains($script, 'ZRANGEBYSCORE') ? [0, 0, 0, 0] : '1';
        });
        $queue = new AuditPersistenceQueue($redis, 2);

        // Assert:
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('excedió el límite de páginas');

        // Act:
        $queue->metrics();
    }

    public function testRejectsMalformedSchedulerCounters(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn(['unknown', 0, 0, 1]);
        $queue = new AuditPersistenceQueue($redis, 2);

        // Assert:
        $this->expectException(\RuntimeException::class);

        // Act:
        $queue->metrics();
    }
}
