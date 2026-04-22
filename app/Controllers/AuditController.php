<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Models\AuditStatusModel;
use App\Services\Audit\AuditOrchestratorFactory;
use App\Services\Audit\AuditQueueService;
use Core\Cache;
use Core\Response;
use Core\Logger;
use RuntimeException;
use InvalidArgumentException;
use OutOfBoundsException;

class AuditController extends Controller
{
    /**
     * POST /audit/single — Ejecuta una auditoría individual por DisDetNro.
     *
     * Pipeline v4 determinista (full PHP):
     *   Fase 1: Extracción (Gemini Vision + Function Calling)
     *   Fase 2: Comparación Semántica (Embedding API)
     *   Fase 3: Evaluación Determinista (RuleEngine PHP)
     */
    public function single(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255',
        ]);

        $dispensationId = (string) $data['DisDetNro'];
        $maskedId = '***' . substr($dispensationId, -3);

        try {
            $orchestrator = AuditOrchestratorFactory::create();
            $result = $orchestrator->auditInvoice($dispensationId, $dispensationId);

            Response::success($result, 'Auditoría completada');
        } catch (OutOfBoundsException $e) {
            Response::error($e->getMessage(), 404);
        } catch (InvalidArgumentException $e) {
            Logger::warning('AuditController::single invalid input', [
                'dispensationId' => $maskedId,
                'error' => $e->getMessage(),
            ]);
            Response::error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            Logger::error('AuditController::single pipeline failed', [
                'dispensationId' => $maskedId,
                'error' => $e->getMessage(),
            ]);
            Response::error('Error en el pipeline de auditoría. Intente nuevamente.', 503);
        }
    }

    /**
     * GET /audit/results — Consulta auditorías persistidas con filtros opcionales y paginación.
     * Query params: facNitSec, facNro, dateFrom, dateTo, page, pageSize
     */
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

    /**
     * GET /audit/documents-history — Historial completo de documentos auditados.
     */
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

    /**
     * POST /audit/async — Encola un job batch para procesamiento asíncrono.
     */
    public function async(): void
    {
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => 'nullable|integer|min_value:1|max_value:1000',
        ]);

        if (isset($data['dateTo']) && $data['dateTo'] !== '') {
            $dtFrom = \DateTime::createFromFormat('Y-m-d', (string) $data['date']);
            $dtTo = \DateTime::createFromFormat('Y-m-d', (string) $data['dateTo']);
            if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                Response::error('date no puede ser mayor que dateTo', 422);
            }
        }

        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string) $data['dateTo'] : null;
        $limit = isset($data['limit']) ? (int) $data['limit'] : 100;

        try {
            $job = $this->buildAuditQueueService()->enqueueBatch(
                facNitSec: (int) $data['facNitSec'],
                dateFrom: (string) $data['date'],
                dateTo: $dateTo,
                limit: $limit,
            );

            Response::success($job, 'Job encolado', 202);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409);
        } catch (OutOfBoundsException $e) {
            Response::error($e->getMessage(), 404);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            Logger::error('AuditController::async queue failed', ['error' => $e->getMessage()]);
            Response::error($e->getMessage(), 503);
        }
    }

    /**
     * GET /audit/jobs/{jobId} — Consulta el estado de un job async.
     */
    public function jobStatus(string $jobId): void
    {
        $this->validateArray(['jobId' => $jobId], [
            'jobId' => 'required|string|max:64',
        ]);

        try {
            $status = $this->buildAuditQueueService()->getJobStatus($jobId);
            if ($status === null) {
                Response::error('No se encontró el job solicitado o ya expiró.', 404);
            }

            Response::success($status, 'Estado del job');
        } catch (RuntimeException $e) {
            Logger::error('AuditController::jobStatus failed', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo consultar el estado del job.', 503);
        }
    }

    // ─── Factory Methods ─────────────────────────────────────────

    protected function buildAuditStatusModel(): AuditStatusModel
    {
        return new AuditStatusModel();
    }

    protected function buildAuditQueueService(): AuditQueueService
    {
        return new AuditQueueService();
    }
}
