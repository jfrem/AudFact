<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDataService;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use App\Services\Audit\Pipeline\DocumentMappingRejectionReason;
use Core\RedisClient;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentAuditOrchestratorTest extends TestCase
{
    public function testAuditCreatedFor2426PublishesThreeDocumentRegisteredEventsWithContractPayload(): void
    {
        // Arrange:
        $publisher  = new InMemoryPublisher();
        $store      = new RecordingStateStore();
        $redis      = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '2426',
                    'DisId'        => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [
                    ['NombreArticulo' => 'ITEM A'],
                ],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE',  'NitMedDocNom' => 'DISPENSA'],
                ['NitMedDocId' => 2, 'NitMedDocCodAlt' => 'AUT',  'NitMedDocNom' => 'AUTORIZACION'],
                ['NitMedDocId' => 3, 'NitMedDocCodAlt' => 'FORM', 'NitMedDocNom' => 'FORMULA MEDICA'],
            ],
            auditConfig: [
                'nitSec'       => '2426',
                'activo'       => true,
                'systemPrompt' => null,
                'documents'    => [
                    'DISPENSA' => [
                        'docId'        => 1,
                        'fields'       => [
                            ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
                            ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name'],
                            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity'],
                        ],
                        'visualChecks' => [[
                            'check' => 'FirmaActaEntrega',
                            'description' => 'Firma',
                            'severity' => 'CRITICO',
                            'codigoCampo' => 'FIR',
                        ]],
                    ],
                    'AUTORIZACION' => [
                        'docId'        => 2,
                        'fields'       => [
                            ['campoNombre' => 'NumeroAutorizacion', 'tipoCampo' => 'E', 'tipoDato' => 'code'],
                        ],
                        'visualChecks' => [],
                    ],
                    'FORMULA MEDICA' => [
                        'docId'        => 3,
                        'fields'       => [
                            ['campoNombre' => 'NombreArticulo', 'tipoCampo' => 'S', 'tipoDato' => 'article_name'],
                        ],
                        'visualChecks' => [['check' => 'FirmaPrescriptor', 'description' => 'Firma médico', 'severity' => 'CRITICO']],
                    ],
                ],
            ],
            attachments: [
                ['attachment_id' => '1', 'physical_catalog_id' => '1', 'physical_document_name' => 'DISPENSA',       'physical_catalog_alias' => 'ANE',  'storage_type' => 'URL'],
                ['attachment_id' => '2', 'physical_catalog_id' => '2', 'physical_document_name' => 'AUTORIZACION',   'physical_catalog_alias' => 'AUT',  'storage_type' => 'URL'],
                ['attachment_id' => '3', 'physical_catalog_id' => '3', 'physical_document_name' => 'FORMULA MEDICA', 'physical_catalog_alias' => 'FORM', 'storage_type' => 'URL'],
            ],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore:   $store,
            dataService:  $dataService,

            redis:        $redis,
            publisher:    $publisher,
            consumerName: 'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => '87723098',
                'source' => 'batch',
            ]
        );

        // Act:
        $orchestrator->processEvent($event);

        // Assert:
        $this->assertSame(['87723098'], $dataService->requestedDisIds);

        // Comportamiento esperado: 3 documentos registrados y publicados
        $this->assertSame(3, $store->docsTotal);
        $this->assertCount(3, $store->registeredDocuments);
        $this->assertCount(3, $publisher->published);

        // Contrato del payload del primer evento (DISPENSA)
        $payload = $publisher->published[0]->payload;
        $this->assertSame('DISPENSA', $payload['tipo_documento']);
        $this->assertSame('ANE', $payload['nombre_alternativo']);
        $this->assertSame('/dispensation/T38250701547/attachments/download/1', $payload['download_url']);
        $this->assertSame('URL', $payload['tipo_almacenamiento']);
        $this->assertSame('1', $payload['doc_id']);
        $this->assertSame('1', $payload['attachment_id']);
        $this->assertSame('1', $payload['physical_catalog_id']);
        $this->assertSame('DISPENSA', $payload['physical_document_name']);
        $this->assertSame('exact_name', $payload['attachment_match_strategy']);
        $this->assertSame(['1'], $payload['attachment_match_candidates']);
        $this->assertArrayNotHasKey('extraction_schema', $payload);
        $this->assertIsArray($payload['extraction_contract']);
        $this->assertContractFunctionNames($payload['extraction_contract'], [
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);
        $this->assertCount(4, $payload['extraction_contract']['function_declarations']);
        $this->assertSame(
            ['DocumentoPaciente'],
            $payload['extraction_contract']['field_groups']['fields']
        );
        $this->assertSame(
            ['NombreArticulo', 'CantidadEntregada'],
            $payload['extraction_contract']['field_groups']['items']
        );
        // Schema v1: DocumentoPaciente es un objeto de evidencia, no un escalar
        $docPacSchema = $payload['extraction_contract']['function_declarations'][0]['parameters']['properties']['fields']['properties']['DocumentoPaciente'];
        $this->assertSame('object', $docPacSchema['type']);
        $this->assertArrayHasKey('valor', $docPacSchema['properties']);
        $this->assertSame('string', $docPacSchema['properties']['valor']['type']);
        $this->assertStringContainsString('Solo numero del paciente', $docPacSchema['properties']['valor']['description']);
        $this->assertArrayHasKey('estadoExtraccion', $docPacSchema['properties']);
        $this->assertSame(
            ['FOUND', 'FOUND_IN_LIST', 'NOT_FOUND', 'ILLEGIBLE'],
            $docPacSchema['properties']['estadoExtraccion']['enum']
        );
        // Schema v1: CantidadEntregada es un objeto de evidencia con valor type=number
        $cantSchema = $payload['extraction_contract']['function_declarations'][1]['parameters']['properties']['items']['items']['properties']['CantidadEntregada'];
        $this->assertSame('object', $cantSchema['type']);
        $this->assertSame('number', $cantSchema['properties']['valor']['type']);
        $this->assertArrayNotHasKey('description', $cantSchema['properties']['valor']);
        $this->assertArrayNotHasKey('description', $cantSchema['properties']['presente']);
        $this->assertArrayNotHasKey('description', $cantSchema['properties']['estadoExtraccion']);
        $this->assertSame(
            ['legible', 'parcialmente_legible', 'ilegible'],
            $payload['extraction_contract']['function_declarations'][3]['parameters']['properties']['document_quality']['enum']
        );
        $this->assertSame(
            ['check', 'presente', 'detalle'],
            $payload['extraction_contract']['function_declarations'][2]['parameters']['properties']['visual_checks']['items']['required']
        );
        $visualProperties = $payload['extraction_contract']['function_declarations'][2]['parameters']['properties']['visual_checks']['items']['properties'];
        $this->assertArrayNotHasKey('valor', $visualProperties);
        $this->assertArrayNotHasKey('unidad', $visualProperties);
        $this->assertArrayNotHasKey('fecha_base', $visualProperties);
        $this->assertArrayNotHasKey(
            'additionalProperties',
            $payload['extraction_contract']['function_declarations'][0]['parameters']['properties']['fields']
        );
        $this->assertIsArray($payload['visual_checks']);
        $this->assertSame('FIR', $payload['visual_checks'][0]['codigoCampo']);
        $this->assertArrayHasKey('header', $payload['fuente_verdad']);
        $this->assertNull($payload['system_prompt']);
        $this->assertSame('T38250701547', $payload['dis_det_nro']);
        $this->assertSame('T38250701547', $payload['numero_factura']);
        $this->assertSame('87723098', $payload['dis_id']);
        $this->assertSame('2426', $payload['fac_nit_sec']);
        $this->assertSame('87723098', $store->patches[0]['dis_id'] ?? null);
        $this->assertSame('T38250701547', $store->patches[0]['numero_factura'] ?? null);
        $this->assertArrayNotHasKey('dis_det_nro', $store->patches[0] ?? []);

        // T07: contract_hash propagado; FDV no se convierte en contexto de Gemini
        $this->assertArrayHasKey('contract_hash', $payload);
        $this->assertNotEmpty($payload['contract_hash']);
        $this->assertSame(64, strlen($payload['contract_hash']));
        $this->assertArrayNotHasKey('target_context', $payload);
        $this->assertArrayNotHasKey('target_context_hash', $payload);

        $authorizationPayload = $publisher->published[1]->payload;
        $this->assertSame('AUTORIZACION', $authorizationPayload['tipo_documento']);
        $this->assertContractFunctionNames($authorizationPayload['extraction_contract'], [
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);

        $formulaPayload = $publisher->published[2]->payload;
        $this->assertSame('FORMULA MEDICA', $formulaPayload['tipo_documento']);
        $this->assertContractFunctionNames($formulaPayload['extraction_contract'], [
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
            DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
        ]);
        $this->assertSame([], $formulaPayload['extraction_contract']['field_groups']['fields']);
        $this->assertSame(['NombreArticulo'], $formulaPayload['extraction_contract']['field_groups']['items']);
    }

    public function testFallbackMatchesAttachmentByNormalizedName(): void
    {
        // Arrange:
        $publisher   = new InMemoryPublisher();
        $store       = new RecordingStateStore();
        $redis       = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '1165',
                    'DisId'        => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 3, 'NitMedDocCodAlt' => 'FORM', 'NitMedDocNom' => 'FORMULA MEDICA'],
            ],
            auditConfig: [
                'nitSec'       => '1165',
                'activo'       => true,
                'systemPrompt' => 'prompt-fixture',
                'documents'    => [
                    'FORMULA_MEDICA' => [
                        'docId'        => 3,
                        'fields'       => [
                            ['campoNombre' => 'FirmaActaEntrega', 'tipoCampo' => 'E', 'tipoDato' => 'text'],
                        ],
                        'visualChecks' => [],
                    ],
                ],
            ],
            attachments: [
                ['attachment_id' => '99', 'physical_catalog_id' => '99', 'physical_document_name' => 'FORMULA MEDICA', 'physical_catalog_alias' => 'FORM', 'storage_type' => 'BLOB'],
            ],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore:    $store,
            dataService:   $dataService,

            redis:         $redis,
            publisher:     $publisher,
            consumerName:  'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'dis_id' => '87723098',
            ]
        );

        // Act:
        $orchestrator->processEvent($event);

        // Assert:
        $this->assertCount(1, $publisher->published);
        $this->assertSame('FORMULA_MEDICA', $publisher->published[0]->payload['tipo_documento']);
        $this->assertSame('99', $publisher->published[0]->payload['attachment_id']);
        $this->assertSame('prompt-fixture', $publisher->published[0]->payload['system_prompt']);
    }

    public function testMissingRequiredAttachmentPublishesControlledMappingRejection(): void
    {
        // Arrange:
        $store = new RecordingStateStore();
        $publisher = new InMemoryPublisher();
        $redis       = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '2426',
                    'DisId'        => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE', 'NitMedDocNom' => 'DISPENSA'],
            ],
            auditConfig: [
                'nitSec'       => '2426',
                'activo'       => true,
                'systemPrompt' => null,
                'documents'    => [
                    'DISPENSA' => [
                        'docId'        => 1,
                        'fields'       => [
                            ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
                        ],
                        'visualChecks' => [],
                    ],
                ],
            ],
            attachments: [],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore:    $store,
            dataService:   $dataService,

            redis:         $redis,
            publisher:     $publisher,
            consumerName:  'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'dis_id' => '87723098',
            ]
        );

        // Act:
        $orchestrator->processEvent($event);

        // Assert:
        $this->assertSame(1, $store->docsTotal);
        $this->assertCount(1, $store->registeredDocuments);
        $this->assertCount(1, $store->rejectedDocuments);
        $this->assertCount(1, $publisher->published);
        $this->assertSame(AuditEvent::TYPE_DOCUMENT_REJECTED, $publisher->published[0]->eventType);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING,
            $publisher->published[0]->payload['rejection_reason']
        );
        $this->assertSame(
            DocumentMappingRejectionReason::CATEGORY,
            $publisher->published[0]->payload['rejection_category']
        );
        $this->assertSame('DISPENSA', $publisher->published[0]->payload['document_type']);
        $this->assertSame('1', $publisher->published[0]->payload['logical_doc_id']);
        $this->assertSame([], $publisher->published[0]->payload['candidate_attachment_ids']);
        $this->assertNotEmpty($publisher->published[0]->payload['rejected_at']);
        $this->assertSame('registered', $store->registeredDocuments[0]['state']['status']);
        $this->assertSame('', $store->registeredDocuments[0]['state']['attachment_id']);
        $this->assertNull($store->registeredDocuments[0]['state']['attachment_match_strategy']);
        $this->assertSame(
            DocumentMappingRejectionReason::CATEGORY,
            $store->rejectedDocuments[0]['patch']['rejection_category']
        );
    }

    public function test2624OrchestrationPublishesFourDistinctPhysicalMappings(): void
    {
        // Arrange:
        $store = new RecordingStateStore();
        $publisher = new InMemoryPublisher();
        $documents = [
            'DISPENSA' => ['docId' => 1, 'fields' => [], 'visualChecks' => []],
            'AUTORIZACION' => ['docId' => 2, 'fields' => [], 'visualChecks' => []],
            'FORMULA MEDICA' => ['docId' => 3, 'fields' => [], 'visualChecks' => []],
            'VALIDADOR DE DERECHOS' => ['docId' => 4, 'fields' => [], 'visualChecks' => []],
        ];
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec' => '2624',
                    'DisId' => '87723098',
                    'NumeroFactura' => 'T38250701547',
                    'Autorizacion' => 'S',
                ],
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE', 'NitMedDocNom' => 'DISPENSA'],
                ['NitMedDocId' => 2, 'NitMedDocCodAlt' => 'PDE', 'NitMedDocNom' => 'AUTORIZACION'],
                ['NitMedDocId' => 3, 'NitMedDocCodAlt' => 'OPF', 'NitMedDocNom' => 'FORMULA MEDICA'],
                ['NitMedDocId' => 4, 'NitMedDocCodAlt' => 'PDE', 'NitMedDocNom' => 'VALIDADOR DE DERECHOS'],
            ],
            auditConfig: ['nitSec' => '2624', 'activo' => true, 'documents' => $documents],
            attachments: [
                ['attachment_id' => '1', 'physical_catalog_id' => '1', 'physical_document_name' => 'DISPENSA', 'physical_catalog_alias' => 'ANE', 'storage_type' => 'BLOB'],
                ['attachment_id' => '6', 'physical_catalog_id' => '6', 'physical_document_name' => 'AUTORIZACION', 'physical_catalog_alias' => 'PDE', 'storage_type' => 'BLOB'],
                ['attachment_id' => '3', 'physical_catalog_id' => '3', 'physical_document_name' => 'FORMULA MEDICA', 'physical_catalog_alias' => 'OPF', 'storage_type' => 'BLOB'],
                ['attachment_id' => '4', 'physical_catalog_id' => '4', 'physical_document_name' => 'VALIDADOR DE DERECHOS', 'physical_catalog_alias' => 'PDE', 'storage_type' => 'BLOB'],
            ],
        );
        $orchestrator = $this->makeOrchestrator($dataService, $store, $publisher);
        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547', 'dis_id' => '87723098']
        );

        // Act:
        $orchestrator->processEvent($event);

        // Assert:
        $this->assertSame(4, $store->docsTotal);
        $this->assertSame([], $store->rejectedDocuments);
        $this->assertCount(4, $publisher->published);
        $this->assertSame(
            [
                AuditEvent::TYPE_DOCUMENT_REGISTERED,
                AuditEvent::TYPE_DOCUMENT_REGISTERED,
                AuditEvent::TYPE_DOCUMENT_REGISTERED,
                AuditEvent::TYPE_DOCUMENT_REGISTERED,
            ],
            array_column($publisher->published, 'eventType')
        );
        $this->assertSame(['1', '6', '3', '4'], array_column(
            array_column($store->registeredDocuments, 'state'),
            'attachment_id'
        ));
        $this->assertSame(['exact_name', 'exact_name', 'exact_name', 'exact_name'], array_column(
            array_column($store->registeredDocuments, 'state'),
            'attachment_match_strategy'
        ));
        $this->assertCount(4, array_unique(array_column(
            array_column($store->registeredDocuments, 'state'),
            'attachment_id'
        )));
    }

    public function testMissingDisIdThrowsRuntimeException(): void
    {
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService());

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   ['dis_det_nro' => 'T38250701547']
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_created sin dis_id');
        $orchestrator->processEvent($event);
    }

    public function testPatchAuditFailureStopsBeforePublishingDocuments(): void
    {
        $store = new RecordingStateStore();
        $store->patchAuditResult = false;
        $publisher = new InMemoryPublisher();
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService(), $store, $publisher);

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => '87723098',
                'source' => 'batch',
            ]
        );

        try {
            $orchestrator->processEvent($event);
            $this->fail('Se esperaba RuntimeException por fallo de patchAudit.');
        } catch (RuntimeException $error) {
            $this->assertSame('No se pudo actualizar el contexto de auditoría en Redis', $error->getMessage());
        }

        $this->assertSame([], $store->registeredDocuments);
        $this->assertSame([], $publisher->published);
    }

    public function testDocumentsTotalFailureStopsBeforePublishingDocuments(): void
    {
        $store = new RecordingStateStore();
        $store->setAuditDocumentsTotalResult = false;
        $publisher = new InMemoryPublisher();
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService(), $store, $publisher);

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => '87723098',
                'source' => 'batch',
            ]
        );

        try {
            $orchestrator->processEvent($event);
            $this->fail('Se esperaba RuntimeException por fallo de setAuditDocumentsTotal.');
        } catch (RuntimeException $error) {
            $this->assertSame('No se pudo registrar el total de documentos de la auditoría', $error->getMessage());
        }

        $this->assertSame([], $store->registeredDocuments);
        $this->assertSame([], $publisher->published);
    }

    public function testRegisterDocumentFailureDoesNotPublishDocumentRegistered(): void
    {
        $store = new RecordingStateStore();
        $store->registerDocumentResult = false;
        $publisher = new InMemoryPublisher();
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService(), $store, $publisher);

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => '87723098',
                'source' => 'batch',
            ]
        );

        try {
            $orchestrator->processEvent($event);
            $this->fail('Se esperaba RuntimeException por fallo de registerDocument.');
        } catch (RuntimeException $error) {
            $this->assertSame('No se pudo registrar el documento en Redis', $error->getMessage());
        }

        $this->assertSame([], $store->registeredDocuments);
        $this->assertSame([], $publisher->published);
    }

    public function testBatchDisIdMismatchThrowsIdentityMismatch(): void
    {
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService());

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => 'LEGACY-FACSEC',
                'source' => 'batch',
            ]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('AUDIT_IDENTITY_MISMATCH: payload.dis_id');
        $orchestrator->processEvent($event);
    }

    public function testDisDetNroMismatchThrowsIdentityMismatch(): void
    {
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService([
            'NumeroFactura' => 'OTHER-DISPENSA',
        ]));

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'dis_id' => '87723098',
                'source' => 'batch',
            ]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('AUDIT_IDENTITY_MISMATCH: payload.dis_det_nro');
        $orchestrator->processEvent($event);
    }

    /**
     * @param array<string,string> $headerOverrides
     */
    private function makeSingleDocumentDataService(array $headerOverrides = []): StubAuditDataService
    {
        return new StubAuditDataService(
            dispensation: [
                'header' => array_merge([
                    'NitSec'        => '2426',
                    'DisId'        => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ], $headerOverrides),
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE', 'NitMedDocNom' => 'DISPENSA'],
            ],
            auditConfig: [
                'nitSec'    => '2426',
                'activo'    => true,
                'documents' => [
                    'DISPENSA' => [
                        'docId'        => 1,
                        'fields'       => [
                            ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
                        ],
                        'visualChecks' => [],
                    ],
                ],
            ],
            attachments: [
                ['attachment_id' => '1', 'physical_catalog_id' => '1', 'physical_document_name' => 'DISPENSA', 'physical_catalog_alias' => 'ANE', 'storage_type' => 'URL'],
            ],
        );
    }

    private function makeOrchestrator(
        StubAuditDataService $dataService,
        ?RecordingStateStore $stateStore = null,
        ?InMemoryPublisher $publisher = null
    ): DocumentAuditOrchestrator
    {
        return new DocumentAuditOrchestrator(
            stateStore:   $stateStore ?? new RecordingStateStore(),
            dataService:  $dataService,
            redis:        $this->createMock(RedisClient::class),
            publisher:    $publisher ?? new InMemoryPublisher(),
            consumerName: 'test-orchestrator'
        );
    }

    /**
     * @param array<string,mixed> $contract
     * @param array<int,string> $expected
     */
    private function assertContractFunctionNames(array $contract, array $expected): void
    {
        $this->assertSame($expected, $contract['required_function_names']);
        $this->assertSame($expected, array_column($contract['function_declarations'], 'name'));
    }
}

