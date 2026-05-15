<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditConfigModel;
use App\Services\Audit\AuditFieldValueType;
use Core\Response;

/**
 * API REST para leer y reemplazar la configuración de auditoría por cliente.
 */
class AuditConfigController extends Controller
{
    /** Campos técnicos internos que la UI nunca debe permitir configurar */
    private const EXCLUDED_FIELDS = ['FacSec', 'NitSec'];

    /** Severidades válidas para campos auditables */
    private const VALID_SEVERITIES = ['ALTA', 'MEDIA', 'BAJA'];

    public function __construct()
    {
        $this->model = new AuditConfigModel();
    }

    // -------------------------------------------------------------------------
    // GET /clients/{clientId}/audit-config
    // -------------------------------------------------------------------------

    /**
     * Retorna la configuración de auditoría del cliente.
     * Si el cliente no tiene configuración guardada aún, retorna 404.
     */
    public function show(string $clientId): void
    {
        $this->validateArray(['clientId' => $clientId], [
            'clientId' => 'required|integer|min_value:1',
        ]);

        $config = $this->model->getConfig($clientId);

        if ($config === null) {
            Response::error(
                'Este cliente no tiene configuración de auditoría. '
                    . 'Usa POST con una factura de muestra para inicializarlo.',
                404
            );
        }

        Response::success($config, 'Configuración de auditoría recuperada');
    }

    // -------------------------------------------------------------------------
    // POST /clients/{clientId}/audit-config
    // -------------------------------------------------------------------------

    /**
     * Guarda (o reemplaza completamente) la configuración de un cliente.
     *
     * La UI envía SOLO los campos en ON (toggles activos).
     * Esta operación hace DELETE + INSERT — es un reemplazo total, no un merge parcial.
     */
    public function save(string $clientId): void
    {
        $this->validateArray(['clientId' => $clientId], [
            'clientId' => 'required|integer|min_value:1',
        ]);

        $body = $this->getBody();

        if (!isset($body['fields']) || !is_array($body['fields'])) {
            Response::error(
                'El campo "fields" es requerido y debe ser un array.',
                422
            );
        }

        $systemPrompt = isset($body['systemPrompt']) && is_string($body['systemPrompt'])
            ? trim($body['systemPrompt'])
            : null;

        $sanitizedFields = $this->sanitizeFields($body['fields']);

        $this->model->saveConfig($clientId, $sanitizedFields, $systemPrompt ?: null);

        Response::success(
            ['fieldCount' => count($sanitizedFields)],
            'Configuración de auditoría guardada correctamente'
        );
    }

    // -------------------------------------------------------------------------
    // PRIVADOS
    // -------------------------------------------------------------------------

    /**
     * Valida y sanitiza el array de campos del payload.
     * - Rechaza campos excluidos (FacSec, NitSec).
     * - Valida tipoCampo como 'E', 'S', 'B', 'V'.
     * - Exige tipoDato para campos no visuales.
     * - Acepta description y severity para todos los campos.
     * - Limita CampoNombre a 100 caracteres alfanuméricos+guiones.
     *
     * @param  array $rawFields Body['fields'] sin sanitizar
     * @return array            Campos válidos listos para insertar
     */
    private function sanitizeFields(array $rawFields): array
    {
        $sanitized = [];
        $errors    = [];

        foreach ($rawFields as $idx => $field) {
            $pos = $idx + 1;

            if (!is_array($field)) {
                $errors[] = "Campo #{$pos}: debe ser un objeto.";
                continue;
            }

            try {
                $docId       = $this->sanitizeDocId($field);
                $campoNombre = $this->sanitizeCampoNombre($field);
                $tipoCampo   = $this->sanitizeTipoCampo($field);
                $tipoDato    = $this->sanitizeTipoDato($field, $tipoCampo);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = "Campo #{$pos}: {$exception->getMessage()}";
                continue;
            }

            $description = isset($field['description']) && is_string($field['description'])
                ? trim($field['description'])
                : null;

            $sanitized[] = [
                'docId'       => $docId,
                'campoNombre' => $campoNombre,
                'tipoCampo'   => $tipoCampo,
                'tipoDato'    => $tipoDato,
                'orden'       => (int) ($field['orden'] ?? 0),
                'description' => $description,
                'severity'    => $this->sanitizeSeverity($field),
            ];
        }

        if (!empty($errors)) {
            Response::error('Errores en los campos enviados', 422, $errors);
        }

        return $sanitized;
    }

    private function sanitizeDocId(array $field): int
    {
        if (!isset($field['docId']) || !is_numeric($field['docId'])) {
            throw new \InvalidArgumentException("'docId' requerido y numérico.");
        }

        return (int) $field['docId'];
    }

    private function sanitizeCampoNombre(array $field): string
    {
        if (empty($field['campoNombre']) || !is_string($field['campoNombre'])) {
            throw new \InvalidArgumentException("'campoNombre' requerido.");
        }

        $campoNombre = trim($field['campoNombre']);

        if (in_array($campoNombre, self::EXCLUDED_FIELDS, true)) {
            throw new \InvalidArgumentException("'{$campoNombre}' no es auditable.");
        }

        if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $campoNombre)) {
            throw new \InvalidArgumentException("'{$campoNombre}' contiene caracteres inválidos.");
        }

        return $campoNombre;
    }

    private function sanitizeTipoCampo(array $field): string
    {
        // tipoCampo: E=Exacto, S=Semántico, B=Negocio, V=Visual
        $tipoCampo = strtoupper(trim((string)($field['tipoCampo'] ?? '')));
        if (!in_array($tipoCampo, ['E', 'S', 'B', 'V'], true)) {
            throw new \InvalidArgumentException("'tipoCampo' inválido.");
        }

        return $tipoCampo;
    }

    private function sanitizeTipoDato(array $field, string $tipoCampo): ?string
    {
        if ($tipoCampo === 'V') {
            return null;
        }

        $rawTipoDato = trim((string) ($field['tipoDato'] ?? ''));
        if ($rawTipoDato === '') {
            throw new \InvalidArgumentException("'tipoDato' es requerido para campos no visuales.");
        }

        try {
            $valueType = AuditFieldValueType::fromInput($rawTipoDato);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException("'tipoDato' inválido.");
        }

        if (!$valueType->isAllowedForTipoCampo($tipoCampo)) {
            throw new \InvalidArgumentException($this->typeCombinationError($tipoCampo));
        }

        return $valueType->value;
    }

    private function typeCombinationError(string $tipoCampo): string
    {
        $allowedValues = AuditFieldValueType::allowedValuesForTipoCampo($tipoCampo);
        if ($allowedValues === []) {
            return "'tipoCampo' inválido.";
        }

        return "'tipoCampo={$tipoCampo}' solo permite 'tipoDato="
            . implode("', 'tipoDato=", $allowedValues)
            . "'.";
    }

    private function sanitizeSeverity(array $field): string
    {
        $severity = isset($field['severity']) && is_string($field['severity'])
            ? strtoupper(trim($field['severity']))
            : 'ALTA';

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            $severity = 'ALTA';
        }

        return strtolower($severity);
    }
}
