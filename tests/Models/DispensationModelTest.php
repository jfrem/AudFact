<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\DispensationModel;
use App\Models\Model;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class DispensationModelTest extends TestCase
{
    public function testGetDispensationDataMapsCanonicalFacSecFromFacsecF(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            [
                'FacSec' => '87723098',
                'NumeroFactura' => 'T38250701547',
            ],
        ];

        $result = $model->getDispensationData('T38250701547');

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('facsecF AS FacSec', $pdo->preparedSql);
        $this->assertStringNotContainsString('facsec AS FacSec', $pdo->preparedSql);
        $this->assertStringContainsString('Dispensa AS NumeroFactura', $pdo->preparedSql);
        $this->assertStringContainsString('WHERE Dispensa = :DisDetNro', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':DisDetNro']);
    }

    private function makeModelWithReadDb(DispensationFakePdo $pdo): DispensationModel
    {
        $reflection = new ReflectionClass(DispensationModel::class);
        /** @var DispensationModel $model */
        $model = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Model::class, 'readDb');
        $property->setAccessible(true);
        $property->setValue($model, $pdo);

        return $model;
    }
}

final class DispensationFakePdo extends PDO
{
    public string $preparedSql = '';
    public array $nextResult = [];
    public DispensationFakePdoStatement $statement;

    public function __construct()
    {
        $this->statement = new DispensationFakePdoStatement();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql = $query;
        $this->statement = new DispensationFakePdoStatement();
        $this->statement->result = $this->nextResult;
        return $this->statement;
    }
}

final class DispensationFakePdoStatement extends PDOStatement
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
