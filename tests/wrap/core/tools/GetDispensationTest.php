<?php

declare(strict_types=1);

namespace Tests\wrap\core\tools;

use App\wrap\core\tools\GetDispensation;
use PHPUnit\Framework\TestCase;

final class GetDispensationTest extends TestCase
{
    public function testRejectsMissingDisDetNro(): void
    {
        $result = (new GetDispensation())->execute([]);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido'], $result);
    }

    public function testRejectsInvoiceIdAliasWithoutCallingApi(): void
    {
        $result = (new GetDispensation())->execute(['invoiceId' => 'T38250701547']);

        $this->assertSame(['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido'], $result);
    }
}
