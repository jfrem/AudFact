<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use App\Services\Audit\Pipeline\BatchRequestedWorker;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class BatchRequestedWorkerTest extends TestCase
{
    public function testBatchRequestedWorkerStreamsAndGroup(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $stateStore = $this->createMock(AuditStateStore::class);
        $jobStore = $this->createMock(BatchJobStore::class);

        $worker = new BatchRequestedWorker(
            $stateStore,
            $jobStore,
            $redis,
            $publisher,
            'test-batch-consumer'
        );

        $refMethod = new \ReflectionMethod($worker, 'streams');
        $refMethod->setAccessible(true);
        $this->assertSame([AuditEventPublisher::STREAM_BATCH_INBOX], $refMethod->invoke($worker));

        $refGroup = new \ReflectionMethod($worker, 'group');
        $refGroup->setAccessible(true);
        $this->assertSame(AuditEventPublisher::GROUP_BATCH, $refGroup->invoke($worker));

        $refConsumer = new \ReflectionMethod($worker, 'consumer');
        $refConsumer->setAccessible(true);
        $this->assertSame('test-batch-consumer', $refConsumer->invoke($worker));
    }

    public function testBatchRequestedWorkerSets30MinuteReclaimTimeout(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $stateStore = $this->createMock(AuditStateStore::class);
        $jobStore = $this->createMock(BatchJobStore::class);

        $worker = new BatchRequestedWorker(
            $stateStore,
            $jobStore,
            $redis,
            $publisher,
            'test-batch-consumer'
        );

        $refIdle = new ReflectionProperty(\App\Services\Audit\Pipeline\AuditEventConsumer::class, 'pendingReclaimIdleMs');
        $refIdle->setAccessible(true);
        $this->assertSame(1800000, $refIdle->getValue($worker));

        $refInterval = new ReflectionProperty(\App\Services\Audit\Pipeline\AuditEventConsumer::class, 'pendingReclaimIntervalMs');
        $refInterval->setAccessible(true);
        $this->assertSame(60000, $refInterval->getValue($worker));
    }
}
