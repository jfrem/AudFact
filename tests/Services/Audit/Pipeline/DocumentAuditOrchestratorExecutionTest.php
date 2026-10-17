<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDataService;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\DocumentMappingRejectionReason;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\RulesEvaluationWorker;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentAuditOrchestratorExecutionTest extends TestCase
{
    #[DataProvider('mappingRejections')]
    public function testScheduledMappingRejectionRemainsAFunctionalPolicyFinding(array $attachments, string $reason): void
    {
        // Arrange:
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', payload: [
            'source' => 'single', 'dis_id' => 'synthetic-dis', 'dis_det_nro' => 'synthetic-invoice',
        ]);
        $audit = ['audit_id' => $event->auditId, 'status' => 'processing', 'execution_version' => 2,
            'dis_det_nro' => 'synthetic-invoice', 'docs_total' => 1, 'docs_rejected' => 1,
            'docs_done' => 0, 'docs_evaluated' => 0, 'documents' => []];
        $redis = $this->createStub(RedisClient::class);
        $store = $this->createStub(AuditStateStore::class);
        $store->method('getAudit')->willReturnCallback(static function () use (&$audit): array { return $audit; });
        $data = $this->createStub(AuditDataService::class);
        $data->method('getDispensation')->willReturn(['header' => [
            'NitSec' => 'synthetic-client', 'DisId' => 'synthetic-dis', 'NumeroFactura' => 'synthetic-invoice',
        ], 'items' => []]);
        $data->method('getClientDocuments')->willReturn([
            ['NitMedDocId' => 1, 'NitMedDocNom' => 'DISPENSA', 'NitMedDocCodAlt' => 'ANE'],
        ]);
        $data->method('getAuditConfig')->willReturn(['activo' => true, 'documents' => ['DISPENSA' => [
            'docId' => 1, 'fields' => [['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number']],
            'visualChecks' => [],
        ]]]);
        $data->method('getAttachments')->willReturn($attachments);
        $orchestrator = new DocumentAuditOrchestrator(stateStore: $store, dataService: $data, redis: $redis);
        $policy = new RulesEvaluationWorker(stateStore: $store, policyEngine: new DocumentPolicyEngine(), redis: $redis);

        // Act:
        $effects = $orchestrator->prepareTask(AuditTask::fromEvent(AuditTaskStage::ORCHESTRATION, $event));
        $rejected = AuditEvent::fromArray($effects['outbox'][0]['event']);
        $audit['documents'][$rejected->documentId] = array_replace($rejected->payload, ['status' => 'rejected']);
        $evaluated = $policy->prepareTask(AuditTask::fromEvent(AuditTaskStage::POLICY, $rejected));
        $transition = $evaluated['state_transitions'][0];

        // Assert:
        self::assertSame(AuditEvent::TYPE_DOCUMENT_REJECTED, $rejected->eventType);
        self::assertSame(DocumentMappingRejectionReason::CATEGORY, $rejected->payload['rejection_category']);
        self::assertSame($reason, $rejected->payload['rejection_reason']);
        self::assertSame('1', $rejected->payload['logical_doc_id']);
        self::assertTrue(AuditEventPublisher::isPriorityEvent($rejected));
        self::assertArrayNotHasKey('rejection_class', $rejected->payload);
        self::assertSame('mark_evaluated', $transition['op']);
        self::assertSame('RECHAZADO', $transition['data']['policy_result']['hallazgos']['items'][0]['resultado']);
        self::assertSame('persistence_queue', $evaluated['outbox'][0]['destination']);
    }

    public static function mappingRejections(): iterable
    {
        $physical = ['attachment_id' => 'physical-1', 'physical_catalog_id' => '1',
            'physical_document_name' => 'DISPENSA', 'storage_type' => 'BLOB'];
        yield 'missing physical document' => [[], DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING];
        yield 'ambiguous physical documents' => [[
            $physical, array_replace($physical, ['attachment_id' => 'physical-2']),
        ], DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS];
        yield 'physical document without content' => [[
            array_replace($physical, ['storage_type' => '']),
        ], DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_NO_CONTENT];
    }
}
