<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class AuditEventConsumerReadCountTest extends TestCase
{
    public function testBaseConsumerReadsOneMessagePerXReadGroup(): void
    {
        // Arrange:
        $consumer = new class($this->createStub(RedisClient::class), null, null, 'all') extends AuditEventConsumer {
            protected function streams(): array
            {
                return ['audit.documents.batch'];
            }

            protected function group(): string
            {
                return 'fixture-group';
            }

            protected function consumer(): string
            {
                return 'fixture-consumer';
            }

            protected function eventTypes(): array
            {
                return [AuditEvent::TYPE_DOCUMENT_REGISTERED];
            }

            protected function handle(AuditEvent $event): void
            {
            }
        };
        $readCount = new \ReflectionMethod(AuditEventConsumer::class, 'readCount');

        // Act / Assert: INV-F3-06, sin override el comportamiento previo (COUNT 1).
        self::assertSame(1, $readCount->invoke($consumer));
    }
}
