<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Services\Audit\Pipeline\AuditDataService;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Cache;
use Core\Response;
use Core\Logger;
use RuntimeException;

class AuditController extends Controller
{
    public function single(): void
    {
        $data = $this->validate([
            'FacSec' => 'required|string|max:255',
        ]);

        $facSec = trim((string) $data['FacSec']);
        if ($facSec === '') {
            Response::error('FacSec es requerido', 422);
        }

        // Resolver identidad completa desde la FDV
        $dataService = $this->buildAuditDataService();
        try {
            $fdv = $dataService->getDispensationByFacSec($facSec);
        } catch (RuntimeException $e) {
            Response::error(
                'No se encontró la dispensación correspondiente a la factura proporcionada',
                404
            );
        }

        $disDetNro = (string) ($fdv['header']['NumeroFactura'] ?? '');
        $facNitSec = (string) ($fdv['header']['NitSec'] ?? '');

        if ($disDetNro === '') {
            Response::error('La FDV no contiene NumeroFactura (DisDetNro) para esta factura', 422);
        }

        $stateStore = $this->buildStateStore();
        $publisher  = $this->buildEventPublisher();

        $auditId = AuditEvent::uuidV4();
        $auditInitialized = false;

        try {
            $initialized = $stateStore->initAudit(
                $auditId, $disDetNro,
                jobId: null, facNitSec: $facNitSec, facSec: $facSec
            );
            if (!$initialized) {
                Logger::error('AuditController::single no se pudo inicializar estado', [
                    'audit_id' => $auditId,
                ]);
                Response::error('No se pudo encolar la auditoría', 503);
            }
            $auditInitialized = true;

            $event = AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_CREATED,
                auditId: $auditId,
                jobId: null,
                documentId: null,
                payload: [
                    'dis_det_nro'  => $disDetNro,
                    'fac_sec'      => $facSec,
                    'fac_nit_sec'  => $facNitSec,
                    'source'       => 'single',
                ],
            );

            $publisher->publish($event);
        } catch (RuntimeException $e) {
            if ($auditInitialized) {
                $stateStore->deleteAudit($auditId);
            }
            Logger::error('AuditController::single falló encolando', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo encolar la auditoría', 503);
        }

        Response::success(
            [
                'audit_id'    => $auditId,
                'status'      => AuditStateStore::AUDIT_STATUS_PENDING,
                'dis_det_nro' => $disDetNro,
                'fac_sec'     => $facSec,
            ],
            'Auditoría encolada',
            202,
        );
    }

