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
    public function testGetInvoicesBuildsSingleDateQueryWhenDateToIsNull(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [['FacSec' => '1']];

        $result = $model->getInvoices(2426, '2025-07-01', null, 100);

        $this->assertSame([['FacSec' => '1']], $result);
        $this->assertStringContainsString('d.Fecha_solicitud = :dateFromD', $pdo->preparedSql);
        $this->assertStringContainsString('f.Fecha = :dateFromF', $pdo->preparedSql);
        $this->assertStringNotContainsString('f.Fecha >= :dateFromF', $pdo->preparedSql);
        $this->assertStringContainsString('NOT EXISTS (', $pdo->preparedSql);
        $this->assertStringContainsString('EXISTS (', $pdo->preparedSql);
        $this->assertStringNotContainsString('having sum(isnull(f.KarUni,0))=0', $pdo->preparedSql);
        $this->assertArrayHasKey(':dateFromD', $pdo->statement->boundValues);
        $this->assertArrayHasKey(':dateFromF', $pdo->statement->boundValues);
        $this->assertArrayNotHasKey(':dateToD', $pdo->statement->boundValues);
        $this->assertArrayNotHasKey(':dateToF', $pdo->statement->boundValues);
    }

    public function testGetInvoicesBuildsRangeQueryWhenDateToIsPresent(): void
    {
        $pdo = new FakePdo();
        $model = $this->makeModelWithReadDb($pdo);

        $model->getInvoices(2426, '2025-07-01', '2025-07-30', 900);

        $this->assertStringContainsString('d.Fecha_solicitud >= :dateFromD AND d.Fecha_solicitud <= :dateToD', $pdo->preparedSql);
        $this->assertStringContainsString('f.Fecha >= :dateFromF AND f.Fecha <= :dateToF', $pdo->preparedSql);
        $this->assertStringContainsString('SELECT DISTINCT TOP (900)', $pdo->preparedSql);
        $this->assertSame(2426, $pdo->statement->boundValues[':facNitSec']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromD']);
        $this->assertSame('2025-07-01', $pdo->statement->boundValues[':dateFromF']);
        $this->assertSame('2025-07-30', $pdo->statement->boundValues[':dateToD']);
        $this->assertSame('2025-07-30', $pdo->statement->boundValues[':dateToF']);
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
