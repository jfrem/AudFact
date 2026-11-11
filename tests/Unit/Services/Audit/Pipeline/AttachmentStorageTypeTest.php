<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AttachmentStorageType;
use PHPUnit\Framework\TestCase;

final class AttachmentStorageTypeTest extends TestCase
{
    public function testFromTransportNormalizesValidValues(): void
    {
        self::assertSame(AttachmentStorageType::Url, AttachmentStorageType::fromTransport('URL'));
        self::assertSame(AttachmentStorageType::Url, AttachmentStorageType::fromTransport('  url  '));
        self::assertSame(AttachmentStorageType::Url, AttachmentStorageType::fromTransport('Url'));

        self::assertSame(AttachmentStorageType::Blob, AttachmentStorageType::fromTransport('BLOB'));
        self::assertSame(AttachmentStorageType::Blob, AttachmentStorageType::fromTransport('  blob  '));
        self::assertSame(AttachmentStorageType::Blob, AttachmentStorageType::fromTransport('Blob'));
    }

    public function testFromTransportReturnsNullForUnknownOrMissingContent(): void
    {
        self::assertNull(AttachmentStorageType::fromTransport('SIN_DOCUMENTOS'));
        self::assertNull(AttachmentStorageType::fromTransport(''));
        self::assertNull(AttachmentStorageType::fromTransport('   '));
        self::assertNull(AttachmentStorageType::fromTransport(null));
        self::assertNull(AttachmentStorageType::fromTransport('DESCONOCIDO'));
        self::assertNull(AttachmentStorageType::fromTransport(123));
    }
}