// ─── Stubs ────────────────────────────────────────────────────────────────────

final class StubAuditDataService extends AuditDataService
{
    /** @var array<int,string> */
    public array $requestedDisIds = [];

    /**
     * @param array<string,mixed>            $dispensation
     * @param array<int,array<string,mixed>> $clientDocuments
     * @param array<string,mixed>            $auditConfig
     * @param array<int,array<string,mixed>> $attachments
     */
    public function __construct(
        private array $dispensation,
        private array $clientDocuments,
        private array $auditConfig,
        private array $attachments,
    ) {
    }

    public function getDispensation(array $filters): array
    {
        if (isset($filters['dis_id'])) {
            $this->requestedDisIds[] = $filters['dis_id'];
        }
        return $this->dispensation;
    }

    public function getAuditConfig(string $nitSec): array
    {
        return $this->auditConfig;
    }

    public function getClientDocuments(string $nitSec): array
    {
        return $this->clientDocuments;
    }

    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        return $this->attachments;
    }
}

final class RecordingStateStore extends AuditStateStore
{
    public int $docsTotal = 0;
    public bool $patchAuditResult = true;
    public bool $setAuditDocumentsTotalResult = true;
    public bool $registerDocumentResult = true;
    /** @var array<int,array{auditId:string,documentId:string,state:array<string,mixed>}> */
    public array $registeredDocuments = [];
    /** @var array<int,array<string,mixed>> */
    public array $patches = [];
    /** @var array<int,array{auditId:string,documentId:string,patch:array<string,mixed>}> */
    public array $rejectedDocuments = [];

    public function __construct()
    {
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        if (!$this->patchAuditResult) {
            return false;
        }

        $this->patches[] = $patch;
        return true;
    }

    public function markAuditStarted(string $auditId): bool
    {
        return true;
    }

    public function setAuditDocumentsTotal(string $auditId, int $total): bool
    {
        if (!$this->setAuditDocumentsTotalResult) {
            return false;
        }

        $this->docsTotal = $total;
        return true;
    }

    public function registerDocument(string $auditId, string $documentId, array $documentState): bool
    {
        if (!$this->registerDocumentResult) {
            return false;
        }

        $this->registeredDocuments[] = [
            'auditId'    => $auditId,
            'documentId' => $documentId,
            'state'      => $documentState,
        ];
        return true;
    }

    public function markDocumentRejected(string $auditId, string $documentId, array $patch = []): bool
    {
        $this->rejectedDocuments[] = [
            'auditId' => $auditId,
            'documentId' => $documentId,
            'patch' => $patch,
        ];
        return true;
    }
}

final class InMemoryPublisher extends AuditEventPublisher
{
    /** @var array<int,AuditEvent> */
    public array $published = [];

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
        $this->published[] = $event;
        return 'dlq-' . count($this->published);
    }
}
