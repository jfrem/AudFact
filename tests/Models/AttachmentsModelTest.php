<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AttachmentsModel;
use App\Models\Model;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class AttachmentsModelTest extends TestCase
{
    public function testGetAttachmentsByDisDetNroUsesCanonicalAttachmentAliases(): void
    {
        $pdo = new AttachmentsFakePdo();
        $model = $this->makeModelWithReadDb($pdo);
        $pdo->nextResult = [
            [
                'dispensacion_id' => 'DIS-ID',
                'dis_det_nro' => 'T38250701547',
            ],
        ];

        $result = $model->getAttachmentsByDisDetNro('T38250701547', '2426');

        $this->assertSame($pdo->nextResult, $result);
        $this->assertStringContainsString('a.DisId AS [dispensacion_id]', $pdo->preparedSql);
        $this->assertStringContainsString('d.DisDetNro AS [dis_det_nro]', $pdo->preparedSql);
        $this->assertStringNotContainsString('[dispiensa]', $pdo->preparedSql);
        $this->assertStringNotContainsString('AS [factura]', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':disDetNro']);
        $this->assertSame('2426', $pdo->statement->boundValues[':nitSec']);
    }

    public function testGetRequiredAttachmentsByDisDetNroFiltersRequiredDocuments(): void
    {
        $pdo = new AttachmentsFakePdo();
        $model = $this->makeModelWithReadDb($pdo);

        $model->getRequiredAttachmentsByDisDetNro('T38250701547', '2426');

        $this->assertStringContainsString("n.NitMedDocOpc = 'N'", $pdo->preparedSql);
        $this->assertStringContainsString('WHERE d.DisDetNro = :disDetNro', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':disDetNro']);
        $this->assertSame('2426', $pdo->statement->boundValues[':nitSec']);
    }

    private function makeModelWithReadDb(AttachmentsFakePdo $pdo): AttachmentsModel
    {
        $reflection = new ReflectionClass(AttachmentsModel::class);
        /** @var AttachmentsModel $model */
        $model = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Model::class, 'readDb');
        $property->setAccessible(true);
        $property->setValue($model, $pdo);

        return $model;
    }
}

final class AttachmentsFakePdo extends PDO
{
    public string $preparedSql = '';
    public array $nextResult = [];
    public AttachmentsFakePdoStatement $statement;

    public function __construct()
    {
        $this->statement = new AttachmentsFakePdoStatement();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql = $query;
        $this->statement = new AttachmentsFakePdoStatement();
        $this->statement->result = $this->nextResult;
        return $this->statement;
    }
}

final class AttachmentsFakePdoStatement extends PDOStatement
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
