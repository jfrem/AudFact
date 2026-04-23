<?php

namespace Tests\Services\Audit;

use App\Services\Audit\AuditOrchestrator;
use App\Services\Audit\ExtractionPromptBuilder;
use App\Services\Audit\ExtractionResponseSchema;
use App\Services\Audit\FieldClassifier;
use App\Services\Audit\RuleEngine;
use PHPUnit\Framework\TestCase;

class ExtractionContractTest extends TestCase
{
    public function testDocumentFieldRequirementsPreserveConfiguredDocumentScope(): void
    {
        $builder = new ExtractionPromptBuilder();
        $requirements = $builder->resolveDocumentFieldRequirements($this->auditConfig());

        $this->assertArrayHasKey('ACTA_DE_ENTREGA', $requirements);
        $this->assertArrayHasKey('FORMULA_MEDICA', $requirements);
        $this->assertSame('DISPENSA', $requirements['ACTA_DE_ENTREGA']['sourceLabel']);
        $this->assertSame(['NombreArticulo', 'CantidadEntregada'], $requirements['ACTA_DE_ENTREGA']['fields']);
        $this->assertSame(['NombreArticulo', 'CantidadPrescrita'], $requirements['FORMULA_MEDICA']['fields']);
    }

    public function testPromptEmitsDocumentScopedBlocks(): void
    {
        $builder = new ExtractionPromptBuilder();
        $prompt = $builder->buildUserPrompt($this->auditConfig(), [], ['DISPENSA', 'FORMULA MEDICA']);

        $this->assertStringContainsString('Documento ACTA_DE_ENTREGA ("DISPENSA"):', $prompt);
        $this->assertStringContainsString('Documento FORMULA_MEDICA ("FORMULA MEDICA"):', $prompt);
        $this->assertStringContainsString('No uses valores de un documento para completar otro.', $prompt);
        $this->assertStringNotContainsString('Extrae los siguientes campos de cada documento donde aparezcan', $prompt);
    }

    public function testSchemaDescriptionIncludesDocumentRequirements(): void
    {
        $builder = new ExtractionPromptBuilder();
        $requirements = $builder->resolveDocumentFieldRequirements($this->auditConfig());
        $declaration = ExtractionResponseSchema::getFunctionDeclaration(
            ['NombreArticulo', 'CantidadEntregada', 'CantidadPrescrita'],
            [],
            ['ACTA_DE_ENTREGA', 'FORMULA_MEDICA'],
            $requirements
        );

        $description = $declaration['parameters']['properties']['documents']['items']['properties']['fields']['description'];

        $this->assertStringContainsString('ACTA_DE_ENTREGA: NombreArticulo, CantidadEntregada', $description);
        $this->assertStringContainsString('FORMULA_MEDICA: NombreArticulo, CantidadPrescrita', $description);
    }

