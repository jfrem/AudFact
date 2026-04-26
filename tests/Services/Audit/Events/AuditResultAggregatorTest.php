<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Events\AuditResultAggregator;
use App\Services\Audit\Events\AuditStateStore;
use PHPUnit\Framework\TestCase;

final class AuditResultAggregatorTest extends TestCase
{
    public function testAggregateBuildsPersistableAuditResultData(): void
    {
        $aggregator = new AuditResultAggregator();

        $result = $aggregator->aggregate(
            [
                'fac_sec' => '87723098',
                'dis_det_nro' => 'T38250701547',
                'fac_nit_sec' => '2426',
                'created_at' => '2026-04-23T12:00:00Z',
                'documents' => [
                    'doc-1' => ['tipo_documento' => 'FORMULA_MEDICA'],
                    'doc-2' => ['tipo_documento' => 'DISPENSA'],
                    'doc-3' => ['tipo_documento' => 'AUTORIZACION'],
                ],
            ],
            [
                'hallazgos' => [
                    'items' => [[
                        'campo' => 'CodigoDiagnostico',
                        'valorFuenteVerdad' => 'S127',
                        'valorDocumento' => null,
                        'resultado' => 'NO_CONCLUYENTE',
                        'severidad' => 'alta',
                        'documento' => 'FORMULA_MEDICA',
                        'detalle' => 'La calidad documental no permite concluir el valor con confianza suficiente.',
                    ]],
                    'metrics' => [
                        'total_campos' => 18,
                        'coincidencias' => 16,
                        'discrepancias' => 0,
                        'omitidos' => 0,
                        'no_concluyentes' => 2,
                        'risk_score' => 20,
                    ],
                ],
                'document_decisions' => [[
                    'documentName' => 'FORMULA_MEDICA',
                    'approved' => false,
                    'observation' => 'La calidad documental no permite concluir el valor con confianza suficiente.',
                ]],
            ]
        );

        $this->assertSame(AuditStateStore::AUDIT_STATUS_MANUAL_REVIEW, $result['final_status']);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertSame('87723098', $result['audit_result_data']['FacSec']);
        $this->assertSame('T38250701547', $result['audit_result_data']['FacNro']);
        $this->assertSame(1, $result['audit_result_data']['EstAud']);
        $this->assertSame('manual_review', $result['audit_result_data']['EstadoDetallado']);
        $this->assertSame(1, $result['audit_result_data']['RequiereRevisionHumana']);
        $this->assertSame('alta', $result['audit_result_data']['Severidad']);
        $this->assertSame('FORMULA MEDICA', $result['audit_result_data']['DocumentoFallido']);
        $this->assertSame(3, $result['audit_result_data']['DocumentosProcesados']);
        $this->assertStringContainsString('"risk_score":20', (string) $result['audit_result_data']['Hallazgos']);
    }
}
