<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditController;
use App\Models\AuditStatusModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditDataService;
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
            body: ['disId' => '87723098', 'disDetNro' => 'T38250701547'],
            stateStore: $store,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(202, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertTrue(AuditEvent::isUuidV4($data['audit_id']));
        $this->assertSame('pending', $data['status']);
        $this->assertSame('T38250701547', $data['dis_det_nro']);
        $this->assertSame('87723098', $data['dis_id']);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publisher->published[0]->eventType);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['dis_det_nro']);
        $this->assertSame('87723098', $publisher->published[0]->payload['dis_id']);
        $this->assertSame('2426', $publisher->published[0]->payload['fac_nit_sec']);
    }

    /**
     * @dataProvider missingRequiredParametersProvider
     */
    public function testSingleReturns422WhenRequiredParametersMissing(array $body): void
    {
        $controller = new TestableAuditController(body: $body);

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(422, $response->getCode());
    }

    public static function missingRequiredParametersProvider(): array
    {
        return [
            'Missing disDetNro' => [['disId' => '87723098']],
        ];
    }

    public function testSingleReturns503WhenPublisherFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublish: true);
        $store = $this->newStoreStub(initAuditReturns: true);

        $controller = new TestableAuditController(
            body: ['disId' => '87723098', 'disDetNro' => 'T38250701547'],
            stateStore: $store,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(503, $response->getCode());
        $this->assertCount(1, $store->deletedAuditIds);
    }

    public function testSingleReturns404WhenDispensationNotFound(): void
    {
        $controller = new TestableAuditController(
            body: ['disId' => '99999999', 'disDetNro' => 'T38250701547'],
            auditDataService: new NotFoundAuditDataService(),
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(404, $response->getCode());
    }

    public function testAsyncReturns202AndPublishesBatchRequested(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $jobStore = $this->newJobStoreStub(initJobReturns: true);

        $controller = new TestableAuditController(
            body: [
                'facNitSec' => 2426,
                'date' => '2025-07-29',
                'dateTo' => '2025-07-29',
                'limit' => 10,
            ],
            jobStore: $jobStore,
            publisher: $publisher,
        );

        // Inject fake header for idempotency
        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'fake-key-123';

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(202, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertTrue(AuditEvent::isUuidV4($data['job_id']));
        $this->assertSame('pending', $data['status']);

        // Verifica que se haya publicado 1 solo evento: BATCH_REQUESTED
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_REQUESTED, $publisher->published[0]->eventType);
        $this->assertSame('2426', $publisher->published[0]->payload['fac_nit_sec']);
        
        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
    }

    public function testAsyncReturns409WhenIdempotencyKeyAlreadyExists(): void
    {
        $jobStore = $this->newJobStoreStub();
        $jobStore->claimIdempotencyKeyReturns = 'existing-job-id-456';
        
        $controller = new TestableAuditController(
            body: [
                'facNitSec' => 2426,
                'date' => '2025-07-29',
            ],
            jobStore: $jobStore,
        );

        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'fake-key-123';

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(409, $response->getCode());
        $this->assertSame('existing-job-id-456', $response->getData()['data']['job_id']);
        
        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
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

    public function testAsyncReturns503WhenInitJobFails(): void
    {
        $jobStore = $this->newJobStoreStub(initJobReturns: false);
        // Hacemos que initJob lance excepcion como espera el controlador
        $jobStore = new class extends StubBatchJobStore {
            public function initJob(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): bool {
                throw new RuntimeException('Falla initJob');
            }
        };

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: new InMemoryAuditEventPublisher(),
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
    }

    public function testAsyncReturns503WhenBatchPublishFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublish: true);
        $jobStore = $this->newJobStoreStub(initJobReturns: true);

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
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
            'accumulated_duration_ms' => 60000,
            'avg_duration_ms' => 20000,
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
        $this->assertSame(60000, $data['accumulated_duration_ms']);
        $this->assertSame(20000, $data['avg_duration_ms']);
        $this->assertSame(0.05, $data['throughput_per_sec']);
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
                'DisId' => '87723098',
                'FacNro' => 'T38250701547',
                'findings' => [],
                'fieldDecisions' => [],
                'documentDecisions' => [],
                'timings' => null,
            ])
        );

        $response = self::captureResponse(static fn() => $controller->resultDetail('T38250701547'));

        $this->assertSame(200, $response->getCode());
        $this->assertSame('87723098', $response->getData()['data']['DisId']);
        $this->assertSame('T38250701547', $response->getData()['data']['FacNro']);
    }

    public function testResultDetailReturns404WhenAuditIsMissing(): void
    {
        $controller = new TestableAuditController(
            auditStatusModel: new StubAuditStatusModel(null)
        );

        $response = self::captureResponse(static fn() => $controller->resultDetail('T38250701547'));

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
        private ?AuditStateStore $stateStore = null,
        private ?BatchJobStore $jobStore = null,
        private ?AuditEventPublisher $publisher = null,
        private ?AuditStatusModel $auditStatusModel = null,
        private ?AuditDataService $auditDataService = null,
        private ?\App\Models\DispensationModel $dispensationModel = null,
    ) {
    }

    protected function getBody(): array
    {
        return $this->body;
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

    protected function buildAuditDataService(): AuditDataService
    {
        return $this->auditDataService ?? new StubAuditDataService();
    }

    protected function buildDispensationModel(): \App\Models\DispensationModel
    {
        return $this->dispensationModel ?? new StubDispensationModel();
    }
}

