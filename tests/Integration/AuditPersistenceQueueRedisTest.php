<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class AuditPersistenceQueueRedisTest extends TestCase
{
    public function testSerializesAuditsPerJobAndAllowsIndependentJobs(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis.');
        }

        $redis = RedisClient::getInstance();
        $this->assertTrue($redis->isAvailable());
        $queue = new AuditPersistenceQueue($redis);
        $jobA = AuditEvent::uuidV4();
        $jobB = AuditEvent::uuidV4();
        $events = [
            self::event($jobA),
            self::event($jobA),
            self::event($jobA),
            self::event($jobB),
        ];

        try {
            $this->assertSame(
                AuditPersistenceQueue::ENQUEUE_DISPATCHED,
                $queue->enqueue($events[0])
            );
            $this->assertSame(
                AuditPersistenceQueue::ENQUEUE_PENDING,
                $queue->enqueue($events[1])
            );
            $this->assertSame(
                AuditPersistenceQueue::ENQUEUE_PENDING,
                $queue->enqueue($events[2])
            );
            $this->assertSame(
                AuditPersistenceQueue::ENQUEUE_DISPATCHED,
                $queue->enqueue($events[3])
            );
            $this->assertSame(
                AuditPersistenceQueue::ENQUEUE_DUPLICATE,
                $queue->enqueue($events[0])
            );

            $this->assertSame(
                [$events[0]->auditId, $events[3]->auditId],
                $this->streamAuditIds($redis)
            );

            $this->assertTrue($queue->advance($events[0]));
            $this->assertTrue($queue->advance($events[0]));
            $this->assertSame(
                [$events[0]->auditId, $events[3]->auditId, $events[1]->auditId],
                $this->streamAuditIds($redis)
            );

            $this->assertTrue($queue->advance($events[1]));
            $this->assertSame(
                [
                    $events[0]->auditId,
                    $events[3]->auditId,
                    $events[1]->auditId,
                    $events[2]->auditId,
                ],
                $this->streamAuditIds($redis)
            );
        } finally {
            $this->cleanup($redis, [$jobA, $jobB]);
        }
    }

    private static function event(string $jobId): AuditEvent
    {
        return AuditEvent::create(
            eventType: AuditEvent::TYPE_RULES_EVALUATED,
            auditId: AuditEvent::uuidV4(),
            jobId: $jobId,
            payload: ['final_status' => 'completed'],
        );
    }

    /**
     * @return array<int,string|null>
     */
    private function streamAuditIds(RedisClient $redis): array
    {
        $messages = $redis->xRange(
            AuditEventPublisher::STREAM_PERSISTENCE,
            '-',
            '+',
            100
        );

        return array_map(static function (array $message): ?string {
            $event = json_decode((string) ($message['fields']['event'] ?? ''), true);
            return is_array($event) ? ($event['audit_id'] ?? null) : null;
        }, $messages);
    }

    /**
     * @param array<int,string> $jobIds
     */
    private function cleanup(RedisClient $redis, array $jobIds): void
    {
        foreach ($jobIds as $jobId) {
            $scope = "audit.persistence:{queue}:job:{$jobId}:";
            $redis->del($scope . 'active');
            $redis->del($scope . 'pending');
            $redis->del($scope . 'commands');
            $redis->del($scope . 'seen');
        }
        $redis->del('audit.persistence:{queue}:sequence');
        $redis->del(AuditEventPublisher::STREAM_PERSISTENCE);
    }
}
