<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDataService;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentAuditOrchestratorTest extends TestCase
{
    public function testAuditCreatedFor2426PublishesThreeDocumentRegisteredEventsWithContractPayload(): void
    {
        $publisher  = new InMemoryPublisher();
        $store      = new RecordingStateStore();
        $redis      = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '2426',
                    'FacSec'        => '87723098',
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
                ['id_documento' => '1', 'nombre_documento' => 'DISPENSA',       'nombre_alternativo' => 'ANE',  'TipoAlmacenamiento' => 'URL'],
                ['id_documento' => '2', 'nombre_documento' => 'AUTORIZACION',   'nombre_alternativo' => 'AUT',  'TipoAlmacenamiento' => 'URL'],
                ['id_documento' => '3', 'nombre_documento' => 'FORMULA MEDICA', 'nombre_alternativo' => 'FORM', 'TipoAlmacenamiento' => 'URL'],
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
                'fac_sec' => '87723098',
                'source' => 'batch',
            ]
        );

        $orchestrator->processEvent($event);

        $this->assertSame(['87723098'], $dataService->requestedFacSecs);

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
        $this->assertSame('87723098', $payload['fac_sec']);
        $this->assertSame('2426', $payload['fac_nit_sec']);
        $this->assertSame('87723098', $store->patches[0]['fac_sec'] ?? null);
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
        $publisher   = new InMemoryPublisher();
        $store       = new RecordingStateStore();
        $redis       = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '1165',
                    'FacSec'        => '87723098',
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
                ['id_documento' => '99', 'nombre_documento' => 'FORMULA MEDICA', 'nombre_alternativo' => 'FORM', 'TipoAlmacenamiento' => 'BLOB'],
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
                'fac_sec' => '87723098',
            ]
        );

        $orchestrator->processEvent($event);

        $this->assertCount(1, $publisher->published);
        $this->assertSame('FORMULA_MEDICA', $publisher->published[0]->payload['tipo_documento']);
        $this->assertSame('99', $publisher->published[0]->payload['attachment_id']);
        $this->assertSame('prompt-fixture', $publisher->published[0]->payload['system_prompt']);
    }

    public function testMissingRequiredAttachmentThrowsRuntimeException(): void
    {
        $redis       = $this->createMock(RedisClient::class);
        $dataService = new StubAuditDataService(
            dispensation: [
                'header' => [
                    'NitSec'        => '2426',
                    'FacSec'        => '87723098',
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
            stateStore:    new RecordingStateStore(),
            dataService:   $dataService,

            redis:         $redis,
            publisher:     new InMemoryPublisher(),
            consumerName:  'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_sec' => '87723098',
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adjuntos no encontrados');
        $orchestrator->processEvent($event);
    }

    public function testMissingFacSecThrowsRuntimeException(): void
    {
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService());

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   ['dis_det_nro' => 'T38250701547']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit_created sin fac_sec');
        $orchestrator->processEvent($event);
    }

    public function testBatchFacSecMismatchThrowsIdentityMismatch(): void
    {
        $orchestrator = $this->makeOrchestrator($this->makeSingleDocumentDataService());

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId:   AuditEvent::uuidV4(),
            payload:   [
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'fac_sec' => 'LEGACY-FACSEC',
                'source' => 'batch',
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_IDENTITY_MISMATCH: payload.fac_sec');
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
                'fac_sec' => '87723098',
                'source' => 'batch',
            ]
        );

        $this->expectException(RuntimeException::class);
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
                    'FacSec'        => '87723098',
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
                ['id_documento' => '1', 'nombre_documento' => 'DISPENSA', 'nombre_alternativo' => 'ANE', 'TipoAlmacenamiento' => 'URL'],
            ],
        );
    }

    private function makeOrchestrator(StubAuditDataService $dataService): DocumentAuditOrchestrator
    {
        return new DocumentAuditOrchestrator(
            stateStore:   new RecordingStateStore(),
            dataService:  $dataService,
            redis:        $this->createMock(RedisClient::class),
            publisher:    new InMemoryPublisher(),
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
    public array $requestedFacSecs = [];

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

    public function getDispensationByFacSec(string $facSec): array
    {
        $this->requestedFacSecs[] = $facSec;
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
    /** @var array<int,array{auditId:string,documentId:string,state:array<string,mixed>}> */
    public array $registeredDocuments = [];
    /** @var array<int,array<string,mixed>> */
    public array $patches = [];

    public function __construct()
    {
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $this->patches[] = $patch;
        return true;
    }

    public function markAuditStarted(string $auditId): bool
    {
        return true;
    }

    public function setAuditDocumentsTotal(string $auditId, int $total): bool
    {
        $this->docsTotal = $total;
        return true;
    }

    public function registerDocument(string $auditId, string $documentId, array $documentState): bool
    {
        $this->registeredDocuments[] = [
            'auditId'    => $auditId,
            'documentId' => $documentId,
            'state'      => $documentState,
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
