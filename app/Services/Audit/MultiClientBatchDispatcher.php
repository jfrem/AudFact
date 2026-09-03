<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditConfigModel;
use App\Models\ClientsModel;
use App\Models\InvoicesModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Despachador de ingesta equitativa (Fair Queuing) para lotes batch multi-cliente.
 *
 * Entrelaza la emisión de eventos de auditoría entre todos los clientes
 * activos mediante rondas Round-Robin por ventanas (chunks), garantizando:
 * 1. Paginación keyset continua en SQL Server sin truncamiento.
 * 2. Sellado previo del Job en estado pending y emisión de batch_created antes de audit_created.
 * 3. Transición atómica de métricas en Redis (jobs_queued -> jobs_running -> jobs_completed).
 * 4. Reconciliación terminal y liberación de reservas si falla la publicación a Redis Streams.
 * 5. Rollback exhaustivo de reservas y eliminación de estados huérfanos ante fallos parciales.
 */
final class MultiClientBatchDispatcher
{
    public const DEFAULT_CHUNK_SIZE = 20;
    public const DEFAULT_IDEMPOTENCY_TTL_SECONDS = 14400; // 4 horas
    public const PHASE_RECOVERY_STARTED = 'recovery_started';
    public const PHASE_RECOVERY_FOUND = 'recovery_found';
    public const PHASE_DISCOVERY_STARTED = 'discovery_started';
    public const PHASE_CLIENT_DISCOVERED = 'client_discovered';
    public const PHASE_PREPARATION_STARTED = 'preparation_started';
    public const PHASE_CLIENT_PREPARED = 'client_prepared';
    public const PHASE_PUBLISHING_STARTED = 'publishing_started';
    public const PHASE_CHUNK_PUBLISHED = 'chunk_published';
    private const SQL_PAGE_LIMIT = 1000;
    private const MAX_PUBLISH_RETRIES = 3;

    public function __construct(
        private readonly ClientsModel $clientsModel,
        private readonly AuditConfigModel $configModel,
        private readonly InvoicesModel $invoicesModel,
        private readonly BatchJobStore $jobStore,
        private readonly AuditStateStore $stateStore,
        private readonly AuditEventPublisher $publisher
    ) {}

    /**
     * Ejecuta el ciclo de despacho equitativo entre todos los clientes configurados.
     *
     * @param string $dateFrom Fecha inicial YYYY-MM-DD
     * @param string $dateTo Fecha final YYYY-MM-DD
     * @param int $limit Límite máximo de facturas por cliente (1..10000)
     * @param int $chunkSize Tamaño de la ventana Round-Robin (1..500)
     * @param bool $dryRun Si es true, simula el descubrimiento y cálculo sin encolar
     * @param (callable(string, array<string,mixed>):void)|null $progressCallback Callback opcional de progreso CLI
     * @return array{
     *     queued_clients: int,
     *     total_invoices_queued: int,
     *     skipped_no_config: int,
     *     skipped_duplicate: int,
     *     errors: int,
     *     clients: array<int, array{
     *         fac_nit_sec: int,
     *         client_name: string,
     *         job_id: string|null,
     *         status: string,
     *         total_enqueued: int,
     *         skipped_locked: int,
     *         error: string|null
     *     }>
     * }
     */
    public function dispatch(
        string $dateFrom,
        string $dateTo,
        int $limit = 3000,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $dryRun = false,
        ?callable $progressCallback = null
    ): array {
        $limit = max(1, min(10000, $limit));
        $chunkSize = max(1, min(500, $chunkSize));

        $summary = [
            'queued_clients' => 0,
            'total_invoices_queued' => 0,
            'skipped_no_config' => 0,
            'skipped_duplicate' => 0,
            'errors' => 0,
            'clients' => [],
        ];

        // 0. Recuperación durable de jobs sellados previos con auditorías pendientes (QUAL-004)
        $this->notifyProgress($progressCallback, self::PHASE_RECOVERY_STARTED, []);
        $recoveredJobs = $this->discoverPendingUnpublishedBatches($progressCallback, $summary);

        // 1. Descubrimiento, Validación de Configuración e Inicialización de Jobs
        $this->notifyProgress($progressCallback, self::PHASE_DISCOVERY_STARTED, []);
        $activeClients = $this->discoverAndInitializeClients(
            $dateFrom,
            $dateTo,
            $limit,
            $dryRun,
            $progressCallback,
            $summary
        );

        if ($dryRun) {
            return $summary;
        }

        // 2. Pre-cargar facturas con paginación keyset, registrar en JobStore y Sellar Jobs
        $this->notifyProgress($progressCallback, self::PHASE_PREPARATION_STARTED, []);
        $readyClients = $this->prepareAndSealClientBatches(
            $activeClients,
            $dateFrom,
            $dateTo,
            $limit,
            $progressCallback,
            $summary
        );

        // Unificar jobs nuevos y jobs recuperados durables por jobId
        $allReadyJobs = array_merge($recoveredJobs, $readyClients);

        if (empty($allReadyJobs)) {
            return $summary;
        }

        // 3. Entrelazado Round-Robin de eventos audit_created a Redis Streams
        $this->notifyProgress($progressCallback, self::PHASE_PUBLISHING_STARTED, []);
        $this->interleaveAndPublishAuditEvents(
            $allReadyJobs,
            $chunkSize,
            $progressCallback,
            $summary
        );

        return $summary;
    }

    /**
     * Descubre clientes elegibles, valida configuración, reclama idempotencia e inicializa el job en Redis.
     *
     * @param array<string, mixed> $summary
     * @return array<int, array{
     *     fac_nit_sec: int,
     *     client_name: string,
     *     job_id: string,
     *     idempotency_key: string,
     *     total_enqueued: int,
     *     skipped_locked: int,
     *     created_reservations: array<int, array{dis_id:string, token:string}>,
     *     created_audit_ids: array<string>,
     *     prepared_events: array<int, array{event: AuditEvent, dis_id: string, reservation_token: string}>
     * }>
     */
    private function discoverAndInitializeClients(
        string $dateFrom,
        string $dateTo,
        int $limit,
        bool $dryRun,
        ?callable $progressCallback,
        array &$summary
    ): array {
        $clients = $this->clientsModel->getAllClients();
        $activeClients = [];

        foreach ($clients as $client) {
            $facNitSec = (int) ($client['NitSec'] ?? 0);
            $clientName = trim((string) ($client['NitCom'] ?? ''));

            if ($facNitSec <= 0) {
                continue;
            }

            $config = $this->configModel->getConfig((string) $facNitSec);
            if ($config === null || empty($config['activo']) || empty((array) ($config['documents'] ?? []))) {
                $summary['skipped_no_config']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_DISCOVERED, $facNitSec, $clientName, 'skipped_no_config');
                continue;
            }

            if ($dryRun) {
                $summary['queued_clients']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_DISCOVERED, $facNitSec, $clientName, 'dry_run_queued');
                continue;
            }