final class StubDispensationModel extends \App\Models\DispensationModel
{
    public function __construct() {}

    public function resolveIdentityByDisDetNro(string $disDetNro): string
    {
        return 'resolved-disId-123';
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
        ?string $disId = null
    ): bool {
        return $this->initAuditReturns;
    }

    public function deleteAudit(string $auditId): bool
    {
        $this->deletedAuditIds[] = $auditId;
        return true;
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        return true;
    }
}

final class StubAuditStatusModel extends AuditStatusModel
{
    public function __construct(private ?array $detail)
    {
    }

    public function getAuditDetailByFacNro(string $facNro): ?array
    {
        return $this->detail;
    }
}

final class StubAuditDataService extends AuditDataService
{
    public function __construct()
    {
    }

    public function getDispensation(array $filters): array
    {
        return [
            'header' => [
                'DisId' => $filters['dis_id'] ?? null,
                'NitSec' => '2426',
                'NumeroFactura' => 'T38250701547',
            ],
            'items' => [],
        ];
    }

    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        return [
            [
                'TipoAlmacenamiento' => 'BLOB',
                'nombre_alternativo' => 'Receta_medica',
            ]
        ];
    }
}

final class NotFoundAuditDataService extends AuditDataService
{
    public function __construct() {}

    public function getDispensation(array $filters): array
    {
        $disId = $filters['dis_id'] ?? 'unknown';
        throw new RuntimeException("FDV vacía: no existe DisId '{$disId}'", 404);
    }
}

class StubBatchJobStore extends BatchJobStore
{
    /** @var array<int,string> */
    public array $deletedJobIds = [];
    /** @var array<int,array{facSec:string,token:string}> */
    public array $releasedReservations = [];
    /** @var array<int,array{jobId:string,patch:array<string,mixed>}> */
    public array $patchedJobs = [];
    /** @var array<string,mixed>|null */
    public ?array $activeReservation = null;
    public ?string $claimIdempotencyKeyReturns = null;

    public function __construct(
        private bool $claimReturns = true,
        private bool $initJobReturns = true,
        private mixed $getJobReturns = null,
        private bool $getJobThrows = false,
    ) {
    }

    public function claimIdempotencyKey(string $idempotencyKey, string $jobId, int $ttlSeconds): ?string
    {
        return $this->claimIdempotencyKeyReturns;
    }

    public function claimAuditReservation(string $facSec, string $ownerToken, array $reservation, ?int $ttl = null): bool
    {
        return $this->claimReturns;
    }

    public function getAuditReservation(string $disId): ?array
    {
        return $this->activeReservation;
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

    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $facSec = null,
        ?string $reservationToken = null
    ): bool
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

    public function sealJob(string $jobId, int $total, array $metadata = []): bool
    {
        $this->patchedJobs[] = [
            'jobId' => $jobId,
            'patch' => array_merge($metadata, [
                'sealed' => true,
                'total' => $total,
            ]),
        ];
        return true;
    }

    public function deleteJob(string $jobId): bool
    {
        $this->deletedJobIds[] = $jobId;
        return true;
    }

    public function releaseAuditReservation(string $disId, string $ownerToken): bool
    {
        $this->releasedReservations[] = [
            'disId' => $disId,
            'token' => $ownerToken,
        ];
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
