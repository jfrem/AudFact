<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\AttachmentDownloadService;
use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
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
        $this->assertSame('extraction', $publisher->published[0]->payload['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('application/pdf', $publisher->published[0]->payload['mime']);
        $this->assertTrue($publisher->published[0]->payload['gemini_metrics']['cache_hit'] ?? false);
        $this->assertSame('extraction', $store->lastPatch['gemini_metrics']['task_type'] ?? null);
        $this->assertTrue($store->lastPatch['gemini_metrics']['cache_hit'] ?? false);
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
        
        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());
        
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
        $this->assertSame(
            [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
            ],
            array_column($gateway->lastTools[0]['functionDeclarations'], 'name')
        );
        $this->assertSame(
            [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
            ],
            $gateway->lastToolConfig['functionCallingConfig']['allowedFunctionNames'] ?? null
        );
        $this->assertSame(GeminiGateway::TASK_EXTRACTION, $gateway->lastTaskType);
        $this->assertIsInt($gateway->lastGenerationOverrides['maxOutputTokens'] ?? null);
        $this->assertGreaterThan(0, $gateway->lastGenerationOverrides['maxOutputTokens']);
        $this->assertSame('extraction', $gateway->lastDebugContext['task_type'] ?? null);
        $toolJson = json_encode($gateway->lastTools, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('extract_document_data', $toolJson);
        $this->assertStringNotContainsString('additionalProperties', $toolJson);
        $this->assertStringNotContainsString('"default"', $toolJson);
        $this->assertFalse($publisher->published[0]->payload['cache_hit']);
        $this->assertSame('extraction', $publisher->published[0]->payload['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('STOP', $publisher->published[0]->payload['gemini_metrics']['finish_reason'] ?? null);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['extraction_result']['fields']['NumeroFactura']);
        $this->assertSame('ITEM A', $publisher->published[0]->payload['extraction_result']['items'][0]['NombreArticulo']);
    }

    public function testExtractionPromptAddsIdentitySeparationRuleWhenIdentityFieldsAreConfigured(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = base64_encode('pdf-data');
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        $redisMock->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => $base64]),
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $contract = $this->extractionContract();
        $contract['field_groups']['fields'] = ['TipoDocumentoPaciente', 'DocumentoPaciente', 'NombrePaciente'];

        $worker->processEvent($this->documentRegisteredEvent($auditId, $documentId, [
            'extraction_contract' => $contract,
            'fields_config' => [
                ['campoNombre' => 'TipoDocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_type'],
                ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
                ['campoNombre' => 'NombrePaciente', 'tipoCampo' => 'S', 'tipoDato' => 'person_name'],
            ],
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
        ]));

        $this->assertStringContainsString('Regla de identidad', $gateway->lastPrompt);
        $this->assertStringContainsString('DocumentoPaciente="94229637"', $gateway->lastPrompt);
        $this->assertStringContainsString('NombrePaciente="NORENA AGUDELO"', $gateway->lastPrompt);
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
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_CANDIDATE');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testMaxTokensFinishReasonDoesNotCacheOrPublishExtraction(): void
    {
        $publisher = new ExtractionPublisher();
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse('MAX_TOKENS')),
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_UNSAFE_FINISH_REASON: MAX_TOKENS');

        try {
            $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testMissingParallelFunctionCallThrowsRuntimeException(): void
    {
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse(
                omittedFunction: DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS
            )),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_FUNCTION_CALL: extract_items');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testDuplicateParallelFunctionCallThrowsRuntimeException(): void
    {
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(null);
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            downloader: new StubDownloadService(['mime' => 'application/pdf', 'data' => base64_encode('pdf-data')]),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse(
                duplicateFunction: DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS
            )),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_DUPLICATE_FUNCTION_CALL: extract_fields');

        $worker->processEvent($this->documentRegisteredEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    private function geminiFunctionCallResponse(
        string $finishReason = 'STOP',
        ?string $omittedFunction = null,
        ?string $duplicateFunction = null
    ): array
    {
        $parts = [
            [
                'functionCall' => [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                    'args' => [
                        'fields' => ['NumeroFactura' => 'T38250701547'],
                    ],
                ],
            ],
            [
                'functionCall' => [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                    'args' => [
                        'items' => [
                            ['NombreArticulo' => 'ITEM A'],
                        ],
                    ],
                ],
            ],
            [
                'functionCall' => [
                    'name' => DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                    'args' => [
                        'visual_checks' => [
                            ['check' => 'FirmaActaEntrega', 'presente' => true],
                        ],
                    ],
                ],
            ],
            [
                'functionCall' => [
                    'name' => DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
                    'args' => [
                        'document_quality' => 'legible',
                        'quality_notes' => ['ok'],
                    ],
                ],
            ],
        ];

        if ($omittedFunction !== null) {
            $parts = array_values(array_filter(
                $parts,
                static fn(array $part): bool => ($part['functionCall']['name'] ?? null) !== $omittedFunction
            ));
        }

        if ($duplicateFunction !== null) {
            foreach ($parts as $part) {
                if (($part['functionCall']['name'] ?? null) === $duplicateFunction) {
                    $parts[] = $part;
                    break;
                }
            }
        }

        return [
            'X-Audit-Metrics' => [
                'task_type' => 'extraction',
                'document_type' => 'FORMULA MEDICA',
                'duration_ms' => 123,
                'cache_hit' => false,
                'finish_reason' => $finishReason,
            ],
            'candidates' => [[
                'finishReason' => $finishReason,
                'content' => [
                    'parts' => $parts,
                ],
            ]],
        ];
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
            'extraction_contract' => $this->extractionContract(),
            'fields_config' => [
                ['campoNombre' => 'NumeroFactura', 'tipoCampo' => 'E', 'tipoDato' => 'text'],
                ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name'],
            ],
            'attempt' => 1,
            'contract_hash' => hash('sha256', json_encode($this->extractionContract(), JSON_THROW_ON_ERROR)),
            'target_context_hash' => hash('sha256', json_encode(['diagnosticos' => ['E119'], 'items' => []], JSON_THROW_ON_ERROR)),
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

    private function extractionContract(): array
    {
        return [
            'function_declarations' => [
                [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'fields' => [
                                'type' => 'object',
                                'properties' => [
                                    'NumeroFactura' => ['type' => 'string', 'nullable' => true],
                                ],
                            ],
                        ],
                        'required' => ['fields'],
                    ],
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'NombreArticulo' => ['type' => 'string', 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['items'],
                    ],
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'visual_checks' => ['type' => 'array'],
                        ],
                        'required' => ['visual_checks'],
                    ],
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'document_quality' => ['type' => 'string'],
                            'quality_notes' => ['type' => 'array'],
                        ],
                        'required' => ['document_quality', 'quality_notes'],
                    ],
                ],
            ],
            'required_function_names' => [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
            ],
            'field_groups' => [
                'fields' => ['NumeroFactura'],
                'items' => ['NombreArticulo'],
            ],
        ];
    }
}

final class StubDownloadService extends AttachmentDownloadService
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
    public string $lastPrompt = '';
    public string $lastTaskType = '';
    public array $lastGenerationOverrides = [];
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
        string $taskType,
        array $generationOverrides = [],
        ?array $debugContext = null
    ): array {
        $this->calls++;
        $this->lastPrompt = $prompt;
        $this->lastTools = $tools;
        $this->lastToolConfig = $toolConfig;
        $this->lastTaskType = $taskType;
        $this->lastGenerationOverrides = $generationOverrides;
        $this->lastDebugContext = array_merge($debugContext ?? [], ['task_type' => $taskType]);
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
