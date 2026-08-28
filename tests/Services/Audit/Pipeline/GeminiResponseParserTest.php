<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\GeminiResponseParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeminiResponseParserTest extends TestCase
{
    private GeminiResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GeminiResponseParser();
    }

    public function testRehydrateFieldWithValueAndNull(): void
    {
        $json = json_encode([
            'fields' => [
                'NombrePaciente' => 'JUAN PEREZ',
                'DocumentoPaciente' => null,
            ],
            'items' => [
                [
                    'NombreArticulo' => 'ACETAMINOFEN',
                    'CantidadEntregada' => 10,
                    'Lote' => null,
                ],
            ],
            'visual_checks' => [
                ['check' => 'FirmaPrescriptor', 'presente' => true, 'detalle' => 'Firma visible'],
            ],
            'document_quality' => 'legible',
            'quality_notes' => ['Documento nitido'],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [
                        ['text' => $json],
                    ],
                ],
            ]],
        ];

        $result = $this->parser->parse($response, []);

        // Campos rehidratados
        $this->assertSame('JUAN PEREZ', $result['fields']['NombrePaciente']['valor']);
        $this->assertTrue($result['fields']['NombrePaciente']['presente']);
        $this->assertSame('FOUND', $result['fields']['NombrePaciente']['estadoExtraccion']);

        $this->assertNull($result['fields']['DocumentoPaciente']['valor']);
        $this->assertFalse($result['fields']['DocumentoPaciente']['presente']);
        $this->assertSame('NOT_FOUND', $result['fields']['DocumentoPaciente']['estadoExtraccion']);

        // Items rehidratados
        $this->assertCount(1, $result['items']);
        $this->assertSame('ACETAMINOFEN', $result['items'][0]['NombreArticulo']['valor']);
        $this->assertTrue($result['items'][0]['NombreArticulo']['presente']);
        $this->assertSame('FOUND', $result['items'][0]['NombreArticulo']['estadoExtraccion']);

        $this->assertSame(10, $result['items'][0]['CantidadEntregada']['valor']);
        $this->assertTrue($result['items'][0]['CantidadEntregada']['presente']);
        $this->assertSame('FOUND', $result['items'][0]['CantidadEntregada']['estadoExtraccion']);

        $this->assertNull($result['items'][0]['Lote']['valor']);
        $this->assertFalse($result['items'][0]['Lote']['presente']);
        $this->assertSame('NOT_FOUND', $result['items'][0]['Lote']['estadoExtraccion']);

        // Visual checks y calidad
        $this->assertCount(1, $result['visual_checks']);
        $this->assertSame('FirmaPrescriptor', $result['visual_checks'][0]['check']);
        $this->assertTrue($result['visual_checks'][0]['presente']);
        $this->assertSame('legible', $result['document_quality']);
        $this->assertSame(['Documento nitido'], $result['quality_notes']);
    }

    public function testMissingCandidateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_CANDIDATE');

        $this->parser->parse(['candidates' => []], []);
    }

    public function testUnsafeFinishReasonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_UNSAFE_FINISH_REASON: SAFETY');

        $response = [
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'content' => ['parts' => [['text' => '{}']]],
            ]],
        ];

        $this->parser->parse($response, []);
    }

    public function testMissingTextResponseThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_MISSING_TEXT_RESPONSE');

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['inlineData' => []]]],
            ]],
        ];

        $this->parser->parse($response, []);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_EXTRACTION_INVALID_JSON');

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => '{invalid json...']]],
            ]],
        ];

        $this->parser->parse($response, []);
    }

    public function testInvalidDocumentQualityThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini retornó extraction payload sin document_quality');

        $json = json_encode([
            'fields' => [],
            'items' => [],
            'document_quality' => '',
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $this->parser->parse($response, []);
    }

    public function testMissingRequiredFieldFromContractThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction payload omitió el campo requerido: DocumentoPaciente');

        $json = json_encode([
            'fields' => [
                'NombrePaciente' => 'JUAN PEREZ',
                // DocumentoPaciente omitido por Gemini
            ],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'field_groups' => [
                'fields' => ['NombrePaciente', 'DocumentoPaciente'],
            ],
        ];

        $this->parser->parse($response, $contract);
    }

    public function testMissingRequiredVisualCheckFromContractThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction payload omitió el check visual requerido: SelloPrescriptor');

        $json = json_encode([
            'fields' => [],
            'items' => [],
            'visual_checks' => [
                ['check' => 'FirmaPrescriptor', 'presente' => true],
                // SelloPrescriptor omitido
            ],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'response_schema' => [
                'properties' => [
                    'visual_checks' => [
                        'items' => [
                            'properties' => [
                                'check' => [
                                    'enum' => ['FirmaPrescriptor', 'SelloPrescriptor'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->parser->parse($response, $contract);
    }

    public function testRehydrateTraceTokenMultiValueArray(): void
    {
        $json = json_encode([
            'fields' => [],
            'items' => [
                [
                    'NombreArticulo' => 'MEDICAMENTO X',
                    'Lote' => ['LOTE-A1', 'LOTE-B2'],
                ],
            ],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'field_groups' => [
                'items' => ['NombreArticulo', 'Lote'],
            ],
        ];

        $result = $this->parser->parse($response, $contract);

        $loteEvidence = $result['items'][0]['Lote'];
        $this->assertSame('LOTE-A1, LOTE-B2', $loteEvidence['valor']);
        $this->assertSame(['LOTE-A1', 'LOTE-B2'], $loteEvidence['valores']);
        $this->assertTrue($loteEvidence['presente']);
        $this->assertSame('FOUND_IN_LIST', $loteEvidence['estadoExtraccion']);
    }

    public function testRehydrateScalarDateString(): void
    {
        $json = json_encode([
            'fields' => [
                'FechaAutorizacion' => '18/08/2026 14:33:39',
            ],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $result = $this->parser->parse($response, []);

        $fechaEvidence = $result['fields']['FechaAutorizacion'];
        $this->assertSame('18/08/2026 14:33:39', $fechaEvidence['valor']);
        $this->assertSame(['18/08/2026 14:33:39'], $fechaEvidence['valores']);
        $this->assertTrue($fechaEvidence['presente']);
        $this->assertSame('FOUND', $fechaEvidence['estadoExtraccion']);
    }

    public function testMissingRequiredItemFieldFromContractThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction payload omitió el campo de ítem requerido: CantidadEntregada en posición 0');

        $json = json_encode([
            'fields' => [],
            'items' => [
                [
                    'NombreArticulo' => 'MEDICAMENTO X',
                    // CantidadEntregada omitida
                ],
            ],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'field_groups' => [
                'items' => ['NombreArticulo', 'CantidadEntregada'],
            ],
        ];

        $this->parser->parse($response, $contract);
    }

    public function testNonArrayItemThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini retornó item inválido en posición 0');

        $json = json_encode([
            'fields' => [],
            'items' => [
                'invalid_string_item',
            ],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $this->parser->parse($response, []);
    }

    public function testDocumentConformityParsedCorrectly(): void
    {
        $json = json_encode([
            'document_conformity' => [
                'matches_expected_type' => false,
                'detected_type' => 'Cédula de ciudadanía',
                'justification' => 'Es un documento de identidad',
            ],
            'fields' => [],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $result = $this->parser->parse($response, []);
        $this->assertArrayHasKey('document_conformity', $result);
        $this->assertFalse($result['document_conformity']['matches_expected_type']);
        $this->assertSame('Cédula de ciudadanía', $result['document_conformity']['detected_type']);
        $this->assertSame('Es un documento de identidad', $result['document_conformity']['justification']);
    }

    public function testMissingDocumentConformityThrowsExceptionWhenRequired(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction payload omitió la sección requerida document_conformity');

        $json = json_encode([
            'fields' => [],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'response_schema' => [
                'properties' => [
                    'document_conformity' => ['type' => 'object'],
                ],
            ],
        ];

        $this->parser->parse($response, $contract);
    }

    public function testMissingMatchesExpectedTypeThrowsExceptionWhenRequired(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini extraction payload omitió el campo requerido matches_expected_type en document_conformity');

        $json = json_encode([
            'document_conformity' => [
                'detected_type' => 'Otro',
            ],
            'fields' => [],
            'items' => [],
            'visual_checks' => [],
            'document_quality' => 'legible',
            'quality_notes' => [],
        ], JSON_THROW_ON_ERROR);

        $response = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => $json]]],
            ]],
        ];

        $contract = [
            'response_schema' => [
                'properties' => [
                    'document_conformity' => ['type' => 'object'],
                ],
            ],
        ];

        $this->parser->parse($response, $contract);
    }
}
