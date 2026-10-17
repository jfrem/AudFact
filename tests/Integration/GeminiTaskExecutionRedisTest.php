<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventConsumer;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskEffects;
use App\Services\Audit\Pipeline\AuditTaskOutbox;
use App\Services\Audit\Pipeline\AuditTaskRunner;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedAuditRedis;

final class GeminiTaskExecutionRedisTest extends TestCase
{
    private IsolatedAuditRedis $redis;
    private FairAuditTaskQueue $queue;
    private GeminiQuotaBudget $quota;
    private AuditEvent $event;
    private array $history = [];

    protected function setUp(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis real; el transporte Gemini está simulado.');
        }
        $this->redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        $this->queue = new FairAuditTaskQueue($this->redis, new AuditExecutionConfig(2));
        $this->quota = new GeminiQuotaBudget($this->redis, ['scope' => 'fixture', 'model' => 'gemini-3.7-flash',
            'rpm' => 100, 'tpm' => 10000, 'rpd' => 1000, 'inflight' => 4]);
        $this->event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_DOWNLOADED, AuditEvent::uuidV4(), documentId: 'existing');
        $root = ['audit_id' => $this->event->auditId, 'status' => 'processing', 'execution_version' => 2,
            'documents' => [], 'docs_total' => 0];
        $stream = AuditEventPublisher::streamForEvent($this->event);
        $id = $this->redis->eval('redis.call("SET",KEYS[1],"2"); redis.call("SET",KEYS[2],ARGV[1]); redis.call("XGROUP","CREATE",KEYS[3],"test","0","MKSTREAM"); local id=redis.call("XADD",KEYS[3],"*","event",ARGV[2]); redis.call("XREADGROUP","GROUP","test","intake","STREAMS",KEYS[3],">"); return id',
            ['audit:execution:version', AuditStateStore::auditKey($this->event->auditId), $stream],
            [json_encode($root), $this->event->toJson()]);
        $this->queue->admit(AuditTask::fromEvent(AuditTaskStage::EXTRACTION, $this->event), $stream, 'test', $id);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->cleanup();
        }
    }

    public function testRetryAfterDefersAndReusesCountTokensWithoutInternalRetry(): void
    {
        // Arrange:
        $worker = $this->worker([new Response(200, [], '{"totalTokens":100}'),
            new Response(429, ['Retry-After' => '7'], '{"error":{"message":"synthetic"}}'),
            new Response(200, [], '{"usageMetadata":{"promptTokenCount":100},"value":"synthetic"}')]);
        $runner = new AuditTaskRunner($this->redis, new AuditExecutionConfig(2));
        $before = $this->queue->nowMs();

        // Act:
        $runner->tick($worker, AuditLane::BATCH, 'worker');
        $delayed = $this->task();
        $httpAfterFailure = count($this->history);
        $this->makeDue($delayed);
        $runner->tick($worker, AuditLane::BATCH, 'worker');

        // Assert:
        self::assertSame('delayed', $delayed->record['state']);
        self::assertGreaterThanOrEqual($before + 7000, $delayed->record['due_at_ms']);
        self::assertSame('generate_content', $delayed->record['operation']);
        self::assertSame(1, $delayed->record['operation_attempt']);
        self::assertSame(2, $httpAfterFailure);
        self::assertCount(3, $this->history);
        self::assertStringEndsWith(':generateContent', (string) $this->history[2]['request']->getUri());
        self::assertSame('completed', $this->task()->record['state']);
        self::assertSame('synthetic', (new AuditStateStore($this->redis))->getAudit($this->event->auditId)['fixture_result']);
        self::assertSame(3, $this->quota->metrics()['rpm_used']);
        $attempts = (new AuditStateStore($this->redis))->getAudit($this->event->auditId)['gemini_attempts'];
        $generations = array_values(array_filter($attempts, static fn(array $row): bool => $row['operation'] === 'generate_content'));
        self::assertCount(3, $attempts);
        self::assertSame($generations[0]['request_id'], $generations[1]['request_id']);
        self::assertNotSame($generations[0]['attempt_id'], $generations[1]['attempt_id']);
        $states = array_column($generations, 'state');
        sort($states);
        self::assertSame(['completed', 'failed'], $states);
    }

    public function testRetryExhaustionCreatesOneDurableTechnicalFailureAndDlq(): void
    {
        // Arrange:
        $worker = $this->worker([new Response(200, [], '{"totalTokens":100}'),
            new Response(503), new Response(503), new Response(503)]);
        $runner = new AuditTaskRunner($this->redis, new AuditExecutionConfig(2));
        $outbox = new AuditTaskOutbox($this->redis, new AuditPersistenceQueue($this->redis));

        // Act:
        for ($i = 0; $i < 3; $i++) {
            $runner->tick($worker, AuditLane::BATCH, 'worker');
            if ($this->task()->record['state'] === 'delayed') {
                $this->makeDue($this->task());
            }
        }
        $outbox->flush();
        $outbox->flush();
        $root = (new AuditStateStore($this->redis))->getAudit($this->event->auditId);

        // Assert:
        self::assertCount(4, $this->history);
        self::assertSame('failed', $root['status']);
        self::assertTrue($root['requires_manual_review']);
        self::assertSame('audit_task_failed', $root['detail_error']);
        self::assertSame('completed', $this->task()->record['state']);
        self::assertSame(1, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::dlqStream()]));
        self::assertSame(1, $this->redis->eval('return redis.call("XLEN",KEYS[1])', [AuditEventPublisher::STREAM_RESULTS_BATCH]));
        self::assertSame('1', $this->redis->eval('return redis.call("HGET",KEYS[1],"terminal_failures")', ['telemetry:async_metrics']));
        self::assertSame(3, $this->quota->metrics()['extraction_request_failures']);
        self::assertCount(4, $root['gemini_attempts']);
        self::assertSame(3, count(array_filter($root['gemini_attempts'], static fn(array $row): bool => $row['state'] === 'failed')));
    }

    public function testResponseSurvivesQuotaFinishFailureWithoutRepeatingHttp(): void
    {
        // Arrange:
        $metrics = $this->quotaBase() . ':metrics';
        $worker = $this->worker([new Response(200, [], '{"totalTokens":100}'), function () use ($metrics): Response {
            $this->redis->eval('redis.call("DEL",KEYS[1]); return redis.call("SET",KEYS[1],"wrong-type")', [$metrics]);
            return new Response(200, [], '{"usageMetadata":{"promptTokenCount":100},"value":"synthetic"}');
        }]);
        $runner = new AuditTaskRunner($this->redis, new AuditExecutionConfig(2));

        // Act:
        $runner->tick($worker, AuditLane::BATCH, 'worker');
        $saved = $this->task();
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [$metrics]);
        $this->makeDue($saved);
        $runner->tick($worker, AuditLane::BATCH, 'worker');

        // Assert:
        self::assertCount(1, $saved->record['stage_artifact']['responses']);
        self::assertCount(2, $this->history);
        self::assertSame('completed', $this->task()->record['state']);
        self::assertSame(0, $this->quota->metrics()['inflight']);
        self::assertSame(2, $this->quota->metrics()['rpd_used']);
    }

    public function testExpiredAttemptStaysChargedAndKnownLateResponseResolvesUnknown(): void
    {
        // Arrange:
        $task = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'crashed');
        $grant = $this->quota->reserve(['task' => $task->record, 'operation' => 'generate_content',
            'tokens' => 100, 'body_hash' => hash('sha256', 'synthetic')]);
        $this->redis->eval('return redis.call("ZADD",KEYS[1],0,ARGV[1])', [$this->quotaBase() . ':inflight'], [$grant['attempt_id']]);

        // Act:
        $this->quota->recoverExpired();
        $uncertain = $this->quota->metrics();
        $this->quota->recoverExpired();
        $receipt = json_decode($this->redis->get('gemini:attempt:v2:' . $grant['attempt_id']), true);
        $this->quota->finish($grant['attempt_id'], ['promptTokenCount' => 120]);
        $resolved = $this->quota->metrics();

        // Assert:
        self::assertSame('unknown', $receipt['state']);
        self::assertSame($task->record['audit_id'], $receipt['audit_id']);
        self::assertSame(1, $uncertain['unknown_attempts']);
        self::assertSame(1, $uncertain['rpm_used']);
        self::assertSame(100, $uncertain['tpm_reserved']);
        self::assertSame(1, $uncertain['rpd_used']);
        self::assertSame(0, $resolved['unknown_attempts']);
        self::assertSame(120, $resolved['tpm_reserved']);
        self::assertSame(1, $resolved['unknown_attempts_total']);
    }

    public function testContractFailureDoesNotPartiallyChangeReceiptWhenMetricsAreInvalid(): void
    {
        // Arrange:
        $task = $this->queue->claim(AuditTaskStage::EXTRACTION, AuditLane::BATCH, 'worker');
        $grant = $this->quota->reserve(['task' => $task->record, 'operation' => 'generate_content',
            'tokens' => 100, 'body_hash' => hash('sha256', 'synthetic')]);
        $this->quota->finish($grant['attempt_id'], ['promptTokenCount' => 100]);
        $this->redis->eval('redis.call("DEL",KEYS[1]); return redis.call("SET",KEYS[1],"invalid")', [$this->quotaBase() . ':metrics']);
        $caught = null;

        // Act:
        try {
            $this->quota->failContract($grant['attempt_id']);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $unchanged = json_decode($this->redis->get('gemini:attempt:v2:' . $grant['attempt_id']), true);
        $this->redis->eval('return redis.call("DEL",KEYS[1])', [$this->quotaBase() . ':metrics']);
        $this->quota->failContract($grant['attempt_id']);
        $this->quota->failContract($grant['attempt_id']);
        $final = json_decode($this->redis->get('gemini:attempt:v2:' . $grant['attempt_id']), true);

        // Assert:
        self::assertNotNull($caught);
        self::assertSame('completed', $unchanged['state']);
        self::assertSame('failed', $final['state']);
        self::assertTrue($final['contract_invalid']);
        self::assertSame(1, $this->quota->metrics()['extraction_request_failures']);
        self::assertSame(1, $this->quota->metrics()['rpd_used']);
    }

    private function worker(array $responses): ScheduledGeminiFixtureWorker
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $gateway = new GeminiGateway(new Client(['handler' => $stack]), 'synthetic-key',
            new GeminiConfig(model: 'gemini-3.7-flash'), cbRedis: $this->redis);
        return new ScheduledGeminiFixtureWorker($this->redis, $gateway, $this->quota, $this->queue);
    }

    private function task(): AuditTask
    {
        return $this->queue->get(AuditTask::fromEvent(AuditTaskStage::EXTRACTION, $this->event)->id());
    }

    private function makeDue(AuditTask $task): void
    {
        $this->redis->eval('local r=cjson.decode(redis.call("HGET",KEYS[1],"record")); r.due_at_ms=0; redis.call("HSET",KEYS[1],"record",cjson.encode(r)); return redis.call("ZADD",KEYS[2],0,r.task_id)',
            [FairAuditTaskQueue::taskKey($task->id()), FairAuditTaskQueue::base('extraction', 'batch') . ':delayed']);
    }

    private function quotaBase(): string
    {
        return 'gemini:quota:v2:' . hash('sha256', 'fixture') . ':' . hash('sha256', 'gemini-3.7-flash');
    }
}

