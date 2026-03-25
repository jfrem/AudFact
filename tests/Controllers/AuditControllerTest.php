<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditController;
use App\Models\InvoicesModel;
use App\Services\Audit\AuditQueueService;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class AuditControllerTest extends TestCase
{
    public function testRunRejectsInvalidDateRangeBeforeInvokingPipeline(): void
    {
        $controller = new TestableAuditController([
            'facNitSec' => '2426',
            'date' => '2025-07-30',
            'dateTo' => '2025-07-01',
            'limit' => 10,
        ]);

        $response = $this->captureHttpResponse(static fn() => $controller->run());

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertSame('date no puede ser mayor que dateTo', $response->getData()['message']);
    }

    public function testRunAcceptsFacNitSecAsStringWithoutFailingInLogging(): void
    {
        $controller = new TestableAuditController(
            [
                'facNitSec' => '2426',
                'date' => '2025-07-01',
                'dateTo' => '2025-07-30',
                'limit' => 10,
            ],
            new FakeInvoicesModel()
        );

        $response = $this->captureHttpResponse(static fn() => $controller->run());

        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('No se encontraron facturas para los parámetros indicados.', $response->getData()['message']);
    }

    public function testAsyncAcceptsFacNitSecAsStringWithoutFailingInLogging(): void
    {
        $queueService = new FakeAuditQueueService('job-123');
        $controller = new TestableAuditController(
            [
                'facNitSec' => '2426',
                'date' => '2025-07-01',
                'dateTo' => '2025-07-30',
                'limit' => 10,
            ],
            new FakeInvoicesModel(),
            $queueService
        );

        $response = $this->captureHttpResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('Auditoría encolada para procesamiento asíncrono', $response->getData()['message']);
        $this->assertSame('2426', $queueService->lastPayload['facNitSec']);
    }

    public function testRunAcceptsFacNitSecAsInteger(): void
    {
        $controller = new TestableAuditController(
            [
                'facNitSec' => 2426,
                'date' => '2025-07-01',
                'dateTo' => '2025-07-30',
                'limit' => 10,
            ],
            new FakeInvoicesModel()
        );

        $response = $this->captureHttpResponse(static fn() => $controller->run());

        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('No se encontraron facturas para los parámetros indicados.', $response->getData()['message']);
    }

    public function testAsyncAcceptsFacNitSecAsInteger(): void
    {
        $queueService = new FakeAuditQueueService('job-456');
        $controller = new TestableAuditController(
            [
                'facNitSec' => 2426,
                'date' => '2025-07-01',
                'dateTo' => '2025-07-30',
                'limit' => 10,
            ],
            new FakeInvoicesModel(),
            $queueService
        );

        $response = $this->captureHttpResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('Auditoría encolada para procesamiento asíncrono', $response->getData()['message']);
        $this->assertSame(2426, $queueService->lastPayload['facNitSec']);
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
    public function __construct(
        private array $body = [],
        private ?InvoicesModel $invoicesModel = null,
        private ?AuditQueueService $queueService = null
    ) {
    }

    protected function getInvoicesModel(): InvoicesModel
    {
        return $this->invoicesModel ?? new FakeInvoicesModel();
    }

    protected function getBody(): array
    {
        return $this->body;
    }

    protected function buildQueueService(): AuditQueueService
    {
        return $this->queueService ?? new FakeAuditQueueService('job-default');
    }
}

final class FakeInvoicesModel extends InvoicesModel
{
    public function __construct()
    {
    }

    public function getInvoices(int $facNitSec, string $dateFrom, ?string $dateTo = null, int $limit = 100): array
    {
        return [];
    }
}

final class FakeAuditQueueService extends AuditQueueService
{
    public array $lastPayload = [];

    public function __construct(private readonly ?string $jobId)
    {
    }

    public function enqueue(array $params): ?string
    {
        $this->lastPayload = $params;

        return $this->jobId;
    }

    public function queueDepth(): int
    {
        return 0;
    }
}
