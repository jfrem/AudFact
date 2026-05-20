<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetDispensation
{
    public function execute(array $params): array
    {
        $disDetNro = $params['DisDetNro'] ?? null;
        if ($disDetNro === null || trim((string)$disDetNro) === '') {
            return ['success' => false, 'status' => 400, 'error' => 'DisDetNro es requerido'];
        }

        $client = new ApiClient();

        return $client->get('/dispensation/' . urlencode((string)$disDetNro));
    }
}
