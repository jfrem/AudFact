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
    public function testGetDispensationDataMapsCanonicalDisIdFromDisId(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            [
                'DisId' => '87723098',
                'NumeroFactura' => 'T38250701547',
            ],
        ];

        $result = $model->getDispensationData(['Dispensa' => 'T38250701547']);

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('facsec AS DisId', $pdo->preparedSql);
        $this->assertStringNotContainsString('facsec AS FacSec', $pdo->preparedSql);
        $this->assertStringContainsString('Dispensa AS NumeroFactura', $pdo->preparedSql);
        $this->assertStringContainsString('WHERE Dispensa = :Dispensa', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':Dispensa']);
    }

    public function testGetDispensationDataByDisIdFiltersByCanonicalDisId(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            [
                'DisId' => '87723098',
                'NumeroFactura' => 'T38250701547',
            ],
        ];

        $result = $model->getDispensationData(['DisId' => '87723098']);

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('facsec AS DisId', $pdo->preparedSql);
        $this->assertStringContainsString('WHERE facsec = :DisId', $pdo->preparedSql);
        $this->assertSame('87723098', $pdo->statement->boundValues[':DisId']);
    }

    public function testFilterSnakeCaseDisIdGeneratesCorrectSQL(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            ['DisId' => '877', 'NumeroFactura' => 'T38250701547'],
        ];

        $result = $model->getDispensationData(['dis_id' => '877']);

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('WHERE facsec = :dis_id', $pdo->preparedSql);
        $this->assertSame('877', $pdo->statement->boundValues[':dis_id']);
    }

    public function testFilterSnakeCaseDisDetNroGeneratesCorrectSQL(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            ['DisId' => '877', 'NumeroFactura' => 'T38250701547'],
        ];

        $result = $model->getDispensationData(['dis_det_nro' => 'T38250701547']);

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('WHERE Dispensa = :dis_det_nro', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':dis_det_nro']);
    }

    public function testCombinedFiltersGenerateCorrectSQL(): void
    {
        $pdo = new DispensationFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            ['DisId' => '877', 'NumeroFactura' => 'T38250701547'],
        ];

        $result = $model->getDispensationData([
            'dis_id' => '877',
            'dis_det_nro' => 'T38250701547',
        ]);

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('facsec = :dis_id', $pdo->preparedSql);
        $this->assertStringContainsString('Dispensa = :dis_det_nro', $pdo->preparedSql);
        $this->assertStringContainsString('AND', $pdo->preparedSql);
        $this->assertSame('877', $pdo->statement->boundValues[':dis_id']);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':dis_det_nro']);
    }

    public function testUnknownFilterThrowsInvalidArgumentException(): void
    {
        $model = $this->makeModelWithReadDb(new DispensationFakePdo());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filtro no permitido en DispensationModel: malicious_col');

        $model->getDispensationData(['malicious_col' => 'x']);
    }

    public function testEmptyFiltersThrowsInvalidArgumentException(): void
    {
        $model = $this->makeModelWithReadDb(new DispensationFakePdo());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No se proporcionaron filtros para DispensationModel');

        $model->getDispensationData([]);
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

    public function closeCursor(): bool
    {
        return true;
    }
}
