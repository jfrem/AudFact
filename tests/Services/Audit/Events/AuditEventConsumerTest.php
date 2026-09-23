<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use Core\RedisClient;
use Core\RedisUnavailableException;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditEventConsumerTest extends TestCase
{
    #[DataProvider('failureRoutingCases')]
    public function testTerminalFailureKeepsRoutingAndOriginalEventInDlq(array $routing, string $stream): void
    {
        // Arrange:
        $event = AuditEvent::fromArray([
            'event_id' => '11111111-1111-4111-8111-111111111111',
            'audit_id' => '22222222-2222-4222-8222-222222222222',
            'event_type' => AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            'timestamp' => '2026-09-23T12:00:00Z',
            'payload' => $routing,
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('get')->willReturn(json_encode(['audit_id' => $event->auditId, 'status' => 'processing']));
        $redis->method('eval')->willReturn(1);
        $redis->method('incr')->willReturn(1);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->once())->method('publish')->with(
            $this->callback(function (AuditEvent $failed) use ($routing, $stream, $event): bool {
                $this->assertSame(AuditEvent::TYPE_AUDIT_FAILED, $failed->eventType);
                $this->assertSame($stream, AuditEventPublisher::streamForEvent($failed));
                $this->assertSame($event->eventId, $failed->parentEventId);
                $this->assertSame($routing, array_intersect_key($failed->payload, ['source' => true, 'is_priority' => true]));
                return true;
            })
        )->willReturn('2-0');
        $publisher->expects($this->once())->method('publishDeadLetter')->with(
            $this->callback(function (AuditEvent $dlq) use ($event): bool {
                $this->assertSame($event->toArray(), $dlq->payload['original_event']);
                return true;
            })
        )->willReturn('3-0');
        $redis->expects($this->once())->method('xAck')->with('test.stream', 'test-group', '1-0');
        $consumer = new TerminalFailureConsumer(redis: $redis, publisher: $publisher, lane: 'all');

        // Act:
        $processed = $consumer->run(1);

        // Assert:
        $this->assertSame(1, $processed);
        $this->assertSame([$event->eventId], $consumer->terminalFailureEventIds);
    }

    public static function failureRoutingCases(): iterable
    {
        yield 'single' => [['source' => 'single'], AuditEventPublisher::STREAM_RESULTS_PRIORITY];
        yield 'priority cron' => [['source' => 'cron', 'is_priority' => true], AuditEventPublisher::STREAM_RESULTS_PRIORITY];
        yield 'batch' => [['source' => 'batch', 'is_priority' => false], AuditEventPublisher::STREAM_RESULTS_BATCH];
        yield 'absent' => [[], AuditEventPublisher::STREAM_RESULTS_BATCH];
    }

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
        $redis->method('xReadGroupMulti')->willThrowException(
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
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000000-0',
            'stream' => 'test.stream',
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

    public function testRunProcessesReclaimedPendingBeforeReadingNewMessages(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: ['document_id' => AuditEvent::uuidV4()]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->expects($this->once())
            ->method('xAutoClaim')
            ->with(
                'test.stream',
                'test-group',
                'test-consumer',
                $this->greaterThanOrEqual(600000),
                '0-0',
                1
            )
            ->willReturn([
                'next' => '0-0',
                'messages' => [[
                    'id' => '1700000000001-0',
                    'fields' => ['event' => $event->toJson()],
                ]],
            ]);
        $redis->expects($this->never())->method('xReadGroupMulti');
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000001-0');

        $consumer = new MinimalConsumer(redis: $redis);
        $processed = $consumer->run(1);

        $this->assertSame(1, $processed);
        $this->assertSame($event->eventId, $consumer->handled[0]->eventId);
    }

    public function testRunRecordsSuccessfulEventTelemetry(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T00000002']
        );
        $telemetryStore = new RecordingTelemetryStateStore();

        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id'     => '1700000000000-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->never())->method('xAdd');
        $redis->expects($this->once())->method('xAck');

        $consumer = new MinimalConsumer(redis: $redis, stateStore: $telemetryStore);
        $consumer->run(1);

        $this->assertCount(1, $telemetryStore->telemetry);
        $this->assertSame($event->eventId, $telemetryStore->telemetry[0]['event_id']);
        $this->assertSame('audit_created', $telemetryStore->telemetry[0]['event_type']);
        $this->assertSame('test.stream', $telemetryStore->telemetry[0]['stream']);
        $this->assertSame('test-consumer', $telemetryStore->telemetry[0]['consumer']);
        $this->assertSame('acked', $telemetryStore->telemetry[0]['status']);
        $this->assertArrayHasKey('queue_wait_ms', $telemetryStore->telemetry[0]);
        $this->assertArrayHasKey('handle_duration_ms', $telemetryStore->telemetry[0]);
        $this->assertArrayHasKey('ack_duration_ms', $telemetryStore->telemetry[0]);
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

    public function testTerminalFailureRunsHookBeforeAcknowledgingMessage(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000003-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->method('xAdd')->willReturn('1700000000004-0');
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000003-0');

        $consumer = new TerminalFailureConsumer(redis: $redis);
        $consumer->run(1);

        $this->assertSame([$event->eventId], $consumer->terminalFailureEventIds);
    }

    public function testSqlRetryExhaustionDeadLettersAndAcksImmediately(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4()
        );
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('incr')->willReturn(1);
        $redis->method('xReadGroupMulti')->willReturn([[
            'id' => '1700000000005-0',
            'stream' => 'test.stream',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->once())
            ->method('xAck')
            ->with('test.stream', 'test-group', '1700000000005-0');

        $publisher = new ConsumerRecordingPublisher();
        $error = new SqlServerOperationException(
            'default',
            'operation',
            SqlServerOperationMode::IDEMPOTENT_WRITE,
            4,
            '08S01',
            true,
            new \PDOException('SQLSTATE[08S01] Communication link failure')
        );
        $consumer = new SqlTerminalFailureConsumer($error, $redis, $publisher);

        $consumer->run(1);

        $this->assertCount(1, $publisher->deadLetters);
        $this->assertSame(AuditEvent::TYPE_DEAD_LETTER, $publisher->deadLetters[0]->eventType);
        $this->assertSame([$event->eventId], $consumer->terminalFailureEventIds);
    }
}

final class RecordingTelemetryStateStore extends AuditStateStore
{
    /** @var array<int,array<string,mixed>> */
    public array $telemetry = [];

    public function __construct()
    {
    }

    public function recordEventTelemetry(string $auditId, array $telemetry): bool
    {
        $this->telemetry[] = $telemetry;
        return true;
    }
}

final class MinimalConsumer extends AuditEventConsumer
{
    /** @var AuditEvent[] */
    public array $handled = [];

    protected function streams(): array
    {
        return ['test.stream'];
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

final class TerminalFailureConsumer extends AuditEventConsumer
{
    /** @var array<int,string> */
    public array $terminalFailureEventIds = [];

    protected function streams(): array
    {
        return ['test.stream'];
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
        throw new InvalidArgumentException('evento no recuperable');
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        $this->terminalFailureEventIds[] = $event->eventId;
    }
}

final class SqlTerminalFailureConsumer extends AuditEventConsumer
{
    /** @var array<int,string> */
    public array $terminalFailureEventIds = [];

    public function __construct(
        private SqlServerOperationException $failure,
        RedisClient $redis,
        AuditEventPublisher $publisher
    ) {
        parent::__construct($redis, $publisher);
    }

    protected function streams(): array
    {
        return ['test.stream'];
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
        throw $this->failure;
    }

    protected function afterTerminalFailure(AuditEvent $event, \Throwable $error): void
    {
        $this->terminalFailureEventIds[] = $event->eventId;
    }
}

final class ConsumerRecordingPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $deadLetters = [];

    public function __construct()
    {
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        $this->deadLetters[] = $event;

        return 'dead-letter-1';
    }
}
