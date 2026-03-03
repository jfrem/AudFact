<?php

namespace App\Services\Audit;

use Core\Logger;

/**
 * Validador de respuestas de auditoría con IA.
 * Valida que las respuestas de Gemini AI cumplan con el schema formal definido.
 *
 * @version 2.0
 * @date 2026-02-09 - Actualizado para validación estricta con AuditResponseSchema
 */
class AuditResultValidator
{
    /**
     * Valida que los datos cumplan con el schema de auditoría.
     * Usa AuditResponseSchema para validación formal.
     *
     * @param array|null $data Datos a validar
     * @return bool True si es válido, False si no
     */
    public function isValid(?array $data): bool
    {
        if (!is_array($data)) {
            Logger::warning('AuditResultValidator: Data is not an array', [
                'receivedType' => gettype($data)
            ]);
            return false;
        }

        $validation = AuditResponseSchema::validate($data);

        if (!$validation['valid']) {
            Logger::warning('AuditResultValidator: Schema validation failed', [
                'errors' => $validation['errors'],
                'responseType' => $data['response'] ?? null,
                'itemsCount' => count($data['data']['items'] ?? [])
            ]);
        }

        return $validation['valid'];
    }

    // Methods dealing with JSON extraction and repair have been moved to JsonResponseParser
}
