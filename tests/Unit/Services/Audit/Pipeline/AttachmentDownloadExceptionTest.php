<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AttachmentDownloadException;
use PHPUnit\Framework\TestCase;

final class AttachmentDownloadExceptionTest extends TestCase
{
    public function testRetryableReturnsTrueForTransientNetworkAndTransferFailures(): void
    {
        $networkError = new AttachmentDownloadException(
            AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED,
            'cURL timeout'
        );
        $this->assertTrue($networkError->retryable());

        $incompleteError = new AttachmentDownloadException(
            AttachmentDownloadException::INCOMPLETE_TRANSFER,
            'Incomplete stream'
        );
        $this->assertTrue($incompleteError->retryable());
    }

    public function testRetryableReturnsFalseForPermanentDataFailures(): void
    {
        $notFound = new AttachmentDownloadException(
            AttachmentDownloadException::SOURCE_NOT_FOUND,
            'Document missing'
        );
        $this->assertFalse($notFound->retryable());

        $empty = new AttachmentDownloadException(
            AttachmentDownloadException::SOURCE_EMPTY,
            'Empty storage'
        );
        $this->assertFalse($empty->retryable());
    }
}

