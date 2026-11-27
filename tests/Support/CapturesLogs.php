<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Env;

/**
 * Captura las entradas de `Core\Logger` en un archivo temporal (modo `production` → `error_log`).
 *
 * Uso: `startLogCapture()` en el test y `stopLogCapture()` en `finally`/`tearDown`; `capturedLogs()` devuelve las
 * entradas JSON decodificadas (`level`, `message`, `context`).
 */
trait CapturesLogs
{
    private ?string $logCaptureFile = null;
    private string|false|null $logCapturePrevIni = null;
    private ?string $logCapturePrevEnv = null;

    protected function startLogCapture(): void
    {
        $this->logCaptureFile = tempnam(sys_get_temp_dir(), 'audfact-log-');
        $this->logCapturePrevIni = ini_get('error_log');
        $this->logCapturePrevEnv = Env::get('APP_ENV');
        ini_set('error_log', (string) $this->logCaptureFile);
        Env::set('APP_ENV', 'production');
    }

    protected function stopLogCapture(): void
    {
        if ($this->logCaptureFile === null) {
            return;
        }
        ini_set('error_log', $this->logCapturePrevIni === false ? '' : (string) $this->logCapturePrevIni);
        Env::set('APP_ENV', $this->logCapturePrevEnv);
        Env::clearCache('APP_ENV');
        @unlink($this->logCaptureFile);
        $this->logCaptureFile = null;
    }

    /**
     * @return array<int,array{level:string,message:string,context?:array<string,mixed>}>
     */
    protected function capturedLogs(): array
    {
        if ($this->logCaptureFile === null || !is_file($this->logCaptureFile)) {
            return [];
        }
        $entries = [];
        foreach (file($this->logCaptureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $start = strpos($line, '{');
            $decoded = $start === false ? null : json_decode(substr($line, $start), true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * JSON de los contextos capturados (sin `timestamp`, que podría contener dígitos que parezcan un NIT).
     */
    protected function capturedContextsJson(): string
    {
        return (string) json_encode(array_column($this->capturedLogs(), 'context'));
    }

    /**
     * @return array<int,array<string,mixed>> Contextos de las entradas cuyo mensaje contiene `$needle`.
     */
    protected function logContextsContaining(string $needle): array
    {
        $contexts = [];
        foreach ($this->capturedLogs() as $entry) {
            if (str_contains((string) ($entry['message'] ?? ''), $needle)) {
                $contexts[] = (array) ($entry['context'] ?? []);
            }
        }

        return $contexts;
    }
}
