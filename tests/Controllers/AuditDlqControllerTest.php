<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditDlqController;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Exceptions\HttpResponseException;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditDlqControllerTest extends TestCase
{
    public function testIndexListsDlqEvents(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: [
                'failed_event_type' => AuditEvent::TYPE_DOCUMENT_EXTRACTED,
                'failed_stage' => 'App\\Services\\Audit\\Pipeline\\DocumentExtractionWorker',
                'failed_stream' => 'audit.documents',
                'attempts' => 3,
                'last_error_code' => 'RUNTIME_EXCEPTION',
                'last_error_message' => 'Gemini invalid output',
                'original_event' => AuditEvent::create(
                    eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
                    auditId: AuditEvent::uuidV4(),
                    documentId: AuditEvent::uuidV4(),
                    payload: ['tipo_documento' => 'DISPENSA'],
                )->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('xRange')
            ->with('audit.dlq', '-', '+', 20)
            ->willReturn([[
                'id' => '1700000000000-0',
                'fields' => ['event' => $event->toJson()],
            ]]);

        $controller = new TestableAuditDlqController(redis: $redis);
        $response = self::captureResponse(static fn() => $controller->index());

        $this->assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertSame('audit.dlq', $data['stream']);
        $this->assertSame(1, $data['count']);
        $this->assertSame('1700000000000-0', $data['items'][0]['stream_id']);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_EXTRACTED, $data['items'][0]['failed_event_type']);
    }

    public function testReprocessRepublishesOriginalEvent(): void
    {
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: ['tipo_documento' => 'DISPENSA'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $original->auditId,
            documentId: $original->documentId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'Stage',
                'failed_stream' => 'audit.documents',
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('xRange')
            ->with('audit.dlq', '1700000000000-0', '1700000000000-0', 1)
            ->willReturn([[
                'id' => '1700000000000-0',
                'fields' => ['event' => $deadLetter->toJson()],
            ]]);

        $publisher = new DlqPublisher();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('reopenAuditForReprocess')->willReturn(true);
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000000-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertCount(1, $publisher->published);
        $reprocessed = $publisher->published[0];
        $this->assertNotSame($original->eventId, $reprocessed->eventId);
        $this->assertTrue(AuditEvent::isUuidV4($reprocessed->eventId));
        $this->assertSame($original->eventId, $reprocessed->parentEventId);
        $this->assertSame($original->eventType, $reprocessed->eventType);
        $this->assertSame($original->auditId, $reprocessed->auditId);
    }

    public function testReprocessRoutesRulesEvaluatedThroughPersistenceQueue(): void
    {
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            payload: ['final_status' => 'completed'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $original->auditId,
            jobId: $original->jobId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'Persistence',
                'failed_stream' => AuditEventPublisher::STREAM_PERSISTENCE_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000001-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);
        $publisher = new DlqPublisher();
        $queue = new DlqPersistenceQueue();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('reopenAuditForReprocess')->willReturn(true);
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('reopenAuditInJob')->willReturn(true);
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000001-0'],
            redis: $redis,
            publisher: $publisher,
            persistenceQueue: $queue,
            stateStore: $stateStore,
            jobStore: $jobStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertCount(1, $queue->reprocessedEvents);
        $reprocessed = $queue->reprocessedEvents[0];
        $this->assertNotSame($original->eventId, $reprocessed->eventId);
        $this->assertTrue(AuditEvent::isUuidV4($reprocessed->eventId));
        $this->assertSame($original->eventId, $reprocessed->parentEventId);
        $this->assertSame($original->eventType, $reprocessed->eventType);
        $this->assertSame([], $publisher->published);
    }

    public function testReprocessReturns404WhenEventMissing(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([]);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000000-0'],
            redis: $redis,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(404, $response->getCode());
    }

    public function testReprocessReopensFailedAuditBeforePublishing(): void
    {
        $auditId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            jobId: null,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            jobId: null,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000002-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = new DlqPublisher();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->with(
                $this->equalTo($auditId),
                $this->callback(fn($id) => AuditEvent::isUuidV4($id))
            )
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000002-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(200, $response->getCode());
        $this->assertCount(1, $publisher->published);
    }

    public function testReprocessReopensAuditInJobWhenJobIdPresent(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            jobId: $jobId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            jobId: $jobId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000003-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = new DlqPublisher();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->expects($this->once())
            ->method('reopenAuditInJob')
            ->with(
                $this->equalTo($jobId),
                $this->equalTo($auditId),
                $this->callback(fn($id) => AuditEvent::isUuidV4($id))
            )
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000003-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
            jobStore: $jobStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(200, $response->getCode());
    }

    public function testReprocessFailsClosedAndDoesNotPublishWhenReopenAuditReturnsFalse(): void
    {
        $auditId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000004-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = new DlqPublisher();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(false);
        $stateStore->method('getAudit')->willReturn(['status' => 'completed']);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000004-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(409, $response->getCode());
        $this->assertEmpty($publisher->published, 'No debe publicar evento si reapertura falla');
    }

    public function testReprocessFailsClosedCompensatesStateStoreAndDoesNotPublishWhenReopenJobReturnsFalse(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            jobId: $jobId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            jobId: $jobId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000005-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = new DlqPublisher();
        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);

        // QUAL-001: Compensación atómica — revertir via revertReprocess, no patchAudit
        $stateStore->expects($this->once())
            ->method('revertReprocess')
            ->with($auditId, $this->isType('string'))
            ->willReturn(true);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->expects($this->once())
            ->method('reopenAuditInJob')
            ->willReturn(false);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000005-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
            jobStore: $jobStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(409, $response->getCode());
        $this->assertEmpty($publisher->published, 'No debe publicar evento si reapertura de job falla');
    }

    public function testReprocessCompensatesWhenPublishFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000006-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new RuntimeException('Redis Stream connection failed'));

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);
        $stateStore->expects($this->once())
            ->method('revertReprocess')
            ->with($this->equalTo($auditId), $this->stringContains('Redis Stream connection failed'))
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000006-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(503, $response->getCode());
    }

    public function testReprocessCompensatesJobWhenPublishFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            jobId: $jobId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            jobId: $jobId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000007-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new RuntimeException('Publish error'));

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);
        $stateStore->expects($this->once())
            ->method('revertReprocess')
            ->with($this->equalTo($auditId), $this->stringContains('Publish error'))
            ->willReturn(true);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->expects($this->once())
            ->method('reopenAuditInJob')
            ->willReturn(true);
        $jobStore->expects($this->once())
            ->method('revertAuditReprocessInJob')
            ->with($this->equalTo($jobId), $this->equalTo($auditId))
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000007-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
            jobStore: $jobStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(503, $response->getCode());
    }

    public function testReprocessCompensatesWhenPersistenceQueueFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $auditId,
            payload: ['status' => 'evaluated'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'RulesEvaluation',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000008-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $persistenceQueue = $this->createMock(AuditPersistenceQueue::class);
        $persistenceQueue->expects($this->once())
            ->method('reprocess')
            ->willThrowException(new RuntimeException('Persistence error'));

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);
        $stateStore->expects($this->once())
            ->method('revertReprocess')
            ->with($this->equalTo($auditId), $this->stringContains('Persistence error'))
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000008-0'],
            redis: $redis,
            persistenceQueue: $persistenceQueue,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(503, $response->getCode());
    }

    public function testReprocessRecordsFailedReconciliationWhenCompensationFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $original = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            auditId: $auditId,
            payload: ['status' => 'extracted'],
        );
        $deadLetter = AuditEvent::create(
            eventType: AuditEvent::TYPE_DEAD_LETTER,
            auditId: $auditId,
            payload: [
                'failed_event_type' => $original->eventType,
                'failed_stage' => 'DocumentExtraction',
                'failed_stream' => AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
                'attempts' => 3,
                'original_event' => $original->toArray(),
            ]
        );

        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([[
            'id' => '1700000000009-0',
            'fields' => ['event' => $deadLetter->toJson()],
        ]]);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willThrowException(new RuntimeException('Network down'));

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('reopenAuditForReprocess')
            ->willReturn(true);
        // revertReprocess fails all attempts
        $stateStore->expects($this->exactly(3))
            ->method('revertReprocess')
            ->willReturn(false);
        // Should record failed reconciliation
        $stateStore->expects($this->once())
            ->method('recordFailedReconciliation')
            ->with($this->equalTo($auditId), $this->callback(function (array $data): bool {
                return ($data['state_reverted'] ?? null) === false
                    && str_contains((string) ($data['error'] ?? ''), 'Network down');
            }))
            ->willReturn(true);

        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000009-0'],
            redis: $redis,
            publisher: $publisher,
            stateStore: $stateStore,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());
        $this->assertSame(503, $response->getCode());
    }

    private static function captureResponse(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $e) {
            return $e;
        }

        self::fail('Se esperaba HttpResponseException');
    }
}

