<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\DeliveryValidityEvaluator;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\ArticleSemanticMatchJudge;
use Core\Logger;
use RuntimeException;

final class RulesEvaluationWorker extends AuditEventConsumer
{
    private AuditStateStore $stateStore;
    private DocumentPolicyEngine $policyEngine;

    private string $consumerName;

    public function __construct(
        ?AuditStateStore $stateStore = null,
        ?DocumentPolicyEngine $policyEngine = null,
        ?\Core\RedisClient $redis = null,
        ?AuditEventPublisher $publisher = null,
        ?string $consumerName = null
    ) {
        parent::__construct($redis, $publisher);

        $this->stateStore = $stateStore ?? new AuditStateStore($this->redis);

        if ($policyEngine === null) {
            $gateway = GeminiGateway::create();
            $semanticJudge = new ArticleSemanticMatchJudge($gateway, $this->redis);
            $this->policyEngine = new DocumentPolicyEngine(semanticJudge: $semanticJudge);
        } else {
            $this->policyEngine = $policyEngine;
        }

        $this->consumerName = $consumerName ?? ('policy-' . getmypid());
    }

    protected function stream(): string
    {
        return AuditEventPublisher::STREAM_DOCUMENTS;
    }

    protected function group(): string
    {
        return 'policy';
    }

    protected function consumer(): string
    {
        return $this->consumerName;
    }

    protected function handle(AuditEvent $event): void
    {
        $start = microtime(true);

        if ($event->eventType !== AuditEvent::TYPE_DOCUMENT_NORMALIZED) {
            return;
        }

        if ($event->auditId === null || $event->documentId === null) {
            throw new RuntimeException('document_normalized sin audit_id o document_id');
        }

        [, $documentState] = $this->loadPolicyContext($event);
        $policyResult = $this->policyEngine->evaluate($documentState, $event->payload);
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $documentPatch = $this->buildDocumentPatch($policyResult, $durationMs);
        if (!$this->stateStore->markDocumentEvaluated($event->auditId, $event->documentId, $documentPatch)) {
            throw new RuntimeException('No se pudo persistir la evaluación documental en Redis');
        }

        Logger::info('Document policy evaluation processed', [
            'auditId'            => $event->auditId,
            'documentId'         => $event->documentId,
            'policy_duration_ms' => $durationMs,
        ]);

        $updatedAudit = $this->stateStore->getAudit($event->auditId);
        if ($updatedAudit === null) {
            throw new RuntimeException('Auditoría no disponible después de policy');
        }

        if (!$this->isAuditReadyForRulesEvaluation($updatedAudit)) {
            return;
        }

        $rulesEvaluation = $this->aggregateRulesEvaluation($updatedAudit);
        if (!$this->storeRulesEvaluationOnce($event, $rulesEvaluation)) {
            return;
        }

        $this->publisher->publish(AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $event->auditId,
            jobId: $event->jobId,
            payload: $rulesEvaluation,
            parentEventId: $event->eventId,
        ));
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
        $docsTotal      = (int) ($audit['docs_total'] ?? 0);
        $docsEvaluated  = (int) ($audit['docs_evaluated'] ?? 0);

        return $docsTotal >= 1 && $docsNormalized >= $docsTotal && $docsEvaluated >= $docsTotal;
    }

    /**
     * @param  array<string,mixed> $rulesEvaluation
     */
    private function storeRulesEvaluationOnce(AuditEvent $event, array $rulesEvaluation): bool
    {
        if ($this->stateStore->storeRulesEvaluation((string) $event->auditId, $rulesEvaluation)) {
            return true;
        }

        $latestAudit = $this->stateStore->getAudit((string) $event->auditId);
        if (is_array($latestAudit) && is_array($latestAudit['rules_evaluated_result'] ?? null)) {
            return false;
        }

        throw new RuntimeException('No se pudo persistir rules_evaluated en Redis');
    }

    /**
     * @param  array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function aggregateRulesEvaluation(array $audit): array
    {
        [$allFindings, $documentDecisions] = $this->collectPolicyOutputs($audit);
        $calculatedFindings = DeliveryValidityEvaluator::evaluate($audit, $allFindings);
        $allFindings = array_merge($allFindings, $calculatedFindings);
        $documentDecisions = $this->normalizeDocumentDecisions($documentDecisions);
        $documentDecisions = $this->mergeCalculatedFindingsIntoDecisions($documentDecisions, $calculatedFindings);
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
                $documentDecisions[] = $decision;
            }
        }

        return [$allFindings, $documentDecisions];
    }

    /**
     * @param  array<int,array<string,mixed>> $decisions
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function normalizeDocumentDecisions(array $decisions): array
    {
        $normalized = [];
        foreach ($decisions as $decision) {
            $name = DocumentExtractionContractBuilder::normalizeDocumentName((string) ($decision['documentName'] ?? ''));
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
        return [
            'FacSec' => (string) ($audit['fac_sec'] ?? ''),
            'FacNro' => (string) ($audit['dis_det_nro'] ?? ''),
            'EstAud' => $finalStatus === AuditStateStore::AUDIT_STATUS_FAILED ? 0 : 1,
            'EstadoDetallado' => $finalStatus,
            'RequiereRevisionHumana' => $requiresManualReview ? 1 : 0,
            'Severidad' => $severity,
            'Hallazgos' => json_encode([
                'items' => $findings,
                'field_decisions' => $findings,
                'document_decisions' => $documentDecisions,
                'metrics' => $metrics,
                'timings' => AuditTimingSummarizer::buildPhaseTimings($audit),
                'total_duration_ms' => AuditTimingSummarizer::resolveDurationMs($audit),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'DetalleError' => $detailMessage,
            'DocumentosProcesados' => count(is_array($audit['documents'] ?? null) ? $audit['documents'] : []),
            'DocumentoFallido' => $failedDocument,
            'DuracionProcesamientoMs' => AuditTimingSummarizer::resolveDurationMs($audit),
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
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     * @param  array<int,array<string,mixed>> $calculatedFindings
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function mergeCalculatedFindingsIntoDecisions(array $documentDecisions, array $calculatedFindings): array
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
            $updated = false;
            foreach ($documentDecisions as &$decision) {
                if (DocumentExtractionContractBuilder::normalizeDocumentName((string) ($decision['documentName'] ?? '')) !== $documentName) {
                    continue;
                }

                $this->rejectDocumentDecision($decision, $detail);
                $updated = true;
                break;
            }
            unset($decision);

            if (!$updated) {
                $documentDecisions[] = [
                    'documentName' => $documentName,
                    'approved' => false,
                    'observation' => $detail === '' ? null : $detail,
                ];
            }
        }

        return $documentDecisions;
    }

    /**
     * @param  array<string,mixed> $decision
     */
    private function rejectDocumentDecision(array &$decision, string $detail): void
    {
        $decision['approved'] = false;
        if ($detail === '') {
            return;
        }

        $existing = trim((string) ($decision['observation'] ?? ''));
        $decision['observation'] = $existing === '' ? $detail : "{$existing} | {$detail}";
    }
}
