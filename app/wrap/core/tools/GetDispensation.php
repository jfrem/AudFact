<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetDispensation
{
    public function execute(array $params): array
    {
        $client = new ApiClient();

        // Compatibilidad: aceptar invoiceId (preferido) y alias legacy.
        $invoiceId = $params['invoiceId'] ?? $params['DisDetNro'] ?? $params['facSec'] ?? null;
        if ($invoiceId === null || trim((string)$invoiceId) === '') {
            return ['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido'];
        }

        return $client->get('/dispensation/' . urlencode((string)$invoiceId));
    }
}
