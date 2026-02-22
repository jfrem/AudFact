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
        $limit = $params['limit'] ?? null;

        if ($facNitSec === null || $date === null) {
            return ['success' => false, 'status' => 400, 'error' => 'facNitSec y date son requeridos'];
        }

        $query = [
            'facNitSec' => $facNitSec,
            'date' => $date
        ];
        if ($limit !== null) {
            $query['limit'] = $limit;
        }

        return $client->get('/invoices', $query);
    }
}
