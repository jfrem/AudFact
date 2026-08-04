<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditDlqController;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
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
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000000-0'],
            redis: $redis,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertCount(1, $publisher->published);
        $this->assertSame($original->eventId, $publisher->published[0]->eventId);
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
                'failed_stream' => AuditEventPublisher::STREAM_PERSISTENCE,
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
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000001-0'],
            redis: $redis,
            publisher: $publisher,
            persistenceQueue: $queue,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertSame([$original->eventId], $queue->reprocessedEventIds);
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
    ) {
    }

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

    private function createRedisFailure(): RedisClient
    {
        throw new RuntimeException('Redis no inyectado');
    }
}

final class DlqPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stream-' . count($this->published);
    }
}

final class DlqPersistenceQueue extends AuditPersistenceQueue
{
    /** @var array<int,string> */
    public array $reprocessedEventIds = [];

    public function __construct()
    {
    }

    public function reprocess(AuditEvent $event): int
    {
        $this->reprocessedEventIds[] = $event->eventId;
        return self::ENQUEUE_DISPATCHED;
    }
}
