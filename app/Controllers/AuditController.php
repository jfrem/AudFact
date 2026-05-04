<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Models\InvoicesModel;
use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\ClientConfigurationService;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\Exceptions\HttpResponseException;
use Core\Cache;
use Core\Response;
use Core\Logger;
use RuntimeException;

class AuditController extends Controller
{
    public function single(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255',
        ]);

        $disDetNro = trim((string) $data['DisDetNro']);
        if ($disDetNro === '') {
            Response::error('DisDetNro es requerido', 422);
        }

        $stateStore = $this->buildStateStore();
        $publisher = $this->buildEventPublisher();

        $auditId = AuditEvent::uuidV4();
        $auditInitialized = false;

        try {
            $initialized = $stateStore->initAudit($auditId, $disDetNro);
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
                    'fac_nit_sec'  => null,
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
            ],
            'Auditoría encolada',
            202,
        );
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
                $results = $model->searchAudits($filters, $page, $pageSize);
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
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string) $data['dateTo'] : null;

        if ($dateTo !== null) {
            $dtFrom = \DateTime::createFromFormat('Y-m-d', $dateFrom);
            $dtTo = \DateTime::createFromFormat('Y-m-d', $dateTo);
            if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                Response::error('dateTo debe ser mayor o igual a date', 422);
            }
        }

        $facNitSec = (int) $data['facNitSec'];
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        $orchestrator = new AuditBatchOrchestrator(
            $this->buildStateStore(),
            $this->buildBatchJobStore(),
            $this->buildEventPublisher(),
            $this->getInvoicesModel()
        );

        try {
            $result = $orchestrator->enqueueBatch($facNitSec, $dateFrom, $dateTo, $limit);
        } catch (RuntimeException $e) {
            $code = $e->getCode() !== 0 ? $e->getCode() : 500;
            Response::error($e->getMessage(), $code);
        }

        Response::success(
            $result,
            'Batch de auditoría encolado',
            202,
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
            'created_at' => (string) ($state['created_at'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'audits'     => $audits,
        ];
    }



    public function configByClient(string $clientId): void
    {
        $service = new ClientConfigurationService($this->buildAuditStatusModel(), new AttachmentsModel());
        $config = $service->getConfigForClient($clientId);

        if ($config === null) {
            Response::error('Cliente no encontrado o sin configuración', 404);
        }

        Response::success($config, 'Configuración de auditoría recuperada');
    }

    public function saveAuditConfig(string $clientId): void
    {
        $data = $this->getJsonBody();
        
        $service = new ClientConfigurationService($this->buildAuditStatusModel(), new AttachmentsModel());
        $success = $service->saveConfigForClient($clientId, $data);

        if ($success) {
            Response::success(null, 'Configuración guardada exitosamente');
        } else {
            Response::error('Error al guardar la configuración', 500);
        }
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

    protected function getInvoicesModel(): InvoicesModel
    {
        return new InvoicesModel();
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
