<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\DocumentExtractionWorker;
use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\DocumentMappingRejectionReason;
use App\Services\Audit\Pipeline\DocumentRejectionReason;
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
        $queue = new RulesPersistenceQueue();
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
            consumerName: 'policy-test',
            persistenceQueue: $queue
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
        $this->assertCount(1, $queue->enqueued);
        $this->assertSame(AuditEvent::TYPE_RULES_EVALUATED, $queue->enqueued[0]->eventType);
        $this->assertSame(1, $queue->enqueued[0]->payload['hallazgos']['metrics']['total_campos']);
        $this->assertSame('completed', $queue->enqueued[0]->payload['final_status']);
        $this->assertSame('87723098', $queue->enqueued[0]->payload['audit_result_data']['DisId']);
        $this->assertSame([], $publisher->published);
    }

    public function testDocumentRejectedPublishesRulesEvaluatedWithCanonicalFinding(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $queue = new RulesPersistenceQueue();
        $store = new RulesRejectedStateStore($auditId, $documentId);

        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(['unexpected' => true]),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: $auditId,
            documentId: $documentId,
            payload: [
                'document_type' => 'FORMULA MEDICA',
                'rejection_class' => DocumentRejectionReason::REJECTION_CLASS,
                'rejection_origin' => DocumentExtractionWorker::class,
                'rejection_reason' => DocumentRejectionReason::UNKNOWN_FILE_SIGNATURE,
            ]
        ));

        $this->assertSame('evaluated', $store->lastPolicyPatch['status'] ?? null);
        $this->assertCount(1, $queue->enqueued);
        $payload = $queue->enqueued[0]->payload;
        $finding = $payload['hallazgos']['items'][0];

        $this->assertSame(AuditEvent::TYPE_RULES_EVALUATED, $queue->enqueued[0]->eventType);
        $this->assertSame('RECHAZADO', $finding['resultado']);
        $this->assertSame('integrity', $finding['tipo_auditoria']);
        $this->assertSame('FORMULA MEDICA', $finding['documento']);
        $this->assertSame(1, $payload['hallazgos']['metrics']['discrepancias']);
        $this->assertSame('manual_review', $payload['final_status']);
        $this->assertFalse($payload['document_decisions'][0]['approved']);
        $this->assertSame(
            DocumentRejectionReason::REJECTION_CLASS,
            $payload['document_decisions'][0]['rejection_class']
        );
        $this->assertSame(
            DocumentRejectionReason::UNKNOWN_FILE_SIGNATURE,
            $payload['document_decisions'][0]['rejection_reason']
        );
    }

    public function testMappingRejectionPublishesMapFindingAndPreservesTraceability(): void
    {
        // Arrange:
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $queue = new RulesPersistenceQueue();
        $store = new RulesMappingRejectedStateStore($auditId, $documentId);
        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine(['unexpected' => true]),
            redis: $this->createMock(RedisClient::class),
            publisher: new RulesPublisher(),
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: $auditId,
            documentId: $documentId,
            payload: [
                'document_type' => 'AUTORIZACION',
                'logical_doc_id' => '2',
                'candidate_attachment_ids' => ['6', '4', '6'],
                'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
                'rejection_origin' => DocumentAuditOrchestrator::class,
                'rejection_reason' => DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
                'rejected_at' => '2026-08-03T12:00:00Z',
            ]
        );

        // Act:
        $worker->processEvent($event);

        // Assert:
        $this->assertCount(1, $queue->enqueued);
        $payload = $queue->enqueued[0]->payload;
        $finding = $payload['hallazgos']['items'][0];
        $decision = $payload['document_decisions'][0];
        $expectedDetail = "No fue posible asociar de forma inequívoca el documento lógico 'AUTORIZACION' con un adjunto físico: DOCUMENT_ATTACHMENT_AMBIGUOUS.";
        $this->assertSame('MAP', $finding['codigoCampo']);
        $this->assertSame('RECHAZADO', $finding['resultado']);
        $this->assertSame('alta', $finding['severidad']);
        $this->assertSame('integrity', $finding['tipo_auditoria']);
        $this->assertSame($expectedDetail, $finding['detalle']);
        $this->assertSame('manual_review', $payload['final_status']);
        $this->assertSame('2', $decision['doc_id']);
        $this->assertNull($decision['attachment_id']);
        $this->assertSame(['4', '6'], $decision['candidate_attachment_ids']);
        $this->assertSame(DocumentMappingRejectionReason::CATEGORY, $decision['rejection_category']);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            $decision['rejection_reason']
        );
        $this->assertArrayNotHasKey('rejection_class', $decision);
        $this->assertSame('MAP', $decision['payload']['hallazgos'][0]['Codigo']);
        $this->assertSame($expectedDetail, $decision['payload']['hallazgos'][0]['Descripcion']);
    }

    public function testLegacyDownloadErrorIsRejectedBeforePersistenceQueue(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $queue = new RulesPersistenceQueue();
        $worker = new RulesEvaluationWorker(
            stateStore: new RulesRejectedStateStore($auditId, $documentId),
            policyEngine: new StubDocumentPolicyEngine(['unexpected' => true]),
            redis: $this->createMock(RedisClient::class),
            publisher: new RulesPublisher(),
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('contrato de rechazo documental');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
                auditId: $auditId,
                documentId: $documentId,
                payload: [
                    'document_type' => 'FORMULA MEDICA',
                    'rejection_reason' => 'DOWNLOAD_ERROR',
                ]
            ));
        } finally {
            $this->assertSame([], $queue->enqueued);
        }
    }

    public function testDoesNotPublishRulesEvaluatedWhenStateStoreFailsPolicyPersistence(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $queue = new RulesPersistenceQueue();
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
            consumerName: 'policy-test',
            persistenceQueue: $queue
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
            $this->assertCount(0, $queue->enqueued);
        }
    }

    public function testRetryEnqueuesTheCanonicalOutcomeAlreadyStoredInRedis(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $canonicalOutcome = [
            'final_status' => 'manual_review',
            'source' => 'canonical-redis-outcome',
        ];
        $store = new RulesReadyStateStore(
            $auditId,
            $documentId,
            $canonicalOutcome
        );
        $queue = new RulesPersistenceQueue();
        $worker = new RulesEvaluationWorker(
            stateStore: $store,
            policyEngine: new StubDocumentPolicyEngine([
                'document_name' => 'DISPENSA',
                'hallazgos' => ['items' => []],
                'document_decision' => [
                    'documentName' => 'DISPENSA',
                    'approved' => true,
                ],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: new RulesPublisher(),
            consumerName: 'policy-retry-test',
            persistenceQueue: $queue
        );

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

        $this->assertCount(1, $queue->enqueued);
        $this->assertSame($canonicalOutcome, $queue->enqueued[0]->payload);
    }

    public function testPublishesCalculatedDeliveryValidityFindingWhenVisualEvidenceIsComplete(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $queue = new RulesPersistenceQueue();
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
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $findings = $queue->enqueued[0]->payload['hallazgos']['items'];
        $vigencia = end($findings);
        $this->assertSame('VigenciaEntrega', $vigencia['campo']);
        $this->assertSame('COINCIDE', $vigencia['resultado']);
        $this->assertSame(3, $queue->enqueued[0]->payload['hallazgos']['metrics']['total_campos']);
        $this->assertSame(3, $queue->enqueued[0]->payload['hallazgos']['metrics']['coincidencias']);
    }

    public function testCalculatedDeliveryValidityMarksDocumentWhenExpired(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $queue = new RulesPersistenceQueue();
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
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $payload = $queue->enqueued[0]->payload;
        $vigencia = end($payload['hallazgos']['items']);
        $this->assertSame('VALOR_DISTINTO', $vigencia['resultado']);
        $this->assertSame(1, $payload['hallazgos']['metrics']['discrepancias']);
        $this->assertFalse($payload['document_decisions'][1]['approved']);
        $this->assertStringContainsString('supera la vigencia', $payload['document_decisions'][1]['payload']['hallazgos'][0]['Descripcion'] ?? '');
    }

    public function testCalculatedDeliveryValidityFallsBackToDefaultWhenAuthoritativeEvidenceIsIncomplete(): void
    {
        $auditId = AuditEvent::uuidV4();
        $authorizationDocumentId = AuditEvent::uuidV4();
        $publisher = new RulesPublisher();
        $queue = new RulesPersistenceQueue();
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
            consumerName: 'policy-test',
            persistenceQueue: $queue
        );

        $worker->processEvent(self::authorizationNormalizedEvent($auditId, $authorizationDocumentId));

        $payload = $queue->enqueued[0]->payload;
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

    public function evaluate(array $documentState, array $normalizedPayload, string $facNro = ''): array
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

    public function __construct(
        private string $auditId,
        private string $documentId,
        ?array $storedRulesEvaluation = null
    )
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
        if ($storedRulesEvaluation !== null) {
            $this->rulesStored = true;
            $this->audit['rules_evaluated_result'] = $storedRulesEvaluation;
        }
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
                    'rejection_class' => DocumentRejectionReason::REJECTION_CLASS,
                    'rejection_origin' => DocumentExtractionWorker::class,
                    'rejection_reason' => DocumentRejectionReason::UNKNOWN_FILE_SIGNATURE,
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

final class RulesMappingRejectedStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    private array $audit;
    private bool $rulesStored = false;

    public function __construct(private string $auditId, private string $documentId)
    {
        $this->audit = [
            'audit_id' => $auditId,
            'dis_id' => '87723098',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2624',
            'docs_total' => 1,
            'docs_done' => 0,
            'docs_rejected' => 1,
            'docs_evaluated' => 0,
            'documents' => [
                $documentId => [
                    'tipo_documento' => 'AUTORIZACION',
                    'doc_id' => '2',
                    'status' => 'rejected',
                    'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
                    'rejection_origin' => DocumentAuditOrchestrator::class,
                    'rejection_reason' => DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
                    'logical_doc_id' => '2',
                    'candidate_attachment_ids' => ['4', '6'],
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
        $this->audit['docs_evaluated'] = 1;
        $this->audit['documents'][$documentId] = array_merge(
            $this->audit['documents'][$documentId],
            $policyState
        );
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

final class RulesPersistenceQueue extends AuditPersistenceQueue
{
    /** @var array<int,AuditEvent> */
    public array $enqueued = [];

    public function __construct()
    {
    }

    public function enqueue(AuditEvent $event): int
    {
        $this->enqueued[] = $event;
        return self::ENQUEUE_DISPATCHED;
    }
}
