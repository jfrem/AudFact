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
        $this->assertStringNotContainsString('↔', $systemPrompt, 'El prompt NO debe contener tablas de confusión de caracteres');
    }

    public function testBuildSystemPromptPreservesCustomPromptWithDeduplication(): void
    {
        $contract = [
            'function_declarations' => [
                [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'DocumentoPaciente' => [
                                        'properties' => [
                                            'valor' => [
                                                'description' => 'Solo numero del paciente; transcribe cada digito individualmente sin tipo ni nombre.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'required_function_names' => [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
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
            'function_declarations' => [
                [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                    'parameters' => [
                        'properties' => [
                            'fields' => [
                                'properties' => [
                                    'DocumentoPaciente' => [],
                                    'FechaFormula' => [],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                    'parameters' => [
                        'properties' => [
                            'items' => [
                                'items' => [
                                    'properties' => [
                                        'Articulo' => [],
                                        'Cantidad' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                ],
                [
                    'name' => DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
                ],
            ],
            'required_function_names' => [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                DocumentExtractionContractBuilder::FN_ASSESS_DOCUMENT_QUALITY,
            ],
            'field_groups' => [
                'fields' => ['DocumentoPaciente', 'FechaFormula'],
                'items' => ['Articulo', 'Cantidad'],
            ],
        ];

        $payload = [
            'fields_config' => [
                ['campo' => 'TipoDocumentoPaciente', 'tipoDato' => 'identity_doc_type'],
                ['campo' => 'DocumentoPaciente', 'tipoDato' => 'identity_doc_number'],
                ['campo' => 'NombrePaciente', 'tipoDato' => 'person_name'],
            ],
            'visual_checks' => [
                ['check' => 'FirmaPrescriptor', 'description' => 'Firma del medico visible'],
                ['check' => 'VigenciaEntrega', 'description' => 'Dias de vigencia'],
            ],
        ];

        $userPrompt = $this->builder->buildUserPrompt('FORMULA MEDICA', $payload, $contract);

        $this->assertStringContainsString('Documento objetivo: FORMULA MEDICA.', $userPrompt);
        $this->assertStringContainsString('### Regla de identidad', $userPrompt);
        $this->assertStringContainsString('Campos para `extract_fields`: DocumentoPaciente, FechaFormula.', $userPrompt);
        $this->assertStringContainsString('Campos para `extract_items`: Articulo, Cantidad.', $userPrompt);
        $this->assertStringContainsString('Checks visuales esperados:', $userPrompt);
        $this->assertStringContainsString('- FirmaPrescriptor: Firma del medico visible', $userPrompt);
        $this->assertStringContainsString('Para VigenciaEntrega', $userPrompt);
        $this->assertStringContainsString('Invoca exactamente una vez cada función en el mismo turno: extract_fields, extract_items, detect_visual_checks, assess_document_quality.', $userPrompt);
    }

    public function testBuildToolConfigAndPromptContextHash(): void
    {
        $contract = [
            'function_declarations' => [
                ['name' => DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS],
                ['name' => DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS],
            ],
            'required_function_names' => [
                DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
            ],
        ];

        $toolConfig = $this->builder->buildToolConfig($contract);

        $this->assertSame([
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => [
                    DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS,
                    DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS,
                ],
            ],
        ], $toolConfig);

        $hash1 = $this->builder->promptContextHash('user prompt', 'system prompt');
        $hash2 = $this->builder->promptContextHash('user prompt', 'system prompt');
        $hash3 = $this->builder->promptContextHash('different user prompt', 'system prompt');

        $this->assertSame($hash1, $hash2);
        $this->assertNotSame($hash1, $hash3);
        $this->assertSame(64, strlen($hash1));
    }
}
