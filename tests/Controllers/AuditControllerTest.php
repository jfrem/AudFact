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
use Tests\Support\InMemoryBatchJobStore;

final class AuditControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Core\Env::set('GEMINI_QUOTA_SCOPE', 'audfact-test');
        \Core\Env::set('GEMINI_MODEL', 'gemini-3.8-flash');
        \Core\Env::set('GEMINI_QUOTA_RPM', '2000');
        \Core\Env::set('GEMINI_QUOTA_TPM', '4000000');
        \Core\Env::set('GEMINI_QUOTA_RPD', '1000000');
        \Core\Env::set('GEMINI_QUOTA_MAX_INFLIGHT', '16');
    }

    protected function tearDown(): void
    {
        foreach (['GEMINI_QUOTA_SCOPE', 'GEMINI_MODEL', 'GEMINI_QUOTA_RPM', 'GEMINI_QUOTA_TPM', 'GEMINI_QUOTA_RPD', 'GEMINI_QUOTA_MAX_INFLIGHT'] as $key) {
            \Core\Env::set($key, null);
        }
        parent::tearDown();
    }

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
        $this->assertNull($publisher->published[0]->payload['attachment_id']);
    }

    public function testSingleWithAttachmentIdPropagatesToEventPayloadAndResponse(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $store = $this->newStoreStub(initAuditReturns: true);

        $controller = new TestableAuditController(
            body: ['disId' => '87723098', 'disDetNro' => 'T38250701547', 'attachmentId' => '14250781'],
            stateStore: $store,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->single());

        $this->assertSame(202, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertSame('14250781', $data['attachment_id']);
        $this->assertCount(1, $publisher->published);
        $this->assertSame('14250781', $publisher->published[0]->payload['attachment_id']);
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

    public function testPausedAdmissionReturns503BeforeCreatingAuditOrIdempotencyKey(): void
    {
        // Arrange:
        $redis = $this->createMock(\Core\RedisClient::class);
        $redis->method('get')->willReturnCallback(static fn(string $key): ?string =>
            $key === 'audit:admission:paused' ? '1' : null);
        $state = $this->createMock(AuditStateStore::class);
        $state->expects($this->never())->method('initAudit');
        $jobs = $this->createMock(BatchJobStore::class);
        $jobs->expects($this->never())->method('claimIdempotencyKey');
        $jobs->expects($this->never())->method('initJobExclusive');
        $publisher = new InMemoryAuditEventPublisher();
        $single = new TestableAuditController(body: ['disId' => 'synthetic', 'disDetNro' => 'synthetic'],
            stateStore: $state, publisher: $publisher, admissionRedis: $redis);
        $batch = new TestableAuditController(body: ['facNitSec' => 2426, 'date' => '2026-01-01'],
            jobStore: $jobs, publisher: $publisher, admissionRedis: $redis);

        // Act:
        $singleResponse = self::captureResponse(static fn() => $single->single());
        $batchResponse = self::captureResponse(static fn() => $batch->async());

        // Assert:
        self::assertSame(503, $singleResponse->getCode());
        self::assertSame(503, $batchResponse->getCode());
        self::assertSame([], $publisher->published);
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
        $jobStore = $this->newJobStoreStub();

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
        $this->assertSame(1, $jobStore->initJobExclusiveCalls, 'El job se crea con initJobExclusive (INV-03).');
        $this->assertSame([], $jobStore->releasedIdempotencyKeys, 'En el camino exitoso la llave de idempotencia se conserva.');
        $this->assertSame([], $jobStore->deletedJobIds);

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
        $this->assertSame(0, $jobStore->initJobExclusiveCalls, 'Una solicitud repetida no crea otro job.');
        $this->assertSame([], $jobStore->releasedIdempotencyKeys, 'La llave previa pertenece a otro job: no se libera.');

        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
    }

    /** T-U4 (CA-F1-15, REQ-F1-11): TTL de idempotencia inválido ⇒ 500 controlado, sin job ni evento. */
    public function testAsyncReturns500WhenIdempotencyTtlIsInvalid(): void
    {
        $publisher = new InMemoryAuditEventPublisher();
        $jobStore = $this->newJobStoreStub();
        $jobStore->claimIdempotencyKeyThrowsInvalid = true;

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

        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'fake-key-ttl-invalid';

        try {
            $response = self::captureResponse(static fn() => $controller->async());
        } finally {
            unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
        }

        $this->assertSame(500, $response->getCode());
        $this->assertSame('Configuración de idempotencia inválida', $response->getData()['message']);
        $this->assertSame([], $publisher->published);
        $this->assertSame(0, $jobStore->initJobExclusiveCalls, 'Con TTL inválido no debe iniciarse ningún job (initJobExclusive).');
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
        // El controlador espera una excepción de initJobExclusive.
        $jobStore = new class extends StubBatchJobStore {
            public function initJobExclusive(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): array {
                throw new RuntimeException('Falla initJobExclusive');
            }
        };
        $publisher = new InMemoryAuditEventPublisher();

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertStringContainsString('Falla initJobExclusive', $response->getData()['message']);
        $this->assertCount(1, $jobStore->releasedIdempotencyKeys, 'La llave de idempotencia recién reclamada se libera.');
        $this->assertSame('test-uuid-1234', $jobStore->releasedIdempotencyKeys[0]['key']);
        $this->assertSame([], $publisher->published);
    }

    public function testAsyncReturns503WhenBatchPublishFails(): void
    {
        $publisher = new InMemoryAuditEventPublisher(throwOnPublish: true);
        $jobStore = $this->newJobStoreStub();

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: $publisher,
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertCount(1, $jobStore->deletedJobIds, 'El job creado se elimina si no se pudo publicar el evento.');
        $this->assertCount(1, $jobStore->releasedIdempotencyKeys, 'La llave de idempotencia se libera para permitir el reintento.');
        $this->assertSame($jobStore->deletedJobIds[0], $jobStore->releasedIdempotencyKeys[0]['job_id']);
    }

    /** W-F2-M6: un fallo de Redis dentro de la compensación no convierte el 503 en 500. */
    public function testAsyncCompensationFailureStillReturns503(): void
    {
        $jobStore = $this->newJobStoreStub();
        $jobStore->deleteJobThrows = true;
        $jobStore->releaseIdempotencyThrows = true;

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $jobStore,
            publisher: new InMemoryAuditEventPublisher(throwOnPublish: true),
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertStringContainsString('Error publicando evento batch_requested', $response->getData()['message']);
        $this->assertSame(1, $jobStore->deleteJobAttempts, 'Se intentó compensar aunque Redis falló.');
        $this->assertSame(1, $jobStore->releaseIdempotencyAttempts);

        // Mismo comportamiento si falla la compensación tras un error de initJobExclusive.
        $initFails = new class extends StubBatchJobStore {
            public function initJobExclusive(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): array {
                throw new RuntimeException('Falla initJobExclusive');
            }
        };
        $initFails->releaseIdempotencyThrows = true;
        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2025-07-29', 'limit' => 10],
            jobStore: $initFails,
            publisher: new InMemoryAuditEventPublisher(),
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(503, $response->getCode());
        $this->assertSame(1, $initFails->releaseIdempotencyAttempts);
    }

    public function testAsyncReturns409WhenActiveJobOverlapsSameNitAndDateRange(): void
    {
        $store = new InMemoryBatchJobStore();
        $activeJobId = AuditEvent::uuidV4();
        $store->initJobExclusive($activeJobId, 2426, '2026-07-01', '2026-07-31', 100);
        $publisher = new InMemoryAuditEventPublisher();

        $controller = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2026-07-15', 'dateTo' => '2026-08-15', 'limit' => 10],
            jobStore: $store,
            publisher: $publisher,
            idempotencyKey: 'overlap-key-1',
        );

        $response = self::captureResponse(static fn() => $controller->async());

        $this->assertSame(409, $response->getCode());
        $payload = $response->getData();
        $this->assertFalse($payload['success']);
        $this->assertSame('Ya existe un job activo para el cliente y rango de fechas especificado', $payload['message']);
        $this->assertSame([
            'active_job_id' => $activeJobId,
            'fac_nit_sec' => 2426,
            'date_from' => '2026-07-15',
            'date_to' => '2026-08-15',
        ], $payload['errors']);
        $this->assertSame([], $publisher->published, 'No se publica batch_requested.');
        $this->assertSame([$activeJobId], array_keys($store->jobs), 'No se crea un segundo job.');
        $this->assertArrayNotHasKey('overlap-key-1', $store->idempotency, 'La llave de idempotencia reclamada se libera (CA-F2-05).');
        $this->assertSame(1, $store->countCalls('releaseIdempotencyKeyIfMatches'));
    }

    public function testAsyncAllowsNonOverlappingDateRangeForSameNit(): void
    {
        $store = new InMemoryBatchJobStore();
        $store->initJobExclusive(AuditEvent::uuidV4(), 2426, '2026-01-01', '2026-06-30', 100);
        $publisher = new InMemoryAuditEventPublisher();

        // Rango contiguo no solapado → 202.
        $contiguous = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2026-07-01', 'dateTo' => '2026-07-31', 'limit' => 10],
            jobStore: $store,
            publisher: $publisher,
            idempotencyKey: 'contiguous-key',
        );
        $accepted = self::captureResponse(static fn() => $contiguous->async());

        $this->assertSame(202, $accepted->getCode());
        $newJobId = $accepted->getData()['data']['job_id'];
        $this->assertCount(2, $store->jobs);
        $this->assertSame($newJobId, $store->idempotency['contiguous-key'], 'La llave se conserva en el camino exitoso.');
        $this->assertCount(1, $publisher->published);

        // Otro NIT con el mismo rango → 202 (INV-03 es por NIT).
        $otherNit = new TestableAuditController(
            body: ['facNitSec' => 9999, 'date' => '2026-01-15', 'dateTo' => '2026-02-15', 'limit' => 10],
            jobStore: $store,
            publisher: $publisher,
            idempotencyKey: 'other-nit-key',
        );
        $this->assertSame(202, self::captureResponse(static fn() => $otherNit->async())->getCode());

        // Rango solapado con el job recién creado → 409 y se libera su llave.
        $overlapping = new TestableAuditController(
            body: ['facNitSec' => 2426, 'date' => '2026-06-15', 'dateTo' => '2026-07-31', 'limit' => 10],
            jobStore: $store,
            publisher: $publisher,
            idempotencyKey: 'overlapping-key',
        );
        $rejected = self::captureResponse(static fn() => $overlapping->async());

        $this->assertSame(409, $rejected->getCode());
        $this->assertCount(3, $store->jobs);
        $this->assertArrayNotHasKey('overlapping-key', $store->idempotency);
        $this->assertCount(2, $publisher->published);
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
            'sealed'     => true,
            'limit'      => 10,
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
        $this->assertTrue($data['sealed']);
        $this->assertSame(10, $data['limit']);
        $this->assertSame(5, $data['total']);
        $this->assertSame(2, $data['done']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(2, $data['pending']);
        $this->assertSame(60000, $data['accumulated_duration_ms']);
        $this->assertSame(20000, $data['avg_duration_ms']);
        $this->assertSame(0.01, $data['throughput_per_sec']);
        $this->assertSame(300000, $data['elapsed_ms']);
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

    public function testJobsListReturns200WithLightweightSummaryAndEnrichedClients(): void
    {
        $jobStore = new StubBatchJobStore();
        $jobStore->listJobsReturns = [
            [
                'job_id' => '85fabb86-d563-4d1c-bb49-659b728d0126',
                'fac_nit_sec' => 2624,
                'status' => 'processing',
                'total' => 3000,
                'done' => 500,
                'failed' => 2,
                'pending' => 2498,
                'progress_percent' => 16,
                'created_at' => '2026-08-20T15:00:14Z',
            ],
            [
                'job_id' => '7df545b9-f004-4f40-82b7-39406993df6f',
                'fac_nit_sec' => 2426,
                'status' => 'completed',
                'total' => 14,
                'done' => 14,
                'failed' => 0,
                'pending' => 0,
                'progress_percent' => 100,
                'created_at' => '2026-08-20T15:00:15Z',
            ],
        ];

        $clientsModel = new StubClientsModel([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS SA'],
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑIA DE SEGUROS SA'],
        ]);

        $controller = new TestableAuditController(
            jobStore: $jobStore,
            clientsModel: $clientsModel
        );

        $response = self::captureResponse(static fn() => $controller->jobsList());

        $this->assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        $this->assertCount(2, $data);
        $this->assertSame('NUEVA EPS SA', $data[0]['client_name']);
        $this->assertSame('POSITIVA COMPAÑIA DE SEGUROS SA', $data[1]['client_name']);
        $this->assertSame('processing', $data[0]['status']);
    }

    public function testJobsListHandlesStoreFailureWith503(): void
    {
        $jobStore = new StubBatchJobStore();
        $jobStore->listJobsThrows = true;

        $controller = new TestableAuditController(
            jobStore: $jobStore
        );

        $response = self::captureResponse(static fn() => $controller->jobsList());

        $this->assertSame(503, $response->getCode());
    }

    // ── Helpers ────────────────────────────────────────────────

    private function newStoreStub(
        bool $initAuditReturns = true,
    ): StubAuditStateStore {
        return new StubAuditStateStore($initAuditReturns);
    }

    private function newJobStoreStub(
        bool $claimReturns = true,
        bool $initCreated = true,
        mixed $getJobReturns = null,
        bool $getJobThrows = false,
    ): StubBatchJobStore {
        return new StubBatchJobStore(
            $claimReturns,
            $initCreated,
            $getJobReturns,
            $getJobThrows,
        );
    }

    public function testMonthlyPerformanceReturns200WithSummaryAndItems(): void
    {
        $model = new StubAuditStatusModel();
        $model->monthlyStats = [
            [
                'mes'          => 8,
                'fac_nit_sec'  => 2624,
                'tercero'      => 'NUEVA EPS SA',
                'aud_conf'     => 2898,
                'aud_rech'     => 3995,
                'total'        => 6893,
                'rate_conf'    => 42.0,
                'aud_conf_doc' => 9263,
                'aud_rech_doc' => 13784,
                'total_doc'    => 23047,
            ],
            [
                'mes'          => 8,
                'fac_nit_sec'  => 2426,
                'tercero'      => 'POSITIVA COMPAIA DE SEGUROS SA',
                'aud_conf'     => 3715,
                'aud_rech'     => 1864,
                'total'        => 5579,
                'rate_conf'    => 66.6,
                'aud_conf_doc' => 11145,
                'aud_rech_doc' => 5592,
                'total_doc'    => 16737,
            ],
        ];

        $_GET['year'] = '2026';
        \Core\Cache::forget('audit:stats:monthly:2026');
        $controller = new TestableAuditController(auditStatusModel: $model);

        $response = self::captureResponse(fn () => $controller->monthlyPerformance());

        self::assertSame(200, $response->getCode());
        self::assertTrue($response->getData()['success']);
        $data = $response->getData()['data'];
        self::assertSame(2026, $data['year']);
        self::assertSame(12472, $data['summary']['total_facturas']);
        self::assertSame(6613, $data['summary']['total_conformes']);
        self::assertSame(5859, $data['summary']['total_rechazadas']);
        self::assertSame(53.0, $data['summary']['global_rate_conf']);
        self::assertSame(39784, $data['summary']['total_documentos']);
        self::assertCount(2, $data['items']);
    }

    public function testMonthlyPerformanceHandlesInvalidYear422(): void
    {
        $_GET['year'] = 'invalid-year';
        $controller = new TestableAuditController();

        $response = self::captureResponse(fn () => $controller->monthlyPerformance());

        self::assertSame(422, $response->getCode());
    }

    public function testMonthlyPerformanceHandlesModelException503(): void
    {
        $model = new StubAuditStatusModel();
        $model->monthlyStatsThrows = true;

        $_GET['year'] = '2025';
        \Core\Cache::forget('audit:stats:monthly:2025');
        $controller = new TestableAuditController(auditStatusModel: $model);

        $response = self::captureResponse(fn () => $controller->monthlyPerformance());

        self::assertSame(503, $response->getCode());
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
        private ?\App\Models\ClientsModel $clientsModel = null,
        private ?\Core\RedisClient $admissionRedis = null,
        private string $idempotencyKey = 'test-uuid-1234',
    ) {
    }

    protected function getBody(): array
    {
        return $this->body;
    }

    protected function buildAdmissionRedis(): \Core\RedisClient
    {
        return $this->admissionRedis ?? new class extends \Core\RedisClient {
            public function __construct() {}
            public function get(string $key): ?string { return $key === 'audit:execution:heartbeat' ? '1000000' : null; }
            public function serverConfiguration(array $patterns): array
            {
                return ['appendonly' => 'yes', 'appendfsync' => 'always', 'maxmemory-policy' => 'noeviction'];
            }
            public function serverInfo(?string $section = null): array
            {
                return ['aof_last_write_status' => 'ok', 'used_memory' => '100', 'maxmemory' => '1000'];
            }
            public function eval(string $script, array $keys = [], array $args = []): mixed
            {
                if ($keys === []) {
                    return 1000000;
                }
                return ($keys[0] ?? '') === \App\Services\Audit\Pipeline\FairAuditTaskQueue::COUNTS
                    ? [[], array_fill(0, count($keys) - 2, [])]
                    : [];
            }
        };
    }

    protected function currentTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-23T10:05:00Z');
    }

    protected function getRequestHeaders(): array
    {
        return ['X-Idempotency-Key' => $this->idempotencyKey];
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

    protected function buildClientsModel(): \App\Models\ClientsModel
    {
        return $this->clientsModel ?? new StubClientsModel([]);
    }
}

final class StubClientsModel extends \App\Models\ClientsModel
{
    public function __construct(private array $clients = []) {}

    public function getAllClients(): array
    {
        return $this->clients;
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
    /** @var array<int,array<string,mixed>> */
    public array $monthlyStats = [];
    public bool $monthlyStatsThrows = false;

    public function __construct(private ?array $detail = null)
    {
    }

    public function getAuditDetailByFacNro(string $facNro): ?array
    {
        return $this->detail;
    }

    public function getMonthlyPerformanceStats(int $year): array
    {
        if ($this->monthlyStatsThrows) {
            throw new RuntimeException('Error consultando base de datos');
        }
        return $this->monthlyStats;
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

    public function getAttachments(string $disDetNro, string $nitSec, ?string $attachmentId = null): array
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
    public bool $claimIdempotencyKeyThrowsInvalid = false;
    public int $initJobExclusiveCalls = 0;
    /** @var array{created:bool,conflict_job_id:?string,reason:?string} */
    public array $initResult = ['created' => true, 'conflict_job_id' => null, 'reason' => null];
    /** @var array<int,array{key:string,job_id:string}> */
    public array $releasedIdempotencyKeys = [];
    public bool $deleteJobThrows = false;
    public bool $releaseIdempotencyThrows = false;
    public int $deleteJobAttempts = 0;
    public int $releaseIdempotencyAttempts = 0;

    public function __construct(
        private bool $claimReturns = true,
        bool $initCreated = true,
        private mixed $getJobReturns = null,
        private bool $getJobThrows = false,
    ) {
        if (!$initCreated) {
            $this->initResult = ['created' => false, 'conflict_job_id' => 'active-job', 'reason' => 'overlap'];
        }
    }

    public function claimIdempotencyKey(string $idempotencyKey, string $jobId, int $ttlSeconds): ?string
    {
        if ($this->claimIdempotencyKeyThrowsInvalid) {
            throw new \InvalidArgumentException('idempotency_ttl_invalid');
        }
        return $this->claimIdempotencyKeyReturns;
    }

    public function claimAuditReservation(string $facSec, string $ownerToken, array $reservation): bool
    {
        return $this->claimReturns;
    }

    public function getAuditReservation(string $disId): ?array
    {
        return $this->activeReservation;
    }

    public function initJobExclusive(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): array {
        $this->initJobExclusiveCalls++;
        return $this->initResult;
    }

    public function releaseIdempotencyKeyIfMatches(string $key, string $jobId): bool
    {
        $this->releaseIdempotencyAttempts++;
        if ($this->releaseIdempotencyThrows) {
            throw new RuntimeException('Redis no disponible al liberar idempotencia', 503);
        }
        $this->releasedIdempotencyKeys[] = ['key' => $key, 'job_id' => $jobId];
        return true;
    }

    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $facSec = null,
        ?string $reservationToken = null,
        ?int $expectedEpoch = null
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

    public function sealJob(
        string $jobId,
        int $skippedLockedDelta = 0,
        int $skippedExistingDelta = 0,
        ?int $expectedEpoch = null,
        ?int $chunkIndex = null,
        ?int $nextOffset = null
    ): bool {
        $this->patchedJobs[] = [
            'jobId' => $jobId,
            'patch' => [
                'sealed' => true,
                'skipped_locked' => $skippedLockedDelta,
                'skipped_existing' => $skippedExistingDelta,
            ],
        ];
        return true;
    }

    public function deleteJob(string $jobId): bool
    {
        $this->deleteJobAttempts++;
        if ($this->deleteJobThrows) {
            throw new RuntimeException('Redis no disponible al eliminar job', 503);
        }
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

    /** @var array<int,array<string,mixed>> */
    public array $listJobsReturns = [];
    public bool $listJobsThrows = false;

    public function listJobs(int $limit = 50): array
    {
        if ($this->listJobsThrows) {
            throw new RuntimeException('Redis no disponible al listar jobs', 503);
        }
        return $this->listJobsReturns;
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
