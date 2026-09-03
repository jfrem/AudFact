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
                'inbox_priority'       => [AuditEventPublisher::STREAM_INBOX_PRIORITY, [AuditEventPublisher::GROUP_ORCHESTRATOR]],
                'inbox_batch'          => [AuditEventPublisher::STREAM_INBOX_BATCH, [AuditEventPublisher::GROUP_ORCHESTRATOR]],
                'documents_priority'   => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, [
                    AuditEventPublisher::GROUP_DOWNLOADERS,
                    AuditEventPublisher::GROUP_EXTRACTORS,
                    AuditEventPublisher::GROUP_NORMALIZERS,
                    AuditEventPublisher::GROUP_POLICY
                ]],
                'documents_batch'      => [AuditEventPublisher::STREAM_DOCUMENTS_BATCH, [
                    AuditEventPublisher::GROUP_DOWNLOADERS,
                    AuditEventPublisher::GROUP_EXTRACTORS,
                    AuditEventPublisher::GROUP_NORMALIZERS,
                    AuditEventPublisher::GROUP_POLICY
                ]],
                'persistence_priority' => [AuditEventPublisher::STREAM_PERSISTENCE_PRIORITY, [AuditEventPublisher::GROUP_PERSISTENCE]],
                'persistence_batch'    => [AuditEventPublisher::STREAM_PERSISTENCE_BATCH, [AuditEventPublisher::GROUP_PERSISTENCE]],
                'results_priority'     => [AuditEventPublisher::STREAM_RESULTS_PRIORITY, []],
                'results_batch'        => [AuditEventPublisher::STREAM_RESULTS_BATCH, []],
                'batchInbox'           => [AuditEventPublisher::STREAM_BATCH_INBOX, [AuditEventPublisher::GROUP_BATCH]],
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

            // Claves agregadas para compatibilidad con dashboards frontend existentes
            $streamDepths['inbox'] = $streamDepths['inbox_priority'] + $streamDepths['inbox_batch'];
            $streamDepths['documents'] = $streamDepths['documents_priority'] + $streamDepths['documents_batch'];
            $streamDepths['persistence'] = $streamDepths['persistence_priority'] + $streamDepths['persistence_batch'];
            $streamDepths['results'] = $streamDepths['results_priority'] + $streamDepths['results_batch'];

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
            $reconciliationAnomalies = max(0, (int) ($metrics['reconciliation_anomalies'] ?? 0));

            $payload = [
                'queueDepth'              => $totalDepth,
                'streamDepths'            => $streamDepths,
                'deadLetterDepth'         => $deadLetterDepth,
                'reconciliationAnomalies' => $reconciliationAnomalies,
                'jobs'                    => $jobCounts,
                'retries'                 => $retries,
                'terminalFailures'        => $terminalFailures,
            ];
        } catch (\Throwable $e) {
            $payload = [
                'queueDepth'              => 0,
                'streamDepths'            => [
                    'inbox' => 0,
                    'documents' => 0,
                    'persistence' => 0,
                    'results' => 0,
                    'batchInbox' => 0,
                ],
                'deadLetterDepth'         => 0,
                'reconciliationAnomalies' => 0,
                'jobs'                    => ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                'retries'                 => 0,
                'terminalFailures'        => 0,
            ];
        }

        Response::success($payload);
    }

    protected function buildRedisClient(): RedisClient
    {
        return RedisClient::getInstance();
    }
}
