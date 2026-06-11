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
        $this->assertSame('completed', $publisher->published[0]->payload['final_status']);
        $this->assertSame('87723098', $publisher->published[0]->payload['audit_result_data']['DisId']);
    }

    public function testDocumentRejectedPublishesRulesEvaluatedWithCanonicalFinding(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesRejectedStateStore($auditId, $documentId);

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(['unexpected' => true]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: $auditId,
            documentId: $documentId,
            payload: [
                'document_type' => 'FORMULA MEDICA',
                'rejection_reason' => 'UNKNOWN_FILE_SIGNATURE',
            ]
        ));

        $this->assertSame('evaluated', $store->lastPolicyPatch['status'] ?? null);
        $this->assertCount(1, $publisher->published);
        $payload = $publisher->published[0]->payload;
        $finding = $payload['hallazgos']['items'][0];

        $this->assertSame(AuditEvent::TYPE_RULES_EVALUATED, $publisher->published[0]->eventType);
        $this->assertSame('RECHAZADO', $finding['resultado']);
        $this->assertSame('integrity', $finding['tipo_auditoria']);
        $this->assertSame('FORMULA MEDICA', $finding['documento']);
        $this->assertSame(1, $payload['hallazgos']['metrics']['discrepancias']);
        $this->assertSame('manual_review', $payload['final_status']);
        $this->assertFalse($payload['document_decisions'][0]['approved']);
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

    public function testPublishesCalculatedDeliveryValidityFindingWhenVisualEvidenceIsComplete(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesDeliveryValidityStateStore(
            $auditId,
            $authorizationDocumentId,
            deliveryDate: '2025-07-29',
            visualResult: [
                'check' => 'VigenciaEntrega',
                'presente' => true,
                'valor' => 60,
                'unidad' => 'dias',
                'fecha_base' => 'FechaAutorizacion',
                'severidad' => 'ALTA',
            ]
        );

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(self::authorizationPolicyResult('2025-07-27')),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $findings = $publisher->published[0]->payload['hallazgos']['items'];
        $vigencia = end($findings);
        $this->assertSame('VigenciaEntrega', $vigencia['campo']);
        $this->assertSame('COINCIDE', $vigencia['resultado']);
        $this->assertSame(3, $publisher->published[0]->payload['hallazgos']['metrics']['total_campos']);
        $this->assertSame(3, $publisher->published[0]->payload['hallazgos']['metrics']['coincidencias']);
    }

    public function testCalculatedDeliveryValidityMarksDocumentWhenExpired(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesDeliveryValidityStateStore(
            $auditId,
            $authorizationDocumentId,
            deliveryDate: '2025-10-01',
            visualResult: [
                'check' => 'VigenciaEntrega',
                'presente' => true,
                'valor' => 60,
                'unidad' => 'dias',
                'fecha_base' => 'FechaAutorizacion',
                'severidad' => 'ALTA',
            ]
        );

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(self::authorizationPolicyResult('2025-07-27')),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $payload = $publisher->published[0]->payload;
        $vigencia = end($payload['hallazgos']['items']);
        $this->assertSame('VALOR_DISTINTO', $vigencia['resultado']);
        $this->assertStringContainsString('-VIG- ', (string) $vigencia['detalle']);
        $this->assertSame(1, $payload['hallazgos']['metrics']['discrepancias']);
        $this->assertFalse($payload['document_decisions'][1]['approved']);
        $this->assertStringContainsString('supera la vigencia', $payload['document_decisions'][1]['observation']);
    }

    public function testCalculatedDeliveryValidityFallsBackToDefaultWhenAuthoritativeEvidenceIsIncomplete(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $store = new RulesDeliveryValidityStateStore(
            $auditId,
            $authorizationDocumentId,
            deliveryDate: '2025-07-29',
            visualResult: [
                'check' => 'VigenciaEntrega',
                'presente' => true,
                'valor' => null,
                'unidad' => 'dias',
                'fecha_base' => 'FechaAutorizacion',
                'severidad' => 'ALTA',
            ]
        );

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(self::authorizationPolicyResult('2025-07-27')),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test'
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $payload = $publisher->published[0]->payload;
        $vigencia = end($payload['hallazgos']['items']);
        $this->assertSame('COINCIDE', $vigencia['resultado']);
        $this->assertSame(3, $payload['hallazgos']['metrics']['coincidencias']);
        $this->assertStringContainsString('por defecto del sistema', $vigencia['detalle']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function authorizationPolicyResult(string $authorizationDate): array
    {
        return [
            'document_name' => 'AUTORIZACION',
            'hallazgos' => [
                'items' => [[
                    'campo' => 'FechaAutorizacion',
                    'valorFuenteVerdad' => $authorizationDate,
                    'valorDocumento' => $authorizationDate,
                    'resultado' => 'COINCIDE',
                    'severidad' => 'alta',
                    'documento' => 'AUTORIZACION',
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
                'documentName' => 'AUTORIZACION',
                'approved' => true,
                'observation' => null,
            ],
        ];
    }

    private static function authorizationNormalizedEvent(string $auditId, string $documentId): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            auditId: $auditId,
            documentId: $documentId,
            payload: [
                'tipo_documento' => 'AUTORIZACION',
                'fields_normalized' => ['FechaAutorizacion' => '2025-07-27'],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
                'normalization_log' => [],
            ]
        );
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
            'dis_id' => '87723098',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2426',
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

final class RulesRejectedStateStore extends AuditStateStore
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
            'dis_id' => '87723098',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2426',
            'docs_total' => 1,
            'docs_done' => 0,
            'docs_rejected' => 1,
            'docs_evaluated' => 0,
            'documents' => [
                $documentId => [
                    'document_type' => 'FORMULA MEDICA',
                    'status' => 'rejected',
                    'rejection_reason' => 'UNKNOWN_FILE_SIGNATURE',
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

final class RulesDeliveryValidityStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    private array $audit;

    public function __construct(
        private string $auditId,
        private string $authorizationDocumentId,
        string $deliveryDate,
        array $visualResult
    ) {
        $dispensaDocumentId = AuditEvent::uuidV4();
        $this->audit = [
            'audit_id' => $auditId,
            'dis_id' => '87723098',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2426',
            'docs_total' => 2,
            'docs_done' => 2,
            'docs_evaluated' => 1,
            'documents' => [
                $dispensaDocumentId => [
                    'tipo_documento' => 'DISPENSA',
                    'status' => 'evaluated',
                    'fuente_verdad' => [
                        'header' => ['FechaEntrega' => $deliveryDate, 'FechaAutorizacion' => '2025-07-27'],
                        'items' => []
                    ],
                    'visual_checks' => [],
                    'policy_result' => [
                        'document_name' => 'DISPENSA',
                        'hallazgos' => [
                            'items' => [[
                                'campo' => 'FechaEntrega',
                                'valorFuenteVerdad' => $deliveryDate,
                                'valorDocumento' => $deliveryDate,
                                'resultado' => 'COINCIDE',
                                'severidad' => 'alta',
                                'documento' => 'DISPENSA',
                            ]],
                        ],
                        'document_decision' => [
                            'documentName' => 'DISPENSA',
                            'approved' => true,
                            'observation' => null,
                        ],
                    ],
                ],
                $authorizationDocumentId => [
                    'tipo_documento' => 'AUTORIZACION',
                    'status' => 'normalized',
                    'fuente_verdad' => [
                        'header' => ['FechaEntrega' => $deliveryDate, 'FechaAutorizacion' => '2025-07-27'],
                        'items' => []
                    ],
                    'visual_checks' => [[
                        'check' => 'VigenciaEntrega',
                        'description' => 'Vigencia visible',
                        'severity' => 'alta',
                        'codigoCampo' => 'VIG',
                    ]],
                    'normalized_result' => [
                        'tipo_documento' => 'AUTORIZACION',
                        'fields_normalized' => ['FechaAutorizacion' => '2025-07-27'],
                        'items_normalized' => [],
                        'visual_checks_resultado' => [$visualResult],
                        'document_quality' => 'legible',
                    ],
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
        $this->audit['docs_evaluated'] = 2;
        $this->audit['documents'][$this->authorizationDocumentId] = array_merge(
            $this->audit['documents'][$this->authorizationDocumentId],
            $policyState
        );
        return true;
    }

    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        $this->audit['rules_evaluated_result'] = $rulesEvaluation;
        return true;
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
