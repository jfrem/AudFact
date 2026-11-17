<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentRejectionReason;
use PHPUnit\Framework\TestCase;

final class DocumentRejectionReasonTest extends TestCase
{
    public function testDocumentExceedsPageLimitIsAllowedReason(): void
    {
        $this->assertTrue(DocumentRejectionReason::isAllowed(DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT));
        $this->assertSame('DOCUMENT_EXCEEDS_PAGE_LIMIT', DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT);
    }
}
