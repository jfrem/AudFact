<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\InvoicesController;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class InvoicesControllerTest extends TestCase
{
    private array $originalGet;
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        $this->originalServer = $_SERVER;
        $_GET = [];
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
    }

    public function testIndexPassesDateRangeToModel(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-30',
            'limit' => '900',
        ];

        $model = new InvoicesControllerFakeModel();
        $model->returnValue = [['FacSec' => '87172329', 'Dispensa' => 'X24250700021']];
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(200, $response->getCode());
        $this->assertSame([2426, '2025-07-01', '2025-07-30', 900], $model->lastCall);
        $this->assertTrue($response->getData()['success']);
        $this->assertSame($model->returnValue, $response->getData()['data']);
    }

    public function testIndexRejectsInvalidDateRange(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-30',
            'dateTo' => '2025-07-01',
            'limit' => '10',
        ];

        $controller = new TestableInvoicesController(new InvoicesControllerFakeModel());

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertSame('dateFrom no puede ser mayor que dateTo', $response->getData()['message']);
    }

    public function testSearchOmitsMissingDateToAsNull(): void
    {
        $model = new InvoicesControllerFakeModel();
        $controller = new TestableInvoicesController($model, [
            'facNitSec' => 2426,
            'dateFrom' => '2025-07-01',
            'limit' => 100,
        ]);

        $response = $this->captureHttpResponse(static fn() => $controller->search());

        $this->assertSame(200, $response->getCode());
        $this->assertSame([2426, '2025-07-01', null, 100], $model->lastCall);
        $this->assertTrue($response->getData()['success']);
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

final class TestableInvoicesController extends InvoicesController
{
    public function __construct(private readonly InvoicesControllerFakeModel $fakeModel, private array $body = [])
    {
        $this->model = $this->fakeModel;
    }

    protected function getBody(): array
    {
        return $this->body;
    }
}

final class InvoicesControllerFakeModel
{
    public array $returnValue = [];
    public array $lastCall = [];

    public function getInvoices(int $facNitSec, string $date, ?string $dateTo = null, int $limit = 100): array
    {
        $this->lastCall = [$facNitSec, $date, $dateTo, $limit];
        return $this->returnValue;
    }
}
