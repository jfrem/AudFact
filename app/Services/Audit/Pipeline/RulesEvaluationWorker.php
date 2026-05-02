<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFindingResult;
use App\Services\Audit\AuditSeverity;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\SemanticMatchJudge;
use Core\Logger;
use RuntimeException;

final class RulesEvaluationWorker extends AuditEventConsumer
{
    private const FIELD_DELIVERY_DATE = 'FechaEntrega';
    private const UNIT_DAYS = 'dias';
    private const ROLE_INFORMATIVE = 'INFORMATIVO';

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
        $calculatedFindings = $this->evaluateCalculatedVisualFindings($audit, $allFindings);
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
     * @param  array<string,mixed> $audit
     * @param  array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private function evaluateCalculatedVisualFindings(array $audit, array $findings): array
    {
        $candidate = $this->resolveDeliveryValidityCandidate($audit);
        if ($candidate === null) {
            return [];
        }

        $role = strtoupper((string) ($candidate['expected']['rol'] ?? 'AUTORITATIVO'));
        if ($role === self::ROLE_INFORMATIVE) {
            return [];
        }

        $visual = $candidate['visual'];
        if (!is_array($visual) || ($visual['presente'] ?? false) !== true) {
            return [$this->buildDeliveryValidityInconclusiveFinding($candidate, 'No se encontró una vigencia de entrega visible y estructurada.')];
        }

        $days = $this->resolvePositiveInteger($visual['valor'] ?? null);
        $unit = (string) ($visual['unidad'] ?? '');
        $baseField = trim((string) ($visual['fecha_base'] ?? ''));
        if ($days === null || $unit !== self::UNIT_DAYS || $baseField === '') {
            return [$this->buildDeliveryValidityInconclusiveFinding($candidate, 'La vigencia visible no contiene valor, unidad o fecha base suficiente para calcular.')];
        }

        $deliveryDate = $this->resolveMatchedDate($findings, self::FIELD_DELIVERY_DATE);
        $baseDate = $this->resolveMatchedDate($findings, $baseField);
        if ($deliveryDate === null || $baseDate === null) {
            return [$this->buildDeliveryValidityInconclusiveFinding($candidate, 'FechaEntrega o fecha base no tienen resultado COINCIDE para validar la vigencia.')];
        }

        return [$this->buildDeliveryValidityFinding($candidate, $days, $baseField, $baseDate, $deliveryDate, $role)];
    }

    /**
     * @param  array{document_name:string,expected:array<string,mixed>,visual:?array<string,mixed>} $candidate
     * @return array<string,mixed>
     */
    private function buildDeliveryValidityFinding(
        array $candidate,
        int $days,
        string $baseField,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $deliveryDate,
        string $role
    ): array {
        $limitDate = $baseDate->modify("+{$days} days");
        $matches = $deliveryDate <= $limitDate;
        $baseDateText = $baseDate->format('Y-m-d');
        $deliveryDateText = $deliveryDate->format('Y-m-d');
        $limitDateText = $limitDate->format('Y-m-d');

        return [
            'campo' => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => "{$baseField} {$baseDateText} + {$days} dias = {$limitDateText}",
            'valorDocumento' => "{$deliveryDateText} dentro de {$days} dias",
            'resultado' => $matches ? AuditFindingResult::MATCH->value : AuditFindingResult::MISMATCH->value,
            'severidad' => $this->normalizeSeverity($candidate['expected']['severity'] ?? null),
            'documento' => $candidate['document_name'],
            'detalle' => $matches
                ? "FechaEntrega {$deliveryDateText} dentro de la vigencia hasta {$limitDateText}."
                : "FechaEntrega {$deliveryDateText} supera la vigencia hasta {$limitDateText}.",
            'tipo_auditoria' => 'visual',
            'rol' => $role,
        ];
    }

    /**
     * @param  array<string,mixed> $audit
     * @return array{document_name:string,expected:array<string,mixed>,visual:?array<string,mixed>}|null
     */
    private function resolveDeliveryValidityCandidate(array $audit): ?array
    {
        $fallback = null;

        foreach (($audit['documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $documentName = (string) ($document['tipo_documento'] ?? '');
            $visualResults = $this->indexVisualResults($document['normalized_result']['visual_checks_resultado'] ?? []);
            $sourceTruth = is_array($document['fuente_verdad'] ?? null) ? $document['fuente_verdad'] : [];
            $documentQuality = (string) ($document['normalized_result']['document_quality'] ?? '');

            foreach (($document['visual_checks'] ?? []) as $expected) {
                if (!is_array($expected)) {
                    continue;
                }

                $checkName = trim((string) ($expected['check'] ?? ''));
                if (!AuditFindingRules::isCalculatedVisualCheck($checkName)) {
                    continue;
                }

                if (AuditFindingRules::shouldSkipByCondition($expected['omitirSi'] ?? null, $sourceTruth, $documentQuality)) {
                    continue;
                }

                $candidate = [
                    'document_name' => $documentName,
                    'expected' => $expected,
                    'visual' => $visualResults[$checkName] ?? null,
                ];

                if (is_array($candidate['visual']) && ($candidate['visual']['presente'] ?? false) === true) {
                    return $candidate;
                }

                $fallback ??= $candidate;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<int,array<string,mixed>> $visualResults
     * @return array<string,array<string,mixed>>
     */
    private function indexVisualResults(mixed $visualResults): array
    {
        if (!is_array($visualResults)) {
            return [];
        }

        $indexed = [];
        foreach ($visualResults as $visual) {
            if (!is_array($visual)) {
                continue;
            }

            $check = trim((string) ($visual['check'] ?? ''));
            if ($check !== '') {
                $indexed[$check] = $visual;
            }
        }

        return $indexed;
    }

    private function resolvePositiveInteger(mixed $value): ?int
    {
        if (!is_int($value) || $value < 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     */
    private function resolveMatchedDate(array $findings, string $field): ?\DateTimeImmutable
    {
        foreach ($findings as $finding) {
            if (($finding['campo'] ?? null) !== $field || ($finding['resultado'] ?? null) !== AuditFindingResult::MATCH->value) {
                continue;
            }

            foreach (['valorFuenteVerdad', 'valorDocumento'] as $key) {
                $date = $this->parseIsoDate($finding[$key] ?? null);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function parseIsoDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $candidate = preg_split('/\s+/', trim($value), 2)[0] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $candidate) {
            return null;
        }

        return $date;
    }

    /**
     * @param  array{document_name:string,expected:array<string,mixed>,visual:?array<string,mixed>} $candidate
     * @return array<string,mixed>
     */
    private function buildDeliveryValidityInconclusiveFinding(array $candidate, string $detail): array
    {
        return [
            'campo' => AuditFindingRules::FIELD_DELIVERY_VALIDITY,
            'valorFuenteVerdad' => 'Vigencia calculable requerida',
            'valorDocumento' => null,
            'resultado' => AuditFindingResult::INCONCLUSIVE->value,
            'severidad' => $this->normalizeSeverity($candidate['expected']['severity'] ?? null),
            'documento' => $candidate['document_name'],
            'detalle' => $detail,
            'tipo_auditoria' => 'visual',
            'rol' => strtoupper((string) ($candidate['expected']['rol'] ?? 'AUTORITATIVO')),
        ];
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

    private function normalizeSeverity(mixed $severity): string
    {
        return AuditSeverity::fromInput((string) ($severity ?? AuditSeverity::MEDIUM->value))->value;
    }
}
