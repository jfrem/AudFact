<?php

namespace Tests\Services\Audit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\Audit\AuditPersistenceService;
use App\Models\AuditStatusModel;

/**
 * Tests unitarios para AuditPersistenceService.
 *
 * Valida guards de persistencia: _errorOrigin, flujo aprobada/rechazada
 * y construcción de observaciones.
 */
class AuditPersistenceServiceTest extends TestCase
{
    private MockObject&AuditStatusModel $auditStatusModel;
    private AuditPersistenceService $service;

    protected function setUp(): void
    {
        $this->auditStatusModel = $this->createMock(AuditStatusModel::class);
        $this->service = new AuditPersistenceService($this->auditStatusModel);
    }

    // ── Guard: _errorOrigin infrastructure no actualiza AdjuntosDispensacion ──

    public function testSaveToDatabaseSkipsUpdateForInfrastructureErrors(): void
    {
        $result = [
            'response' => 'error',
            'message' => 'HTTP 429 Too Many Requests',
            '_errorOrigin' => 'infrastructure',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 500],
            'data' => ['items' => []],
            'severity' => 'ninguna',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult');

        // updateAuditResult NO debe llamarse para errores de infraestructura
        $this->auditStatusModel
            ->expects($this->never())
            ->method('updateAuditResult');

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    // ── Success: marca todos los adjuntos como C (baseline only) ──

    public function testSaveToDatabaseApprovesAllOnSuccess(): void
    {
        $result = [
            'response' => 'success',
            'message' => 'Sin hallazgos',
            '_errorOrigin' => 'gemini',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 1000],
            'data' => ['items' => []],
            'severity' => 'ninguna',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult');

        // Solo 1 llamada: baseline approved (marca todos como C)
        $this->auditStatusModel
            ->expects($this->once())
            ->method('updateAuditResult')
            ->with(
                'FAC-001',
                true,       // approved baseline
                null,
                null
            )
            ->willReturn(true);

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    // ── Rechazada: baseline C + rechazo individual por documento ──

    public function testSaveToDatabaseRejectsDocumentWithFindings(): void
    {
        $result = [
            'response' => 'warning',
            'message' => 'Discrepancia encontrada',
            '_errorOrigin' => 'gemini',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 2000],
            'data' => ['items' => [
                ['severidad' => 'alta', 'item' => 'Cantidad', 'hallazgo' => 'No coincide', 'documento' => 'factura.pdf'],
            ]],
            'severity' => 'alta',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult');

        // 2 llamadas: 1 baseline (approved) + 1 rechazo (factura.pdf)
        $this->auditStatusModel
            ->expects($this->exactly(2))
            ->method('updateAuditResult')
            ->willReturnCallback(function ($invoice, $approved, $observacion, $documento) {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertTrue($approved);
                    $this->assertNull($observacion);
                    $this->assertNull($documento);
                    return true;
                }

                if ($call === 2) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertFalse($approved);
                    $this->assertIsString($observacion);
                    $this->assertStringContainsString('No coincide', $observacion);
                    $this->assertSame('factura.pdf', $documento);
                    return true;
                }

                $this->fail('Cantidad inesperada de llamadas a updateAuditResult');
            });

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    // ── Error por faltantes: rechazo puntual por documento faltante ──

    public function testSaveToDatabaseRejectsOnlyMissingDocumentsOnBusinessPrevalidationError(): void
    {
        $result = [
            'response' => 'error',
            'message' => 'Documentos requeridos sin archivo adjunto: AUTORIZACION DE SERVICIOS, VALIDADOR DE DERECHOS',
            '_errorOrigin' => 'business',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 1200],
            'data' => ['items' => []],
            'severity' => 'ninguna',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult')
            ->with($this->callback(function (array $data) {
                return $data['EstAud'] === 0
                    && $data['EstadoDetallado'] === 'error'
                    && $data['Severidad'] === 'ninguna';
            }));

        // 2 llamadas: rechazo por cada documento faltante (sin rechazo global masivo).
        $this->auditStatusModel
            ->expects($this->exactly(2))
            ->method('updateAuditResult')
            ->willReturnCallback(function ($invoice, $approved, $observation, $documento) {
                static $call = 0;
                $call++;

                $this->assertSame('FAC-001', $invoice);
                $this->assertFalse($approved);
                $this->assertIsString($observation);
                $this->assertStringContainsString('Documentos requeridos sin archivo adjunto', $observation);

                if ($call === 1) {
                    $this->assertSame('AUTORIZACION DE SERVICIOS', $documento);
                    return true;
                }

                if ($call === 2) {
                    $this->assertSame('VALIDADOR DE DERECHOS', $documento);
                    return true;
                }

                $this->fail('Cantidad inesperada de llamadas a updateAuditResult');
            });

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    public function testSaveToDatabaseRejectsSpecificDocumentWhenBusinessErrorIncludesDocumentFindings(): void
    {
        $result = [
            'response' => 'error',
            'message' => 'Adjunto supera el maximo de páginas permitidas',
            '_errorOrigin' => 'business',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 1500],
            'data' => ['items' => [
                [
                    'item' => 'Cantidad de páginas',
                    'hallazgo' => 'Adjunto supera el maximo de páginas permitidas',
                    'severidad' => 'alta',
                    'documento' => 'VALIDADOR DE DERECHOS',
                ],
            ]],
            'severity' => 'alta',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult');

        // 2 llamadas: baseline aprobado + rechazo puntual del documento con hallazgo.
        $this->auditStatusModel
            ->expects($this->exactly(2))
            ->method('updateAuditResult')
            ->willReturnCallback(function ($invoice, $approved, $observation, $documento) {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertTrue($approved);
                    $this->assertNull($observation);
                    $this->assertNull($documento);
                    return true;
                }

                if ($call === 2) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertFalse($approved);
                    $this->assertIsString($observation);
                    $this->assertStringContainsString('maximo de páginas', $observation);
                    $this->assertSame('VALIDADOR DE DERECHOS', $documento);
                    return true;
                }

                $this->fail('Cantidad inesperada de llamadas a updateAuditResult');
            });

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    public function testSaveToDatabaseMarksTraceabilityForHumanReview(): void
    {
        $result = [
            'response' => 'human_review',
            'message' => 'Tipo de servicio requiere revisión humana',
            '_errorOrigin' => 'business',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 900],
            'data' => ['items' => []],
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult')
            ->with($this->callback(function (array $data) {
                return $data['EstadoDetallado'] === 'human_review'
                    && $data['Severidad'] === 'ninguna'
                    && $data['EstAud'] === 0
                    && $data['RequiereRevisionHumana'] === 0;
            }));

        $this->auditStatusModel
            ->expects($this->once())
            ->method('updateAuditResult')
            ->with('FAC-001', true, null, null)
            ->willReturn(true);

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    // ── Multi-documento: cada documento con hallazgos se rechaza individualmente ──

    public function testSaveToDatabaseRejectsMultipleDocumentsIndividually(): void
    {
        $result = [
            'response' => 'warning',
            'message' => 'Múltiples hallazgos',
            '_errorOrigin' => 'gemini',
            '_meta' => ['factura' => 'FAC-001', 'documentos' => [], 'totalTimeMs' => 3000],
            'data' => ['items' => [
                ['severidad' => 'alta', 'item' => 'Regimen', 'hallazgo' => 'Discrepancia régimen', 'documento' => 'VALIDADOR DE DERECHOS'],
                ['severidad' => 'media', 'item' => 'Firma', 'hallazgo' => 'Falta firma', 'documento' => 'ACTA DE ENTREGA'],
            ]],
            'severity' => 'alta',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult');

        // 3 llamadas: 1 baseline (approved=true) + 2 rechazos (uno por documento)
        $this->auditStatusModel
            ->expects($this->exactly(3))
            ->method('updateAuditResult')
            ->willReturnCallback(function ($invoice, $approved, $observacion, $documento) {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertTrue($approved);
                    $this->assertNull($observacion);
                    $this->assertNull($documento);
                    return true;
                }

                if ($call === 2) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertFalse($approved);
                    $this->assertIsString($observacion);
                    $this->assertStringContainsString('Discrepancia régimen', $observacion);
                    $this->assertSame('VALIDADOR DE DERECHOS', $documento);
                    return true;
                }

                if ($call === 3) {
                    $this->assertSame('FAC-001', $invoice);
                    $this->assertFalse($approved);
                    $this->assertIsString($observacion);
                    $this->assertStringContainsString('Falta firma', $observacion);
                    $this->assertSame('ACTA DE ENTREGA', $documento);
                    return true;
                }

                $this->fail('Cantidad inesperada de llamadas a updateAuditResult');
            });

        $this->service->saveToDatabase('DIS-001', $result, ['FacSec' => 'DIS-001', 'NumeroFactura' => 'FAC-001']);
    }

    // ── Mapping de campos en datos de persistencia ──

    public function testSaveToDatabaseMapsFieldsCorrectly(): void
    {
        $dispensation = [
            'FacSec' => 'SEC-100',
            'NumeroFactura' => 'FAC-200',
            'NitSec' => '999',
            'IPS_NIT' => '800',
            'VlrCobrado' => 150000,
        ];

        $result = [
            'response' => 'success',
            'message' => 'OK',
            '_errorOrigin' => 'gemini',
            '_meta' => [
                'factura' => 'FAC-200',
                'documentos' => ['factura.pdf'],
                'totalTimeMs' => 3000,
            ],
            'data' => ['items' => []],
            'severity' => 'ninguna',
        ];

        $this->auditStatusModel
            ->expects($this->once())
            ->method('upsertAuditResult')
            ->with($this->callback(function (array $data) {
                return $data['FacSec'] === 'SEC-100'
                    && $data['FacNro'] === 'FAC-200'
                    && $data['EstAud'] === 1
                    && $data['FacNitSec'] === '999'
                    && $data['IPS_NIT'] === '800'
                    && $data['VlrCobrado'] === 150000.0
                    && $data['DocumentosProcesados'] === 1;
            }));

        $this->service->saveToDatabase('DIS-001', $result, $dispensation);
    }
}