final class TestableAuditDlqController extends AuditDlqController
{
    public function __construct(
        private array $body = [],
        private ?RedisClient $redis = null,
        private ?AuditEventPublisher $publisher = null,
        private ?AuditPersistenceQueue $persistenceQueue = null,
        private ?AuditStateStore $stateStore = null,
        private ?BatchJobStore $jobStore = null,
    ) {}

    protected function getBody(): array
    {
        return $this->body;
    }

    protected function buildRedisClient(): RedisClient
    {
        return $this->redis ?? $this->createRedisFailure();
    }

    protected function buildEventPublisher(): AuditEventPublisher
    {
        return $this->publisher ?? new DlqPublisher();
    }

    protected function buildPersistenceQueue(): AuditPersistenceQueue
    {
        return $this->persistenceQueue ?? new DlqPersistenceQueue();
    }

    protected function buildStateStore(): AuditStateStore
    {
        return $this->stateStore ?? new AuditStateStore($this->buildRedisClient());
    }

    protected function buildJobStore(): BatchJobStore
    {
        return $this->jobStore ?? new BatchJobStore($this->buildRedisClient());
    }

    private function createRedisFailure(): RedisClient
    {
        throw new RuntimeException('Redis no inyectado');
    }
}

final class DlqPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct() {}

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stream-' . count($this->published);
    }
}

final class DlqPersistenceQueue extends AuditPersistenceQueue
{
    /** @var array<int,AuditEvent> */
    public array $reprocessedEvents = [];

    public function __construct() {}

    public function reprocess(AuditEvent $event): int
    {
        $this->reprocessedEvents[] = $event;
        return self::ENQUEUE_DISPATCHED;
    }
}
