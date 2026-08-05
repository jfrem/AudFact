<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Models\AuditResultPersistenceModel;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditPersistenceWorker;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentMappingRejectionReason;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditPersistenceWorkerTest extends TestCase
{
    public function testRulesEvaluatedPersistsCompletesAuditAndPublishesBatchTerminalEvent(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $publisher = new PersistencePublisher();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $model = new RecordingAuditResultPersistenceModel();
        $queue = new RecordingAuditPersistenceQueue();
        
        $jobStore = new RecordingBatchJobStore($store);
        
        $worker = new AuditPersistenceWorker(
            stateStore: $store,
            jobStore: $jobStore,
            persistenceModel: $model,
            persistenceQueue: $queue,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'persistence-test'
        );

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $auditId,
            jobId: $jobId,
            payload: self::rulesOutcomePayload('manual_review')
        ));

        $this->assertSame('manual_review', $store->lastCompletion['status'] ?? null);
        $this->assertSame('87723098', $model->lastAuditResultData['DisId'] ?? null);
        $this->assertSame('FORMULA MEDICA', $model->lastDocumentDecisions[0]['documentName'] ?? null);
        $hallazgos = json_decode((string) $model->lastAuditResultData['Hallazgos'], true);
        $this->assertSame(1, $hallazgos['timings']['gemini_extraction']['count'] ?? null);
        $this->assertSame(1, $hallazgos['timings']['gemini_semantic']['count'] ?? null);
        $this->assertSame(300, $hallazgos['timings']['gemini_total']['total_tokens'] ?? null);
        $this->assertArrayHasKey('sql_persist_ms', $hallazgos['timings']['aggregation'] ?? []);
        $this->assertSame('T38250701547', $model->lastUpdatedFacNro);
        $this->assertSame(42000, $model->lastUpdatedDurationMs);
        $this->assertArrayNotHasKey('token_usage', $hallazgos['timings']);
        $this->assertCount(2, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_AUDIT_COMPLETED, $publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
        $this->assertSame([
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'status' => 'manual_review',
            'duration_ms' => 42000,
        ], $jobStore->lastJobCompletion);
        $this->assertSame(
            [['87723098', 'reservation-token']],
            $jobStore->releasedReservations
        );
        $this->assertSame([$auditId], $queue->advancedAuditIds);
    }

    public function testDoesNotPublishAuditCompletedWhenSqlPersistenceFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $publisher = new PersistencePublisher();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $queue = new RecordingAuditPersistenceQueue();
        
        $jobStore = clone new RecordingBatchJobStore($store);
        
        $worker = new AuditPersistenceWorker(
            stateStore: $store,
            jobStore: $jobStore,
            persistenceModel: new FailingAuditResultPersistenceModel(),
            persistenceQueue: $queue,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'persistence-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sql-down');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_RULES_EVALUATED,
                auditId: $auditId,
                jobId: $jobId,
                payload: self::rulesOutcomePayload('error')
            ));
        } finally {
            $this->assertSame([], $store->lastCompletion);
            $this->assertSame([], $jobStore->lastJobCompletion);
            $this->assertSame([], $publisher->published);
            $this->assertSame([], $queue->advancedAuditIds);
        }
    }

    public function testValidMappingRejectionContractReachesSqlPersistence(): void
    {
        // Arrange:
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $model = new RecordingAuditResultPersistenceModel();
        $queue = new RecordingAuditPersistenceQueue();
        $payload = self::rulesOutcomePayload('manual_review');
        $payload['document_decisions'][0] = [
            'documentName' => 'AUTORIZACION',
            'approved' => false,
            'doc_id' => '2',
            'attachment_id' => null,
            'candidate_attachment_ids' => ['4', '6'],
            'rejection_category' => DocumentMappingRejectionReason::CATEGORY,
            'rejection_reason' => DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            'payload' => [
                'state' => false,
                'hallazgos' => [[
                    'Codigo' => 'MAP',
                    'Descripcion' => 'Asociación física ambigua.',
                ]],
            ],
        ];
        $worker = new AuditPersistenceWorker(
            stateStore: $store,
            jobStore: new RecordingBatchJobStore($store),
            persistenceModel: $model,
            persistenceQueue: $queue,
            redis: $this->createMock(RedisClient::class),
            publisher: new PersistencePublisher(),
            consumerName: 'persistence-test'
        );
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $auditId,
            jobId: $jobId,
            payload: $payload
        );

        // Act:
        $worker->processEvent($event);

        // Assert:
        $this->assertSame('manual_review', $store->lastCompletion['status'] ?? null);
        $this->assertSame(DocumentMappingRejectionReason::CATEGORY, $model->lastDocumentDecisions[0]['rejection_category']);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            $model->lastDocumentDecisions[0]['rejection_reason']
        );
        $this->assertSame(['4', '6'], $model->lastDocumentDecisions[0]['candidate_attachment_ids']);
        $this->assertSame([$auditId], $queue->advancedAuditIds);
    }

    public function testDownloadErrorIsBlockedBeforeSqlPersistence(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $model = new RecordingAuditResultPersistenceModel();
        $queue = new RecordingAuditPersistenceQueue();
        $payload = self::rulesOutcomePayload('manual_review');
        $payload['document_decisions'][0]['rejection_class'] = 'document_content';
        $payload['document_decisions'][0]['rejection_reason'] = 'DOWNLOAD_ERROR';

        $worker = new AuditPersistenceWorker(
            stateStore: $store,
            jobStore: new RecordingBatchJobStore($store),
            persistenceModel: $model,
            persistenceQueue: $queue,
            redis: $this->createMock(RedisClient::class),
            publisher: new PersistencePublisher(),
            consumerName: 'persistence-test'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('razón técnica prohibida');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_RULES_EVALUATED,
                auditId: $auditId,
                jobId: $jobId,
                payload: $payload
            ));
        } finally {
            $this->assertSame([], $model->lastAuditResultData);
            $this->assertSame([], $store->lastCompletion);
            $this->assertSame([], $queue->advancedAuditIds);
        }
    }

    public function testInvalidContentRejectionContractIsBlockedBeforeSql(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $model = new RecordingAuditResultPersistenceModel();
        $payload = self::rulesOutcomePayload('manual_review');
        $payload['document_decisions'][0]['rejection_class'] = 'technical_failure';
        $payload['document_decisions'][0]['rejection_reason'] = 'UNKNOWN_FILE_SIGNATURE';

        $worker = new AuditPersistenceWorker(
            stateStore: $store,
            jobStore: new RecordingBatchJobStore($store),
            persistenceModel: $model,
            persistenceQueue: new RecordingAuditPersistenceQueue(),
            redis: $this->createMock(RedisClient::class),
            publisher: new PersistencePublisher(),
            consumerName: 'persistence-test'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('rechazo documental inválido');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_RULES_EVALUATED,
                auditId: $auditId,
                jobId: $jobId,
                payload: $payload
            ));
        } finally {
            $this->assertSame([], $model->lastAuditResultData);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function rulesOutcomePayload(string $status): array
    {
        $isManualReview = $status === 'manual_review';
        $documentDecisions = [[
            'documentName' => $isManualReview ? 'FORMULA MEDICA' : 'DISPENSA',
            'approved' => !$isManualReview,
            'observation' => $isManualReview
                ? 'La calidad documental no permite concluir el valor con confianza suficiente.'
                : null,
        ]];
        $findings = [[
            'campo' => $isManualReview ? 'FirmaActaEntrega' : 'NumeroFactura',
            'resultado' => $isManualReview ? 'NO_CONCLUYENTE' : 'NO_ENCONTRADO',
            'severidad' => $isManualReview ? 'alta' : 'baja',
            'documento' => $documentDecisions[0]['documentName'],
        ]];
        $metrics = [
            'total_campos' => 1,
            'coincidencias' => 0,
            'discrepancias' => $isManualReview ? 0 : 1,
            'omitidos' => 0,
            'no_concluyentes' => $isManualReview ? 1 : 0,
            'risk_score' => $isManualReview ? 20 : 1,
        ];
        $hallazgos = [
            'items' => $findings,
            'field_decisions' => $findings,
            'document_decisions' => $documentDecisions,
            'metrics' => $metrics,
            'timings' => [
                'gemini_extraction' => ['count' => 1],
                'gemini_semantic' => ['count' => 1],
                'gemini_total' => ['total_tokens' => 300],
            ],
            'total_duration_ms' => 42000,
        ];

        return [
            'hallazgos' => [
                'items' => $findings,
                'metrics' => $metrics,
            ],
            'document_decisions' => $documentDecisions,
            'final_status' => $status,
            'requires_manual_review' => $isManualReview,
            'severity' => $isManualReview ? 'alta' : 'baja',
            'detail_message' => 'Resultado final construido por policy.',
            'failed_document' => $documentDecisions[0]['documentName'],
            'audit_result_data' => [
                'DisId' => '87723098',
                'FacNro' => 'T38250701547',
                'EstAud' => $status === 'completed' ? 1 : 0,
                'EstadoDetallado' => $status,
                'RequiereRevisionHumana' => $isManualReview ? 1 : 0,
                'Severidad' => $isManualReview ? 'alta' : 'baja',
                'Hallazgos' => json_encode($hallazgos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'DetalleError' => 'Resultado final construido por policy.',
                'DocumentosProcesados' => 2,
                'DocumentoFallido' => $documentDecisions[0]['documentName'],
                'DuracionProcesamientoMs' => 42000,
                'FacNitSec' => '2426',
            ],
            'completion_payload' => [
                'status' => $status,
                'requires_manual_review' => $isManualReview,
                'audit_result' => [
                    'hallazgos' => [
                        'items' => $findings,
                        'metrics' => $metrics,
                    ],
                    'document_decisions' => $documentDecisions,
                ],
                'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
            ],
        ];
    }
}

class AggregationRecordingStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    public array $lastCompletion = [];
    /** @var array<string,mixed> */
    public array $lastJobCompletion = [];
    /** @var array<string,mixed> */
    private array $auditState;

    public function __construct(private string $auditId, private ?string $jobId)
    {
        $this->auditState = [
            'audit_id' => $this->auditId,
            'status' => 'processing',
            'dis_id' => '87723098',
            'reservation_token' => 'reservation-token',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2426',
            'created_at' => '2026-05-23T10:00:00.000000Z',
            'started_at' => '2026-05-23T10:00:00.000000Z',
            'rules_evaluated_at' => '2026-05-23T10:00:41.500000Z',
            'documents' => [
                'doc-1' => ['tipo_documento' => 'DISPENSA'],
                'doc-2' => [
                    'tipo_documento' => 'AUTORIZACION',
                    'gemini_metrics' => [
                        'task_type' => 'extraction',
                        'duration_ms' => 1000,
                        'cache_hit' => false,
                        'total_tokens' => 200,
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
                ],
            ],
        ];
    }

    public function getAudit(string $auditId): ?array
    {
        return $this->auditState;
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $this->auditState = array_merge($this->auditState, $patch);
        return true;
    }

    public function completeAudit(string $auditId, array $completionState): bool
    {
        $this->lastCompletion = $completionState;
        $this->auditState = array_merge($this->auditState, $completionState, [
            'completed_at' => '2026-05-23T10:00:42.000000Z',
        ]);
        return true;
    }

}

class RecordingBatchJobStore extends \App\Services\Audit\Pipeline\BatchJobStore
{
    public array $lastJobCompletion = [];
    public array $calls = [];
    private bool $batchTerminalClaimed = false;
    public array $releasedReservations = [];

    public function __construct(private AggregationRecordingStateStore $stateStore)
    {
    }

    public function releaseAuditReservation(string $disId, string $ownerToken): bool
    {
        $this->releasedReservations[] = [$disId, $ownerToken];
        return true;
    }

    public function markAuditCompletedInJob(
        string $jobId,
        string $auditId,
        string $status,
        int $durationMs = 0,
        ?string $failedStage = null
    ): bool {
        $this->calls[] = [
            'method'          => 'markAuditCompletedInJob',
            'jobId'           => $jobId,
            'auditId'         => $auditId,
            'status'          => $status,
            'durationMs'      => $durationMs,
            'failedStage'     => $failedStage,
        ];
        $this->lastJobCompletion = [
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'status' => $status,
            'duration_ms' => $durationMs,
        ];
        return true;
    }

    public function getJob(string $jobId): ?array
    {
        return [
            'job_id' => $jobId,
            'status' => 'completed_with_errors',
            'total' => 1,
            'done' => 0,
            'failed' => 1,
        ];
    }

    public function claimBatchTerminalEvent(string $jobId, string $eventType): bool
    {
        if ($this->batchTerminalClaimed) {
            return false;
        }

        $this->batchTerminalClaimed = true;
        return true;
    }
}

final class RecordingAuditResultPersistenceModel extends AuditResultPersistenceModel
{
    /** @var array<string,mixed> */
    public array $lastAuditResultData = [];
    /** @var array<int,array{documentName:string,approved:bool,observation:?string}> */
    public array $lastDocumentDecisions = [];
    /** @var array<string,mixed> */
    public array $lastUpdatedTimings = [];
    public string $lastUpdatedFacNro = '';
    public int $lastUpdatedDurationMs = 0;

    public function __construct()
    {
    }

    public function persist(array $auditResultData, array $documentDecisions): void
    {
        $this->lastAuditResultData = $auditResultData;
        $this->lastDocumentDecisions = $documentDecisions;
    }

    public function updateFinalTimings(
        string $facNro,
        string $hallazgosJson,
        int $durationMs
    ): bool
    {
        $this->lastUpdatedFacNro = $facNro;
        $this->lastUpdatedDurationMs = $durationMs;
        $payload = json_decode($hallazgosJson, true);
        if (is_array($payload)) {
            $this->lastUpdatedTimings = $payload['timings'] ?? [];
        }
        $this->lastAuditResultData['Hallazgos'] = $hallazgosJson;
        $this->lastAuditResultData['DuracionProcesamientoMs'] = $durationMs;

        return true;
    }
}

final class FailingAuditResultPersistenceModel extends AuditResultPersistenceModel
{
    public function __construct()
    {
    }

    public function persist(array $auditResultData, array $documentDecisions): void
    {
        throw new RuntimeException('sql-down');
    }
}

final class PersistencePublisher extends AuditEventPublisher
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

final class RecordingAuditPersistenceQueue extends AuditPersistenceQueue
{
    /** @var array<int,string> */
    public array $advancedAuditIds = [];

    public function __construct()
    {
    }

    public function advance(AuditEvent $event): bool
    {
        $this->advancedAuditIds[] = (string) $event->auditId;
        return true;
    }
}
