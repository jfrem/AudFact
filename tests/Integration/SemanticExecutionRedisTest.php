<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiGateway;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditLane;
use App\Services\Audit\Pipeline\AuditPersistenceQueue;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AuditTask;
use App\Services\Audit\Pipeline\AuditTaskCommitter;
use App\Services\Audit\Pipeline\AuditTaskOutbox;
use App\Services\Audit\Pipeline\AuditTaskStage;
use App\Services\Audit\Pipeline\DocumentPolicyEngine;
use App\Services\Audit\Pipeline\FairAuditTaskQueue;
use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use App\Services\Audit\Pipeline\RulesEvaluationWorker;
use App\Services\Audit\Pipeline\SemanticEvaluationWorker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tests\Support\IsolatedAuditRedis;

final class SemanticExecutionRedisTest extends TestCase
{
    public function testPolicyPlansWithoutHttpAndResumesOnlyAfterFencedSemanticResult(): void
    {
        // Arrange:
        if (getenv('RUN_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Requiere Redis aislado; Gemini usa transporte simulado.');
        }
        $redis = new IsolatedAuditRedis('integration:audit:' . AuditEvent::uuidV4() . ':');
        try {
            $queue = new FairAuditTaskQueue($redis, new AuditExecutionConfig(2));
            $committer = new AuditTaskCommitter($redis);
            $outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
            $event = AuditEvent::create(AuditEvent::TYPE_DOCUMENT_NORMALIZED, AuditEvent::uuidV4(), documentId: 'existing', payload: [
                'tipo_documento' => 'DISPENSA', 'document_quality' => 'legible',
                'fields_normalized' => ['NombrePaciente' => ['valor' => 'SUJETO BETA', 'presente' => true, 'estadoExtraccion' => 'FOUND']],
            ]);
            $document = ['status' => 'normalized', 'doc_id' => 'fixture', 'attachment_id' => 'fixture',
                'tipo_documento' => 'DISPENSA', 'fields_config' => [[
                    'campoNombre' => 'NombrePaciente', 'tipoCampo' => 'S', 'tipoDato' => 'person_name', 'severity' => 'alta',
                ]], 'fuente_verdad' => ['header' => ['NombrePaciente' => 'PERSONA ALFA'], 'items' => []]];
            $root = ['audit_id' => $event->auditId, 'status' => 'processing', 'execution_version' => 2,
                'docs_total' => 1, 'docs_done' => 1, 'docs_rejected' => 0, 'docs_evaluated' => 0,
                'documents' => ['existing' => $document], 'dis_det_nro' => 'synthetic', 'created_at' => $event->timestamp];
            $redis->eval('redis.call("SET",KEYS[1],"2"); return redis.call("SET",KEYS[2],ARGV[1])',
                ['audit:execution:version', AuditStateStore::auditKey($event->auditId)], [json_encode($root)]);
            $history = [];
            $mock = new MockHandler([
                new Response(200, [], '{"totalTokens":100}'),
                static function (RequestInterface $request): Response {
                    $body = json_decode((string) $request->getBody(), true);
                    $pairs = json_decode($body['contents'][0]['parts'][0]['text'], true)['pairs'];
                    $results = array_map(static fn(array $pair): array => ['pair_id' => $pair['pair_id'],
                        'is_match' => false, 'reasoning' => 'Synthetic fixture'], $pairs);
                    return new Response(200, [], json_encode(['candidates' => [['finishReason' => 'STOP',
                        'content' => ['parts' => [['text' => json_encode(['results' => $results])]]]]],
                        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'totalTokenCount' => 120]]));
                },
            ]);
            $stack = HandlerStack::create($mock);
            $stack->push(Middleware::history($history));
            $gateway = new GeminiGateway(new Client(['handler' => $stack]), 'synthetic-key', new GeminiConfig(model: 'gemini-3.7-flash'), cbRedis: $redis);
            $quota = new GeminiQuotaBudget($redis, ['scope' => 'synthetic', 'model' => 'gemini-3.7-flash', 'rpm' => 100,
                'tpm' => 10000, 'rpd' => 1000, 'inflight' => 4]);
            $policy = new RulesEvaluationWorker(redis: $redis, policyEngine: new DocumentPolicyEngine());
            $semantic = new SemanticEvaluationWorker($redis, $gateway, quota: $quota);

            // Act:
            $this->admit($redis, $queue, AuditTaskStage::POLICY, $event);
            $first = $queue->claim(AuditTaskStage::POLICY, AuditLane::BATCH, 'policy');
            $effects = $policy->prepareTask($first);
            $httpAfterPlanning = count($history);
            $committer->commit($first->record, $effects);
            $outbox->flush();
            $scheduled = $this->lastEvent($redis, AuditEventPublisher::STREAM_SEMANTIC_BATCH);
            $this->admit($redis, $queue, AuditTaskStage::SEMANTIC, $scheduled);
            $task = $queue->claim(AuditTaskStage::SEMANTIC, AuditLane::BATCH, 'semantic');
            $semanticEffects = $semantic->prepareTask($task);
            $committer->commit($task->record, $semanticEffects);
            $semantic->afterTaskCommitted($task);
            $outbox->flush();
            $resolved = $this->lastEvent($redis, AuditEventPublisher::STREAM_DOCUMENTS_BATCH);
            $this->admit($redis, $queue, AuditTaskStage::POLICY, $resolved);
            $resume = $queue->claim(AuditTaskStage::POLICY, AuditLane::BATCH, 'policy');
            $final = $policy->prepareTask($resume);
            $committer->commit($resume->record, $final);
            $duplicate = $committer->commit($resume->record, $final);
            $audit = (new AuditStateStore($redis))->getAudit($event->auditId);

            // Assert:
            self::assertSame(0, $httpAfterPlanning);
            self::assertCount(2, $history);
            self::assertStringEndsWith(':countTokens', (string) $history[0]['request']->getUri());
            self::assertStringEndsWith(':generateContent', (string) $history[1]['request']->getUri());
            self::assertSame(8192, json_decode((string) $history[1]['request']->getBody(), true)['generationConfig']['maxOutputTokens']);
            self::assertSame(1, $audit['docs_evaluated']);
            self::assertSame('evaluated', $audit['documents']['existing']['status']);
            self::assertSame('ALREADY_COMMITTED', $duplicate);
            self::assertCount(1, $final['outbox']);
            self::assertSame('persistence_queue', $final['outbox'][0]['destination']);
            self::assertSame(1, $audit['documents']['existing']['gemini_semantic_metrics']['semantic_calls']);
            self::assertSame(2, $quota->metrics()['rpm_used']);
        } finally {
            $redis->cleanup();
        }
    }

    private function lastEvent(IsolatedAuditRedis $redis, string $stream): AuditEvent
    {
        $entries = $redis->eval('return redis.call("XREVRANGE",KEYS[1],"+","-","COUNT",1)', [$stream]);
        return AuditEvent::fromArray(json_decode($entries[0][1][1], true));
    }

    private function admit(IsolatedAuditRedis $redis, FairAuditTaskQueue $queue, AuditTaskStage $stage, AuditEvent $event): void
    {
        $stream = AuditEventPublisher::streamForEvent($event);
        $id = $redis->eval('redis.pcall("XGROUP","CREATE",KEYS[1],"test","$","MKSTREAM"); local id=redis.call("XADD",KEYS[1],"*","event",ARGV[1]); redis.call("XREADGROUP","GROUP","test","test","COUNT",1,"STREAMS",KEYS[1],">"); return id', [$stream], [$event->toJson()]);
        self::assertTrue($queue->admit(AuditTask::fromEvent($stage, $event), $stream, 'test', $id));
    }
}