    public function status(string $auditId): void
    {
        if (!AuditEvent::isUuidV4($auditId)) {
            Response::error('auditId inválido', 422);
        }

        try {
            $state = $this->buildStateStore()->getAudit($auditId);
        } catch (RuntimeException $e) {
            Logger::error('AuditController::status falló', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo consultar el estado de la auditoría', 503);
        }

        if ($state === null) {
            Response::error('Auditoría no encontrada o expirada', 404);
        }

        $status = (string) ($state['status'] ?? AuditStateStore::AUDIT_STATUS_PENDING);
        $isTerminal = in_array($status, [
            AuditStateStore::AUDIT_STATUS_COMPLETED,
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_ERROR,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);

        Response::success([
            'audit_id'       => $auditId,
            'status'         => $status,
            'dis_det_nro'    => (string) ($state['dis_det_nro'] ?? ''),
            'fac_sec'        => (string) ($state['fac_sec'] ?? ''),
            'docs_total'     => (int) ($state['docs_total'] ?? 0),
            'docs_done'      => (int) ($state['docs_done'] ?? 0),
            'docs_extracted' => (int) ($state['docs_extracted'] ?? 0),
            'docs_evaluated' => (int) ($state['docs_evaluated'] ?? 0),
            'is_terminal'    => $isTerminal,
            'error_message'  => $isTerminal && in_array($status, [
                AuditStateStore::AUDIT_STATUS_ERROR,
                AuditStateStore::AUDIT_STATUS_FAILED,
            ], true) ? (string) ($state['error_message'] ?? '') : null,
            'created_at'     => (string) ($state['created_at'] ?? ''),
            'updated_at'     => (string) ($state['updated_at'] ?? ''),
        ], 'Estado de la auditoría');
    }

    public function stats(): void
    {
        try {
            $cacheKey = 'audit:stats:summary:v' . Cache::getQueryResultsVersion('all');

            $payload = Cache::remember($cacheKey, function () {
                return $this->buildAuditStatusModel()->getStateSummary();
            }, 30);

            Response::success($payload, 'Resumen de estados de auditoría');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            Logger::error('AuditController::stats falló', [
                'error' => $e->getMessage(),
            ]);
            Response::error('Estadísticas de auditoría temporalmente no disponibles', 503);
        }
    }

    public function results(): void
    {
        try {
            $validated = $this->validateQuery([
                'facNitSec' => 'nullable|integer|min_value:1',
                'facNro' => 'nullable|string|max:50',
                'dateFrom' => 'nullable|date',
                'dateTo' => 'nullable|date',
                'page' => 'nullable|integer|min_value:1',
                'pageSize' => 'nullable|integer|min_value:1|max_value:100',
            ]);

            if (
                isset($validated['dateFrom'], $validated['dateTo']) &&
                $validated['dateFrom'] !== '' &&
                $validated['dateTo'] !== ''
            ) {
                $dtFrom = \DateTime::createFromFormat('Y-m-d', $validated['dateFrom']);
                $dtTo = \DateTime::createFromFormat('Y-m-d', $validated['dateTo']);
                if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                    Response::error('dateFrom no puede ser mayor que dateTo', 422);
                }
            }

            $filters = [];
            foreach (['facNitSec', 'facNro', 'dateFrom', 'dateTo'] as $key) {
                if (isset($validated[$key]) && $validated[$key] !== '') {
                    $filters[$key] = $validated[$key];
                }
            }

            $page = (isset($validated['page']) && $validated['page'] !== '') ? (int)$validated['page'] : 1;
            $pageSize = (isset($validated['pageSize']) && $validated['pageSize'] !== '') ? (int)$validated['pageSize'] : 20;

            Logger::info('AuditController::results', [
                'filters'  => $filters,
                'page'     => $page,
                'pageSize' => $pageSize,
            ]);

            // Caché read-through: 60s TTL para consultas idénticas
            $facNitSecFilter = $filters['facNitSec'] ?? 'all';
            $allVersion = Cache::getQueryResultsVersion('all');
            $scopeVersion = $facNitSecFilter !== 'all'
                ? Cache::getQueryResultsVersion((string) $facNitSecFilter)
                : 0;
            $cacheKey = 'query:results:' . $facNitSecFilter . ':v' . $allVersion . ':' . $scopeVersion . ':'
                . md5(json_encode([$filters, $page, $pageSize]));
            $cacheTtl = 60;

            $payload = Cache::remember($cacheKey, function () use ($filters, $page, $pageSize) {
                $model = $this->buildAuditStatusModel();
                $total = $model->countAudits($filters);
                $results = $model->searchAuditSummaries($filters, $page, $pageSize);
                $totalPages = (int)ceil($total / $pageSize);

                return [
                    'items'      => $results,
                    'total'      => $total,
                    'page'       => $page,
                    'pageSize'   => $pageSize,
                    'totalPages' => $totalPages,
                    'filters'    => $filters,
                ];
            }, $cacheTtl);

            Response::success($payload, 'Resultados de auditorías');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            Logger::error('Excepción en AuditController::results: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            Response::error('Resultados de auditoría temporalmente no disponibles', 503);
        }
    }

    public function resultDetail(string $facSec): void
    {
        try {
            $model = $this->buildAuditStatusModel();
            $detail = $model->getAuditDetailByFacSec($facSec);

            if (empty($detail)) {
                Response::error('Auditoría no encontrada para la factura proporcionada', 404);
            }

            Response::success($detail, 'Detalle de auditoría');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            Logger::error('Excepción en AuditController::resultDetail: ' . $e->getMessage(), [
                'exception' => $e,
                'facSec' => $facSec,
            ]);
            Response::error('Detalle de auditoría temporalmente no disponible', 503);
        }
    }

    public function documentsHistory(): void
    {
        try {
            $validated = $this->validateQuery([
                'facNitSec' => 'nullable|integer|min_value:1',
                'facNro' => 'nullable|string|max:50',
                'page' => 'nullable|integer|min_value:1',
                'pageSize' => 'nullable|integer|min_value:1|max_value:100',
            ]);

            $filters = [];
            foreach (['facNitSec', 'facNro'] as $key) {
                if (isset($validated[$key]) && $validated[$key] !== '') {
                    $filters[$key] = $validated[$key];
                }
            }

            $page = (isset($validated['page']) && $validated['page'] !== '') ? (int)$validated['page'] : 1;
            $pageSize = (isset($validated['pageSize']) && $validated['pageSize'] !== '') ? (int)$validated['pageSize'] : 20;

            $model = new AttachmentsModel();
            $totalItems = $model->countAuditHistory($filters);
            $totalPages = (int) ceil($totalItems / $pageSize);

            $results = $model->getAuditHistory($page, $pageSize, $filters);

            Response::success([
                'items' => $results,
                'total' => $totalItems,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => $totalPages,
                'filters' => $filters
            ], 'Historial de auditorías de documentos');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Logger::error("Error unexpected querying document audit history", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Response::error('Error unexpected querying document audit history', 500);
        }
    }

    public function async(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date'      => 'required|date',
            'dateTo'    => 'optional|date',
            'limit'     => 'nullable|integer|min_value:1|max_value:100',
        ]);

        $dateFrom = (string) $data['date'];
        // Normalizar: si dateTo ausente, consultar un solo dia
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string) $data['dateTo'] : $dateFrom;

        $dtFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
        $dtTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
        if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
            Response::error('dateTo debe ser mayor o igual a date', 422);
        }

