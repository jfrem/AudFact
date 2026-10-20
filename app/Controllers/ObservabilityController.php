<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditDocumentMetrics;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use Core\Logger;
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
            $execution = AuditExecutionConfig::fromEnv();
            if ($execution->version === 2) {
                $streamGroups['semantic_priority'] = [AuditEventPublisher::STREAM_SEMANTIC_PRIORITY, [AuditEventPublisher::GROUP_SEMANTIC]];
                $streamGroups['semantic_batch'] = [AuditEventPublisher::STREAM_SEMANTIC_BATCH, [AuditEventPublisher::GROUP_SEMANTIC]];
            }

            $streamDepths = [];
            $streamBacklogs = [];
            $knownLag = 0;
            $totalDepth   = 0;
            foreach ($streamGroups as $name => [$stream, $groups]) {
                $depth = 0;
                $groupInfo = $groups === [] ? [] : $this->readGroupInfo($redis, $stream);
                foreach ($groups as $group) {
                    $pending = isset($groupInfo[$group]) ? $groupInfo[$group]['pending'] : 0;
                    $depth += $pending;
                    $lag = $groupInfo[$group]['lag'] ?? null;
                    $lag = is_numeric($lag) ? max(0, (int) $lag) : null;
                    $streamBacklogs[$name][$group] = [
                        'pending' => $pending, 'lag' => $lag, 'lagKnown' => $lag !== null,
                    ];
                    $knownLag += $lag ?? 0;
                }
                $streamDepths[$name] = $depth;
                $totalDepth += $depth;
            }

            // Claves agregadas para compatibilidad con dashboards frontend existentes
            $streamDepths['inbox'] = $streamDepths['inbox_priority'] + $streamDepths['inbox_batch'];
            $streamDepths['documents'] = $streamDepths['documents_priority'] + $streamDepths['documents_batch'];
            $streamDepths['persistence'] = $streamDepths['persistence_priority'] + $streamDepths['persistence_batch'];
            $streamDepths['results'] = $streamDepths['results_priority'] + $streamDepths['results_batch'];

            $counters = $redis->eval(
                'return {redis.call("XLEN", KEYS[1]), redis.call("HGETALL", KEYS[2])}',
                [AuditEventPublisher::dlqStream(), 'telemetry:async_metrics']
            );
            if (!is_array($counters) || count($counters) !== 2
                || !is_numeric($counters[0]) || !is_array($counters[1]) || count($counters[1]) % 2 !== 0) {
                throw new \RuntimeException('Respuesta inválida de contadores de auditoría');
            }
            $deadLetterDepth = max(0, (int) $counters[0]);
            $metrics = [];
            for ($i = 0; $i < count($counters[1]); $i += 2) {
                $metrics[(string) $counters[1][$i]] = $counters[1][$i + 1];
            }

            // pending  = job creado sin auditorias (vacio); aun no disparo trabajo al stream.
            // queued   = auditorias en stream, workers no han completado ninguna aun.
            // running  = al menos una auditoria completo; el pipeline esta activo.
            $jobCounts = [
                'pending'   => max(0, (int) ($metrics['jobs_queued']    ?? 0)),
                'queued'    => max(0, (int) ($metrics['jobs_active']    ?? 0)),
                'running'   => max(0, (int) ($metrics['jobs_running']   ?? 0)),
                'completed' => max(0, (int) ($metrics['jobs_completed'] ?? 0)),
                'failed'    => max(0, (int) ($metrics['jobs_failed']    ?? 0)),
            ];

            $retries = max(0, (int) ($metrics['retries'] ?? 0));
            $terminalFailures = max(0, (int) ($metrics['terminal_failures'] ?? 0));
            $scheduler = (new AuditPersistenceQueue($redis))->metrics();

            $payload = [
                'queueDepth'       => $totalDepth,
                'streamDepths'     => $streamDepths,
                'streamBacklogs'   => $streamBacklogs,
                'persistenceScheduler' => $scheduler,
                'backlogDepth' => $totalDepth + $knownLag + $scheduler['pending'],
                'deadLetterDepth'  => $deadLetterDepth,
                'auditsInFlight'   => max(0, (int) ($metrics['audits_in_flight'] ?? 0)),
                'jobs'             => $jobCounts,
                'retries'          => $retries,
                'terminalFailures' => $terminalFailures,
                'documentCache' => (new AuditDocumentMetrics($redis))->snapshot(),
            ];
            $payload['executionVersion'] = $execution->version;
            if ($execution->version === 2) {
                $execution->assertProtocol($redis);
                $payload['executionScheduler'] = (new FairAuditTaskQueue($redis, $execution))->metrics();
                foreach ($payload['executionScheduler'] as $lanes) {
                    foreach ($lanes as $counts) {
                        $payload['queueDepth'] += $counts['active'];
                        $payload['backlogDepth'] += $counts['queued'] + $counts['active'] + $counts['delayed'];
                    }
                }
                $payload['geminiBudget'] = (new GeminiQuotaBudget($redis))->metrics();
                $values = $redis->eval('return {redis.call("SCARD",KEYS[1]),redis.call("ZCARD",KEYS[2]),redis.call("HGETALL",KEYS[3]),redis.call("GET",KEYS[4])}',
                    [\App\Services\Audit\Pipeline\AuditTaskCommitter::PENDING, \App\Services\Audit\Pipeline\AuditTaskCommitter::OUTBOX,
                        'audit:semantic:v2:counts', 'audit:execution:heartbeat']);
                if (!is_array($values) || count($values) !== 4 || !is_array($values[2]) || count($values[2]) % 2 !== 0) {
                    throw new \RuntimeException('execution_metrics_invalid');
                }
                $plans = [];
                for ($i = 0; $i < count($values[2]); $i += 2) {
                    if (!is_numeric($values[2][$i + 1]) || (int) $values[2][$i + 1] < 0) {
                        throw new \RuntimeException('semantic_metrics_invalid');
                    }
                    $plans[$values[2][$i]] = (int) $values[2][$i + 1];
                }
                $payload['executionMaintenance'] = ['commitsPending' => $values[0], 'outboxPending' => $values[1],
                    'semanticPlans' => (object) $plans, 'heartbeatAgeMs' => $values[3] === null ? null
                        : max(0, (new FairAuditTaskQueue($redis, $execution))->nowMs() - (int) $values[3])];
            }
        } catch (\Throwable $e) {
            Logger::error('ObservabilityController::asyncMetrics falló', ['error_class' => get_class($e)]);
            Response::error('No se pudieron consultar las métricas de auditoría', 503);
            return;
        }

        Response::success($payload);
    }

    /** @return array<string,array<string,mixed>> */
    private function readGroupInfo(RedisClient $redis, string $stream): array
    {
        $raw = $redis->eval(
            'if redis.call("EXISTS", KEYS[1]) == 0 then return {} end; return redis.call("XINFO", "GROUPS", KEYS[1])',
            [$stream]
        );
        if (!is_array($raw)) {
            throw new \RuntimeException('Respuesta inválida al consultar grupos de Redis');
        }
        $groups = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || count($entry) % 2 !== 0) {
                throw new \RuntimeException('Grupo de Redis malformado');
            }
            $fields = [];
            for ($i = 0; $i + 1 < count($entry); $i += 2) {
                $fields[(string) $entry[$i]] = $entry[$i + 1];
            }
            if (!isset($fields['name']) || !is_string($fields['name'])
                || !isset($fields['pending']) || !is_numeric($fields['pending']) || (int) $fields['pending'] < 0) {
                throw new \RuntimeException('Grupo de Redis sin métricas válidas');
            }
            $fields['pending'] = (int) $fields['pending'];
            $groups[$fields['name']] = $fields;
        }
        return $groups;
    }

    protected function buildRedisClient(): RedisClient
    {
        return RedisClient::getInstance();
    }
}
