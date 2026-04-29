<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AuditStatusModel;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\Debug\GeminiCallMetrics;
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
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);
        $this->jobStore = $jobStore ?? new BatchJobStore($this->redis);
        $this->auditStatusModel = $auditStatusModel ?? new AuditStatusModel();
        $this->consumerName = $consumerName ?? ('aggregator-' . getmypid());
    }

    /**
     * Procesa un evento de auditoría.
     * @param AuditEvent $event
     * @return void
     */
    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
    }

    /**
     * Retorna el stream del consumer.
     * @return string
     */
    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_RESULTS;
    }

    /**
     * Retorna el grupo del consumer.
     * @return string
     */
    protected function group(): string
    {
        return 'aggregator';
    }

    /**
     * Retorna el nombre del consumer.
     * @return string
     */
    protected function consumer(): string
    {
        return $this->consumerName;
    }

    /**
     * Maneja el evento de auditoría.
     * @param AuditEvent $event
     * @return void
     */
    protected function handle(AuditEvent $event): void
    {
        if ($event->eventType !== AuditEvent::TYPE_RULES_EVALUATED) {
            return;
        }

        if ($event->auditId === null) {
            throw new RuntimeException('rules_evaluated sin audit_id');
        }

        $audit = $this->stateStore->getAudit($event->auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada para agregación final');
        }

        $aggregate = $this->aggregate($audit, $event->payload);
        $persistStart = microtime(true);
        try {
            $persisted = $this->auditStatusModel->persistAuditResultWithAttachments(
                $aggregate['audit_result_data'],
                $aggregate['document_decisions']
            );
        } catch (\Throwable $e) {
            $this->handleFinalFailure($event, $aggregate, $e);
            throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $e);
        }

        $persistDurationMs = (int) ((microtime(true) - $persistStart) * 1000);

        if ($persisted === false) {
            $failure = new RuntimeException('persistAuditResultWithAttachments retornó false');
            $this->handleFinalFailure($event, $aggregate, $failure);
            throw new RuntimeException('No se pudo persistir el resultado final de auditoría', 0, $failure);
        }

        if (!$this->stateStore->completeAudit($event->auditId, $aggregate['completion_payload'])) {
            $latestAudit = $this->stateStore->getAudit($event->auditId);
            $currentStatus = is_array($latestAudit) ? (string) ($latestAudit['status'] ?? '') : '';
            if (in_array($currentStatus, [
                AuditStateStore::AUDIT_STATUS_COMPLETED,
                AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
                AuditStateStore::AUDIT_STATUS_ERROR,
                AuditStateStore::AUDIT_STATUS_FAILED,
            ], true)) {
                return;
            }

            throw new RuntimeException('No se pudo cerrar la auditoría en Redis después de persistir SQL');
        }

        if ($event->jobId !== null) {
            $this->jobStore->markAuditCompletedInJob($event->jobId, $event->auditId, $aggregate['final_status']);
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
            $this->publishBatchTerminalEventIfNeeded($event->jobId, $event->auditId, $event->eventId);
        }

        \Core\Logger::info('Audit aggregation completed', [
            'auditId'             => $event->auditId,
            'final_status'        => $aggregate['final_status'],
            'persistence_ms'      => $persistDurationMs,
            'total_duration_ms'   => $aggregate['audit_result_data']['DuracionProcesamientoMs'] ?? 0,
        ]);
    }

    /**
     * Construye los tiempos de las fases de la auditoría.
     * @param  array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function buildPhaseTimings(array $audit): array
    {
        $extractions    = [];
        $normalizations = [];
        $policies       = [];
        $downloads      = [];
        $geminis        = [];
        $geminiExtractionMetrics = [];
        $geminiSemanticMetrics = [];
        $semanticCacheHits = 0;
        $cacheHits      = 0;
        $total          = 0;

        foreach ($audit['documents'] ?? [] as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $total++;
            if (isset($doc['extraction_duration_ms'])) {
                $extractions[] = (int) $doc['extraction_duration_ms'];
            }
            if (isset($doc['normalization_duration_ms'])) {
                $normalizations[] = (int) $doc['normalization_duration_ms'];
            }
            if (isset($doc['policy_duration_ms'])) {
                $policies[] = (int) $doc['policy_duration_ms'];
            }
            if (isset($doc['download_duration_ms'])) {
                $downloads[] = (int) $doc['download_duration_ms'];
            }
            if (isset($doc['gemini_duration_ms'])) {
                $geminis[] = (int) $doc['gemini_duration_ms'];
            }
            if (is_array($doc['gemini_metrics'] ?? null)) {
                $geminiExtractionMetrics[] = $doc['gemini_metrics'];
            }
            if (is_array($doc['gemini_semantic_metrics']['semantic'] ?? null)) {
                foreach ($doc['gemini_semantic_metrics']['semantic'] as $metric) {
                    if (is_array($metric)) {
                        $geminiSemanticMetrics[] = $metric;
                    }
                }
            }
            $semanticCacheHits += (int) ($doc['gemini_semantic_metrics']['semantic_cache_hits'] ?? 0);
            if ($doc['cache_hit'] ?? false) {
                $cacheHits++;
            }
        }

        $allGeminiMetrics = array_merge($geminiExtractionMetrics, $geminiSemanticMetrics);

        return [
            'docs_total'     => $total,
            'cache_hit_rate' => $total > 0 ? round($cacheHits / $total, 2) : 0.0,
            'download'       => $this->summarizeTimings($downloads),
            'gemini'         => $this->summarizeTimings($geminis),
            'gemini_extraction' => GeminiCallMetrics::summarize($geminiExtractionMetrics),
            'gemini_semantic'   => GeminiCallMetrics::summarize($geminiSemanticMetrics),
            'gemini_total'      => GeminiCallMetrics::summarize($allGeminiMetrics),
            'semantic_calls'    => count($geminiSemanticMetrics),
            'semantic_cache_hits' => $semanticCacheHits,
            'extraction'     => $this->summarizeTimings($extractions),
            'normalization'  => $this->summarizeTimings($normalizations),
            'policy'         => $this->summarizeTimings($policies),
        ];
    }

    /**
     * Resume los tiempos de un array de valores enteros.
     * @param  array<int,int> $values
     * @return array<string,int|float>
     */
    private function summarizeTimings(array $values): array
    {
        if ($values === []) {
            return ['count' => 0];
        }

        sort($values);
        $count    = count($values);
        $p95index = max(0, (int) ceil($count * 0.95) - 1);

        return [
            'count'  => $count,
            'avg_ms' => (int) (array_sum($values) / $count),
            'min_ms' => $values[0],
            'max_ms' => $values[$count - 1],
            'p95_ms' => $values[$p95index],
        ];
    }

    /**
     * @param  array<string,mixed> $audit
     * @param  array<string,mixed> $rulesPayload
     * @return array{
     *   final_status:string,
     *   requires_manual_review:bool,
     *   severity:string,
     *   detail_message:string,
     *   failed_document:?string,
     *   document_decisions:array<int,array{documentName:string,approved:bool,observation:?string}>,
     *   audit_result_data:array<string,mixed>,
     *   completion_payload:array<string,mixed>
     * }
     */
    private function aggregate(array $audit, array $rulesPayload): array
    {
        $hallazgos = $rulesPayload['hallazgos'] ?? null;
        $documentDecisions = $rulesPayload['document_decisions'] ?? null;

        if (!is_array($hallazgos) || !is_array($documentDecisions)) {
            throw new RuntimeException('rules_evaluated sin hallazgos o document_decisions válidos');
        }

        $findings = $this->normalizeFindings($hallazgos['items'] ?? []);
        $metrics = $this->normalizeMetrics($hallazgos['metrics'] ?? []);
        $normalizedDecisions = $this->normalizeDocumentDecisions($documentDecisions);

        $finalStatus = $this->resolveFinalStatus($findings, $normalizedDecisions);
        $requiresManualReview = in_array($finalStatus, [
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);
        $severity = $this->resolveOverallSeverity($findings);
        $failedDocument = $this->resolveFailedDocument($findings);
        $detailMessage = $this->buildDetailMessage($finalStatus, $metrics);

        $auditResultData = [
            'FacSec' => (string) ($audit['fac_sec'] ?? ''),
            'FacNro' => (string) ($audit['dis_det_nro'] ?? ''),
            'EstAud' => $finalStatus === AuditStateStore::AUDIT_STATUS_FAILED ? 0 : 1,
            'EstadoDetallado' => $finalStatus,
            'RequiereRevisionHumana' => $requiresManualReview ? 1 : 0,
            'Severidad' => $severity,
            'Hallazgos' => json_encode([
                'items'              => $findings,
                'metrics'            => $metrics,
                'affected_documents' => $normalizedDecisions,
                '_meta'              => [
                    'phase_timings'    => $this->buildPhaseTimings($audit),
                    'total_duration_ms'=> $this->resolveDurationMs($audit),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'DetalleError' => $detailMessage,
            'DocumentosProcesados' => count(is_array($audit['documents'] ?? null) ? $audit['documents'] : []),
            'DocumentoFallido' => $failedDocument,
            'DuracionProcesamientoMs' => $this->resolveDurationMs($audit),
            'FacNitSec' => (string) ($audit['fac_nit_sec'] ?? ''),
        ];

        if ($auditResultData['FacSec'] === '' || $auditResultData['FacNro'] === '') {
            throw new RuntimeException('Estado de auditoría incompleto para persistencia final');
        }

        return [
            'final_status' => $finalStatus,
            'requires_manual_review' => $requiresManualReview,
            'severity' => $severity,
            'detail_message' => $detailMessage,
            'failed_document' => $failedDocument,
            'document_decisions' => $normalizedDecisions,
            'audit_result_data' => $auditResultData,
            'completion_payload' => [
                'status' => $finalStatus,
                'requires_manual_review' => $requiresManualReview,
                'audit_result' => [
                    'hallazgos' => [
                        'items' => $findings,
                        'metrics' => $metrics,
                    ],
                    'document_decisions' => $normalizedDecisions,
                ],
                'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
            ],
        ];
    }

    /**
     * Normaliza los hallazgos de la auditoría.
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFindings(mixed $findings): array
    {
        if (!is_array($findings)) {
            return [];
        }
        return array_values(array_filter($findings, 'is_array'));
    }

    /**
     * Normaliza las métricas de la auditoría.
     * @return array<string,int>
     */
    private function normalizeMetrics(mixed $metrics): array
    {
        $base = [
            'total_campos' => 0,
            'coincidencias' => 0,
            'discrepancias' => 0,
            'omitidos' => 0,
            'no_concluyentes' => 0,
            'risk_score' => 0,
        ];

        if (!is_array($metrics)) {
            return $base;
        }

        foreach (array_keys($base) as $key) {
            $base[$key] = (int) ($metrics[$key] ?? 0);
        }

        return $base;
    }

    /**
     * Normaliza las decisiones documentales.
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function normalizeDocumentDecisions(mixed $decisions): array
    {
        if (!is_array($decisions)) {
            return [];
        }

        $normalized = [];
        foreach ($decisions as $decision) {
            if (!is_array($decision)) {
                continue;
            }

            $name = $this->normalizeDocumentName((string) ($decision['documentName'] ?? ''));
            if ($name === '') {
                continue;
            }

            $observation = trim((string) ($decision['observation'] ?? ''));
            $normalized[] = [
                'documentName' => $name,
                'approved' => (bool) ($decision['approved'] ?? false),
                'observation' => $observation === '' ? null : $observation,
            ];
        }

        return $normalized;
    }

    /**
     * Resuelve el estado final de la auditoría basado en los hallazgos y decisiones documentales.
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     */
    private function resolveFinalStatus(array $findings, array $documentDecisions): string
    {
        $hasHighSeverityFailure = false;
        $hasNonCriticalFailure = false;

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (!AuditFindingRules::isFailureResult($result)) {
                continue;
            }

            $severity = (string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value);
            if ($severity === AuditSeverity::HIGH->value) {
                $hasHighSeverityFailure = true;
            } else {
                $hasNonCriticalFailure = true;
            }
        }

        foreach ($documentDecisions as $decision) {
            if ($decision['approved'] === true) {
                continue;
            }

            if (AuditFindingRules::observationRequiresManualReview($decision['observation'] ?? null)) {
                $hasHighSeverityFailure = true;
            }
        }

        if ($hasHighSeverityFailure) {
            return AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW;
        }

        if ($hasNonCriticalFailure) {
            return AuditStateStore::AUDIT_STATUS_ERROR;
        }

        return AuditStateStore::AUDIT_STATUS_COMPLETED;
    }

    /**
     * Resuelve la severidad general de la auditoría.
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveOverallSeverity(array $findings): string
    {
        $highest = AuditSeverity::LOW->value;
        foreach ($findings as $finding) {
            $severity = (string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value);
            if ($severity === AuditSeverity::HIGH->value) {
                return AuditSeverity::HIGH->value;
            }
            if ($severity === AuditSeverity::MEDIUM->value) {
                $highest = AuditSeverity::MEDIUM->value;
            }
        }

        return $highest;
    }

    /**
     * Resuelve el documento que falló en la auditoría.
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveFailedDocument(array $findings): ?string
    {
        $bestDocument = null;
        $bestPriority = -1;

        foreach ($findings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (!AuditFindingRules::isFailureResult($result)) {
                continue;
            }

            $document = $this->normalizeDocumentName((string) ($finding['documento'] ?? ''));
            if ($document === '') {
                continue;
            }

            $severity = (string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value);
            $priority = AuditFindingRules::findingPriority($severity, $result);
            if ($bestDocument === null || $priority > $bestPriority) {
                $bestDocument = $document;
                $bestPriority = $priority;
            }
        }

        return $bestDocument;
    }

    /**
     * Construye un mensaje de detalle basado en el estado final y las métricas de la auditoría.
     * @param  array<string,int> $metrics
     */
    private function buildDetailMessage(string $finalStatus, array $metrics): string
    {
        return match ($finalStatus) {
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW => sprintf(
                'Auditoria completada con incertidumbre documental: %d campos no concluyentes requieren revision humana.',
                $metrics['no_concluyentes']
            ),
            AuditStateStore::AUDIT_STATUS_ERROR => sprintf(
                'Auditoria completada con discrepancias documentales: %d discrepancias requieren analisis posterior.',
                $metrics['discrepancias']
            ),
            default => sprintf(
                'Auditoria completada sin hallazgos criticos: %d campos evaluados.',
                $metrics['total_campos']
            ),
        };
    }

    /**
     * Calcula la duración total de la auditoría en milisegundos, restando el tiempo
     * dedicado a llamadas a Gemini desde el tiempo transcurrido entre creación y
     * finalización.
     * @param  array<string,mixed> $audit
     */
    private function resolveDurationMs(array $audit): int
    {
        $createdAt = $audit['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return 0;
        }

        try {
            $created = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));
            $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return 0;
        }

        $diffUs = (int) $now->format('Uu') - (int) $created->format('Uu');
        return max(0, (int) round($diffUs / 1000));
    }

    private function normalizeDocumentName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return str_replace('_', ' ', strtoupper($trimmed));
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
            $this->jobStore->markAuditCompletedInJob($event->jobId, $event->auditId, AuditStateStore::AUDIT_STATUS_FAILED);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_FAILED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: array_merge($failedPayload, ['failed_at' => gmdate('Y-m-d\TH:i:s\Z')]),
            parentEventId: $event->eventId,
        ));

        if ($event->jobId !== null && $event->auditId !== null) {
            $this->publishBatchTerminalEventIfNeeded($event->jobId, $event->auditId, $event->eventId);
        }
    }

    /**
     * Publica un evento terminal del batch si se cumplen las condiciones.
     * @param string $jobId
     * @param string $auditId
     * @param string $parentEventId
     */
    private function publishBatchTerminalEventIfNeeded(string $jobId, string $auditId, string $parentEventId): void
    {
        $job = $this->jobStore->getJob($jobId);
        if ($job === null) {
            return;
        }

        $jobStatus = (string) ($job['status'] ?? '');
        $eventType = match ($jobStatus) {
            BatchJobStore::JOB_STATUS_COMPLETED => AuditEvent::TYPE_BATCH_COMPLETED,
            BatchJobStore::JOB_STATUS_COMPLETED_WITH_ERR => AuditEvent::TYPE_BATCH_COMPLETED_ERR,
            default => null,
        };

        if ($eventType === null) {
            return;
        }

        if (!$this->jobStore->claimBatchTerminalEvent($jobId, $eventType)) {
            return;
        }

        $facNitSec = isset($job['fac_nit_sec']) ? (int) $job['fac_nit_sec'] : 0;
        $dateFrom = isset($job['date_from']) ? trim((string) $job['date_from']) : '';
        $dateTo = isset($job['date_to']) ? trim((string) $job['date_to']) : '';
        $normalizedDateTo = $dateTo !== '' ? $dateTo : null;
        if ($facNitSec > 0 && $dateFrom !== '') {
            $this->jobStore->releaseBatchSlot($facNitSec, $dateFrom, $normalizedDateTo);
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: $eventType,
            auditId: $auditId,
            jobId: $jobId,
            payload: [
                'status' => $jobStatus,
                'total' => (int) ($job['total'] ?? 0),
                'done' => (int) ($job['done'] ?? 0),
                'failed' => (int) ($job['failed'] ?? 0),
            ],
            parentEventId: $parentEventId,
        ));
    }
}
