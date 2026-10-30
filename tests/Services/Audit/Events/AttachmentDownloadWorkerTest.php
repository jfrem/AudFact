<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AttachmentDownloadService;
use App\Services\Audit\Pipeline\AttachmentDownloadException;
use App\Services\Audit\Pipeline\AttachmentDownloadWorker;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttachmentDownloadWorkerTest extends TestCase
{
    public function testStoppedWorkerDoesNotReadOrDownloadWork(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('serverConfiguration')->willReturn(['appendonly' => 'yes', 'appendfsync' => 'always', 'maxmemory-policy' => 'noeviction']);
        $redis->method('serverInfo')->willReturn(['aof_last_write_status' => 'ok']);
        $redis->expects(self::never())->method('xReadGroupMulti');
        $downloader = $this->createMock(AttachmentDownloadService::class);
        $downloader->expects(self::never())->method('download');
        $worker = new AttachmentDownloadWorker(redis: $redis, downloader: $downloader,
            publisher: new DownloadPublisher(), consumerName: 'prefetch-startup-fixture');
        $worker->requestStop();
        // Act:
        $processed = $worker->run();

        // Assert:
        self::assertSame(0, $processed);
    }

    public function testPublishesDocumentDownloadedAfterPersistingBlob(): void
    {
        // Arrange:
        $document = $this->downloadedDocument();
        $documentHash = hash('sha256', $document['data']);
        $publisher = new DownloadPublisher();
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->exactly(2))->method('eval')->willReturnCallback(
            static function (string $script, array $keys, array $arguments) use ($document): int {
                if ($keys[0] === 'telemetry:document_cache') {
                    self::assertSame('admitted', $arguments[0]);
                    return 1;
                }
                self::assertStringStartsWith('audit:blob:', $keys[0]);
                self::assertSame($document, json_decode($arguments[0], true));
                self::assertSame(60, $arguments[4]);
                return 1;
            }
        );

        $worker = new AttachmentDownloadWorker(
            stateStore: new DownloadRecordingStateStore(),
            downloader: new StubAttachmentDownloadService($document),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-test',
            blobTtl: 60
        );

        // Act:
        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        // Assert:
        $this->assertCount(1, $publisher->published);
        $event = $publisher->published[0];
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $event->eventType);
        $this->assertSame($documentHash, $event->payload['document_hash']);
        $this->assertStringStartsWith("audit:blob:{$auditId}:{$documentId}:", $event->payload['blob_reference_key']);
        $this->assertArrayNotHasKey('data', $event->payload);
        $this->assertTrue($event->payload['blob_cache_stored']);
    }

    public function testDownloadFailurePropagatesWithoutPublishingDocumentRejected(): void
    {
        // Arrange:
        $stateStore = new DownloadRecordingStateStore();
        $publisher = new DownloadPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('eval');

        $worker = new AttachmentDownloadWorker(
            stateStore: $stateStore,
            downloader: new StubAttachmentDownloadService(error: new AttachmentDownloadException(
                AttachmentDownloadException::SOURCE_NOT_FOUND,
                'Adjunto no disponible'
            )),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-test',
            blobTtl: 60
        );

        // Assert:
        $this->expectException(AttachmentDownloadException::class);
        $this->expectExceptionMessage('Adjunto no disponible');

        // Act:
        try {
            $worker->processEvent(
                $this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4())
            );
        } finally {
            $this->assertSame([], $stateStore->lastRejectedPatch);
            $this->assertSame([], $publisher->published);
        }
    }

    public function testBlobPersistenceFailureThrowsAndDoesNotPublishDownloaded(): void
    {
        // Arrange:
        $publisher = new DownloadPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('eval')->willThrowException(new RuntimeException('Redis no disponible'));

        $worker = new AttachmentDownloadWorker(
            stateStore: new DownloadRecordingStateStore(),
            downloader: new StubAttachmentDownloadService($this->downloadedDocument()),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-test',
            blobTtl: 60
        );

        // Assert:
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis no disponible');

        // Act:
        try {
            $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testFullCachePublishesRecoverableReferenceWithoutDocumentBytes(): void
    {
        // Arrange:
        $publisher = new DownloadPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturn(0);
        $worker = new AttachmentDownloadWorker(
            downloader: new StubAttachmentDownloadService($this->downloadedDocument()),
            redis: $redis,
            publisher: $publisher
        );
        $event = $this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4());

        // Act:
        $worker->processEvent($event);

        // Assert:
        $this->assertCount(1, $publisher->published);
        $payload = $publisher->published[0]->payload;
        $this->assertFalse($payload['blob_cache_stored']);
        $this->assertSame($event->payload['attachment_id'], $payload['attachment_id']);
        $this->assertSame($event->payload['dis_det_nro'], $payload['dis_det_nro']);
        $this->assertSame(hash('sha256', $this->downloadedDocument()['data']), $payload['document_hash']);
        $this->assertArrayNotHasKey('data', $payload);
    }

    /**
     * @return array{mime:string,data:string,duration_ms:int}
     */
    private function downloadedDocument(): array
    {
        return [
            'mime' => 'application/pdf',
            'data' => base64_encode("%PDF-1.4\n1 0 obj\n<</Type /Page>>\nendobj\n"),
            'duration_ms' => 25,
        ];
    }

    private function documentRegisteredEvent(string $auditId, string $documentId): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: $auditId,
            jobId: AuditEvent::uuidV4(),
            documentId: $documentId,
            payload: [
                'attachment_id' => 'att-1',
                'dis_det_nro' => 'T38250701547',
                'tipo_documento' => 'FORMULA MEDICA',
            ],
        );
    }
}

final class DownloadRecordingStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    public array $lastRejectedPatch = [];

    public function __construct()
    {
    }

    public function markDocumentRejected(string $auditId, string $documentId, array $patch): bool
    {
        $this->lastRejectedPatch = $patch;
        return true;
    }
}

final class DownloadPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return $event->eventId;
    }
}

final class StubAttachmentDownloadService extends AttachmentDownloadService
{
    /**
     * @param array<string,mixed>|null $document
     */
    public function __construct(
        private ?array $document = null,
        private ?RuntimeException $error = null
    ) {
    }

    public function download(string $attachmentId, string $disDetNro): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->document ?? [];
    }
}
