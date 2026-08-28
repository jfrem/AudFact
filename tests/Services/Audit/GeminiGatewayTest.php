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
}
