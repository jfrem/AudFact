<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Services\Audit\Pipeline\AttachmentDownloadException;
use App\Services\Audit\Pipeline\AttachmentDownloadService;
use App\Services\GoogleDriveAuthService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AttachmentDownloadServiceTest extends TestCase
{
    public function testClassifiesGoogleDrive404AsSourceNotFound(): void
    {
        $driveAuth = $this->createMock(GoogleDriveAuthService::class);
        $driveAuth->expects(self::once())
            ->method('downloadFileToTemp')
            ->willThrowException(new ClientException(
                'File not found',
                new Request('GET', 'files/101'),
                new Response(404)
            ));

        $model = $this->createMock(AttachmentsModel::class);

        $service = new AttachmentDownloadService(
            model: $model,
            driveAuth: $driveAuth
        );

        $metadata = [
            'AdjDisId' => 101,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => 'drive-id-101',
            'TipoAlmacenamiento' => 'URL',
        ];

        try {
            $service->download('101', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            self::assertSame(AttachmentDownloadException::SOURCE_NOT_FOUND, $e->reasonCode());
            self::assertFalse($e->retryable(), 'El error 404 no debe ser reintentable');
        }
    }

    public function testClassifiesGoogleDrive403AsRetryableExternalTransfer(): void
    {
        $driveAuth = $this->createMock(GoogleDriveAuthService::class);
        $driveAuth->expects(self::once())
            ->method('downloadFileToTemp')
            ->willThrowException(new ClientException(
                'User Rate Limit Exceeded',
                new Request('GET', 'files/102'),
                new Response(403)
            ));

        $model = $this->createMock(AttachmentsModel::class);

        $service = new AttachmentDownloadService(
            model: $model,
            driveAuth: $driveAuth
        );

        $metadata = [
            'AdjDisId' => 102,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => 'drive-id-102',
            'TipoAlmacenamiento' => 'URL',
        ];

        try {
            $service->download('102', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            self::assertSame(AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED, $e->reasonCode());
            self::assertTrue($e->retryable(), 'El error 403 por cuota debe ser reintentable con backoff');
        }
    }

    public function testPurgesTokenOnGoogleDrive401AndClassifiesRetryable(): void
    {
        $driveAuth = $this->createMock(GoogleDriveAuthService::class);
        $driveAuth->expects(self::once())
            ->method('clearCachedToken');
        $driveAuth->expects(self::once())
            ->method('downloadFileToTemp')
            ->willThrowException(new ClientException(
                'Unauthorized token',
                new Request('GET', 'files/103'),
                new Response(401)
            ));

        $model = $this->createMock(AttachmentsModel::class);

        $service = new AttachmentDownloadService(
            model: $model,
            driveAuth: $driveAuth
        );

        $metadata = [
            'AdjDisId' => 103,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => 'drive-id-103',
            'TipoAlmacenamiento' => 'URL',
        ];

        try {
            $service->download('103', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            self::assertSame(AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED, $e->reasonCode());
            self::assertTrue($e->retryable(), 'El error 401 debe invalidar token y reintentar');
        }
    }

    public function testNonHttpExceptionWithCode404ClassifiedAsExternalTransferFailed(): void
    {
        $driveAuth = $this->createMock(GoogleDriveAuthService::class);
        $driveAuth->expects(self::once())
            ->method('downloadFileToTemp')
            ->willThrowException(new \RuntimeException('Non-HTTP exception with 404 code', 404));

        $model = $this->createMock(AttachmentsModel::class);

        $service = new AttachmentDownloadService(
            model: $model,
            driveAuth: $driveAuth
        );

        $metadata = [
            'AdjDisId' => 105,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => 'drive-id-105',
            'TipoAlmacenamiento' => 'URL',
        ];

        try {
            $service->download('105', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            // Demuestra que el fallback a getCode() fue retirado y no se clasifica erróneamente como SOURCE_NOT_FOUND
            self::assertSame(AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED, $e->reasonCode());
            self::assertTrue($e->retryable());
        }
    }

    public function testNonHttpExceptionWithCode401DoesNotPurgeToken(): void
    {
        $driveAuth = $this->createMock(GoogleDriveAuthService::class);
        $driveAuth->expects(self::never())
            ->method('clearCachedToken');
        $driveAuth->expects(self::once())
            ->method('downloadFileToTemp')
            ->willThrowException(new \RuntimeException('Non-HTTP exception with 401 code', 401));

        $model = $this->createMock(AttachmentsModel::class);

        $service = new AttachmentDownloadService(
            model: $model,
            driveAuth: $driveAuth
        );

        $metadata = [
            'AdjDisId' => 106,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => 'drive-id-106',
            'TipoAlmacenamiento' => 'URL',
        ];

        try {
            $service->download('106', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            self::assertSame(AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED, $e->reasonCode());
        }
    }

    public function testThrowsSourceEmptyWhenStorageTypeIsSinDocumentos(): void
    {
        $model = $this->createMock(AttachmentsModel::class);
        $service = new AttachmentDownloadService(model: $model);

        $metadata = [
            'AdjDisId' => 107,
            'AdjDisNom' => 'sin_doc.pdf',
            'AdjDisDocUrl' => null,
            'TipoAlmacenamiento' => 'SIN_DOCUMENTOS',
        ];

        try {
            $service->download('107', 'T38250701547', $metadata);
            self::fail('Se esperaba AttachmentDownloadException');
        } catch (AttachmentDownloadException $e) {
            self::assertSame(AttachmentDownloadException::SOURCE_EMPTY, $e->reasonCode());
            self::assertStringContainsString("TipoAlmacenamiento='SIN_DOCUMENTOS'", $e->getMessage());
        }
    }

    public function testShortCircuitsModelQueryWhenPrecalculatedMetadataProvided(): void
    {
        $model = $this->createMock(AttachmentsModel::class);
        $model->expects(self::never())
            ->method('getPhysicalAttachmentsByDisDetNro');
        $model->expects(self::once())
            ->method('getAttachmentBlobBytesByIdForDisDetNro')
            ->with('104', 'T38250701547')
            ->willReturn(['bytes' => '%PDF-sample', 'expected_size' => 11]);

        $service = new AttachmentDownloadService(model: $model);

        $metadata = [
            'AdjDisId' => 104,
            'AdjDisNom' => 'formula.pdf',
            'AdjDisDocUrl' => null,
            'TipoAlmacenamiento' => 'BLOB',
            'BlobSize' => 11,
        ];

        $doc = $service->download('104', 'T38250701547', $metadata);
        self::assertSame('application/pdf', $doc['mime']);
        self::assertSame('%PDF-sample', base64_decode($doc['data'], true));
    }
}
