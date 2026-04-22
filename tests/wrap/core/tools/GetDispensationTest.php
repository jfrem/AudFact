<?php

declare(strict_types=1);

namespace Tests\wrap\core\tools;

use App\wrap\core\tools\GetDispensation;
use PHPUnit\Framework\TestCase;

final class GetDispensationTest extends TestCase
{
    public function testRejectsMissingInvoiceId(): void
    {
        $result = (new GetDispensation())->execute([]);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido'], $result);
    }

    public function testRejectsLegacyAliasWithoutCallingApi(): void
    {
        $result = (new GetDispensation())->execute(['DisDetNro' => 'T38250701547']);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido'], $result);
    }
}
