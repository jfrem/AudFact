<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditController;
use App\Models\AuditStatusModel;
use App\Models\InvoicesModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditControllerTest extends TestCase
{
    public function testSingleReturns202WithAuditIdAndPublishesAuditCreated(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $store = $this->newStoreStub(initAuditReturns: true);

        $controller = new TestableAuditController(
            body: ['DisDetNro' => 'T38250701547'],
            stateStore: $store,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(202, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertTrue(AuditEvent::isUuidV4($data['audit_id']));
        $this->assertSame('pending', $data['status']);
        $this->assertSame('T38250701547', $data['dis_det_nro']);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publisher->published[0]->eventType);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['dis_det_nro']);
    }

    public function testSingleReturns422WhenDisDetNroMissing(): void
    {
        $controller = new TestableAuditController(body: []);

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(422, $response->getCode());
    }

    public function testSingleReturns503WhenPublisherFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublish: true);
        $store = $this->newStoreStub(initAuditReturns: true);

        $controller = new TestableAuditController(
            body: ['DisDetNro' => 'T38250701547'],
            stateStore: $store,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(503, $response->getCode());
        $this->assertCount(1, $store->deletedAuditIds);
    }

    public function testAsyncReturns409WhenBatchSlotAlreadyClaimed(): void
    {
        $jobStore = $this->newJobStoreStub(claimReturns: false);

        $controller = new TestableAuditController(
            body: [
                'facNitSec' => 2426,
                'date' => '2025-07-29',
                'dateTo' => '2025-07-29',
                'limit' => 10,
            ],
            jobStore: $jobStore,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(409, $response->getCode());
    }

    public function testAsyncReturns422WhenDateToBeforeDate(): void
    {
        $controller = new TestableAuditController(body: [
            'facNitSec' => 2426,
            'date' => '2025-07-30',
            'dateTo' => '2025-07-01',
            'limit' => 10,
        ]);

        $response = self::captureResponse(static fn() => $controller->async());
        $this->assertSame(422, $response->getCode());
    }

    public function testAsyncPublishesBatchCreatedAndAuditCreatedPerInvoice(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $store = $this->newStoreStub(initAuditReturns: true);
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: true);
        $invoices = new StubInvoicesModel([
            ['NitSec' => '2426', 'FacSec' => '87723098', 'Dispensa' => 'T38250701547'],
            ['NitSec' => '2426', 'FacSec' => '87723099', 'Dispensa' => 'T38250701548'],
        ]);

        $controller = new TestableAuditController(
            body: [
                'facNitSec' => 2426,
                'date' => '2025-07-29',
                'dateTo' => '2025-07-29',
                'limit' => 10,
            ],
            invoicesModel: $invoices,
            stateStore: $store,
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertTrue(AuditEvent::isUuidV4($data['job_id']));
        $this->assertSame(2, $data['total']);

        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publisher->published[1]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publisher->published[2]->eventType);
        $this->assertSame('T38250701547', $publisher->published[1]->payload['dis_det_nro']);
        $this->assertSame('87723099', $publisher->published[2]->payload['fac_sec']);
    }

    public function testAsyncSkipsInvoicesMissingFacSecOrDispensa(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $store = $this->newStoreStub(initAuditReturns: true);
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: true);
        $invoices = new StubInvoicesModel([
            ['FacSec' => '87723098', 'Dispensa' => ''],
            ['FacSec' => '', 'Dispensa' => 'T2'],
            ['FacSec' => '87723099', 'Dispensa' => 'T3'],
        ]);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            invoicesModel: $invoices,
            stateStore: $store,
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $this->assertSame(1, $response->getData()['data']['total']);
        $auditEvents = array_filter(
            $publisher->published,
            static fn($e) => $e->eventType === AuditEvent::TYPE_AUDIT_CREATED
        );
        $this->assertCount(1, $auditEvents);
    }

    public function testAsyncReturns503AndReleasesBatchSlotWhenInitJobFails(): void
    {
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: false);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: new InMemoryAuditEventPublisher(),
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertSame([[2426, '2025-07-29', '2025-07-29']], $jobStore->releasedBatchSlots);
        $this->assertSame([], $jobStore->deletedJobIds);
    }

    public function testAsyncReturns503AndCleansJobWhenBatchPublishFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublishAt: 1);
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: true);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertCount(1, $jobStore->deletedJobIds);
        $this->assertSame([[2426, '2025-07-29', '2025-07-29']], $jobStore->releasedBatchSlots);
    }

    public function testAsyncReturns503AndCleansPartialAuditsWhenAuditPublishFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublishAt: 3);
        $store = $this->newStoreStub(initAuditReturns: true);
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: true);
        $invoices = new StubInvoicesModel([
            ['NitSec' => '2426', 'FacSec' => '87723098', 'Dispensa' => 'T38250701547'],
            ['NitSec' => '2426', 'FacSec' => '87723099', 'Dispensa' => 'T38250701548'],
        ]);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            invoicesModel: $invoices,
            stateStore: $store,
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertCount(2, $store->deletedAuditIds);
        $this->assertCount(1, $jobStore->deletedJobIds);
        $this->assertSame([[2426, '2025-07-29', '2025-07-29']], $jobStore->releasedBatchSlots);
    }

    public function testAsyncReturnsCompletedWhenBatchIsEmpty(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $store = $this->newStoreStub(initAuditReturns: true);
        $jobStore = $this->newJobStoreStub(claimReturns: true, initJobReturns: true);
        $invoices = new StubInvoicesModel([]);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            invoicesModel: $invoices,
            stateStore: $store,
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $this->assertSame('completed', $response->getData()['data']['status']);
        $this->assertSame([
            'status' => 'completed',
            'total' => 0,
        ], $jobStore->patchedJobs[0]['patch'] ?? null);
    }

    public function testJobStatusReturns422WhenJobIdInvalid(): void
    {
        $controller = new TestableAuditController();
        $response = self::captureResponse(static fn() => $controller->jobStatus('not-a-uuid'));
        $this->assertSame(422, $response->getCode());
    }

    public function testJobStatusReturns404WhenJobMissing(): void
    {
        $jobStore = $this->newJobStoreStub(getJobReturns: null);

        $controller = new TestableAuditController(jobStore: $jobStore);

        $response = self::captureResponse(static fn() => $controller->jobStatus(AuditEvent::uuidV4()));
        $this->assertSame(404, $response->getCode());
    }

    public function testJobStatusReturns200WithFormattedState(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $jobStore = $this->newJobStoreStub(getJobReturns: [
            'job_id'     => $jobId,
            'status'     => 'processing',
            'total'      => 5,
            'done'       => 2,
            'failed'     => 1,
            'created_at' => '2026-04-23T10:00:00Z',
            'updated_at' => '2026-04-23T10:05:00Z',
            'audits'     => [
                $auditId => ['dis_det_nro' => 'T38250701547', 'status' => 'completed'],
            ],
        ]);

        $controller = new TestableAuditController(jobStore: $jobStore);

        $response = self::captureResponse(static fn() => $controller->jobStatus($jobId));

        $this->assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertSame($jobId, $data['job_id']);
        $this->assertSame(5, $data['total']);
        $this->assertSame(2, $data['done']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(2, $data['pending']);
        $this->assertCount(1, $data['audits']);
        $this->assertSame($auditId, $data['audits'][0]['audit_id']);
    }

    public function testJobStatusReturns503WhenStoreFails(): void
    {
        $jobStore = $this->newJobStoreStub(getJobThrows: true);

        $controller = new TestableAuditController(jobStore: $jobStore);

        $response = self::captureResponse(static fn() => $controller->jobStatus(AuditEvent::uuidV4()));
        $this->assertSame(503, $response->getCode());
    }

    public function testResultDetailReturnsPersistedAuditDetail(): void
    {
        $controller = new TestableAuditController(
            auditStatusModel: new StubAuditStatusModel([
                'FacSec' => '87723098',
                'FacNro' => 'T38250701547',
                'findings' => [],
                'fieldDecisions' => [],
                'documentDecisions' => [],
                'timings' => null,
            ])
        );

        $response = self::captureResponse(static fn() => $controller->resultDetail('87723098'));

        $this->assertSame(200, $response->getCode());
        $this->assertSame('87723098', $response->getData()['data']['FacSec']);
    }

    public function testResultDetailReturns404WhenAuditIsMissing(): void
    {
        $controller = new TestableAuditController(
            auditStatusModel: new StubAuditStatusModel(null)
        );

        $response = self::captureResponse(static fn() => $controller->resultDetail('87723098'));

        $this->assertSame(404, $response->getCode());
    }

    // ── Helpers ────────────────────────────────────────────────

    private function newStoreStub(
        bool $initAuditReturns = true,
    ): StubAuditStateStore {
        return new StubAuditStateStore($initAuditReturns);
    }

    private function newJobStoreStub(
        bool $claimReturns = true,
        bool $initJobReturns = true,
        mixed $getJobReturns = null,
        bool $getJobThrows = false,
    ): StubBatchJobStore {
        return new StubBatchJobStore(
            $claimReturns,
            $initJobReturns,
            $getJobReturns,
            $getJobThrows,
        );
    }

    private static function captureResponse(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $e) {
            return $e;
        }

        self::fail('Se esperaba HttpResponseException');
    }
}

