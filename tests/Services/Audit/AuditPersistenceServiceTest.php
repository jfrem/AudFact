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
            ->withConsecutive(
                ['FAC-001', true, null, null],
                ['FAC-001', false, $this->stringContains('No coincide'), 'factura.pdf']
            )
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
            ->withConsecutive(
                ['FAC-001', true, null, null],
                ['FAC-001', false, $this->stringContains('Discrepancia régimen'), 'VALIDADOR DE DERECHOS'],
                ['FAC-001', false, $this->stringContains('Falta firma'), 'ACTA DE ENTREGA']
            )
            ->willReturn(true);

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
