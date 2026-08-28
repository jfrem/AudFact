<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentExtractionContractBuilder;
use PHPUnit\Framework\TestCase;

final class DocumentExtractionContractBuilderTest extends TestCase
{
    private DocumentExtractionContractBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DocumentExtractionContractBuilder();
    }

    public function testBuildReturnsUnifiedResponseSchema(): void
    {
        $fields = [
            ['campoNombre' => 'NombrePaciente', 'tipoCampo' => 'E', 'tipoDato' => 'person_name'],
            ['campoNombre' => 'DocumentoPaciente', 'tipoCampo' => 'E', 'tipoDato' => 'identity_doc_number'],
            ['campoNombre' => 'CodigoProducto', 'tipoCampo' => 'S', 'tipoDato' => 'code', 'esMultiItem' => true],
            ['campoNombre' => 'CantidadEntregada', 'tipoCampo' => 'B', 'tipoDato' => 'quantity'],
            ['campoNombre' => 'Lote', 'tipoCampo' => 'E', 'tipoDato' => 'trace_token'],
        ];

        $visualChecks = [
            ['check' => 'VigenciaEntrega', 'description' => '30 dias', 'severity' => 'ALTA'],
        ];

        $contract = $this->builder->build('AUTORIZACION', $fields, $visualChecks);

        $this->assertArrayHasKey('response_schema', $contract);
        $this->assertArrayNotHasKey('function_declarations', $contract);
        $this->assertArrayHasKey('field_groups', $contract);
        $this->assertArrayHasKey('contract_hash', $contract);

        $schema = $contract['response_schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('document_conformity', $schema['properties']);
        $this->assertArrayHasKey('fields', $schema['properties']);
        $this->assertArrayHasKey('items', $schema['properties']);
        $this->assertArrayHasKey('visual_checks', $schema['properties']);
        $this->assertArrayHasKey('document_quality', $schema['properties']);
        $this->assertArrayHasKey('quality_notes', $schema['properties']);

        // Verificar required
        $this->assertContains('document_conformity', $schema['required']);
        $this->assertContains('fields', $schema['required']);
        $this->assertContains('items', $schema['required']);
        $this->assertContains('visual_checks', $schema['required']);
        $this->assertContains('document_quality', $schema['required']);
        $this->assertContains('quality_notes', $schema['required']);

        // Verificar document_conformity
        $conformityProps = $schema['properties']['document_conformity']['properties'];
        $this->assertSame(['matches_expected_type', 'detected_type', 'justification'], $schema['properties']['document_conformity']['required']);
        $this->assertSame('boolean', $conformityProps['matches_expected_type']['type']);
        $this->assertSame('string', $conformityProps['detected_type']['type']);
        $this->assertTrue($conformityProps['detected_type']['nullable']);
        $this->assertSame('string', $conformityProps['justification']['type']);
        $this->assertTrue($conformityProps['justification']['nullable']);

        // Verificar campos planos
        $fieldsProps = $schema['properties']['fields']['properties'];
        $this->assertSame(['NombrePaciente', 'DocumentoPaciente'], $schema['properties']['fields']['required']);
        $this->assertSame('string', $fieldsProps['NombrePaciente']['type']);
        $this->assertTrue($fieldsProps['NombrePaciente']['nullable']);
        $this->assertArrayNotHasKey('properties', $fieldsProps['NombrePaciente']);

        $this->assertSame('string', $fieldsProps['DocumentoPaciente']['type']);
        $this->assertTrue($fieldsProps['DocumentoPaciente']['nullable']);

        // Verificar items planos
        $itemsProps = $schema['properties']['items']['items']['properties'];
        $this->assertSame(['CodigoProducto', 'CantidadEntregada', 'Lote'], $schema['properties']['items']['items']['required']);
        $this->assertSame('string', $itemsProps['CodigoProducto']['type']);
        $this->assertSame('number', $itemsProps['CantidadEntregada']['type']);
        $this->assertSame('array', $itemsProps['Lote']['type']);
        $this->assertSame('string', $itemsProps['Lote']['items']['type']);
        $this->assertTrue($itemsProps['Lote']['nullable']);

        // Verificar visual checks
        $visualProps = $schema['properties']['visual_checks']['items']['properties'];
        $this->assertSame('string', $visualProps['check']['type']);
        $this->assertSame(['VigenciaEntrega'], $visualProps['check']['enum']);
        $this->assertSame('boolean', $visualProps['presente']['type']);
        $this->assertSame('number', $visualProps['valor']['type']);
        $this->assertSame('string', $visualProps['unidad']['type']);
    }

    public function testHashPayloadIsDeterministic(): void
    {
        $payload1 = ['b' => 2, 'a' => 1, 'c' => ['y' => 20, 'x' => 10]];
        $payload2 = ['a' => 1, 'c' => ['x' => 10, 'y' => 20], 'b' => 2];

        $hash1 = DocumentExtractionContractBuilder::hashPayload($payload1);
        $hash2 = DocumentExtractionContractBuilder::hashPayload($payload2);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
    }
}
