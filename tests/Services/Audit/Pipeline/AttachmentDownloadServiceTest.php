<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Services\Audit\Pipeline\AttachmentDownloadException;
use App\Services\Audit\Pipeline\AttachmentDownloadService;
use PHPUnit\Framework\TestCase;

final class AttachmentDownloadServiceTest extends TestCase
{
    public function testRejectsPartialBlobAsTechnicalTransfer(): void
    {
        $service = new AttachmentDownloadService(new DownloadAttachmentsModel(
            metadata: self::blobMetadata(),
            blob: ['bytes' => 'abc', 'expected_size' => 4]
        ));

        try {
            $service->download('41', 'T38250701547');
            self::fail('Se esperaba transferencia incompleta.');
        } catch (AttachmentDownloadException $error) {
            $this->assertSame(
                AttachmentDownloadException::INCOMPLETE_TRANSFER,
                $error->reasonCode()
            );
        }
    }

    public function testClassifiesMissingSource(): void
    {
        $service = new AttachmentDownloadService(new DownloadAttachmentsModel(
            metadata: false,
            blob: null
        ));

        try {
            $service->download('41', 'T38250701547');
            self::fail('Se esperaba fuente inexistente.');
        } catch (AttachmentDownloadException $error) {
            $this->assertSame(
                AttachmentDownloadException::SOURCE_NOT_FOUND,
                $error->reasonCode()
            );
        }
    }

    public function testClassifiesEmptyBlob(): void
    {
        $service = new AttachmentDownloadService(new DownloadAttachmentsModel(
            metadata: self::blobMetadata(),
            blob: ['bytes' => '', 'expected_size' => 0]
        ));

        try {
            $service->download('41', 'T38250701547');
            self::fail('Se esperaba fuente vacía.');
        } catch (AttachmentDownloadException $error) {
            $this->assertSame(
                AttachmentDownloadException::SOURCE_EMPTY,
                $error->reasonCode()
            );
        }
    }

    public function testReturnsOnlyCompleteBlob(): void
    {
        $bytes = "%PDF-1.4\n";
        $service = new AttachmentDownloadService(new DownloadAttachmentsModel(
            metadata: self::blobMetadata(),
            blob: ['bytes' => $bytes, 'expected_size' => strlen($bytes)]
        ));

        $document = $service->download('41', 'T38250701547');

        $this->assertSame('application/pdf', $document['mime']);
        $this->assertSame($bytes, base64_decode($document['data'], true));
    }

    /**
     * @return array<string,mixed>
     */
    private static function blobMetadata(): array
    {
        return [
            'AdjDisId' => 41,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => null,
            'TipoAlmacenamiento' => 'BLOB',
            'BlobSize' => 10,
        ];
    }
}

final class DownloadAttachmentsModel extends AttachmentsModel
{
    /**
     * @param array<string,mixed>|false $metadata
     * @param array{bytes:string,expected_size:int}|null $blob
     */
    public function __construct(
        private array|false $metadata,
        private ?array $blob
    ) {
    }

    public function getAttachmentByIdForDisDetNro(
        string $attachmentId,
        string $disDetNro
    ): array|false {
        return $this->metadata;
    }

    public function getAttachmentBlobBytesByIdForDisDetNro(
        string $attachmentId,
        string $disDetNro
    ): ?array {
        return $this->blob;
    }
}
