<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentAuditOrchestrator;
use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DocumentAuditOrchestratorServiceTypeFilterTest extends TestCase
{
    private DocumentAuditOrchestrator $orchestrator;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new DocumentAuditOrchestrator(
            null,
            null,
            new DocumentExtractionContractBuilder(),
            null,
            null,
            null,
            'test-orchestrator'
        );
        $this->reflection = new ReflectionClass($this->orchestrator);
    }

    public function testResolveServiceTypeExtractsMipresFromItems(): void
    {
        $method = $this->reflection->getMethod('resolveServiceType');
        $method->setAccessible(true);

        $fuenteVerdad = [
            'header' => ['NumeroFactura' => 'T71260801307'],
            'items' => [
                ['Tipo' => 'MIPRES', 'Mipres' => '20260507172001157676'],
            ],
        ];

        $serviceType = $method->invoke($this->orchestrator, $fuenteVerdad);
        $this->assertSame('MIPRES', $serviceType);
    }

    public function testResolveServiceTypeExtractsPosFromItems(): void
    {
        $method = $this->reflection->getMethod('resolveServiceType');
        $method->setAccessible(true);

        $fuenteVerdad = [
            'header' => ['NumeroFactura' => 'X08260600449'],
            'items' => [
                ['Tipo' => 'POS'],
            ],
        ];

        $serviceType = $method->invoke($this->orchestrator, $fuenteVerdad);
        $this->assertSame('POS', $serviceType);
    }

    public function testResolveServiceTypeDefaultsToTodosWhenEmpty(): void
    {
        $method = $this->reflection->getMethod('resolveServiceType');
        $method->setAccessible(true);

        $fuenteVerdad = [
            'header' => ['NumeroFactura' => 'X08260600449'],
            'items' => [],
        ];

        $serviceType = $method->invoke($this->orchestrator, $fuenteVerdad);
        $this->assertSame('TODOS', $serviceType);
    }

    public function testBuildConfiguredDocumentsFiltersPosOnlyCheckOnMipresDelivery(): void
    {
        $method = $this->reflection->getMethod('buildConfiguredDocuments');
        $method->setAccessible(true);

        $auditConfig = [
            'documents' => [
                'FORMULA MEDICA' => [
                    'docId' => 3,
                    'fields' => [
                        [
                            'campoNombre' => 'NombrePaciente',
                            'tipoDato' => 'person_name',
                            'aplicaServicio' => 'TODOS',
                        ],
                    ],
                    'visualChecks' => [
                        [
                            'check' => 'FirmaPrescriptor',
                            'description' => 'Verificar firma prescriptor',
                            'severity' => 'ALTA',
                            'codigoCampo' => 'FPRE',
                            'aplicaServicio' => 'POS',
                        ],
                    ],
                ],
            ],
        ];

        $fuenteVerdadMipres = [
            'header' => ['NumeroFactura' => 'T71260801307'],
            'items' => [
                ['Tipo' => 'MIPRES', 'Mipres' => '20260507172001157676'],
            ],
        ];

        $configured = $method->invoke($this->orchestrator, $auditConfig, $fuenteVerdadMipres);

        $this->assertCount(1, $configured);
        $formulaDoc = $configured[0];
        $this->assertSame('FORMULA MEDICA', $formulaDoc['document_name']);

        // In MIPRES delivery, FirmaPrescriptor (aplicaServicio=POS) MUST be filtered out
        $this->assertEmpty($formulaDoc['visual_checks']);
        $this->assertCount(1, $formulaDoc['fields']);
        $this->assertSame('NombrePaciente', $formulaDoc['fields'][0]['campoNombre']);

        // Gemini extraction contract schema must NOT include visual_checks
        $contract = $formulaDoc['extraction_contract'];
        $this->assertArrayHasKey('response_schema', $contract);
        $this->assertArrayNotHasKey('visual_checks', $contract['response_schema']['properties'] ?? []);
    }

    public function testBuildConfiguredDocumentsPreservesPosOnlyCheckOnPosDelivery(): void
    {
        $method = $this->reflection->getMethod('buildConfiguredDocuments');
        $method->setAccessible(true);

        $auditConfig = [
            'documents' => [
                'FORMULA MEDICA' => [
                    'docId' => 3,
                    'fields' => [
                        [
                            'campoNombre' => 'NombrePaciente',
                            'tipoDato' => 'person_name',
                            'aplicaServicio' => 'TODOS',
                        ],
                    ],
                    'visualChecks' => [
                        [
                            'check' => 'FirmaPrescriptor',
                            'description' => 'Verificar firma prescriptor',
                            'severity' => 'ALTA',
                            'codigoCampo' => 'FPRE',
                            'aplicaServicio' => 'POS',
                        ],
                    ],
                ],
            ],
        ];

        $fuenteVerdadPos = [
            'header' => ['NumeroFactura' => 'X08260600449'],
            'items' => [
                ['Tipo' => 'POS'],
            ],
        ];

        $configured = $method->invoke($this->orchestrator, $auditConfig, $fuenteVerdadPos);

        $this->assertCount(1, $configured);
        $formulaDoc = $configured[0];

        // In POS delivery, FirmaPrescriptor (aplicaServicio=POS) MUST be preserved
        $this->assertCount(1, $formulaDoc['visual_checks']);
        $this->assertSame('FirmaPrescriptor', $formulaDoc['visual_checks'][0]['check']);
        $this->assertSame('POS', $formulaDoc['visual_checks'][0]['aplicaServicio']);

        // Gemini extraction contract schema MUST include visual_checks with FirmaPrescriptor
        $contract = $formulaDoc['extraction_contract'];
        $this->assertArrayHasKey('response_schema', $contract);
        $this->assertArrayHasKey('visual_checks', $contract['response_schema']['properties'] ?? []);

        $checkEnum = $contract['response_schema']['properties']['visual_checks']['items']['properties']['check']['enum'] ?? [];
        $this->assertContains('FirmaPrescriptor', $checkEnum);
    }
}
