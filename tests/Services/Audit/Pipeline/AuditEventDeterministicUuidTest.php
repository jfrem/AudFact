<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use PHPUnit\Framework\TestCase;

final class AuditEventDeterministicUuidTest extends TestCase
{
    public function testDeterministicUuidIsStableAndValidV4(): void
    {
        $first = AuditEvent::deterministicUuidV4('audit_created:job-1:dis-9');
        $second = AuditEvent::deterministicUuidV4('audit_created:job-1:dis-9');

        self::assertSame($first, $second);
        self::assertTrue(AuditEvent::isUuidV4($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-a[0-9a-f]{3}-[0-9a-f]{12}$/', $first);
    }

    public function testDifferentIdentitiesProduceDifferentUuids(): void
    {
        self::assertNotSame(
            AuditEvent::deterministicUuidV4('identity-a'),
            AuditEvent::deterministicUuidV4('identity-b')
        );
    }

    public function testAuditTaskEffectsUuidDelegatesToDeterministicUuid(): void
    {
        foreach (['x', 'batch_created:job-2', 'batch-cont:job-2:4'] as $identity) {
            self::assertSame(
                AuditEvent::deterministicUuidV4($identity),
                AuditTaskEffects::uuid($identity)
            );
        }
    }

    public function testCreateUsesProvidedEventIdWhenGiven(): void
    {
        $eventId = AuditEvent::deterministicUuidV4('audit_created:job-1:dis-9');

        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, null, null, null, [], null, $eventId);

        self::assertSame($eventId, $event->eventId);
    }

    public function testCreateGeneratesRandomV4WhenEventIdOmitted(): void
    {
        $event = AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, null);

        self::assertTrue(AuditEvent::isUuidV4($event->eventId));
    }

    public function testCreateRejectsNonV4ProvidedEventId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditEvent::create(AuditEvent::TYPE_AUDIT_CREATED, null, null, null, [], null, 'no-es-uuid');
    }
}
