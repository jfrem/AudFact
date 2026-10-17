<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AttachmentDownloadException;
use App\Services\Audit\Pipeline\AttachmentDownloadService;
use App\Services\Audit\Pipeline\AuditDocumentCache;
use App\Services\Audit\Pipeline\AuditDocumentInput;
use App\Services\Audit\Pipeline\AuditEvent;
use Core\RedisClient;
use Core\RedisUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditDocumentInputTest extends TestCase
{
    public function testAvailableBlobAvoidsAnotherDownload(): void
    {
        // Arrange:
        $document = self::document();
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(json_encode($document, JSON_THROW_ON_ERROR));
        $downloader = $this->createMock(AttachmentDownloadService::class);
        $downloader->expects($this->never())->method('download');
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $result = $input->resolve(self::event());

        // Assert:
        $this->assertSame($document, $result);
    }

    public function testExpiredOrUncachedBlobRecoversTheSameOriginalDocument(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(null);
        $redis->method('eval')->willReturn(1);
        $downloader = $this->createMock(AttachmentDownloadService::class);
        $downloader->expects($this->once())->method('download')->with('att-fixture', 'FAC-FIXTURE')
            ->willReturn(self::document());
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $result = $input->resolve(self::event());

        // Assert:
        $this->assertSame(self::document(), $result);
    }

    public function testChangedSourceFailsInsteadOfAuditingDifferentBytes(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(null);
        $downloader = $this->createStub(AttachmentDownloadService::class);
        $downloader->method('download')->willReturn(['mime' => 'application/pdf', 'data' => base64_encode('OTHER')]);
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $this->expectException(RuntimeException::class);

        // Assert:
        $this->expectExceptionMessage('document_hash no coincide con el BLOB descargado');
        $input->resolve(self::event());
    }

    public function testSourceTransferFailureKeepsItsTechnicalClassification(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(null);
        $downloader = $this->createStub(AttachmentDownloadService::class);
        $downloader->method('download')->willThrowException(new AttachmentDownloadException(
            AttachmentDownloadException::INCOMPLETE_TRANSFER, 'Transferencia incompleta'
        ));
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $this->expectException(AttachmentDownloadException::class);

        // Assert:
        $this->expectExceptionMessage('Transferencia incompleta');
        $input->resolve(self::event());
    }

    public function testRedisFailureDoesNotPretendThatTheBlobExpired(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willThrowException(new RedisUnavailableException('Redis inaccesible'));
        $downloader = $this->createMock(AttachmentDownloadService::class);
        $downloader->expects($this->never())->method('download');
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $this->expectException(RedisUnavailableException::class);

        // Assert:
        $this->expectExceptionMessage('Redis inaccesible');
        $input->resolve(self::event());
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedCachedInputFailsBeforeSourceOrGemini(array $document, string $message): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('get')->willReturn(json_encode($document, JSON_THROW_ON_ERROR));
        $downloader = $this->createMock(AttachmentDownloadService::class);
        $downloader->expects($this->never())->method('download');
        $input = new AuditDocumentInput(new AuditDocumentCache($redis), $downloader);

        // Act:
        $this->expectException(RuntimeException::class);

        // Assert:
        $this->expectExceptionMessage($message);
        $input->resolve(self::event());
    }

    public static function invalidDocuments(): iterable
    {
        yield 'missing MIME' => [['data' => self::document()['data']], 'sin MIME'];
        yield 'missing bytes' => [['mime' => 'application/pdf'], 'sin data'];
        yield 'wrong hash' => [['mime' => 'application/pdf', 'data' => base64_encode('OTHER')], 'document_hash no coincide'];
    }

    private static function document(): array
    {
        return ['mime' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 fixture'), 'duration_ms' => 7];
    }

    private static function event(): AuditEvent
    {
        return AuditEvent::fromArray([
            'event_id' => '10000000-0000-4000-8000-000000000001',
            'audit_id' => '20000000-0000-4000-8000-000000000001',
            'document_id' => 'fixture-document',
            'event_type' => AuditEvent::TYPE_DOCUMENT_DOWNLOADED,
            'timestamp' => '2026-10-08T11:00:00Z',
            'payload' => [
                'attachment_id' => 'att-fixture',
                'dis_det_nro' => 'FAC-FIXTURE',
                'blob_reference_key' => 'audit:blob:fixture',
                'document_hash' => hash('sha256', self::document()['data']),
            ],
        ]);
    }
}
