<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\DocumentDuplicationEvaluator;
use App\Services\Audit\DeliveryValidityEvaluator;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\ArticleSemanticMatchJudge;
use Core\Logger;
use RuntimeException;
use App\Services\Audit\Telemetry\TelemetryPublisher;

final class RulesEvaluationWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private DocumentPolicyEngine $policyEngine;
    private TelemetryPublisher $telemetryPublisher;
    private AuditPersistenceQueue $persistenceQueue;

    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?DocumentPolicyEngine $policyEngine = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null,
        ?TelemetryPublisher $telemetryPublisher = null,
        ?AuditPersistenceQueue $persistenceQueue = null
    ) {
        parent::__construct($redis, $publisher, $stateStore);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);

        if ($policyEngine === null) {
            $gateway = GeminiGateway::create();
            $semanticJudge = new ArticleSemanticMatchJudge($gateway, $this->redis);
            $this->policyEngine = new DocumentPolicyEngine(semanticJudge: $semanticJudge);
        } else {
            $this->policyEngine = $policyEngine;
        }

        $this->consumerName = $consumerName ?? self::defaultConsumerName(AuditEventPublisher::GROUP_POLICY);
        $this->telemetryPublisher = $telemetryPublisher ?? new TelemetryPublisher($this->redis);
        $this->persistenceQueue = $persistenceQueue ?? new AuditPersistenceQueue($this->redis);
    }

    protected function streams(): array
    {
        return [
            AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY,
            AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
        ];
    }

    protected function group(): string
    {
        return AuditEventPublisher::GROUP_POLICY;
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        $start = microtime(true);

        if (!$this->isPolicyInputEvent($event)) {
            return;
        }

        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException("{$event->eventType} sin audit_id o document_id");
        }

        [$auditContext, $documentState] = $this->loadPolicyContext($event);
        $facNro = (string) ($auditContext['dis_det_nro'] ?? '');

        $telemetryMeta = ['worker' => $this->consumer()];
        $telemetryStartedAt = hrtime(true);
        $this->telemetryPublisher->started(
            $event->auditId,
            'policy',
            $event->documentId,
            $facNro,
            $telemetryMeta,
            $event->jobId
        );

        try {
            $policyResult = $event->eventType === AuditEvent::TYPE_DOCUMENT_REJECTED
                ? $this->buildRejectedPolicyResult($documentState, $event->payload, $facNro)
                : $this->policyEngine->evaluate($documentState, $event->payload, $facNro);
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $documentPatch = $this->buildDocumentPatch($policyResult, $durationMs);
            if (!$this->stateStore->markDocumentEvaluated($event->auditId, $event->documentId, $documentPatch)) {
                throw new RuntimeException('No se pudo persistir la evaluación documental en Redis');
            }

            Logger::info('Document policy evaluation processed', [
                'auditId'            => $event->auditId,
                'documentId'         => $event->documentId,
                'event_type'         => $event->eventType,
                'policy_duration_ms' => $durationMs,
            ]);

            $isRejected = ($policyResult['document_decision']['approved'] ?? true) === false;
            $telemetryAction = $isRejected ? 'rejected' : 'completed';

            $this->telemetryPublisher->$telemetryAction(
                $event->auditId,
                'policy',
                self::elapsedMs($telemetryStartedAt),
                $event->documentId,
                $facNro,
                $telemetryMeta,
                $event->jobId
            );
        } catch (\Throwable $error) {
            $this->telemetryPublisher->failed(
                $event->auditId,
                'policy',
                self::elapsedMs($telemetryStartedAt),
                $event->documentId,
                $facNro,
                array_merge($telemetryMeta, ['error_class' => get_class($error)]),
                $event->jobId
            );
            throw $error;
        }

        $updatedAudit = $this->stateStore->getAudit($event->auditId);
        if ($updatedAudit === null) {
            throw new RuntimeException('Auditoría no disponible después de policy');
        }

        if (!$this->isAuditReadyForRulesEvaluation($updatedAudit)) {
            return;
        }

        $rulesEvaluation = $this->aggregateRulesEvaluation($updatedAudit);
        $rulesEvaluation = $this->ensureRulesEvaluationStored($event, $rulesEvaluation);
        $persistenceEvent = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: $rulesEvaluation,
            parentEventId: $event->eventId,
        );

        $this->persistenceQueue->enqueue($persistenceEvent);
    }

    private function isPolicyInputEvent(AuditEvent $event): bool
    {
        return in_array($event->eventType, [
            AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            AuditEvent::TYPE_DOCUMENT_REJECTED,
        ], true);
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function loadPolicyContext(AuditEvent $event): array
    {
        $audit = $this->stateStore->getAudit((string) $event->auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada en Redis para policy');
        }

        $documentState = $audit['documents'][$event->documentId] ?? null;
        if (!is_array($documentState)) {
            throw new RuntimeException('Documento no encontrado en Redis para policy');
        }

        return [$audit, $documentState];
    }

    /**
     * @param  array<string,mixed> $policyResult
     * @return array<string,mixed>
     */
    private function buildDocumentPatch(array $policyResult, int $durationMs): array
    {
        $documentPatch = [
            'status'             => 'evaluated',
            'policy_result'      => $policyResult,
            'evaluated_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            'policy_duration_ms' => $durationMs,
        ];

        if (is_array($policyResult['gemini_semantic_metrics'] ?? null)) {
            $documentPatch['gemini_semantic_metrics'] = $policyResult['gemini_semantic_metrics'];
        }

        return $documentPatch;
    }

    /**
     * @param  array<string,mixed> $audit
     */
    private function isAuditReadyForRulesEvaluation(array $audit): bool
    {
        $docsNormalized = (int) ($audit['docs_done'] ?? 0);
        $docsRejected   = (int) ($audit['docs_rejected'] ?? 0);
        $docsTotal      = (int) ($audit['docs_total'] ?? 0);
        $docsEvaluated  = (int) ($audit['docs_evaluated'] ?? 0);

        return $docsTotal >= 1
            && ($docsNormalized + $docsRejected) >= $docsTotal
            && $docsEvaluated >= $docsTotal;
    }

    /**
     * @param  array<string,mixed> $rulesEvaluation
     * @return array<string,mixed>
     */
    private function ensureRulesEvaluationStored(
        AuditEvent $event,
        array $rulesEvaluation
    ): array
    {
        if ($this->stateStore->storeRulesEvaluation((string) $event->auditId, $rulesEvaluation)) {
            return $rulesEvaluation;
        }

        $latestAudit = $this->stateStore->getAudit((string) $event->auditId);
        if (is_array($latestAudit) && is_array($latestAudit['rules_evaluated_result'] ?? null)) {
            return $latestAudit['rules_evaluated_result'];
        }

        throw new RuntimeException('No se pudo persistir rules_evaluated en Redis');
    }

    /**
     * @param  array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function aggregateRulesEvaluation(array $audit): array
    {
        $facNro = (string) ($audit['dis_det_nro'] ?? '');
        [$allFindings, $documentDecisions] = $this->collectPolicyOutputs($audit);

        // Incorporar rechazos sintéticos del Orquestador (e.g., Autorizacion='R' sin PDF)
        foreach (($audit['synthetic_rejections'] ?? []) as $rejection) {
            if (is_array($rejection)) {
                $documentDecisions[] = $rejection;
            }
        }

        $calculatedFindings = DeliveryValidityEvaluator::evaluate($audit, $allFindings);
        $duplicatedHashFindings = DocumentDuplicationEvaluator::evaluate($audit);
        $calculatedFindings = array_merge($calculatedFindings, $duplicatedHashFindings);
        $allFindings = array_merge($allFindings, $calculatedFindings);
        $documentDecisions = $this->normalizeDocumentDecisions($documentDecisions);
        $documentDecisions = $this->mergeCalculatedFindingsIntoDecisions($documentDecisions, $calculatedFindings, $facNro);
        $metrics = AuditFindingRules::summarizeMetrics($allFindings);

        $finalStatus = $this->resolveFinalStatus($allFindings, $documentDecisions);
        $requiresManualReview = $this->requiresManualReview($finalStatus);
        $severity = $this->resolveOverallSeverity($allFindings);
        $failedDocument = $this->resolveFailedDocument($allFindings);
        $detailMessage = $this->buildDetailMessage($finalStatus, $metrics);
        $auditResultData = $this->buildAuditResultData(
            $audit,
            $allFindings,
            $metrics,
            $documentDecisions,
            $finalStatus,
            $requiresManualReview,
            $severity,
            $failedDocument,
            $detailMessage
        );

        return [
            'hallazgos' => [
                'items' => $allFindings,
                'metrics' => $metrics,
            ],
            'document_decisions' => $documentDecisions,
            'final_status' => $finalStatus,
            'requires_manual_review' => $requiresManualReview,
            'severity' => $severity,
            'detail_message' => $detailMessage,
            'failed_document' => $failedDocument,
            'audit_result_data' => $auditResultData,
            'completion_payload' => $this->buildCompletionPayload(
                $finalStatus,
                $requiresManualReview,
                $allFindings,
                $metrics,
                $documentDecisions
            ),
        ];
    }

    /**
     * @param  array<string,mixed> $audit
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function collectPolicyOutputs(array $audit): array
    {
        $allFindings = [];
        $documentDecisions = [];

        foreach (($audit['documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $policyResult = $document['policy_result'] ?? null;
            if (!is_array($policyResult)) {
                continue;
            }

            $items = $policyResult['hallazgos']['items'] ?? [];
            if (is_array($items)) {
                foreach (array_filter($items, 'is_array') as $finding) {
                    $allFindings[] = $finding;
                }
            }

            $decision = $policyResult['document_decision'] ?? null;
            if (is_array($decision)) {
                $rawNotes = $document['extraction_result']['quality_notes']
                    ?? $document['normalized_result']['quality_notes']
                    ?? [];
                $qualityNotes = array_values(array_filter(
                    is_array($rawNotes) ? $rawNotes : [],
                    fn($n) => is_string($n) && trim($n) !== ''
                ));
                if ($qualityNotes !== []) {
                    $decision['quality_notes'] = $qualityNotes;
                }
                $documentDecisions[] = $decision;
            }
        }

        return [$allFindings, $documentDecisions];
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildRejectedPolicyResult(array $documentState, array $payload, string $facNro): array
    {
        $rejectionCategory = (string) (
            $payload['rejection_category']
            ?? $documentState['rejection_category']
            ?? ''
        );
        if ($rejectionCategory === DocumentMappingRejectionReason::CATEGORY) {
            return $this->buildMappingRejectedPolicyResult($documentState, $payload, $facNro);
        }

        return $this->buildContentRejectedPolicyResult($documentState, $payload, $facNro);
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildContentRejectedPolicyResult(array $documentState, array $payload, string $facNro): array
    {
        $documentName = DocumentExtractionContractBuilder::normalizeDocumentName(
            (string) (
                $payload['document_type']
                ?? $documentState['document_type']
                ?? $documentState['tipo_documento']
                ?? $payload['tipo_documento']
                ?? 'DOCUMENTO'
            )
        );
        $rejectionClass = (string) (
            $payload['rejection_class']
            ?? $documentState['rejection_class']
            ?? ''
        );
        $rejectionOrigin = (string) (
            $payload['rejection_origin']
            ?? $documentState['rejection_origin']
            ?? ''
        );
        $reason = (string) (
            $payload['rejection_reason']
            ?? $documentState['rejection_reason']
            ?? ''
        );
        if (
            $rejectionClass !== DocumentRejectionReason::REJECTION_CLASS
            || $rejectionOrigin !== DocumentExtractionWorker::class
            || !DocumentRejectionReason::isAllowed($reason)
        ) {
            throw new \DomainException(
                'document_rejected no cumple el contrato de rechazo documental.'
            );
        }

        $detail = "Documento rechazado por validación de integridad: {$reason}";
        $finding = [
            'severidad'         => AuditSeverity::HIGH->value,
            'campo'             => 'INTEGRIDAD_DOCUMENTO',
            'documento'         => $documentName,
            'valorDocumento'    => null,
            'valorFuenteVerdad' => null,
            'resultado'         => AuditFindingResult::REJECTED->value,
            'detalle'           => $detail,
            'tipo_auditoria'    => 'integrity',
        ];

        return [
            'document_name' => $documentName,
            'hallazgos' => [
                'items' => [$finding],
                'metrics' => AuditFindingRules::summarizeMetrics([$finding]),
            ],
            'document_decision' => [
                'documentName' => $documentName,
                'approved'     => false,
                'doc_id'       => $documentState['doc_id'] ?? $payload['doc_id'] ?? null,
                'attachment_id'=> $documentState['attachment_id'] ?? $payload['attachment_id'] ?? null,
                'rejection_class' => $rejectionClass,
                'rejection_reason' => $reason,
                'payload'      => AuditFindingRules::buildRejectionPayload($facNro, [
                    [
                        'Codigo' => 'INTEGRIDAD',
                        'Descripcion' => "Documento no procesable: {$reason}"
                    ]
                ]),
            ],
        ];
    }

    /**
     * @param  array<string,mixed> $documentState
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildMappingRejectedPolicyResult(array $documentState, array $payload, string $facNro): array
    {
        $documentName = DocumentExtractionContractBuilder::normalizeDocumentName(
            (string) (
                $payload['document_type']
                ?? $documentState['document_type']
                ?? $documentState['tipo_documento']
                ?? 'DOCUMENTO'
            )
        );
        $rejectionOrigin = (string) (
            $payload['rejection_origin']
            ?? $documentState['rejection_origin']
            ?? ''
        );
        $reason = (string) (
            $payload['rejection_reason']
            ?? $documentState['rejection_reason']
            ?? ''
        );
        if (
            $rejectionOrigin !== DocumentAuditOrchestrator::class
            || !DocumentMappingRejectionReason::isAllowed($reason)
        ) {
            throw new \DomainException(
                'document_rejected no cumple el contrato de rechazo de asociación documental.'
            );
        }

        $logicalDocId = trim((string) (
            $payload['logical_doc_id']
            ?? $documentState['logical_doc_id']
            ?? $documentState['doc_id']
            ?? ''
        ));
        if ($logicalDocId === '') {
            throw new \DomainException('document_rejected de mapping sin logical_doc_id.');
        }

        $rawCandidates = $payload['candidate_attachment_ids']
            ?? $documentState['candidate_attachment_ids']
            ?? [];
        if (!is_array($rawCandidates)) {
            throw new \DomainException('document_rejected de mapping con candidatos inválidos.');
        }
        $candidateAttachmentIds = [];
        foreach ($rawCandidates as $candidateId) {
            if (!is_scalar($candidateId)) {
                throw new \DomainException('document_rejected de mapping con candidato inválido.');
            }
            $candidateId = trim((string) $candidateId);
            if ($candidateId !== '') {
                $candidateAttachmentIds[$candidateId] = true;
            }
        }
        $candidateAttachmentIds = array_map('strval', array_keys($candidateAttachmentIds));
        sort($candidateAttachmentIds, SORT_NATURAL);

        $detail = "No fue posible asociar de forma inequívoca el documento lógico '{$documentName}' con un adjunto físico: {$reason}.";
        $finding = [
            'severidad'         => AuditSeverity::HIGH->value,
            'campo'             => 'ASOCIACION_DOCUMENTAL',
            'codigoCampo'       => 'MAP',
            'documento'         => $documentName,
            'valorDocumento'    => null,
            'valorFuenteVerdad' => null,
            'resultado'         => AuditFindingResult::REJECTED->value,
            'detalle'           => $detail,
            'tipo_auditoria'    => 'integrity',
        ];

        return [
            'document_name' => $documentName,
            'hallazgos' => [
                'items' => [$finding],
                'metrics' => AuditFindingRules::summarizeMetrics([$finding]),
            ],
            'document_decision' => [
                'documentName' => $documentName,
                'approved' => false,
                'doc_id' => $logicalDocId,
                'attachment_id' => null,
                'candidate_attachment_ids' => $candidateAttachmentIds,
                'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
                'rejection_reason' => $reason,
                'payload' => AuditFindingRules::buildRejectionPayload($facNro, [
                    [
                        'Codigo' => 'MAP',
                        'Descripcion' => $detail,
                    ],
                ]),
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $decisions
     * @return array<int,array{documentName:string,approved:bool,payload?:array<string,mixed>}>
     */
    private function normalizeDocumentDecisions(array $decisions): array
    {
        $normalized = [];
        foreach ($decisions as $decision) {
            $name = DocumentExtractionContractBuilder::normalizeDocumentName((string) ($decision['documentName'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = $decision['payload'] ?? null;
            $normalizedDecision = [
                'documentName' => $name,
                'approved' => (bool) ($decision['approved'] ?? false),
                'payload' => is_array($payload) ? $payload : null,
                'doc_id' => is_scalar($decision['doc_id'] ?? null) ? (string) $decision['doc_id'] : null,
                'attachment_id' => is_scalar($decision['attachment_id'] ?? null) ? (string) $decision['attachment_id'] : null,
            ];
            if (array_key_exists('rejection_reason', $decision)) {
                $normalizedDecision['rejection_reason'] = (string) (
                    $decision['rejection_reason'] ?? ''
                );
                if (array_key_exists('rejection_class', $decision)) {
                    $normalizedDecision['rejection_class'] = (string) $decision['rejection_class'];
                }
                if (array_key_exists('rejection_category', $decision)) {
                    $normalizedDecision['rejection_category'] = (string) $decision['rejection_category'];
                }
                if (array_key_exists('candidate_attachment_ids', $decision)) {
                    $normalizedDecision['candidate_attachment_ids'] = is_array($decision['candidate_attachment_ids'])
                        ? array_values($decision['candidate_attachment_ids'])
                        : [];
                }
            }
            $normalized[] = $normalizedDecision;
        }

        return $normalized;
    }

    private function requiresManualReview(string $finalStatus): bool
    {
        return in_array($finalStatus, [
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW,
            AuditStateStore::AUDIT_STATUS_FAILED,
        ], true);
    }

    /**
     * @param  array<string,mixed> $audit
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<string,int> $metrics
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     * @return array<string,mixed>
     */
    private function buildAuditResultData(
        array $audit,
        array $findings,
        array $metrics,
        array $documentDecisions,
        string $finalStatus,
        bool $requiresManualReview,
        string $severity,
        ?string $failedDocument,
        string $detailMessage
    ): array {
        $phaseTimings = AuditTimingSummarizer::buildPhaseTimings($audit);
        $processingDurationMs = (int) ($phaseTimings['processing_duration_ms'] ?? 0);

        return [
            'DisId' => (string) ($audit['dis_id'] ?? ''),
            'FacNro' => (string) ($audit['dis_det_nro'] ?? ''),
            'EstAud' => $finalStatus === AuditStateStore::AUDIT_STATUS_COMPLETED ? 1 : 0,
            'EstadoDetallado' => $finalStatus,
            'RequiereRevisionHumana' => $requiresManualReview ? 1 : 0,
            'Severidad' => $severity,
            'Hallazgos' => json_encode([
                'items' => $findings,
                'field_decisions' => $findings,
                'document_decisions' => $documentDecisions,
                'metrics' => $metrics,
                'timings' => $phaseTimings,
                'total_duration_ms' => $processingDurationMs,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'DetalleError' => $detailMessage,
            'DocumentosProcesados' => count(is_array($audit['documents'] ?? null) ? $audit['documents'] : []),
            'DocumentoFallido' => $failedDocument,
            'DuracionProcesamientoMs' => $processingDurationMs,
            'FacNitSec' => (string) ($audit['fac_nit_sec'] ?? ''),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<string,int> $metrics
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     * @return array<string,mixed>
     */
    private function buildCompletionPayload(
        string $finalStatus,
        bool $requiresManualReview,
        array $findings,
        array $metrics,
        array $documentDecisions
    ): array {
        return [
            'status' => $finalStatus,
            'requires_manual_review' => $requiresManualReview,
            'audit_result' => [
                'hallazgos' => [
                    'items' => $findings,
                    'metrics' => $metrics,
                ],
                'document_decisions' => $documentDecisions,
            ],
            'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     */
    private function resolveFinalStatus(array $findings, array $documentDecisions): string
    {
        $hasHighSeverityFailure = false;
        $hasNonCriticalFailure = false;

        foreach ($findings as $finding) {
            $resultEnum = AuditFindingResult::tryFrom((string) ($finding['resultado'] ?? ''));
            if ($resultEnum === null || !$resultEnum->isFailure()) {
                continue;
            }

            $severity = AuditSeverity::fromInput((string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value))->value;
            if ($severity === AuditSeverity::HIGH->value) {
                $hasHighSeverityFailure = true;
            } else {
                $hasNonCriticalFailure = true;
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
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveOverallSeverity(array $findings): string
    {
        $highest = AuditSeverity::LOW->value;
        foreach ($findings as $finding) {
            $severity = AuditSeverity::fromInput((string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value))->value;
            if ($severity === AuditSeverity::HIGH->value) {
                return AuditSeverity::HIGH->value;
            }
            if ($severity === AuditSeverity::MEDIUM->value) {
                $highest = AuditSeverity::MEDIUM->value;
            }
        }

        return $highest;
    }

    private static function elapsedMs(int $start): int
    {
        return max(0, (int) round((hrtime(true) - $start) / 1_000_000));
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveFailedDocument(array $findings): ?string
    {
        $bestDocument = null;
        $bestPriority = -1;

        foreach ($findings as $finding) {
            $resultEnum = AuditFindingResult::tryFrom((string) ($finding['resultado'] ?? ''));
            if ($resultEnum === null || !$resultEnum->isFailure()) {
                continue;
            }

            $document = DocumentExtractionContractBuilder::normalizeDocumentName((string) ($finding['documento'] ?? ''));
            if ($document === '') {
                continue;
            }

            $severity = AuditSeverity::fromInput((string) ($finding['severidad'] ?? AuditSeverity::MEDIUM->value))->value;
            $priority = AuditFindingRules::findingPriority($severity, $resultEnum->value);
            if ($bestDocument === null || $priority > $bestPriority) {
                $bestDocument = $document;
                $bestPriority = $priority;
            }
        }

        return $bestDocument;
    }

    /**
     * @param  array<string,int> $metrics
     */
    private function buildDetailMessage(string $finalStatus, array $metrics): string
    {
        return match ($finalStatus) {
            AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW => $this->buildManualReviewDetailMessage($metrics),
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
     * @param  array<string,int> $metrics
     */
    private function buildManualReviewDetailMessage(array $metrics): string
    {
        $nonConclusive = (int) ($metrics['no_concluyentes'] ?? 0);
        if ($nonConclusive > 0) {
            return sprintf(
                'Auditoria completada con incertidumbre documental: %d campos no concluyentes requieren revision humana.',
                $nonConclusive
            );
        }

        return sprintf(
            'Auditoria requiere revision humana por %d hallazgos documentales de alta severidad.',
            (int) ($metrics['discrepancias'] ?? 0)
        );
    }

    /**
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     * @param  array<int,array<string,mixed>> $calculatedFindings
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function mergeCalculatedFindingsIntoDecisions(array $documentDecisions, array $calculatedFindings, string $facNro): array
    {
        foreach ($calculatedFindings as $finding) {
            $resultEnum = AuditFindingResult::tryFrom((string) ($finding['resultado'] ?? ''));
            if ($resultEnum === null || !$resultEnum->isFailure()) {
                continue;
            }

            $documentName = DocumentExtractionContractBuilder::normalizeDocumentName((string) ($finding['documento'] ?? ''));
            if ($documentName === '') {
                continue;
            }

            $detail = trim((string) ($finding['detalle'] ?? ''));
            $codigo = trim((string) ($finding['codigoCampo'] ?? 'CALC'));
            $updated = false;
            foreach ($documentDecisions as &$decision) {
                if (DocumentExtractionContractBuilder::normalizeDocumentName((string) ($decision['documentName'] ?? '')) !== $documentName) {
                    continue;
                }

                if ($this->decisionAlreadyHasFinding($decision, $codigo)) {
                    continue;
                }

                $this->rejectDocumentDecision($decision, $codigo, $detail, $facNro);
                $updated = true;
                break;
            }
            unset($decision);

            if (!$updated) {
                $documentDecisions[] = [
                    'documentName' => $documentName,
                    'approved' => false,
                    'payload' => AuditFindingRules::buildRejectionPayload($facNro, [
                        [
                            'Codigo' => $codigo,
                            'Descripcion' => $detail,
                        ]
                    ]),
                ];
            }
        }

        return $documentDecisions;
    }

    /**
     * @param  array<string,mixed> $decision
     */
    private function decisionAlreadyHasFinding(array $decision, string $codigo): bool
    {
        if (!isset($decision['payload']['hallazgos']) || !is_array($decision['payload']['hallazgos'])) {
            return false;
        }

        foreach ($decision['payload']['hallazgos'] as $finding) {
            if (($finding['Codigo'] ?? '') === $codigo) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed> $decision
     */
    private function rejectDocumentDecision(array &$decision, string $codigo, string $detail, string $facNro): void
    {
        $decision['approved'] = false;

        if (!isset($decision['payload']) || !is_array($decision['payload'])) {
            $decision['payload'] = AuditFindingRules::buildRejectionPayload($facNro, []);
        }

        $decision['payload']['state'] = false;
        $decision['payload']['hallazgos'][] = [
            'Codigo' => $codigo,
            'Descripcion' => $detail !== '' ? $detail : 'Hallazgo calculado sin detalle adicional.',
        ];
    }
}
