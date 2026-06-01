<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\RedisClient;
use Core\RedisUnavailableException;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditEventPublisherTest extends TestCase
{
    private MockObject&RedisClient $redis;
    private AuditEventPublisher $publisher;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(RedisClient::class);
        $this->publisher = new AuditEventPublisher($this->redis);
    }

    public function testAuditCreatedRoutesToInboxStream(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547', 'fac_nit_sec' => null, 'source' => 'single'],
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(
                AuditEventPublisher::STREAM_INBOX,
                $this->callback(function (array $fields) {
                    $this->assertArrayHasKey('event', $fields);
                    $decoded = json_decode($fields['event'], true);
                    $this->assertSame(AuditEvent::TYPE_AUDIT_CREATED, $decoded['event_type']);
                    return true;
                })
            )
            ->willReturn('1700000000000-0');

        $id = $this->publisher->publish($event);
        $this->assertSame('1700000000000-0', $id);
    }

    public function testDocumentRegisteredRoutesToDocumentsStream(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: AuditEvent::uuidV4(),
            documentId: '1',
            payload: ['tipo_documento' => 'DISPENSA'],
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(AuditEventPublisher::STREAM_DOCUMENTS, $this->anything())
            ->willReturn('1700000000001-0');

        $this->publisher->publish($event);
    }

    public function testDocumentRejectedRoutesToDocumentsStream(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REJECTED,
            auditId: AuditEvent::uuidV4(),
            documentId: '1',
            payload: ['rejection_reason' => 'UNKNOWN_FILE_SIGNATURE'],
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(AuditEventPublisher::STREAM_DOCUMENTS, $this->anything())
            ->willReturn('1700000000001-1');

        $this->publisher->publish($event);
    }

    public function testRulesEvaluatedRoutesToResultsStream(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(AuditEventPublisher::STREAM_RESULTS, $this->anything())
            ->willReturn('1700000000002-0');

        $this->publisher->publish($event);
    }

    public function testRedisUnavailableThrowsRuntimeException(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->redis
            ->method('xAdd')
            ->willThrowException(new RedisUnavailableException('Redis down'));

        $this->expectException(RuntimeException::class);
        $this->publisher->publish($event);
    }

    public function testNullReturnFromXAddThrowsRuntimeException(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->redis->method('xAdd')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->publisher->publish($event);
    }

    public function testPublishDeadLetterRequiresDeadLetterType(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->publisher->publishDeadLetter($event);
    }

    public function testStreamForEventTypeRejectsDeadLetter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditEventPublisher::streamForEventType(AuditEvent::TYPE_DEAD_LETTER);
    }
}
