<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditLane;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class TestLaneConsumer extends AuditEventConsumer
{
    public function __construct(
        ?RedisClient $redis = null,
        string|AuditLane|null $lane = null
    ) {
        parent::__construct($redis, lane: $lane);
    }

    protected function streams(): array
    {
        return [
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
            AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
        ];
    }

    protected function group(): string
    {
        return 'test-group';
    }

    protected function consumer(): string
    {
        return self::defaultConsumerName('test-role', $this->lane);
    }

    protected function handle(AuditEvent $event): void
    {
    }

    public function testDefaultConsumerName(string $role, string|AuditLane $lane = AuditLane::ALL): string
    {
        return self::defaultConsumerName($role, $lane);
    }
}

final class TestSingleStreamConsumer extends AuditEventConsumer
{
    public function __construct(
        ?RedisClient $redis = null,
        string|AuditLane|null $lane = null
    ) {
        parent::__construct($redis, lane: $lane);
    }

    protected function streams(): array
    {
        return ['audit.single.stream'];
    }

    protected function group(): string
    {
        return 'test-single-group';
    }

    protected function consumer(): string
    {
        return 'test-single-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
    }
}

final class TestMixedStreamConsumer extends AuditEventConsumer
{
    public function __construct(
        ?RedisClient $redis = null,
        string|AuditLane|null $lane = null
    ) {
        parent::__construct($redis, lane: $lane);
    }

    protected function streams(): array
    {
        return [
            'audit.common',
            'audit.custom.priority',
            'audit.custom.batch',
        ];
    }

    protected function group(): string
    {
        return 'test-mixed-group';
    }

    protected function consumer(): string
    {
        return 'test-mixed-consumer';
    }

    protected function handle(AuditEvent $event): void
    {
    }
}

