<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Events\AuditEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
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