final class ScheduledGeminiFixtureWorker extends AuditEventConsumer
{
    public function __construct(IsolatedAuditRedis $redis, private readonly GeminiGateway $gateway,
        private readonly GeminiQuotaBudget $quota, private readonly FairAuditTaskQueue $queue)
    {
        parent::__construct(redis: $redis);
    }

    protected function streams(): array { return [AuditEventPublisher::STREAM_DOCUMENTS_BATCH]; }
    protected function group(): string { return 'test'; }
    protected function consumer(): string { return 'test'; }
    protected function eventTypes(): array { return [AuditEvent::TYPE_DOCUMENT_DOWNLOADED]; }
    protected function handle(AuditEvent $event): void { throw new \LogicException('scheduled_only'); }
    public function taskStage(): ?AuditTaskStage { return AuditTaskStage::EXTRACTION; }

    public function prepareTask(AuditTask $task): array
    {
        $response = $this->gateway->withTask($task, $this->quota, $this->queue)->executeTaskRequest(
            ['contents' => [['parts' => [['text' => 'Synthetic fixture']]]]], ['task_type' => GeminiGateway::TASK_EXTRACTION]);
        $effects = AuditTaskEffects::empty($task);
        $effects['state_transitions'][] = AuditTaskEffects::transition($task, 'patch_audit_context', null,
            ['fixture_result' => $response['value']]);
        return $effects;
    }
}
