<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\AuditBatchOrchestrator;
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

    public function testHandleIgnoresNonBatchRequestedEvent(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->never())->method('publish');
        $stateStore = $this->createMock(AuditStateStore::class);
        $jobStore = $this->createMock(BatchJobStore::class);

        $worker = new BatchRequestedWorker(
            $stateStore,
            $jobStore,
            $redis,
            $publisher,
            'test-batch-consumer'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            payload: []
        );

        $refMethod = new \ReflectionMethod($worker, 'handle');
        $refMethod->setAccessible(true);
        $refMethod->invoke($worker, $event);
    }

    public function testHandleBatchRequestedPublishesContinuationWhenHasMore(): void
    {
        putenv('AUDIT_BATCH_CHUNK_SIZE=1');
        try {
            $redis = $this->createMock(RedisClient::class);
            $redis->method('isAvailable')->willReturn(true);

            $jobId = AuditEvent::uuidV4();
            $initialEvent = AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_REQUESTED,
                auditId: null,
                jobId: $jobId,
                payload: [
                    'fac_nit_sec' => '2426',
                    'date_from' => '2026-06-01',
                    'date_to' => '2026-06-30',
                    'limit' => 2,
                    'source' => 'cron',
                    'chunk_size' => 1,
                ]
            );

            $invoicesModel = new class extends \App\Models\InvoicesModel {
                public function getInvoicesForAuditBatch(
                    int $facNitSec,
                    string $dateFrom,
                    string $dateTo,
                    int $limit = 100,
                    ?array $cursor = null
                ): array {
                    return [
                        ['NitSec' => $facNitSec, 'DisId' => '101', 'Dispensa' => 'D101', 'DisFecSol' => '2026-06-01T00:00:00'],
                        ['NitSec' => $facNitSec, 'DisId' => '102', 'Dispensa' => 'D102', 'DisFecSol' => '2026-06-02T00:00:00'],
                    ];
                }
            };

            $stateStore = $this->createMock(AuditStateStore::class);
            $stateStore->method('initAudit')->willReturn(true);
            $stateStore->method('patchAudit')->willReturn(true);

            $jobStore = $this->createMock(BatchJobStore::class);
            $jobStore->method('getJob')->willReturn(['job_id' => $jobId]);
            $jobStore->method('claimJobGenerationLock')->willReturn(true);
            $jobStore->method('claimAuditReservation')->willReturn(true);
            $jobStore->method('registerAuditInJob')->willReturn(true);
            $jobStore->method('patchJob')->willReturn(true);
            $jobStore->method('releaseJobGenerationLock')->willReturn(true);

            /** @var AuditEvent[] $publishedEvents */
            $publishedEvents = [];
            $publisher = $this->createMock(AuditEventPublisher::class);
            $publisher->method('publish')
                ->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
                    $publishedEvents[] = $event;
                    return $event->eventId;
                });

            $orchestrator = new AuditBatchOrchestrator(
                $stateStore,
                $jobStore,
                $publisher,
                $invoicesModel
            );

            $worker = new BatchRequestedWorker(
                $stateStore,
                $jobStore,
                $redis,
                $publisher,
                'test-batch-consumer',
                $orchestrator
            );

            $refMethod = new \ReflectionMethod($worker, 'handle');
            $refMethod->setAccessible(true);
            $refMethod->invoke($worker, $initialEvent);

            $continuationEvents = array_values(array_filter(
                $publishedEvents,
                fn (AuditEvent $e) => $e->eventType === AuditEvent::TYPE_BATCH_REQUESTED
            ));

            $this->assertCount(1, $continuationEvents);
            $continuation = $continuationEvents[0];
            $this->assertSame($jobId, $continuation->jobId);
            $this->assertSame($initialEvent->eventId, $continuation->parentEventId);
            $this->assertSame(2, $continuation->payload['chunk_index']);
            $this->assertSame(1, $continuation->payload['accumulated_total']);
            $this->assertSame('101', $continuation->payload['cursor']['disId']);
        } finally {
            putenv('AUDIT_BATCH_CHUNK_SIZE');
        }
    }

    public function testHandleBatchRequestedDoesNotPublishContinuationWhenFinished(): void
    {
        putenv('AUDIT_BATCH_CHUNK_SIZE=50');
        try {
            $redis = $this->createMock(RedisClient::class);
            $redis->method('isAvailable')->willReturn(true);

            $jobId = AuditEvent::uuidV4();
            $initialEvent = AuditEvent::create(
                eventType: AuditEvent::TYPE_BATCH_REQUESTED,
                auditId: null,
                jobId: $jobId,
                payload: [
                    'fac_nit_sec' => '2426',
                    'date_from' => '2026-06-01',
                    'date_to' => '2026-06-30',
                    'limit' => 1,
                ]
            );

            $invoicesModel = new class extends \App\Models\InvoicesModel {
                public function getInvoicesForAuditBatch(
                    int $facNitSec,
                    string $dateFrom,
                    string $dateTo,
                    int $limit = 100,
                    ?array $cursor = null
                ): array {
                    return [
                        ['NitSec' => $facNitSec, 'DisId' => '101', 'Dispensa' => 'D101', 'DisFecSol' => '2026-06-01T00:00:00'],
                    ];
                }
            };

            $stateStore = $this->createMock(AuditStateStore::class);
            $stateStore->method('initAudit')->willReturn(true);
            $stateStore->method('patchAudit')->willReturn(true);

            $jobStore = $this->createMock(BatchJobStore::class);
            $jobStore->method('getJob')->willReturn(['job_id' => $jobId]);
            $jobStore->method('claimJobGenerationLock')->willReturn(true);
            $jobStore->method('claimAuditReservation')->willReturn(true);
            $jobStore->method('registerAuditInJob')->willReturn(true);
            $jobStore->method('sealJob')->willReturn(true);
            $jobStore->method('releaseJobGenerationLock')->willReturn(true);

            /** @var AuditEvent[] $publishedEvents */
            $publishedEvents = [];
            $publisher = $this->createMock(AuditEventPublisher::class);
            $publisher->method('publish')
                ->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
                    $publishedEvents[] = $event;
                    return $event->eventId;
                });

            $orchestrator = new AuditBatchOrchestrator(
                $stateStore,
                $jobStore,
                $publisher,
                $invoicesModel
            );

            $worker = new BatchRequestedWorker(
                $stateStore,
                $jobStore,
                $redis,
                $publisher,
                'test-batch-consumer',
                $orchestrator
            );

            $refMethod = new \ReflectionMethod($worker, 'handle');
            $refMethod->setAccessible(true);
            $refMethod->invoke($worker, $initialEvent);

            $continuationEvents = array_filter(
                $publishedEvents,
                fn (AuditEvent $e) => $e->eventType === AuditEvent::TYPE_BATCH_REQUESTED
            );

            $this->assertEmpty($continuationEvents);
        } finally {
            putenv('AUDIT_BATCH_CHUNK_SIZE');
        }
    }
}
