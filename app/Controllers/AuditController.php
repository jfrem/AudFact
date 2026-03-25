<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Models\InvoicesModel;
use App\Models\AuditStatusModel;
use App\Models\DispensationModel;
use App\Services\Audit\AuditFileManager;
use App\Services\Audit\AuditOrchestrator;
use App\Services\Audit\AuditPersistenceService;
use App\Services\Audit\AuditPreValidator;
use App\Services\Audit\AuditPromptBuilder;
use App\Services\Audit\AuditResultValidator;
use App\Services\Audit\AuditTelemetryService;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\JsonResponseParser;
use App\Services\Audit\AuditQueueService;
use App\Services\Audit\AuditOrchestratorFactory;
use GuzzleHttp\Client;
use Core\Cache;
use Core\RedisClient;
use Core\Response;
use Core\Logger;

class AuditController extends Controller
{
    /**
     * Ejecuta una auditoría batch síncrona por cliente y rango de fechas.
     *
     * @return void
     */
    public function run(): void
    {
        // Leer configuración centralizada desde .env
        $batchTimeout = (int) \Core\Env::get('AUDIT_BATCH_TIMEOUT', 3600); // 1 hora
        $batchMaxLimit = (int) \Core\Env::get('AUDIT_BATCH_MAX_LIMIT', 100); // 100 facturas

        // C03: Prevenir timeout en auditorías masivas
        set_time_limit($batchTimeout);
        // C04: Proveer memoria suficiente para el array de resultados y procesamiento base64
        ini_set('memory_limit', '1024M');

        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => "required|integer|min_value:1|max_value:{$batchMaxLimit}",
        ]);

        if (isset($data['dateTo']) && $data['dateTo'] !== '') {
            $dtFrom = \DateTime::createFromFormat('Y-m-d', $data['date']);
            $dtTo = \DateTime::createFromFormat('Y-m-d', $data['dateTo']);
            if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                Response::error('date no puede ser mayor que dateTo', 422);
            }
        }

        Logger::info('AuditController::run — Request recibido', [
            'facNitSec' => $this->maskFacNitSec($data['facNitSec'] ?? null),
            'dateRange' => ($data['date'] ?? '?') . ' → ' . ($data['dateTo'] ?? '?'),
            'limit'     => $data['limit'] ?? null,
        ]);

        $facNitSec = (string) $data['facNitSec'];
        $date = (string)$data['date'];
        $dateTo = (isset($data['dateTo']) && $data['dateTo'] !== '') ? (string)$data['dateTo'] : null;
        $limit = (int)$data['limit'];

        $invoices = $this->getInvoicesModel()->getInvoices((int) $facNitSec, $date, $dateTo, $limit);
        Logger::info('AuditController::run — Facturas obtenidas', [
            'count'     => count($invoices),
            'dateRange' => $date . ' → ' . ($dateTo ?? 'null'),
        ]);
        if (empty($invoices)) {
            Response::success(['items' => []], 'No se encontraron facturas para los parámetros indicados.');
        }

        $auditor = $this->buildAuditOrchestrator();
        $results = [];
        // Circuit breaker de tiempo — valor centralizado desde .env
        $batchStartTime = time();
        $maxBatchDurationSeconds = $batchTimeout;
        $stoppedEarly = false;

        foreach ($invoices as $invoice) {
            // Circuit breaker: verificar si se excedió el tiempo máximo de batch
            if ((time() - $batchStartTime) > $maxBatchDurationSeconds) {
                Logger::warning('Circuit breaker activado — batch detenido por tiempo', [
                    'elapsed' => time() - $batchStartTime,
                    'processed' => count($results),
                    'total' => count($invoices),
                ]);
                $stoppedEarly = true;
                break;
            }

            $Dispensa = (string)($invoice['Dispensa'] ?? '');
            $facSec = (string)($invoice['FacSec'] ?? '');

            if ($Dispensa === '' || $facSec === '') {
                $results[] = [
                    'invoice' => $invoice,
                    'result' => [
                        'response' => 'error',
                        'message' => 'Factura inválida: Dispensa/FacSec faltante',
                        'data' => ['items' => []],
                    ],
                ];
                continue;
            }

            // Fase 1.2: Idempotencia — verificar si ya fue auditada exitosamente
            $cached = $this->getIdempotentResult($facSec);
            if ($cached !== null) {
                $idempotencySource = $cached['_idempotency_source'] ?? 'unknown';
                Logger::info('AuditController: idempotencia — factura ya auditada, reutilizando resultado', [
                    'FacSec' => $facSec,
                    'source' => $idempotencySource,
                ]);

                // Guardia de consistencia: si viene de Redis, verificar que exista en BD
                if ($idempotencySource === 'redis') {
                    $model = new AuditStatusModel();
                    $existing = $model->getByFacSec($facSec);
                    if ($existing === false || empty($existing)) {
                        Logger::warning('AuditController: resultado en Redis sin respaldo en BD, re-persistiendo', [
                            'FacSec' => $facSec,
                        ]);
                        $persistence = new AuditPersistenceService($model);
                        $cachedForPersist = $cached;
                        unset($cachedForPersist['_idempotency_source']);
                        // C-02: NumeroFactura debe asociarse a la factura real.
                        $facNro = (string) ($invoice['FacNro'] ?? $Dispensa);
                        $persistence->saveToDatabase($facSec, $cachedForPersist, [['FacSec' => $facSec, 'NitSec' => $invoice['NitSec'] ?? null, 'NumeroFactura' => $facNro]]);
                    }
                }

                unset($cached['_idempotency_source']);
                $results[] = [
                    'invoice' => $invoice,
                    'result'  => $cached,
                ];
                continue;
            }

            $results[] = [
                'invoice' => $invoice,
                'result' => $auditor->auditInvoice($facSec, $Dispensa, null),
            ];
        }

        $message = $stoppedEarly
            ? sprintf('Auditoría parcial: %d de %d facturas procesadas (tiempo límite alcanzado)', count($results), count($invoices))
            : 'Auditoría ejecutada';

        Response::success([
            'items' => $results,
            'stoppedEarly' => $stoppedEarly,
            'totalRequested' => count($invoices),
            'totalProcessed' => count($results),
        ], $message);
    }

    public function single(): void
    {
        // Validar y sanitizar los parámetros de entrada
        $data = $this->validate([
            'DisDetNro' => 'required|string|min_length:1',
        ]);

        Logger::info('AuditController::single — Request recibido', [
            'DisDetNro' => !empty($data['DisDetNro']) ? '***' . substr($data['DisDetNro'], -4) : null,
        ]);

        $disDetNro = (string)$data['DisDetNro'];
        $auditor = $this->buildAuditOrchestrator();

        // M-04 NOTE: En single(), DisDetNro sirve como invoiceId Y como disDetNro
        // porque el endpoint opera sobre un único punto de dispensación.
        $result = $auditor->auditInvoice($disDetNro, $disDetNro, null);

        Response::success($result, 'Auditoría individual completada');
    }

    /**
     * GET /audit/results — Consulta auditorías persistidas con filtros opcionales y paginación.
     * Query params: facNitSec, facNro, dateFrom, dateTo, page, pageSize
     */
    public function results(): void
    {
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
        $cacheKey = 'query:results:' . $facNitSecFilter . ':' . md5(json_encode([$filters, $page, $pageSize]));
        $cacheTtl = 60;

        $payload = Cache::remember($cacheKey, function () use ($filters, $page, $pageSize) {
            $model = new AuditStatusModel();
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
    }

    private function buildAuditOrchestrator(): AuditOrchestrator
    {
        return AuditOrchestratorFactory::create();
    }

    /**
     * Verifica si una factura ya fue auditada exitosamente.
     * Capa 1: Redis cache (rápido). Capa 2: SQL (fallback).
     *
     * @param string $facSec PK de la factura
     * @return array|null Resultado anterior si existe, null si no
     */
    private function getIdempotentResult(string $facSec): ?array
    {
        $cacheTTL = (int) \Core\Env::get('AUDIT_CACHE_TTL', 86400);
        $cacheKey = 'audit:result:' . $facSec;

        // Capa 1: Redis
        $redis = RedisClient::getInstance();
        if ($redis->isAvailable()) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== null) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        $decoded['_idempotency_source'] = 'redis';
                        return $decoded;
                    }
                }
            } catch (\Core\RedisUnavailableException $e) {
                // Redis falló durante GET: degradar a SQL capa 2
                \Core\Logger::warning('Idempotencia: Redis falló, cayendo a SQL', [
                    'facSec' => $facSec,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        // Capa 2: SQL fallback
        $model = new AuditStatusModel();
        $existing = $model->getByFacSec($facSec);

        if ($existing !== false && isset($existing['EstAud']) && (int) $existing['EstAud'] === 1) {
            // Auditada exitosamente en SQL → cachear en Redis para próximas consultas
            $result = [
                'response' => 'success',
                'message'  => 'Resultado reutilizado (idempotencia)',
                'data'     => $existing,
            ];

            if ($redis->isAvailable()) {
                $redis->set($cacheKey, json_encode($result), $cacheTTL);
            }

            $result['_idempotency_source'] = 'sql';
            return $result;
        }

        return null;
    }

    /**
     * Endpoint para consultar el historial completo de documentos auditados por Gemini IA integrando facturas
     * GET /audit/documents-history
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
     * POST /audit/async — Encola batch de auditoría para procesamiento async.
     * Retorna 202 Accepted + jobId inmediatamente.
     */
    public function async(): void
    {
        $batchMaxLimit = (int) \Core\Env::get('AUDIT_BATCH_MAX_LIMIT', 100);

        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'dateTo' => 'optional|date',
            'limit' => "required|integer|min_value:1|max_value:{$batchMaxLimit}",
        ]);

        if (isset($data['dateTo']) && $data['dateTo'] !== '') {
            $dtFrom = \DateTime::createFromFormat('Y-m-d', $data['date']);
            $dtTo = \DateTime::createFromFormat('Y-m-d', $data['dateTo']);
            if ($dtFrom && $dtTo && $dtFrom > $dtTo) {
                Response::error('date no puede ser mayor que dateTo', 422);
            }
        }

        $queueService = $this->buildQueueService();
        $jobId = $queueService->enqueue($data);

        if ($jobId === null) {
            Response::error('Cola de auditoría no disponible. Use POST /audit para procesamiento síncrono.', 503);
        }

        Logger::info('AuditController::async — Job encolado', [
            'jobId'     => $jobId,
            'facNitSec' => $this->maskFacNitSec($data['facNitSec'] ?? null),
            'dateRange' => ($data['date'] ?? '?') . ' → ' . ($data['dateTo'] ?? '?'),
        ]);

        Response::success([
            'jobId'       => $jobId,
            'status'      => 'pending',
            'statusUrl'   => "/audit/jobs/{$jobId}",
            'queueDepth'  => $queueService->queueDepth(),
        ], 'Auditoría encolada para procesamiento asíncrono', 202);
    }

    /**
     * GET /audit/jobs/{jobId} — Consulta el estado de un job async.
     */
    public function jobStatus(string $jobId): void
    {
        if (empty($jobId) || !preg_match('/^[a-f0-9]{32,64}$/i', $jobId)) {
            Response::error('jobId inválido', 400);
        }

        $queueService = new AuditQueueService();
        $job = $queueService->getJobStatus($jobId);

        if ($job === null) {
            Response::error('Job no encontrado o expirado', 404);
        }

        Response::success($job, 'Estado del job de auditoría');
    }

    protected function getInvoicesModel(): InvoicesModel
    {
        return new InvoicesModel();
    }

    protected function buildQueueService(): AuditQueueService
    {
        return new AuditQueueService();
    }

    private function maskFacNitSec(mixed $facNitSec): ?string
    {
        if ($facNitSec === null || $facNitSec === '') {
            return null;
        }

        $value = trim((string) $facNitSec);
        if ($value === '') {
            return null;
        }

        return '***' . substr($value, -3);
    }
}
