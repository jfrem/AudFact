<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Response;
use Core\Database;
use Core\Env;

class HealthController extends Controller
{
    public function status(): void
    {
        $database = $this->checkDatabase();
        $disk = $this->checkDisk();
        $memory = $this->checkMemory();

        $allOk = $database['status'] === 'ok' && $disk['status'] === 'ok';
        $globalStatus = $allOk ? 'healthy' : 'unhealthy';

        $status = [
            'status' => $globalStatus,
            'timestamp' => time(),
            'uptime_seconds' => (int) (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),
            'environment' => Env::get('APP_ENV', 'unknown'),
            'php_version' => PHP_VERSION,
            'services' => [
                'database' => $database,
                'disk' => $disk,
                'memory' => $memory,
            ],
        ];

        Response::success($status);
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            Database::getConnection()->query('SELECT 1');
            return [
                'status' => 'ok',
                'message' => 'connected',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        } catch (\Exception $e) {
            $errorMessage = Env::get('APP_ENV') === 'production'
                ? 'database unreachable'
                : $e->getMessage();

            return [
                'status' => 'fail',
                'message' => 'disconnected',
                'error' => $errorMessage,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function checkDisk(): array
    {
        $logsDir = __DIR__ . '/../../logs';
        $freeBytes = @disk_free_space($logsDir);

        if ($freeBytes === false) {
            return ['status' => 'unknown', 'message' => 'cannot determine'];
        }

        $freeMb = round($freeBytes / 1048576);
        $threshold = 100; // MB mínimo

        return [
            'status' => $freeMb > $threshold ? 'ok' : 'warn',
            'free_mb' => $freeMb,
            'threshold_mb' => $threshold,
        ];
    }

    private function checkMemory(): array
    {
        $usageBytes = memory_get_usage(true);
        $peakBytes = memory_get_peak_usage(true);
        $limitStr = ini_get('memory_limit') ?: '128M';

        return [
            'status' => 'ok',
            'usage_mb' => round($usageBytes / 1048576, 1),
            'peak_mb' => round($peakBytes / 1048576, 1),
            'limit' => $limitStr,
        ];
    }
}
