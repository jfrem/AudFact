<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use Core\RedisClient;
use Core\RedisUnavailableException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('explicitRoutingCases')]
    public function testDirectPublicationUsesExplicitPriorityRegardlessOfJob(array $routing, ?string $jobId, bool $priority): void
    {
        // Arrange:
        $event = AuditEvent::fromArray([
            'event_id' => '11111111-1111-4111-8111-111111111111',
            'audit_id' => '22222222-2222-4222-8222-222222222222',
            'job_id' => $jobId,
            'event_type' => AuditEvent::TYPE_AUDIT_CREATED,
            'timestamp' => '2026-09-23T12:00:00Z',
            'payload' => $routing,
        ]);
        $stream = $priority ? AuditEventPublisher::STREAM_INBOX_PRIORITY : AuditEventPublisher::STREAM_INBOX_BATCH;
        $this->redis->expects($this->once())->method('xAdd')
            ->with($stream, $this->anything(), $this->anything())->willReturn('1-0');

        // Act:
        $id = $this->publisher->publish($event);

        // Assert:
        $this->assertSame('1-0', $id);
        $this->assertSame($priority, AuditEventPublisher::isPriorityEvent($event));
    }

    public static function explicitRoutingCases(): iterable
    {
        foreach ([null, '33333333-3333-4333-8333-333333333333'] as $jobId) {
            $scope = $jobId === null ? 'without job' : 'with job';
            yield "single only $scope" => [['source' => 'single'], $jobId, true];
            yield "priority only $scope" => [['is_priority' => true], $jobId, true];
            yield "single false flag $scope" => [['source' => 'single', 'is_priority' => false], $jobId, true];
            yield "priority cron $scope" => [['source' => 'cron', 'is_priority' => true], $jobId, true];
            yield "batch $scope" => [['source' => 'batch'], $jobId, false];
            yield "absent $scope" => [[], $jobId, false];
            yield "null $scope" => [['source' => null, 'is_priority' => null], $jobId, false];
            yield "string true $scope" => [['is_priority' => 'true'], $jobId, false];
            yield "integer one $scope" => [['is_priority' => 1], $jobId, false];
        }
    }

    public function testAuditCreatedRoutesToPriorityStreamWhenSingle(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547', 'fac_nit_sec' => null, 'source' => 'single', 'is_priority' => true],
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(
                AuditEventPublisher::STREAM_INBOX_PRIORITY,
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

    public function testAuditCreatedRoutesToBatchStreamWhenBatch(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            jobId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547', 'source' => 'batch'],
        );

        $this->redis
            ->expects($this->once())
            ->method('xAdd')
            ->with(
                AuditEventPublisher::STREAM_INBOX_BATCH,
                $this->anything()
            )
            ->willReturn('1700000000000-1');

        $id = $this->publisher->publish($event);
        $this->assertSame('1700000000000-1', $id);
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
            ->with(AuditEventPublisher::STREAM_DOCUMENTS_BATCH, $this->anything())
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
            ->with(AuditEventPublisher::STREAM_DOCUMENTS_BATCH, $this->anything())
            ->willReturn('1700000000001-1');

        $this->publisher->publish($event);
    }

    public function testRulesEvaluatedCannotBypassPersistenceQueue(): void
    {
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
        );

        $this->redis->expects($this->never())->method('xAdd');
        $this->expectException(InvalidArgumentException::class);

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
