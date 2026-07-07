<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\InvoicesModel;
use App\Models\Model;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class InvoicesModelTest extends TestCase
{
    public function testSearchInvoicesAlwaysUsesPagedRangeQuery(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [['DisId' => '1']];

        $result = $model->searchInvoices([
            'facNitSec' => 2426,
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-01',
        ], 2, 50);

        $this->assertSame([['DisId' => '1']], $result);
        $this->assertStringContainsString('INTO #Disp', $pdo->preparedSql);
        $this->assertStringContainsString('group by f.FacNitSec,dd.DisDetNro', $pdo->preparedSql);
        $this->assertStringContainsString('d.DisFecSol>=:dateFromD and d.DisFecSol<=:dateToD', $pdo->preparedSql);
        $this->assertStringContainsString('ORDER BY s.fecha ASC, s.DisId ASC, s.Dispensa ASC', $pdo->preparedSql);
        $this->assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $pdo->preparedSql);
        
        $this->assertArrayHasKey(':dateFromD', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':dateToD', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':facNitSec', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':offset', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':pageSize', $pdo->statement->boundValues);
        
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromD']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateToD']);
        $this->assertSame(2426, $pdo->statement->boundValues[':facNitSec']);
        $this->assertSame(50, $pdo->statement->boundValues[':offset']);
        $this->assertSame(50, $pdo->statement->boundValues[':pageSize']);
    }

    public function testCountInvoicesUsesSameCandidateFilters(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [['total' => 7]];

        $total = $model->countInvoices([
            'facNitSec' => 2426,
            'dateFrom' => '2025-07-01',
            'dateTo' => '2025-07-30',
        ]);

        $this->assertSame(7, $total);
        $this->assertStringContainsString('SELECT COUNT(1) AS total', $pdo->preparedSql);
        $this->assertStringContainsString('d.DisFecSol>=:dateFromD and d.DisFecSol<=:dateToD', $pdo->preparedSql);
        $this->assertStringContainsString('group by f.FacNitSec,dd.DisDetNro', $pdo->preparedSql);
        
        $this->assertSame(2426, $pdo->statement->boundValues[':facNitSec']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromD']);
        $this->assertSame('2025-07-30', $pdo->statement->boundValues[':dateToD']);
    }

    public function testGetInvoicesForAuditBatchUsesKeysetPagination(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);

        $model->getInvoicesForAuditBatch(2426, '2025-07-01', '2025-07-30', 50, [
            'date' => '2025-07-10T00:00:00',
            'disId' => '87723098',
            'dispensa' => 'T38250701547',
        ]);

        $this->assertStringContainsString('FROM (', $pdo->preparedSql);
        $this->assertStringContainsString('SELECT TOP (50)', $pdo->preparedSql);
        $this->assertStringContainsString('s.fecha > :cursorDate1', $pdo->preparedSql);
        $this->assertStringContainsString('ORDER BY s.fecha ASC, s.DisId ASC, s.Dispensa ASC', $pdo->preparedSql);
        $this->assertSame('2025-07-10T00:00:00', $pdo->statement->boundValues[':cursorDate1']);
        $this->assertSame('2025-07-10T00:00:00', $pdo->statement->boundValues[':cursorDate2']);
        $this->assertSame('2025-07-10T00:00:00', $pdo->statement->boundValues[':cursorDate3']);
        $this->assertSame('87723098', $pdo->statement->boundValues[':cursorDisId1']);
        $this->assertSame('87723098', $pdo->statement->boundValues[':cursorDisId2']);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':cursorDispensa1']);
    }

    private function makeModelWithReadDb(FakePdo $pdo): InvoicesModel
    {
        $reflection = new ReflectionClass(InvoicesModel::class);
        /** @var InvoicesModel $model */
        $model = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Model::class, 'readDb');
        $property->setAccessible(true);
        $property->setValue($model, $pdo);

        return $model;
    }
}

final class FakePdo extends PDO
{
    public string $preparedSql = '';
    public array $nextResult = [];
    public FakePdoStatement $statement;

    public function __construct()
    {
        $this->statement = new FakePdoStatement();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql = $query;
        $this->statement = new FakePdoStatement();
        $this->statement->result = $this->nextResult;
        return $this->statement;
    }
}

final class FakePdoStatement extends PDOStatement
{
    public array $boundValues = [];
    public array $result = [];

    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->boundValues[$param] = $var;
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if (is_array($params)) {
            foreach ($params as $key => $value) {
                $this->boundValues[$key] = $value;
            }
        }

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result[0] ?? false;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
