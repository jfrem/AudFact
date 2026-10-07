<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\ObservabilityController;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use Core\RedisClient;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditPersistenceQueueRedisTest extends TestCase
{
    private IsolatedPersistenceRedis $redis;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere RUN_REDIS_INTEGRATION=1 y Redis.');
        }
        $this->redis = new IsolatedPersistenceRedis(RedisClient::getInstance(),
            'integration:queue:' . AuditEvent::uuidV4() . ':');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testSerializesWithOneSlotAndAllowsIndependentJobs(): void
    {
        // Arrange:
        $queue = new AuditPersistenceQueue($this->redis, 1);
        $events = [self::event(1), self::event(2), self::event(3), self::event(4, '20000000-0000-4000-8000-000000000000')];

        // Act:
        $results = array_map($queue->enqueue(...), $events);

        // Assert:
        $this->assertSame([1, 2, 2, 1], $results);
        $this->assertSame(0, $queue->enqueue($events[0]));
        $this->assertSame(['active' => 2, 'pending' => 2, 'scopes' => 2], $queue->metrics());
        $this->assertTrue($queue->advance($events[0]));
        $this->assertTrue($queue->advance($events[0]));
        $this->assertSame([$events[0]->auditId, $events[3]->auditId, $events[1]->auditId], $this->publishedIds());
        $this->assertTrue($queue->advance($events[1]));
        $this->assertTrue($queue->advance($events[2]));
        $this->assertTrue($queue->advance($events[3]));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $queue->metrics());
    }

    public function testPinnedSlotsSurviveConfigurationChangesAndReprocessing(): void
    {
        // Arrange:
        $original = new AuditPersistenceQueue($this->redis, 2);
        $reduced = new AuditPersistenceQueue($this->redis, 1);
        $increased = new AuditPersistenceQueue($this->redis, 4);
        $events = [self::event(1), self::event(2), self::event(3), self::event(4)];

        // Act:
        $results = array_map($original->enqueue(...), $events);
        $reduced->advance($events[0]);
        $increased->advance($events[2]);

        // Assert:
        $this->assertSame([1, 2, 1, 2], $results);
        $this->assertSame([$events[0]->auditId, $events[2]->auditId, $events[1]->auditId, $events[3]->auditId], $this->publishedIds());
        $this->assertTrue($reduced->advance($events[1]));
        $this->assertTrue($increased->advance($events[3]));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $original->metrics());
        $this->assertSame(1, $increased->reprocess($events[0]));
        $this->assertSame(0, $reduced->reprocess($events[0]));
        $this->assertTrue($reduced->advanceAfterFailure($events[0]));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $original->metrics());
    }

    public function testDetectsLegacyActiveJobWithoutMovingItsPendingCommands(): void
    {
        // Arrange:
        $first = self::event(1);
        $second = self::event(3); // Otro slot si se usara la configuración actual.
        $root = 'audit.persistence:{queue}:job:' . $first->jobId;
        $this->redis->eval(
            'redis.call("SET",KEYS[1],ARGV[1],"EX",600); redis.call("HSET",KEYS[2],ARGV[1],ARGV[2]); redis.call("HSET",KEYS[3],ARGV[1],ARGV[3]); return 1',
            [$root . ':active', $root . ':commands', $root . ':seen'],
            [$first->auditId, $first->toJson(), $first->eventId]
        );
        $queue = new AuditPersistenceQueue($this->redis, 4);

        // Act:
        $result = $queue->enqueue($second);
        $queue->advanceAfterFailure($first);

        // Assert:
        $this->assertSame(2, $result);
        $this->assertSame('1', $this->redis->eval('return redis.call("GET",KEYS[1])', [$root . ':slots']));
        $this->assertSame([$second->auditId], $this->publishedIds());
        $this->assertTrue($queue->advance($second));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $queue->metrics());
    }

    public function testTerminalFailureDoesNotReleaseAnotherSlotsOwner(): void
    {
        // Arrange:
        $queue = new AuditPersistenceQueue($this->redis, 2);
        $first = self::event(1);
        $waiting = self::event(2);
        $independent = self::event(3);
        $queue->enqueue($first);
        $queue->enqueue($waiting);
        $queue->enqueue($independent);

        // Act:
        $queue->advanceAfterFailure($first);
        $queue->advanceAfterFailure($first);

        // Assert:
        $this->assertSame([$first->auditId, $independent->auditId, $waiting->auditId], $this->publishedIds());
        $this->assertSame(['active' => 2, 'pending' => 0, 'scopes' => 2], $queue->metrics());
    }

    public function testOneHundredAuditsDrainAcrossFourSlotsWithoutDuplicates(): void
    {
        // Arrange:
        $queue = new AuditPersistenceQueue($this->redis, 4);
        $events = [];
        for ($n = 1; $n <= 100; $n++) {
            $event = self::event($n);
            $events[$event->auditId] = $event;
            $queue->enqueue($event);
        }

        // Act:
        $initial = $queue->metrics();
        for ($i = 0; $i < 100; $i++) {
            $published = $this->publishedIds();
            $this->assertArrayHasKey($i, $published, 'Cada turno debe publicar su sucesor.');
            $queue->advance($events[$published[$i]]);
        }

        // Assert:
        $this->assertSame(['active' => 4, 'pending' => 96, 'scopes' => 4], $initial);
        $published = $this->publishedIds();
        $this->assertCount(100, $published);
        $this->assertCount(100, array_unique($published));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $queue->metrics());
    }

    #[DataProvider('recoveryCases')]
    public function testRecoversLostSlotsWithoutChangingThePartition(int $configured, bool $terminal): void
    {
        // Arrange:
        $original = new AuditPersistenceQueue($this->redis, 2);
        $first = self::event(1);
        $waiting = self::event(2);
        $independent = self::event(3);
        $original->enqueue($first);
        $original->enqueue($waiting);
        $root = 'audit.persistence:{queue}:job:' . $first->jobId;
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [$root . ':slots']);
        $reconfigured = new AuditPersistenceQueue($this->redis, $configured);

        // Act:
        $advanced = $terminal ? $reconfigured->advanceAfterFailure($first) : $reconfigured->advance($first);
        $duplicate = $reconfigured->enqueue($waiting);
        $newAudit = $reconfigured->enqueue($independent);

        // Assert:
        $this->assertTrue($advanced);
        $this->assertSame('2', $this->redis->eval('return redis.call("GET",KEYS[1])', [$root . ':slots']));
        $this->assertSame(0, $duplicate);
        $this->assertSame(1, $newAudit);
        $this->assertSame([$first->auditId, $waiting->auditId, $independent->auditId], $this->publishedIds());
        $this->assertTrue($reconfigured->advance($first)); // No libera al nuevo dueño.
        $this->assertSame(['active' => 2, 'pending' => 0, 'scopes' => 2], $reconfigured->metrics());
        $this->assertTrue($reconfigured->advance($waiting));
        $this->assertTrue($reconfigured->advance($independent));
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $reconfigured->metrics());
    }

    public static function recoveryCases(): iterable
    {
        yield 'reduce a uno' => [1, false];
        yield 'aumenta a cuatro' => [4, false];
        yield 'aumenta al máximo' => [16, false];
        yield 'fallo terminal con uno' => [1, true];
        yield 'fallo terminal con cuatro' => [4, true];
        yield 'fallo terminal con máximo' => [16, true];
    }

    public function testDrainsPreviouslyPartitionedCommandsWithoutRecoverableCount(): void
    {
        // Arrange:
        $first = self::event(1);
        $waiting = self::event(2);
        $scope = 'job:' . $first->jobId . ':slot:1';
        $this->seedPreviousScope($scope, $first, $waiting);
        $queue = new AuditPersistenceQueue($this->redis, 1);

        // Act:
        $advanced = $queue->advance($first);
        $duplicate = $queue->reprocess($waiting);
        $closed = $queue->advanceAfterFailure($waiting);

        // Assert:
        $this->assertTrue($advanced);
        $this->assertSame(0, $duplicate);
        $this->assertTrue($closed);
        $this->assertSame([$waiting->auditId], $this->publishedIds());
        $this->assertSame(['active' => 0, 'pending' => 0, 'scopes' => 0], $queue->metrics());
    }

    public function testRejectsNewAuditsInsteadOfGuessingMissingPartitionMetadata(): void
    {
        // Arrange:
        $first = self::event(1);
        $this->seedPreviousScope('job:' . $first->jobId . ':slot:1', $first, self::event(2));
        $queue = new AuditPersistenceQueue($this->redis, 1);

        // Assert:
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no se admiten auditorias nuevas');

        // Act:
        $queue->enqueue(self::event(3));
    }

    public function testReportsLegacyQueueBeforeAnyNewEnqueue(): void
    {
        // Arrange:
        $first = self::event(1);
        $this->seedPreviousScope('job:' . $first->jobId, $first, self::event(2));
        $queue = new AuditPersistenceQueue($this->redis, 4);

        // Act:
        $metrics = $queue->metrics();

        // Assert:
        $this->assertSame(['active' => 1, 'pending' => 1, 'scopes' => 1], $metrics);
        $this->assertSame([], $this->publishedIds());
    }

    public function testRebuildsLostIndexIncludingLegacyAndPartitionedScopes(): void
    {
        // Arrange:
        $queue = new AuditPersistenceQueue($this->redis, 2);
        $queue->enqueue(self::event(1));
        $queue->enqueue(self::event(2));
        $first = self::event(3, '20000000-0000-4000-8000-000000000000');
        $this->seedPreviousScope('job:' . $first->jobId, $first,
            self::event(4, (string) $first->jobId));
        $initial = $queue->metrics();
        $this->redis->eval('return redis.call("DEL",KEYS[1])', ['audit.persistence:{queue}:scopes']);

        // Act:
        $rebuilt = $queue->metrics();

        // Assert:
        $this->assertSame(['active' => 2, 'pending' => 2, 'scopes' => 2], $initial);
        $this->assertSame($initial, $rebuilt);
    }

    public function testRestoresMetadataLostBetweenResolutionAndEnqueue(): void
    {
        // Arrange:
        $faultInjected = false;
        $this->redis = new IsolatedPersistenceRedis(RedisClient::getInstance(),
            'integration:queue:' . AuditEvent::uuidV4() . ':',
            static function (string $script, array $keys, RedisClient $inner) use (&$faultInjected): void {
                if (!$faultInjected && str_contains($script, 'local function refreshSlots')) {
                    $inner->eval('return redis.call("DEL",KEYS[1])', [$keys[6]]);
                    $faultInjected = true;
                }
            });
        $first = self::event(1);
        $waiting = self::event(2);
        $original = new AuditPersistenceQueue($this->redis, 2);
        $reconfigured = new AuditPersistenceQueue($this->redis, 1);

        // Act:
        $dispatched = $original->enqueue($first);
        $pending = $original->enqueue($waiting);
        $advanced = $reconfigured->advance($first);

        // Assert:
        $this->assertTrue($faultInjected);
        $this->assertSame(1, $dispatched);
        $this->assertSame(2, $pending);
        $this->assertTrue($advanced);
        $this->assertSame([$first->auditId, $waiting->auditId], $this->publishedIds());
    }

    public function testMetricsEndpointReadsRealXinfoAndInternalBacklog(): void
    {
        // Arrange:
        $queue = new AuditPersistenceQueue($this->redis, 2);
        $queue->enqueue(self::event(1));
        $queue->enqueue(self::event(2));
        $this->redis->eval(
            'redis.call("XGROUP","CREATE",KEYS[1],"persistence","0"); return redis.call("XREADGROUP","GROUP","persistence","integration","COUNT",1,"STREAMS",KEYS[1],">")',
            [AuditEventPublisher::STREAM_PERSISTENCE_BATCH]
        );
        $controller = new RedisMetricsController($this->redis);

        // Act:
        $response = self::captureMetrics($controller);
        $data = $response->getData()['data'];

        // Assert:
        $this->assertSame(200, $response->getCode());
        $this->assertSame(1, $data['queueDepth']);
        $this->assertSame(2, $data['backlogDepth']);
        $this->assertSame(['pending' => 1, 'lag' => 0, 'lagKnown' => true],
            $data['streamBacklogs']['persistence_batch']['persistence']);
        $this->assertSame(['active' => 1, 'pending' => 1, 'scopes' => 1], $data['persistenceScheduler']);
    }

    public function testMetricsEndpointReturns503ForRealPartialRedisFailure(): void
    {
        // Arrange:
        $this->redis->eval('return redis.call("SET",KEYS[1],"invalid-stream")', [AuditEventPublisher::dlqStream()]);
        $controller = new RedisMetricsController($this->redis);

        // Act:
        $response = self::captureMetrics($controller);

        // Assert:
        $this->assertSame(503, $response->getCode());
        $this->assertFalse($response->getData()['success']);
    }

    public function testMetricsDoesNotCertifyAnIndexLostDuringReconciliation(): void
    {
        // Arrange:
        $this->redis = new IsolatedPersistenceRedis(RedisClient::getInstance(),
            'integration:queue:' . AuditEvent::uuidV4() . ':',
            static function (string $script, array $keys, RedisClient $inner): void {
                if (str_contains($script, "'__ready')") && str_contains($script, 'ZREM')) {
                    $inner->eval('return redis.call("DEL",KEYS[1])', [$keys[0]]);
                }
            });
        $queue = new AuditPersistenceQueue($this->redis, 2);
        $queue->enqueue(self::event(1));
        $queue->enqueue(self::event(2));
        $controller = new RedisMetricsController($this->redis);

        // Act:
        $response = self::captureMetrics($controller);

        // Assert:
        $this->assertSame(503, $response->getCode());
        $this->assertFalse($response->getData()['success']);
    }

    private static function captureMetrics(ObservabilityController $controller): HttpResponseException
    {
        try {
            $controller->asyncMetrics();
        } catch (HttpResponseException $response) {
            return $response;
        }
        self::fail('Se esperaba una respuesta HTTP de métricas');
    }

    private function seedPreviousScope(string $scope, AuditEvent $first, AuditEvent $waiting): void
    {
        $root = 'audit.persistence:{queue}:' . $scope;
        $this->redis->eval(
            'redis.call("SET",KEYS[1],ARGV[1],"EX",600); redis.call("ZADD",KEYS[2],1,ARGV[2]); redis.call("HSET",KEYS[3],ARGV[1],ARGV[3],ARGV[2],ARGV[4]); redis.call("HSET",KEYS[4],ARGV[1],ARGV[5],ARGV[2],ARGV[6]); for i=2,4 do redis.call("EXPIRE",KEYS[i],600) end; return 1',
            [$root . ':active', $root . ':pending', $root . ':commands', $root . ':seen'],
            [$first->auditId, $waiting->auditId, $first->toJson(), $waiting->toJson(), $first->eventId, $waiting->eventId]
        );
    }

    private static function event(int $n, string $job = '10000000-0000-4000-8000-000000000000'): AuditEvent
    {
        return AuditEvent::create(AuditEvent::TYPE_RULES_EVALUATED,
            sprintf('%08d-0000-4000-8000-000000000000', $n), jobId: $job,
            payload: ['source' => 'batch', 'final_status' => 'completed']);
    }

    private function publishedIds(): array
    {
        return $this->redis->eval(
            'local ids={}; for _,m in ipairs(redis.call("XRANGE",KEYS[1],"-","+")) do ids[#ids+1]=cjson.decode(m[2][2]).audit_id end; return ids',
            [AuditEventPublisher::STREAM_PERSISTENCE_BATCH]
        );
    }
}

final class RedisMetricsController extends ObservabilityController
{
    public function __construct(private readonly RedisClient $redis)
    {
    }

    protected function buildRedisClient(): RedisClient
    {
        return $this->redis;
    }
}

/** Adaptador de integración: todas las claves, incluidos streams, quedan aisladas. */
final class IsolatedPersistenceRedis extends RedisClient
{
    private array $ownedKeys = [];

    public function __construct(
        private readonly RedisClient $inner,
        private readonly string $namespace,
        private readonly ?\Closure $beforeEval = null
    )
    {
    }

    public function eval(string $script, array $keys = [], array $argv = []): mixed
    {
        $keys = array_map(function (string $key): string {
            $isolated = $this->namespace . $key;
            $this->ownedKeys[$isolated] = true;
            return $isolated;
        }, $keys);
        if ($this->beforeEval !== null) {
            ($this->beforeEval)($script, $keys, $this->inner);
        }
        return $this->inner->eval($script, $keys, $argv);
    }

    public function cleanup(): void
    {
        foreach (array_keys($this->ownedKeys) as $key) {
            $this->inner->del($key);
        }
    }
}
