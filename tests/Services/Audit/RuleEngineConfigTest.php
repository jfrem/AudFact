<?php

namespace Tests\Services\Audit;

use App\Services\Audit\FieldClassifier;
use App\Services\Audit\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineConfigTest extends TestCase
{
    public function testSemanticResultsAreScopedByDocumentAndField(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                [
                    'IPS' => 'IPS CENTRO CLINICO Y DE INVESTIGACION SICOR SAS',
                ],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [
                        'IPS' => 'IPS CENTRO CLINICO Y DE INVESTIGACION SICOR SAS',
                    ],
                ],
                [
                    'type' => 'AUTORIZACION',
                    'fields' => [
                        'IPS' => 'DISCOLMETS SAS',
                    ],
                ],
            ],
            [],
            [
                [
                    'field' => 'IPS',
                    'document' => 'ACTA_DE_ENTREGA',
                    'fdvValue' => 'IPS CENTRO CLINICO Y DE INVESTIGACION SICOR SAS',
                    'docValue' => 'IPS CENTRO CLINICO Y DE INVESTIGACION SICOR SAS',
                    'similarity' => 1.0,
                    'threshold' => 0.8,
                    'match' => true,
                ],
                [
                    'field' => 'IPS',
                    'document' => 'AUTORIZACION',
                    'fdvValue' => 'IPS CENTRO CLINICO Y DE INVESTIGACION SICOR SAS',
                    'docValue' => 'DISCOLMETS SAS',
                    'similarity' => 0.42,
                    'threshold' => 0.8,
                    'match' => false,
                ],
            ],
            [
                'nitSec' => '2426',
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => [['field' => 'IPS', 'severity' => 'MEDIA']],
                        'visualChecks' => [],
                    ],
                    'AUTORIZACION' => [
                        'fields' => [['field' => 'IPS', 'severity' => 'CRITICO']],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $this->assertSame('COINCIDE', $result['data']['items'][0]['resultado']);
        $this->assertSame('ACTA_DE_ENTREGA', $result['data']['items'][0]['documento']);
        $this->assertSame('VALOR_DISTINTO', $result['data']['items'][1]['resultado']);
        $this->assertSame('AUTORIZACION', $result['data']['items'][1]['documento']);
        $this->assertStringContainsString('DISCOLMETS SAS', $result['data']['items'][1]['detalle']);
        $this->assertSame('alta', $result['data']['items'][1]['severidad']);
    }

    public function testVisualChecksUseConfiguredSeverityWithoutFdvValue(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [[]],
            [
                [
                    'type' => 'FORMULA_MEDICA',
                    'fields' => [],
                ],
            ],
            [
                'FirmaPrescriptor' => [
                    'present' => true,
                    'confidence' => 'ALTA',
                    'evidence' => 'Firma visible en la formula',
                ],
            ],
            [],
            [
                'nitSec' => '2426',
                'documents' => [
                    'FORMULA_MEDICA' => [
                        'fields' => [],
                        'visualChecks' => [
                            [
                                'check' => 'FirmaPrescriptor',
                                'severity' => 'MENOR',
                            ],
                        ],
                    ],
                ],
            ],
            $classifier
        );

        $item = $result['data']['items'][0];

        $this->assertSame('FirmaPrescriptor', $item['campo']);
        $this->assertSame('COINCIDE', $item['resultado']);
        $this->assertSame('baja', $item['severidad']);
        $this->assertSame('client_audit_config', $result['config_used']['audit_config']['source']);
        $this->assertSame('2426', $result['config_used']['audit_config']['nitSec']);
        $this->assertSame(1, $result['config_used']['audit_config']['visual_check_count']);
        $this->assertNotEmpty($result['config_used']['audit_config']['config_hash']);
    }

    public function testFechaVencimientoIsAggregatedPerDispensedItem(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                ['FechaVencimiento' => '2026-05-31'],
                ['FechaVencimiento' => '2026-07-31'],
            ],
            [
                [
                    'type' => 'FACTURA',
                    'fields' => [
                        'FechaVencimiento' => '2026-05-31, 2026-07-31',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'FACTURA' => [
                        'fields' => ['FechaVencimiento'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $item = $result['data']['items'][0];

        $this->assertSame('2026-05-31, 2026-07-31', $item['valorFuenteVerdad']);
        $this->assertSame('COINCIDE', $item['resultado']);
    }

    public function testFechaVencimientoListMatchesEquivalentDocumentFormats(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                ['FechaVencimiento' => '2029-03-30'],
                ['FechaVencimiento' => '2030-05-30'],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [
                        'FechaVencimiento' => '30/03/2029, 30/05/2030',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => ['FechaVencimiento'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $item = $result['data']['items'][0];

        $this->assertSame('2029-03-30, 2030-05-30', $item['valorFuenteVerdad']);
        $this->assertSame('30/03/2029, 30/05/2030', $item['valorDocumento']);
        $this->assertSame('COINCIDE', $item['resultado']);
    }

    public function testFechaVencimientoListDetectsDifferentDate(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                ['FechaVencimiento' => '2029-03-30'],
                ['FechaVencimiento' => '2030-05-30'],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [
                        'FechaVencimiento' => '30/03/2029, 31/05/2030',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => ['FechaVencimiento'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $item = $result['data']['items'][0];

        $this->assertSame('VALOR_DISTINTO', $item['resultado']);
        $this->assertStringContainsString('2030-05-30', $item['detalle']);
        $this->assertStringContainsString('31/05/2030', $item['detalle']);
    }

    public function testFechaVencimientoListMatchesSlashSeparatedDocumentValues(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                ['FechaVencimiento' => '2029-03-30'],
                ['FechaVencimiento' => '2030-05-30'],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [
                        'FechaVencimiento' => '30/03/2029 / 30/05/2030',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => ['FechaVencimiento'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $this->assertSame('COINCIDE', $result['data']['items'][0]['resultado']);
    }

    public function testLoteListMatchesSlashSeparatedDocumentValues(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                ['Lote' => '02041804-25'],
                ['Lote' => '02041806-25'],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [
                        'Lote' => '02041804-25 / 02041806-25',
                    ],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => ['Lote'],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $this->assertSame('COINCIDE', $result['data']['items'][0]['resultado']);
    }

    public function testMissingConfiguredFieldCountsAsDiscrepancyAndRisk(): void
    {
        $engine = new RuleEngine();
        $classifier = new FieldClassifier();

        $result = $engine->evaluate(
            [
                [
                    'Tipo' => 'POS',
                ],
            ],
            [
                [
                    'type' => 'ACTA_DE_ENTREGA',
                    'fields' => [],
                ],
            ],
            [],
            [],
            [
                'documents' => [
                    'ACTA_DE_ENTREGA' => [
                        'fields' => [
                            [
                                'field' => 'Tipo',
                                'severity' => 'MEDIA',
                            ],
                        ],
                        'visualChecks' => [],
                    ],
                ],
            ],
            $classifier
        );

        $this->assertSame('NO_ENCONTRADO', $result['data']['items'][0]['resultado']);
        $this->assertSame(1, $result['metrics']['TotalDiscrepancias']);
        $this->assertSame(1, $result['metrics']['Medias']);
        $this->assertSame(5, $result['risk_score']);
    }
}
