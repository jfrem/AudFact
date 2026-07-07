<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Models\InvoicesModel;
use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use PHPUnit\Framework\TestCase;

final class AuditBatchOrchestratorTest extends TestCase
{
    public function testEnqueueBatchPublishesCompletedEventWhenNoCandidates(): void
    {
        $stateStore = new BatchOrchestratorStateStore();
        $jobStore = new BatchOrchestratorJobStore();
        $publisher = new BatchOrchestratorPublisher();
        $invoicesModel = new BatchOrchestratorInvoicesModel([]);

        $orchestrator = new AuditBatchOrchestrator(
            $stateStore,
            $jobStore,
            $publisher,
            $invoicesModel,
        );

        $result = $orchestrator->enqueueBatch(2426, '2026-06-12', '2026-06-12', 1);

        $this->assertSame(BatchJobStore::JOB_STATUS_COMPLETED, $result['status']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['accepted']);
        $this->assertSame(0, $jobStore->claimAuditReservationCalls);
        $this->assertSame(1, $jobStore->patchJobCalls);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED, $publisher->published[0]->eventType);
    }

    public function testEnqueueBatchClaimsReservationAndPublishesAuditCreated(): void
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

        $orchestrator = new AuditBatchOrchestrator(
            $stateStore,
            $jobStore,
            $publisher,
            $invoicesModel,
        );

        $result = $orchestrator->enqueueBatch(2426, '2026-06-12', '2026-06-12', 1);

        $this->assertSame(BatchJobStore::JOB_STATUS_PENDING, $result['status']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['accepted']);
        $this->assertSame(1, $jobStore->claimAuditReservationCalls);
        $this->assertCount(1, $jobStore->registeredAudits);
        $this->assertSame('87723098', $jobStore->registeredAudits[0]['dis_id']);
        $this->assertSame('T38250701547', $jobStore->registeredAudits[0]['dis_det_nro']);
        $this->assertCount(2, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publisher->published[1]->eventType);
        $this->assertSame('87723098', $publisher->published[1]->payload['dis_id']);
        $this->assertSame('T38250701547', $publisher->published[1]->payload['dis_det_nro']);
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

final class BatchOrchestratorJobStore extends BatchJobStore
{
    public int $claimAuditReservationCalls = 0;
    public int $patchJobCalls = 0;
    /** @var array<int,array<string,string|null>> */
    public array $registeredAudits = [];

    public function __construct()
    {
    }

    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $disId = null,
        ?string $reservationToken = null
    ): bool {
        $this->registeredAudits[] = [
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'dis_det_nro' => $disDetNro,
            'dis_id' => $disId,
            'reservation_token' => $reservationToken,
        ];

        return true;
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

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $disId = null
    ): bool {
        return true;
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        return true;
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
