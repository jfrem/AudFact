<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Events\DocumentPolicyEngine;
use PHPUnit\Framework\TestCase;

final class DocumentPolicyEngineTest extends TestCase
{
    public function testEvaluateBuildsFindingsAndDocumentDecision(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'DISPENSA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'Autorizacion' => ['type' => 'string'],
                                    'CantidadEntregada' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'visual_checks' => [
                    ['check' => 'FirmaActaEntrega', 'severity' => 'CRITICO'],
                ],
                'fuente_verdad' => [
                    'header' => [
                        'NumeroAutorizacion' => '46338218',
                    ],
                    'items' => [
                        ['CantidadEntregada' => '20'],
                        ['CantidadEntregada' => '30'],
                    ],
                ],
            ],
            [
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => [
                    'Autorizacion' => '46338218',
                ],
                'items_normalized' => [
                    ['CantidadEntregada' => '20'],
                    ['CantidadEntregada' => '30'],
                ],
                'visual_checks_resultado' => [
                    ['check' => 'FirmaActaEntrega', 'presente' => true, 'detalle' => 'Firma visible', 'severidad' => 'CRITICO'],
                ],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame('DISPENSA', $result['document_name']);
        $this->assertSame(3, $result['hallazgos']['metrics']['total_campos']);
        $this->assertSame(3, $result['hallazgos']['metrics']['coincidencias']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateMarksInconclusiveWhenDocumentQualityIsNotLegible(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'FORMULA_MEDICA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'CodigoDiagnostico' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => ['CodigoDiagnostico' => 'S127'],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'FORMULA_MEDICA',
                'fields_normalized' => [],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'parcialmente_legible',
            ]
        );

        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertFalse($result['document_decision']['approved']);
    }

    public function testEvaluateSkipsAuthorizationFieldInFormulaMedica(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'NumeroAutorizacion' => ['type' => 'string'],
                                    'NumeroIdentificacion' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [
                        'NumeroAutorizacion' => '46338218',
                        'DocumentoPaciente' => '12132213',
                    ],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'fields_normalized' => [
                    'NumeroAutorizacion' => '45082636',
                    'NumeroIdentificacion' => '12132213',
                ],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('DocumentoPaciente', $result['hallazgos']['items'][0]['campo']);
    }

    public function testEvaluateMarksFormulaDiagnosticAsInconclusiveWhenMissing(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'CodigoDiagnostico' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => ['CodigoDiagnostico' => 'S127'],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'fields_normalized' => [
                    'CodigoDiagnostico' => 'null',
                ],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertStringContainsString('codigo diagnostico', (string) $result['hallazgos']['items'][0]['detalle']);
    }

    public function testEvaluateMatchesPatientNameWhenTokensOnlyChangeOrder(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'AUTORIZACION',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'NombrePaciente' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => ['NombrePaciente' => 'GARCIA ABSALON'],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'AUTORIZACION',
                'fields_normalized' => [
                    'NombrePaciente' => 'ABSALON GARCIA',
                ],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame([], $result['hallazgos']['items']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateSkipsCantidadPrescritaWhenAuthorizationExists(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'CantidadPrescrita' => ['type' => 'string'],
                                    'NumeroIdentificacion' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [
                        'NumeroAutorizacion' => '46338218',
                        'DocumentoPaciente' => '12132213',
                    ],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'fields_normalized' => [
                    'NumeroIdentificacion' => '12132213',
                ],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertCount(1, $result['hallazgos']['items']);
        $this->assertSame('DocumentoPaciente', $result['hallazgos']['items'][0]['campo']);
    }

    public function testEvaluateSkipsNonDeterministicMultiItemFieldsInDispensa(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'DISPENSA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'Lote' => ['type' => 'string'],
                                    'FechaVencimiento' => ['type' => 'string'],
                                    'NombreArticulo' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [],
                    'items' => [
                        ['Lote' => '02041804-25', 'FechaVencimiento' => '2029-03-30', 'NombreArticulo' => 'GASA ESTERIL'],
                        ['Lote' => '02041806-25', 'FechaVencimiento' => '2030-05-30', 'NombreArticulo' => 'GASA ESTERIL'],
                    ],
                ],
            ],
            [
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => [],
                'items_normalized' => [
                    ['Lote' => '02041804-25', 'FechaVencimiento' => '2029-03-30', 'NombreArticulo' => 'GASA ESTERIL'],
                    ['Lote' => '02041806-25', 'FechaVencimiento' => '2030-05-30', 'NombreArticulo' => 'GASA ESTERIL'],
                ],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $fields = array_column($result['hallazgos']['items'], 'campo');

        $this->assertSame(['NombreArticulo'], $fields);
        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
    }

    public function testEvaluateSkipsFormulaProductWhenItIsOutsideAuthorityMatrix(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'NombreArticulo' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [
                        'NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
                    ],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'FORMULA MEDICA',
                'fields_normalized' => [],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame([], $result['hallazgos']['items']);
        $this->assertTrue($result['document_decision']['approved']);
    }

    public function testEvaluateKeepsAuthorizationProductAsInconclusiveWhenSimilarityIsInsufficient(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'AUTORIZACION',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'NombreArticulo' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [
                        'NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
                    ],
                    'items' => [],
                ],
            ],
            [
                'tipo_documento' => 'AUTORIZACION',
                'fields_normalized' => [
                    'NombreArticulo' => 'Cureband premium gasa antiadherente estéril 7.5cm x 7.5cm',
                ],
                'items_normalized' => [],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame('NO_CONCLUYENTE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertStringContainsString('Similitud', (string) $result['hallazgos']['items'][0]['detalle']);
    }

    public function testEvaluateMatchesDispensaArticleWhenFdvNameIsContainedWithinExtendedText(): void
    {
        $engine = new DocumentPolicyEngine();

        $result = $engine->evaluate(
            [
                'tipo_documento' => 'DISPENSA',
                'extraction_schema' => [
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'NombreArticulo' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'fuente_verdad' => [
                    'header' => [],
                    'items' => [[
                        'NombreArticulo' => 'GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5',
                    ]],
                ],
            ],
            [
                'tipo_documento' => 'DISPENSA',
                'fields_normalized' => [],
                'items_normalized' => [[
                    'NombreArticulo' => '20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5 -- INV:2018DM-0018580',
                ]],
                'visual_checks_resultado' => [],
                'document_quality' => 'legible',
            ]
        );

        $this->assertSame('COINCIDE', $result['hallazgos']['items'][0]['resultado']);
        $this->assertTrue($result['document_decision']['approved']);
    }
}
