<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetInvoices
{
    public function execute(array $params): array
    {
        $client = new ApiClient();

        $facNitSec = $params['facNitSec'] ?? null;
        $date = $params['date'] ?? null;
        $page = $params['page'] ?? null;
        $pageSize = $params['pageSize'] ?? null;

        if ($facNitSec === null || $date === null) {
            return ['success' => false, 'status' => 400, 'error' => 'facNitSec y date son requeridos'];
        }

        $query = [
            'facNitSec' => $facNitSec,
            'dateFrom' => $date
        ];
        if ($page !== null) {
            $query['page'] = $page;
        }
        if ($pageSize !== null) {
            $query['pageSize'] = $pageSize;
        }

        return $client->get('/invoices', $query);
    }
}
