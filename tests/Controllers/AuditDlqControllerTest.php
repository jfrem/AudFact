<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditDlqController;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use Core\Exceptions\HttpResponseException;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testReprocessCreatesIndependentAuditWithFreshEventIdentity(): void
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

        $store = $this->createMock(AuditStateStore::class);
        $newAuditId = AuditEvent::uuidV4();
        $newEventId = AuditEvent::uuidV4();
        $store->expects($this->once())->method('recoverFailedAudit')->with(
            $this->callback(static function (AuditEvent $recovery) use ($original): bool {
                return $recovery->eventType === AuditEvent::TYPE_AUDIT_CREATED
                    && $recovery->eventId !== $original->eventId && $recovery->auditId !== $original->auditId
                    && $recovery->jobId === null && $recovery->parentEventId === $original->eventId
                    && $recovery->payload['is_priority'] === false;
            }), $original->auditId
        )->willReturn(['audit_id' => $newAuditId, 'original_audit_id' => $original->auditId,
            'reprocessed_event_id' => $newEventId, 'event_type' => AuditEvent::TYPE_AUDIT_CREATED]);
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000000-0'],
            redis: $redis,
            stateStore: $store,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertSame($newAuditId, $response->getData()['data']['audit_id']);
        $this->assertSame($newEventId, $response->getData()['data']['reprocessed_event_id']);
    }

    public function testFailedPersistenceRestartsTheAuditWithoutReopeningHistoricalJob(): void
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
        $store = $this->createMock(AuditStateStore::class);
        $store->expects($this->once())->method('recoverFailedAudit')->with(
            $this->callback(static fn(AuditEvent $recovery): bool => $recovery->eventType === AuditEvent::TYPE_AUDIT_CREATED
                && $recovery->jobId === null), $original->auditId
        )->willReturn(['audit_id' => AuditEvent::uuidV4(), 'event_type' => AuditEvent::TYPE_AUDIT_CREATED]);
        $controller = new TestableAuditDlqController(
            body: ['streamId' => '1700000000001-0'],
            redis: $redis,
            stateStore: $store,
        );

        $response = self::captureResponse(static fn() => $controller->reprocess());

        $this->assertSame(200, $response->getCode());
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $response->getData()['data']['event_type']);
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

    #[DataProvider('recoveryErrors')]
    public function testRecoveryConflictAndInfrastructureFailureAreDistinct(int $code, int $expectedStatus): void
    {
        // Arrange:
        $original = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, AuditEvent::uuidV4());
        $dlq = AuditEvent::create(AuditEvent::TYPE_DEAD_LETTER, $original->auditId,
            payload: ['original_event' => $original->toArray()]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('xRange')->willReturn([['id' => '1-0', 'fields' => ['event' => $dlq->toJson()]]]);
        $store = $this->createMock(AuditStateStore::class);
        $store->method('recoverFailedAudit')->willThrowException(new RuntimeException('Recuperación no disponible', $code));
        $controller = new TestableAuditDlqController(body: ['streamId' => '1-0'], redis: $redis, stateStore: $store);

        // Act:
        $response = self::captureResponse(static fn() => $controller->reprocess());

        // Assert:
        self::assertSame($expectedStatus, $response->getCode());
    }

    public static function recoveryErrors(): iterable
    {
        yield 'terminal state conflict' => [409, 409];
        yield 'Redis failure' => [0, 503];
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
        private ?AuditStateStore $stateStore = null,
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

    protected function buildStateStore(): AuditStateStore
    {
        return $this->stateStore ?? new AuditStateStore($this->buildRedisClient());
    }

    private function createRedisFailure(): RedisClient
    {
        throw new RuntimeException('Redis no inyectado');
    }
}
