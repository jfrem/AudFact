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

        // Fields ahora son shapes v1 — verificar estructura
        $numAut = $result['fields_normalized']['NumeroAutorizacion'];
        $this->assertSame('46338218', $numAut['valor']);
        $this->assertTrue($numAut['presente']);
        $this->assertSame('FOUND', $numAut['estadoExtraccion']);
        $this->assertSame(['46338218'], $numAut['valores']);

        $docPac = $result['fields_normalized']['DocumentoPaciente'];
        $this->assertSame('123456789', $docPac['valor']);

        $nombrePac = $result['fields_normalized']['NombrePaciente'];
        $this->assertNull($nombrePac['valor']);
        $this->assertFalse($nombrePac['presente']);
        $this->assertSame('NOT_FOUND', $nombrePac['estadoExtraccion']);

        $this->assertArrayNotHasKey('CodigoArticulo', $result['fields_normalized']);
        $this->assertArrayNotHasKey('CantidadEntregada', $result['fields_normalized']);

        // Items: cada campo es shape v1
        $this->assertCount(2, $result['items_normalized']);
        $this->assertSame('IM01273', $result['items_normalized'][0]['CodigoArticulo']['valor']);
        $this->assertSame('20', $result['items_normalized'][0]['CantidadEntregada']['valor']);

        $this->assertSame(true, $result['visual_checks_resultado'][0]['presente']);
        $this->assertSame('Firma visible', $result['visual_checks_resultado'][0]['detalle']);
        $this->assertSame('CRITICO', $result['visual_checks_resultado'][0]['severidad']);
        $this->assertSame('legible', $result['document_quality']);
        $this->assertSame(['primera pagina OK'], $result['quality_notes']);
        $this->assertNotEmpty($result['normalization_log']);
        $this->assertContains('empty_string_to_null', array_column($result['normalization_log'], 'operation'));
        $this->assertContains('legacy_scalar_wrapped_v1', array_column($result['normalization_log'], 'operation'));
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

        // Fields: shapes v1 con fecha normalizada
        $fechaEntrega = $result['fields_normalized']['FechaEntrega'];
        $this->assertSame('2025-07-29', $fechaEntrega['valor']);
        $this->assertTrue($fechaEntrega['presente']);

        $fechaAut = $result['fields_normalized']['FechaAutorizacion'];
        $this->assertSame('2025-07-27', $fechaAut['valor']);

        // Items: shape v1 con fecha normalizada
        $fechaVenc = $result['items_normalized'][0]['FechaVencimiento'];
        $this->assertSame('2029-03-30', $fechaVenc['valor']);
        $this->assertContains('date_normalized_to_iso', array_column($result['normalization_log'], 'operation'));
    }

    public function testNormalizePreservesStructuredVisualEvidence(): void
    {
        $normalizer = new DocumentNormalizer();

        $result = $normalizer->normalize([
            'tipo_documento' => 'AUTORIZACION',
            'visual_checks' => [
                [
                    'check' => 'VigenciaEntrega',
                    'description' => 'Vigencia visible',
                    'severity' => 'alta',
                    'rol' => 'AUTORITATIVO',
                    'omitirSi' => null,
                ],
            ],
            'extraction_result' => [
                'fields' => [],
                'items' => [],
                'visual_checks' => [
                    [
                        'check' => 'VigenciaEntrega',
                        'presente' => true,
                        'valor' => '60',
                        'unidad' => 'días',
                        'fecha_base' => 'fecha de autorización',
                        'detalle' => 'Vigencia de 60 días',
                    ],
                ],
                'document_quality' => 'legible',
                'quality_notes' => [],
            ],
        ]);

        $visual = $result['visual_checks_resultado'][0];
        $this->assertSame('VigenciaEntrega', $visual['check']);
        $this->assertTrue($visual['presente']);
        $this->assertSame(60, $visual['valor']);
        $this->assertSame('dias', $visual['unidad']);
        $this->assertSame('FechaAutorizacion', $visual['fecha_base']);
        $this->assertSame('AUTORITATIVO', $visual['rol']);
    }

    /**
     * Verifica que el normalizer procesa correctamente objetos de evidencia v1
     * (como los que produce Gemini con el nuevo schema).
     */
    public function testNormalizeHandlesV1EvidenceObjects(): void
    {
        $normalizer = new DocumentNormalizer();

        $result = $normalizer->normalize([
            'tipo_documento' => 'FORMULA MEDICA',
            'visual_checks' => [],
            'extraction_result' => [
                'fields' => [
                    'CodigoDiagnostico' => [
                        'valor' => 'S202, S273, S224',
                        'valores' => ['S202', 'S273', 'S224'],
                        'presente' => true,
                        'confianza' => 'alta',
                        'estadoExtraccion' => 'FOUND_IN_LIST',
                        'evidencia' => 'S202, S273, S224',
                        'ubicacion' => 'sección Diagnóstico',
                    ],
                    'NombrePaciente' => [
                        'valor' => 'ROBERTO TAPIAS SOCHA',
                        'valores' => ['ROBERTO TAPIAS SOCHA'],
                        'presente' => true,
                        'confianza' => 'alta',
                        'estadoExtraccion' => 'FOUND',
                        'evidencia' => 'Roberto Tapias Socha',
                        'ubicacion' => 'encabezado',
                    ],
                    'FechaFormula' => [
                        'valor' => '22/04/2026',
                        'valores' => ['22/04/2026'],
                        'presente' => true,
                        'confianza' => 'alta',
                        'estadoExtraccion' => 'FOUND',
                        'evidencia' => '22/04/2026',
                        'ubicacion' => 'encabezado',
                    ],
                ],
                'items' => [],
                'visual_checks' => [],
                'document_quality' => 'legible',
                'quality_notes' => [],
            ],
        ]);

        // CodigoDiagnostico: v1 preservada con tokens
        $diag = $result['fields_normalized']['CodigoDiagnostico'];
        $this->assertSame('S202, S273, S224', $diag['valor']);
        $this->assertSame(['S202', 'S273', 'S224'], $diag['valores']);
        $this->assertTrue($diag['presente']);
        $this->assertSame('alta', $diag['confianza']);
        $this->assertSame('FOUND_IN_LIST', $diag['estadoExtraccion']);
        $this->assertSame('S202, S273, S224', $diag['evidencia']);
        $this->assertSame('sección Diagnóstico', $diag['ubicacion']);

        // NombrePaciente: v1 preservada
        $nombre = $result['fields_normalized']['NombrePaciente'];
        $this->assertSame('ROBERTO TAPIAS SOCHA', $nombre['valor']);
        $this->assertSame('FOUND', $nombre['estadoExtraccion']);

        // FechaFormula: fecha normalizada dentro de v1
        $fecha = $result['fields_normalized']['FechaFormula'];
        $this->assertSame('2026-04-22', $fecha['valor']);
        $this->assertSame('FOUND', $fecha['estadoExtraccion']);

        // Verificar log operations
        $ops = array_column($result['normalization_log'], 'operation');
        $this->assertContains('v1_evidence_normalized', $ops);
    }
}
