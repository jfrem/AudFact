<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\RulesEvaluationWorker;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RulesEvaluationWorkerTest extends TestCase
{
    public function testDocumentNormalizedPublishesRulesEvaluatedWhenAuditIsReady(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesReadyStateStore($auditId, $documentId);

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine([
                'document_name' => 'DISPENSA',
                'hallazgos' => [
                    'items' => [[
                        'campo' => 'Autorizacion',
                        'valorFuenteVerdad' => '46338218',
                        'valorDocumento' => '46338218',
                        'resultado' => 'COINCIDE',
                        'severidad' => 'alta',
                        'documento' => 'DISPENSA',
                    ]],
                    'metrics' => [
                        'total_campos' => 1,
                        'coincidencias' => 1,
                        'discrepancias' => 0,
                        'omitidos' => 0,
                        'no_concluyentes' => 0,
                        'risk_score' => 0,
                    ],
                ],
                'document_decision' => [
                    'documentName' => 'DISPENSA',
                    'approved' => true,
                    'observation' => null,
                ],
                'gemini_semantic_metrics' => [
                    'semantic' => [[
                        'task_type' => 'semantic_match',
                        'duration_ms' => 120,
                        'cache_hit' => false,
                        'total_tokens' => 100,
                    ]],
                    'semantic_calls' => 1,
                    'semantic_cache_hits' => 0,
                ],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            auditId: $auditId,
            documentId: $documentId,
            payload: [
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => ['Autorizacion' => '46338218'],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
                'normalization_log' => [],
            ]
        ));

        $this->assertSame('evaluated', $store->lastPolicyPatch['status'] ?? null);
        $this->assertSame(1, $store->lastPolicyPatch['gemini_semantic_metrics']['semantic_calls'] ?? null);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_RULES_EVALUATED, $publisher->published[0]->eventType);
        $this->assertSame(1, $publisher->published[0]->payload['hallazgos']['metrics']['total_campos']);
    }

    public function testDoesNotPublishRulesEvaluatedWhenStateStoreFailsPolicyPersistence(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesFailingStateStore($auditId, $documentId);

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine([
                'document_name' => 'DISPENSA',
                'hallazgos' => ['items' => [], 'metrics' => []],
                'document_decision' => ['documentName' => 'DISPENSA', 'approved' => true, 'observation' => null],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo persistir la evaluación documental');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
                auditId: $auditId,
                documentId: $documentId,
                payload: [
                    'tipo_documento' => 'DISPENSA',
                    'fields_normalized' => [],
                    'items_normalized' => [],
                    'visual_checks_resultado' => [],
                    'document_quality' => 'legible',
                    'normalization_log' => [],
                ]
            ));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }
}

final class StubDocumentPolicyEngine extends DocumentPolicyEngine
{
    /**
     * @param array<string,mixed> $result
     */
    public function __construct(private array $result)
    {
    }

    public function evaluate(array $documentState, array $normalizedPayload): array
    {
        return $this->result;
    }
}

class RulesReadyStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    public array $lastPolicyPatch = [];
    /** @var array<string,mixed> */
    private array $audit;
    private bool $rulesStored = false;

    public function __construct(private string $auditId, private string $documentId)
    {
        $this->audit = [
            'audit_id' => $auditId,
            'docs_total' => 1,
            'docs_done' => 1,
            'docs_evaluated' => 0,
            'documents' => [
                $documentId => [
                    'tipo_documento' => 'DISPENSA',
                    'status' => 'normalized',
                    'fuente_verdad' => ['header' => [], 'items' => []],
                    'visual_checks' => [],
                ],
            ],
        ];
    }

    public function getAudit(string $auditId): ?array
    {
        return $this->audit;
    }

    public function markDocumentEvaluated(string $auditId, string $documentId, array $policyState): bool
    {
        $this->lastPolicyPatch = $policyState;
        $this->audit['docs_evaluated'] = 1;
        $this->audit['documents'][$documentId] = array_merge($this->audit['documents'][$documentId], $policyState);
        return true;
    }

    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        if ($this->rulesStored) {
            return false;
        }

        $this->rulesStored = true;
        $this->audit['rules_evaluated_result'] = $rulesEvaluation;
        return true;
    }
}

final class RulesFailingStateStore extends RulesReadyStateStore
{
    public function markDocumentEvaluated(string $auditId, string $documentId, array $policyState): bool
    {
        return false;
    }
}

final class RulesPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stream-' . count($this->published);
    }
}
