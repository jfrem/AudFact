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

    public function testWorkerCanBeInstantiatedWithoutGoogleDriveEnv(): void
    {
        $originalEmail = \Core\Env::get('GOOGLE_DRIVE_CLIENT_EMAIL');
        $originalKey   = \Core\Env::get('GOOGLE_DRIVE_PRIVATE_KEY');
        \Core\Env::set('GOOGLE_DRIVE_CLIENT_EMAIL', '');
        \Core\Env::set('GOOGLE_DRIVE_PRIVATE_KEY', '');

        try {
            $redis = $this->createMock(RedisClient::class);
            $redis->method('isAvailable')->willReturn(true);
            $redis->method('serverConfiguration')->willReturn(['appendonly' => 'yes', 'appendfsync' => 'always', 'maxmemory-policy' => 'noeviction']);
            $redis->method('serverInfo')->willReturn(['aof_last_write_status' => 'ok']);

            $worker = new AttachmentDownloadWorker(
                redis: $redis,
                publisher: new DownloadPublisher(),
                consumerName: 'lazy-drive-test'
            );

            self::assertInstanceOf(AttachmentDownloadWorker::class, $worker);
        } finally {
            \Core\Env::set('GOOGLE_DRIVE_CLIENT_EMAIL', (string) $originalEmail);
            \Core\Env::set('GOOGLE_DRIVE_PRIVATE_KEY', (string) $originalKey);
        }
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

    public function testWorkerForwardsPhysicalAttachmentMetadataToDownloader(): void
    {
        $stateStore = new DownloadRecordingStateStore();
        $publisher = new DownloadPublisher();
        $downloader = new StubAttachmentDownloadService(document: [
            'mime' => 'application/pdf',
            'data' => base64_encode('test-pdf-bytes'),
            'duration_ms' => 12,
        ]);
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(1);

        $worker = new AttachmentDownloadWorker(
            stateStore: $stateStore,
            downloader: $downloader,
            redis: $redis,
            publisher: $publisher,
            consumerName: 'downloader-metadata-test',
            blobTtl: 60
        );

        $metadata = [
            'TipoAlmacenamiento' => 'URL',
            'AdjDisDocUrl' => 'drive-id-12345',
            'AdjDisNom' => 'formula.pdf',
            'BlobSize' => null,
        ];

        $event = AuditEvent::fromArray([
            'event_id' => AuditEvent::uuidV4(),
            'audit_id' => AuditEvent::uuidV4(),
            'event_type' => AuditEvent::TYPE_DOCUMENT_REGISTERED,
            'timestamp' => '2026-09-24T12:00:00Z',
            'payload' => [
                'attachment_id' => 'att-99',
                'dis_det_nro' => 'DIS-999',
                'physical_attachment' => $metadata,
            ],
            'document_id' => 'doc-1',
        ]);

        $worker->processEvent($event);

        $this->assertSame('att-99', $downloader->lastAttachmentId);
        $this->assertSame('DIS-999', $downloader->lastDisDetNro);
        $this->assertSame($metadata, $downloader->lastMetadata);
        $this->assertCount(1, $publisher->published);
    }

    public function testPutsBlobInLaneIndexMatchingEventPriority(): void
    {
        // Arrange:
        $calls = [];
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturnCallback(static function (string $script, array $keys) use (&$calls): int {
            $calls[] = $keys;
            return 1;
        });
        $worker = new AttachmentDownloadWorker(
            stateStore: new DownloadRecordingStateStore(),
            downloader: new StubAttachmentDownloadService($this->downloadedDocument()),
            redis: $redis,
            publisher: new DownloadPublisher(),
            consumerName: 'downloader-lane-test',
            blobTtl: 60
        );
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            documentId: AuditEvent::uuidV4(),
            payload: ['attachment_id' => 'att-1', 'dis_det_nro' => 'T38250701547', 'is_priority' => true],
        );

        // Act:
        $worker->processEvent($event);

        // Assert: el BLOB prioritario se admite solo en el índice del carril prioritario.
        $blobCalls = array_values(array_filter($calls, static fn(array $keys): bool => str_starts_with($keys[0], 'audit:blob:')));
        self::assertCount(1, $blobCalls);
        self::assertSame('audit:document-cache:{budget}:priority:expires', $blobCalls[0][1]);
    }

    public function testLaneAllProcessRequiresBothGatesOpen(): void
    {
        // Arrange: el carril prioritario está al límite; el batch tiene capacidad.
        $priorityFull = $this->createStub(RedisClient::class);
        $priorityFull->method('eval')->willReturnCallback(
            static fn(string $script, array $keys): array => str_contains($keys[1], ':priority:') ? [0, 1000000000000, 0] : [0, 0, 0]
        );
        $priorityClosed = new AttachmentDownloadWorker(redis: $priorityFull, publisher: new DownloadPublisher(),
            consumerName: 'lane-all-closed');
        $bothOpen = $this->createStub(RedisClient::class);
        $bothOpen->method('eval')->willReturn([0, 0, 0]);
        $open = new AttachmentDownloadWorker(redis: $bothOpen, publisher: new DownloadPublisher(),
            consumerName: 'lane-all-open');
        $canRead = new \ReflectionMethod(AttachmentDownloadWorker::class, 'canReadNewMessages');

        // Act / Assert:
        self::assertFalse($canRead->invoke($priorityClosed));
        self::assertTrue($canRead->invoke($open));
    }

    public function testXReadGroupRequestsConfiguredReadCount(): void
    {
        // Arrange:
        $previous = (string) \Core\Env::get('AUDIT_DOCUMENT_STREAM_READ_COUNT', '');
        \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', '10');
        \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
        try {
            $redis = $this->createMock(RedisClient::class);
            $redis->method('isAvailable')->willReturn(true);
            $redis->method('serverConfiguration')->willReturn(['appendonly' => 'yes', 'appendfsync' => 'always', 'maxmemory-policy' => 'noeviction']);
            $redis->method('serverInfo')->willReturn(['aof_last_write_status' => 'ok']);
            $redis->method('eval')->willReturn([0, 0, 0]);
            $worker = new AttachmentDownloadWorker(redis: $redis, publisher: new DownloadPublisher(),
                consumerName: 'read-count-run-test');
            $counts = [];
            $redis->expects(self::once())->method('xReadGroupMulti')->willReturnCallback(
                static function (string $group, string $consumer, array $streams, int $count) use (&$counts, $worker): array {
                    $counts[] = $count;
                    $worker->requestStop();
                    return [];
                }
            );

            // Act:
            $worker->run();

            // Assert: CA-F3-08, COUNT = AUDIT_DOCUMENT_STREAM_READ_COUNT.
            self::assertSame([10], $counts);
        } finally {
            \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', $previous === '' ? null : $previous);
            \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
        }
    }

    public function testReadCountComesFromConfiguration(): void
    {
        // Arrange:
        $previous = (string) \Core\Env::get('AUDIT_DOCUMENT_STREAM_READ_COUNT', '');
        $readCount = new \ReflectionMethod(AttachmentDownloadWorker::class, 'readCount');
        $worker = new AttachmentDownloadWorker(redis: $this->createStub(RedisClient::class),
            publisher: new DownloadPublisher(), consumerName: 'read-count-test');
        try {
            // Act / Assert: sin variable, 5 (DEC-F3-07); con valor explícito, ese valor.
            \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', null);
            \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
            self::assertSame(5, $readCount->invoke($worker));

            \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', '10');
            \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
            self::assertSame(10, $readCount->invoke($worker));

            \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', '1');
            \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
            self::assertSame(1, $readCount->invoke($worker));
        } finally {
            \Core\Env::set('AUDIT_DOCUMENT_STREAM_READ_COUNT', $previous === '' ? null : $previous);
            \Core\Env::clearCache('AUDIT_DOCUMENT_STREAM_READ_COUNT');
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

    public ?array $lastMetadata = null;
    public ?string $lastAttachmentId = null;
    public ?string $lastDisDetNro = null;

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

    public ?array $lastMetadata = null;
    public ?string $lastAttachmentId = null;
    public ?string $lastDisDetNro = null;

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
    public ?array $lastMetadata = null;
    public ?string $lastAttachmentId = null;
    public ?string $lastDisDetNro = null;

    /**
     * @param array<string,mixed>|null $document
     */
    public function __construct(
        private ?array $document = null,
        private ?RuntimeException $error = null
    ) {
    }

    public function download(string $attachmentId, string $disDetNro, ?array $metadata = null): array
    {
        $this->lastAttachmentId = $attachmentId;
        $this->lastDisDetNro = $disDetNro;
        $this->lastMetadata = $metadata;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->document ?? [];
    }
}
