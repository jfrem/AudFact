<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentExtractionWorker;
use App\Services\Audit\Pipeline\ExtractionCache;
use App\Services\Audit\Pipeline\InternalAuditApiClient;
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
        $cache = new StubExtractionCache([
            $hash => [
                'fields' => ['NumeroFactura' => 'T38250701547'],
                'items' => [],
                'visual_checks' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
            ],
        ]);
        $gateway = new StubGeminiGateway([]);
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => $base64]),
            cache: $cache,
            gateway: $gateway,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        $this->assertSame(0, $gateway->calls);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_EXTRACTED, $publisher->published[0]->eventType);
        $this->assertTrue($publisher->published[0]->payload['cache_hit']);
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
        $cache = new StubExtractionCache();
        $gateway = new StubGeminiGateway([
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
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => $base64]),
            cache: $cache,
            gateway: $gateway,
            redis: $this->createMock(RedisClient::class),
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId));

        $this->assertSame(1, $gateway->calls);
        $this->assertSame('extract_document_data', $gateway->lastTools[0]['functionDeclarations'][0]['name'] ?? null);
        $this->assertSame(['extract_document_data'], $gateway->lastToolConfig['functionCallingConfig']['allowedFunctionNames'] ?? null);
        $toolJson = json_encode($gateway->lastTools, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('additionalProperties', $toolJson);
        $this->assertStringNotContainsString('"default"', $toolJson);
        $this->assertArrayHasKey($hash, $cache->stored);
        $this->assertFalse($publisher->published[0]->payload['cache_hit']);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['extraction_result']['fields']['NumeroFactura']);
    }

    public function testDoesNotPublishWhenStateStoreReturnsFalse(): void
    {
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore(false);
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache([
                hash('sha256', base64_encode('pdf-data')) => [
                    'fields' => ['NumeroFactura' => 'T38250701547'],
                    'items' => [],
                    'visual_checks' => [],
                    'document_quality' => 'legible',
                    'quality_notes' => [],
                ],
            ]),
            gateway: new StubGeminiGateway([]),
            redis: $this->createMock(RedisClient::class),
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
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache(),
            gateway: new StubGeminiGateway(['candidates' => []]),
            redis: $this->createMock(RedisClient::class),
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testInvalidDocumentQualityThrowsRuntimeException(): void
    {
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache(),
            gateway: new StubGeminiGateway([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'extract_document_data',
                                'args' => [
                                    'fields' => [],
                                    'items' => [],
                                    'visual_checks' => [],
                                    'document_quality' => 'borroso',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('document_quality inválido');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testMalformedVisualChecksThrowsRuntimeException(): void
    {
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache(),
            gateway: new StubGeminiGateway([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'extract_document_data',
                                'args' => [
                                    'fields' => [],
                                    'items' => [],
                                    'visual_checks' => [
                                        ['check' => 'FirmaActaEntrega'],
                                    ],
                                    'document_quality' => 'legible',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('visual_check sin presente booleano');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testDispensaWithMultipleFdvItemsRequiresSegmentedItems(): void
    {
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache(),
            gateway: new StubGeminiGateway([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'extract_document_data',
                                'args' => [
                                    'fields' => ['CantidadEntregada' => '20'],
                                    'items' => [
                                        ['CantidadEntregada' => '20'],
                                    ],
                                    'visual_checks' => [],
                                    'document_quality' => 'legible',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]),
            redis: $this->createMock(RedisClient::class),
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no segmentó todos los items visibles');

        $worker->processEvent($this->documentRegisteredEvent(
            AuditEvent::uuidV4(),
            AuditEvent::uuidV4(),
            [
                'fuente_verdad' => [
                    'header' => [],
                    'items' => [
                        ['CantidadEntregada' => '20'],
                        ['CantidadEntregada' => '30'],
                    ],
                ],
            ]
        ));
    }

    public function testRunSendsDeadLetterAfterRetryLimit(): void
    {
        putenv('AUDIT_EVENT_MAX_RETRIES=1');

        $auditId = AuditEvent::uuidV4();
        $documentId = AuditEvent::uuidV4();
        $event = $this->documentRegisteredEvent($auditId, $documentId);
        $publisher = new ExtractionPublisher();
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('xGroupCreate')->willReturn(true);
        $redis->method('xReadGroup')->willReturn([[
            'id' => '1700000000000-0',
            'fields' => ['event' => $event->toJson()],
        ]]);
        $redis->expects($this->once())->method('incr')->willReturn(1);
        $redis->expects($this->once())->method('xAck')->with('audit.documents', 'extractors', '1700000000000-0');
        $redis->expects($this->once())->method('del');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            apiClient: new StubDownloadApiClient(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            cache: new StubExtractionCache(),
            gateway: new StubGeminiGateway(['candidates' => []]),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $processed = $worker->run(1);

        $this->assertSame(1, $processed);
        $this->assertCount(1, $publisher->deadLetters);
        $this->assertSame(AuditEvent::TYPE_DEAD_LETTER, $publisher->deadLetters[0]->eventType);

        putenv('AUDIT_EVENT_MAX_RETRIES');
    }

    private function documentRegisteredEvent(string $auditId, string $documentId, array $payloadOverrides = []): AuditEvent
    {
        $payload = [
            'tipo_documento' => 'DISPENSA',
            'download_url' => '/dispensation/T38250701547/attachments/download/1',
            'visual_checks' => [
                [
                    'check' => 'FirmaActaEntrega',
                    'description' => 'Firma de recibido',
                    'severity' => 'CRITICO',
                ],
            ],
            'system_prompt' => null,
            'extraction_schema' => [
                'name' => 'extract_document_data',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'fields' => ['type' => 'object', 'properties' => []],
                        'visual_checks' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'document_quality' => ['type' => 'string'],
                    ],
                    'required' => ['fields', 'visual_checks', 'document_quality'],
                ],
            ],
        ];

        return AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_REGISTERED,
            auditId: $auditId,
            documentId: $documentId,
            payload: array_replace_recursive($payload, $payloadOverrides)
        );
    }
}

final class StubDownloadApiClient extends InternalAuditApiClient
{
    /**
     * @param array{mime:string,data:string} $document
     */
    public function __construct(private array $document)
    {
    }

    public function downloadAttachment(string $downloadUrl): array
    {
        return $this->document;
    }
}

final class StubExtractionCache extends ExtractionCache
{
    /** @var array<string,array<string,mixed>> */
    public array $stored = [];

    /**
     * @param array<string,array<string,mixed>> $stored
     */
    public function __construct(array $stored = [])
    {
        $this->stored = $stored;
    }

    public function get(string $documentHash): ?array
    {
        return $this->stored[$documentHash] ?? null;
    }

    public function put(string $documentHash, array $payload): bool
    {
        $this->stored[$documentHash] = $payload;
        return true;
    }
}

final class StubGeminiGateway extends GeminiGateway
{
    public int $calls = 0;
    /** @var array<int,array<string,mixed>> */
    public array $lastTools = [];
    /** @var array<string,mixed> */
    public array $lastToolConfig = [];

    /**
     * @param array<string,mixed> $response
     */
    public function __construct(private array $response)
    {
    }

    public function sendWithFunctionCalling(
        string $prompt,
        array $files,
        string $systemInstruction,
        array $tools,
        array $toolConfig,
        array $generationOverrides = []
    ): array {
        $this->calls++;
        $this->lastTools = $tools;
        $this->lastToolConfig = $toolConfig;
        return $this->response;
    }
}

final class ExtractionRecordingStateStore extends AuditStateStore
{
    /** @var array<string,mixed> */
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
    /** @var array<int,AuditEvent> */
    public array $published = [];
    /** @var array<int,AuditEvent> */
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
