<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditConfigModel;
use Core\Response;

/**
 * Gestiona la configuración de auditoría por cliente (Sample-Driven).
 *
 * Endpoints:
 *   GET  /clients/{clientId}/audit-config  → Retorna configuración guardada
 *   POST /clients/{clientId}/audit-config  → Guarda/reemplaza configuración
 *
 * Payload POST esperado:
 * {
 *   "fields": [
 *     {"docId": 1, "campoNombre": "NumeroFactura", "tipoCampo": "D", "orden": 1},
 *     {"docId": 1, "campoNombre": "FirmaActaEntrega", "tipoCampo": "V", "orden": 37,
 *      "description": "Firma o sello de recibido", "severity": "CRITICO"}
 *   ],
 *   "systemPrompt": "Instrucción personalizada opcional..."
 * }
 *
 * Campos excluidos siempre (llaves internas de DB, no auditables):
 *   FacSec, NitSec
 */
class AuditConfigController extends Controller
{
    /** Campos técnicos internos que la UI nunca debe permitir configurar */
    private const EXCLUDED_FIELDS = ['FacSec', 'NitSec'];

    /** Severidades válidas para visual checks */
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

        $sanitizedFields = $this->sanitizeFields($body['fields'], $clientId);

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
     * - Normaliza tipoCampo a 'E', 'S', 'B', 'V'.
     * - Acepta description y severity para todos los campos.
     * - Limita CampoNombre a 100 caracteres alfanuméricos+guiones.
     *
     * @param array  $rawFields  Body['fields'] sin sanitizar
     * @param string $clientId   Para logging
     * @return array             Campos válidos listos para insertar
     */
    private function sanitizeFields(array $rawFields, string $clientId): array
    {
        $sanitized = [];
        $errors    = [];

        foreach ($rawFields as $idx => $field) {
            $pos = $idx + 1;

            if (!isset($field['docId']) || !is_numeric($field['docId'])) {
                $errors[] = "Campo #{$pos}: 'docId' requerido y numérico.";
                continue;
            }

            if (empty($field['campoNombre']) || !is_string($field['campoNombre'])) {
                $errors[] = "Campo #{$pos}: 'campoNombre' requerido.";
                continue;
            }

            $campoNombre = trim($field['campoNombre']);

            if (in_array($campoNombre, self::EXCLUDED_FIELDS, true)) {
                $errors[] = "Campo #{$pos}: '{$campoNombre}' no es auditable.";
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $campoNombre)) {
                $errors[] = "Campo #{$pos}: '{$campoNombre}' contiene caracteres inválidos.";
                continue;
            }

            // tipoCampo: E=Exacto (default), S=Semántico, B=Negocio, V=Visual
            $tipoCampo = strtoupper(trim((string)($field['tipoCampo'] ?? 'E')));
            if (!in_array($tipoCampo, ['E', 'S', 'B', 'V'], true)) {
                $tipoCampo = 'E';
            }

            // description y severity para todos los campos
            $description = isset($field['description']) && is_string($field['description'])
                ? trim($field['description'])
                : null;
            $severity = isset($field['severity']) && is_string($field['severity'])
                ? strtoupper(trim($field['severity']))
                : 'ALTA';
            if (!in_array($severity, self::VALID_SEVERITIES, true)) {
                $severity = 'ALTA';
            }

            $sanitized[] = [
                'docId'       => (int) $field['docId'],
                'campoNombre' => $campoNombre,
                'tipoCampo'   => $tipoCampo,
                'orden'       => (int) ($field['orden'] ?? 0),
                'description' => $description,
                'severity'    => strtolower($severity), // Guardar como alta, media, baja para consistencia interna
            ];
        }

        if (!empty($errors)) {
            Response::error('Errores en los campos enviados', 422, $errors);
        }

        return $sanitized;
    }
}
