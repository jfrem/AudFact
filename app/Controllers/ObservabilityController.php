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

            // Conteos de jobs por estado: escanear keys job:*:state con SCAN
            $jobCounts = ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
            $retries = 0;
            $terminalFailures = 0;

            $cursor = '0';
            do {
                [$cursor, $keys] = $redis->scan($cursor, 'job:*:state', 100);
                foreach ($keys as $key) {
                    $raw = $redis->get($key);
                    if (!is_string($raw) || $raw === '') {
                        continue;
                    }
                    $job = json_decode($raw, true);
                    if (!is_array($job)) {
                        continue;
                    }

                    $status = (string) ($job['status'] ?? '');
                    // Normalizar al schema del frontend
                    $frontendStatus = match ($status) {
                        BatchJobStore::JOB_STATUS_PENDING              => 'queued',
                        BatchJobStore::JOB_STATUS_PROCESSING           => 'running',
                        BatchJobStore::JOB_STATUS_COMPLETED,
                        BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR   => 'completed',
                        BatchJobStore::JOB_STATUS_FAILED               => 'failed',
                        default                                        => null,
                    };

                    if ($frontendStatus !== null && isset($jobCounts[$frontendStatus])) {
                        $jobCounts[$frontendStatus]++;
                    }

                    if ($status === BatchJobStore::JOB_STATUS_FAILED) {
                        $terminalFailures++;
                    }
                }
            } while ($cursor !== '0');

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
