<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentNormalizer;
use PHPUnit\Framework\TestCase;

final class DocumentNormalizerTest extends TestCase
{
    public function testNormalizeProducesPlanningContractWithNormalizationLog(): void
    {
        $normalizer = new DocumentNormalizer();

        $result = $normalizer->normalize([
            'tipo_documento' => 'DISPENSA',
            'visual_checks' => [
                [
                    'check' => 'FirmaActaEntrega',
                    'description' => 'Firma de recibido',
                    'severity' => 'critico',
                ],
            ],
            'extraction_result' => [
                'fields' => [
                    'NumeroAutorizacion' => ' 46338218 ',
                    'DocumentoPaciente' => ' 123456789 ',
                    'NombrePaciente' => '   ',
                ],
                'items' => [
                    [
                        'CodigoArticulo' => 'IM01273',
                        'CantidadEntregada' => '20',
                    ],
                    [
                        'CodigoArticulo' => 'IM01274',
                        'CantidadEntregada' => '30',
                    ],
                ],
                'visual_checks' => [
                    [
                        'check' => 'FirmaActaEntrega',
                        'presente' => true,
                        'detalle' => 'Firma visible',
                    ],
                ],
                'document_quality' => 'legible',
                'quality_notes' => ['  primera pagina OK  ', '', 'primera pagina OK'],
            ],
        ]);

        $this->assertSame('DISPENSA', $result['tipo_documento']);
        $this->assertSame('46338218', $result['fields_normalized']['Autorizacion']);
        $this->assertSame('123456789', $result['fields_normalized']['NumeroIdentificacion']);
        $this->assertNull($result['fields_normalized']['NombrePaciente']);
        $this->assertArrayNotHasKey('CodigoArticulo', $result['fields_normalized']);
        $this->assertArrayNotHasKey('CantidadEntregada', $result['fields_normalized']);
        $this->assertCount(2, $result['items_normalized']);
        $this->assertSame('IM01273', $result['items_normalized'][0]['CodigoArticulo']);
        $this->assertSame('20', $result['items_normalized'][0]['CantidadEntregada']);
        $this->assertSame(true, $result['visual_checks_resultado'][0]['presente']);
        $this->assertSame('Firma visible', $result['visual_checks_resultado'][0]['detalle']);
        $this->assertSame('CRITICO', $result['visual_checks_resultado'][0]['severidad']);
        $this->assertSame('legible', $result['document_quality']);
        $this->assertSame(['primera pagina OK'], $result['quality_notes']);
        $this->assertNotEmpty($result['normalization_log']);
        $this->assertContains('field_alias_normalized', array_column($result['normalization_log'], 'operation'));
        $this->assertContains('empty_string_to_null', array_column($result['normalization_log'], 'operation'));
    }

    public function testNormalizeDropsEmptyRowsAndDefaultsConfiguredVisualCheck(): void
    {
        $normalizer = new DocumentNormalizer();

        $result = $normalizer->normalize([
            'tipo_documento' => 'FORMULA MEDICA',
            'visual_checks' => [
                [
                    'check' => 'FirmaPrescriptor',
                    'description' => 'Firma del medico',
                    'severity' => 'alto',
                ],
            ],
            'extraction_result' => [
                'fields' => [],
                'items' => [
                    ['CodigoArticulo' => '   '],
                    ['CodigoArticulo' => 'ABC123'],
                ],
                'visual_checks' => [],
                'document_quality' => 'parcialmente_legible',
                'quality_notes' => [],
            ],
        ]);

        $this->assertSame('FORMULA MEDICA', $result['tipo_documento']);
        $this->assertCount(1, $result['items_normalized']);
        $this->assertSame(false, $result['visual_checks_resultado'][0]['presente']);
        $this->assertSame('Firma del medico', $result['visual_checks_resultado'][0]['detalle']);
        $this->assertSame('ALTO', $result['visual_checks_resultado'][0]['severidad']);
        $this->assertContains('empty_item_row_dropped', array_column($result['normalization_log'], 'operation'));
        $this->assertContains('visual_check_defaulted', array_column($result['normalization_log'], 'operation'));
    }

    public function testNormalizeCanonicalizesKnownDateFieldsToIsoFormat(): void
    {
        $normalizer = new DocumentNormalizer();

        $result = $normalizer->normalize([
            'tipo_documento' => 'DISPENSA',
            'visual_checks' => [],
            'extraction_result' => [
                'fields' => [
                    'FechaEntrega' => '29/07/2025',
                    'FechaAutorizacion' => '27/07/2025',
                ],
                'items' => [
                    [
                        'FechaVencimiento' => '30/03/2029',
                    ],
                ],
                'visual_checks' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
            ],
        ]);

        $this->assertSame('2025-07-29', $result['fields_normalized']['FechaEntrega']);
        $this->assertSame('2025-07-27', $result['fields_normalized']['FechaAutorizacion']);
        $this->assertSame('2029-03-30', $result['items_normalized'][0]['FechaVencimiento']);
        $this->assertContains('date_normalized_to_iso', array_column($result['normalization_log'], 'operation'));
    }
}