final class AuditEventConsumerLaneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::clearCache();
    }

    protected function tearDown(): void
    {
        Env::set('AUDIT_WORKER_LANE', null);
        Env::clearCache();
        parent::tearDown();
    }

    public function testActiveStreamsReturnsAllWhenLaneIsAll(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: AuditEventConsumer::LANE_ALL);

        $this->assertSame(AuditEventConsumer::LANE_ALL, $consumer->getLane());
        $this->assertSame(AuditLane::ALL, $consumer->getLaneEnum());
        $this->assertSame([
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
            AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
        ], $consumer->activeStreams());
    }

    public function testActiveStreamsFiltersPriorityOnly(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: AuditEventConsumer::LANE_PRIORITY);

        $this->assertSame(AuditEventConsumer::LANE_PRIORITY, $consumer->getLane());
        $this->assertSame(AuditLane::PRIORITY, $consumer->getLaneEnum());
        $this->assertSame([
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
        ], $consumer->activeStreams());
    }

    public function testActiveStreamsFiltersBatchOnly(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: AuditEventConsumer::LANE_BATCH);

        $this->assertSame(AuditEventConsumer::LANE_BATCH, $consumer->getLane());
        $this->assertSame(AuditLane::BATCH, $consumer->getLaneEnum());
        $this->assertSame([
            AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
        ], $consumer->activeStreams());
    }

    public function testActiveStreamsFallbacksToAllWhenNoMatch(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestSingleStreamConsumer(redis: $redis, lane: AuditEventConsumer::LANE_PRIORITY);

        // Como 'audit.single.stream' no contiene ':priority' ni ':batch', no se descarta
        $this->assertSame(['audit.single.stream'], $consumer->activeStreams());
    }

    public function testActiveStreamsWithMixedStreamsKeepsNeutralStreams(): void
    {
        $redis = $this->createMock(RedisClient::class);

        $priorityConsumer = new TestMixedStreamConsumer(redis: $redis, lane: AuditLane::PRIORITY);
        $this->assertSame([
            'audit.common',
            'audit.custom.priority',
        ], $priorityConsumer->activeStreams());

        $batchConsumer = new TestMixedStreamConsumer(redis: $redis, lane: AuditLane::BATCH);
        $this->assertSame([
            'audit.common',
            'audit.custom.batch',
        ], $batchConsumer->activeStreams());
    }

    public function testConsumerAcceptsAuditLaneEnumDirectly(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: AuditLane::PRIORITY);

        $this->assertSame('priority', $consumer->getLane());
        $this->assertSame(AuditLane::PRIORITY, $consumer->getLaneEnum());
    }

    public function testInvalidLaneFallsBackToAll(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: 'carril_inexistente');

        $this->assertSame(AuditEventConsumer::LANE_ALL, $consumer->getLane());
        $this->assertSame(AuditLane::ALL, $consumer->getLaneEnum());
    }

    public function testLaneResolutionFromEnvCleanly(): void
    {
        Env::set('AUDIT_WORKER_LANE', 'priority');

        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis);

        $this->assertSame(AuditEventConsumer::LANE_PRIORITY, $consumer->getLane());
        $this->assertSame(AuditLane::PRIORITY, $consumer->getLaneEnum());
        $this->assertSame([
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
        ], $consumer->activeStreams());
    }

    public function testDefaultConsumerNameIncludesLaneSuffix(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $consumer = new TestLaneConsumer(redis: $redis, lane: AuditEventConsumer::LANE_PRIORITY);

        $nameAll = $consumer->testDefaultConsumerName('worker', AuditLane::ALL);
        $namePriority = $consumer->testDefaultConsumerName('worker', AuditLane::PRIORITY);
        $nameBatch = $consumer->testDefaultConsumerName('worker', AuditLane::BATCH);

        $this->assertStringNotContainsString('-all-', $nameAll);
        $this->assertStringStartsWith('worker-', $nameAll);

        $this->assertStringStartsWith('worker-priority-', $namePriority);
        $this->assertStringStartsWith('worker-batch-', $nameBatch);

        // Validar también con compatibilidad string legacy
        $this->assertSame($namePriority, $consumer->testDefaultConsumerName('worker', 'priority'));
        $this->assertSame($nameBatch, $consumer->testDefaultConsumerName('worker', 'batch'));
    }

    public function testAuditLaneEnumMethods(): void
    {
        $this->assertSame(AuditLane::PRIORITY, AuditLane::fromString('  PRIORITY  '));
        $this->assertSame(AuditLane::BATCH, AuditLane::fromString('BaTcH'));
        $this->assertSame(AuditLane::ALL, AuditLane::fromString('all'));
        $this->assertSame(AuditLane::ALL, AuditLane::fromString(''));
        $this->assertSame(AuditLane::ALL, AuditLane::fromString(null));
        $this->assertSame(AuditLane::ALL, AuditLane::fromString('unknown_value'));

        $this->assertTrue(AuditLane::PRIORITY->isPriority());
        $this->assertFalse(AuditLane::PRIORITY->isBatch());
        $this->assertFalse(AuditLane::PRIORITY->isAll());

        $this->assertTrue(AuditLane::BATCH->isBatch());
        $this->assertFalse(AuditLane::BATCH->isPriority());
        $this->assertFalse(AuditLane::BATCH->isAll());

        $this->assertTrue(AuditLane::ALL->isAll());
        $this->assertFalse(AuditLane::ALL->isPriority());
        $this->assertFalse(AuditLane::ALL->isBatch());

        $this->assertTrue(AuditLane::PRIORITY->matchesStream('stream.priority'));
        $this->assertTrue(AuditLane::PRIORITY->matchesStream('stream:priority'));
        $this->assertFalse(AuditLane::PRIORITY->matchesStream('stream.batch'));
        $this->assertTrue(AuditLane::PRIORITY->matchesStream('stream.neutral'));

        $this->assertTrue(AuditLane::BATCH->matchesStream('stream.batch'));
        $this->assertTrue(AuditLane::BATCH->matchesStream('stream:batch'));
        $this->assertFalse(AuditLane::BATCH->matchesStream('stream.priority'));
        $this->assertTrue(AuditLane::BATCH->matchesStream('stream.neutral'));
    }
}
