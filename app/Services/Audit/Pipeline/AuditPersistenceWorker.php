<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditResultPersistenceModel;
use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\Logger;
use Core\RedisClient;
use RuntimeException;
use Throwable;

final class AuditPersistenceWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private BatchJobStore $jobStore;
    private AuditResultPersistenceModel $persistenceModel;
    private AuditPersistenceQueue $persistenceQueue;
    private TelemetryPublisher $telemetryPublisher;
    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?BatchJobStore $jobStore = null,
        ?AuditResultPersistenceModel $persistenceModel = null,
        ?AuditPersistenceQueue $persistenceQueue = null,
        ?RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null,
        ?TelemetryPublisher $telemetryPublisher = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->jobStore = $jobStore ?? new BatchJobStore($this->redis);
        $this->persistenceModel = $persistenceModel ?? new AuditResultPersistenceModel();
        $this->persistenceQueue = $persistenceQueue ?? new AuditPersistenceQueue($this->redis);
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->consumerName = $consumerName ?? self::defaultConsumerName('persistence');
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_PERSISTENCE;
    }

    protected function group(): string
    {
        return 'persistence';
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
        $aggregate = $this->validateOutcome($audit, $event->payload);
        $disDetNro = trim((string) ($audit['dis_det_nro'] ?? '')) ?: null;
        $meta = ['worker' => $this->consumer()];
        $telemetryStartedAt = hrtime(true);

        $this->telemetryPublisher->started(
            $event->auditId,
            'aggregation',
            null,
            $disDetNro,
            $meta,
            $event->jobId
        );

        try {
            $persistStart = hrtime(true);
            $this->persistenceModel->persist(
                $aggregate['audit_result_data'],
                $aggregate['document_decisions']
            );
            $persistenceDurationMs = self::elapsedMs($persistStart);
            $aggregationTimings = [
                'aggregate_build_ms' => 0,
                'sql_persist_ms' => $persistenceDurationMs,
            ];

            $redisCompleteStart = hrtime(true);
            $completedNow = $this->stateStore->completeAudit(
                $event->auditId,
                $aggregate['completion_payload']
            );
            if (!$completedNow && !$this->auditAlreadyTerminal($event->auditId)) {
                throw new RuntimeException(
                    'No se pudo cerrar la auditoría en Redis después de persistir SQL'
                );
            }
            $aggregationTimings['redis_complete_ms'] = self::elapsedMs($redisCompleteStart);

            $finalAudit = $this->stateStore->getAudit($event->auditId) ?? $audit;
            $finalAudit['aggregation_timings'] = $aggregationTimings;
            $this->stateStore->patchAudit(
                $event->auditId,
                ['aggregation_timings' => $aggregationTimings]
            );

            $aggregate['audit_result_data'] = $this->refreshPersistedTimings(
                $aggregate['audit_result_data'],
                $finalAudit
            );

            if ($event->jobId !== null) {
                $this->markJobAuditCompleted($event, $aggregate, $finalAudit);
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
                $this->publishBatchTerminalEventIfNeeded(
                    $this->jobStore,
                    $event->jobId,
                    $event->auditId,
                    $event->eventId
                );
            }

            $this->telemetryPublisher->completed(
                $event->auditId,
                'aggregation',
                self::elapsedMs($telemetryStartedAt),
                null,
                $disDetNro,
                array_merge($meta, ['final_status' => $aggregate['final_status']]),
                $event->jobId
            );

            $this->jobStore->releaseAuditReservationFromAudit($audit);
            if (!$this->persistenceQueue->advance($event)) {
                throw new RuntimeException('No se pudo liberar el turno de persistencia del job');
            }

            Logger::info('Audit persistence completed', [
                'auditId' => $event->auditId,
                'final_status' => $aggregate['final_status'],
                'persistence_ms' => $persistenceDurationMs,
                'total_duration_ms' =>
                    $aggregate['audit_result_data']['DuracionProcesamientoMs'] ?? 0,
            ]);
        } catch (Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'aggregation',
                self::elapsedMs($telemetryStartedAt),
                null,
                $disDetNro,
                array_merge($meta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }
    }

    protected function afterTerminalFailure(AuditEvent $event, Throwable $error): void
    {
        if (
            $event->eventType === AuditEvent::TYPE_RULES_EVALUATED
            && !$this->persistenceQueue->advance($event)
        ) {
            throw new RuntimeException(
                'No se pudo liberar el turno tras el fallo terminal de persistencia',
                0,
                $error
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function requireAuditState(string $auditId): array
    {
        $audit = $this->stateStore->getAudit($auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada para persistencia final');
        }

        return $audit;
    }

    private function auditAlreadyTerminal(string $auditId): bool
    {
        $latestAudit = $this->stateStore->getAudit($auditId);
        $status = is_array($latestAudit) ? (string) ($latestAudit['status'] ?? '') : '';

        return in_array($status, [
            AuditStateStore::AUDIT_STATUS_COMPLETED,
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_ERROR,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);
    }

    /**
     * @param array<string,mixed> $audit
     * @param array<string,mixed> $rulesPayload
     * @return array{
     *   final_status:string,
     *   document_decisions:array<int,array<string,mixed>>,
     *   audit_result_data:array<string,mixed>,
     *   completion_payload:array<string,mixed>
     * }
     */
    private function validateOutcome(array $audit, array $rulesPayload): array
    {
        $auditResultData = $rulesPayload['audit_result_data'] ?? null;
        $documentDecisions = $rulesPayload['document_decisions'] ?? null;
        $completionPayload = $rulesPayload['completion_payload'] ?? null;
        $finalStatus = $rulesPayload['final_status'] ?? null;

        if (
            !is_array($auditResultData)
            || !is_array($documentDecisions)
            || !is_array($completionPayload)
            || !is_string($finalStatus)
        ) {
            throw new RuntimeException('rules_evaluated sin outcome final canónico');
        }
        if (($auditResultData['DisId'] ?? '') === '' || ($auditResultData['FacNro'] ?? '') === '') {
            throw new RuntimeException('Estado de auditoría incompleto para persistencia final');
        }
        if (
            (string) ($audit['dis_id'] ?? '') !== ''
            && (string) $auditResultData['DisId'] !== (string) $audit['dis_id']
        ) {
            throw new RuntimeException('rules_evaluated no coincide con DisId de estado Redis');
        }

        $hallazgos = json_decode((string) ($auditResultData['Hallazgos'] ?? ''), true);
        if (
            self::containsExactValue($documentDecisions, 'DOWNLOAD_ERROR')
            || (is_array($hallazgos) && self::containsExactValue($hallazgos, 'DOWNLOAD_ERROR'))
        ) {
            throw new \DomainException(
                'rules_evaluated contiene una razón técnica prohibida.'
            );
        }

        foreach ($documentDecisions as $decision) {
            if (!is_array($decision)) {
                continue;
            }

            $hasRejectionContract = array_key_exists('rejection_category', $decision)
                || array_key_exists('rejection_class', $decision)
                || array_key_exists('rejection_reason', $decision);
            if (!$hasRejectionContract) {
                continue;
            }

            $rejectionReason = (string) ($decision['rejection_reason'] ?? '');
            $rejectionCategory = (string) ($decision['rejection_category'] ?? '');
            if ($rejectionCategory !== '') {
                if (
                    $rejectionCategory !== DocumentMappingRejectionReason::CATEGORY
                    || array_key_exists('rejection_class', $decision)
                    || !DocumentMappingRejectionReason::isAllowed($rejectionReason)
                ) {
                    throw new \DomainException(
                        'rules_evaluated contiene un rechazo de asociación documental inválido.'
                    );
                }
                continue;
            }

            $rejectionClass = (string) ($decision['rejection_class'] ?? '');
            if (
                $rejectionClass !== DocumentRejectionReason::REJECTION_CLASS
                || !DocumentRejectionReason::isAllowed($rejectionReason)
            ) {
                throw new \DomainException(
                    'rules_evaluated contiene un rechazo documental inválido.'
                );
            }
        }

        return [
            'final_status' => $finalStatus,
            'document_decisions' => array_values(array_filter($documentDecisions, 'is_array')),
            'audit_result_data' => $auditResultData,
            'completion_payload' => $completionPayload,
        ];
    }

    private static function containsExactValue(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return $value === $needle;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $nestedValue) {
            if (self::containsExactValue($nestedValue, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $aggregate
     * @param array<string,mixed> $finalAudit
     */
    private function markJobAuditCompleted(
        AuditEvent $event,
        array $aggregate,
        array $finalAudit
    ): void {
        $stageForJob = match (true) {
            $aggregate['final_status'] === AuditStateStore::AUDIT_STATUS_FAILED =>
                'final_persistence',
            ((int) ($finalAudit['docs_rejected'] ?? 0)) > 0 =>
                DocumentExtractionWorker::class,
            $aggregate['final_status'] === AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW =>
                RulesEvaluationWorker::class,
            default => null,
        };

        $this->jobStore->markAuditCompletedInJob(
            (string) $event->jobId,
            (string) $event->auditId,
            $aggregate['final_status'],
            self::resolveAggregateDurationMs($aggregate),
            $stageForJob
        );
    }

    /**
     * @param array<string,mixed> $auditResultData
     * @param array<string,mixed> $finalAudit
     * @return array<string,mixed>
     */
    private function refreshPersistedTimings(array $auditResultData, array $finalAudit): array
    {
        $timings = AuditTimingSummarizer::buildPhaseTimings($finalAudit);
        $durationMs = (int) ($timings['processing_duration_ms'] ?? 0);
        $facNro = (string) ($auditResultData['FacNro'] ?? '');
        $payload = json_decode((string) ($auditResultData['Hallazgos'] ?? ''), true);

        if (is_array($payload) && !array_is_list($payload)) {
            $payload['timings'] = $timings;
            $payload['total_duration_ms'] = $durationMs;
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($encoded !== false) {
                $auditResultData['Hallazgos'] = $encoded;
            }
        }
        $auditResultData['DuracionProcesamientoMs'] = $durationMs;

        try {
            $this->persistenceModel->updateFinalTimings(
                $facNro,
                (string) $auditResultData['Hallazgos'],
                $durationMs
            );
        } catch (Throwable $error) {
            Logger::error('Audit persistence: no se pudieron persistir timings finales', [
                'FacNro' => $facNro,
                'error_class' => get_class($error),
                'error' => $error->getMessage(),
            ]);
        }

        return $auditResultData;
    }

    /**
     * @param array<string,mixed> $aggregate
     */
    private static function resolveAggregateDurationMs(array $aggregate): int
    {
        return max(
            0,
            (int) ($aggregate['audit_result_data']['DuracionProcesamientoMs'] ?? 0)
        );
    }

    private static function elapsedMs(int $start): int
    {
        return max(0, (int) round((hrtime(true) - $start) / 1_000_000));
    }
}
