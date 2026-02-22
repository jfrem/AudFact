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
            Logger::warning('AuditResultValidator: Data is not an array', ['data' => $data]);
            return false;
        }

        $validation = AuditResponseSchema::validate($data);
        
        if (!$validation['valid']) {
            Logger::warning('AuditResultValidator: Schema validation failed', [
                'errors' => $validation['errors'],
                'data' => $data
            ]);
        }

        return $validation['valid'];
    }

    /**
     * Valida y retorna errores detallados si falla la validación.
     * Útil para debugging y mejora de prompts.
     *
     * @param array|null $data Datos a validar
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateWithErrors(?array $data): array
    {
        if (!is_array($data)) {
            return [
                'valid' => false,
                'errors' => ['Data must be an array, got: ' . gettype($data)]
            ];
        }

        return AuditResponseSchema::validate($data);
    }

    /**
     * Verifica rápidamente si tiene la estructura mínima esperada.
     * Útil para validación preliminar antes de processing completo.
     *
     * @param mixed $data Datos a verificar
     * @return bool
     */
    public function hasBasicStructure($data): bool
    {
        return is_array($data)
            && isset($data['response'])
            && isset($data['message'])
            && isset($data['documento'])
            && isset($data['data']['items']);
    }

    // Methods dealing with JSON extraction and repair have been moved to JsonResponseParser
}
