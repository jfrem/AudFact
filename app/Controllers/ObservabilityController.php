<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use Core\Response;

/**
 * ObservabilityController
 *
 * Expone métricas operativas del pipeline de auditoría async para consumo
 * del frontend en la sección de observabilidad.
 */
class ObservabilityController extends Controller
{
    /**
     * GET /metrics/async
     *
     * Retorna métricas en tiempo real del sistema de colas Redis:
     * - Profundidad de la cola principal de auditorías
     * - Profundidad de la Dead Letter Queue (DLQ)
     * - Conteos de jobs por estado (queued, running, completed, failed)
     * - Reintentos y fallos terminales
     */
    public function asyncMetrics(): void
    {
        try {
            $redis = $this->buildRedisClient();

            $inboxStream = AuditEventPublisher::STREAM_INBOX;
            $dlqStream   = AuditEventPublisher::dlqStream();

            // Profundidad del stream principal de eventos de auditoría
            $queueDepth = (int) $redis->xLen($inboxStream);

            // Profundidad de la Dead Letter Queue
            $deadLetterDepth = (int) $redis->xLen($dlqStream);

            // Métricas operativas desde hash atómico
            $metrics = $redis->hGetAll('telemetry:async_metrics');
            if (!is_array($metrics)) {
                $metrics = [];
            }

            $jobCounts = [
                'queued'    => max(0, (int) ($metrics['jobs_queued'] ?? 0)),
                'running'   => max(0, (int) ($metrics['jobs_running'] ?? 0)),
                'completed' => max(0, (int) ($metrics['jobs_completed'] ?? 0)),
                'failed'    => max(0, (int) ($metrics['jobs_failed'] ?? 0)),
            ];

            $retries = max(0, (int) ($metrics['retries'] ?? 0));
            $terminalFailures = max(0, (int) ($metrics['terminal_failures'] ?? 0));

            Response::success([
                'queueDepth'       => $queueDepth,
                'deadLetterDepth'  => $deadLetterDepth,
                'jobs'             => $jobCounts,
                'retries'          => $retries,
                'terminalFailures' => $terminalFailures,
            ]);
        } catch (\Throwable $e) {
            // Redis no disponible: devolver ceros para no romper la UI.
            // El endpoint /health expone el estado real de Redis.
            Response::success([
                'queueDepth'       => 0,
                'deadLetterDepth'  => 0,
                'jobs'             => ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                'retries'          => 0,
                'terminalFailures' => 0,
            ]);
        }
    }

    protected function buildRedisClient(): RedisClient
    {
        return RedisClient::getInstance();
    }
}
