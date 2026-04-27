<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Events\SchemaBuilder;
use PHPUnit\Framework\TestCase;

final class SchemaBuilderTest extends TestCase
{
    public function testFirmaActaEntregaStaysInFieldsFor1165Fixture(): void
    {
        $builder = new SchemaBuilder();

        $schema = $builder->build([
            'documents' => [
                'DISPENSA' => [
                    'docId' => 1,
                    'fields' => ['DocumentoPaciente', 'FirmaActaEntrega'],
                    'visualChecks' => [],
                ],
            ],
        ]);

        $this->assertCount(1, $schema);
        $this->assertSame(
            ['DocumentoPaciente', 'FirmaActaEntrega'],
            array_column($schema[0]['fields'], 'campoNombre')
        );
        $this->assertSame([], $schema[0]['visual_checks']);
        $this->assertSame('extract_document_data', $schema[0]['extraction_schema']['name']);
        $this->assertSame(
            ['fields', 'visual_checks', 'document_quality'],
            $schema[0]['extraction_schema']['parameters']['required']
        );
        $this->assertSame(
            ['legible', 'parcialmente_legible', 'ilegible'],
            $schema[0]['extraction_schema']['parameters']['properties']['document_quality']['enum']
        );
        $this->assertArrayHasKey(
            'DocumentoPaciente',
            $schema[0]['extraction_schema']['parameters']['properties']['fields']['properties']
        );
        $this->assertArrayHasKey(
            'FirmaActaEntrega',
            $schema[0]['extraction_schema']['parameters']['properties']['fields']['properties']
        );
        $this->assertSame(
            'string',
            $schema[0]['extraction_schema']['parameters']['properties']['fields']['properties']['DocumentoPaciente']['type']
        );
        $this->assertArrayHasKey(
            'DocumentoPaciente',
            $schema[0]['extraction_schema']['parameters']['properties']['items']['items']['properties']
        );
        $this->assertArrayNotHasKey(
            'additionalProperties',
            $schema[0]['extraction_schema']['parameters']['properties']['fields']
        );
        $this->assertArrayNotHasKey(
            'default',
            $schema[0]['extraction_schema']['parameters']['properties']['visual_checks']
        );
        $this->assertArrayNotHasKey(
            'response_template',
            $schema[0]['extraction_schema']
        );
        $this->assertSame(
            ['check', 'presente'],
            $schema[0]['extraction_schema']['parameters']['properties']['visual_checks']['items']['required']
        );
    }

    public function testFirmaActaEntregaAndFirmaPrescriptorBecomeVisualChecksFor2426Fixture(): void
    {
        $builder = new SchemaBuilder();

        $schema = $builder->build([
            'documents' => [
                'DISPENSA' => [
                    'docId' => 1,
                    'fields' => ['DocumentoPaciente'],
                    'visualChecks' => [
                        [
                            'check' => 'FirmaActaEntrega',
                            'description' => 'Firma del paciente',
                            'severity' => 'CRITICO',
                        ],
                    ],
                ],
                'FORMULA MEDICA' => [
                    'docId' => 3,
                    'fields' => ['NombreArticulo'],
                    'visualChecks' => [
                        [
                            'check' => 'FirmaPrescriptor',
                            'description' => 'Firma del médico',
                            'severity' => 'CRITICO',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $schema);
        $this->assertSame('DISPENSA', $schema[0]['document_name']);
        $this->assertSame('FirmaActaEntrega', $schema[0]['visual_checks'][0]['check']);
        $this->assertSame('FORMULA MEDICA', $schema[1]['document_name']);
        $this->assertSame('FirmaPrescriptor', $schema[1]['visual_checks'][0]['check']);
        $this->assertSame(
            'check',
            array_key_first($schema[0]['extraction_schema']['parameters']['properties']['visual_checks']['items']['properties'])
        );
        $this->assertArrayHasKey(
            'DocumentoPaciente',
            $schema[0]['extraction_schema']['parameters']['properties']['fields']['properties']
        );
        $this->assertArrayHasKey(
            'NombreArticulo',
            $schema[1]['extraction_schema']['parameters']['properties']['fields']['properties']
        );
        $this->assertArrayHasKey(
            'NombreArticulo',
            $schema[1]['extraction_schema']['parameters']['properties']['items']['items']['properties']
        );
        $schemaJson = json_encode($schema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('additionalProperties', $schemaJson);
        $this->assertStringNotContainsString('"default"', $schemaJson);
        $this->assertStringNotContainsString('response_template', $schemaJson);
    }
}
