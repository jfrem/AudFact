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

    public function testIndexReturnsPaginatedPayloadAndPassesPageToModel(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-30',
            'page' => '2',
            'pageSize' => '50',
        ];

        $model = new InvoicesControllerFakeModel();
        $model->total = 121;
        $model->returnValue = [['FacSec' => '87172329', 'Dispensa' => 'X24250700021']];
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(200, $response->getCode());
        $this->assertSame(
            ['facNitSec' => 2426, 'dateFrom' => '2025-07-01', 'dateTo' => '2025-07-30'],
            $model->lastCountFilters
        );
        $this->assertSame(
            [['facNitSec' => 2426, 'dateFrom' => '2025-07-01', 'dateTo' => '2025-07-30'], 2, 50],
            $model->lastSearchCall
        );
        $this->assertTrue($response->getData()['success']);
        $this->assertSame([
            'items' => $model->returnValue,
            'total' => 121,
            'page' => 2,
            'pageSize' => 50,
            'totalPages' => 3,
            'filters' => ['facNitSec' => 2426, 'dateFrom' => '2025-07-01', 'dateTo' => '2025-07-30'],
        ], $response->getData()['data']);
    }

    public function testIndexRejectsInvalidDateRange(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-30',
            'dateTo' => '2025-07-01',
            'pageSize' => '10',
        ];

        $model = new InvoicesControllerFakeModel();
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertSame('dateFrom no puede ser mayor que dateTo', $response->getData()['message']);
        $this->assertSame([], $model->lastCountFilters);
        $this->assertSame([], $model->lastSearchCall);
    }

    public function testIndexRejectsNonIntegerPageSizeBeforeCallingModel(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-30',
            'pageSize' => 'abc',
        ];

        $model = new InvoicesControllerFakeModel();
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertArrayHasKey('pageSize', $response->getData()['errors']);
        $this->assertSame([], $model->lastCountFilters);
        $this->assertSame([], $model->lastSearchCall);
    }

    public function testIndexRejectsNonIntegerFacNitSecBeforeCallingModel(): void
    {
        $_GET = [
            'facNitSec' => 'abc',
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-30',
            'pageSize' => '100',
        ];

        $model = new InvoicesControllerFakeModel();
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertArrayHasKey('facNitSec', $response->getData()['errors']);
        $this->assertSame([], $model->lastCountFilters);
        $this->assertSame([], $model->lastSearchCall);
    }

    public function testIndexRejectsInvalidDateBeforeCallingModel(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-02-30',
            'pageSize' => '100',
        ];

        $model = new InvoicesControllerFakeModel();
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertArrayHasKey('dateFrom', $response->getData()['errors']);
        $this->assertSame([], $model->lastCountFilters);
        $this->assertSame([], $model->lastSearchCall);
    }

    public function testSearchAutocompletesDateToWhenMissing(): void
    {
        $model = new InvoicesControllerFakeModel();
        $model->total = 1;
        $controller = new TestableInvoicesController($model, [
            'facNitSec' => 2426,
            'dateFrom' => '2025-07-01',
            'pageSize' => 20,
        ]);

        $response = $this->captureHttpResponse(static fn() => $controller->search());

        $this->assertSame(200, $response->getCode());
        $this->assertSame(
            [['facNitSec' => 2426, 'dateFrom' => '2025-07-01', 'dateTo' => '2025-07-01'], 1, 20],
            $model->lastSearchCall
        );
        $this->assertTrue($response->getData()['success']);
    }

    public function testIndexClampsOutOfRangePageToLastAvailablePage(): void
    {
        $_GET = [
            'facNitSec' => '2426',
            'dateFrom' => '2025-07-01',
            'page' => '99',
            'pageSize' => '20',
        ];

        $model = new InvoicesControllerFakeModel();
        $model->total = 45;
        $controller = new TestableInvoicesController($model);

        $response = $this->captureHttpResponse(static fn() => $controller->index());

        $this->assertSame(200, $response->getCode());
        $this->assertSame(
            [['facNitSec' => 2426, 'dateFrom' => '2025-07-01', 'dateTo' => '2025-07-01'], 3, 20],
            $model->lastSearchCall
        );
        $this->assertSame(3, $response->getData()['data']['page']);
        $this->assertSame(3, $response->getData()['data']['totalPages']);
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
    public int $total = 0;
    public array $lastCountFilters = [];
    public array $lastSearchCall = [];

    /**
     * @param array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     */
    public function countInvoices(array $filters): int
    {
        $this->lastCountFilters = $filters;
        return $this->total;
    }

    /**
     * @param array{facNitSec:int,dateFrom:string,dateTo:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function searchInvoices(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $this->lastSearchCall = [$filters, $page, $pageSize];
        return $this->returnValue;
    }
}
