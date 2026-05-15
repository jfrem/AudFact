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
     *
     * @param  array<string, mixed> $requestPayload  Payload enviado a Gemini.
     * @param  array<string, mixed> $responseBody  Respuesta o error asociado.
     * @param  array<string, mixed> $context  Contexto operacional del evento.
     * @return bool True cuando el snapshot queda persistido.
     */
    public function persist(array $requestPayload, array $responseBody, array $context): bool
    {
        $appEnv = $this->resolveAppEnv();
        $disDetNro = $this->resolveDisDetNro($context);

        if ($appEnv !== 'development' || $disDetNro === '') {
            return false;
        }

        if (!$this->ensureDirectory()) {
            return false;
        }

        $status = $this->resolveStatus($context);
        $payload = $this->buildSnapshotPayload($appEnv, $disDetNro, $status, $requestPayload, $responseBody, $context);
        $json = $this->encodeSnapshotPayload($payload);

        if ($json === false) {
            Logger::warning('responseIA persist failed: json_encode', [
                'disDetNro' => $disDetNro,
                'json_error' => json_last_error_msg(),
            ]);
            return false;
        }

        $finalPath = $this->buildFinalPath($disDetNro, $status);
        $bytes = $this->writeAtomically($finalPath, $json, $disDetNro);
        if ($bytes === null) {
            return false;
        }

        Logger::info('responseIA snapshot persisted', [
            'disDetNro' => $disDetNro,
            'auditId' => $context['audit_id'] ?? null,
            'documentId' => $context['document_id'] ?? null,
            'status' => $status,
            'bytes' => $bytes,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed> $context
     */
    private function resolveDisDetNro(array $context): string
    {
        return trim((string) ($context['dis_det_nro'] ?? ''));
    }

    private function resolveAppEnv(): string
    {
        return strtolower(trim((string) Env::get('APP_ENV', 'development')));
    }

    /**
     * @param  array<string, mixed> $context
     */
    private function resolveStatus(array $context): string
    {
        $status = trim((string) ($context['status'] ?? self::DEFAULT_STATUS));
        return $status !== '' ? $status : self::DEFAULT_STATUS;
    }

    /**
     * @param  array<string, mixed> $requestPayload
     * @param  array<string, mixed> $responseBody
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildSnapshotPayload(
        string $appEnv,
        string $disDetNro,
        string $status,
        array $requestPayload,
        array $responseBody,
        array $context
    ): array {
        return [
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
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function redactInlineData(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if ($key === 'inlineData' && is_array($value)) {
                $redacted[$key] = $this->redactInlineDataBlock($value);
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactInlineData($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string,mixed> $inlineData
     * @return array<string,mixed>
     */
    private function redactInlineDataBlock(array $inlineData): array
    {
        $data = is_string($inlineData['data'] ?? null) ? $inlineData['data'] : '';
        if ($data !== '') {
            $inlineData['data_sha256'] = hash('sha256', $data);
            $inlineData['data_base64_bytes'] = strlen($data);
            $inlineData['data_redacted'] = true;
        }

        unset($inlineData['data']);
        return $inlineData;
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function encodeSnapshotPayload(array $payload): string|false
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private function writeAtomically(string $finalPath, string $json, string $disDetNro): ?int
    {
        $tmpPath = $finalPath . '.tmp-' . bin2hex(random_bytes(6));
        $bytes = @file_put_contents($tmpPath, $json, LOCK_EX);

        if ($bytes === false || $bytes <= 0) {
            Logger::warning('responseIA persist failed: file_put_contents', [
                'disDetNro' => $disDetNro,
                'tmp_path' => $tmpPath,
                'bytes' => $bytes,
            ]);
            @unlink($tmpPath);
            return null;
        }

        if (!@rename($tmpPath, $finalPath)) {
            Logger::warning('responseIA persist failed: rename', [
                'disDetNro' => $disDetNro,
                'tmp_path' => $tmpPath,
                'final_path' => $finalPath,
            ]);
            @unlink($tmpPath);
            return null;
        }

        return $bytes;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->baseDir)) {
            return true;
        }

        if (@mkdir($this->baseDir, 0770, true)) {
            return true;
        }

        if (is_dir($this->baseDir)) {
            return true;
        }

        Logger::warning('responseIA persist failed: mkdir', ['base_dir' => $this->baseDir]);
        return false;
    }

    private function buildFinalPath(string $disDetNro, string $status): string
    {
        $sanitizedDisDetNro = preg_replace('/[^a-zA-Z0-9-]/', '_', $disDetNro) ?? 'unknown';
        $sanitizedStatus = preg_replace('/[^a-zA-Z0-9-]/', '_', trim($status)) ?? 'unknown';
        $timestamp = gmdate('Ymd_His');
        $micros = str_pad((string) ((int) ((microtime(true) - floor(microtime(true))) * 1_000_000)), 6, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(4));

        $filename = sprintf(
            '%s_%s_%s%s_%s.json',
            $sanitizedDisDetNro,
            $sanitizedStatus !== '' ? $sanitizedStatus : 'unknown',
            $timestamp,
            $micros,
            $random
        );

        return $this->baseDir . DIRECTORY_SEPARATOR . $filename;
    }
}
