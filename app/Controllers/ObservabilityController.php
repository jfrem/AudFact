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

            $streamGroups = [
                'inbox'       => [AuditEventPublisher::STREAM_INBOX, [AuditEventPublisher::GROUP_ORCHESTRATOR]],
                'documents'   => [AuditEventPublisher::STREAM_DOCUMENTS, [
                    AuditEventPublisher::GROUP_DOWNLOADERS,
                    AuditEventPublisher::GROUP_EXTRACTORS,
                    AuditEventPublisher::GROUP_NORMALIZERS,
                    AuditEventPublisher::GROUP_POLICY
                ]],
                'persistence' => [AuditEventPublisher::STREAM_PERSISTENCE, [AuditEventPublisher::GROUP_PERSISTENCE]],
                'results'     => [AuditEventPublisher::STREAM_RESULTS, []],
                'batchInbox'  => [AuditEventPublisher::STREAM_BATCH_INBOX, [AuditEventPublisher::GROUP_BATCH]],
            ];

            $streamDepths = [];
            $totalDepth   = 0;
            foreach ($streamGroups as $name => [$stream, $groups]) {
                $depth = 0;
                foreach ($groups as $group) {
                    $depth += $redis->xPending($stream, $group);
                }
                $streamDepths[$name] = $depth;
                $totalDepth += $depth;
            }

            $dlqStream       = AuditEventPublisher::dlqStream();
            $deadLetterDepth = (int) $redis->xLen($dlqStream);

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

            $payload = [
                'queueDepth'       => $totalDepth,
                'streamDepths'     => $streamDepths,
                'deadLetterDepth'  => $deadLetterDepth,
                'jobs'             => $jobCounts,
                'retries'          => $retries,
                'terminalFailures' => $terminalFailures,
            ];
        } catch (\Throwable $e) {
            $payload = [
                'queueDepth'       => 0,
                'streamDepths'     => [
                    'inbox' => 0,
                    'documents' => 0,
                    'persistence' => 0,
                    'results' => 0,
                    'batchInbox' => 0,
                ],
                'deadLetterDepth'  => 0,
                'jobs'             => ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                'retries'          => 0,
                'terminalFailures' => 0,
            ];
        }

        Response::success($payload);
    }

    protected function buildRedisClient(): RedisClient
    {
        return RedisClient::getInstance();
    }
}
