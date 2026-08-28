<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use App\Services\Audit\Pipeline\ExtractionPromptBuilder;
use PHPUnit\Framework\TestCase;

final class ExtractionPromptBuilderTest extends TestCase
{
    private ExtractionPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ExtractionPromptBuilder();
    }

    public function testDefaultSystemPromptContainsEssentialExtractionRules(): void
    {
        $systemPrompt = $this->builder->buildSystemPrompt([], []);

        $this->assertStringContainsString('Eres un extractor documental determinístico.', $systemPrompt);
        $this->assertStringContainsString('Extrae el texto exactamente como aparece en la imagen.', $systemPrompt);
        $this->assertStringContainsString('Transcribe cada carácter tal como es visible, sin corregir ni interpretar.', $systemPrompt);
        $this->assertStringContainsString('Si el documento está rotado o invertido, orienta mentalmente la lectura en sentido natural antes de transcribir.', $systemPrompt);
        $this->assertStringContainsString('Para fechas, transcribe el año exacto tal como está impreso.', $systemPrompt);
        $this->assertStringContainsString('Responde únicamente con JSON estructurado válido según el schema indicado.', $systemPrompt);
        $this->assertStringNotContainsString('↔', $systemPrompt, 'El prompt NO debe contener tablas de confusión de caracteres');
    }

    public function testBuildSystemPromptPreservesCustomPromptWithDeduplication(): void
    {
        $contract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'object',
                        'properties' => [
                            'DocumentoPaciente' => [
                                'type' => 'string',
                                'description' => 'Solo numero del paciente; transcribe cada digito individualmente sin tipo ni nombre.',
                            ],
                        ],
                    ],
                ],
            ],
            'field_groups' => [
                'fields' => ['DocumentoPaciente'],
                'items' => [],
            ],
        ];

        $payload = [
            'system_prompt' => "Instruccion adicional personalizada para la EPS.\nSolo numero del paciente; transcribe cada digito individualmente sin tipo ni nombre.",
        ];

        $systemPrompt = $this->builder->buildSystemPrompt($payload, $contract);

        $this->assertStringContainsString('Instruccion adicional personalizada para la EPS.', $systemPrompt);
        $this->assertStringContainsString('Eres un extractor documental determinístico.', $systemPrompt);
    }

    public function testBuildUserPromptIncludesAllFieldGroupsAndVisualChecks(): void
    {
        $contract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'fields' => [
                        'type' => 'object',
                        'properties' => [
                            'DocumentoPaciente' => ['type' => 'string'],
                            'FechaFormula' => ['type' => 'string'],
                        ],
                    ],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'Articulo' => ['type' => 'string'],
                                'Cantidad' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'visual_checks' => [
                        'type' => 'array',
                    ],
                    'document_quality' => [
                        'type' => 'string',
                    ],
                    'quality_notes' => [
                        'type' => 'array',
                    ],
                ],
            ],
            'field_groups' => [
                'fields' => ['DocumentoPaciente', 'FechaFormula'],
                'items' => ['Articulo', 'Cantidad'],
            ],
        ];

        $payload = [
            'fields_config' => [
                ['campoNombre' => 'TipoDocumentoPaciente', 'tipoDato' => 'identity_doc_type'],
                ['campoNombre' => 'DocumentoPaciente', 'tipoDato' => 'identity_doc_number'],
                ['campoNombre' => 'NombrePaciente', 'tipoDato' => 'person_name'],
            ],
            'visual_checks' => [
                ['check' => 'FirmaPrescriptor', 'description' => 'Firma del medico visible'],
                ['check' => 'VigenciaEntrega', 'description' => 'Dias de vigencia autorizada desde la fecha base'],
            ],
        ];

        $userPrompt = $this->builder->buildUserPrompt('FORMULA MEDICA', $payload, $contract);

        $this->assertStringContainsString('Documento objetivo: FORMULA MEDICA.', $userPrompt);
        $this->assertStringContainsString('### Regla de tipología y conformidad documental', $userPrompt);
        $this->assertStringContainsString('matches_expected_type', $userPrompt);
        $this->assertStringContainsString('### Regla de identidad', $userPrompt);
        $this->assertStringContainsString('TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente', $userPrompt);
        $this->assertStringNotContainsString('NORENA AGUDELO', $userPrompt, 'No debe contener ejemplos de personas hardcodeados');
        $this->assertStringContainsString('Campos para `fields`: DocumentoPaciente, FechaFormula.', $userPrompt);
        $this->assertStringContainsString('Campos para `items`: Articulo, Cantidad.', $userPrompt);
        $this->assertStringContainsString('Checks visuales esperados:', $userPrompt);
        $this->assertStringContainsString('- FirmaPrescriptor: Firma del medico visible', $userPrompt);
        $this->assertStringContainsString('- VigenciaEntrega: Dias de vigencia autorizada desde la fecha base', $userPrompt);
        $this->assertStringContainsString('Devuelve un JSON con las siguientes secciones: fields, items, visual_checks, document_quality, quality_notes.', $userPrompt);
    }

    public function testBuildUserPromptSegmentedItemsActivatedByCardinality(): void
    {
        $contract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'fields' => ['type' => 'object'],
                    'items' => ['type' => 'array'],
                ],
            ],
            'field_groups' => [
                'fields' => ['NumeroFactura'],
                'items' => ['NombreArticulo', 'CantidadEntregada'],
            ],
        ];

        $payload = [
            'fuente_verdad' => [
                'items' => [
                    ['NombreArticulo' => 'Medicamento A'],
                    ['NombreArticulo' => 'Medicamento B'],
                ],
            ],
        ];

        // Se usa cualquier tipo documental (ej. "FACTURA_PROVEEDOR", no necesariamente "DISPENSA")
        $userPrompt = $this->builder->buildUserPrompt('FACTURA_PROVEEDOR', $payload, $contract);

        $this->assertStringContainsString('Este documento contiene multiples lineas de detalle.', $userPrompt);
        $this->assertStringContainsString('Debes usar `items` con una entrada por cada fila visible.', $userPrompt);
    }

    public function testBuildUserPromptItemCandidatesActivatedByArticleValueType(): void
    {
        $contract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => ['type' => 'array'],
                ],
            ],
            'field_groups' => [
                'fields' => [],
                'items' => ['NombreArticulo', 'Cantidad'],
            ],
        ];

        $payload = [
            'fields_config' => [
                ['campoNombre' => 'NombreArticulo', 'tipoDato' => 'article_name'],
                ['campoNombre' => 'Cantidad', 'tipoDato' => 'quantity'],
            ],
            'fuente_verdad' => [
                'items' => [
                    ['NombreArticulo' => 'ACETAMINOFEN 500MG'],
                    ['NombreArticulo' => 'IBUPROFENO 400MG'],
                ],
            ],
        ];

        // Se activa para cualquier tipo de documento que tenga article_name en items
        $userPrompt = $this->builder->buildUserPrompt('DOCUMENTO_GENERICO', $payload, $contract);

        $this->assertStringContainsString('Candidatos de articulo para busqueda en documento:', $userPrompt);
        $this->assertStringContainsString('- ACETAMINOFEN 500MG', $userPrompt);
        $this->assertStringContainsString('- IBUPROFENO 400MG', $userPrompt);
    }

    public function testBuildUserPromptOmitsIdentityRuleWhenNoIdentityFieldsConfigured(): void
    {
        $contract = [
            'response_schema' => ['type' => 'object'],
            'field_groups' => ['fields' => ['NumeroFactura'], 'items' => []],
        ];

        $payload = [
            'fields_config' => [
                ['campoNombre' => 'NumeroFactura', 'tipoDato' => 'text'],
            ],
        ];

        $userPrompt = $this->builder->buildUserPrompt('RECIBO_PAGO', $payload, $contract);

        $this->assertStringNotContainsString('### Regla de identidad', $userPrompt);
    }

    public function testPromptContextHash(): void
    {
        $hash1 = $this->builder->promptContextHash('user prompt', 'system prompt');
        $hash2 = $this->builder->promptContextHash('user prompt', 'system prompt');
        $hash3 = $this->builder->promptContextHash('different user prompt', 'system prompt');

        $this->assertSame($hash1, $hash2);
        $this->assertNotSame($hash1, $hash3);
        $this->assertSame(64, strlen($hash1));
    }

    public function testContractRequirementsDetection(): void
    {
        $contract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'fields' => ['type' => 'object'],
                    'items' => ['type' => 'array'],
                    'visual_checks' => ['type' => 'array'],
                ],
            ],
            'field_groups' => [
                'fields' => ['NombrePaciente'],
                'items' => ['NombreArticulo'],
            ],
        ];

        $this->assertTrue($this->builder->contractRequiresItems($contract));
        $this->assertTrue($this->builder->contractRequiresVisualChecks($contract));

        $emptyContract = [
            'response_schema' => [
                'type' => 'object',
                'properties' => [
                    'document_quality' => ['type' => 'string'],
                ],
            ],
            'field_groups' => [
                'fields' => [],
                'items' => [],
            ],
        ];

        $this->assertFalse($this->builder->contractRequiresItems($emptyContract));
        $this->assertFalse($this->builder->contractRequiresVisualChecks($emptyContract));
    }
}
