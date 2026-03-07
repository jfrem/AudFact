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
use GuzzleHttp\Client;
use Core\Response;
use Core\Logger;

class AuditController extends Controller
{
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
            'limit' => "required|integer|min_value:1|max_value:{$batchMaxLimit}",
        ]);

        Logger::info("AuditController: Received request with parameters: " . json_encode($data));

        $facNitSec = (int)$data['facNitSec'];
        $date = (string)$data['date'];
        $limit = (int)$data['limit'];

        $invoices = (new InvoicesModel())->getInvoices($facNitSec, $date, $limit);
        Logger::info("AuditController: Retrieved " . count($invoices) . " invoices for facNitSec={$facNitSec}, date={$date}, limit={$limit}");
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
            'FacNro' => 'required|string|min_length:1',
        ]);

        Logger::info("AuditController::single: Received request with parameters: " . json_encode($data));

        $FacNro = (string)$data['FacNro'];
        $auditor = $this->buildAuditOrchestrator();

        $result = $auditor->auditInvoice($FacNro, $FacNro, null);

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
            $validated['dateTo'] !== '' &&
            $validated['dateFrom'] > $validated['dateTo']
        ) {
            Response::error('dateFrom no puede ser mayor que dateTo', 422);
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

        $model = new AuditStatusModel();
        $total = $model->countAudits($filters);
        $results = $model->searchAudits($filters, $page, $pageSize);
        $totalPages = (int)ceil($total / $pageSize);

        Response::success([
            'items'      => $results,
            'total'      => $total,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalPages' => $totalPages,
            'filters'    => $filters,
        ], 'Resultados de auditorías');
    }

    private function buildAuditOrchestrator(): AuditOrchestrator
    {
        $apiKey = (string) \Core\Env::get('GEMINI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY no configurada');
        }

        $model = (string) \Core\Env::get('GEMINI_MODEL', '');
        if ($model === '') {
            throw new \RuntimeException('GEMINI_MODEL no está configurada en .env');
        }

        $timeout = (int) \Core\Env::get('GEMINI_TIMEOUT', 60);
        $httpClient = new Client(['timeout' => $timeout > 0 ? $timeout : 60]);

        $maxOutputTokens = (int) \Core\Env::get('GEMINI_MAX_OUTPUT_TOKENS', 0);
        if ($maxOutputTokens <= 0) {
            throw new \RuntimeException('GEMINI_MAX_OUTPUT_TOKENS no está configurada o es inválida en .env');
        }

        $responseMimeType = (string) \Core\Env::get('GEMINI_RESPONSE_MIME', '');
        if ($responseMimeType === '') {
            throw new \RuntimeException('GEMINI_RESPONSE_MIME no está configurada en .env');
        }

        $temperature = \Core\Env::get('GEMINI_TEMPERATURE');
        $topP = \Core\Env::get('GEMINI_TOP_P');
        $topK = \Core\Env::get('GEMINI_TOP_K');
        $thinkingBudget = \Core\Env::get('GEMINI_THINKING_BUDGET');

        $gateway = new GeminiGateway(
            $httpClient,
            $apiKey,
            $model,
            ($temperature !== null && $temperature !== '') ? (float) $temperature : null,
            ($topP !== null && $topP !== '') ? (float) $topP : null,
            ($topK !== null && $topK !== '') ? (int) $topK : null,
            $maxOutputTokens,
            $responseMimeType,
            \Core\Env::get('GEMINI_MEDIA_RESOLUTION') ?: null,
            ($thinkingBudget !== null && $thinkingBudget !== '') ? (int) $thinkingBudget : null
        );

        $dispensationModel = new DispensationModel();
        $attachmentsModel = new AttachmentsModel();
        $fileManager = new AuditFileManager();
        $persistence = new AuditPersistenceService(new AuditStatusModel());

        $preValidator = new AuditPreValidator(
            $dispensationModel,
            $attachmentsModel,
            $fileManager,
            $persistence
        );

        return new AuditOrchestrator(
            $fileManager,
            new AuditPromptBuilder(),
            new AuditResultValidator(),
            new JsonResponseParser(),
            $gateway,
            $persistence,
            new AuditTelemetryService(),
            $preValidator
        );
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
}