        $facNitSec = (int) $data['facNitSec'];
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        $headers = getallheaders();
        $idempotencyKey = $headers['X-Idempotency-Key'] ?? $headers['x-idempotency-key'] ?? null;
        $autoGenerated = false;

        if ($idempotencyKey === null || trim((string) $idempotencyKey) === '') {
            $idempotencyKey = AuditEvent::uuidV4();
            $autoGenerated = true;
            Logger::warning('POST /audit/async sin X-Idempotency-Key. Generando barrera temporal para esta solicitud.', [
                'generated_key' => $idempotencyKey,
                'fac_nit_sec'   => $facNitSec,
            ]);
        }

        $jobStore = $this->buildBatchJobStore();
        $jobId = AuditEvent::uuidV4();
        $ttl = (int) \Core\Env::get('AUDIT_IDEMPOTENCY_KEY_TTL', 300);

        try {
            $existingJobId = $jobStore->claimIdempotencyKey((string) $idempotencyKey, $jobId, $ttl);
        } catch (\RuntimeException $e) {
            Response::error('Error validando idempotencia: ' . $e->getMessage(), 503);
        }

        if ($existingJobId !== null) {
            Response::success(
                ['job_id' => $existingJobId],
                'Solicitud ya registrada',
                409
            );
        }

        try {
            $jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        } catch (\RuntimeException $e) {
            Response::error('Error inicializando job batch: ' . $e->getMessage(), 503);
        }

