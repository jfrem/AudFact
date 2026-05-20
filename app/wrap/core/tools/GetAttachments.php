<?php

namespace App\wrap\core\tools;

use App\wrap\core\ApiClient;

class GetAttachments
{
    public function execute(array $params): array
    {
        if (!empty($params['attachmentId'])) {
            $disDetNro = $this->requiredString($params, 'DisDetNro');
            if ($disDetNro === null) {
                return $this->validationError('DisDetNro es requerido para descargar por attachmentId');
            }

            return (new ApiClient())->get(
                '/dispensation/' . urlencode($disDetNro) . '/attachments/download/' . urlencode((string)$params['attachmentId']),
                [],
                ['Accept: application/json']
            );
        }

        $disDetNro = $this->requiredString($params, 'DisDetNro');
        if ($disDetNro === null) {
            return $this->validationError('DisDetNro es requerido');
        }

        $nitSec = $this->requiredString($params, 'nitSec');
        if ($nitSec === null) {
            return $this->validationError('nitSec es requerido para listar adjuntos');
        }

        return (new ApiClient())->get(
            '/dispensation/' . urlencode($disDetNro) . '/attachments/' . urlencode($nitSec)
        );
    }

    private function requiredString(array $params, string $key): ?string
    {
        $value = trim((string) ($params[$key] ?? ''));
        return $value === '' ? null : $value;
    }

    private function validationError(string $message): array
    {
        return ['success' => false, 'status' => 400, 'error' => $message];
    }
}
