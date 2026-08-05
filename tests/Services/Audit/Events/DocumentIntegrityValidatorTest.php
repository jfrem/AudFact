<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentIntegrityValidator;
use PHPUnit\Framework\TestCase;

final class DocumentIntegrityValidatorTest extends TestCase
{
    public function testAcceptsPdfWhenMimeAndMagicBytesMatch(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/pdf',
            'data' => base64_encode("%PDF-1.4\n1 0 obj\n<</Type /Page>>\nendobj\n"),
            'duration_ms' => 10,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
        $this->assertSame('application/pdf', $result['detected_mime']);
        $this->assertGreaterThan(4, $result['size_bytes']);
    }

    public function testRejectsEmptyDocument(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/pdf',
            'data' => '',
            'duration_ms' => 10,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('EMPTY_DOCUMENT', $result['reason']);
        $this->assertSame(0, $result['size_bytes']);
    }

    public function testRejectsMimeMismatch(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/pdf',
            'data' => base64_encode("\x89PNG\r\n\x1A\npng-data"),
            'duration_ms' => 10,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('MIME_MISMATCH', $result['reason']);
        $this->assertSame('image/png', $result['detected_mime']);
    }

    public function testRejectsUnknownSignatureEvenWithSupportedDeclaredMime(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/pdf',
            'data' => base64_encode('plain text payload'),
            'duration_ms' => 10,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('UNKNOWN_FILE_SIGNATURE', $result['reason']);
    }

    public function testRejectsUnsupportedMime(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/zip',
            'data' => base64_encode("PK\x03\x04zip-data"),
            'duration_ms' => 10,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('UNSUPPORTED_MIME', $result['reason']);
    }

    public function testRejectsEncryptedPdf(): void
    {
        $result = DocumentIntegrityValidator::validate([
            'mime' => 'application/pdf',
            'data' => base64_encode("%PDF-1.4\n1 0 obj\n<< /Encrypt 2 0 R >>\nendobj\n"),
            'duration_ms' => 10,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('ENCRYPTED_DOCUMENT', $result['reason']);
        $this->assertSame('application/pdf', $result['detected_mime']);
    }
}
