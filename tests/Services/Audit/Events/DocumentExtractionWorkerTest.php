<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AttachmentDownloadServiceInterface;
use App\Services\Audit\Pipeline\DocumentExtractionWorker;
use App\Services\Audit\GeminiGateway;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentExtractionWorkerTest extends TestCase
{
    public function testCacheHitSkipsGeminiAndPublishesDocumentExtracted(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = base64_encode('pdf-data');
        $hash = hash('sha256', $base64);
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();
        
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->with($this->stringContains('extraction:'))
            ->willReturn(json_encode([
                'fields' => ['NumeroFactura' => 'T38250701547'],
                'items' => [],
                'visual_checks' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
            ]));
            
        $gateway = new StubGeminiGateway([]);
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => $base64]),
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        $this->assertSame(0, $gateway->calls);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_EXTRACTED, $publisher->published[0]->eventType);
        $this->assertTrue($publisher->published[0]->payload['cache_hit']);
        $this->assertArrayNotHasKey('gemini_metrics', $publisher->published[0]->payload);
        $this->assertSame($hash, $publisher->published[0]->payload['document_hash']);
        $this->assertSame('extracted', $store->lastPatch['status'] ?? null);
        $this->assertNull($store->forcedResult);
    }

    public function testCacheMissCallsGeminiStoresCacheAndPublishesResult(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = base64_encode('pdf-data');
        $hash = hash('sha256', $base64);
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();
        
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        $redisMock->expects($this->once())->method('set')->willReturn(true);
        
        $gateway = new StubGeminiGateway([
            'X-Audit-Metrics' => [
                'task_type' => 'extraction',
                'document_type' => 'FORMULA MEDICA',
                'duration_ms' => 123,
                'cache_hit' => false,
            ],
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'extract_document_data',
                            'args' => [
                                'fields' => ['NumeroFactura' => 'T38250701547'],
                                'items' => [],
                                'visual_checks' => [
                                    ['check' => 'FirmaActaEntrega', 'presente' => true],
                                ],
                                'document_quality' => 'legible',
                                'quality_notes' => ['ok'],
                            ],
                        ],
                    ]],
                ],
            ]],
        ]);
        
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => $base64]),
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        $this->assertSame(1, $gateway->calls);
        $this->assertSame('extract_document_data', $gateway->lastTools[0]['functionDeclarations'][0]['name'] ?? null);
        $this->assertSame(['extract_document_data'], $gateway->lastToolConfig['functionCallingConfig']['allowedFunctionNames'] ?? null);
        $this->assertSame('extraction', $gateway->lastDebugContext['task_type'] ?? null);
        $toolJson = json_encode($gateway->lastTools, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('additionalProperties', $toolJson);
        $this->assertStringNotContainsString('"default"', $toolJson);
        $this->assertFalse($publisher->published[0]->payload['cache_hit']);
        $this->assertSame('extraction', $publisher->published[0]->payload['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['extraction_result']['fields']['NumeroFactura']);
    }

    public function testDoesNotPublishWhenStateStoreReturnsFalse(): void
    {
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore(false);
        
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(json_encode([
            'fields' => ['NumeroFactura' => 'T38250701547'],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ]));
        
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            gateway: new StubGeminiGateway([]),
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo persistir la extracción');

        try {
            $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testInvalidGeminiResponseThrowsRuntimeException(): void
    {
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            gateway: new StubGeminiGateway(['candidates' => []]),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    private function documentRegisteredEvent(
        string $auditId,
        string $documentId,
        array $payloadOverrides = []
    ): AuditEvent {
        $payload = [
            'document_id' => $documentId,
            'attachment_id' => 'att-1',
            'dis_det_nro' => 'T38250701547',
            'tipo_documento' => 'FORMULA MEDICA',
            'contexto' => [
                'diagnosticos' => ['E119'],
                'items' => [],
            ],
            'extraction_schema' => [
                'name' => 'extract_document_data',
                'description' => 'Extract data from document',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fields' => ['type' => 'object'],
                        'items' => ['type' => 'array'],
                        'visual_checks' => ['type' => 'array'],
                    ],
                ]
            ],
            'attempt' => 1,
        ];

        return AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: $auditId,
            jobId: AuditEvent::uuidV4(),
            payload: array_replace_recursive($payload, $payloadOverrides),
            parentEventId: null,
            documentId: $documentId
        );
    }
}

final class StubDownloadService implements AttachmentDownloadServiceInterface
{
    public function __construct(private array $document)
    {
    }

    public function download(string $attachmentId, string $disDetNro): array
    {
        return array_merge($this->document, ['duration_ms' => 0]);
    }
}

final class StubGeminiGateway extends GeminiGateway
{
    public int $calls = 0;
    public array $lastTools = [];
    public array $lastToolConfig = [];
    public array $lastDebugContext = [];

    public function __construct(private array $response)
    {
    }

    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->calls++;
        $this->lastTools = $tools;
        $this->lastToolConfig = $toolConfig;
        $this->lastDebugContext = $debugContext ?? [];
        return $this->response;
    }
}

final class ExtractionRecordingStateStore extends AuditStateStore
{
    public array $lastPatch = [];
    public ?bool $forcedResult = null;

    public function __construct(?bool $forcedResult = null)
    {
        $this->forcedResult = $forcedResult;
    }

    public function markDocumentExtracted(string $auditId, string $documentId, array $extractionState): bool
    {
        $this->lastPatch = $extractionState;
        return $this->forcedResult ?? true;
    }
}

final class ExtractionPublisher extends AuditEventPublisher
{
    public array $published = [];
    public array $deadLetters = [];

    public function __construct()
    {
    }

    public function publish(AuditEvent $event): string
    {
        $this->published[] = $event;
        return 'stream-' . count($this->published);
    }

    public function publishDeadLetter(AuditEvent $event): string
    {
        $this->deadLetters[] = $event;
        return 'dlq-' . count($this->deadLetters);
    }
}