final class TestableAuditController extends AuditController
{
    public function __construct(
        private array $body = [],
        private ?InvoicesModel $invoicesModel = null,
        private ?AuditStateStore $stateStore = null,
        private ?BatchJobStore $jobStore = null,
        private ?AuditEventPublisher $publisher = null,
        private ?AuditStatusModel $auditStatusModel = null,
    ) {
    }

    protected function getBody(): array
    {
        return $this->body;
    }

    protected function getInvoicesModel(): InvoicesModel
    {
        return $this->invoicesModel ?? new StubInvoicesModel([]);
    }

    protected function buildStateStore(): AuditStateStore
    {
        return $this->stateStore ?? new StubAuditStateStore();
    }

    protected function buildBatchJobStore(): BatchJobStore
    {
        return $this->jobStore ?? new StubBatchJobStore();
    }

    protected function buildEventPublisher(): AuditEventPublisher
    {
        return $this->publisher ?? new InMemoryAuditEventPublisher();
    }

    protected function buildAuditStatusModel(): AuditStatusModel
    {
        return $this->auditStatusModel ?? new StubAuditStatusModel(null);
    }
}

final class StubInvoicesModel extends InvoicesModel
{
    /** @param array<int,array<string,mixed>> $invoices */
    public function __construct(private array $invoices = [])
    {
    }

