<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditCostModel;
use App\Services\Finance\CurrencyExchangeService;
use Core\Response;

class AuditCostController extends Controller
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    private AuditCostModel $auditCostModel;
    private CurrencyExchangeService $currencyService;

    public function __construct(
        ?AuditCostModel $auditCostModel = null,
        ?CurrencyExchangeService $currencyService = null
    ) {
        $this->auditCostModel = $auditCostModel ?? new AuditCostModel();
        $this->currencyService = $currencyService ?? new CurrencyExchangeService();
    }

    /**
     * Reporte paginado de costos por intento individual.
     * GET /audit/costs
     */
    public function index(): void
    {
        $queryParams = $this->getQueryParams();

        $page = isset($queryParams['page']) && is_numeric($queryParams['page'])
            ? max(1, (int) $queryParams['page'])
            : self::DEFAULT_PAGE;

        $pageSize = isset($queryParams['pageSize']) && is_numeric($queryParams['pageSize'])
            ? max(1, min(self::MAX_PAGE_SIZE, (int) $queryParams['pageSize']))
            : self::DEFAULT_PAGE_SIZE;

        $filters = $this->extractFilters($queryParams, ['dateFrom', 'dateTo', 'facNitSec', 'facNro']);
        $report = $this->auditCostModel->getDetailedCostsReport($filters, $page, $pageSize);
        $trmData = $this->currencyService->getUsdCopRate();

        Response::success([
            'trm_vigente_hoy' => $trmData['trm'],
            'trm_fuente' => $trmData['source'],
            'pagination' => $report['pagination'],
            'totales_periodo' => $report['totales_periodo'],
            'items' => $report['items'],
        ]);
    }

    /**
     * Agrupación y totalización del gasto acumulado por EPS / Cliente.
     * GET /audit/costs/clients
     */
    public function byClients(): void
    {
        $queryParams = $this->getQueryParams();
        $filters = $this->extractFilters($queryParams, ['dateFrom', 'dateTo', 'facNitSec']);
        $items = $this->auditCostModel->getCostsByClient($filters);

        Response::success([
            'items' => $items,
        ]);
    }

    /**
     * Inspección en vivo de la TRM vigente y origen de datos.
     * GET /audit/costs/trm
     */
    public function currentTrm(): void
    {
        $trmData = $this->currencyService->getUsdCopRate();
        Response::success($trmData);
    }

    /**
     * Historial de intentos y costos para una factura específica.
     * GET /audit/costs/{facNro}
     */
    public function byInvoice(string $facNro): void
    {
        $this->validateArray(['facNro' => $facNro], [
            'facNro' => 'required'
        ]);

        $items = $this->auditCostModel->getCostsByInvoice($facNro);

        if (empty($items)) {
            Response::error("No se encontraron registros de costos para la factura '{$facNro}'", 404);
            return;
        }

        $summary = $this->summarizeInvoiceCosts($items);

        Response::success([
            'fac_nro' => $facNro,
            'total_intentos' => count($items),
            'gran_total_tokens' => $summary['total_tokens'],
            'costo_total_acumulado_usd' => $summary['costo_usd'],
            'costo_total_acumulado_cop' => $summary['costo_cop'],
            'intentos' => $items,
        ]);
    }

    /**
     * Extrae y tipa filtros de búsqueda permitidos a partir de los parámetros de consulta.
     *
     * @param array<string, mixed> $queryParams
     * @param array<int, string> $allowedKeys
     * @return array<string, string>
     */
    private function extractFilters(array $queryParams, array $allowedKeys): array
    {
        $filters = [];
        foreach ($allowedKeys as $key) {
            if (!empty($queryParams[$key])) {
                $filters[$key] = (string) $queryParams[$key];
            }
        }

        return $filters;
    }

    /**
     * Totaliza tokens y montos consolidados a través de múltiples intentos de una factura.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{total_tokens: int, costo_usd: float, costo_cop: float}
     */
    private function summarizeInvoiceCosts(array $items): array
    {
        $totalUsd = 0.0;
        $totalCop = 0.0;
        $totalTokens = 0;

        foreach ($items as $item) {
            $totalUsd += (float) ($item['costo_usd'] ?? 0);
            $totalCop += (float) ($item['costo_cop'] ?? 0);
            $totalTokens += (int) ($item['total_tokens'] ?? 0);
        }

        return [
            'total_tokens' => $totalTokens,
            'costo_usd' => round($totalUsd, 8),
            'costo_cop' => round($totalCop, 4),
        ];
    }
}
