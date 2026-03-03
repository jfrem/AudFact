<?php

namespace Core;

class Logger
{
    private static $logDir = __DIR__ . '/../logs';
    private static $retentionDays = 7;
    private static $maxSizeMb = 10;
    private static $maxBackups = 5;
    private static $minLevel = 'info';
    private static bool $initialized = false;

    private static function loadConfig(): void
    {
        $days = (int)Env::get('LOG_RETENTION_DAYS', self::$retentionDays);
        if ($days > 0) {
            self::$retentionDays = $days;
        }

        $size = (int)Env::get('LOG_MAX_SIZE_MB', self::$maxSizeMb);
        if ($size > 0) {
            self::$maxSizeMb = $size;
        }

        $level = strtolower((string)Env::get('LOG_LEVEL', self::$minLevel));
        if (in_array($level, ['error', 'warning', 'info'], true)) {
            self::$minLevel = $level;
        }
    }

    private static function shouldLog(string $level): bool
    {
        $order = ['error' => 3, 'warning' => 2, 'info' => 1];
        $levelKey = strtolower($level);
        $minKey = strtolower(self::$minLevel);
        if (!isset($order[$levelKey], $order[$minKey])) {
            return true;
        }
        return $order[$levelKey] >= $order[$minKey];
    }

    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password',
            'token',
            'secret',
            'api_key',
            'credit_card',
            'ssn',
            'authorization',
            'raw',
            'rawtext',
            'parsed',
            'hallazgos',
            'details',
            'trace'
        ];

        array_walk_recursive($context, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $value = '[REDACTED]';
            }
        });

        return $context;
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::loadConfig();
            self::cleanupOldLogs();
            self::$initialized = true;
        }
        if (!self::shouldLog($level)) {
            return;
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
        }

        $hostname = gethostname();
        $logFile = self::$logDir . "/app-{$hostname}-" . date('Y-m-d') . '.log';

        // Sanitize log file path to prevent directory traversal
        $realLogDir = realpath(self::$logDir);
        $realLogFile = realpath(dirname($logFile));

        if (!$realLogDir || !$realLogFile || strpos($realLogFile, $realLogDir) !== 0) {
            // Log to a safe fallback or throw an exception
            error_log("Security: Invalid log file path detected: {$logFile}");
            return;
        }

        self::rotateIfNeeded($logFile);

        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
        ];

        if (!empty($context)) {
            $context = self::sanitizeContext($context);

            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $e = $context['exception'];
                $context['exception'] = [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
            $entry['context'] = $context;
        }

        $jsonEntry = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($logFile, $jsonEntry . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function rotateIfNeeded(string $logFile): void
    {
        if (!file_exists($logFile)) {
            return;
        }

        $maxBytes = self::$maxSizeMb * 1024 * 1024;
        $size = filesize($logFile);
        if ($size === false || $size < $maxBytes) {
            return;
        }

        $oldest = $logFile . '.' . self::$maxBackups;
        if (file_exists($oldest)) {
            @unlink($oldest);
        }

        for ($i = self::$maxBackups - 1; $i >= 1; $i--) {
            $src = $logFile . '.' . $i;
            $dst = $logFile . '.' . ($i + 1);
            if (file_exists($src)) {
                @rename($src, $dst);
            }
        }

        @rename($logFile, $logFile . '.1');
    }

    private static function cleanupOldLogs(): void
    {
        $cutoff = strtotime('-' . self::$retentionDays . ' days');

        foreach (glob(self::$logDir . '/app-*.log') as $file) {
            if (preg_match('/app-.*-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                $fileDate = strtotime($matches[1]);
                if ($fileDate < $cutoff) {
                    unlink($file);
                }
            }
        }

        foreach (glob(self::$logDir . '/app-*.log.*') as $file) {
            if (preg_match('/app-.*-(\d{4}-\d{2}-\d{2})\.log\.\d+$/', $file, $matches)) {
                $fileDate = strtotime($matches[1]);
                if ($fileDate < $cutoff) {
                    unlink($file);
                }
            }
        }
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }
}
