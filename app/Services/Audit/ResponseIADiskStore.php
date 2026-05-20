<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use Core\Logger;

/**
 * Servicios de auditoría para persistencia de snapshots request/response en disco.
 *
 * La clase ResponseIADiskStore se encarga de guardar una copia fiel de la información procesada por el
 * pipeline de auditoría, específicamente lo relacionado con las interacciones con el modelo de IA.
 * Esta funcionalidad es utilizada exclusivamente en el entorno de desarrollo (`development`) para facilitar
 * la depuración y el análisis offline de los resultados obtenidos, sin afectar la operación normal del sistema
 * en producción.
 *
 */
final class ResponseIADiskStore
{
    private const DEFAULT_BASE_DIR = '/var/www/html/responseIA';
    private const DEFAULT_STATUS = 'unknown';
    private const SOURCE = 'GeminiGateway::sendWithFunctionCalling';

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? self::DEFAULT_BASE_DIR, '/\\');
    }

    /**
     * Persiste snapshot request/response para diagnóstico del pipeline.
     */
    public function persist(array $requestPayload, array $responseBody, array $context): bool
    {
        $appEnv = strtolower(trim((string) Env::get('APP_ENV', 'development')));
        $disDetNro = trim((string) ($context['dis_det_nro'] ?? ''));

        if ($appEnv !== 'development' || $disDetNro === '') {
            return false;
        }

        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0770, true) && !is_dir($this->baseDir)) {
            Logger::warning('responseIA persist failed: mkdir', ['base_dir' => $this->baseDir]);
            return false;
        }

        $status = trim((string) ($context['status'] ?? self::DEFAULT_STATUS));
        $status = $status !== '' ? $status : self::DEFAULT_STATUS;

        $payload = [
            'meta' => [
                'saved_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'app_env' => $appEnv,
                'source' => self::SOURCE,
                'audit_id' => $context['audit_id'] ?? null,
                'document_id' => $context['document_id'] ?? null,
                'dis_det_nro' => $disDetNro,
                'document_type' => $context['document_type'] ?? null,
                'task_type' => $context['task_type'] ?? null,
                'field' => $context['field'] ?? null,
                'tipoCampo' => $context['tipoCampo'] ?? null,
                'tipoDato' => $context['tipoDato'] ?? null,
                'call_purpose' => $context['call_purpose'] ?? null,
                'status' => $status,
            ],
            'request' => $this->redactInlineData($requestPayload),
            'response' => $responseBody,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            Logger::warning('responseIA persist failed: json_encode', ['disDetNro' => $disDetNro, 'json_error' => json_last_error_msg()]);
            return false;
        }

        $filename = sprintf('%s_%s_%s%s_%s.json', 
            preg_replace('/[^a-zA-Z0-9-]/', '_', $disDetNro) ?? 'unknown',
            preg_replace('/[^a-zA-Z0-9-]/', '_', $status) ?? 'unknown',
            gmdate('Ymd_His'),
            str_pad((string) ((int) ((microtime(true) - floor(microtime(true))) * 1_000_000)), 6, '0', STR_PAD_LEFT),
            bin2hex(random_bytes(4))
        );

        $tmpPath = $this->baseDir . DIRECTORY_SEPARATOR . $filename . '.tmp-' . bin2hex(random_bytes(6));
        $finalPath = $this->baseDir . DIRECTORY_SEPARATOR . $filename;

        $bytes = @file_put_contents($tmpPath, $json, LOCK_EX);
        if ($bytes === false || $bytes <= 0 || !@rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            Logger::warning('responseIA persist failed: write/rename', ['disDetNro' => $disDetNro, 'tmp_path' => $tmpPath]);
            return false;
        }

        Logger::info('responseIA snapshot persisted', ['disDetNro' => $disDetNro, 'status' => $status, 'bytes' => $bytes]);
        return true;
    }

    private function redactInlineData(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if ($key === 'inlineData' && is_array($value)) {
                $data = is_string($value['data'] ?? null) ? $value['data'] : '';
                if ($data !== '') {
                    $value['data_sha256'] = hash('sha256', $data);
                    $value['data_base64_bytes'] = strlen($data);
                    $value['data_redacted'] = true;
                }
                unset($value['data']);
                $redacted[$key] = $value;
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactInlineData($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
}
