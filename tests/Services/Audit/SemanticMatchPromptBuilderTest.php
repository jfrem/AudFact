<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\SemanticMatchJudge;
use App\Services\Audit\SemanticMatchPromptBuilder;
use PHPUnit\Framework\TestCase;

final class SemanticMatchPromptBuilderTest extends TestCase
{
    private SemanticMatchPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SemanticMatchPromptBuilder();
    }

    public function testBuildProductContractGeneratesPromptAndSchema(): void
    {
        [$prompt, $systemInstruction, $schema] = $this->builder->buildProductContract(
            'SERTRALINA 100MG C*10 TABLETA',
            'SERTRALINA TAB 100 GM',
            ['document_context' => 'Posologia: 1 cada 24 horas | Concentracion: 100mg']
        );

        $this->assertStringContainsString('SERTRALINA 100MG C*10 TABLETA', $prompt);
        $this->assertStringContainsString('SERTRALINA TAB 100 GM', $prompt);
        $this->assertStringContainsString('Posologia: 1 cada 24 horas', $prompt);

        $this->assertStringContainsString('EQUIVALENCIA DE DENOMINACIÓN', $systemInstruction);
        $this->assertStringContainsString('CONTRADICCIÓN EXPLÍCITA', $systemInstruction);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('is_match', $schema['properties']);
        $this->assertArrayHasKey('same_clinical_use', $schema['properties']);
        $this->assertArrayHasKey('same_dimensions_or_dose', $schema['properties']);
        $this->assertArrayHasKey('reasoning', $schema['properties']);
        $this->assertContains('is_match', $schema['required']);
    }

    public function testBuildPersonNameContractGeneratesIdentityPromptAndSchema(): void
    {
        [$prompt, $systemInstruction, $schema] = $this->builder->buildPersonNameContract(
            'OSORIO GONZALEZ YULIETH',
            'YULIETH OSORIO GONZALEZ'
        );

        $this->assertStringContainsString('OSORIO GONZALEZ YULIETH', $prompt);
        $this->assertStringContainsString('YULIETH OSORIO GONZALEZ', $prompt);

        $this->assertStringContainsString('verificación de identidad', $systemInstruction);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('is_match', $schema['properties']);
        $this->assertArrayHasKey('reasoning', $schema['properties']);
        $this->assertContains('is_match', $schema['required']);
        $this->assertContains('reasoning', $schema['required']);
    }

    public function testBuildContractRoutesAccordingToCallPurpose(): void
    {
        [$promptPerson] = $this->builder->buildContract(
            SemanticMatchJudge::PURPOSE_PERSON_MATCH,
            'JUAN PEREZ',
            'PEREZ JUAN'
        );
        $this->assertStringContainsString('Nombre Esperado', $promptPerson);

        [$promptProduct] = $this->builder->buildContract(
            SemanticMatchJudge::PURPOSE_PRODUCT_MATCH,
            'ACETAMINOFEN 500MG',
            'PARACETAMOL 500MG'
        );
        $this->assertStringContainsString('Valor Esperado', $promptProduct);
    }
}
