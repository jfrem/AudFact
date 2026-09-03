<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Models\AuditConfigModel;
use App\Models\ClientsModel;
use App\Models\InvoicesModel;
use App\Services\Audit\MultiClientBatchDispatcher;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MultiClientBatchDispatcherTest extends TestCase
{
    public function testDispatchesMultipleClientsInRoundRobinInterleaving(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑÍA DE SEGUROS'],
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true, 'RECIBO' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')
            ->willReturnCallback(function (int $facNitSec, string $dateFrom, string $dateTo, int $limit, ?array $cursor): array {
                $maxTotal = 40; // 40 facturas por cliente
                $batch = [];
                for ($i = 1; $i <= $limit && $i <= $maxTotal; $i++) {
                    $batch[] = [
                        'DisId' => "DISID-{$facNitSec}-{$i}",
                        'Dispensa' => "DISP-{$facNitSec}-{$i}",
                        'DisFecSol' => '2026-08-28T10:00:00Z',
                    ];
                }

                return $batch;
            });

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publishedEvents = [];
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
            $publishedEvents[] = $event;
            return '1787932627594-0';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 40, chunkSize: 10);

        $this->assertSame(2, $result['queued_clients']);
        $this->assertSame(80, $result['total_invoices_queued']);
        $this->assertSame(0, $result['errors']);

        // Verificar que los eventos batch_created se publicaron ANTES del primer audit_created
        $batchCreatedIndices = [];
        $firstAuditCreatedIndex = null;

        foreach ($publishedEvents as $idx => $event) {
            if ($event->eventType === AuditEvent::TYPE_BATCH_CREATED) {
                $batchCreatedIndices[] = $idx;
            } elseif ($event->eventType === AuditEvent::TYPE_AUDIT_CREATED && $firstAuditCreatedIndex === null) {
                $firstAuditCreatedIndex = $idx;
            }
        }

        $this->assertCount(2, $batchCreatedIndices);
        $this->assertNotNull($firstAuditCreatedIndex);
        $this->assertLessThan($firstAuditCreatedIndex, max($batchCreatedIndices), 'Todos los batch_created deben publicarse antes del primer audit_created');

        // Filtrar solo los eventos audit_created para verificar el entrelazado Round-Robin
        $auditCreatedEvents = array_filter(
            $publishedEvents,
            fn(AuditEvent $e) => $e->eventType === AuditEvent::TYPE_AUDIT_CREATED
        );
        $this->assertCount(80, $auditCreatedEvents);

        $nitSecSequence = array_map(
            fn(AuditEvent $e) => (int) $e->payload['fac_nit_sec'],
            array_values($auditCreatedEvents)
        );

        // Verificar que los primeros 10 son de 2426, los siguientes 10 de 2624, los siguientes 10 de 2426, etc.
        $this->assertSame(array_fill(0, 10, 2426), array_slice($nitSecSequence, 0, 10));
        $this->assertSame(array_fill(0, 10, 2624), array_slice($nitSecSequence, 10, 10));
        $this->assertSame(array_fill(0, 10, 2426), array_slice($nitSecSequence, 20, 10));
        $this->assertSame(array_fill(0, 10, 2624), array_slice($nitSecSequence, 30, 10));
        $this->assertSame(array_fill(0, 10, 2426), array_slice($nitSecSequence, 40, 10));
        $this->assertSame(array_fill(0, 10, 2624), array_slice($nitSecSequence, 50, 10));
        $this->assertSame(array_fill(0, 10, 2426), array_slice($nitSecSequence, 60, 10));
        $this->assertSame(array_fill(0, 10, 2624), array_slice($nitSecSequence, 70, 10));
    }

    public function testKeysetPaginationFetchesBeyondSqlPageLimit(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $calls = 0;
        $invoicesModel->method('getInvoicesForAuditBatch')
            ->willReturnCallback(function (int $facNitSec, string $dateFrom, string $dateTo, int $limit, ?array $cursor) use (&$calls): array {
                $calls++;
                $start = $cursor !== null ? ((int) str_replace('DISP-', '', $cursor['dispensa'])) + 1 : 1;
                $batch = [];
                for ($i = 0; $i < $limit; $i++) {
                    $num = $start + $i;
                    $batch[] = [
                        'DisId' => "DISID-{$num}",
                        'Dispensa' => "DISP-{$num}",
                        'DisFecSol' => '2026-08-28T10:00:00Z',
                    ];
                }
                return $batch;
            });

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 2500, chunkSize: 50);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(2500, $result['total_invoices_queued']);
        $this->assertSame(3, $calls, 'Debe haber llamado 3 veces (1000 + 1000 + 500) usando keyset pagination');
    }

    public function testHandlesDatabaseQueryFailureWithRollbackAndIdempotencyRelease(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')
            ->willThrowException(new RuntimeException('SQL Server connection timeout'));

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->expects($this->once())->method('deleteJob')->willReturn(true);
        $jobStore->expects($this->once())->method('releaseIdempotencyKey');

        $stateStore = $this->createMock(AuditStateStore::class);
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->never())->method('publish');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 50);

        $this->assertSame(0, $result['queued_clients']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('error_query', $result['clients'][0]['status']);
        $this->assertStringContainsString('SQL Server connection timeout', $result['clients'][0]['error']);
    }

    public function testHandlesAsymmetricBatchSizes(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 100, 'NitCom' => 'CLIENTE PEQUEÑO'],
            ['NitSec' => 200, 'NitCom' => 'CLIENTE GRANDE'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')
            ->willReturnCallback(function (int $facNitSec, string $dateFrom, string $dateTo, int $limit): array {
                $maxTotal = ($facNitSec === 100) ? 15 : 50;
                $batch = [];
                for ($i = 1; $i <= $limit && $i <= $maxTotal; $i++) {
                    $batch[] = [
                        'DisId' => "DISID-{$facNitSec}-{$i}",
                        'Dispensa' => "DISP-{$facNitSec}-{$i}",
                        'DisFecSol' => '2026-08-28T10:00:00Z',
                    ];
                }

                return $batch;
            });

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publishedEvents = [];
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
            $publishedEvents[] = $event;
            return '1787932627594-0';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 50, chunkSize: 10);

        $this->assertSame(2, $result['queued_clients']);
        $this->assertSame(65, $result['total_invoices_queued']); // 15 + 50

        $auditCreatedEvents = array_filter(
            $publishedEvents,
            fn(AuditEvent $e) => $e->eventType === AuditEvent::TYPE_AUDIT_CREATED
        );
        $this->assertCount(65, $auditCreatedEvents);

        $nitSecSequence = array_map(
            fn(AuditEvent $e) => (int) $e->payload['fac_nit_sec'],
            array_values($auditCreatedEvents)
        );

        // Ronda 1: 10 de 100, 10 de 200
        $this->assertSame(array_fill(0, 10, 100), array_slice($nitSecSequence, 0, 10));
        $this->assertSame(array_fill(0, 10, 200), array_slice($nitSecSequence, 10, 10));

        // Ronda 2: 5 restantes de 100, 10 de 200
        $this->assertSame(array_fill(0, 5, 100), array_slice($nitSecSequence, 20, 5));
        $this->assertSame(array_fill(0, 10, 200), array_slice($nitSecSequence, 25, 10));

        // Rondas posteriores: solo 200 sigue emitiendo
        $this->assertSame(array_fill(0, 10, 200), array_slice($nitSecSequence, 35, 10));
        $this->assertSame(array_fill(0, 10, 200), array_slice($nitSecSequence, 45, 10));
        $this->assertSame(array_fill(0, 10, 200), array_slice($nitSecSequence, 55, 10));
    }

    public function testSkipsClientsWithoutConfig(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 101, 'NitCom' => 'CLIENTE SIN CONFIG'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn(null);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $jobStore = $this->createMock(BatchJobStore::class);
        $stateStore = $this->createMock(AuditStateStore::class);
        $publisher = $this->createMock(AuditEventPublisher::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28');

        $this->assertSame(0, $result['queued_clients']);
        $this->assertSame(1, $result['skipped_no_config']);
        $this->assertSame('skipped_no_config', $result['clients'][0]['status']);
    }

    public function testSkipsDuplicateJobsWithinIdempotencyTtl(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn('existing-job-uuid-1234');

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $stateStore = $this->createMock(AuditStateStore::class);
        $publisher = $this->createMock(AuditEventPublisher::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28');

        $this->assertSame(0, $result['queued_clients']);
        $this->assertSame(1, $result['skipped_duplicate']);
        $this->assertSame('skipped_duplicate', $result['clients'][0]['status']);
        $this->assertSame('existing-job-uuid-1234', $result['clients'][0]['job_id']);
    }

    public function testDryRunModeDoesNotPublishOrMutateState(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->expects($this->never())->method('getInvoicesForAuditBatch');

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->expects($this->never())->method('initJob');

        $stateStore = $this->createMock(AuditStateStore::class);
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->expects($this->never())->method('publish');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', dryRun: true);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(0, $result['total_invoices_queued']);
        $this->assertSame('dry_run_queued', $result['clients'][0]['status']);
    }

    public function testHandlesPublishingFailureWithReconciliationAndSlotRelease(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DISID-1', 'Dispensa' => 'DISP-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);
        // Debe reconciliar marcando como failed en el job y liberando la reserva
        $jobStore->expects($this->once())->method('markAuditCompletedInJob')
            ->with($this->anything(), $this->anything(), 'failed', 0, $this->stringContains('publisher_failed'))
            ->willReturn(true);
        $jobStore->expects($this->once())->method('releaseAuditReservation')->willReturn(true);
        $jobStore->method('getJob')->willReturn([
            'status' => BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR,
            'total' => 1,
            'done' => 0,
            'failed' => 1,
            'accepted' => 1,
            'skipped_locked' => 0,
        ]);
        $jobStore->method('claimBatchTerminalEvent')->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);
        $stateStore->method('deleteAudit')->willReturn(true);

        $publishedEvents = [];
        $publisher = $this->createMock(AuditEventPublisher::class);
        // batch_created pasa exitosamente, audit_created falla, batch_completed_with_errors pasa
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
            $publishedEvents[] = $event;
            if ($event->eventType === AuditEvent::TYPE_AUDIT_CREATED) {
                throw new RuntimeException('Redis Stream connection severed');
            }
            return '1787932627594-0';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 1);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(0, $result['total_invoices_queued']);
        $this->assertSame(1, $result['errors'], 'Debe contabilizar el error de publicación');
        $this->assertCount(5, $publishedEvents, '1 batch_created + 3 intentos fallidos de audit_created + 1 batch_completed_with_errors');
        $this->assertSame(AuditEvent::TYPE_BATCH_CREATED, $publishedEvents[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publishedEvents[1]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publishedEvents[2]->eventType);
        $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $publishedEvents[3]->eventType);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publishedEvents[4]->eventType);
    }

    public function testHandlesFullChunkPublishingFailuresAndContinuesProcessingSubsequentChunks(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        // 10 facturas: el primer chunk (5) fallará, el segundo chunk (5) pasará
        $invoices = [];
        for ($i = 1; $i <= 10; $i++) {
            $invoices[] = [
                'DisId' => "DISID-{$i}",
                'Dispensa' => "DISP-{$i}",
                'DisFecSol' => '2026-08-28T10:00:00Z',
            ];
        }

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn($invoices);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        // Debe reconciliar exactamente los 5 eventos fallidos del primer chunk
        $reconciledCount = 0;
        $jobStore->expects($this->exactly(5))->method('markAuditCompletedInJob')
            ->willReturnCallback(function () use (&$reconciledCount) {
                $reconciledCount++;
                return true;
            });
        $releasedCount = 0;
        $jobStore->expects($this->exactly(5))->method('releaseAuditReservation')
            ->willReturnCallback(function () use (&$releasedCount) {
                $releasedCount++;
                return true;
            });

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);
        $stateStore->method('deleteAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publishedCount = 0;
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedCount): string {
            if ($event->eventType === AuditEvent::TYPE_BATCH_CREATED) {
                return '1787932627594-0';
            }
            $disId = $event->payload['dis_id'] ?? '';
            // Eventos 1..5 fallan; eventos 6..10 pasan
            if (in_array($disId, ['DISID-1', 'DISID-2', 'DISID-3', 'DISID-4', 'DISID-5'], true)) {
                throw new RuntimeException('Connection timed out');
            }
            $publishedCount++;
            return '1787932627594-1';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 10, chunkSize: 5);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(5, $result['total_invoices_queued'], 'Debe haber encolado exitosamente el segundo chunk');
        $this->assertSame(5, $result['errors'], 'Debe contabilizar los 5 fallos del primer chunk');
        $this->assertSame(5, $publishedCount);
        $this->assertSame(5, $reconciledCount);
        $this->assertSame(5, $releasedCount);
    }

    public function testHandlesSharedRedisOutageWithoutDroppingUnreconciledEvents(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $configModel = $this->createMock(AuditConfigModel::class);
        $invoicesModel = $this->createMock(InvoicesModel::class);

        $jobStore = $this->createMock(BatchJobStore::class);
        // Cuando falla la publicación, la reconciliación también falla porque Redis está totalmente caído
        $jobStore->method('markAuditCompletedInJob')->willThrowException(new RuntimeException('Redis down'));

        $stateStore = $this->createMock(AuditStateStore::class);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willThrowException(new RuntimeException('Redis Streams unreachable'));

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $jobId = AuditEvent::uuidV4();
        $preparedEvents = [];
        for ($i = 1; $i <= 5; $i++) {
            $preparedEvents[] = [
                'event' => AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_CREATED,
                    auditId: AuditEvent::uuidV4(),
                    jobId: $jobId,
                    payload: ['dis_det_nro' => "DET-{$i}", 'fac_nit_sec' => '2426', 'dis_id' => "DIS-{$i}"]
                ),
                'dis_id' => "DIS-{$i}",
                'reservation_token' => AuditEvent::uuidV4(),
            ];
        }

        $readyClients = [
            $jobId => [
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'job_id' => $jobId,
                'prepared_events' => $preparedEvents,
                'total_enqueued' => 0,
                'total_target' => 5,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $reflection = new \ReflectionClass($dispatcher);
        $method = $reflection->getMethod('interleaveAndPublishAuditEvents');
        $method->setAccessible(true);
        $method->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        // Debe registrar el error
        $this->assertSame(1, $summary['errors']);
        $this->assertSame(0, $summary['total_invoices_queued']);

        // CRÍTICO: Los 5 eventos NO fueron descartados a ciegas; permanecen intactos en la cola en memoria (QUAL-004)
        $this->assertCount(5, $readyClients[$jobId]['prepared_events'], 'Los eventos no reconciliados deben preservarse en memoria sin pérdida');
    }

    public function testEmptyBatchSealingAtomicallyTransitionsMetrics(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑÍA DE SEGUROS'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        // QUAL-010: lote vacío ahora usa patchJob + claim/confirm protocol
        $jobStore->method('patchJob')->willReturn(true);
        $jobStore->method('claimBatchTerminalEvent')->willReturn(true);
        $jobStore->method('confirmBatchTerminalEvent')->willReturn(true);
        // Debe invocar sealJob con total 0
        $jobStore->expects($this->once())->method('sealJob')->with($this->anything(), 0, $this->anything())->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);

        $publishedEvents = [];
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
            $publishedEvents[] = $event;
            return '1787932627594-0';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28');

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(0, $result['total_invoices_queued']);
        $this->assertSame('completed_empty', $result['clients'][0]['status']);
        $this->assertCount(1, $publishedEvents);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED, $publishedEvents[0]->eventType);
    }

    public function testInterleavesMultipleJobsOfSameClient(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $configModel = $this->createMock(AuditConfigModel::class);
        $invoicesModel = $this->createMock(InvoicesModel::class);
        $jobStore = $this->createMock(BatchJobStore::class);
        $stateStore = $this->createMock(AuditStateStore::class);

        $publishedEvents = [];
        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(function (AuditEvent $event) use (&$publishedEvents): string {
            $publishedEvents[] = $event;
            return '1787932627594-0';
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $job1Id = AuditEvent::uuidV4();
        $job2Id = AuditEvent::uuidV4();

        $job1Events = [];
        $job2Events = [];
        for ($i = 1; $i <= 30; $i++) {
            $auditId1 = AuditEvent::uuidV4();
            $token1 = AuditEvent::uuidV4();
            $job1Events[] = [
                'event' => AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_CREATED,
                    auditId: $auditId1,
                    jobId: $job1Id,
                    payload: ['dis_det_nro' => "DET-J1-{$i}", 'fac_nit_sec' => '2426', 'dis_id' => "DIS-J1-{$i}"]
                ),
                'dis_id' => "DIS-J1-{$i}",
                'reservation_token' => $token1,
            ];

            $auditId2 = AuditEvent::uuidV4();
            $token2 = AuditEvent::uuidV4();
            $job2Events[] = [
                'event' => AuditEvent::create(
                    eventType: AuditEvent::TYPE_AUDIT_CREATED,
                    auditId: $auditId2,
                    jobId: $job2Id,
                    payload: ['dis_det_nro' => "DET-J2-{$i}", 'fac_nit_sec' => '2426', 'dis_id' => "DIS-J2-{$i}"]
                ),
                'dis_id' => "DIS-J2-{$i}",
                'reservation_token' => $token2,
            ];
        }

        $readyClients = [
            $job1Id => [
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA (Lote 1)',
                'job_id' => $job1Id,
                'prepared_events' => $job1Events,
                'total_enqueued' => 0,
                'total_target' => 30,
            ],
            $job2Id => [
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA (Lote 2)',
                'job_id' => $job2Id,
                'prepared_events' => $job2Events,
                'total_enqueued' => 0,
                'total_target' => 30,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $reflection = new \ReflectionClass($dispatcher);
        $method = $reflection->getMethod('interleaveAndPublishAuditEvents');
        $method->setAccessible(true);
        $method->invokeArgs($dispatcher, [&$readyClients, 10, null, &$summary]);

        $this->assertSame(60, $summary['total_invoices_queued']);
        $this->assertSame(0, $summary['errors']);
        $this->assertCount(60, $publishedEvents);

        $jobSequence = array_map(
            fn(AuditEvent $e) => $e->jobId,
            $publishedEvents
        );

        // Verifica entrelazado Round-Robin [J1: 10, J2: 10, J1: 10, J2: 10, J1: 10, J2: 10]
        $this->assertSame(array_fill(0, 10, $job1Id), array_slice($jobSequence, 0, 10));
        $this->assertSame(array_fill(0, 10, $job2Id), array_slice($jobSequence, 10, 10));
        $this->assertSame(array_fill(0, 10, $job1Id), array_slice($jobSequence, 20, 10));
        $this->assertSame(array_fill(0, 10, $job2Id), array_slice($jobSequence, 30, 10));
        $this->assertSame(array_fill(0, 10, $job1Id), array_slice($jobSequence, 40, 10));
        $this->assertSame(array_fill(0, 10, $job2Id), array_slice($jobSequence, 50, 10));
    }

    public function testDiscoversAndRecoversPendingAuditsFromSealedJobs(): void
    {
        $jobId = AuditEvent::uuidV4();
        $audit1Id = AuditEvent::uuidV4();
        $audit2Id = AuditEvent::uuidV4();
        $audit3Id = AuditEvent::uuidV4();
        $stableEventId = AuditEvent::uuidV4();

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => [$jobId]]);
        $jobStore->method('getJob')->with($jobId)->willReturn([
            'job_id' => $jobId,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'status' => BatchJobStore::JOB_STATUS_PROCESSING,
            'sealed' => true,
            'total' => 3,
            'done' => 1,
            'failed' => 0,
            'audits' => [
                // 1. Auditoría ya completada en negocio: no recuperar
                $audit1Id => [
                    'status' => 'completed',
                    'dis_det_nro' => 'DET-001',
                    'dis_id' => 'DIS-001',
                    'reservation_token' => 'TOK-001',
                    'publication_status' => 'published',
                ],
                // 2. Auditoría en pending pero ya publicada a Redis Streams (evitar duplicados tras reinicio): no recuperar
                $audit2Id => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-002',
                    'dis_id' => 'DIS-002',
                    'reservation_token' => 'TOK-002',
                    'publication_status' => 'published',
                    'published_at' => '2026-08-31T12:00:00Z',
                ],
                // 3. Auditoría genuinamente pendiente de publicación: recuperar con MISMO event_id
                $audit3Id => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-003',
                    'dis_id' => 'DIS-003',
                    'reservation_token' => 'TOK-003',
                    'event_id' => $stableEventId,
                    'publication_status' => 'pending',
                ],
            ],
        ]);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertArrayHasKey($jobId, $recovered);
        $this->assertSame(2426, $recovered[$jobId]['fac_nit_sec']);
        $this->assertCount(1, $recovered[$jobId]['prepared_events'], 'Solo debe recuperar la auditoría pendiente de publicación');

        $recoveredEvent = $recovered[$jobId]['prepared_events'][0]['event'];
        $this->assertSame($audit3Id, $recoveredEvent->auditId);
        $this->assertSame($jobId, $recoveredEvent->jobId);
        $this->assertSame($stableEventId, $recoveredEvent->eventId, 'Debe reutilizar el mismo event_id estable para deduplicación');
        $this->assertSame('DET-003', $recoveredEvent->payload['dis_det_nro']);
        $this->assertSame('DIS-003', $recoveredEvent->payload['dis_id']);
        $this->assertSame('TOK-003', $recoveredEvent->payload['reservation_token']);
    }

    public function testDiscoversAndRecoversPendingAuditsAcrossMultiplePages(): void
    {
        $jobStore = $this->createMock(BatchJobStore::class);

        $jobId1 = AuditEvent::uuidV4();
        $jobId2 = AuditEvent::uuidV4();
        $aud1Id = AuditEvent::uuidV4();
        $aud2Id = AuditEvent::uuidV4();

        // Página 1: retorna 50 job IDs
        $page1 = [];
        for ($i = 1; $i <= 50; $i++) {
            $page1[] = ($i === 1 ? $jobId1 : AuditEvent::uuidV4());
        }
        // Página 2: retorna 10 job IDs
        $page2 = [$jobId2];

        $jobStore->method('listJobIds')->willReturnCallback(function (int $limit, string $cursor) use ($page1, $page2) {
            if ($cursor === '0') {
                return ['cursor' => 'next-cursor-1', 'job_ids' => $page1];
            }
            if ($cursor === 'next-cursor-1') {
                return ['cursor' => '0', 'job_ids' => $page2];
            }
            return ['cursor' => '0', 'job_ids' => []];
        });

        $jobStore->method('getJob')->willReturnCallback(function (string $jobId) use ($jobId1, $jobId2, $aud1Id, $aud2Id) {
            if ($jobId === $jobId1) {
                return [
                    'job_id' => $jobId1,
                    'fac_nit_sec' => 1001,
                    'status' => 'pending',
                    'sealed' => true,
                    'audits' => [
                        $aud1Id => ['status' => 'pending', 'publication_status' => 'pending', 'dis_det_nro' => 'D1', 'dis_id' => 'I1'],
                    ],
                ];
            }
            if ($jobId === $jobId2) {
                return [
                    'job_id' => $jobId2,
                    'fac_nit_sec' => 1002,
                    'status' => 'pending',
                    'sealed' => true,
                    'audits' => [
                        $aud2Id => ['status' => 'pending', 'publication_status' => 'pending', 'dis_det_nro' => 'D2', 'dis_id' => 'I2'],
                    ],
                ];
            }
            return null;
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertCount(2, $recovered, 'Debe descubrir jobs pendientes a través de múltiples páginas');
        $this->assertArrayHasKey($jobId1, $recovered);
        $this->assertArrayHasKey($jobId2, $recovered);
    }

    public function testDiscoversAndRecoversPendingAuditsWithHolesInFirstPage(): void
    {
        $jobStore = $this->createMock(BatchJobStore::class);

        $jobId1 = AuditEvent::uuidV4();
        $jobId2 = AuditEvent::uuidV4();
        $aud1Id = AuditEvent::uuidV4();
        $aud2Id = AuditEvent::uuidV4();

        // Página 1: retorna 50 IDs examinados del ZSET, pero 40 son huecos (jobs eliminados o expirados)
        $page1 = [$jobId1];
        for ($i = 2; $i <= 50; $i++) {
            $page1[] = AuditEvent::uuidV4(); // huecos en Redis
        }

        // Página 2: retorna 15 IDs del ZSET con jobId2
        $page2 = [$jobId2];
        for ($i = 2; $i <= 15; $i++) {
            $page2[] = AuditEvent::uuidV4();
        }

        $jobStore->method('listJobIds')->willReturnCallback(function (int $limit, string $cursor) use ($page1, $page2) {
            if ($cursor === '0') {
                return ['cursor' => 'next-cursor-1', 'job_ids' => $page1];
            }
            if ($cursor === 'next-cursor-1') {
                return ['cursor' => '0', 'job_ids' => $page2];
            }
            return ['cursor' => '0', 'job_ids' => []];
        });

        $jobStore->method('getJob')->willReturnCallback(function (string $jobId) use ($jobId1, $jobId2, $aud1Id, $aud2Id) {
            if ($jobId === $jobId1) {
                return [
                    'job_id' => $jobId1,
                    'fac_nit_sec' => 2426,
                    'status' => 'pending',
                    'sealed' => true,
                    'audits' => [
                        $aud1Id => ['status' => 'pending', 'publication_status' => 'pending', 'dis_det_nro' => 'D1', 'dis_id' => 'I1'],
                    ],
                ];
            }
            if ($jobId === $jobId2) {
                return [
                    'job_id' => $jobId2,
                    'fac_nit_sec' => 2624,
                    'status' => 'pending',
                    'sealed' => true,
                    'audits' => [
                        $aud2Id => ['status' => 'pending', 'publication_status' => 'pending', 'dis_det_nro' => 'D2', 'dis_id' => 'I2'],
                    ],
                ];
            }
            // Hueco/inexistente en Redis
            return null;
        });

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertCount(2, $recovered, 'Debe superar los huecos de la primera página y recuperar jobs de páginas subsiguientes');
        $this->assertArrayHasKey($jobId1, $recovered);
        $this->assertArrayHasKey($jobId2, $recovered);
    }

    public function testDiscoversAndRecoversUsesAuditIdFallbackWhenStableEventIdMissing(): void
    {
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();

        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => [$jobId]]);
        $jobStore->method('getJob')->with($jobId)->willReturn([
            'job_id' => $jobId,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'status' => 'pending',
            'sealed' => true,
            'audits' => [
                $auditId => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-FALLBACK',
                    'dis_id' => 'DIS-FALLBACK',
                    'reservation_token' => 'TOK-FALLBACK',
                    'publication_status' => 'pending',
                    // Sin event_id explícito (job heredado)
                ],
            ],
        ]);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertArrayHasKey($jobId, $recovered);
        $recoveredEvent = $recovered[$jobId]['prepared_events'][0]['event'];
        $this->assertSame($auditId, $recoveredEvent->eventId, 'Debe usar auditId como fallback determinístico cuando event_id falta');
    }

    public function testDiscoversAndRecoversPendingAuditsWhenFirstPageIsEmptyWithNonZeroCursor(): void
    {
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();

        // Página 1: retorna cursor='12' pero job_ids=[] (ej: Redis escaneó buckets vacíos o filtró huérfanos)
        // Página 2: retorna cursor='0' y job_ids=[$jobId]
        $jobStore->method('listJobIds')->willReturnCallback(function (int $limit, string $cursor) use ($jobId) {
            if ($cursor === '0') {
                return ['cursor' => '12', 'job_ids' => []];
            }
            if ($cursor === '12') {
                return ['cursor' => '0', 'job_ids' => [$jobId]];
            }
            return ['cursor' => '0', 'job_ids' => []];
        });

        $jobStore->method('getJob')->with($jobId)->willReturn([
            'job_id' => $jobId,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'status' => 'pending',
            'sealed' => true,
            'audits' => [
                $auditId => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-NONZERO',
                    'dis_id' => 'DIS-NONZERO',
                    'reservation_token' => 'TOK-NONZERO',
                    'publication_status' => 'pending',
                ],
            ],
        ]);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertCount(1, $recovered, 'Debe continuar la paginación a través de páginas vacías con cursor no cero');
        $this->assertArrayHasKey($jobId, $recovered);
    }

    public function testSuccessfulPublishingMarksAuditPublishedInJobStore(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2624, 'NitCom' => 'NUEVA EPS S.A.'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DISID-1', 'Dispensa' => 'DISP-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        // Debe llamar markAuditPublishedInJob tras publicación exitosa (QUAL-004)
        $jobStore->expects($this->once())->method('markAuditPublishedInJob')
            ->with($this->anything(), $this->anything(), 'stream-msg-id-101')
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturn('stream-msg-id-101');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 1);

        $this->assertSame(1, $result['total_invoices_queued']);
        $this->assertSame(0, $result['errors']);
    }

    public function testFailedReconciliationPreservesStateAndReservation(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $configModel = $this->createMock(AuditConfigModel::class);
        $invoicesModel = $this->createMock(InvoicesModel::class);

        $jobStore = $this->createMock(BatchJobStore::class);
        // markAuditCompletedInJob retorna false (reconciliación fallida)
        $jobStore->method('markAuditCompletedInJob')->willReturn(false);
        // NUNCA debe liberar la reserva si la reconciliación falló (QUAL-004)
        $jobStore->expects($this->never())->method('releaseAuditReservation');

        $stateStore = $this->createMock(AuditStateStore::class);
        // NUNCA debe borrar el estado si la reconciliación falló (QUAL-004)
        $stateStore->expects($this->never())->method('deleteAudit');

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willThrowException(new RuntimeException('Publish failed'));

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $readyClients = [
            $jobId => [
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'job_id' => $jobId,
                'prepared_events' => [
                    [
                        'event' => AuditEvent::create(
                            eventType: AuditEvent::TYPE_AUDIT_CREATED,
                            auditId: $auditId,
                            jobId: $jobId,
                            payload: ['dis_det_nro' => 'DET-1', 'fac_nit_sec' => '2426', 'dis_id' => 'DIS-1']
                        ),
                        'dis_id' => 'DIS-1',
                        'reservation_token' => 'TOK-1',
                    ],
                ],
                'total_enqueued' => 0,
                'total_target' => 1,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $reflection = new \ReflectionClass($dispatcher);
        $method = $reflection->getMethod('interleaveAndPublishAuditEvents');
        $method->setAccessible(true);
        $method->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        $this->assertSame(1, $summary['errors']);
        $this->assertSame(0, $summary['total_invoices_queued']);
        $this->assertCount(1, $readyClients[$jobId]['prepared_events'], 'El evento no reconciliado debe preservarse en cola');
    }

    public function testBatchOrchestratorJobIsRecognizedAsPublishedAndNotDuplicatedByDispatcher(): void
    {
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobId = AuditEvent::uuidV4();
        $auditIdPublished = AuditEvent::uuidV4();
        $auditIdPending = AuditEvent::uuidV4();
        $stableEventId = AuditEvent::uuidV4();

        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => [$jobId]]);
        $jobStore->method('getJob')->with($jobId)->willReturn([
            'job_id' => $jobId,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'status' => 'pending',
            'sealed' => true,
            'audits' => [
                // Auditoría ya publicada por AuditBatchOrchestrator
                $auditIdPublished => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-PUB',
                    'dis_id' => 'DIS-PUB',
                    'reservation_token' => 'TOK-PUB',
                    'publication_status' => 'published',
                    'published_at' => '2026-08-31T12:00:00.000Z',
                    'stream_id' => '1700000000000-0',
                    'event_id' => AuditEvent::uuidV4(),
                ],
                // Auditoría pendiente tras crash antes de publicar
                $auditIdPending => [
                    'status' => 'pending',
                    'dis_det_nro' => 'DET-PEND',
                    'dis_id' => 'DIS-PEND',
                    'reservation_token' => 'TOK-PEND',
                    'publication_status' => 'pending',
                    'event_id' => $stableEventId,
                ],
            ],
        ]);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $this->createMock(AuditStateStore::class),
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertArrayHasKey($jobId, $recovered);
        $this->assertCount(1, $recovered[$jobId]['prepared_events'], 'Solo debe recuperar la auditoría pendiente');
        $recoveredEvent = $recovered[$jobId]['prepared_events'][0]['event'];
        $this->assertSame($stableEventId, $recoveredEvent->eventId, 'Debe preservar el event_id estable generado por AuditBatchOrchestrator');
        $this->assertSame($auditIdPending, $recoveredEvent->auditId);
    }

    public function testProgressCallbackReceivesAllStructuredPhasesAndPayloads(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑÍA DE SEGUROS'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-1', 'Dispensa' => 'DET-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);
        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => []]);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturn('stream-1');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $recordedPhases = [];
        $recordedPayloads = [];
        $callback = function (string $phase, array $data) use (&$recordedPhases, &$recordedPayloads): void {
            $recordedPhases[] = $phase;
            $recordedPayloads[$phase] = $data;
        };

        $summary = $dispatcher->dispatch('2026-08-01', '2026-08-28', 10, 5, false, $callback);

        $this->assertSame(1, $summary['queued_clients']);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_RECOVERY_STARTED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_DISCOVERY_STARTED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_CLIENT_DISCOVERED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_PREPARATION_STARTED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_CLIENT_PREPARED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_PUBLISHING_STARTED, $recordedPhases);
        $this->assertContains(MultiClientBatchDispatcher::PHASE_CHUNK_PUBLISHED, $recordedPhases);

        $chunkData = $recordedPayloads[MultiClientBatchDispatcher::PHASE_CHUNK_PUBLISHED];
        $this->assertSame('2426', $chunkData['fac_nit_sec']);
        $this->assertSame(1, $chunkData['chunk_size']);
        $this->assertSame(0, $chunkData['remaining']);
    }

    public function testIsolatedRollbackExecutesAllStepsAndRetainsIdempotencyOnPartialFailure(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-1', 'Dispensa' => 'DET-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        // Fallo al sellar el job
        $jobStore->method('sealJob')->willThrowException(new RuntimeException('Redis seal failure'));

        // deleteJob y releaseAuditReservation DEBEN ejecutarse a pesar de fallos en deleteAudit
        $jobStore->expects($this->once())->method('deleteJob');
        $jobStore->expects($this->once())->method('releaseAuditReservation');
        // releaseIdempotencyKey NO debe ser llamada porque el rollback fue incompleto
        $jobStore->expects($this->never())->method('releaseIdempotencyKey');

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);
        // Simular que deleteAudit falla
        $stateStore->method('deleteAudit')->willThrowException(new RuntimeException('Redis audit delete failure'));

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $this->createMock(AuditEventPublisher::class)
        );

        $summary = $dispatcher->dispatch('2026-08-01', '2026-08-28', 10, 5, false);

        $this->assertSame(1, $summary['errors']);
        $this->assertSame('error_sealing', $summary['clients'][0]['status']);
    }

    public function testIsolatedRollbackRetainsIdempotencyWhenCleanupReturnsFalse(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-1', 'Dispensa' => 'DET-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willThrowException(new RuntimeException('Redis seal failure'));

        // deleteJob retorna false (Redis indisponible/error silencioso)
        $jobStore->method('deleteJob')->willReturn(false);
        $jobStore->method('releaseAuditReservation')->willReturn(true);
        // releaseIdempotencyKey NO debe ser llamada
        $jobStore->expects($this->never())->method('releaseIdempotencyKey');

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);
        $stateStore->method('deleteAudit')->willReturn(true);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $this->createMock(AuditEventPublisher::class)
        );

        $summary = $dispatcher->dispatch('2026-08-01', '2026-08-28', 10, 5, false);

        $this->assertSame(1, $summary['errors']);
        $this->assertSame('error_sealing', $summary['clients'][0]['status']);
    }

    public function testPartialRollbackRetainsTrackingWhenDeleteAuditOrReleaseReservationReturnsFalse(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-1', 'Dispensa' => 'DET-1', 'DisFecSol' => '2026-08-28T10:00:00Z'],
            ['DisId' => 'DIS-2', 'Dispensa' => 'DET-2', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        // releaseAuditReservation returns false on local failure
        $jobStore->method('releaseAuditReservation')->willReturn(false);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('sealJob')->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        // First invoice initAudit fails
        $stateStore->method('initAudit')->willReturnCallback(function ($auditId, $disDetNro) {
            return $disDetNro === 'DET-2';
        });
        $stateStore->method('patchAudit')->willReturn(true);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $this->createMock(AuditEventPublisher::class)
        );

        $summary = $dispatcher->dispatch('2026-08-01', '2026-08-28', 10, 5, false);

        $this->assertSame(1, $summary['total_invoices_queued'], 'Solo debe aceptar y encolar la segunda factura exitosa');
        $this->assertSame(1, $summary['clients'][0]['total_enqueued']);
    }

    public function testFailedReconciliationPreservesEventWhenDeleteAuditOrReleaseReservationReturnsFalse(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $eventId = AuditEvent::uuidV4();

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            documentId: null,
            payload: ['dis_id' => 'DIS-FAIL', 'dis_det_nro' => 'DET-FAIL', 'reservation_token' => 'TOK-FAIL'],
            eventId: $eventId
        );

        $publisher = $this->createMock(AuditEventPublisher::class);
        // Fallo en la publicación para provocar reconciliación
        $publisher->method('publish')->willThrowException(new RuntimeException('Redis connection dropped'));

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('markAuditCompletedInJob')->willReturn(true);
        // releaseAuditReservation devuelve false durante la reconciliación
        $jobStore->method('releaseAuditReservation')->willReturn(false);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('deleteAudit')->willReturn(true);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $publisher
        );

        $readyClients = [
            $jobId => [
                'job_id' => $jobId,
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'prepared_events' => [
                    [
                        'event' => $event,
                        'dis_id' => 'DIS-FAIL',
                        'reservation_token' => 'TOK-FAIL',
                        'audit_id' => $auditId,
                    ],
                ],
                'total_enqueued' => 0,
                'total_accepted' => 1,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $refMethod = new \ReflectionMethod($dispatcher, 'interleaveAndPublishAuditEvents');
        $refMethod->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        $this->assertCount(1, $readyClients[$jobId]['prepared_events'], 'El evento no debe retirarse de la cola si la compensación devolvió false');
        $this->assertSame(1, $summary['errors']);
    }

    public function testMarkAuditPublishedInJobReturningFalseIsLoggedAndStillEnqueued(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $eventId = AuditEvent::uuidV4();

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            documentId: null,
            payload: ['dis_id' => 'DIS-1', 'dis_det_nro' => 'DET-1', 'reservation_token' => 'TOK-1'],
            eventId: $eventId
        );

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturn('stream-id-12345');

        $jobStore = $this->createMock(BatchJobStore::class);
        // markAuditPublishedInJob devuelve false (fallo en durable state)
        $jobStore->method('markAuditPublishedInJob')->willReturn(false);

        $stateStore = $this->createMock(AuditStateStore::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $publisher
        );

        $readyClients = [
            $jobId => [
                'job_id' => $jobId,
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'prepared_events' => [
                    [
                        'event' => $event,
                        'dis_id' => 'DIS-1',
                        'reservation_token' => 'TOK-1',
                        'audit_id' => $auditId,
                    ],
                ],
                'total_enqueued' => 0,
                'total_accepted' => 1,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $refMethod = new \ReflectionMethod($dispatcher, 'interleaveAndPublishAuditEvents');
        $refMethod->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        $this->assertSame(1, $summary['total_invoices_queued']);
        $this->assertCount(0, $readyClients[$jobId]['prepared_events']);
    }

    public function testAtomicReconciliationSucceedsInOneStepAndRemovesEventFromQueue(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $eventId = AuditEvent::uuidV4();

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            documentId: null,
            payload: ['dis_id' => 'DIS-ATOMIC', 'dis_det_nro' => 'DET-ATOMIC', 'reservation_token' => 'TOK-ATOMIC'],
            eventId: $eventId
        );

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willThrowException(new RuntimeException('Network down'));

        $jobStore = $this->createMock(BatchJobStore::class);
        // Transición atómica exitosa (QUAL-015)
        $jobStore->expects($this->once())
            ->method('reconcileFailedAuditInJob')
            ->with($jobId, $auditId, 'DIS-ATOMIC', 'TOK-ATOMIC', 'MultiClientBatchDispatcher::publisher_failed')
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $publisher
        );

        $readyClients = [
            $jobId => [
                'job_id' => $jobId,
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'prepared_events' => [
                    [
                        'event' => $event,
                        'dis_id' => 'DIS-ATOMIC',
                        'reservation_token' => 'TOK-ATOMIC',
                        'audit_id' => $auditId,
                    ],
                ],
                'total_enqueued' => 0,
                'total_accepted' => 1,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $refMethod = new \ReflectionMethod($dispatcher, 'interleaveAndPublishAuditEvents');
        $refMethod->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        $this->assertCount(0, $readyClients[$jobId]['prepared_events'], 'El evento debe retirarse tras reconciliación atómica');
        $this->assertSame(1, $summary['errors']);
    }

    public function testSecondDispatcherInstanceRecoversAndReconcilesPendingCompensationsFromPriorCrash(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditCrashId = AuditEvent::uuidV4();
        $auditValidId = AuditEvent::uuidV4();

        $jobState = [
            'job_id' => $jobId,
            'sealed' => true,
            'status' => BatchJobStore::JOB_STATUS_PROCESSING,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'done' => 0,
            'failed' => 0,
            'audits' => [
                $auditCrashId => [
                    'dis_det_nro' => 'DET-CRASH',
                    'dis_id' => 'DIS-CRASH',
                    'reservation_token' => 'TOK-CRASH',
                    'status' => 'compensation_pending',
                    'compensation_pending' => true,
                    'compensation_dis_id' => 'DIS-CRASH',
                    'compensation_token' => 'TOK-CRASH',
                    'publication_status' => 'pending',
                ],
                $auditValidId => [
                    'dis_det_nro' => 'DET-VALID',
                    'dis_id' => 'DIS-VALID',
                    'reservation_token' => 'TOK-VALID',
                    'status' => 'pending',
                    'publication_status' => 'pending',
                ],
            ],
        ];

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => [$jobId]]);
        $jobStore->method('getJob')->with($jobId)->willReturn($jobState);

        // La segunda instancia debe intentar reconciliar la deuda de compensación
        $jobStore->expects($this->once())
            ->method('reconcileFailedAuditInJob')
            ->with($jobId, $auditCrashId, 'DIS-CRASH', 'TOK-CRASH', 'MultiClientBatchDispatcher::recovered_compensation')
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);

        // Instancia 2 del dispatcher
        $dispatcher2 = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher2->discoverPendingUnpublishedBatches();

        $this->assertArrayHasKey($jobId, $recovered);
        $this->assertCount(1, $recovered[$jobId]['prepared_events'], 'Solo debe recuperar el evento válido, no la deuda de compensación');
        $this->assertSame('DIS-VALID', $recovered[$jobId]['prepared_events'][0]['dis_id']);
    }

    public function testReconciliationFailurePersistsCompensationPendingInJob(): void
    {
        $jobId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $eventId = AuditEvent::uuidV4();

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: $auditId,
            jobId: $jobId,
            documentId: null,
            payload: ['dis_id' => 'DIS-RETRY', 'dis_det_nro' => 'DET-RETRY', 'reservation_token' => 'TOK-RETRY'],
            eventId: $eventId
        );

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willThrowException(new RuntimeException('Network drop'));

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('reconcileFailedAuditInJob')->willReturn(false);
        $jobStore->method('markAuditCompletedInJob')->willReturn(true);
        // releaseAuditReservation falla para simular compensación incompleta
        $jobStore->method('releaseAuditReservation')->willReturn(false);

        // Debe persistir compensation_pending en el job vía patchAuditInJob (QUAL-015)
        $jobStore->expects($this->once())
            ->method('patchAuditInJob')
            ->with(
                $jobId,
                $auditId,
                $this->callback(fn($patch) => ($patch['compensation_pending'] ?? false) === true && ($patch['compensation_dis_id'] ?? '') === 'DIS-RETRY')
            )
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('deleteAudit')->willReturn(true);

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $publisher
        );

        $readyClients = [
            $jobId => [
                'job_id' => $jobId,
                'fac_nit_sec' => 2426,
                'client_name' => 'POSITIVA',
                'prepared_events' => [
                    [
                        'event' => $event,
                        'dis_id' => 'DIS-RETRY',
                        'reservation_token' => 'TOK-RETRY',
                        'audit_id' => $auditId,
                    ],
                ],
                'total_enqueued' => 0,
                'total_accepted' => 1,
            ],
        ];

        $summary = [
            'total_invoices_queued' => 0,
            'errors' => 0,
        ];

        $refMethod = new \ReflectionMethod($dispatcher, 'interleaveAndPublishAuditEvents');
        $refMethod->invokeArgs($dispatcher, [&$readyClients, 5, null, &$summary]);

        $this->assertCount(1, $readyClients[$jobId]['prepared_events'], 'El evento debe conservarse en memoria para no perder trazabilidad');
        $this->assertSame(1, $summary['errors']);
    }

    public function testHealthyBatchSealsWithoutCompensationPendingMetadata(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑÍA DE SEGUROS'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-101', 'Dispensa' => 'DET-101', 'DisFecSol' => '2026-08-28T10:00:00Z'],
            ['DisId' => 'DIS-102', 'Dispensa' => 'DET-102', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $capturedMetadata = null;
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('markAuditPublishedInJob')->willReturn(true);
        $jobStore->expects($this->once())
            ->method('sealJob')
            ->willReturnCallback(function (string $jobId, int $total, array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
                return true;
            });

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        $stateStore->method('patchAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturn('1787932627594-0');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 10, chunkSize: 5);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(2, $result['total_invoices_queued']);
        $this->assertNotNull($capturedMetadata);
        $this->assertIsArray($capturedMetadata);
        $this->assertSame(2, $capturedMetadata['accepted']);
        $this->assertArrayNotHasKey('compensation_pending', $capturedMetadata, 'Un batch sano NUNCA debe sellarse con compensation_pending=true');
        $this->assertArrayNotHasKey('pending_audit_ids', $capturedMetadata, 'Un batch sano NUNCA debe persistir pending_audit_ids');
        $this->assertArrayNotHasKey('pending_reservations', $capturedMetadata, 'Un batch sano NUNCA debe persistir pending_reservations');
    }

    public function testCrashAfterSealingHealthyBatchRecoversAllAuditsIntactWithoutDeletion(): void
    {
        $jobId = '00000000-0000-4000-8000-000000000099';
        $auditId1 = '00000000-0000-4000-8000-000000000001';
        $auditId2 = '00000000-0000-4000-8000-000000000002';

        $healthyJob = [
            'job_id' => $jobId,
            'sealed' => true,
            'status' => BatchJobStore::JOB_STATUS_PENDING,
            'fac_nit_sec' => 2426,
            'client_name' => 'POSITIVA',
            'done' => 0,
            'failed' => 0,
            'total' => 2,
            'audits' => [
                $auditId1 => [
                    'status' => 'pending',
                    'dis_id' => 'DIS-HEALTHY-1',
                    'reservation_token' => '00000000-0000-4000-8000-000000000011',
                    'dis_det_nro' => 'DET-1',
                    'event_id' => '00000000-0000-4000-8000-000000000021',
                    'publication_status' => 'pending',
                ],
                $auditId2 => [
                    'status' => 'pending',
                    'dis_id' => 'DIS-HEALTHY-2',
                    'reservation_token' => '00000000-0000-4000-8000-000000000012',
                    'dis_det_nro' => 'DET-2',
                    'event_id' => '00000000-0000-4000-8000-000000000022',
                    'publication_status' => 'pending',
                ],
            ],
        ];

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('listJobIds')->willReturn(['cursor' => '0', 'job_ids' => [$jobId]]);
        $jobStore->method('getJob')->with($jobId)->willReturn($healthyJob);
        // Invariante crítico: NUNCA debe liberar reservas de auditorías válidas en un lote sano
        $jobStore->expects($this->never())->method('releaseAuditReservation');
        $jobStore->expects($this->never())->method('reconcileFailedAuditInJob');

        $stateStore = $this->createMock(AuditStateStore::class);
        // Invariante crítico: NUNCA debe eliminar auditorías válidas en un lote sano
        $stateStore->expects($this->never())->method('deleteAudit');

        $dispatcher = new MultiClientBatchDispatcher(
            $this->createMock(ClientsModel::class),
            $this->createMock(AuditConfigModel::class),
            $this->createMock(InvoicesModel::class),
            $jobStore,
            $stateStore,
            $this->createMock(AuditEventPublisher::class)
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        $this->assertArrayHasKey($jobId, $recovered);
        $this->assertCount(2, $recovered[$jobId]['prepared_events'], 'Todas las auditorías válidas deben recuperarse intactas');
        $this->assertSame($auditId1, $recovered[$jobId]['prepared_events'][0]['event']->auditId);
        $this->assertSame($auditId2, $recovered[$jobId]['prepared_events'][1]['event']->auditId);
    }

    public function testBatchWithFailedCompensationSealsWithOnlyUncompensatedResources(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'POSITIVA COMPAÑÍA DE SEGUROS'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-OK', 'Dispensa' => 'DET-OK', 'DisFecSol' => '2026-08-28T10:00:00Z'],
            ['DisId' => 'DIS-FAIL', 'Dispensa' => 'DET-FAIL', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $capturedMetadata = null;
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('markAuditPublishedInJob')->willReturn(true);
        // Si la compensación de DIS-FAIL falla al liberar la reserva:
        $jobStore->method('releaseAuditReservation')->willReturnCallback(function (string $disId) {
            if ($disId === 'DIS-FAIL') {
                return false; // Falla de compensación
            }
            return true;
        });

        $jobStore->expects($this->once())
            ->method('sealJob')
            ->willReturnCallback(function (string $jobId, int $total, array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
                return true;
            });

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturnCallback(function (string $auditId, string $disDetNro, string $jobId, string $facNitSec, string $disId) {
            if ($disId === 'DIS-FAIL') {
                return false; // Falla de enrolamiento
            }
            return true;
        });
        $stateStore->method('patchAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);
        $publisher->method('publish')->willReturn('1787932627594-0');

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $result = $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 10, chunkSize: 5);

        $this->assertSame(1, $result['queued_clients']);
        $this->assertSame(1, $result['total_invoices_queued']); // Solo DIS-OK
        $this->assertNotNull($capturedMetadata);
        $this->assertIsArray($capturedMetadata);
        $this->assertTrue($capturedMetadata['compensation_pending'] ?? false);
        $this->assertCount(1, $capturedMetadata['pending_reservations']);
        $this->assertSame('DIS-FAIL', $capturedMetadata['pending_reservations'][0]['dis_id']);
        $this->assertEmpty($capturedMetadata['pending_audit_ids'], 'No debe haber pending_audit_ids si initAudit retornó false');
    }

    public function testEnrollmentCompensationOnlyTracksReservationWhenAuditDeleteSucceeds(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'Cliente Test 1'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-FAIL-RES', 'Dispensa' => 'DET-FAIL-RES', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $capturedMetadata = null;
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('markAuditPublishedInJob')->willReturn(true);
        // La liberación de reserva falla:
        $jobStore->method('releaseAuditReservation')->willReturn(false);

        $jobStore->expects($this->once())
            ->method('sealJob')
            ->willReturnCallback(function (string $jobId, int $total, array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
                return true;
            });

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        // patchAudit falla disparando compensación con hasAuditCreated=true:
        $stateStore->method('patchAudit')->willReturn(false);
        // deleteAudit tiene éxito:
        $stateStore->expects($this->once())->method('deleteAudit')->willReturn(true);

        $publisher = $this->createMock(AuditEventPublisher::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 10, chunkSize: 5);

        $this->assertNotNull($capturedMetadata);
        $this->assertIsArray($capturedMetadata);
        $this->assertTrue($capturedMetadata['compensation_pending'] ?? false);
        $this->assertCount(1, $capturedMetadata['pending_reservations'], 'Debe registrar la reserva no liberada');
        $this->assertSame('DIS-FAIL-RES', $capturedMetadata['pending_reservations'][0]['dis_id']);
        $this->assertEmpty($capturedMetadata['pending_audit_ids'], 'pending_audit_ids debe estar vacío si deleteAudit tuvo éxito (QUAL-015)');
    }

    public function testEnrollmentCompensationOnlyTracksAuditWhenReservationReleaseSucceeds(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $clientsModel->method('getAllClients')->willReturn([
            ['NitSec' => 2426, 'NitCom' => 'Cliente Test 1'],
        ]);

        $configModel = $this->createMock(AuditConfigModel::class);
        $configModel->method('getConfig')->willReturn([
            'activo' => true,
            'documents' => ['FORMULA' => true],
        ]);

        $invoicesModel = $this->createMock(InvoicesModel::class);
        $invoicesModel->method('getInvoicesForAuditBatch')->willReturn([
            ['DisId' => 'DIS-FAIL-AUD', 'Dispensa' => 'DET-FAIL-AUD', 'DisFecSol' => '2026-08-28T10:00:00Z'],
        ]);

        $capturedMetadata = null;
        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('claimIdempotencyKey')->willReturn(null);
        $jobStore->method('initJob')->willReturn(true);
        $jobStore->method('claimAuditReservation')->willReturn(true);
        $jobStore->method('registerAuditInJob')->willReturn(true);
        $jobStore->method('markAuditPublishedInJob')->willReturn(true);
        // La liberación de reserva tiene éxito:
        $jobStore->expects($this->once())->method('releaseAuditReservation')->willReturn(true);

        $jobStore->expects($this->once())
            ->method('sealJob')
            ->willReturnCallback(function (string $jobId, int $total, array $metadata) use (&$capturedMetadata) {
                $capturedMetadata = $metadata;
                return true;
            });

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->method('initAudit')->willReturn(true);
        // patchAudit falla disparando compensación con hasAuditCreated=true:
        $stateStore->method('patchAudit')->willReturn(false);
        // deleteAudit falla (ej: excepción):
        $stateStore->method('deleteAudit')->willThrowException(new \RuntimeException('Redis timeout'));

        $publisher = $this->createMock(AuditEventPublisher::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $dispatcher->dispatch('2026-08-01', '2026-08-28', limit: 10, chunkSize: 5);

        $this->assertNotNull($capturedMetadata);
        $this->assertIsArray($capturedMetadata);
        $this->assertTrue($capturedMetadata['compensation_pending'] ?? false);
        $this->assertEmpty($capturedMetadata['pending_reservations'], 'pending_reservations debe estar vacío si releaseAuditReservation tuvo éxito');
        $this->assertCount(1, $capturedMetadata['pending_audit_ids'], 'Debe registrar el audit_id no compensado (QUAL-015)');
    }

    public function testReconcilePendingCompensationsRunsOnTerminalCompletedJob(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $configModel = $this->createMock(AuditConfigModel::class);
        $invoicesModel = $this->createMock(InvoicesModel::class);
        $publisher = $this->createMock(AuditEventPublisher::class);

        $terminalJob = [
            'job_id' => 'job-term-1',
            'fac_nit_sec' => 900123,
            'client_name' => 'Cliente Terminal',
            'status' => BatchJobStore::JOB_STATUS_COMPLETED,
            'sealed' => true,
            'compensation_pending' => true,
            'pending_audit_ids' => ['audit-term-debt'],
            'pending_reservations' => [['dis_id' => 'DIS-TERM', 'token' => 'TOK-TERM']],
            'audits' => [],
        ];

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('listJobIds')->willReturn([
            'cursor' => '0',
            'job_ids' => ['job-term-1'],
        ]);
        $jobStore->method('getJob')->with('job-term-1')->willReturn($terminalJob);

        $jobStore->expects($this->once())
            ->method('releaseAuditReservation')
            ->with('DIS-TERM', 'TOK-TERM')
            ->willReturn(true);

        $jobStore->expects($this->once())
            ->method('patchJob')
            ->with('job-term-1', [
                'compensation_pending' => false,
                'pending_audit_ids' => [],
                'pending_reservations' => [],
            ])
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);
        $stateStore->expects($this->once())
            ->method('deleteAudit')
            ->with('audit-term-debt')
            ->willReturn(true);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();

        // El job terminal no debe ser retornado para publicación porque status == completed
        $this->assertArrayNotHasKey('job-term-1', $recovered);
    }

    public function testReconcileIndividualAuditCompensationClearsPendingDebt(): void
    {
        $clientsModel = $this->createMock(ClientsModel::class);
        $configModel = $this->createMock(AuditConfigModel::class);
        $invoicesModel = $this->createMock(InvoicesModel::class);
        $publisher = $this->createMock(AuditEventPublisher::class);

        $jobWithAuditDebt = [
            'job_id' => 'job-audit-debt-1',
            'fac_nit_sec' => 900123,
            'client_name' => 'Cliente Deuda Individual',
            'status' => BatchJobStore::JOB_STATUS_PROCESSING,
            'sealed' => true,
            'compensation_pending' => false,
            'audits' => [
                'audit-ind-1' => [
                    'status' => 'compensation_pending',
                    'compensation_pending' => true,
                    'compensation_dis_id' => 'DIS-IND-1',
                    'compensation_token' => 'TOK-IND-1',
                    'dis_det_nro' => 'DET-IND-1',
                    'dis_id' => 'DIS-IND-1',
                    'reservation_token' => 'TOK-IND-1',
                ],
            ],
        ];

        $jobStore = $this->createMock(BatchJobStore::class);
        $jobStore->method('listJobIds')->willReturn([
            'cursor' => '0',
            'job_ids' => ['job-audit-debt-1'],
        ]);
        $jobStore->method('getJob')->with('job-audit-debt-1')->willReturn($jobWithAuditDebt);

        // Debe llamar a reconcileFailedAuditInJob con los identificadores de la auditoría
        $jobStore->expects($this->once())
            ->method('reconcileFailedAuditInJob')
            ->with(
                'job-audit-debt-1',
                'audit-ind-1',
                'DIS-IND-1',
                'TOK-IND-1',
                'MultiClientBatchDispatcher::recovered_compensation'
            )
            ->willReturn(true);

        $stateStore = $this->createMock(AuditStateStore::class);

        $dispatcher = new MultiClientBatchDispatcher(
            $clientsModel,
            $configModel,
            $invoicesModel,
            $jobStore,
            $stateStore,
            $publisher
        );

        $recovered = $dispatcher->discoverPendingUnpublishedBatches();
        $this->assertArrayNotHasKey('job-audit-debt-1', $recovered, 'Auditoría con deuda no debe ser publicada');
    }
}
