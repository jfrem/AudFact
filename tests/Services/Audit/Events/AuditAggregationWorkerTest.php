<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Models\AuditStatusModel;
use App\Services\Audit\Events\AuditAggregationWorker;
use App\Services\Audit\Events\AuditEvent;
use App\Services\Audit\Events\AuditEventPublisher;
use App\Services\Audit\Events\AuditResultAggregator;
use App\Services\Audit\Events\AuditStateStore;
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
        $worker = new AuditAggregationWorker(
            stateStore: $store,
            aggregator: new StubAuditResultAggregator([
                'final_status' => 'manual_review',
                'requires_manual_review' => true,
                'severity' => 'alta',
                'detail_message' => 'Auditoria completada con incertidumbre documental: 2 campos no concluyentes requieren revision humana.',
                'failed_document' => 'FORMULA MEDICA',
                'document_decisions' => [[
                    'documentName' => 'FORMULA MEDICA',
                    'approved' => false,
                    'observation' => 'La calidad documental no permite concluir el valor con confianza suficiente.',
                ]],
                'audit_result_data' => [
                    'FacSec' => '87723098',
                    'FacNro' => 'T38250701547',
                    'EstAud' => 1,
                    'EstadoDetallado' => 'manual_review',
                    'RequiereRevisionHumana' => 1,
                    'Severidad' => 'alta',
                    'Hallazgos' => '{"items":[],"metrics":{"risk_score":20}}',
                    'DetalleError' => 'Auditoria completada con incertidumbre documental: 2 campos no concluyentes requieren revision humana.',
                    'DocumentosProcesados' => 3,
                    'DocumentoFallido' => 'FORMULA MEDICA',
                    'DuracionProcesamientoMs' => 42000,
                    'FacNitSec' => '2426',
                ],
                'completion_payload' => [
                    'status' => 'manual_review',
                    'requires_manual_review' => true,
                    'audit_result' => [
                        'hallazgos' => [
                            'items' => [],
                            'metrics' => ['risk_score' => 20],
                        ],
                        'document_decisions' => [[
                            'documentName' => 'FORMULA MEDICA',
                            'approved' => false,
                            'observation' => 'La calidad documental no permite concluir el valor con confianza suficiente.',
                        ]],
                    ],
                    'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
                ],
            ]),
            auditStatusModel: $model,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'aggregator-test'
        );

        $worker->processEvent(AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: $auditId,
            jobId: $jobId,
            payload: [
                'hallazgos' => ['items' => [], 'metrics' => ['risk_score' => 20]],
                'document_decisions' => [],
            ]
        ));

        $this->assertSame('manual_review', $store->lastCompletion['status'] ?? null);
        $this->assertSame('87723098', $model->lastAuditResultData['FacSec'] ?? null);
        $this->assertSame('FORMULA MEDICA', $model->lastDocumentDecisions[0]['documentName'] ?? null);
        $this->assertCount(2, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_AUDIT_COMPLETED, $publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
        $this->assertSame(['job_id' => $jobId, 'audit_id' => $auditId, 'status' => 'manual_review'], $store->lastJobCompletion);
    }

    public function testDoesNotPublishAuditCompletedWhenSqlPersistenceFails(): void
    {
        $auditId = AuditEvent::uuidV4();
        $jobId = AuditEvent::uuidV4();
        $publisher = new AggregationPublisher();
        $store = new AggregationRecordingStateStore($auditId, $jobId);
        $worker = new AuditAggregationWorker(
            stateStore: $store,
            aggregator: new StubAuditResultAggregator([
                'final_status' => 'completed',
                'requires_manual_review' => false,
                'severity' => 'baja',
                'detail_message' => 'ok',
                'failed_document' => null,
                'document_decisions' => [[
                    'documentName' => 'DISPENSA',
                    'approved' => true,
                    'observation' => null,
                ]],
                'audit_result_data' => [
                    'FacSec' => '87723098',
                    'FacNro' => 'T38250701547',
                    'EstAud' => 1,
                    'EstadoDetallado' => 'completed',
                    'RequiereRevisionHumana' => 0,
                    'Severidad' => 'baja',
                    'Hallazgos' => '{"items":[],"metrics":{"risk_score":0}}',
                    'DetalleError' => 'ok',
                    'DocumentosProcesados' => 1,
                    'DocumentoFallido' => null,
                    'DuracionProcesamientoMs' => 1000,
                    'FacNitSec' => '2426',
                ],
                'completion_payload' => [
                    'status' => 'completed',
                    'requires_manual_review' => false,
                    'audit_result' => ['hallazgos' => ['items' => [], 'metrics' => ['risk_score' => 0]], 'document_decisions' => []],
                    'persistence_target' => 'AudDispEst+AdjuntosDispensacion',
                ],
            ]),
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
                payload: [
                    'hallazgos' => ['items' => [], 'metrics' => ['risk_score' => 0]],
                    'document_decisions' => [],
                ]
            ));
        } finally {
            $this->assertSame('failed', $store->lastCompletion['status'] ?? null);
            $this->assertTrue($store->lastCompletion['requires_manual_review'] ?? false);
            $this->assertSame(['job_id' => $jobId, 'audit_id' => $auditId, 'status' => 'failed'], $store->lastJobCompletion);
            $this->assertCount(2, $publisher->published);
            $this->assertSame(AuditEvent::TYPE_AUDIT_FAILED, $publisher->published[0]->eventType);
            $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
        }
    }
}

final class StubAuditResultAggregator extends AuditResultAggregator
{
    /**
     * @param array<string,mixed> $result
     */
    public function __construct(private array $result)
    {
    }

    public function aggregate(array $audit, array $rulesPayload): array
    {
        return $this->result;
    }
}

class AggregationRecordingStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
    public array $lastCompletion = [];
    /** @var array<string,mixed> */
    public array $lastJobCompletion = [];
    private bool $batchTerminalClaimed = false;

    public function __construct(private string $auditId, private ?string $jobId)
    {
    }

    public function getAudit(string $auditId): ?array
    {
        return [
            'audit_id' => $this->auditId,
            'status' => 'processing',
            'fac_sec' => '87723098',
            'dis_det_nro' => 'T38250701547',
            'fac_nit_sec' => '2426',
            'documents' => [
                'doc-1' => ['tipo_documento' => 'DISPENSA'],
            ],
        ];
    }

    public function completeAudit(string $auditId, array $completionState): bool
    {
        $this->lastCompletion = $completionState;
        return true;
    }

    public function markAuditCompletedInJob(string $jobId, string $auditId, string $auditStatus): bool
    {
        $this->lastJobCompletion = [
            'job_id' => $jobId,
            'audit_id' => $auditId,
            'status' => $auditStatus,
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

    public function __construct()
    {
    }

    public function persistAuditResultWithAttachments(array $auditResultData, array $documentDecisions): array|false
    {
        $this->lastAuditResultData = $auditResultData;
        $this->lastDocumentDecisions = $documentDecisions;
        return ['FacSec' => $auditResultData['FacSec']];
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
