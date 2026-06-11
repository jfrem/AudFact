<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditStatusModel;
use RuntimeException;

final class AuditAggregationWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private BatchJobStore $jobStore;
    private AuditStatusModel $auditStatusModel;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?BatchJobStore $jobStore = null,
        ?AuditStatusModel $auditStatusModel = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->jobStore = $jobStore ?? new BatchJobStore($this->redis);
        $this->auditStatusModel = $auditStatusModel ?? new AuditStatusModel();
        $this->consumerName = $consumerName ?? self::defaultConsumerName('aggregator');
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_RESULTS;
    }

    protected function group(): string
    {
        return 'aggregator';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_RULES_EVALUATED) {
            return;
        }

        if ($event->auditId === null) {
            throw new RuntimeException('rules_evaluated sin audit_id');
        }

        $audit = $this->requireAuditState($event->auditId);
        $finalAudit = $audit;
        $terminalReached = false;

        try {
            $aggregateStart = hrtime(true);
            $aggregate = $this->aggregate($audit, $event->payload);
            $aggregationTimings = [
                'aggregate_build_ms' => self::elapsedMs($aggregateStart),
            ];

            $persistStart = hrtime(true);
            try {
                $persisted = $this->auditStatusModel->persistAuditResultWithAttachments(
                    $aggregate['audit_result_data'],
                    $aggregate['document_decisions']
                );
            } catch (\Throwable $e) {
                $this->handleFinalFailure($event, $aggregate, $e);
                $terminalReached = true;
                throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $e);
            }

            $persistDurationMs = self::elapsedMs($persistStart);
            $aggregationTimings['sql_persist_ms'] = $persistDurationMs;

            if ($persisted === false) {
                $failure = new RuntimeException('persistAuditResultWithAttachments retornó false');
                $this->handleFinalFailure($event, $aggregate, $failure);
                $terminalReached = true;
                throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $failure);
            }

            $redisCompleteStart = hrtime(true);
            if (!$this->stateStore->completeAudit($event->auditId, $aggregate['completion_payload'])) {
                if ($this->auditAlreadyTerminal($event->auditId)) {
                    $terminalReached = true;
                    return;
                }

                throw new RuntimeException('No se pudo cerrar la auditoría en Redis después de persistir SQL');
            }
            $aggregationTimings['redis_complete_ms'] = self::elapsedMs($redisCompleteStart);
            $terminalReached = true;

            $finalAudit = $this->stateStore->getAudit($event->auditId) ?? $finalAudit;
            $finalAudit['aggregation_timings'] = $aggregationTimings;
            $this->stateStore->patchAudit($event->auditId, ['aggregation_timings' => $aggregationTimings]);

            $aggregate['audit_result_data'] = $this->refreshPersistedTimings(
                $aggregate['audit_result_data'],
                $finalAudit
            );

            if ($event->jobId !== null) {
                $this->jobStore->markAuditCompletedInJob(
                    $event->jobId,
                    $event->auditId,
                    $aggregate['final_status'],
                    self::resolveAggregateDurationMs($aggregate)
                );
            }
            
            $this->publisher->publish(AuditEvent::create(
                eventType: AuditEvent::TYPE_AUDIT_COMPLETED,
                auditId: $event->auditId,
                jobId: $event->jobId,
                payload: array_merge($aggregate['completion_payload'], [
                    'audit_result_data' => $aggregate['audit_result_data'],
                    'document_decisions' => $aggregate['document_decisions'],
                    'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]),
                parentEventId: $event->eventId,
            ));

            if ($event->jobId !== null) {
                $this->publishBatchTerminalEventIfNeeded($this->jobStore, $event->jobId, $event->auditId, $event->eventId);
            }

            \Core\Logger::info('Audit aggregation completed', [
                'auditId'             => $event->auditId,
                'final_status'        => $aggregate['final_status'],
                'persistence_ms'      => $persistDurationMs,
                'total_duration_ms'   => $aggregate['audit_result_data']['DuracionProcesamientoMs'] ?? 0,
            ]);
        } finally {
            if ($terminalReached) {
                $this->jobStore->releaseAuditReservationFromAudit($audit);
            }
        }
    }

    private function auditAlreadyTerminal(string $auditId): bool
    {
        $latestAudit = $this->stateStore->getAudit($auditId);
        $currentStatus = is_array($latestAudit) ? (string) ($latestAudit['status'] ?? '') : '';

        return in_array($currentStatus, [
            AuditStateStore::AUDIT_STATUS_COMPLETED,
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_ERROR,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function requireAuditState(string $auditId): array
    {
        $audit = $this->stateStore->getAudit($auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada para agregación final');
        }

        return $audit;
    }

    /**
     * Valida el outcome canónico construido por RulesEvaluationWorker.
     *
     * @param  array<string,mixed> $audit
     * @param  array<string,mixed> $rulesPayload
     * @return array{
     *   final_status:string,
     *   document_decisions:array<int,array{documentName:string,approved:bool,observation:?string}>,
     *   audit_result_data:array<string,mixed>,
     *   completion_payload:array<string,mixed>
     * }
     */
    private function aggregate(array $audit, array $rulesPayload): array
    {
        $auditResultData = $rulesPayload['audit_result_data'] ?? null;
        $documentDecisions = $rulesPayload['document_decisions'] ?? null;
        $completionPayload = $rulesPayload['completion_payload'] ?? null;
        $finalStatus = $rulesPayload['final_status'] ?? null;

        if (!is_array($auditResultData) || !is_array($documentDecisions) || !is_array($completionPayload) || !is_string($finalStatus)) {
            throw new RuntimeException('rules_evaluated sin outcome final canónico');
        }

        if (($auditResultData['DisId'] ?? '') === '' || ($auditResultData['FacNro'] ?? '') === '') {
            throw new RuntimeException('Estado de auditoría incompleto para persistencia final');
        }

        if ((string) ($audit['dis_id'] ?? '') !== '' && (string) $auditResultData['DisId'] !== (string) $audit['dis_id']) {
            throw new RuntimeException('rules_evaluated no coincide con DisId de estado Redis');
        }

        return [
            'final_status' => $finalStatus,
            'document_decisions' => array_values(array_filter($documentDecisions, 'is_array')),
            'audit_result_data' => $auditResultData,
            'completion_payload' => $completionPayload,
        ];
    }

    /**
     * Maneja el fallo final de la auditoría, actualizando el estado y persistiendo los resultados.
     * @param  array<string,mixed> $aggregate
     */
    private function handleFinalFailure(AuditEvent $event, array $aggregate, \Throwable $error): void
    {
        $failedPayload = [
            'status' => AuditStateStore::AUDIT_STATUS_FAILED,
            'requires_manual_review' => true,
            'detail_error' => $error->getMessage(),
            'failed_stage' => 'final_persistence',
            'audit_result' => $aggregate['completion_payload']['audit_result'] ?? null,
            'audit_result_data' => $aggregate['audit_result_data'] ?? null,
            'document_decisions' => $aggregate['document_decisions'] ?? [],
        ];

        $this->stateStore->completeAudit($event->auditId ?? '', $failedPayload);

        if ($event->jobId !== null && $event->auditId !== null) {
            $this->jobStore->markAuditCompletedInJob(
                $event->jobId,
                $event->auditId,
                AuditStateStore::AUDIT_STATUS_FAILED,
                self::resolveAggregateDurationMs($aggregate)
            );
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_FAILED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: array_merge($failedPayload, ['failed_at' => gmdate('Y-m-d\TH:i:s\Z')]),
            parentEventId: $event->eventId,
        ));

        if ($event->jobId !== null && $event->auditId !== null) {
            $this->publishBatchTerminalEventIfNeeded($this->jobStore, $event->jobId, $event->auditId, $event->eventId);
        }
    }

    /**
     * @param  array<string,mixed> $aggregate
     */
    private static function resolveAggregateDurationMs(array $aggregate): int
    {
        return max(0, (int) ($aggregate['audit_result_data']['DuracionProcesamientoMs'] ?? 0));
    }

    /**
     * Recalcula y persiste los timings definitivos después de completed_at.
     *
     * @param  array<string,mixed> $auditResultData
     * @param  array<string,mixed> $finalAudit
     * @return array<string,mixed>
     */
    private function refreshPersistedTimings(array $auditResultData, array $finalAudit): array
    {
        $timings = AuditTimingSummarizer::buildPhaseTimings($finalAudit);
        $durationMs = (int) ($timings['processing_duration_ms'] ?? 0);
        $disId = (string) ($auditResultData['DisId'] ?? '');

        try {
            $this->auditStatusModel->updateAuditTimings($disId, $timings, $durationMs);
        } catch (\Throwable $error) {
            \Core\Logger::error('Audit aggregation: no se pudieron persistir timings finales', [
                'DisId' => $disId,
                'error_class' => get_class($error),
                'error' => $error->getMessage(),
            ]);
        }

        $payload = json_decode((string) ($auditResultData['Hallazgos'] ?? ''), true);
        if (is_array($payload) && !array_is_list($payload)) {
            $payload['timings'] = $timings;
            $payload['total_duration_ms'] = $durationMs;
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $auditResultData['Hallazgos'] = $encoded;
            }
        }

        $auditResultData['DuracionProcesamientoMs'] = $durationMs;

        return $auditResultData;
    }

    private static function elapsedMs(int $start): int
    {
        return max(0, (int) round((hrtime(true) - $start) / 1_000_000));
    }
}
