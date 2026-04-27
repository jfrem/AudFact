<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditEventPublisher;
use App\Services\Audit\Pipeline\AuditStateStore;
use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\InternalAuditApiClient;
use App\Services\Audit\Pipeline\SchemaBuilder;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentAuditOrchestratorTest extends TestCase
{
    public function testAuditCreatedFor2426PublishesThreeDocumentRegisteredEventsWithContractPayload(): void
    {
        $publisher = new InMemoryPublisher();
        $store = new RecordingStateStore();
        $redis = $this->createMock(RedisClient::class);
        $apiClient = new StubInternalAuditApiClient(
            dispensation: [
                'header' => [
                    'NitSec' => '2426',
                    'FacSec' => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [
                    ['NombreArticulo' => 'ITEM A'],
                ],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE', 'NitMedDocNom' => 'DISPENSA'],
                ['NitMedDocId' => 2, 'NitMedDocCodAlt' => 'AUT', 'NitMedDocNom' => 'AUTORIZACION'],
                ['NitMedDocId' => 3, 'NitMedDocCodAlt' => 'FORM', 'NitMedDocNom' => 'FORMULA MEDICA'],
            ],
            auditConfig: [
                'nitSec' => '2426',
                'activo' => true,
                'systemPrompt' => null,
                'documents' => [
                    'DISPENSA' => [
                        'docId' => 1,
                        'fields' => ['DocumentoPaciente'],
                        'visualChecks' => [[
                            'check' => 'FirmaActaEntrega',
                            'description' => 'Firma',
                            'severity' => 'CRITICO',
                        ]],
                    ],
                    'AUTORIZACION' => [
                        'docId' => 2,
                        'fields' => ['NumeroAutorizacion'],
                        'visualChecks' => [],
                    ],
                    'FORMULA MEDICA' => [
                        'docId' => 3,
                        'fields' => ['NombreArticulo'],
                        'visualChecks' => [[
                            'check' => 'FirmaPrescriptor',
                            'description' => 'Firma médico',
                            'severity' => 'CRITICO',
                        ]],
                    ],
                ],
            ],
            attachments: [
                ['id_documento' => '1', 'nombre_documento' => 'DISPENSA', 'nombre_alternativo' => 'ANE', 'TipoAlmacenamiento' => 'URL'],
                ['id_documento' => '2', 'nombre_documento' => 'AUTORIZACION', 'nombre_alternativo' => 'AUT', 'TipoAlmacenamiento' => 'URL'],
                ['id_documento' => '3', 'nombre_documento' => 'FORMULA MEDICA', 'nombre_alternativo' => 'FORM', 'TipoAlmacenamiento' => 'URL'],
            ],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore: $store,
            apiClient: $apiClient,
            schemaBuilder: new SchemaBuilder(),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547']
        );

        $orchestrator->processEvent($event);

        $this->assertSame(3, $store->docsTotal);
        $this->assertCount(3, $store->registeredDocuments);
        $this->assertCount(3, $publisher->published);
        $this->assertSame('/dispensation/T38250701547', $apiClient->calledPaths[0] ?? null);
        $this->assertSame('/clients/2426/audit-config', $apiClient->calledPaths[1] ?? null);
        $this->assertSame('/clients/2426/documents', $apiClient->calledPaths[2] ?? null);
        $this->assertSame('/dispensation/T38250701547/attachments/2426', $apiClient->calledPaths[3] ?? null);

        $payload = $publisher->published[0]->payload;
        $this->assertSame('DISPENSA', $payload['tipo_documento']);
        $this->assertSame('ANE', $payload['nombre_alternativo']);
        $this->assertSame('/dispensation/T38250701547/attachments/download/1', $payload['download_url']);
        $this->assertSame('URL', $payload['tipo_almacenamiento']);
        $this->assertIsArray($payload['extraction_schema']);
        $this->assertSame('extract_document_data', $payload['extraction_schema']['name']);
        $this->assertSame(
            ['fields', 'visual_checks', 'document_quality'],
            $payload['extraction_schema']['parameters']['required']
        );
        $this->assertSame(
            'string',
            $payload['extraction_schema']['parameters']['properties']['fields']['properties']['DocumentoPaciente']['type']
        );
        $this->assertSame(
            ['legible', 'parcialmente_legible', 'ilegible'],
            $payload['extraction_schema']['parameters']['properties']['document_quality']['enum']
        );
        $this->assertSame(
            ['check', 'presente'],
            $payload['extraction_schema']['parameters']['properties']['visual_checks']['items']['required']
        );
        $this->assertArrayNotHasKey(
            'additionalProperties',
            $payload['extraction_schema']['parameters']['properties']['fields']
        );
        $this->assertIsArray($payload['visual_checks']);
        $this->assertArrayHasKey('header', $payload['fuente_verdad']);
        $this->assertNull($payload['system_prompt']);
        $this->assertSame('T38250701547', $payload['dis_det_nro']);
        $this->assertSame('T38250701547', $payload['numero_factura']);
        $this->assertSame('T38250701547', $store->patches[0]['numero_factura'] ?? null);
        $this->assertArrayNotHasKey('dis_det_nro', $store->patches[0] ?? []);
    }

    public function testFallbackMatchesAttachmentByNormalizedName(): void
    {
        $publisher = new InMemoryPublisher();
        $store = new RecordingStateStore();
        $redis = $this->createMock(RedisClient::class);
        $apiClient = new StubInternalAuditApiClient(
            dispensation: [
                'header' => [
                    'NitSec' => '1165',
                    'FacSec' => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 3, 'NitMedDocCodAlt' => 'FORM', 'NitMedDocNom' => 'FORMULA MEDICA'],
            ],
            auditConfig: [
                'nitSec' => '1165',
                'activo' => true,
                'systemPrompt' => 'prompt-fixture',
                'documents' => [
                    'FORMULA_MEDICA' => [
                        'docId' => 3,
                        'fields' => ['FirmaActaEntrega'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            attachments: [
                ['id_documento' => '99', 'nombre_documento' => 'FORMULA MEDICA', 'nombre_alternativo' => 'FORM', 'TipoAlmacenamiento' => 'BLOB'],
            ],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore: $store,
            apiClient: $apiClient,
            schemaBuilder: new SchemaBuilder(),
            redis: $redis,
            publisher: $publisher,
            consumerName: 'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547']
        );

        $orchestrator->processEvent($event);

        $this->assertCount(1, $publisher->published);
        $this->assertSame('FORMULA_MEDICA', $publisher->published[0]->payload['tipo_documento']);
        $this->assertSame('99', $publisher->published[0]->payload['attachment_id']);
        $this->assertSame('prompt-fixture', $publisher->published[0]->payload['system_prompt']);
    }

    public function testMissingRequiredAttachmentThrowsRuntimeException(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $apiClient = new StubInternalAuditApiClient(
            dispensation: [
                'header' => [
                    'NitSec' => '2426',
                    'FacSec' => '87723098',
                    'NumeroFactura' => 'T38250701547',
                ],
                'items' => [],
            ],
            clientDocuments: [
                ['NitMedDocId' => 1, 'NitMedDocCodAlt' => 'ANE', 'NitMedDocNom' => 'DISPENSA'],
            ],
            auditConfig: [
                'nitSec' => '2426',
                'activo' => true,
                'systemPrompt' => null,
                'documents' => [
                    'DISPENSA' => [
                        'docId' => 1,
                        'fields' => ['DocumentoPaciente'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            attachments: [],
        );

        $orchestrator = new DocumentAuditOrchestrator(
            stateStore: new RecordingStateStore(),
            apiClient: $apiClient,
            schemaBuilder: new SchemaBuilder(),
            redis: $redis,
            publisher: new InMemoryPublisher(),
            consumerName: 'test-orchestrator'
        );

        $event = AuditEvent::create(
            eventType: AuditEvent::TYPE_AUDIT_CREATED,
            auditId: AuditEvent::uuidV4(),
            payload: ['dis_det_nro' => 'T38250701547']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Adjuntos no encontrados');
        $orchestrator->processEvent($event);
    }
}

final class StubInternalAuditApiClient extends InternalAuditApiClient
{
    /** @var array<int,string> */
    public array $calledPaths = [];

    /**
     * @param array<string,mixed> $dispensation
     * @param array<int,array<string,mixed>> $clientDocuments
     * @param array<string,mixed> $auditConfig
     * @param array<int,array<string,mixed>> $attachments
     */
    public function __construct(
        private array $dispensation,
        private array $clientDocuments,
        private array $auditConfig,
        private array $attachments,
    ) {
    }

    public function getDispensation(string $disDetNro): array
    {
        $this->calledPaths[] = '/dispensation/' . rawurlencode($disDetNro);
        return $this->dispensation;
    }

    public function getClientDocuments(string $clientId): array
    {
        $this->calledPaths[] = '/clients/' . rawurlencode($clientId) . '/documents';
        return $this->clientDocuments;
    }

    public function getAuditConfig(string $clientId): array
    {
        $this->calledPaths[] = '/clients/' . rawurlencode($clientId) . '/audit-config';
        return $this->auditConfig;
    }

    public function getAttachments(string $disDetNro, string $nitSec): array
    {
        $this->calledPaths[] = '/dispensation/' . rawurlencode($disDetNro) . '/attachments/' . rawurlencode($nitSec);
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

    public function setAuditDocumentsTotal(string $auditId, int $total): bool
    {
        $this->docsTotal = $total;
        return true;
    }

    public function registerDocument(string $auditId, string $documentId, array $documentState): bool
    {
        $this->registeredDocuments[] = [
            'auditId' => $auditId,
            'documentId' => $documentId,
            'state' => $documentState,
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