    public function getInvoices(int $facNitSec, string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->invoices;
    }
}

final class StubAuditStateStore extends AuditStateStore
{
    /** @var array<int,string> */
    public array $deletedAuditIds = [];

    public function __construct(
        private bool $initAuditReturns = true,
    ) {
    }

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $facSec = null
    ): bool {
        return $this->initAuditReturns;
    }

    public function deleteAudit(string $auditId): bool
    {
        $this->deletedAuditIds[] = $auditId;
        return true;
    }
}

final class StubAuditStatusModel extends AuditStatusModel
{
    public function __construct(private ?array $detail)
    {
    }

    public function getAuditDetailByFacSec(string $facSec): ?array
    {
        return $this->detail;
    }
}

final class StubBatchJobStore extends BatchJobStore
{
    /** @var array<int,string> */
    public array $deletedJobIds = [];
    /** @var array<int,array{0:int,1:string,2:?string}> */
    public array $releasedBatchSlots = [];
    /** @var array<int,array{jobId:string,patch:array<string,mixed>}> */
    public array $patchedJobs = [];

    public function __construct(
        private bool $claimReturns = true,
        private bool $initJobReturns = true,
        private mixed $getJobReturns = null,
        private bool $getJobThrows = false,
    ) {
    }

    public function claimBatchSlot(int $facNitSec, string $dateFrom, string $dateTo, string $jobId): bool
    {
        return $this->claimReturns;
    }

    public function initJob(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): bool {
        return $this->initJobReturns;
    }

    public function registerAuditInJob(string $jobId, string $auditId, string $disDetNro): bool
    {
        return true;
    }

    public function patchJob(string $jobId, array $patch): bool
    {
        $this->patchedJobs[] = [
            'jobId' => $jobId,
            'patch' => $patch,
        ];
        return true;
    }

    public function deleteJob(string $jobId): bool
    {
        $this->deletedJobIds[] = $jobId;
        return true;
    }

    public function releaseBatchSlot(int $facNitSec, string $dateFrom, string $dateTo): bool
    {
        $this->releasedBatchSlots[] = [$facNitSec, $dateFrom, $dateTo];
        return true;
    }

    public function getJob(string $jobId): ?array
    {
        if ($this->getJobThrows) {
            throw new RuntimeException('Redis no disponible', 503);
        }
        return $this->getJobReturns;
    }
}

final class InMemoryAuditEventPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct(
        private bool $throwOnPublish = false,
        private ?int $throwOnPublishAt = null,
    )
    {
    }

    public function publish(AuditEvent $event): string
    {
        $nextIndex = count($this->published) + 1;
        if ($this->throwOnPublish || ($this->throwOnPublishAt !== null && $nextIndex === $this->throwOnPublishAt)) {
            throw new RuntimeException('Redis no disponible', 503);
        }

        $this->published[] = $event;
        return 'stub-id-' . $nextIndex;
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stub-dlq-' . count($this->published);
    }
}
