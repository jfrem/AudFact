<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\InvoicesModel;
use Core\Response;

class InvoicesController extends Controller
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct()
    {
        $this->model = new InvoicesModel();
    }

    public function index(): void
    {
        $payload = $this->buildPaginatedInvoiceResponse(
            $this->validateQuery($this->invoiceSearchRules())
        );

        Response::success($payload, 'Facturas encontradas');
    }

    public function search(): void
    {
        $payload = $this->buildPaginatedInvoiceResponse(
            $this->validate($this->invoiceSearchRules())
        );

        Response::success($payload, 'Facturas encontradas');
    }

    /**
     * @return array<string,string>
     */
    private function invoiceSearchRules(): array
    {
        return [
            'facNitSec' => 'required|integer|min_value:1',
            'dateFrom' => 'required|date',
            'dateTo' => 'optional|date',
            'page' => 'optional|integer|min_value:1',
            'pageSize' => 'optional|integer|min_value:1|max_value:100',
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{facNitSec:int,dateFrom:string,dateTo:string}
     */
    private function normalizeInvoiceFilters(array $data): array
    {
        $dateFrom = (string) $data['dateFrom'];
        $dateTo = $this->resolveDateTo($data, $dateFrom);
        $this->assertValidDateRange($dateFrom, $dateTo);

        return [
            'facNitSec' => (int) $data['facNitSec'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pageSize:int,totalPages:int,filters:array{facNitSec:int,dateFrom:string,dateTo:string}}
     */
    private function buildPaginatedInvoiceResponse(array $data): array
    {
        $filters = $this->normalizeInvoiceFilters($data);
        $page = $this->resolvePage($data);
        $pageSize = $this->resolvePageSize($data);

        $total = $this->model->countInvoices($filters);
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;
        $effectivePage = ($totalPages > 0 && $page > $totalPages) ? $totalPages : $page;
        $items = $total > 0 ? $this->model->searchInvoices($filters, $effectivePage, $pageSize) : [];

        return [
            'items' => $items,
            'total' => $total,
            'page' => $total > 0 ? $effectivePage : self::DEFAULT_PAGE,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'filters' => $filters,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolveDateTo(array $data, string $dateFrom): string
    {
        $dateTo = $data['dateTo'] ?? null;
        return ($dateTo !== null && $dateTo !== '') ? (string) $dateTo : $dateFrom;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolvePage(array $data): int
    {
        $page = $data['page'] ?? null;
        return ($page !== null && $page !== '') ? (int) $page : self::DEFAULT_PAGE;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function resolvePageSize(array $data): int
    {
        $pageSize = $data['pageSize'] ?? null;
        return ($pageSize !== null && $pageSize !== '') ? (int) $pageSize : self::DEFAULT_PAGE_SIZE;
    }

    private function assertValidDateRange(string $dateFrom, string $dateTo): void
    {
        $dtFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $dtTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if ($dtFrom instanceof \DateTimeImmutable && $dtTo instanceof \DateTimeImmutable && $dtFrom > $dtTo) {
            Response::error('dateFrom no puede ser mayor que dateTo', 422);
        }
    }
}
