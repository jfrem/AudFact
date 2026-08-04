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
    public function testPublishesDocumentDownloadedAfterPersistingBlob(): void
    {
        $document = $this->downloadedDocument();
        $documentHash = hash('sha256', $document['data']);
        $publisher = new DownloadPublisher();
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                $this->callback(static fn(string $key): bool => str_starts_with($key, 'audit:blob:')
                    && !str_starts_with($key, 'audfact:')),
                $this->callback(static function (string $payload) use ($document): bool {
                    $decoded = json_decode($payload, true);
                    return $decoded === $document;
                }),
                60
            )
            ->willReturn(true);

        $worker = new AttachmentDownloadWorker(
            stateStore: new DownloadRecordingStateStore(),
            downloader: new StubAttachmentDownloadService($document),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-test',
            blobTtl: 60
        );

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        $this->assertCount(1, $publisher->published);
        $event = $publisher->published[0];
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, $event->eventType);
        $this->assertSame($documentHash, $event->payload['document_hash']);
        $this->assertStringStartsWith("audit:blob:{$auditId}:{$documentId}:", $event->payload['blob_reference_key']);
        $this->assertArrayNotHasKey('data', $event->payload);
    }

    public function testDownloadFailurePropagatesWithoutPublishingDocumentRejected(): void
    {
        $stateStore = new DownloadRecordingStateStore();
        $publisher = new DownloadPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->never())->method('set');

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

        $this->expectException(AttachmentDownloadException::class);
        $this->expectExceptionMessage('Adjunto no disponible');

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
        $publisher = new DownloadPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())->method('set')->willReturn(false);

        $worker = new AttachmentDownloadWorker(
            stateStore: new DownloadRecordingStateStore(),
            downloader: new StubAttachmentDownloadService($this->downloadedDocument()),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-test',
            blobTtl: 60
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo persistir BLOB documental en Redis');

        try {
            $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
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
