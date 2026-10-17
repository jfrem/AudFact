<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditCostController;
use App\Models\AuditCostModel;
use App\Services\Finance\CurrencyExchangeService;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class AuditCostControllerTest extends TestCase
{
    public function testIndexReturnsDetailedReportAndTrm(): void
    {
        $mockModel = $this->createMock(AuditCostModel::class);
        $mockCurrency = $this->createMock(CurrencyExchangeService::class);

        $mockModel->expects($this->once())
            ->method('getDetailedCostsReport')
            ->willReturn([
                'pagination' => [
                    'page' => 1,
                    'pageSize' => 20,
                    'totalRecords' => 1,
                    'totalPages' => 1,
                ],
                'totales_periodo' => [
                    'gran_total_tokens' => 10036,
                    'costo_total_usd' => 0.016716,
                    'costo_total_cop' => 53.4,
                ],
                'items' => [
                    [
                        'aud_costo_id' => 1,
                        'fac_nro' => 'D01260811080',
                    ],
                ],
            ]);

        $mockCurrency->expects($this->once())
            ->method('getUsdCopRate')
            ->willReturn([
                'trm' => 3194.44,
                'source' => 'datos_gov_co',
                'updated_at' => '2026-10-13 10:00:00',
            ]);

        $controller = new AuditCostController($mockModel, $mockCurrency);

        try {
            $controller->index();
            $this->fail('Se esperaba HttpResponseException de Response::success');
        } catch (HttpResponseException $e) {
            $data = json_decode($e->getMessage(), true);
            $this->assertTrue($data['success']);
            $this->assertSame(3194.44, $data['data']['trm_vigente_hoy']);
            $this->assertSame('datos_gov_co', $data['data']['trm_fuente']);
            $this->assertSame(1, $data['data']['pagination']['totalRecords']);
            $this->assertCount(1, $data['data']['items']);
        }
    }

    public function testByClientsReturnsAggregatedList(): void
    {
        $mockModel = $this->createMock(AuditCostModel::class);
        $mockModel->expects($this->once())
            ->method('getCostsByClient')
            ->willReturn([
                [
                    'fac_nit_sec' => '1165',
                    'cliente' => 'SANITAS',
                    'facturas_auditadas' => 50,
                    'total_intentos' => 60,
                    'gasto_total_usd' => 1.25,
                    'gasto_total_cop' => 3993.05,
                ],
            ]);

        $controller = new AuditCostController($mockModel);

        try {
            $controller->byClients();
            $this->fail('Se esperaba HttpResponseException');
        } catch (HttpResponseException $e) {
            $data = json_decode($e->getMessage(), true);
            $this->assertTrue($data['success']);
            $this->assertCount(1, $data['data']['items']);
            $this->assertSame('1165', $data['data']['items'][0]['fac_nit_sec']);
        }
    }

    public function testByInvoiceReturnsAttemptsAndCalculatesTotals(): void
    {
        $mockModel = $this->createMock(AuditCostModel::class);
        $mockModel->expects($this->once())
            ->method('getCostsByInvoice')
            ->with('FAC-123')
            ->willReturn([
                [
                    'aud_costo_id' => 1,
                    'fac_nro' => 'FAC-123',
                    'intento' => 1,
                    'total_tokens' => 5000,
                    'costo_usd' => 0.01000000,
                    'costo_cop' => 31.9444,
                ],
                [
                    'aud_costo_id' => 2,
                    'fac_nro' => 'FAC-123',
                    'intento' => 2,
                    'total_tokens' => 0,
                    'costo_usd' => 0.0,
                    'costo_cop' => 0.0,
                ],
            ]);

        $controller = new AuditCostController($mockModel);

        try {
            $controller->byInvoice('FAC-123');
            $this->fail('Se esperaba HttpResponseException');
        } catch (HttpResponseException $e) {
            $data = json_decode($e->getMessage(), true);
            $this->assertTrue($data['success']);
            $this->assertSame('FAC-123', $data['data']['fac_nro']);
            $this->assertSame(2, $data['data']['total_intentos']);
            $this->assertSame(5000, $data['data']['gran_total_tokens']);
            $this->assertSame(0.01, $data['data']['costo_total_acumulado_usd']);
            $this->assertSame(31.9444, $data['data']['costo_total_acumulado_cop']);
            $this->assertCount(2, $data['data']['intentos']);
        }
    }

    public function testByInvoiceNotFoundReturns404(): void
    {
        $mockModel = $this->createMock(AuditCostModel::class);
        $mockModel->expects($this->once())
            ->method('getCostsByInvoice')
            ->with('NON_EXISTENT')
            ->willReturn([]);

        $controller = new AuditCostController($mockModel);

        try {
            $controller->byInvoice('NON_EXISTENT');
            $this->fail('Se esperaba HttpResponseException de Response::error');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getCode());
            $data = $e->getData();
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('NON_EXISTENT', $data['message']);
        }
    }

    public function testCurrentTrmReturnsRate(): void
    {
        $mockCurrency = $this->createMock(CurrencyExchangeService::class);
        $mockCurrency->expects($this->once())
            ->method('getUsdCopRate')
            ->willReturn([
                'trm' => 3200.00,
                'source' => 'static_fallback',
                'updated_at' => '2026-10-13 12:00:00',
            ]);

        $controller = new AuditCostController(null, $mockCurrency);

        try {
            $controller->currentTrm();
            $this->fail('Se esperaba HttpResponseException');
        } catch (HttpResponseException $e) {
            $data = $e->getData();
            $this->assertTrue($data['success']);
            $this->assertEquals(3200.00, $data['data']['trm']);
            $this->assertSame('static_fallback', $data['data']['source']);
        }
    }
}
