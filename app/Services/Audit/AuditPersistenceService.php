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
     * Persiste el estado de auditoría en la tabla AudDispEst y actualiza
     * el resultado en AdjuntosDispensacion.
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
            // EstAud=1 significa "auditoría procesada por la IA" (independiente del resultado).
            // Solo es 0 para registros que nunca han sido auditados.
            $isProcessed = in_array($response, ['success', 'warning', 'error', 'human_review'], true);
            $findings = $result['data']['items'] ?? [];
            $severity = strtolower(trim((string) ($result['severity'] ?? '')));
            if ($severity === '') {
                if ($isSuccess) {
                    $severity = 'ninguna';
                } elseif ($response === 'warning' || $response === 'human_review') {
                    $severity = 'media';
                } else {
                    $severity = 'alta';
                }
            }
            if ($response === 'error' && $severity === 'ninguna') {
                $severity = 'alta';
            }

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
                'EstAud' => $isProcessed ? 1 : 0,
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

            // Actualizar resultado en AdjuntosDispensacion excepto errores de infraestructura
            $errorOrigin = $result['_errorOrigin'] ?? 'infrastructure';

            if ($errorOrigin !== 'infrastructure') {
                $this->updateAuditResultIfNeeded($data['FacNro'], $isSuccess, $result);
            } else if (!$isSuccess) {
                Logger::info('Resultado en AdjuntosDispensacion NO actualizado: error de infraestructura', [
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
     * Actualiza AdjuntosDispensacion según los hallazgos de la auditoría.
     *
     * Estrategia:
     * - Auditoría aprobada (sin hallazgos): marca TODOS los adjuntos como conformes (C).
     * - Auditoría con hallazgos: primero marca TODOS como conformes (baseline),
     *   luego rechaza individualmente cada documento que tenga hallazgos.
     *
     * @param string $facNro Número de factura
     * @param bool $isSuccess true si auditoría aprobada globalmente
     * @param array $result Resultado de auditoría completo
     * @return void
     */
    private function updateAuditResultIfNeeded(string $facNro, bool $isSuccess, array $result): void
    {
        try {
            if (($result['response'] ?? '') === 'human_review') {
                // Trazabilidad documental: marcar auditoría IA sin rechazo puntual.
                $this->auditStatusModel->updateAuditResult($facNro, true, null, null);
                Logger::info('Resultado human_review: adjuntos marcados para trazabilidad', [
                    'FacNro' => $facNro,
                ]);
                return;
            }

            if ($isSuccess) {
                // Auditoría aprobada: todos los adjuntos conformes.
                $this->auditStatusModel->updateAuditResult($facNro, true, null, null);
                Logger::info('Resultado de auditoría: todos los adjuntos aprobados', [
                    'FacNro' => $facNro,
                ]);
                return;
            }

            // Paso 2: Agrupar hallazgos por documento
            $findings = $result['data']['items'] ?? [];
            $findingsByDoc = [];
            foreach ($findings as $finding) {
                $doc = $finding['documento'] ?? null;
                if ($doc !== null) {
                    $findingsByDoc[$doc][] = $finding;
                }
            }

            if (empty($findingsByDoc)) {
                // Faltantes prevalidación: rechazar solo documentos listados en el mensaje.
                $missingDocuments = $this->extractMissingDocumentsFromMessage((string) ($result['message'] ?? ''));

                if (empty($missingDocuments)) {
                    Logger::warning('Auditoría con error sin mapeo documental; no se aplica rechazo masivo', [
                        'FacNro' => $facNro,
                        'findingsCount' => count($findings),
                    ]);
                    return;
                }

                $rejectedCount = 0;
                $globalObservation = mb_substr(trim((string) ($result['message'] ?? '')), 0, 4000);
                foreach ($missingDocuments as $docName) {
                    $updated = $this->auditStatusModel->updateAuditResult(
                        $facNro,
                        false,
                        $globalObservation,
                        $docName
                    );
                    if ($updated) {
                        $rejectedCount++;
                    }
                }

                Logger::warning('Auditoría por faltantes: rechazo puntual aplicado', [
                    'FacNro' => $facNro,
                    'missingDocuments' => $missingDocuments,
                    'rejectedCount' => $rejectedCount,
                ]);
                return;
            }

            // Paso 3: baseline de aprobados + rechazos puntuales por documento.
            $this->auditStatusModel->updateAuditResult($facNro, true, null, null);

            $rejectedCount = 0;
            foreach ($findingsByDoc as $docName => $docFindings) {
                $parts = [];
                foreach ($docFindings as $finding) {
                    $item = $finding['item'] ?? '';
                    $detail = $finding['hallazgo'] ?? $finding['detalle'] ?? '';
                    if (!empty($detail)) {
                        $parts[] = "{$item}: {$detail}";
                    }
                }

                $observation = implode(' | ', $parts);
                $observation = mb_substr($observation, 0, 4000);

                if (empty($observation)) {
                    $observation = 'Auditoría IA detectó hallazgos — ver detalle en AudDispEst';
                }

                $updated = $this->auditStatusModel->updateAuditResult(
                    $facNro,
                    false,
                    $observation,
                    $docName
                );

                if ($updated) {
                    $rejectedCount++;
                }
            }

            Logger::info('Resultado de auditoría: adjuntos rechazados individualmente', [
                'FacNro' => $facNro,
                'documentosRechazados' => $rejectedCount,
                'documentosTotales' => count($findingsByDoc),
            ]);
        } catch (\Exception $e) {
            // No debe fallar el flujo principal si esta actualización falla
            Logger::error('Error actualizando resultado de auditoría en AdjuntosDispensacion', [
                'FacNro' => $facNro,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrae nombres de documentos faltantes desde el mensaje de prevalidación.
     *
     * Ejemplo:
     * "Documentos requeridos sin archivo adjunto: AUTORIZACION DE SERVICIOS, VALIDADOR DE DERECHOS"
     *
     * @param string $message Mensaje de error
     * @return array<string> Nombres de documentos normalizados
     */
    private function extractMissingDocumentsFromMessage(string $message): array
    {
        $prefix = 'Documentos requeridos sin archivo adjunto:';
        $position = mb_stripos($message, $prefix);

        if ($position === false) {
            return [];
        }

        $docsRaw = trim(mb_substr($message, $position + mb_strlen($prefix)));
        if ($docsRaw === '') {
            return [];
        }

        $parts = array_map(static fn($item) => trim($item), explode(',', $docsRaw));
        $parts = array_values(array_filter($parts, static fn($item) => $item !== ''));

        return array_values(array_unique($parts));
    }
}