        $publisher = $this->buildEventPublisher();
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_REQUESTED,
            auditId: null,
            jobId: $jobId,
            documentId: null,
            payload: [
                'fac_nit_sec' => (string) $facNitSec,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'limit'       => $limit,
            ]
        );

        try {
            $publisher->publish($event);
        } catch (\RuntimeException $e) {
            Response::error('Error publicando evento batch_requested: ' . $e->getMessage(), 503);
        }

        $responseData = [
            'job_id' => $jobId,
            'status' => BatchJobStore::JOB_STATUS_PENDING,
        ];
        if ($autoGenerated) {
            $responseData['idempotency_key'] = $idempotencyKey;
        }

        Response::success(
            $responseData,
            'Batch de auditoría encolado',
            202
        );
    }

    public function jobStatus(string $jobId): void
    {
        if (!AuditEvent::isUuidV4($jobId)) {
            Response::error('jobId inválido', 422);
        }

        try {
            $state = $this->buildBatchJobStore()->getJob($jobId);
        } catch (RuntimeException $e) {
            Logger::error('AuditController::jobStatus falló', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo consultar el estado del job', 503);
        }

        if ($state === null) {
            Response::error('No se encontró el job solicitado', 404);
        }

        Response::success(self::formatJobStatus($state), 'Estado del job');
    }

    private static function formatJobStatus(array $state): array
    {
        $total = (int) ($state['total'] ?? 0);
        $done = (int) ($state['done'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);
        $pending = max(0, $total - $done - $failed);
        $performance = self::formatJobPerformance($state, $done + $failed);

        $audits = [];
        $auditsMap = is_array($state['audits'] ?? null) ? $state['audits'] : [];
        foreach ($auditsMap as $auditId => $auditData) {
            if (!is_string($auditId) || !is_array($auditData)) {
                continue;
            }
            $audits[] = [
                'audit_id'    => $auditId,
                'dis_det_nro' => (string) ($auditData['dis_det_nro'] ?? ''),
                'status'      => (string) ($auditData['status'] ?? AuditStateStore::AUDIT_STATUS_PENDING),
            ];
        }

        return [
            'job_id'     => (string) ($state['job_id'] ?? ''),
            'status'     => (string) ($state['status'] ?? BatchJobStore::JOB_STATUS_PENDING),
            'total'      => $total,
            'done'       => $done,
            'failed'     => $failed,
            'pending'    => $pending,
            'avg_duration_ms' => $performance['avg_duration_ms'],
            'accumulated_duration_ms' => $performance['accumulated_duration_ms'],
            'throughput_per_sec' => $performance['throughput_per_sec'],
            'created_at' => (string) ($state['created_at'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'audits'     => $audits,
        ];
    }

    /**
     * @param  array<string,mixed> $state
     * @return array{avg_duration_ms:int,accumulated_duration_ms:int,throughput_per_sec:float}
     */
    private static function formatJobPerformance(array $state, int $processed): array
    {
        $accumulatedDurationMs = max(0, (int) ($state['accumulated_duration_ms'] ?? 0));
        $avgDurationMs = max(0, (int) ($state['avg_duration_ms'] ?? 0));
        $throughput = 0.0;

        if ($processed > 0 && $accumulatedDurationMs > 0) {
            $throughput = round($processed / ($accumulatedDurationMs / 1000), 2);
        }

        return [
            'avg_duration_ms' => $avgDurationMs,
            'accumulated_duration_ms' => $accumulatedDurationMs,
            'throughput_per_sec' => $throughput,
        ];
    }

    public function timings(string $facNro): void
    {
        $facNro = trim($facNro);
        if ($facNro === '') {
            Response::error('facNro es requerido', 422);
        }

        try {
            $row = $this->buildAuditStatusModel()->getTimingsByFacNro($facNro);
        } catch (\RuntimeException $e) {
            Logger::error('AuditController::timings falló', [
                'fac_nro' => $facNro,
                'error'   => $e->getMessage(),
            ]);
            Response::error('No se pudieron obtener las métricas de fase', 503);
        }

        if ($row === null) {
            Response::error('Auditoría no encontrada o sin métricas de fase persistidas', 404);
        }

        Response::success($row, 'Métricas de fase de auditoría');
    }

    protected function buildAuditStatusModel(): AuditStatusModel
    {
        return new AuditStatusModel();
    }

    protected function buildAuditDataService(): AuditDataService
    {
        return new AuditDataService();
    }

    protected function buildStateStore(): AuditStateStore
    {
        return new AuditStateStore();
    }

    protected function buildEventPublisher(): AuditEventPublisher
    {
        return new AuditEventPublisher();
    }

    protected function buildBatchJobStore(): BatchJobStore
    {
        return new BatchJobStore();
    }
}
