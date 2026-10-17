<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class GeminiQuotaConcurrencyRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private string $namespace;
    private array $tasks;

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real aislado.');
        }
        $this->namespace = 'integration:audit:' . AuditEvent::uuidV4() . ':';
        $this->redis = new IsolatedAuditRedis($this->namespace);
        $this->redis->eval('return redis.call("SET",KEYS[1],"2")', ['audit:execution:version']);
        $queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $this->tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $lane = $i % 4 < 2 ? AuditLane::PRIORITY : AuditLane::BATCH;
            $stage = $i % 2 === 0 ? AuditTaskStage::EXTRACTION : AuditTaskStage::SEMANTIC;
            $payload = $stage === AuditTaskStage::SEMANTIC
                ? ['plan_id' => hash('sha256', (string) $i), 'generation' => 1, 'wave' => 0,
                    'purpose' => 'person_name_match', 'pair_ids' => [hash('sha256', 'pair')]] : [];
            $payload += ['source' => $lane === AuditLane::PRIORITY ? 'single' : 'batch'];
            $event = AuditEvent::create($stage->eventTypes()[0], AuditEvent::uuidV4(), documentId: 'fixture', payload: $payload);
            $stream = AuditEventPublisher::streamForEvent($event);
            $id = $this->redis->eval('redis.call("SET",KEYS[1],ARGV[1]); redis.pcall("XGROUP","CREATE",KEYS[2],"test","0","MKSTREAM"); local id=redis.call("XADD",KEYS[2],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP","test","intake","COUNT",1,"STREAMS",KEYS[2],">"); return id',
                [AuditStateStore::auditKey($event->auditId), $stream],
                [json_encode(['audit_id' => $event->auditId, 'status' => 'processing', 'documents' => []]), $event->toJson()]);
            $queue->admit(AuditTask::fromEvent($stage, $event), $stream, 'test', $id);
            $this->tasks[] = $queue->claim($stage, $lane, 'fixture-' . $i);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testOneHundredConcurrentReservationsCannotExceedSharedLimits(): void
    {
        // Arrange:
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Requiere proc_open para conexiones concurrentes.');
        }
        $children = [];

        // Act:
        foreach (array_chunk(array_map(static fn(AuditTask $task): string => $task->id(), $this->tasks), 5) as $ids) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/reserve-gemini-quota.php',
                $this->namespace, json_encode($ids)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        $grants = 0;
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            $grants += (int) $output;
        }
        $metrics = $this->budget(3, 100, 5, 2)->metrics();

        // Assert:
        self::assertSame(2, $grants);
        self::assertSame(2, $metrics['rpm_used']);
        self::assertSame(100, $metrics['tpm_reserved']);
        self::assertSame(2, $metrics['rpd_used']);
        self::assertSame(2, $metrics['inflight']);
    }

    public function testContinuouslyEligibleDemandSharesLanesThreeToOneAndAlternatesStages(): void
    {
        // Arrange:
        $budget = $this->budget(100, 10000, 1000, 4);
        $base = 'gemini:quota:v2:' . hash('sha256', 'race') . ':' . hash('sha256', 'fixture');
        $now = (new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2)))->nowMs();
        $this->redis->eval('for i=1,100 do redis.call("ZADD",KEYS[1],ARGV[1],"seed-"..i) end; return 1', [$base . ':minute'], [$now]);
        $requests = array_map(static fn(AuditTask $task): array => ['task' => $task->record,
            'operation' => 'generate_content', 'tokens' => 50, 'body_hash' => hash('sha256', $task->id())], $this->tasks);
        foreach ($requests as $request) {
            self::assertSame('DEFERRED', $budget->reserve($request)['status']);
        }
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [$base . ':minute']);
        $grants = [];

        // Act:
        for ($round = 0; count($grants) < 8 && $round < 20; $round++) {
            foreach ($requests as $request) {
                $result = $budget->reserve($request);
                if ($result['status'] === 'GRANTED') {
                    $grants[] = [$request['task']['lane'], $request['task']['stage']];
                    $budget->finish($result['attempt_id'], []);
                    if (count($grants) === 8) {
                        break;
                    }
                }
            }
        }

        // Assert:
        self::assertSame(['priority', 'priority', 'priority', 'batch', 'priority', 'priority', 'priority', 'batch'], array_column($grants, 0));
        $priority = array_values(array_filter($grants, static fn(array $grant): bool => $grant[0] === 'priority'));
        $batch = array_values(array_filter($grants, static fn(array $grant): bool => $grant[0] === 'batch'));
        self::assertSame(['extraction', 'semantic', 'extraction', 'semantic', 'extraction', 'semantic'], array_column($priority, 1));
        self::assertSame(['extraction', 'semantic'], array_column($batch, 1));
    }

    private function budget(int $rpm, int $tpm, int $rpd, int $inflight): GeminiQuotaBudget
    {
        return new GeminiQuotaBudget($this->redis, ['scope' => 'race', 'model' => 'fixture',
            'rpm' => $rpm, 'tpm' => $tpm, 'rpd' => $rpd, 'inflight' => $inflight]);
    }
}
