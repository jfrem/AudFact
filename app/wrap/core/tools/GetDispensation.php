<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetDispensation
{
    public function execute(array $params): array
    {
        $invoiceId = $params['invoiceId'] ?? null;
        if ($invoiceId === null || trim((string)$invoiceId) === '') {
            return ['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido'];
        }

        $client = new ApiClient();

        return $client->get('/dispensation/' . urlencode((string)$invoiceId));
    }
}
