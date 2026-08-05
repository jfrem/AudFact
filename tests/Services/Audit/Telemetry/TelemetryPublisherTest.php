<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Telemetry;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Telemetry\TelemetryPublisher;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class TelemetryPublisherTest extends TestCase
{
    public function testCompletedPublishesCompactTelemetryEvent(): void
    {
        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $capturedPayload = null;

        $redis = $this->createMock(RedisClient::class);
        $redis->expects($this->once())
            ->method('xAdd')
            ->with(
                'audit.telemetry',
                $this->callback(function (array $fields) use (&$capturedPayload): bool {
                    $capturedPayload = json_decode((string) ($fields['event'] ?? ''), true);
                    return is_array($capturedPayload);
                }),
                1000
            )
            ->willReturn('1-0');

        $publisher = new TelemetryPublisher($redis);
        $publisher->completed(
            $auditId,
            'extraction',
            123,
            $documentId,
            'T38250701547',
            ['worker' => 'extractor-test']
        );

        $this->assertIsArray($capturedPayload);
        $this->assertSame($auditId, $capturedPayload['audit_id'] ?? null);
        $this->assertSame('T38250701547', $capturedPayload['dis_det_nro'] ?? null);
        $this->assertSame($documentId, $capturedPayload['document_id'] ?? null);
        $this->assertSame('extraction', $capturedPayload['node_id'] ?? null);
        $this->assertSame('completed', $capturedPayload['event_type'] ?? null);
        $this->assertSame('completed', $capturedPayload['status'] ?? null);
        $this->assertSame(123, $capturedPayload['meta']['duration_ms'] ?? null);
        $this->assertSame('extractor-test', $capturedPayload['meta']['worker'] ?? null);
        $this->assertIsString($capturedPayload['timestamp'] ?? null);
    }
}
