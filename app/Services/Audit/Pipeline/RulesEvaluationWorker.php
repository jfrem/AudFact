<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

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

    public function processEvent(AuditEvent $event): void
    {
        $this->handle($event);
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

        $audit = $this->stateStore->getAudit($event->auditId);
        if ($audit === null) {
            throw new RuntimeException('Auditoría no encontrada en Redis para policy');
        }

        $documentState = $audit['documents'][$event->documentId] ?? null;
        if (!is_array($documentState)) {
            throw new RuntimeException('Documento no encontrado en Redis para policy');
        }

        $policyResult = $this->policyEngine->evaluate($documentState, $event->payload);
        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $documentPatch = [
            'status'               => 'evaluated',
            'policy_result'        => $policyResult,
            'evaluated_at'         => gmdate('Y-m-d\TH:i:s\Z'),
            'policy_duration_ms'   => $durationMs,
        ];

        if (is_array($policyResult['gemini_semantic_metrics'] ?? null)) {
            $documentPatch['gemini_semantic_metrics'] = $policyResult['gemini_semantic_metrics'];
        }

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

        $docsNormalized = (int) ($updatedAudit['docs_done'] ?? 0);
        $docsTotal      = (int) ($updatedAudit['docs_total'] ?? 0);
        $docsEvaluated  = (int) ($updatedAudit['docs_evaluated'] ?? 0);
        if ($docsTotal < 1 || $docsNormalized < $docsTotal || $docsEvaluated < $docsTotal) {
            return;
        }

        $rulesEvaluation = $this->aggregateRulesEvaluation($updatedAudit);
        if (!$this->stateStore->storeRulesEvaluation($event->auditId, $rulesEvaluation)) {
            $latestAudit = $this->stateStore->getAudit($event->auditId);
            if (is_array($latestAudit) && is_array($latestAudit['rules_evaluated_result'] ?? null)) {
                return;
            }

            throw new RuntimeException('No se pudo persistir rules_evaluated en Redis');
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
     * @param  array<string,mixed> $audit
     * @return array<string,mixed>
     */
    private function aggregateRulesEvaluation(array $audit): array
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

        return [
            'hallazgos' => [
                'items' => $allFindings,
                'metrics' => AuditFindingRules::summarizeMetrics($allFindings),
            ],
            'document_decisions' => $documentDecisions,
        ];
    }
}
