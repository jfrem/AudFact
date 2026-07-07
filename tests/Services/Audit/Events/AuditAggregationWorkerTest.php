<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Models\AuditStatusModel;
use App\Services\Audit\Pipeline\AuditAggregationWorker;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditAggregationWorkerTest extends TestCase
{
    public function testRulesEvaluatedPersistsCompletesAuditAndPublishesBatchTerminalEvent(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $publisher = new AggregationPublisher();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $model = new RecordingAuditStatusModel();
        
        $jobStore = new RecordingBatchJobStore($store);
        
        $worker = new AuditAggregationWorker(
            stateStore: $store,
            jobStore: $jobStore,
            auditStatusModel: $model,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'aggregator-test'
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
    }

    public function testDoesNotPublishAuditCompletedWhenSqlPersistenceFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $publisher = new AggregationPublisher();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        
        $jobStore = clone new RecordingBatchJobStore($store);
        
        $worker = new AuditAggregationWorker(
            stateStore: $store,
            jobStore: $jobStore,
            auditStatusModel: new FailingAuditStatusModel(),
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'aggregator-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('persistir el resultado final');

        try {
            $worker->processEvent(AuditEvent::create(
                eventType: AuditEvent::TYPE_RULES_EVALUATED,
                auditId: $auditId,
                jobId: $jobId,
                payload: self::rulesOutcomePayload('error')
            ));
        } finally {
            $this->assertSame('failed', $store->lastCompletion['status'] ?? null);
            $this->assertTrue($store->lastCompletion['requires_manual_review'] ?? false);
            $this->assertSame([
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'status' => 'failed',
                'duration_ms' => 42000,
            ], $jobStore->lastJobCompletion);
            $this->assertCount(2, $publisher->published);
            $this->assertSame(AuditEvent::TYPE_AUDIT_FAILED, $publisher->published[0]->eventType);
            $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
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

final class RecordingAuditStatusModel extends AuditStatusModel
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

    public function persistAuditResultWithAttachments(array $auditResultData, array $documentDecisions): array|false
    {
        $this->lastAuditResultData = $auditResultData;
        $this->lastDocumentDecisions = $documentDecisions;
        return ['DisId' => $auditResultData['DisId']];
    }

    public function updateAuditTimings(string $facNro, array $timings, int $durationMs): bool
    {
        $this->lastUpdatedFacNro = $facNro;
        $this->lastUpdatedTimings = $timings;
        $this->lastUpdatedDurationMs = $durationMs;
        $payload = json_decode((string) $this->lastAuditResultData['Hallazgos'], true);
        if (is_array($payload)) {
            $payload['timings'] = $timings;
            $payload['total_duration_ms'] = $durationMs;
            $this->lastAuditResultData['Hallazgos'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->lastAuditResultData['DuracionProcesamientoMs'] = $durationMs;

        return true;
    }
}

final class FailingAuditStatusModel extends AuditStatusModel
{
    public function __construct()
    {
    }

    public function persistAuditResultWithAttachments(array $auditResultData, array $documentDecisions): array|false
    {
        throw new RuntimeException('sql-down');
    }
}

final class AggregationPublisher extends AuditEventPublisher
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
