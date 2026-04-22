<?php

namespace App\Services\Audit;

use App\Models\AuditStatusModel;
use Core\Database;
use Core\Logger;
use Core\RedisClient;

class AuditPersistenceService
{
    private const RESPONSE_DIR = '/../../../responseIA';

    private AuditStatusModel $auditStatusModel;

    /**
     * Inicializa la persistencia con el modelo de estado de auditoría.
     *
     * @param  AuditStatusModel $auditStatusModel  Modelo de escritura en tablas de auditoría.
     * @return void
     */
    public function __construct(AuditStatusModel $auditStatusModel)
    {
        $this->auditStatusModel = $auditStatusModel;
    }

    /**
     * Guarda una copia JSON de la respuesta en disco solo para entornos no productivos.
     *
     * @param  string $disDetNro  Identificador de dispensación auditada.
     * @param  array<string, mixed> $result  Resultado completo del pipeline.
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
     * Persiste el resultado de auditoría en BD y actualiza el estado documental asociado.
     *
     * @param  string $disDetNro  Identificador técnico de dispensación.
     * @param  array<string, mixed> $result  Resultado normalizado del pipeline.
     * @param  array<string, mixed>|array<int, array<string, mixed>>|null $dispensation  Datos FDV usados para poblar llaves.
     * @return bool True si la transacción principal se completó correctamente.
     */
    public function saveToDatabase(string $disDetNro, array $result, ?array $dispensation = null): bool
    {
        $writeDb = null;
        try {
            $master = (isset($dispensation[0]) && is_array($dispensation[0])) ? $dispensation[0] : ($dispensation ?: []);

            $response = $result['response'] ?? 'error';
            $errorOrigin = $result['_errorOrigin'] ?? 'infrastructure';
            $isSuccess = ($response === 'success');
            // EstAud=1 solo cuando la IA alcanzó a procesar la auditoría o falló la infraestructura
            // después de haberse iniciado el flujo técnico. Guards de negocio/prevalidación quedan en 0.
            $isProcessed = in_array($response, ['success', 'warning'], true)
                || ($response === 'error' && $errorOrigin === 'infrastructure');
            $findings = $result['data']['items'] ?? [];
            $severity = strtolower(trim((string) ($result['severity'] ?? 'ninguna')));
            // Safety guard: infrastructure errors should never have 'ninguna' severity
            if ($response === 'error' && $severity === 'ninguna' && $errorOrigin !== 'business') {
                $severity = 'alta';
            }

            $failedDoc = null;
            $fallbackDoc = null;
            foreach ($findings as $finding) {
                $sev = strtolower((string) ($finding['severidad'] ?? ''));
                $doc = $finding['documento'] ?? $finding['item'] ?? null;
                if ($sev === 'alta') {
                    $failedDoc = $doc;
                    break;
                }
                if ($fallbackDoc === null && $doc !== null) {
                    $fallbackDoc = $doc;
                }
            }
            if ($failedDoc === null && $fallbackDoc !== null) {
                $failedDoc = $fallbackDoc;
            }

            $data = [
                'FacSec' => $master['FacSec'] ?? $disDetNro,
                'FacNro' => $master['NumeroFactura'] ?? ($result['_meta']['factura'] ?? $disDetNro),
                'EstAud' => $isProcessed ? 1 : 0,
                'EstadoDetallado' => substr(trim($response), 0, 50),
                'RequiereRevisionHumana' => ($response === 'warning'
                    || ($response === 'error' && $errorOrigin === 'infrastructure')
                    || ($response !== 'human_review' && in_array($severity, ['alta', 'media'], true))) ? 1 : 0,
                'Severidad' => substr($severity, 0, 20),
                'Hallazgos' => !empty($findings) ? json_encode($findings, JSON_UNESCAPED_UNICODE) : null,
                'DetalleError' => $result['message'] ?? null,
                'DocumentosProcesados' => count($result['_meta']['documentos'] ?? []),
                'FacNitSec' => $master['NitSec'] ?? null,
                'DuracionProcesamientoMs' => (int) ($result['_meta']['totalTimeMs'] ?? 0),
                'DocumentoFallido' => $failedDoc ? substr((string) $failedDoc, 0, 255) : null,
            ];

            $writeDb = Database::getConnection('default');
            $writeDb->beginTransaction();

            Logger::info('Persistiendo auditoría en BD', ['FacSec' => $disDetNro, 'EstAud' => $data['EstAud']]);
            $affected = $this->auditStatusModel->upsertAuditResult($data);
            if ($affected === false) {
                $writeDb->rollBack();
                Logger::error('Fallo en upsertAuditResult: no se pudo guardar registro auditado', ['FacSec' => $data['FacSec']]);
                return false;
            }

            if ($errorOrigin !== 'infrastructure') {
                $this->updateAuditResultIfNeeded($data['FacNro'], $isSuccess, $result);
            } else if (!$isSuccess) {
                Logger::info('Resultado en AdjuntosDispensacion NO actualizado: error de infraestructura', [
                    'FacNro' => $data['FacNro'],
                    'message' => $result['message'] ?? 'N/A',
                ]);
            }

            $writeDb->commit();

            if ($isProcessed && (int) $data['EstAud'] === 1) {
                $this->cacheAuditResult($data['FacSec'], $result);
            }

            return true;
        } catch (\Exception $e) {
            if ($writeDb !== null && $writeDb->inTransaction()) {
                $writeDb->rollBack();
            }
            Logger::error('Error persistiendo auditoría en BD', [
                'DisDetNro' => $disDetNro,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Sincroniza el resultado por documento en AdjuntosDispensacion cuando aplica.
     *
     * @param  string $facNro  Número de factura usado para ubicar adjuntos.
     * @param  bool $isSuccess  Indica si la auditoría terminó sin hallazgos.
     * @param  array<string, mixed> $result  Resultado de auditoría con hallazgos.
     * @return void
     */
    private function updateAuditResultIfNeeded(string $facNro, bool $isSuccess, array $result): void
    {
        try {
            if (($result['response'] ?? '') === 'human_review') {
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

            $findings = $result['data']['items'] ?? [];
            $findingsByDoc = [];
            foreach ($findings as $finding) {
                $doc = $finding['documento'] ?? null;
                if ($doc !== null) {
                    $findingsByDoc[$doc][] = $finding;
                }
            }

            if (empty($findingsByDoc)) {
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

            $this->auditStatusModel->updateAuditResult($facNro, true, null, null);

            $rejectedCount = 0;
            foreach ($findingsByDoc as $docName => $docFindings) {
                $parts = [];
                foreach ($docFindings as $finding) {
                    $item = $finding['item'] ?? '';
                    $detail = $finding['detalle'] ?? '';
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
            Logger::error('Error actualizando resultado de auditoría en AdjuntosDispensacion', [
                'FacNro' => $facNro,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrae nombres de documentos faltantes desde el mensaje de prevalidación.
     *
     * @param  string $message  Mensaje con prefijo de documentos requeridos faltantes.
     * @return array<int, string> Nombres únicos de documentos identificados.
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

    /**
     * Cachea un resultado exitosamente persistido para idempotencia de auditorías.
     *
     * @param  string $facSec  Identificador de factura en Fuente de Verdad.
     * @param  array<string, mixed> $result  Resultado completo a reutilizar.
     * @return void
     */
    private function cacheAuditResult(string $facSec, array $result): void
    {
        try {
            $redis = RedisClient::getInstance();
            if (!$redis->isAvailable()) {
                return;
            }

            $cacheTTL = (int) \Core\Env::get('AUDIT_CACHE_TTL', 86400);
            $cacheKey = 'audit:result:' . $facSec;

            $payload = $result;
            $payload['message'] = 'Resultado reutilizado (idempotencia)';

            $redis->set($cacheKey, json_encode($payload, JSON_UNESCAPED_UNICODE), $cacheTTL);
        } catch (\Exception $e) {
            Logger::warning('Error cacheando resultado de auditoría en Redis', [
                'FacSec' => $facSec,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
