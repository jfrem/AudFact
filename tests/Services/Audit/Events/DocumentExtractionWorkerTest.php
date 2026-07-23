<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use App\Services\Audit\Pipeline\DocumentExtractionWorker;
use App\Services\Audit\GeminiGateway;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentExtractionWorkerTest extends TestCase
{
    private const BLOB_KEY = 'audit:blob:att-1:test-hash';

    public function testCacheHitSkipsGeminiAndPublishesDocumentExtracted(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $hash = hash('sha256', $base64);
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                // extraction cache hit
                return json_encode([
                    'fields' => ['NumeroFactura' => 'T38250701547'],
                    'items' => [],
                    'visual_checks' => [],
                    'document_quality' => 'legible',
                    'quality_notes' => [],
                ]);
            });

        $gateway = new StubGeminiGateway([]);
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId));

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
        $base64 = $this->validPdfBase64();
        $hash = hash('sha256', $base64);
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null; // cache miss
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId));

        $this->assertSame(1, $gateway->calls);
        $this->assertDeclaredFunctionNames($gateway, [
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);
        $this->assertSame(GeminiGateway::TASK_EXTRACTION, $gateway->lastTaskType);
        $this->assertIsInt($gateway->lastGenerationOverrides['maxOutputTokens'] ?? null);
        $this->assertGreaterThan(0, $gateway->lastGenerationOverrides['maxOutputTokens']);
        $this->assertSame('extraction', $gateway->lastDebugContext['task_type'] ?? null);
        $toolJson = json_encode($gateway->lastTools, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('extract_document_data', $toolJson);
        $this->assertStringNotContainsString('additionalProperties', $toolJson);
        $this->assertStringNotContainsString('"default"', $toolJson);
        $this->assertStringNotContainsString('Para VigenciaEntrega', $gateway->lastPrompt);
        $this->assertFalse($publisher->published[0]->payload['cache_hit']);
        $this->assertSame('extraction', $publisher->published[0]->payload['gemini_metrics']['task_type'] ?? null);
        $this->assertSame('STOP', $publisher->published[0]->payload['gemini_metrics']['finish_reason'] ?? null);
        $this->assertSame('T38250701547', $publisher->published[0]->payload['extraction_result']['fields']['NumeroFactura']);
        $this->assertSame('ITEM A', $publisher->published[0]->payload['extraction_result']['items'][0]['NombreArticulo']);
        $this->assertArrayHasKey('prompt_context_hash', $publisher->published[0]->payload);
        $this->assertSame(64, strlen($publisher->published[0]->payload['prompt_context_hash']));
        $this->assertArrayNotHasKey('target_context_hash', $publisher->published[0]->payload);
    }

    public function testDownloadedBlobHashMismatchThrowsRuntimeExceptionBeforeGemini(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')->willReturn(json_encode([
            'mime' => 'application/pdf',
            'data' => $base64,
            'duration_ms' => 0,
        ]));
        $redisMock->expects($this->never())->method('set');

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('document_hash no coincide con el BLOB descargado');

        try {
            $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
                'document_hash' => str_repeat('a', 64),
            ]));
        } finally {
            $this->assertSame(0, $gateway->calls);
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testDynamicContractWithoutItemsOrVisualsCallsOnlyDeclaredFunctions(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $fieldsConfig = [
            ['campoNombre' => 'NumeroFactura', 'tipoCampo' => 'E', 'tipoDato' => 'text'],
        ];
        $contract = (new DocumentExtractionContractBuilder())->build('AUTORIZACION', $fieldsConfig, []);
        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse(
            omittedFunction: [
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            ]
        ));

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'tipo_documento' => 'AUTORIZACION',
            'extraction_contract' => $contract,
            'fields_config' => $fieldsConfig,
            'visual_checks' => [],
            'contract_hash' => $contract['contract_hash'],
        ]));

        $this->assertDeclaredFunctionNames($gateway, [
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);
        $this->assertStringNotContainsString('extract_items', $gateway->lastPrompt);
        $this->assertStringNotContainsString('detect_visual_checks', $gateway->lastPrompt);
        $this->assertStringNotContainsString('arreglo vacÃ­o', $gateway->lastPrompt);
        $this->assertSame([], $publisher->published[0]->payload['extraction_result']['items']);
        $this->assertSame([], $publisher->published[0]->payload['extraction_result']['visual_checks']);
    }

    public function testDynamicContractWithoutHeaderFieldsDefaultsFieldsToEmptyObject(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $fieldsConfig = [
            ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name'],
        ];
        $contract = (new DocumentExtractionContractBuilder())->build('FORMULA MEDICA', $fieldsConfig, []);
        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse(
            omittedFunction: [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            ]
        ));

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'extraction_contract' => $contract,
            'fields_config' => $fieldsConfig,
            'visual_checks' => [],
            'contract_hash' => $contract['contract_hash'],
        ]));

        $this->assertDeclaredFunctionNames($gateway, [
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);
        $this->assertStringNotContainsString('extract_fields', $gateway->lastPrompt);
        $this->assertSame([], $publisher->published[0]->payload['extraction_result']['fields']);
        $this->assertSame('ITEM A', $publisher->published[0]->payload['extraction_result']['items'][0]['NombreArticulo']);
    }

    public function testCompactSchemaKeepsValoresOnlyForMultiValueTypes(): void
    {
        $contract = (new DocumentExtractionContractBuilder())->build('DISPENSA', [
            ['campoNombre' => 'NumeroFactura', 'tipoCampo' => 'E', 'tipoDato' => 'text'],
            ['campoNombre' => 'CodigoDiagnostico', 'tipoCampo' => 'E', 'tipoDato' => 'code'],
            ['campoNombre' => 'Lote', 'tipoCampo' => 'E', 'tipoDato' => 'trace_token'],
        ], []);

        $fields = $this->functionDeclaration($contract, DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS)
            ['parameters']['properties']['fields']['properties'];
        $items = $this->functionDeclaration($contract, DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS)
            ['parameters']['properties']['items']['items']['properties'];

        $this->assertArrayNotHasKey('valores', $fields['NumeroFactura']['properties']);
        $this->assertSame(['valor', 'presente', 'estadoExtraccion'], $fields['NumeroFactura']['propertyOrdering']);

        $this->assertArrayHasKey('valores', $fields['CodigoDiagnostico']['properties']);
        $this->assertSame(['valor', 'valores', 'presente', 'estadoExtraccion'], $fields['CodigoDiagnostico']['propertyOrdering']);

        $this->assertArrayHasKey('valores', $items['Lote']['properties']);
        $this->assertSame(['valor', 'valores', 'presente', 'estadoExtraccion'], $items['Lote']['propertyOrdering']);
    }

    public function testBooleanVisualSchemaOmitsDeliveryValidityFields(): void
    {
        $contract = (new DocumentExtractionContractBuilder())->build('DISPENSA', [], [
            ['check' => 'FirmaActaEntrega', 'description' => 'Firma visible', 'severity' => 'CRITICO'],
        ]);

        $visualProperties = $this->functionDeclaration($contract, DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS)
            ['parameters']['properties']['visual_checks']['items']['properties'];

        $this->assertArrayNotHasKey('valor', $visualProperties);
        $this->assertArrayNotHasKey('unidad', $visualProperties);
        $this->assertArrayNotHasKey('fecha_base', $visualProperties);
    }

    public function testDeliveryValidityVisualSchemaKeepsCalculatedFields(): void
    {
        $contract = (new DocumentExtractionContractBuilder())->build('AUTORIZACION', [], [
            ['check' => 'VigenciaEntrega', 'description' => 'Vigencia visible', 'severity' => 'ALTA'],
        ]);

        $visualDeclaration = $this->functionDeclaration($contract, DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS);
        $visualProperties = $visualDeclaration['parameters']['properties']['visual_checks']['items']['properties'];

        $this->assertArrayHasKey('valor', $visualProperties);
        $this->assertArrayHasKey('unidad', $visualProperties);
        $this->assertArrayHasKey('fecha_base', $visualProperties);
        $this->assertSame(
            ['check', 'presente', 'detalle', 'valor', 'unidad', 'fecha_base', 'severidad'],
            $visualDeclaration['parameters']['properties']['visual_checks']['items']['propertyOrdering']
        );
    }

    public function testDeliveryValidityPromptIncludesCalculatedVisualInstruction(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $fieldsConfig = [
            ['campoNombre' => 'NumeroFactura', 'tipoCampo' => 'E', 'tipoDato' => 'text'],
        ];
        $visualChecks = [
            ['check' => 'VigenciaEntrega', 'description' => 'Vigencia visible', 'severity' => 'ALTA'],
        ];
        $contract = (new DocumentExtractionContractBuilder())->build('AUTORIZACION', $fieldsConfig, $visualChecks);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse(
            omittedFunction: DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS
        ));
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'tipo_documento' => 'AUTORIZACION',
            'extraction_contract' => $contract,
            'fields_config' => $fieldsConfig,
            'visual_checks' => $visualChecks,
            'contract_hash' => $contract['contract_hash'],
        ]));

        $this->assertStringContainsString('Para VigenciaEntrega', $gateway->lastPrompt);
    }

    public function testExtractionPromptAddsIdentitySeparationRuleWhenIdentityFieldsAreConfigured(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $contract = $this->extractionContract();
        $contract['field_groups']['fields'] = ['TipoDocumentoPaciente', 'DocumentoPaciente', 'NombrePaciente'];

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'extraction_contract' => $contract,
            'fields_config' => [
                ['campoNombre' => 'TipoDocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_type'],
                ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
                ['campoNombre' => 'NombrePaciente', 'tipoCampo' => 'S', 'tipoDato' => 'person_name'],
            ],
            'contract_hash' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
        ]));

        $this->assertStringContainsString('Regla de identidad', $gateway->lastPrompt);
        $this->assertStringContainsString('CC 94229637 NORENA AGUDELO', $gateway->lastPrompt);
        $this->assertStringContainsString('TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente', $gateway->lastPrompt);
        $this->assertStringNotContainsString('Valores de referencia', $gateway->lastPrompt);
        $this->assertStringNotContainsString('Campos de cabecera esperados', $gateway->lastPrompt);
    }

    public function testDoesNotPublishWhenStateStoreReturnsFalse(): void
    {
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore(false);
        $base64 = $this->validPdfBase64();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                // extraction cache hit
                return json_encode([
                    'fields' => ['NumeroFactura' => 'T38250701547'],
                    'items' => [],
                    'visual_checks' => [],
                    'document_quality' => 'legible',
                    'quality_notes' => [],
                ]);
            });

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: new StubGeminiGateway([]),
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo persistir la extracción');

        try {
            $worker->processEvent($this->documentDownloadedEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testInvalidGeminiResponseThrowsRuntimeException(): void
    {
        $base64 = $this->validPdfBase64();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null;
            });
        
        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: new StubGeminiGateway(['candidates' => []]),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_CANDIDATE');

        $worker->processEvent($this->documentDownloadedEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testMaxTokensFinishReasonDoesNotCacheOrPublishExtraction(): void
    {
        $publisher = new ExtractionPublisher();
        $base64 = $this->validPdfBase64();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null;
            });
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse('MAX_TOKENS')),
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_UNSAFE_FINISH_REASON: MAX_TOKENS');

        try {
            $worker->processEvent($this->documentDownloadedEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
        } finally {
            $this->assertCount(0, $publisher->published);
        }
    }

    public function testMissingParallelFunctionCallThrowsRuntimeException(): void
    {
        $base64 = $this->validPdfBase64();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null;
            });
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse(
                omittedFunction: DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS
            )),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_FUNCTION_CALL: extract_items');

        $worker->processEvent($this->documentDownloadedEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testDuplicateParallelFunctionCallThrowsRuntimeException(): void
    {
        $base64 = $this->validPdfBase64();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null;
            });
        $redisMock->expects($this->never())->method('set');

        $worker = new DocumentExtractionWorker(
            stateStore: new ExtractionRecordingStateStore(),
            gateway: new StubGeminiGateway($this->geminiFunctionCallResponse(
                duplicateFunction: DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS
            )),
            redis: $redisMock,
            publisher: new ExtractionPublisher(),
            consumerName: 'extractor-test'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_DUPLICATE_FUNCTION_CALL: extract_fields');

        $worker->processEvent($this->documentDownloadedEvent(AuditEvent::uuidV4(), AuditEvent::uuidV4()));
    }

    public function testInvalidDocumentPublishesDocumentRejectedAndSkipsGemini(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $invalidBase64 = base64_encode('not-a-pdf');
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();
        $blobJson = json_encode(['mime' => 'application/pdf', 'data' => $invalidBase64, 'duration_ms' => 0]);

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($blobJson) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return $blobJson;
                }
                return null;
            });
        $redisMock->expects($this->never())->method('set');

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());
        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'document_hash' => hash('sha256', $invalidBase64),
        ]));

        $this->assertSame(0, $gateway->calls);
        $this->assertSame('UNKNOWN_FILE_SIGNATURE', $store->lastRejectedPatch['rejection_reason'] ?? null);
        $this->assertSame('FORMULA MEDICA', $store->lastRejectedPatch['document_type'] ?? null);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_REJECTED, $publisher->published[0]->eventType);
        $this->assertSame('UNKNOWN_FILE_SIGNATURE', $publisher->published[0]->payload['rejection_reason'] ?? null);
    }

    public function testSystemPromptDeduplication(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse(
            omittedFunction: [
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            ]
        ));

        $duplicada = "CÃ³digo Producto y CÃ³digo ArtÃ­culo corresponden a conceptos diferentes.";
        $noDuplicada = "Procesa el documento en espaÃ±ol.";
        $systemPromptCliente = $duplicada . "\n" . $noDuplicada;

        $fieldsConfig = [
            [
                'campoNombre' => 'NumeroFactura',
                'tipoCampo' => 'E',
                'tipoDato' => 'text',
                'description' => $duplicada . " Por ejemplo: CÃ³digo Producto: 12345.",
            ],
        ];

        $contract = (new DocumentExtractionContractBuilder())->build('FORMULA MEDICA', $fieldsConfig, []);

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, [
            'tipo_documento' => 'FORMULA MEDICA',
            'extraction_contract' => $contract,
            'fields_config' => $fieldsConfig,
            'system_prompt' => $systemPromptCliente,
            'contract_hash' => $contract['contract_hash'],
        ]));

        $this->assertStringContainsString($noDuplicada, $gateway->lastSystemInstruction);
        $this->assertStringNotContainsString($duplicada, $gateway->lastSystemInstruction);
        $this->assertSame(1, substr_count($gateway->lastSystemInstruction, $noDuplicada));
    }

    public function testMultiItemDispensaPartialExtractionPublishesWarningInsteadOfThrowing(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $payloadOverrides = [
            'tipo_documento' => 'DISPENSA',
            'fuente_verdad' => [
                'items' => [
                    ['NombreArticulo' => 'ITEM A'],
                    ['NombreArticulo' => 'ITEM B'],
                ],
            ],
        ];

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, $payloadOverrides));

        $this->assertCount(1, $publisher->published);
        $warnings = $publisher->published[0]->payload['extraction_result']['extraction_warnings'] ?? [];
        $this->assertCount(1, $warnings);
        $this->assertSame('ITEM_SEGMENTATION_INCOMPLETE', $warnings[0]['code']);
        $this->assertSame(2, $warnings[0]['expected_items_count']);
        $this->assertSame(1, $warnings[0]['extracted_items_count']);
    }

    public function testSingleItemDispensaDoesNotPublishSegmentationWarning(): void
    {
        $documentId = AuditEvent::uuidV4();
        $auditId = AuditEvent::uuidV4();
        $base64 = $this->validPdfBase64();
        $publisher = new ExtractionPublisher();
        $store = new ExtractionRecordingStateStore();

        $redisMock = $this->createMock(RedisClient::class);
        $redisMock->method('get')
            ->willReturnCallback(function (string $key) use ($base64) {
                if (str_starts_with($key, 'audit:blob:')) {
                    return json_encode(['mime' => 'application/pdf', 'data' => $base64, 'duration_ms' => 0]);
                }
                return null;
            });
        $redisMock->expects($this->once())->method('set')->willReturn(true);

        $gateway = new StubGeminiGateway($this->geminiFunctionCallResponse());

        $worker = new DocumentExtractionWorker(
            stateStore: $store,
            gateway: $gateway,
            redis: $redisMock,
            publisher: $publisher,
            consumerName: 'extractor-test'
        );

        $payloadOverrides = [
            'tipo_documento' => 'DISPENSA',
            'fuente_verdad' => [
                'items' => [
                    ['NombreArticulo' => 'ITEM A'],
                ],
            ],
        ];

        $worker->processEvent($this->documentDownloadedEvent($auditId, $documentId, $payloadOverrides));

        $this->assertCount(1, $publisher->published);
        $warnings = $publisher->published[0]->payload['extraction_result']['extraction_warnings'] ?? [];
        $this->assertCount(0, $warnings);
    }

    private function validPdfBase64(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj\n<</Type /Page>>\nendobj\n");
    }

    /**
     * @param array<int,string> $expected
     */
    private function assertDeclaredFunctionNames(StubGeminiGateway $gateway, array $expected): void
    {
        $this->assertSame($expected, array_column($gateway->lastTools[0]['functionDeclarations'], 'name'));
        $this->assertSame($expected, $gateway->lastToolConfig['functionCallingConfig']['allowedFunctionNames'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function functionDeclaration(array $contract, string $name): array
    {
        foreach ($contract['function_declarations'] ?? [] as $declaration) {
            if (is_array($declaration) && ($declaration['name'] ?? null) === $name) {
                return $declaration;
            }
        }

        $this->fail("No se encontro function declaration {$name}");
    }

    private function geminiFunctionCallResponse(
        string $finishReason = 'STOP',
        string|array|null $omittedFunction = null,
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
            $omittedFunctions = is_array($omittedFunction) ? $omittedFunction : [$omittedFunction];
            $parts = array_values(array_filter(
                $parts,
                static fn(array $part): bool => !in_array($part['functionCall']['name'] ?? null, $omittedFunctions, true)
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

    private function documentDownloadedEvent(
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
            'visual_checks' => [
                ['check' => 'FirmaActaEntrega', 'description' => 'Firma visible', 'severity' => 'CRITICO'],
            ],
            'attempt' => 1,
            'blob_reference_key' => self::BLOB_KEY,
            'document_hash' => hash('sha256', $this->validPdfBase64()),
            'contract_hash' => hash('sha256', json_encode($this->extractionContract(), JSON_THROW_ON_ERROR)),
        ];

        return AuditEvent::create(
            eventType: AuditEvent::TYPE_DOCUMENT_DOWNLOADED,
            auditId: $auditId,
            jobId: AuditEvent::uuidV4(),
            payload: array_replace($payload, $payloadOverrides),
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

final class StubGeminiGateway extends GeminiGateway
{
    public int $calls = 0;
    public array $lastTools = [];
    public array $lastToolConfig = [];
    public string $lastPrompt = '';
    public string $lastSystemInstruction = '';
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
        $this->lastSystemInstruction = $systemInstruction;
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
    public array $lastRejectedPatch = [];
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

    public function markDocumentRejected(string $auditId, string $documentId, array $patch): bool
    {
        $this->lastRejectedPatch = $patch;
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
