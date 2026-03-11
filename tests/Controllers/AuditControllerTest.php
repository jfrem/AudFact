<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditController;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class AuditControllerTest extends TestCase
{
    public function testRunRejectsInvalidDateRangeBeforeInvokingPipeline(): void
    {
        $controller = new TestableAuditController([
            'facNitSec' => 2426,
            'date' => '2025-07-30',
            'dateTo' => '2025-07-01',
            'limit' => 10,
        ]);

        $response = $this->captureHttpResponse(static fn() => $controller->run());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertSame('date no puede ser mayor que dateTo', $response->getData()['message']);
    }

    private function captureHttpResponse(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $exception) {
            return $exception;
        }

        $this->fail('Se esperaba HttpResponseException');
    }
}

final class TestableAuditController extends AuditController
{
    public function __construct(private array $body = [])
    {
    }

    protected function getBody(): array
    {
        return $this->body;
    }
}
