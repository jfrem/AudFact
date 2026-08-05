<?php

declare(strict_types=1);

namespace Tests\wrap\core\tools;

use App\wrap\core\tools\GetAttachments;
use PHPUnit\Framework\TestCase;

final class GetAttachmentsTest extends TestCase
{
    public function testRejectsMissingDisDetNroForList(): void
    {
        $result = (new GetAttachments())->execute(['nitSec' => '2426']);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido'], $result);
    }

    public function testRejectsInvoiceIdAliasForList(): void
    {
        $result = (new GetAttachments())->execute(['invoiceId' => 'T38250701547', 'nitSec' => '2426']);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido'], $result);
    }

    public function testRejectsMissingDisDetNroForDownload(): void
    {
        $result = (new GetAttachments())->execute(['attachmentId' => '1']);

        $this->assertSame(
            ['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido para descargar por attachmentId'],
            $result
        );
    }
}
