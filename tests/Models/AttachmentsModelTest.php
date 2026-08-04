<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AttachmentsModel;
use Core\SqlServerConnectionExecutor;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

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

    public function testGetPhysicalAttachmentsByDisDetNroReturnsPhysicalMappingFields(): void
    {
        // Arrange:
        $pdo = new AttachmentsFakePdo();
        $model = $this->makeModelWithReadDb($pdo);

        // Act:
        $model->getPhysicalAttachmentsByDisDetNro('T38250701547', '2426');

        // Assert:
        $this->assertStringContainsString('a.AdjDisId AS [attachment_id]', $pdo->preparedSql);
        $this->assertStringContainsString('n.NitMedDocId AS [physical_catalog_id]', $pdo->preparedSql);
        $this->assertStringContainsString('n.NitMedDocNom AS [physical_document_name]', $pdo->preparedSql);
        $this->assertStringNotContainsString("n.NitMedDocOpc = 'N'", $pdo->preparedSql);
        $this->assertStringContainsString('LEFT JOIN NitDocumentos n', $pdo->preparedSql);
        $this->assertStringContainsString('AND n.NitSec = :nitSec', $pdo->preparedSql);
        $this->assertStringNotContainsString('WHERE d.DisDetNro = :disDetNro AND n.NitSec', $pdo->preparedSql);
        $this->assertStringContainsString('WHERE d.DisDetNro = :disDetNro', $pdo->preparedSql);
        $this->assertSame('T38250701547', $pdo->statement->boundValues[':disDetNro']);
        $this->assertSame('2426', $pdo->statement->boundValues[':nitSec']);
    }

    public function testMaterializesBlobAndExpectedSizeInSameQuery(): void
    {
        $pdo = new AttachmentsFakePdo();
        $pdo->nextBlob = 'complete-bytes';
        $pdo->nextExpectedSize = strlen($pdo->nextBlob);
        $model = $this->makeModelWithReadDb($pdo);

        $blob = $model->getAttachmentBlobBytesByIdForDisDetNro('41', 'T38250701547');

        $this->assertSame([
            'bytes' => 'complete-bytes',
            'expected_size' => 14,
        ], $blob);
        $this->assertStringContainsString('DATALENGTH(a.AdjDisDoc)', $pdo->preparedSql);
        $this->assertSame(1, $pdo->statement->closeCount);
    }

    private function makeModelWithReadDb(AttachmentsFakePdo $pdo): AttachmentsModel
    {
        return new AttachmentsModel(new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => $pdo,
            sleeper: static function (int $milliseconds): void {}
        ));
    }
}

final class AttachmentsFakePdo extends PDO
{
    public string $preparedSql = '';
    public array $nextResult = [];
    public ?string $nextBlob = null;
    public int $nextExpectedSize = 0;
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
        $this->statement->blobBytes = $this->nextBlob;
        $this->statement->expectedSize = $this->nextExpectedSize;
        return $this->statement;
    }
}

final class AttachmentsFakePdoStatement extends PDOStatement
{
    public array $boundValues = [];
    public array $result = [];
    public ?string $blobBytes = null;
    public int $expectedSize = 0;
    public int $closeCount = 0;
    /** @var array<string|int,mixed> */
    private array $boundColumns = [];

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

    public function bindColumn(
        string|int $column,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        $this->boundColumns[$column] = &$var;
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($mode === PDO::FETCH_BOUND && $this->blobBytes !== null) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $this->blobBytes);
            rewind($stream);
            $this->boundColumns[1] = $stream;
            $this->boundColumns[2] = $this->expectedSize;
            return true;
        }

        return $this->result[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result;
    }

    public function closeCursor(): bool
    {
        $this->closeCount++;
        return true;
    }
}
