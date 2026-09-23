<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    #[DataProvider('routingCases')]
    public function testFollowUpPreservesParentRoutingAfterSerialization(
        array $routing,
        bool $priority
    ): void {
        // Arrange:
        $parent = self::extractedEvent($routing);

        // Act:
        $child = $parent->followUp(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            payload: ['fields_normalized' => [], 'source' => 'untrusted-result', 'is_priority' => !$priority],
            documentId: 'doc-1'
        );
        $restored = AuditEvent::fromArray(json_decode($child->toJson(), true, flags: JSON_THROW_ON_ERROR));

        // Assert:
        $this->assertSame(['fields_normalized' => []] + $routing, $restored->payload);
        $this->assertSame($priority, AuditEventPublisher::isPriorityEvent($restored));
        $this->assertSame(
            $priority ? AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY : AuditEventPublisher::STREAM_DOCUMENTS_BATCH,
            AuditEventPublisher::streamForEvent($restored)
        );
    }

    public function testFollowUpKeepsIdentityAndParentLinksAcrossDocumentAndAuditStages(): void
    {
        // Arrange:
        $parent = self::extractedEvent(['source' => 'single']);
        $original = $parent->toArray();

        // Act:
        $document = $parent->followUp(
            eventType: AuditEvent::TYPE_DOCUMENT_NORMALIZED,
            payload: ['fields_normalized' => []],
            documentId: 'doc-1'
        );
        $consolidated = $document->followUp(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            payload: ['final_status' => 'completed'],
            documentId: null
        );
        $terminal = $consolidated->followUp(
            eventType: AuditEvent::TYPE_AUDIT_COMPLETED,
            payload: ['status' => 'completed'],
            documentId: null
        );

        // Assert:
        $this->assertSame($original, $parent->toArray());
        $this->assertSame($parent->eventId, $document->parentEventId);
        $this->assertNotSame($parent->eventId, $document->eventId);
        $this->assertSame('doc-1', $document->documentId);
        $this->assertSame($document->eventId, $consolidated->parentEventId);
        $this->assertNull($consolidated->documentId);
        $this->assertSame($consolidated->eventId, $terminal->parentEventId);
        $this->assertSame($parent->auditId, $terminal->auditId);
        $this->assertSame($parent->jobId, $terminal->jobId);
        $this->assertNull($terminal->documentId);
        $this->assertSame(['fields_normalized' => [], 'source' => 'single'], $document->payload);
        $this->assertSame(['final_status' => 'completed', 'source' => 'single'], $consolidated->payload);
        $this->assertSame(['status' => 'completed', 'source' => 'single'], $terminal->payload);
    }

    private static function extractedEvent(array $routing): AuditEvent
    {
        return AuditEvent::fromArray([
            'event_id' => '11111111-1111-4111-8111-111111111111',
            'audit_id' => '22222222-2222-4222-8222-222222222222',
            'job_id' => '33333333-3333-4333-8333-333333333333',
            'document_id' => 'doc-1',
            'event_type' => AuditEvent::TYPE_DOCUMENT_EXTRACTED,
            'timestamp' => '2026-09-23T12:00:00Z',
            'payload' => $routing + ['extraction_result' => ['fields' => []]],
        ]);
    }

    public static function routingCases(): iterable
    {
        yield 'single' => [['source' => 'single', 'is_priority' => true], true];
        yield 'source alone' => [['source' => 'single'], true];
        yield 'priority alone does not invent source' => [['is_priority' => true], true];
        yield 'priority cron preserves origin' => [['source' => 'cron', 'is_priority' => true], true];
        yield 'batch' => [['source' => 'batch', 'is_priority' => false], false];
        yield 'cron' => [['source' => 'cron'], false];
        yield 'absent metadata stays batch' => [[], false];
        yield 'null metadata stays null' => [['source' => null, 'is_priority' => null], false];
        yield 'unknown source stays batch' => [['source' => 'external'], false];
        yield 'string true does not promote' => [['is_priority' => 'true'], false];
        yield 'integer one does not promote' => [['is_priority' => 1], false];
        yield 'single retains classifier precedence' => [['source' => 'single', 'is_priority' => false], true];
    }

    public function testUuidV4IsValid(): void
    {
        $uuid = AuditEvent::uuidV4();
        $this->assertTrue(AuditEvent::isUuidV4($uuid));
    }

    public function testCreateGeneratesIsoTimestamp(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547', 'fac_nit_sec' => null, 'source' => 'single'],
        );

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $event->timestamp
        );
    }

    public function testToJsonRoundtripPreservesAllFields(): void
    {
        $auditId = AuditEvent::uuidV4();
        $parent = AuditEvent::uuidV4();

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: $auditId,
            jobId: null,
            documentId: '2',
            payload: ['tipo_documento' => 'DISPENSA'],
            parentEventId: $parent,
        );

        $restored = AuditEvent::fromArray(json_decode($event->toJson(), true));

        $this->assertSame($auditId, $restored->auditId);
        $this->assertSame($parent, $restored->parentEventId);
        $this->assertSame('2', $restored->documentId);
        $this->assertSame(['tipo_documento' => 'DISPENSA'], $restored->payload);
    }

    public function testCreateRejectsInvalidAuditId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: 'not-a-uuid',
        );
    }

    public function testCreateAllowsNullAuditIdForBatchCreated(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_BATCH_CREATED,
            auditId: null,
            jobId: AuditEvent::uuidV4(),
        );

        $this->assertNull($event->auditId);
    }

    public function testFromArrayRejectsMissingEventId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEvent::fromArray([
            'event_type' => AuditEvent::TYPE_AUDIT_CREATED,
            'timestamp'  => '2026-04-23T10:00:00Z',
        ]);
    }

    public function testFromArrayRejectsInvalidTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEvent::fromArray([
            'event_id'   => AuditEvent::uuidV4(),
            'event_type' => AuditEvent::TYPE_AUDIT_CREATED,
            'timestamp'  => '2026/04/23 10:00',
        ]);
    }

    public function testIsUuidV4RejectsOtherVersions(): void
    {
        $this->assertFalse(AuditEvent::isUuidV4('12345678-1234-1234-1234-123456789012'));
        $this->assertFalse(AuditEvent::isUuidV4('not-a-uuid-at-all'));
        $this->assertFalse(AuditEvent::isUuidV4(''));
    }
}
