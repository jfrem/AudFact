<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetAttachments
{
    public function execute(array $params): array
    {
        $client = new ApiClient();

        if (!empty($params['attachmentId'])) {
            $invoiceId = $params['invoiceId'] ?? null;
            if ($invoiceId === null || trim((string)$invoiceId) === '') {
                return ['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido para descargar por attachmentId'];
            }

            return $client->get(
                '/dispensation/' . urlencode((string)$invoiceId) . '/attachments/download/' . urlencode((string)$params['attachmentId']),
                [],
                ['Accept: application/json']
            );
        }

        $invoiceId = $params['invoiceId'] ?? null;
        if ($invoiceId === null || trim((string)$invoiceId) === '') {
            return ['success' => false, 'status' => 400, 'error' => 'invoiceId es requerido'];
        }

        $nitSec = $params['nitSec'] ?? null;
        if ($nitSec === null || trim((string)$nitSec) === '') {
            return ['success' => false, 'status' => 400, 'error' => 'nitSec es requerido para listar adjuntos'];
        }

        return $client->get(
            '/dispensation/' . urlencode((string)$invoiceId) . '/attachments/' . urlencode((string)$nitSec)
        );
    }
}
