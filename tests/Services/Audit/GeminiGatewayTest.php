<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\GeminiConfig;
use App\Services\Audit\GeminiGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeminiGatewayTest extends TestCase
{
    public function testSendWithStructuredOutputPayloadAndHeaders(): void
    {
        $container = [];
        $history = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => [
                        'parts' => [['text' => '{"fields":{"NombrePaciente":"JUAN"}}']],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 100,
                    'candidatesTokenCount' => 50,
                    'totalTokenCount' => 150,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $gateway = new GeminiGateway(
            $client,
            'secret-test-api-key',
            new GeminiConfig(model: 'gemini-3.7-flash', mediaResolution: 'MEDIA_RESOLUTION_MEDIUM')
        );

        $schema = [
            'type' => 'object',
            'properties' => [
                'fields' => [
                    'type' => 'object',
                    'properties' => [
                        'NombrePaciente' => ['type' => 'string', 'nullable' => true],
                    ],
                    'required' => ['NombrePaciente'],
                ],
            ],
            'required' => ['fields'],
        ];

        $response = $gateway->sendWithStructuredOutput(
            prompt: 'Extrae los datos',
            files: [['mime' => 'image/png', 'data' => base64_encode('fake-png'), 'label' => 'DOC1']],
            systemInstruction: 'Eres un extractor',
            responseSchema: $schema,
            taskType: GeminiGateway::TASK_EXTRACTION
        );

        $this->assertArrayHasKey('candidates', $response);
        $this->assertArrayHasKey('X-Audit-Metrics', $response);
        $this->assertSame(1, count($container));

        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent', (string) $request->getUri());
        $this->assertSame('secret-test-api-key', $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('toolConfig', $body);
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame($schema, $body['generationConfig']['responseSchema']);
        $this->assertSame('MEDIA_RESOLUTION_MEDIUM', $body['generationConfig']['mediaResolution']);
    }

    public function testSendWithStructuredOutputHttp400ThrowsRuntimeException(): void
    {
        $mock = new MockHandler([
            new Response(400, ['content-type' => 'application/json'], (string) json_encode([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid generationConfig.responseSchema property',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $gateway = new GeminiGateway(
            $client,
            'secret-test-api-key',
            new GeminiConfig(model: 'gemini-3.7-flash')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid generationConfig.responseSchema property');

        $gateway->sendWithStructuredOutput(
            prompt: 'Extrae',
            files: [['mime' => 'application/pdf', 'data' => base64_encode('fake-pdf'), 'label' => 'DOC']],
            systemInstruction: 'Sys',
            responseSchema: ['type' => 'object'],
            taskType: GeminiGateway::TASK_EXTRACTION
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Core\Env::clearCache();
    }

    protected function tearDown(): void
    {
        \Core\Env::clearCache();
        parent::tearDown();
    }

    public function testCircuitBreakerKeySegregationByLane(): void
    {
        \Core\Env::set('CB_GEMINI_THRESHOLD', '1');

        try {
            $redisState = [];
            $redis = $this->createMock(\Core\RedisClient::class);
            $redis->method('isAvailable')->willReturn(true);
            $redis->method('get')->willReturnCallback(function (string $key) use (&$redisState) {
                return $redisState[$key] ?? null;
            });
            $redis->method('set')->willReturnCallback(function (string $key, mixed $val) use (&$redisState) {
                $redisState[$key] = $val;
                return true;
            });
            $redis->method('incr')->willReturnCallback(function (string $key) use (&$redisState) {
                $redisState[$key] = ($redisState[$key] ?? 0) + 1;
                return $redisState[$key];
            });
            $redis->method('ttl')->willReturn(60);

            // Mock Guzzle que falla con 403 (no retryable para evitar usleeps en tests)
            $mockFailures = new MockHandler([
                new Response(403, [], 'Forbidden'),
            ]);
            $clientFailures = new Client(['handler' => HandlerStack::create($mockFailures)]);

            $batchGateway = new GeminiGateway(
                $clientFailures,
                'batch-key',
                new GeminiConfig(model: 'gemini-3.7-flash'),
                cbRedis: $redis,
                lane: 'batch'
            );

            $this->assertSame('batch', $batchGateway->getLane());

            // Intentar llamada que fallará inmediatamente y abrirá el CB con threshold=1
            try {
                $batchGateway->sendWithStructuredOutput(
                    prompt: 'test',
                    files: [['mime' => 'application/pdf', 'data' => base64_encode('pdf'), 'label' => 'D']],
                    systemInstruction: 'sys',
                    responseSchema: ['type' => 'object'],
                    taskType: GeminiGateway::TASK_EXTRACTION
                );
            } catch (\Throwable) {
                // Se esperaba el fallo
            }

            // El Circuit Breaker de BATCH debe estar abierto
            $this->assertSame('open', $redisState['cb:gemini:batch:state'] ?? null);
            // El Circuit Breaker de PRIORITY NO debe estar abierto
            $this->assertArrayNotHasKey('cb:gemini:priority:state', $redisState);

            // Ahora intentamos con una llamada VIP (priority) con un mock exitoso
            $mockSuccess = new MockHandler([
                new Response(200, ['content-type' => 'application/json'], (string) json_encode([
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => ['parts' => [['text' => '{"fields":{}}']]],
                    ]],
                ])),
            ]);
            $priorityGateway = new GeminiGateway(
                new Client(['handler' => HandlerStack::create($mockSuccess)]),
                'priority-key',
                new GeminiConfig(model: 'gemini-3.7-flash'),
                cbRedis: $redis,
                lane: 'priority'
            );

            $this->assertSame('priority', $priorityGateway->getLane());

            // La llamada de priority NO es bloqueada por el CB de batch y se completa con éxito
            $response = $priorityGateway->sendWithStructuredOutput(
                prompt: 'test priority',
                files: [['mime' => 'application/pdf', 'data' => base64_encode('pdf'), 'label' => 'D']],
                systemInstruction: 'sys',
                responseSchema: ['type' => 'object'],
                taskType: GeminiGateway::TASK_EXTRACTION
            );

            $this->assertArrayHasKey('candidates', $response);

            // Por el contrario, una nueva llamada con el batchGateway debe fallar INMEDIATAMENTE por CB abierto (503)
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(503);
            $this->expectExceptionMessageMatches('/Circuit Breaker abierto \[carril: batch\]/');

            $batchGateway->sendWithStructuredOutput(
                prompt: 'test after cb open',
                files: [['mime' => 'application/pdf', 'data' => base64_encode('pdf'), 'label' => 'D']],
                systemInstruction: 'sys',
                responseSchema: ['type' => 'object'],
                taskType: GeminiGateway::TASK_EXTRACTION
            );
        } finally {
            \Core\Env::set('CB_GEMINI_THRESHOLD', null);
        }
    }

    public function testFactoryResolvesDedicatedApiKeyAndLane(): void
    {
        \Core\Env::set('GEMINI_API_KEY', 'key-common');
        \Core\Env::set('GEMINI_API_KEY_PRIORITY', 'key-vip');
        \Core\Env::set('GEMINI_API_KEY_BATCH', 'key-batch');

        try {
            $priority = GeminiGateway::create('priority');
            $this->assertSame('priority', $priority->getLane());

            $batch = GeminiGateway::create('batch');
            $this->assertSame('batch', $batch->getLane());

            $all = GeminiGateway::create('all');
            $this->assertSame('all', $all->getLane());
        } finally {
            \Core\Env::set('GEMINI_API_KEY', null);
            \Core\Env::set('GEMINI_API_KEY_PRIORITY', null);
            \Core\Env::set('GEMINI_API_KEY_BATCH', null);
        }
    }
}
