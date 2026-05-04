<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditFindingRules;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\SemanticMatchJudge;
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
            $semanticJudge = new SemanticMatchJudge($gateway, $this->redis);
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
        $calculatedFindings = AuditFindingRules::evaluateDeliveryValidity($audit, $allFindings);
        $allFindings = array_merge($allFindings, $calculatedFindings);

        return [
            'hallazgos' => [
                'items' => $allFindings,
                'metrics' => AuditFindingRules::summarizeMetrics($allFindings),
            ],
            'document_decisions' => $this->mergeCalculatedFindingsIntoDecisions($documentDecisions, $calculatedFindings),
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
     * @param  array<int,array{documentName:string,approved:bool,observation:?string}> $documentDecisions
     * @param  array<int,array<string,mixed>> $calculatedFindings
     * @return array<int,array{documentName:string,approved:bool,observation:?string}>
     */
    private function mergeCalculatedFindingsIntoDecisions(array $documentDecisions, array $calculatedFindings): array
    {
        foreach ($calculatedFindings as $finding) {
            $result = (string) ($finding['resultado'] ?? '');
            if (!AuditFindingRules::isFailureResult($result)) {
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
