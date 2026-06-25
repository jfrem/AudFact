<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditStateStore;
use Core\RedisClient;
use Core\RedisUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditStateStoreTest extends TestCase
{
    private MockObject&RedisClient $redis;
    private AuditStateStore $store;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(RedisClient::class);
        $this->store = new AuditStateStore($this->redis);
    }

    public function testInitAuditUsesSetnxAndReturnsTrueOnNewKey(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('setnx')
            ->with(
                AuditStateStore::auditKey($auditId),
                $this->callback(function (string $json) use ($auditId): bool {
                    $decoded = json_decode($json, true);
                    $this->assertSame($auditId, $decoded['audit_id']);
                    $this->assertSame('pending', $decoded['status']);
                    $this->assertSame('T38250701547', $decoded['dis_det_nro']);
                    $this->assertSame(0, $decoded['docs_done']);
                    $this->assertSame(0, $decoded['docs_extracted']);
                    $this->assertSame(0, $decoded['docs_evaluated']);
                    $this->assertSame(0, $decoded['docs_rejected']);
                    return true;
                }),
                604800
            )
            ->willReturn(true);

        $this->assertTrue($this->store->initAudit($auditId, 'T38250701547'));
    }

    public function testInitAuditReturnsFalseWhenDuplicate(): void
    {
        $this->redis->method('setnx')->willReturn(false);
        $this->assertFalse($this->store->initAudit(AuditEvent::uuidV4(), 'T1'));
    }



    public function testGetAuditReturnsNullWhenKeyMissing(): void
    {
        $this->redis->method('get')->willReturn(null);
        $this->assertNull($this->store->getAudit(AuditEvent::uuidV4()));
    }

    public function testGetAuditThrowsRuntimeExceptionOnRedisDown(): void
    {
        $this->redis
            ->method('get')
            ->willThrowException(new RedisUnavailableException('down'));

        $this->expectException(RuntimeException::class);
        $this->store->getAudit(AuditEvent::uuidV4());
    }



    public function testPatchAuditUsesEvalWithMergeLua(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('cjson.decode'),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args): bool {
                    $this->assertIsArray($args[0]);
                    $this->assertSame('processing', $args[0]['status']);
                    $this->assertArrayHasKey('updated_at', $args[0]);
                    $this->assertSame(604800, $args[1]);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->patchAudit($auditId, ['status' => 'processing']));
    }

    public function testSetAuditDocumentsTotalMarksAuditAsProcessing(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('cjson.decode'),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args): bool {
                    $this->assertSame(3, $args[0]['docs_total']);
                    $this->assertSame('processing', $args[0]['status']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->setAuditDocumentsTotal($auditId, 3));
    }

    public function testRegisterDocumentStoresDocumentStateWithEval(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains("audit['documents']"),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args) use ($documentId): bool {
                    $this->assertSame($documentId, $args[0]);
                    $this->assertSame('registered', $args[1]['status']);
                    $this->assertSame('DISPENSA', $args[1]['document_name']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->registerDocument($auditId, $documentId, [
            'status' => 'registered',
            'document_name' => 'DISPENSA',
        ]));
    }

    public function testMarkDocumentExtractedUpdatesDocumentAndCounters(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("previousStatus ~= expectedStatus"),
                    $this->stringContains("audit[counterField]")
                ),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args) use ($documentId): bool {
                    $this->assertSame($documentId, $args[0]);
                    $this->assertSame('extracted', $args[1]['status']);
                    $this->assertSame('abc123', $args[1]['document_hash']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->markDocumentExtracted($auditId, $documentId, [
            'status' => 'extracted',
            'document_hash' => 'abc123',
        ]));
    }

    public function testMarkDocumentExtractedDoesNotCloseAuditDuringExtractionStage(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("audit['status'] = 'processing'"),
                    $this->logicalNot($this->stringContains("audit['status'] = 'completed'"))
                ),
                [AuditStateStore::auditKey($auditId)],
                $this->anything()
            )
            ->willReturn(1);

        $this->assertTrue($this->store->markDocumentExtracted($auditId, $documentId, [
            'status' => 'extracted',
            'document_hash' => 'hash',
        ]));
    }

    public function testMarkDocumentNormalizedUpdatesDocumentAndKeepsAuditProcessing(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("previousStatus ~= expectedStatus"),
                    $this->stringContains("audit[counterField]"),
                    $this->stringContains("audit['status'] = 'processing'")
                ),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args) use ($documentId): bool {
                    $this->assertSame($documentId, $args[0]);
                    $this->assertSame('normalized', $args[1]['status']);
                    $this->assertSame('ACTA_DE_ENTREGA', $args[1]['normalized_result']['tipo_documento']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->markDocumentNormalized($auditId, $documentId, [
            'status' => 'normalized',
            'normalized_result' => [
                'tipo_documento' => 'ACTA_DE_ENTREGA',
                'fields_normalized' => [],
            ],
        ]));
    }

    public function testMarkDocumentEvaluatedUpdatesDocumentAndCounter(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("previousStatus ~= expectedStatus"),
                    $this->stringContains("audit[counterField]")
                ),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args) use ($documentId): bool {
                    $this->assertSame($documentId, $args[0]);
                    $this->assertSame('evaluated', $args[1]['status']);
                    $this->assertSame('DISPENSA', $args[1]['policy_result']['document_name']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->markDocumentEvaluated($auditId, $documentId, [
            'status' => 'evaluated',
            'policy_result' => [
                'document_name' => 'DISPENSA',
            ],
        ]));
    }

    public function testMarkDocumentRejectedUpdatesDocumentAndRejectedCounterOnly(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("previousStatus == 'evaluated'"),
                    $this->stringContains("audit['docs_rejected']"),
                    $this->logicalNot($this->stringContains("audit['docs_evaluated'] ="))
                ),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args) use ($documentId): bool {
                    $this->assertSame($documentId, $args[0]);
                    $this->assertSame('UNKNOWN_FILE_SIGNATURE', $args[1]['rejection_reason']);
                    $this->assertSame('FORMULA MEDICA', $args[1]['document_type']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->markDocumentRejected($auditId, $documentId, [
            'rejection_reason' => 'UNKNOWN_FILE_SIGNATURE',
            'document_type' => 'FORMULA MEDICA',
        ]));
    }

    public function testStoreRulesEvaluationUsesAtomicWrite(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains("rules_evaluated_result"),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args): bool {
                    $this->assertSame(3, $args[0]['hallazgos']['metrics']['total_campos']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->storeRulesEvaluation($auditId, [
            'hallazgos' => ['metrics' => ['total_campos' => 3]],
            'document_decisions' => [],
        ]));
    }

    public function testCompleteAuditPersistsFinalStatus(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains("audit['completed_at']"),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args): bool {
                    $this->assertSame('manual_review', $args[0]['status']);
                    $this->assertTrue($args[0]['requires_manual_review']);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->completeAudit($auditId, [
            'status' => 'manual_review',
            'requires_manual_review' => true,
        ]));
    }

    public function testRecordEventTelemetryAppendsScalarTiming(): void
    {
        $auditId = AuditEvent::uuidV4();

        $this->redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains("event_timings"),
                [AuditStateStore::auditKey($auditId)],
                $this->callback(function (array $args): bool {
                    $this->assertSame('audit_created', $args[0]['event_type']);
                    $this->assertSame('audit.inbox', $args[0]['stream']);
                    $this->assertSame(123, $args[0]['queue_wait_ms']);
                    $this->assertSame(604800, $args[2]);
                    return true;
                })
            )
            ->willReturn(1);

        $this->assertTrue($this->store->recordEventTelemetry($auditId, [
            'event_type' => 'audit_created',
            'stream' => 'audit.inbox',
            'queue_wait_ms' => 123,
        ]));
    }
}