    public function testMissingExpectedKeyNormalizedAsNullIsNotFound(): void
    {
        $engine = new RuleEngine();
        $result = $engine->evaluate(
            [['NombreArticulo' => 'GASA', 'CantidadPrescrita' => '20']],
            [
                [
                    'type' => 'FORMULA_MEDICA',
                    'fields' => [
                        'NombreArticulo' => null,
                        'CantidadPrescrita' => '20',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'FORMULA_MEDICA' => [
                        'fields' => ['NombreArticulo', 'CantidadPrescrita'],
                        'visualChecks' => [],
                    ],
                ],
                '_extractionQuality' => [
                    'FORMULA_MEDICA' => [
                        'sourceLabel' => 'FORMULA MEDICA',
                        'expected' => 2,
                        'rawReturnedKeys' => 1,
                        'normalizedReturnedKeys' => 2,
                        'returnedKeys' => 2,
                        'missingKeys' => [],
                        'missingKeysFilledWithNull' => ['NombreArticulo'],
                        'nullFields' => ['NombreArticulo'],
                        'structuralErrors' => [],
                    ],
                ],
            ],
            new FieldClassifier()
        );

        $this->assertSame('NO_ENCONTRADO', $result['data']['items'][0]['resultado']);
        $this->assertSame('COINCIDE', $result['data']['items'][1]['resultado']);
        $this->assertSame(0, $result['metrics']['TotalExtraccionIncompleta']);
        $this->assertSame(1, $result['metrics']['TotalDiscrepancias']);
    }

    public function testExplicitNullExpectedKeyIsNotFound(): void
    {
        $engine = new RuleEngine();
        $result = $engine->evaluate(
            [['NombreArticulo' => 'GASA']],
            [
                [
                    'type' => 'FORMULA_MEDICA',
                    'fields' => [
                        'NombreArticulo' => null,
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'FORMULA_MEDICA' => [
                        'fields' => ['NombreArticulo'],
                        'visualChecks' => [],
                    ],
                ],
                '_extractionQuality' => [
                    'FORMULA_MEDICA' => [
                        'sourceLabel' => 'FORMULA MEDICA',
                        'expected' => 1,
                        'rawReturnedKeys' => 1,
                        'normalizedReturnedKeys' => 1,
                        'returnedKeys' => 1,
                        'missingKeys' => [],
                        'missingKeysFilledWithNull' => [],
                        'nullFields' => ['NombreArticulo'],
                        'structuralErrors' => [],
                    ],
                ],
            ],
            new FieldClassifier()
        );

        $this->assertSame('NO_ENCONTRADO', $result['data']['items'][0]['resultado']);
        $this->assertSame(1, $result['metrics']['TotalDiscrepancias']);
    }

    public function testStructuralExtractionErrorIsIncomplete(): void
    {
        $engine = new RuleEngine();
        $result = $engine->evaluate(
            [['NombreArticulo' => 'GASA']],
            [
                [
                    'type' => 'FORMULA_MEDICA',
                    'fields' => [],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'FORMULA_MEDICA' => [
                        'fields' => ['NombreArticulo'],
                        'visualChecks' => [],
                    ],
                ],
                '_extractionQuality' => [
                    'FORMULA_MEDICA' => [
                        'sourceLabel' => 'FORMULA MEDICA',
                        'expected' => 1,
                        'rawReturnedKeys' => 0,
                        'normalizedReturnedKeys' => 0,
                        'returnedKeys' => 0,
                        'missingKeys' => ['NombreArticulo'],
                        'missingKeysFilledWithNull' => [],
                        'nullFields' => [],
                        'structuralErrors' => ['missing_document'],
                    ],
                ],
            ],
            new FieldClassifier()
        );

        $this->assertSame('EXTRACCION_INCOMPLETA', $result['data']['items'][0]['resultado']);
        $this->assertSame(1, $result['metrics']['TotalExtraccionIncompleta']);
        $this->assertSame(0, $result['metrics']['TotalDiscrepancias']);
    }

    public function testStructuredItemsDeriveStableLegacyFields(): void
    {
        $orchestrator = (new \ReflectionClass(AuditOrchestrator::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(AuditOrchestrator::class))->getMethod('normalizeExtractionContract');
        $method->setAccessible(true);

        $extracted = [
            'documents' => [
                [
                    'type' => 'DISPENSA',
                    'fields' => [],
                    'header' => [
                        'Autorizacion' => '46338218',
                    ],
                    'items' => [
                        [
                            'CodigoArticulo' => 'IM01273',
                            'CodigoProducto' => '20012566-23',
                            'Lote' => '02041804-25',
                            'CantidadEntregada' => '20',
                        ],
                        [
                            'CodigoArticulo' => 'IM01273',
                            'CodigoProducto' => '20012566-23',
                            'Lote' => '02041806-25',
                            'CantidadEntregada' => '30',
                        ],
                    ],
                ],
            ],
        ];

        $requirements = [
            'ACTA_DE_ENTREGA' => [
                'sourceLabel' => 'DISPENSA',
                'fields' => ['Autorizacion', 'CodigoArticulo', 'CodigoProducto', 'Lote', 'CantidadEntregada'],
                'visualChecks' => [],
            ],
        ];

        $normalized = $method->invoke($orchestrator, $extracted, $requirements);
        $fields = $normalized['documents'][0]['fields'];

        $this->assertSame('46338218', $fields['Autorizacion']);
        $this->assertSame('IM01273', $fields['CodigoArticulo']);
        $this->assertSame('20012566-23', $fields['CodigoProducto']);
        $this->assertSame('02041804-25, 02041806-25', $fields['Lote']);
        $this->assertSame('20, 30', $fields['CantidadEntregada']);
        $this->assertSame(5, $normalized['extractionQuality']['ACTA_DE_ENTREGA']['rawReturnedKeys']);
        $this->assertSame(5, $normalized['extractionQuality']['ACTA_DE_ENTREGA']['normalizedReturnedKeys']);
    }

    private function auditConfig(): array
    {
        return [
            'documents' => [
                'DISPENSA' => [
                    'fields' => ['NombreArticulo', 'CantidadEntregada'],
                    'visualChecks' => [],
                ],
                'FORMULA MEDICA' => [
                    'fields' => ['NombreArticulo', 'CantidadPrescrita'],
                    'visualChecks' => [],
                ],
            ],
        ];
    }
}
