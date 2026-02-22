<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetClients
{
    public function execute(array $params): array
    {
        $client = new ApiClient();

        if (!empty($params['clientId'])) {
            return $client->get('/clients/' . urlencode((string)$params['clientId']));
        }

        return $client->get('/clients');
    }
}
