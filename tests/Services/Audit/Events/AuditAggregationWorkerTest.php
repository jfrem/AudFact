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
            payload: [
                'hallazgos' => [
                    'items' => [
                        [
                            'campo' => 'FirmaActaEntrega',
                            'resultado' => 'NO_CONCLUYENTE',
                            'severidad' => 'alta'
                        ]
                    ], 
                    'metrics' => ['risk_score' => 20]
                ],
                'document_decisions' => [[
                    'documentName' => 'FORMULA MEDICA',
                    'approved' => false,
                    'observation' => 'La calidad documental no permite concluir el valor con confianza suficiente.',
                ]],
            ]
        ));

        $this->assertSame('manual_review', $store->lastCompletion['status'] ?? null);
        $this->assertSame('87723098', $model->lastAuditResultData['FacSec'] ?? null);
        $this->assertSame('FORMULA MEDICA', $model->lastDocumentDecisions[0]['documentName'] ?? null);
        $this->assertCount(2, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_AUDIT_COMPLETED, $publisher->published[0]->eventType);
        $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
        $this->assertSame(['job_id' => $jobId, 'audit_id' => $auditId, 'status' => 'manual_review'], $jobStore->lastJobCompletion);
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
                payload: [
                    'hallazgos' => [
                        'items' => [
                            [
                                'campo' => 'NumeroFactura',
                                'resultado' => 'NO_ENCONTRADO',
                                'severidad' => 'baja'
                            ]
                        ], 
                        'metrics' => ['risk_score' => 0]
                    ],
                    'document_decisions' => [[
                        'documentName' => 'DISPENSA',
                        'approved' => true,
                        'observation' => null,
                    ]],
                ]
            ));
        } finally {
            $this->assertSame('failed', $store->lastCompletion['status'] ?? null);
            $this->assertTrue($store->lastCompletion['requires_manual_review'] ?? false);
            $this->assertSame(['job_id' => $jobId, 'audit_id' => $auditId, 'status' => 'failed'], $jobStore->lastJobCompletion);
            $this->assertCount(2, $publisher->published);
            $this->assertSame(AuditEvent::TYPE_AUDIT_FAILED, $publisher->published[0]->eventType);
            $this->assertSame(AuditEvent::TYPE_BATCH_COMPLETED_ERR, $publisher->published[1]->eventType);
        }
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
            'created_at' => microtime(true) - 42.0,
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

}

class RecordingBatchJobStore extends \App\Services\Audit\Pipeline\BatchJobStore
{
    public array $lastJobCompletion = [];
    private bool $batchTerminalClaimed = false;

    public function __construct(private AggregationRecordingStateStore $stateStore)
    {
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
