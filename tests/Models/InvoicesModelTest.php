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
    public function testGetInvoicesAlwaysUsesRangeQuery(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [['FacSec' => '1']];

        // Testing single day using same date for from and to
        $result = $model->getInvoices(2426, '2025-07-01', '2025-07-01', 100);

        $this->assertSame([['FacSec' => '1']], $result);
        $this->assertStringContainsString('tb3.FacSec FacSec', $pdo->preparedSql);
        $this->assertStringContainsString('ON a.FacSec = tb3.FacSec', $pdo->preparedSql);
        $this->assertStringContainsString('GROUP BY tb3.FacNitSec, tb3.FacSec, tb2.DisDetNro', $pdo->preparedSql);
        $this->assertStringContainsString('tb1.DisFecSol >= :dateFromD AND tb1.DisFecSol <= :dateToD', $pdo->preparedSql);
        $this->assertStringContainsString('having sum(tb4.KarUniCP-tb4.KarUni) = 0', $pdo->preparedSql);
        
        $this->assertArrayHasKey(':dateFromD', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':dateToD', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':facNitSec', $pdo->statement->boundValues);
        
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromD']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateToD']);
        $this->assertSame(2426, $pdo->statement->boundValues[':facNitSec']);
    }

    public function testGetInvoicesBuildsRangeQueryWhenDateToIsPresent(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);

        $model->getInvoices(2426, '2025-07-01', '2025-07-30', 900);

        $this->assertStringContainsString('tb1.DisFecSol >= :dateFromD AND tb1.DisFecSol <= :dateToD', $pdo->preparedSql);
        $this->assertStringContainsString('SELECT TOP(900)', $pdo->preparedSql);
        
        $this->assertSame(2426, $pdo->statement->boundValues[':facNitSec']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromD']);
        $this->assertSame('2025-07-30', $pdo->statement->boundValues[':dateToD']);
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
}
