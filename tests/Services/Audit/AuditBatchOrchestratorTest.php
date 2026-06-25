<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Models\AuditStatusModel;
use App\Models\InvoicesModel;
use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use PHPUnit\Framework\TestCase;

final class AuditBatchOrchestratorTest extends TestCase
{
    public function testEnqueueBatchSkipsExistingAuditByFacNroWithoutClaimingDisIdReservation(): void
    {
        $stateStore = new BatchOrchestratorStateStore();
        $jobStore = new BatchOrchestratorJobStore();
        $publisher = new BatchOrchestratorPublisher();
        $invoicesModel = new BatchOrchestratorInvoicesModel([
            [
                'NitSec' => 2426,
                'DisId' => '87723098',
                'Dispensa' => 'T38250701547',
                'DisFecSol' => '2026-06-12T00:00:00',
            ],
        ]);
        $auditStatusModel = new BatchOrchestratorAuditStatusModel([
            'DisId' => '87723098',
            'FacNro' => 'T38250701547',
        ]);

        $orchestrator = new AuditBatchOrchestrator(
            $stateStore,
            $jobStore,
            $publisher,
            $invoicesModel,
            $auditStatusModel,
        );

        $result = $orchestrator->enqueueBatch(2426, '2026-06-12', '2026-06-12', 1);

        $this->assertSame(BatchJobStore::JOB_STATUS_COMPLETED, $result['status']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['accepted']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertSame(['T38250701547'], $auditStatusModel->queriedFacNros);
        $this->assertSame(0, $jobStore->claimAuditReservationCalls);
        $this->assertSame(1, $jobStore->patchJobCalls);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED, $publisher->published[0]->eventType);
    }
}

final class BatchOrchestratorInvoicesModel extends InvoicesModel
{
    private int $calls = 0;

    /**
     * @param array<int,array<string,mixed>> $firstPage
     */
    public function __construct(private readonly array $firstPage)
    {
    }

    public function getInvoicesForAuditBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        ?array $cursor = null
    ): array {
        $this->calls++;

        return $this->calls === 1 ? $this->firstPage : [];
    }
}

final class BatchOrchestratorAuditStatusModel extends AuditStatusModel
{
    /** @var array<int,string> */
    public array $queriedFacNros = [];

    /**
     * @param array<string,mixed>|null $detail
     */
    public function __construct(private readonly ?array $detail)
    {
    }

    public function getAuditDetailByFacNro(string $facNro): ?array
    {
        $this->queriedFacNros[] = $facNro;

        return $this->detail;
    }
}

final class BatchOrchestratorJobStore extends BatchJobStore
{
    public int $claimAuditReservationCalls = 0;
    public int $patchJobCalls = 0;

    public function __construct()
    {
    }

    public function initJob(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): bool {
        return true;
    }

    public function claimAuditReservation(string $disId, string $ownerToken, array $reservation, ?int $ttl = null): bool
    {
        $this->claimAuditReservationCalls++;

        return true;
    }

    public function patchJob(string $jobId, array $patch): bool
    {
        $this->patchJobCalls++;

        return true;
    }

    public function getJob(string $jobId): ?array
    {
        return ['job_id' => $jobId];
    }

    public function deleteJob(string $jobId): bool
    {
        return true;
    }

    public function releaseAuditReservation(string $disId, string $ownerToken): bool
    {
        return true;
    }
}

final class BatchOrchestratorStateStore extends AuditStateStore
{
    public function __construct()
    {
    }

    public function deleteAudit(string $auditId): bool
    {
        return true;
    }
}

final class BatchOrchestratorPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;

        return $event->eventId;
    }
}
