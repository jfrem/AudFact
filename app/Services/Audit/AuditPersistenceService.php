<?php

namespace App\Services\Audit;

use App\Models\AuditStatusModel;
use Core\Logger;

class AuditPersistenceService
{
    private const RESPONSE_DIR = '/../../../responseIA';

    private AuditStatusModel $auditStatusModel;

    public function __construct(AuditStatusModel $auditStatusModel)
    {
        $this->auditStatusModel = $auditStatusModel;
    }

    /**
     * Persiste la respuesta de auditoría en disco para trazabilidad (solo en dev/test).
     * En producción se omite para evitar acumulación de archivos JSON en disco.
     *
     * @param string $disDetNro Identificador de dispensación/factura
     * @param array $result Resultado final de auditoría
     * @return void
     */
    public function saveResponse(string $disDetNro, array $result): void
    {
        $env = strtolower(trim($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
        if (!in_array($env, ['dev', 'development', 'test', 'local'], true)) {
            return;
        }

        $dir = __DIR__ . self::RESPONSE_DIR;

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                Logger::error('No se pudo crear directorio de respuestas', ['dir' => $dir]);
                return;
            }
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $disDetNro) ?: 'unknown';
        $path = $dir . '/' . $safe . '_' . time() . '.json';

        $payload = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            Logger::error('Error codificando JSON de respuesta', ['DisDetNro' => $disDetNro]);
            $payload = '{"response": "error", "message": "JSON Encoding Error"}';
        }

        if (file_put_contents($path, $payload) === false) {
            Logger::error('Error guardando respuesta de auditoría', ['path' => $path]);
        }
    }

    /**
     * Persiste el estado de auditoría en la tabla AudDispEst.
     *
     * @param string $disDetNro Identificador de dispensación/factura
     * @param array $result Resultado final de auditoría
     * @param array|null $dispensation Datos de dispensación base
     * @return void
     */
    public function saveToDatabase(string $disDetNro, array $result, ?array $dispensation = null): void
    {
        try {
            $master = (isset($dispensation[0]) && is_array($dispensation[0])) ? $dispensation[0] : ($dispensation ?: []);

            $response = $result['response'] ?? 'error';
            $isSuccess = ($response === 'success');
            $findings = $result['data']['items'] ?? [];
            $severity = $result['severity'] ?? 'ninguna';

            $failedDoc = null;
            foreach ($findings as $finding) {
                if (strtolower((string) ($finding['severidad'] ?? '')) === 'alta') {
                    $failedDoc = $finding['documento'] ?? $finding['item'] ?? null;
                    break;
                }
            }

            $data = [
                'FacSec' => $master['FacSec'] ?? $disDetNro,
                'FacNro' => $master['NumeroFactura'] ?? ($result['_meta']['factura'] ?? $disDetNro),
                'EstAud' => $isSuccess ? 1 : 0,
                'EstadoDetallado' => substr(trim($response), 0, 50),
                'RequiereRevisionHumana' => ($severity === 'alta' || $severity === 'media' || $response === 'warning' || $response === 'error') ? 1 : 0,
                'Severidad' => substr($severity, 0, 20),
                'Hallazgos' => !empty($findings) ? json_encode($findings, JSON_UNESCAPED_UNICODE) : null,
                'DetalleError' => $result['message'] ?? null,
                'DocumentosProcesados' => count($result['_meta']['documentos'] ?? []),
                'FacNitSec' => $master['NitSec'] ?? null,
                'VlrCobrado' => (float) ($master['VlrCobrado'] ?? 0),
                'DuracionProcesamientoMs' => (int) ($result['_meta']['totalTimeMs'] ?? 0),
                'IPS_NIT' => $master['IPS_NIT'] ?? null,
                'DocumentoFallido' => $failedDoc ? substr((string) $failedDoc, 0, 255) : null,
            ];

            Logger::info('Persistiendo auditoría en BD', ['FacSec' => $disDetNro, 'EstAud' => $data['EstAud']]);
            $this->auditStatusModel->upsertAuditResult($data);
            // Insertar observación en AdjuntosDispensacionDetalle solo si:
            // 1. La auditoría NO fue exitosa
            // 2. NO es un error de infraestructura (timeout, quota, API caída)
            $errorOrigin = $result['_errorOrigin'] ?? 'infrastructure';

            if ($response !== 'success' && $errorOrigin !== 'infrastructure') {
                $this->insertObservationIfNeeded($data['FacNro'], $result, $failedDoc);
            } elseif ($response !== 'success' && $errorOrigin === 'infrastructure') {
                Logger::info('Observación NO insertada: error de infraestructura', [
                    'FacNro' => $data['FacNro'],
                    'message' => $result['message'] ?? 'N/A',
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('Error persistiendo auditoría en BD', [
                'DisDetNro' => $disDetNro,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Construye la observación textual y la inserta en AdjuntosDispensacionDetalle.
     *
     * @param string $facNro Número de factura
     * @param array $result Resultado de auditoría completo
     * @param string|null $failedDoc Documento fallido identificado
     * @return void
     */
    private function insertObservationIfNeeded(string $facNro, array $result, ?string $failedDoc): void
    {
        try {
            // Construir observación textual
            $parts = [];
            $message = $result['message'] ?? '';
            if (!empty($message)) {
                $parts[] = $message;
            }

            $findings = $result['data']['items'] ?? [];
            foreach ($findings as $finding) {
                $severity = $finding['severidad'] ?? '';
                $item = $finding['item'] ?? '';
                $detail = $finding['hallazgo'] ?? '';
                if (!empty($detail)) {
                    $parts[] = "[{$severity}] {$item}: {$detail}";
                }
            }

            $observation = implode(' | ', $parts);
            // Truncar a 4000 caracteres (límite razonable para campo de texto SQL Server)
            $observation = mb_substr($observation, 0, 4000);

            if (empty($observation)) {
                $observation = 'Auditoría IA detectó hallazgos — ver detalle en AudDispEst';
            }

            $inserted = $this->auditStatusModel->insertAuditObservation(
                $facNro,
                $observation,
                $failedDoc
            );

            if ($inserted) {
                Logger::info('Observación de auditoría insertada en AdjuntosDispensacionDetalle', [
                    'FacNro' => $facNro,
                ]);
            }
        } catch (\Exception $e) {
            // No debe fallar el flujo principal si esta inserción falla
            Logger::error('Error insertando observación de auditoría', [
                'FacNro' => $facNro,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