            $jobId = AuditEvent::uuidV4();
            $idempotencyKey = 'cron-batch-' . date('Ymd-H') . '-' . $facNitSec;

            try {
                $existingJobId = $this->jobStore->claimIdempotencyKey(
                    $idempotencyKey,
                    $jobId,
                    self::DEFAULT_IDEMPOTENCY_TTL_SECONDS
                );
            } catch (Throwable $e) {
                $summary['errors']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_DISCOVERED, $facNitSec, $clientName, 'error_idempotency', error: $e->getMessage());
                continue;
            }

            if ($existingJobId !== null) {
                $summary['skipped_duplicate']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_DISCOVERED, $facNitSec, $clientName, 'skipped_duplicate', jobId: $existingJobId);
                continue;
            }

            if (!$this->jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit)) {
                $this->jobStore->releaseIdempotencyKey($idempotencyKey);
                $summary['errors']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_DISCOVERED, $facNitSec, $clientName, 'error_init_job', jobId: $jobId, error: 'No se pudo inicializar el job en Redis');
                continue;
            }

            $activeClients[$facNitSec] = [
                'fac_nit_sec' => $facNitSec,
                'client_name' => $clientName,
                'job_id' => $jobId,
                'idempotency_key' => $idempotencyKey,
                'total_enqueued' => 0,
                'skipped_locked' => 0,
                'created_reservations' => [],
                'created_audit_ids' => [],
                'prepared_events' => [],
            ];

            $this->notifyProgress($progressCallback, self::PHASE_CLIENT_DISCOVERED, [
                'fac_nit_sec' => (string) $facNitSec,
                'client_name' => $clientName,
                'status' => 'initialized',
                'job_id' => $jobId,
            ]);
        }

        return $activeClients;
    }

    /**
     * Consulta facturas candidatas mediante keyset pagination, reserva, inicializa estado y sella los jobs.
     *
     * @param array<int, array<string, mixed>> $activeClients
     * @param array<string, mixed> $summary
     * @return array<string, array<string, mixed>> Jobs sellados listos para emisión
     */
    private function prepareAndSealClientBatches(
        array $activeClients,
        string $dateFrom,
        string $dateTo,
        int $limit,
        ?callable $progressCallback,
        array &$summary
    ): array {
        $readyClients = [];

        foreach ($activeClients as $facNitSec => $state) {
            $jobId = (string) $state['job_id'];
            $clientName = (string) $state['client_name'];
            $idempotencyKey = (string) $state['idempotency_key'];

            // 1. Keyset pagination para superar de forma transparente el límite de 1.000 de SQL Server
            try {
                $invoices = $this->fetchInvoicesWithKeysetPagination($facNitSec, $dateFrom, $dateTo, $limit);
            } catch (Throwable $e) {
                Logger::error('MultiClientBatchDispatcher: error consultando facturas de cliente en SQL Server', [
                    'fac_nit_sec' => $facNitSec,
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);

                $clean = $this->cleanupClientEnqueueState($jobId, $state['created_audit_ids'], $state['created_reservations']);
                if ($clean) {
                    $this->jobStore->releaseIdempotencyKey($idempotencyKey);
                } else {
                    Logger::warning('MultiClientBatchDispatcher: idempotencia retenida tras fallo parcial de rollback para evitar duplicación sobre estado corrupto', [
                        'job_id' => $jobId,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                }

                $summary['errors']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_PREPARED, $facNitSec, $clientName, 'error_query', jobId: $jobId, error: $e->getMessage());
                continue;
            }

            // 2. Preparar reservas, inicializar auditorías y sellar Job
            $createdReservations = [];
            $createdAuditIds = [];
            $uncompensatedReservations = [];
            $uncompensatedAuditIds = [];
            $skippedLocked = 0;
            $total = 0;

            try {
                $preparedEvents = $this->enrollCandidateInvoices(
                    $jobId,
                    $facNitSec,
                    $invoices,
                    $createdReservations,
                    $createdAuditIds,
                    $skippedLocked,
                    $uncompensatedReservations,
                    $uncompensatedAuditIds
                );
                $total = count($preparedEvents);

                $this->sealAndPublishBatch(
                    $jobId,
                    $facNitSec,
                    $clientName,
                    $preparedEvents,
                    $skippedLocked,
                    $dateFrom,
                    $dateTo,
                    $limit,
                    $summary,
                    $readyClients,
                    $uncompensatedReservations,
                    $uncompensatedAuditIds
                );

                $this->notifyProgress($progressCallback, self::PHASE_CLIENT_PREPARED, [
                    'fac_nit_sec' => (string) $facNitSec,
                    'client_name' => $clientName,
                    'job_id' => $jobId,
                    'enqueued' => $total,
                    'skipped_locked' => $skippedLocked,
                ]);
            } catch (Throwable $e) {
                Logger::error('MultiClientBatchDispatcher: error preparando o sellando batch', [
                    'fac_nit_sec' => $facNitSec,
                    'job_id' => $jobId,
                    'error' => $e->getMessage(),
                ]);

                $clean = $this->cleanupClientEnqueueState($jobId, $createdAuditIds, $createdReservations);
                if ($clean) {
                    $this->jobStore->releaseIdempotencyKey($idempotencyKey);
                } else {
                    // QUAL-015: Registrar deuda de compensación en el job para recuperación por otras instancias
                    try {
                        $this->jobStore->patchJob($jobId, [
                            'compensation_pending' => true,
                            'pending_audit_ids' => $createdAuditIds,
                            'pending_reservations' => $createdReservations,
                        ]);
                    } catch (Throwable) {
                    }

                    Logger::warning('MultiClientBatchDispatcher: idempotencia retenida tras fallo parcial de rollback para evitar duplicación sobre estado corrupto', [
                        'job_id' => $jobId,
                        'idempotency_key' => $idempotencyKey,
                        'uncleaned_audits' => $createdAuditIds,
                        'uncleaned_reservations' => array_column($createdReservations, 'dis_id'),
                    ]);
                }

                $summary['errors']++;
                $this->recordClientResult($summary, $progressCallback, self::PHASE_CLIENT_PREPARED, $facNitSec, $clientName, 'error_sealing', jobId: $jobId, totalEnqueued: $total, skippedLocked: $skippedLocked, error: $e->getMessage());
            }
        }

        return $readyClients;
    }

    /**
     * Consulta facturas candidatas mediante keyset pagination superando el límite de SQL Server.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchInvoicesWithKeysetPagination(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): array {
        $invoices = [];
        $cursor = null;

        while (count($invoices) < $limit) {
            $remaining = $limit - count($invoices);
            $fetchLimit = min(self::SQL_PAGE_LIMIT, $remaining);

            $page = $this->invoicesModel->getInvoicesForAuditBatch(
                $facNitSec,
                $dateFrom,
                $dateTo,
                $fetchLimit,
                $cursor
            );

            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                $invoices[] = $row;
                if (count($invoices) >= $limit) {
                    break;
                }
            }

            if (count($page) < $fetchLimit) {
                break;
            }

            $lastRow = end($page);
            $cursor = self::cursorFromInvoice($lastRow);
            if ($cursor === null) {
                break;
            }
        }

        return $invoices;
    }

    /**
     * Prepara reservas y enrola facturas candidatas en JobStore y AuditStateStore.
     *
     * @param array<int, array<string, mixed>> $invoices
     * @param array<int, array{dis_id: string, token: string}> $createdReservations
     * @param array<int, string> $createdAuditIds
     * @param array<int, array{dis_id: string, token: string}> $uncompensatedReservations
     * @param array<int, string> $uncompensatedAuditIds
     * @return array<int, array{event: AuditEvent, dis_id: string, reservation_token: string}>
     */
    private function enrollCandidateInvoices(
        string $jobId,
        int $facNitSec,
        array $invoices,
        array &$createdReservations,
        array &$createdAuditIds,
        int &$skippedLocked,
        array &$uncompensatedReservations,
        array &$uncompensatedAuditIds
    ): array {
        $preparedEvents = [];

        foreach ($invoices as $invoice) {
            $identity = self::invoiceIdentity($invoice);
            if ($identity === null) {
                continue;
            }

            $disDetNro = $identity['dis_det_nro'];
            $disId = $identity['dis_id'];
            $auditId = AuditEvent::uuidV4();
            $reservationToken = AuditEvent::uuidV4();

            $reservationPayload = [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'dis_det_nro' => $disDetNro,
                'fac_nit_sec' => (string) $facNitSec,
                'dis_id' => $disId,
                'source' => 'batch',
            ];

            if (!$this->jobStore->claimAuditReservation($disId, $reservationToken, $reservationPayload)) {
                $skippedLocked++;
                continue;
            }

            $createdReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];

            if (!$this->stateStore->initAudit($auditId, $disDetNro, $jobId, (string) $facNitSec, $disId)) {
                $this->handleFailedEnrollment($auditId, $disId, $reservationToken, false, $createdAuditIds, $createdReservations, $uncompensatedReservations, $uncompensatedAuditIds);
                continue;
            }

            $createdAuditIds[] = $auditId;

            if (!$this->stateStore->patchAudit($auditId, ['reservation_token' => $reservationToken])) {
                $this->handleFailedEnrollment($auditId, $disId, $reservationToken, true, $createdAuditIds, $createdReservations, $uncompensatedReservations, $uncompensatedAuditIds);
                continue;
            }

            $event = AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_CREATED,
                auditId: $auditId,
                jobId: $jobId,
                documentId: null,
                payload: [
                    'dis_det_nro' => $disDetNro,
                    'fac_nit_sec' => (string) $facNitSec,
                    'dis_id' => $disId,
                    'reservation_token' => $reservationToken,
                    'source' => 'batch',
                ]
            );

            if (!$this->jobStore->registerAuditInJob(
                $jobId,
                $auditId,
                $disDetNro,
                $disId,
                $reservationToken,
                $event->eventId
            )) {
                $this->handleFailedEnrollment($auditId, $disId, $reservationToken, true, $createdAuditIds, $createdReservations, $uncompensatedReservations, $uncompensatedAuditIds);
                continue;
            }

            $preparedEvents[] = [
                'event' => $event,
                'dis_id' => $disId,
                'reservation_token' => $reservationToken,
            ];
        }

        return $preparedEvents;
    }

    /**
     * Compensa de forma segura un fallo de enrolamiento manteniendo seguimiento granular (QUAL-015).
     *
     * @param array<int, string> $createdAuditIds
     * @param array<int, array{dis_id: string, token: string}> $createdReservations
     * @return array{audit_deleted: bool, reservation_released: bool}
     */
    private function compensateFailedEnrollment(
        string $auditId,
        string $disId,
        string $reservationToken,
        bool $hasAuditCreated,
        array &$createdAuditIds,
        array &$createdReservations
    ): array {
        $result = ['audit_deleted' => true, 'reservation_released' => true];

        if ($hasAuditCreated) {
            try {
                if ($this->stateStore->deleteAudit($auditId)) {
                    array_pop($createdAuditIds);
                } else {
                    $result['audit_deleted'] = false;
                    Logger::error('MultiClientBatchDispatcher: deleteAudit devolvió false en rollback de enrolamiento', [
                        'audit_id' => $auditId,
                        'dis_id' => $disId,
                    ]);
                }
            } catch (Throwable $e) {
                $result['audit_deleted'] = false;
                Logger::error('MultiClientBatchDispatcher: excepción en deleteAudit en rollback de enrolamiento', [
                    'audit_id' => $auditId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            if ($this->jobStore->releaseAuditReservation($disId, $reservationToken)) {
                array_pop($createdReservations);
            } else {
                $result['reservation_released'] = false;
                Logger::error('MultiClientBatchDispatcher: releaseAuditReservation devolvió false en rollback de enrolamiento', [
                    'dis_id' => $disId,
                ]);
            }
        } catch (Throwable $e) {
            $result['reservation_released'] = false;
            Logger::error('MultiClientBatchDispatcher: excepción en releaseAuditReservation en rollback de enrolamiento', [
                'dis_id' => $disId,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Sella el lote en JobStore y emite el evento batch correspondiente (batch_completed o batch_created).
     *
     * @param array<int, array{event: AuditEvent, dis_id: string, reservation_token: string}> $preparedEvents
     * @param array<string, mixed> $summary
     * @param array<string, array<string, mixed>> $readyClients
     * @param array<int, array{dis_id: string, token: string}> $uncompensatedReservations
     * @param array<int, string> $uncompensatedAuditIds
     */
    private function sealAndPublishBatch(
        string $jobId,
        int $facNitSec,
        string $clientName,
        array $preparedEvents,
        int $skippedLocked,
        string $dateFrom,
        string $dateTo,
        int $limit,
        array &$summary,
        array &$readyClients,
        array $uncompensatedReservations = [],
        array $uncompensatedAuditIds = []
    ): void {
        $total = count($preparedEvents);
        $metadata = [
            'accepted' => $total,
            'skipped_locked' => $skippedLocked,
        ];
        if (!empty($uncompensatedReservations) || !empty($uncompensatedAuditIds)) {
            $metadata['compensation_pending'] = true;
            $metadata['pending_audit_ids'] = $uncompensatedAuditIds;
            $metadata['pending_reservations'] = $uncompensatedReservations;
        }

        if ($total === 0) {
            $emptyMetadata = array_merge($metadata, ['accepted' => 0]);
            if (!$this->jobStore->sealJob($jobId, 0, $emptyMetadata)) {
                throw new RuntimeException('No se pudo sellar el job batch vacío en Redis');
            }

            // QUAL-010: Transicionar job vacío a terminal antes de publicar
            $this->jobStore->patchJob($jobId, ['status' => BatchJobStore::JOB_STATUS_COMPLETED]);

            // QUAL-010: Usar protocolo claim/confirm/release para lote vacío
            $terminalEventType = AuditEvent::TYPE_BATCH_COMPLETED;
            $claimToken = bin2hex(random_bytes(8));
            if (!$this->jobStore->claimBatchTerminalEvent($jobId, $terminalEventType, $claimToken)) {
                Logger::warning('MultiClientBatchDispatcher: no se pudo reclamar evento terminal para batch vacío', [
                    'job_id' => $jobId,
                ]);
                // Job ya fue sellado y marcado completed — es consistente aunque no se publique evento
            } else {
                try {
                    $this->publisher->publish(AuditEvent::create(
                        eventType: $terminalEventType,
                        auditId: null,
                        jobId: $jobId,
                        payload: [
                            'status' => BatchJobStore::JOB_STATUS_COMPLETED,
                            'total' => 0,
                            'done' => 0,
                            'failed' => 0,
                            'accepted' => 0,
                            'skipped_locked' => $skippedLocked,
                        ]
                    ));
                    if (!$this->jobStore->confirmBatchTerminalEvent($jobId, $terminalEventType, $claimToken)) {
                        Logger::warning('MultiClientBatchDispatcher: confirm terminal falló para batch vacío (CAS perdido)', [
                            'job_id' => $jobId,
                        ]);
                    }
                } catch (\Throwable $pubError) {
                    $this->jobStore->releaseBatchTerminalEvent($jobId, $claimToken);
                    throw $pubError;
                }
            }

            $summary['queued_clients']++;
            $summary['clients'][] = [
                'fac_nit_sec' => $facNitSec,
                'client_name' => $clientName,
                'job_id' => $jobId,
                'status' => 'completed_empty',
                'total_enqueued' => 0,
                'skipped_locked' => $skippedLocked,
                'error' => null,
            ];
            return;
        }

        if (!$this->jobStore->sealJob($jobId, $total, $metadata)) {
            throw new RuntimeException('No se pudo sellar el job batch en Redis');
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_CREATED,
            auditId: null,
            jobId: $jobId,
            documentId: null,
            payload: [
                'fac_nit_sec' => (string) $facNitSec,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'total' => $total,
                'accepted' => $total,
                'skipped_locked' => $skippedLocked,
            ]
        ));

        $summary['queued_clients']++;
        $summary['clients'][] = [
            'fac_nit_sec' => $facNitSec,
            'client_name' => $clientName,
            'job_id' => $jobId,
            'status' => 'queued',
            'total_enqueued' => $total,
            'skipped_locked' => $skippedLocked,
            'error' => null,
        ];

        $readyClients[$jobId] = [
            'fac_nit_sec' => $facNitSec,
            'client_name' => $clientName,
            'job_id' => $jobId,
            'prepared_events' => $preparedEvents,
            'total_enqueued' => 0,
            'total_target' => $total,
        ];
    }

    /**
     * Publica los eventos audit_created a Redis Streams de forma entrelazada (Round-Robin).
     *
     * Reglas de consistencia (QUAL-004):
     * 1. Nunca retira un evento de memoria ($state['prepared_events']) antes de confirmar la publicación exitosa.
     * 2. Si la publicación falla tras reintentos, intenta reconciliar en Redis marcándolo como 'failed' y liberando su reserva.
     * 3. Si la reconciliación en Redis tiene éxito, retira el evento de memoria y verifica si el job alcanzó estado terminal.
     * 4. Si la reconciliación en Redis TAMBIÉN falla (ej: caída total de Redis), conserva el evento en memoria y aborta la ronda.
     * 5. Si ningún job puede avanzar en una ronda completa, aborta el ciclo de despacho previniendo bucles infinitos y pérdida de datos.
     *
     * @param array<string, array<string, mixed>> $readyClients Jobs preparados indexados por jobId
     * @param array<string, mixed> $summary
     */
    private function interleaveAndPublishAuditEvents(
        array &$readyClients,
        int $chunkSize,
        ?callable $progressCallback,
        array &$summary
    ): void {
        $round = 1;

        while ($this->hasPendingEventsToPublish($readyClients)) {
            $anyProgressInRound = false;

            foreach ($readyClients as $jobId => &$state) {
                if (empty($state['prepared_events'])) {
                    continue;
                }

                $facNitSec = (int) ($state['fac_nit_sec'] ?? 0);
                $processedInChunk = 0;
                $enqueuedInChunk = 0;

                while ($processedInChunk < $chunkSize && !empty($state['prepared_events'])) {
                    $item = $state['prepared_events'][0];
                    /** @var AuditEvent $event */
                    $event = $item['event'];
                    $disId = (string) $item['dis_id'];
                    $token = (string) $item['reservation_token'];
                    $auditId = (string) $event->auditId;

                    $publishedStreamId = $this->publishEventWithRetries($event, $facNitSec, $jobId);

                    if ($publishedStreamId !== null) {
                        $this->handlePublishedEvent($jobId, $auditId, $publishedStreamId, $state, $summary);
                        $enqueuedInChunk++;
                        $processedInChunk++;
                        $anyProgressInRound = true;
                    } else {
                        $reconciled = $this->reconcileFailedPublication(
                            $jobId,
                            $auditId,
                            $disId,
                            $token,
                            $state,
                            $summary
                        );

                        if ($reconciled) {
                            $processedInChunk++;
                            $anyProgressInRound = true;
                        } else {
                            break;
                        }
                    }
                }

                if ($enqueuedInChunk > 0 || $processedInChunk > 0) {
                    $this->notifyProgress($progressCallback, self::PHASE_CHUNK_PUBLISHED, [
                        'job_id' => $jobId,
                        'fac_nit_sec' => (string) $facNitSec,
                        'client_name' => $state['client_name'],
                        'chunk_size' => $enqueuedInChunk,
                        'remaining' => count($state['prepared_events']),
                        'total_enqueued' => $state['total_enqueued'],
                        'round' => $round,
                    ]);
                }
            }
            unset($state);

            if (!$anyProgressInRound) {
                Logger::critical('MultiClientBatchDispatcher: ciclo abortado; ningún job pudo avanzar por indisponibilidad compartida de Redis', [
                    'round' => $round,
                    'active_jobs' => count($readyClients),
                ]);
                break;
            }

            $round++;
        }
    }

    /**
     * Comprueba si aún restan eventos por publicar entre todos los jobs preparados.
     *
     * @param array<string, array<string, mixed>> $readyClients
     */
    private function hasPendingEventsToPublish(array $readyClients): bool
    {
        foreach ($readyClients as $state) {
            if (!empty($state['prepared_events'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Intenta publicar un evento con reintentos exponenciales.
     */
    private function publishEventWithRetries(AuditEvent $event, int $facNitSec, string $jobId): ?string
    {
        $auditId = (string) $event->auditId;

        for ($attempt = 1; $attempt <= self::MAX_PUBLISH_RETRIES; $attempt++) {
            try {
                return (string) $this->publisher->publish($event);
            } catch (Throwable $e) {
                if ($attempt < self::MAX_PUBLISH_RETRIES) {
                    usleep(25000 * $attempt);
                } else {
                    Logger::error('MultiClientBatchDispatcher: error crítico publicando audit_created tras reintentos', [
                        'fac_nit_sec' => $facNitSec,
                        'job_id' => $jobId,
                        'audit_id' => $auditId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Registra la publicación confirmada en el estado durable del job y actualiza métricas.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     */
    private function handlePublishedEvent(
        string $jobId,
        string $auditId,
        string $publishedStreamId,
        array &$state,
        array &$summary
    ): void {
        $marked = false;
        try {
            $marked = $this->jobStore->markAuditPublishedInJob($jobId, $auditId, $publishedStreamId);
        } catch (Throwable $t) {
            Logger::warning('MultiClientBatchDispatcher: no se pudo actualizar publication_status en jobStore', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'error' => $t->getMessage(),
            ]);
        }

        if (!$marked) {
            Logger::error('MultiClientBatchDispatcher: estado de publicación incierto; el evento fue publicado en stream pero markAuditPublishedInJob devolvió false', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'stream_id' => $publishedStreamId,
            ]);
        }

        array_shift($state['prepared_events']);
        $state['total_enqueued']++;
        $summary['total_invoices_queued']++;
    }

    /**
     * Reconcilia un evento fallido marcándolo en JobStore y compensando reservas y estado.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $summary
     */
    private function reconcileFailedPublication(
        string $jobId,
        string $auditId,
        string $disId,
        string $token,
        array &$state,
        array &$summary
    ): bool {
        $summary['errors']++;

        // 1. Intentar reconciliación atómica en Redis (QUAL-015)
        $reconciled = false;
        try {
            $reconciled = $this->jobStore->reconcileFailedAuditInJob(
                $jobId,
                $auditId,
                $disId,
                $token,
                'MultiClientBatchDispatcher::publisher_failed'
            );
        } catch (Throwable $t) {
            Logger::error('MultiClientBatchDispatcher: fallo reconcileFailedAuditInJob en reconciliación', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'error' => $t->getMessage(),
            ]);
            $reconciled = false;
        }

        if ($reconciled) {
            array_shift($state['prepared_events']);
            $this->checkAndPublishTerminalBatchEvent($jobId);
            return true;
        }

        // 2. Fallback paso a paso (soporta mocks de pruebas unitarias o degradación)
        $stepwiseReconciled = false;
        try {
            $stepwiseReconciled = $this->jobStore->markAuditCompletedInJob(
                $jobId,
                $auditId,
                'failed',
                0,
                'MultiClientBatchDispatcher::publisher_failed'
            );
        } catch (Throwable $t) {
            Logger::error('MultiClientBatchDispatcher: fallo markAuditCompletedInJob en reconciliación', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'error' => $t->getMessage(),
            ]);
        }

        if (!$stepwiseReconciled) {
            Logger::critical('MultiClientBatchDispatcher: fallo catastrófico en Redis durante publicación y reconciliación; conservando eventos pendientes', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'remaining_events' => count($state['prepared_events']),
            ]);
            return false;
        }

        $cleanupSuccess = true;
        try {
            if (!$this->stateStore->deleteAudit($auditId)) {
                $cleanupSuccess = false;
            }
        } catch (Throwable $t) {
            $cleanupSuccess = false;
            Logger::warning('MultiClientBatchDispatcher: error deleteAudit en reconciliación', [
                'audit_id' => $auditId,
                'error' => $t->getMessage(),
            ]);
        }

        try {
            if (!$this->jobStore->releaseAuditReservation($disId, $token)) {
                $cleanupSuccess = false;
            }
        } catch (Throwable $t) {
            $cleanupSuccess = false;
            Logger::error('MultiClientBatchDispatcher: fallo releaseAuditReservation en reconciliación', [
                'dis_id' => $disId,
                'error' => $t->getMessage(),
            ]);
        }

        if ($stepwiseReconciled && $cleanupSuccess) {
            array_shift($state['prepared_events']);
            $this->checkAndPublishTerminalBatchEvent($jobId);
            return true;
        }

        // 3. Compensación incompleta: persistir deuda durable en el job (QUAL-015)
        try {
            $this->jobStore->patchAuditInJob($jobId, $auditId, [
                'compensation_pending' => true,
                'compensation_dis_id' => $disId,
                'compensation_token' => $token,
            ]);
        } catch (Throwable) {
        }

        Logger::critical('MultiClientBatchDispatcher: compensación incompleta en reconciliación; conservando evento y registrando deuda en Redis', [
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'dis_id' => $disId,
        ]);

        return false;
    }

    /**
     * Verifica si el job alcanzó estado terminal debido a fallos y emite el evento correspondiente.
     */
    private function checkAndPublishTerminalBatchEvent(string $jobId): void
    {
        try {
            $job = $this->jobStore->getJob($jobId);
            if ($job === null) {
                return;
            }

            $jobStatus = (string) ($job['status'] ?? '');
            if ($jobStatus !== BatchJobStore::JOB_STATUS_COMPLETED && $jobStatus !== BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR) {
                return;
            }

            $terminalEventType = ($jobStatus === BatchJobStore::JOB_STATUS_COMPLETED)
                ? AuditEvent::TYPE_BATCH_COMPLETED
                : AuditEvent::TYPE_BATCH_COMPLETED_ERR;

            $claimToken = bin2hex(random_bytes(8));
            if ($this->jobStore->claimBatchTerminalEvent($jobId, $terminalEventType, $claimToken)) {
                try {
                    $this->publisher->publish(AuditEvent::create(
                        eventType: $terminalEventType,
                        auditId: null,
                        jobId: $jobId,
                        payload: [
                            'status' => $jobStatus,
                            'total' => (int) ($job['total'] ?? 0),
                            'done' => (int) ($job['done'] ?? 0),
                            'failed' => (int) ($job['failed'] ?? 0),
                            'accepted' => (int) ($job['accepted'] ?? 0),
                            'skipped_locked' => (int) ($job['skipped_locked'] ?? 0),
                        ]
                    ));
                    if (!$this->jobStore->confirmBatchTerminalEvent($jobId, $terminalEventType, $claimToken)) {
                        Logger::warning('MultiClientBatchDispatcher: confirm terminal falló (CAS perdido)', [
                            'job_id' => $jobId,
                            'event_type' => $terminalEventType,
                        ]);
                    }
                } catch (\Throwable $pubError) {
                    $this->jobStore->releaseBatchTerminalEvent($jobId, $claimToken);
                    throw $pubError;
                }
            }
        } catch (Throwable $t) {
            Logger::warning('MultiClientBatchDispatcher: no se pudo verificar terminal status post-reconciliación', [
                'job_id' => $jobId,
                'error' => $t->getMessage(),
            ]);
        }
    }

    /**
     * Limpia el estado parcial de encolamiento de forma aislada ante excepciones
     * para evitar dejar jobs huérfanos o slots de reserva bloqueados (QUAL-015).
     *
     * @param array<string> $createdAuditIds
     * @param array<int,array{dis_id:string,token:string}> $createdReservations
     */
    private function cleanupClientEnqueueState(
        string $jobId,
        array $createdAuditIds,
        array $createdReservations
    ): bool {
        $clean = true;

        foreach ($createdAuditIds as $auditId) {
            try {
                $deleted = $this->stateStore->deleteAudit($auditId);
                if ($deleted !== true) {
                    $clean = false;
                    Logger::warning('MultiClientBatchDispatcher: deleteAudit retornó false durante rollback', [
                        'job_id' => $jobId,
                        'audit_id' => $auditId,
                    ]);
                }
            } catch (Throwable $t) {
                $clean = false;
                Logger::error('MultiClientBatchDispatcher: fallo borrando audit durante rollback', [
                    'job_id' => $jobId,
                    'audit_id' => $auditId,
                    'error' => $t->getMessage(),
                ]);
            }
        }

        try {
            $deleted = $this->jobStore->deleteJob($jobId);
            if ($deleted !== true) {
                $clean = false;
                Logger::warning('MultiClientBatchDispatcher: deleteJob retornó false durante rollback', [
                    'job_id' => $jobId,
                ]);
            }
        } catch (Throwable $t) {
            $clean = false;
            Logger::error('MultiClientBatchDispatcher: fallo borrando job durante rollback', [
                'job_id' => $jobId,
                'error' => $t->getMessage(),
            ]);
        }

        foreach ($createdReservations as $reservation) {
            try {
                $released = $this->jobStore->releaseAuditReservation($reservation['dis_id'], $reservation['token']);
                if ($released !== true) {
                    $clean = false;
                    Logger::warning('MultiClientBatchDispatcher: releaseAuditReservation retornó false durante rollback', [
                        'job_id' => $jobId,
                        'dis_id' => $reservation['dis_id'],
                    ]);
                }
            } catch (Throwable $t) {
                $clean = false;
                Logger::error('MultiClientBatchDispatcher: fallo liberando reserva durante rollback', [
                    'job_id' => $jobId,
                    'dis_id' => $reservation['dis_id'],
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $clean;
    }

    /**
     * Emite un evento de progreso al callback opcional si está presente.
     *
     * @param (callable(string, array<string,mixed>):void)|null $callback
     * @param array<string,mixed> $data
     */
    private function notifyProgress(?callable $callback, string $event, array $data): void
    {
        if ($callback !== null) {
            try {
                $callback($event, $data);
            } catch (Throwable) {
                // Prevenir que una excepción en el callback de UI/CLI rompa el flujo de dominio
            }
        }
    }

    /**
     * Extrae y valida la identidad operativa y global de una factura.
     *
     * @param array<string,mixed> $invoice
     * @return array{dis_det_nro:string, dis_id:string}|null
     */
    private static function invoiceIdentity(array $invoice): ?array
    {
        $disDetNro = isset($invoice['Dispensa']) ? trim((string) $invoice['Dispensa']) : '';
        $disId = isset($invoice['DisId']) ? trim((string) $invoice['DisId']) : '';

        if ($disDetNro === '' || $disId === '') {
            return null;
        }

        return [
            'dis_det_nro' => $disDetNro,
            'dis_id' => $disId,
        ];
    }

    /**
     * Recupera y reconstruye eventos audit_created pendientes de jobs batch sellados durables en Redis (QUAL-004).
     *
     * @param ?callable $progressCallback
     * @param array<string, mixed> $summary
     * @return array<string, array{
     *     job_id: string,
     *     fac_nit_sec: int,
     *     client_name: string,
     *     total_enqueued: int,
     *     prepared_events: array<int, array{event: AuditEvent, dis_id: string, reservation_token: string}>
     * }>
     */
    public function discoverPendingUnpublishedBatches(
        ?callable $progressCallback = null,
        array &$summary = []
    ): array {
        $recovered = [];

        try {
            $cursor = '0';
            $pageSize = 50;
            $visitedCursors = [];

            do {
                $visitedCursors[$cursor] = true;
                $scanResult = $this->jobStore->listJobIds($pageSize, $cursor);
                $cursor = (string) ($scanResult['cursor'] ?? '0');
                $jobIds = (array) ($scanResult['job_ids'] ?? []);

                foreach ($jobIds as $jobId) {
                    $jobId = trim((string) $jobId);
                    if ($jobId === '') {
                        continue;
                    }

                    $job = $this->jobStore->getJob($jobId);
                    if ($job === null || empty($job['sealed'])) {
                        continue;
                    }

                    $this->reconcilePendingCompensationsInJob($jobId, $job);
                    $job = $this->jobStore->getJob($jobId) ?? $job;

                    $jobStatus = (string) ($job['status'] ?? '');
                    if (!in_array($jobStatus, [BatchJobStore::JOB_STATUS_PENDING, BatchJobStore::JOB_STATUS_PROCESSING], true)) {
                        continue;
                    }

                    $facNitSec = (int) ($job['fac_nit_sec'] ?? 0);
                    $clientName = (string) ($job['client_name'] ?? "Cliente {$facNitSec}");
                    $audits = (array) ($job['audits'] ?? []);
                    $pendingEvents = $this->extractPendingEventsFromJob($jobId, $facNitSec, $audits);

                    if (!empty($pendingEvents)) {
                        $recovered[$jobId] = [
                            'job_id'          => $jobId,
                            'fac_nit_sec'     => $facNitSec,
                            'client_name'     => $clientName,
                            'total_enqueued'  => (int) ($job['done'] ?? 0) + (int) ($job['failed'] ?? 0),
                            'prepared_events' => $pendingEvents,
                        ];

                        $this->notifyProgress($progressCallback, self::PHASE_RECOVERY_FOUND, [
                            'job_id'          => $jobId,
                            'fac_nit_sec'     => (string) $facNitSec,
                            'pending_count'   => count($pendingEvents),
                        ]);

                        Logger::info('MultiClientBatchDispatcher: recuperadas auditorías pendientes de publicación en job sellado', [
                            'job_id'          => $jobId,
                            'fac_nit_sec'     => $facNitSec,
                            'pending_count'   => count($pendingEvents),
                        ]);
                    }
                }
            } while ($cursor !== '0' && !isset($visitedCursors[$cursor]));
        } catch (Throwable $e) {
            Logger::error('MultiClientBatchDispatcher: error recuperando jobs pendientes', [
                'error' => $e->getMessage(),
            ]);
        }

        return $recovered;
    }

    /**
     * Resuelve deudas de compensación pendientes registradas en un job sellado (QUAL-015).
     *
     * @param array<string, mixed> $job
     */
    private function reconcilePendingCompensationsInJob(string $jobId, array $job): void
    {
        // 1. Limpieza de deudas de compensación a nivel de auditorías individuales
        $audits = (array) ($job['audits'] ?? []);
        foreach ($audits as $auditId => $auditState) {
            if (!is_array($auditState)) {
                continue;
            }
            if (!empty($auditState['compensation_pending']) || ($auditState['status'] ?? '') === 'compensation_pending') {
                $disId = (string) ($auditState['compensation_dis_id'] ?? $auditState['dis_id'] ?? '');
                $token = (string) ($auditState['compensation_token'] ?? $auditState['reservation_token'] ?? '');

                $reconciled = $this->jobStore->reconcileFailedAuditInJob(
                    $jobId,
                    (string) $auditId,
                    $disId,
                    $token,
                    'MultiClientBatchDispatcher::recovered_compensation'
                );

                if (!$reconciled) {
                    $this->stateStore->deleteAudit((string) $auditId);
                    $this->jobStore->releaseAuditReservation($disId, $token);
                    $this->jobStore->markAuditCompletedInJob(
                        $jobId,
                        (string) $auditId,
                        'failed',
                        0,
                        'MultiClientBatchDispatcher::recovered_compensation'
                    );
                    $this->jobStore->patchAuditInJob($jobId, (string) $auditId, [
                        'compensation_pending' => false,
                        'compensation_dis_id' => null,
                        'compensation_token' => null,
                    ]);
                }

                Logger::info('MultiClientBatchDispatcher: deuda de compensación de auditoría recuperada y resuelta', [
                    'job_id' => $jobId,
                    'audit_id' => $auditId,
                    'dis_id' => $disId,
                ]);
            }
        }

        // 2. Limpieza de deudas de enrolamiento a nivel de job
        if (!empty($job['compensation_pending'])) {
            $pendingAudits = (array) ($job['pending_audit_ids'] ?? []);
            $pendingReservations = (array) ($job['pending_reservations'] ?? []);
            $allClean = true;

            foreach ($pendingAudits as $auditId) {
                if (!$this->stateStore->deleteAudit((string) $auditId)) {
                    $allClean = false;
                }
            }
            foreach ($pendingReservations as $res) {
                $disId = (string) ($res['dis_id'] ?? '');
                $token = (string) ($res['token'] ?? '');
                if (!$this->jobStore->releaseAuditReservation($disId, $token)) {
                    $allClean = false;
                }
            }

            if ($allClean) {
                $this->jobStore->patchJob($jobId, [
                    'compensation_pending' => false,
                    'pending_audit_ids' => [],
                    'pending_reservations' => [],
                ]);
                Logger::info('MultiClientBatchDispatcher: deuda de compensación a nivel de job recuperada y resuelta', [
                    'job_id' => $jobId,
                ]);
            }
        }
    }

    /**
     * Reconstruye los eventos audit_created pendientes de publicación a partir de los datos de un job sellado.
     *
     * @param array<string, mixed> $audits
     * @return array<int, array{event: AuditEvent, dis_id: string, reservation_token: string}>
     */
    private function extractPendingEventsFromJob(string $jobId, int $facNitSec, array $audits): array
    {
        $pendingEvents = [];

        foreach ($audits as $auditId => $auditState) {
            if (!is_array($auditState)) {
                continue;
            }

            // Filtrar estrictamente solo aquellas que NO han sido publicadas a Redis Streams (QUAL-004)
            $pubStatus = (string) ($auditState['publication_status'] ?? '');
            $publishedAt = (string) ($auditState['published_at'] ?? '');
            if ($pubStatus === 'published' || $publishedAt !== '') {
                continue;
            }

            // Ignorar auditorías con compensación pendiente o que ya no estén en estado business 'pending' (QUAL-015)
            if (!empty($auditState['compensation_pending']) || ($auditState['status'] ?? '') !== 'pending') {
                continue;
            }

            $disDetNro = (string) ($auditState['dis_det_nro'] ?? '');
            $disId = (string) ($auditState['dis_id'] ?? '');
            $token = (string) ($auditState['reservation_token'] ?? '');
            $stableEventId = (string) ($auditState['event_id'] ?? '');

            if ($disDetNro === '') {
                continue;
            }

            // Reutilizar el mismo event_id estable si existe; fallback determinístico a auditId (QUAL-004)
            $eventId = $stableEventId !== '' ? $stableEventId : (string) $auditId;

            $event = AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_CREATED,
                auditId: (string) $auditId,
                jobId: $jobId,
                payload: [
                    'dis_det_nro' => $disDetNro,
                    'fac_nit_sec' => (string) $facNitSec,
                    'dis_id' => $disId,
                    'reservation_token' => $token,
                    'source' => 'batch',
                ],
                eventId: $eventId,
            );

            $pendingEvents[] = [
                'event' => $event,
                'dis_id' => $disId,
                'reservation_token' => $token,
            ];
        }

        return $pendingEvents;
    }

    /**
     * Construye el cursor keyset de la última fila leída para paginación continua en SQL Server.
     *
     * @param array<string,mixed> $invoice
     * @return array{date:string, disId:string, dispensa:string}|null
     */
    private static function cursorFromInvoice(array $invoice): ?array
    {
        $date = trim((string) ($invoice['DisFecSol'] ?? ''));
        $disId = trim((string) ($invoice['DisId'] ?? ''));
        $dispensa = trim((string) ($invoice['Dispensa'] ?? ''));

        if ($date === '' || $disId === '' || $dispensa === '') {
            return null;
        }

        return [
            'date' => $date,
            'disId' => $disId,
            'dispensa' => $dispensa,
        ];
    }

    /**
     * Registra el resultado de un cliente en el summary y notifica progreso.
     *
     * Consolida el patrón repetitivo de construir el registro de summary
     * y emitir la notificación de progreso en un único punto.
     *
     * @param array<string, mixed> $summary
     */
    private function recordClientResult(
        array &$summary,
        ?callable $progressCallback,
        string $phase,
        int $facNitSec,
        string $clientName,
        string $status,
        ?string $jobId = null,
        int $totalEnqueued = 0,
        int $skippedLocked = 0,
        ?string $error = null
    ): void {
        $summary['clients'][] = [
            'fac_nit_sec'    => $facNitSec,
            'client_name'    => $clientName,
            'job_id'         => $jobId,
            'status'         => $status,
            'total_enqueued' => $totalEnqueued,
            'skipped_locked' => $skippedLocked,
            'error'          => $error,
        ];

        $progressData = [
            'fac_nit_sec' => (string) $facNitSec,
            'client_name' => $clientName,
            'status'      => $status,
            'job_id'      => $jobId,
        ];
        if ($totalEnqueued > 0) {
            $progressData['enqueued'] = $totalEnqueued;
        }
        if ($skippedLocked > 0) {
            $progressData['skipped_locked'] = $skippedLocked;
        }
        if ($error !== null) {
            $progressData['error'] = $error;
        }

        $this->notifyProgress($progressCallback, $phase, $progressData);
    }

    /**
     * Maneja un fallo de enrolamiento: compensa y registra deudas no compensadas (QUAL-015).
     *
     * @param array<int, string> $createdAuditIds
     * @param array<int, array{dis_id: string, token: string}> $createdReservations
     * @param array<int, array{dis_id: string, token: string}> $uncompensatedReservations
     * @param array<int, string> $uncompensatedAuditIds
     */
    private function handleFailedEnrollment(
        string $auditId,
        string $disId,
        string $reservationToken,
        bool $hasAuditCreated,
        array &$createdAuditIds,
        array &$createdReservations,
        array &$uncompensatedReservations,
        array &$uncompensatedAuditIds
    ): void {
        $compResult = $this->compensateFailedEnrollment(
            $auditId,
            $disId,
            $reservationToken,
            $hasAuditCreated,
            $createdAuditIds,
            $createdReservations
        );

        if (!$compResult['reservation_released']) {
            $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
        }
        if ($hasAuditCreated && !$compResult['audit_deleted']) {
            $uncompensatedAuditIds[] = $auditId;
        }
    }
}
