<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AuditCostModel;
use Core\SqlServerConnectionExecutor;
use Core\SqlServerOperationMode;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AuditCostModelTest extends TestCase
{
    public function testCalculateCostUsdFormula(): void
    {
        // 1000 tokens entrada a 0.75/1M = 0.00075
        // 500 salida + 500 pensamiento = 1000 tokens a 3.75/1M = 0.00375
        // Total = 0.00450000 USD
        $cost = AuditCostModel::calculateCostUsd(1000, 500, 500, 0.7500, 3.7500);
        $this->assertSame(0.0045, $cost);

        // Caso del prompt de usuario:
        // Entrada: 6973, Salida: 1225, Pensamiento: 1838
        // (6973 * 0.75 + (1225 + 1838) * 3.75) / 1000000
        // (5229.75 + 3063 * 3.75) / 1000000 = (5229.75 + 11486.25) / 1000000 = 16716 / 1000000 = 0.016716
        $cost2 = AuditCostModel::calculateCostUsd(6973, 1225, 1838, 0.7500, 3.7500);
        $this->assertSame(0.016716, $cost2);
    }

    public function testRecordCostComputesConsecutiveAttemptsAndCop(): void
    {
        $pdo = new AuditCostFakePdo();
        // Simular que el SELECT MAX(Intento) retorna 0 (es decir, primer intento = 1)
        $pdo->nextIntentoValue = 1;

        $model = new AuditCostModel();

        $costData = [
            'FacNro' => 'D01260811080',
            'FacNitSec' => '2624',
            'FacSec' => 'DIS-100',
            'TokensEntrada' => 6973,
            'TokensSalida' => 1225,
            'TokensPensamiento' => 1838,
            'TrmAplicada' => 3194.44,
            'DocsProcesados' => 2,
            'DocsCacheHit' => 1,
        ];

        $intentoAsignado = $model->recordCost($pdo, $costData);

        $this->assertSame(1, $intentoAsignado);
        $this->assertCount(2, $pdo->preparedSql);
        $this->assertStringContainsString('WITH (UPDLOCK, HOLDLOCK)', $pdo->preparedSql[0]);
        $this->assertStringContainsString('INSERT INTO Discolnet.dbo.AudDispCosto', $pdo->preparedSql[1]);

        $insertStmt = $pdo->statements[1];
        $this->assertSame('D01260811080', $insertStmt->boundValues[':facNro']);
        $this->assertSame('2624', $insertStmt->boundValues[':facNitSec']);
        $this->assertSame(1, $insertStmt->boundValues[':intento']);
        $this->assertSame(6973, $insertStmt->boundValues[':tokensEntrada']);
        $this->assertSame(1225, $insertStmt->boundValues[':tokensSalida']);
        $this->assertSame(1838, $insertStmt->boundValues[':tokensPensamiento']);
        $this->assertSame(10036, $insertStmt->boundValues[':totalTokens']);
        $this->assertSame(0.016716, $insertStmt->boundValues[':costoUsd']);
        $this->assertStringContainsString('COALESCE(:fechaAuditoria, GETDATE())', $pdo->preparedSql[1]);
        $this->assertNull($insertStmt->boundValues[':fechaAuditoria']);
        // 0.016716 * 3194.44 = 53.40
        $this->assertSame(round(0.016716 * 3194.44, 4), $insertStmt->boundValues[':costoCop']);
    }

    public function testRecordCostRespectsExplicitFechaAuditoriaWhenProvided(): void
    {
        $pdo = new AuditCostFakePdo();
        $pdo->nextIntentoValue = 1;
        $model = new AuditCostModel();

        $costData = [
            'FacNro' => 'D01260811080',
            'FacNitSec' => '2624',
            'FechaAuditoria' => '2026-10-19 13:59:51',
        ];

        $model->recordCost($pdo, $costData);
        $insertStmt = $pdo->statements[1];
        $this->assertSame('2026-10-19 13:59:51', $insertStmt->boundValues[':fechaAuditoria']);
    }

    public function testRecordCostSecondAttemptIncrementsConsecutive(): void
    {
        $pdo = new AuditCostFakePdo();
        // Simular que ya existía un intento previo (MAX = 1, next = 2)
        $pdo->nextIntentoValue = 2;

        $model = new AuditCostModel();

        $costData = [
            'FacNro' => 'D01260811080',
            'FacNitSec' => '2624',
            'TokensEntrada' => 0,
            'TokensSalida' => 0,
            'TokensPensamiento' => 0,
            'TrmAplicada' => 3194.44,
            'DocsProcesados' => 2,
            'DocsCacheHit' => 2,
            'EsCacheHitTotal' => 1,
        ];

        $intentoAsignado = $model->recordCost($pdo, $costData);

        $this->assertSame(2, $intentoAsignado);
        $insertStmt = $pdo->statements[1];
        $this->assertSame(2, $insertStmt->boundValues[':intento']);
        $this->assertSame(0.0, $insertStmt->boundValues[':costoUsd']);
        $this->assertSame(0.0, $insertStmt->boundValues[':costoCop']);
        $this->assertSame(1, $insertStmt->boundValues[':esCacheHitTotal']);
    }

    public function testDetailedCostsReportReturnsStructure(): void
    {
        $pdo = new AuditCostFakePdo();
        $pdo->totalsResult = [
            'total_records' => 1,
            'gran_total_tokens' => 10036,
            'costo_total_usd' => 0.016716,
            'costo_total_cop' => 53.4,
        ];
        $pdo->itemsResult = [
            [
                'aud_costo_id' => 1,
                'fac_nit_sec' => '2624',
                'cliente' => 'NUEVA EPS SA',
                'fac_nro' => 'D01260811080',
                'fac_sec' => 'DIS-100',
                'intento' => 1,
                'tokens_entrada' => 6973,
                'tokens_salida' => 1225,
                'tokens_pensamiento' => 1838,
                'total_tokens' => 10036,
                'costo_usd' => 0.016716,
                'trm_aplicada' => 3194.44,
                'costo_cop' => 53.4,
                'docs_procesados' => 2,
                'docs_cache_hit' => 1,
                'es_cache_hit_total' => 0,
                'modelo_ia' => 'gemini-3.7-flash',
                'fecha_auditoria' => '2026-10-13 13:28:39',
            ],
        ];

        $model = $this->makeModel($pdo);

        $report = $model->getDetailedCostsReport(['facNitSec' => '2624'], 1, 20);

        $this->assertSame(1, $report['pagination']['totalRecords']);
        $this->assertSame(1, $report['pagination']['totalPages']);
        $this->assertSame(10036, $report['totales_periodo']['gran_total_tokens']);
        $this->assertSame(0.016716, $report['totales_periodo']['costo_total_usd']);
        $this->assertSame(53.4, $report['totales_periodo']['costo_total_cop']);
        $this->assertCount(1, $report['items']);
        $this->assertSame('D01260811080', $report['items'][0]['fac_nro']);
        $this->assertSame('NUEVA EPS SA', $report['items'][0]['cliente']);
    }

    public function testClientAggregationsSumUsdAndCopDirectly(): void
    {
        $pdo = new AuditCostFakePdo();
        $pdo->itemsResult = [
            [
                'fac_nit_sec' => '1165',
                'cliente' => 'ENTIDAD PROMOTORA DE SALUD SANITAS S.A.S.',
                'facturas_auditadas' => 754,
                'total_intentos' => 920,
                'total_tokens' => 9234500,
                'gasto_total_usd' => 15.42105,
                'gasto_total_cop' => 49261.62,
            ],
        ];

        $model = $this->makeModel($pdo);

        $results = $model->getCostsByClient();

        $this->assertCount(1, $results);
        $this->assertSame('1165', $results[0]['fac_nit_sec']);
        $this->assertSame(754, $results[0]['facturas_auditadas']);
        $this->assertSame(920, $results[0]['total_intentos']);
        $this->assertSame(15.42105, $results[0]['gasto_total_usd']);
        $this->assertSame(49261.62, $results[0]['gasto_total_cop']);
    }

    public function testCostsByInvoiceReturnsOrderedAttempts(): void
    {
        $pdo = new AuditCostFakePdo();
        $pdo->itemsResult = [
            [
                'aud_costo_id' => 1,
                'fac_nro' => 'FAC-999',
                'fac_nit_sec' => '1165',
                'cliente' => 'SANITAS',
                'fac_sec' => 'DIS-1',
                'intento' => 1,
                'tokens_entrada' => 5000,
                'tokens_salida' => 1000,
                'tokens_pensamiento' => 1000,
                'total_tokens' => 7000,
                'costo_usd' => 0.01125,
                'trm_aplicada' => 3194.44,
                'costo_cop' => 35.9375,
                'docs_procesados' => 1,
                'docs_cache_hit' => 0,
                'es_cache_hit_total' => 0,
                'modelo_ia' => 'gemini-3.7-flash',
                'fecha_auditoria' => '2026-10-13 10:00:00',
            ],
            [
                'aud_costo_id' => 2,
                'fac_nro' => 'FAC-999',
                'fac_nit_sec' => '1165',
                'cliente' => 'SANITAS',
                'fac_sec' => 'DIS-1',
                'intento' => 2,
                'tokens_entrada' => 0,
                'tokens_salida' => 0,
                'tokens_pensamiento' => 0,
                'total_tokens' => 0,
                'costo_usd' => 0.0,
                'trm_aplicada' => 3194.44,
                'costo_cop' => 0.0,
                'docs_procesados' => 1,
                'docs_cache_hit' => 1,
                'es_cache_hit_total' => 1,
                'modelo_ia' => 'gemini-3.7-flash',
                'fecha_auditoria' => '2026-10-13 11:00:00',
            ],
        ];

        $model = $this->makeModel($pdo);

        $results = $model->getCostsByInvoice('FAC-999');

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['intento']);
        $this->assertSame(2, $results[1]['intento']);
        $this->assertTrue($results[1]['es_cache_hit_total']);
    }

    private function makeModel(AuditCostFakePdo $pdo): AuditCostModel
    {
        return new AuditCostModel(new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => $pdo,
            sleeper: static function (int $milliseconds): void {}
        ));
    }
}

final class AuditCostFakePdo extends PDO
{
    /** @var array<int, string> */
    public array $preparedSql = [];
    /** @var array<int, AuditCostFakeStatement> */
    public array $statements = [];
    public int $nextIntentoValue = 1;
    public array $totalsResult = [];
    public array $itemsResult = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;
        $statement = new AuditCostFakeStatement($this, $query);
        $this->statements[] = $statement;
        return $statement;
    }
}

final class AuditCostFakeStatement extends PDOStatement
{
    /** @var array<string|int, mixed> */
    public array $boundValues = [];

    public function __construct(
        private AuditCostFakePdo $pdo,
        private string $query
    ) {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'NextIntento')) {
            return ['NextIntento' => $this->pdo->nextIntentoValue];
        }
        if (str_contains($this->query, 'COUNT(*) AS total_records')) {
            return $this->pdo->totalsResult;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->itemsResult;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

