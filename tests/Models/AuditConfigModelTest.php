<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AuditConfigModel;
use Core\SqlServerConnectionExecutor;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AuditConfigModelTest extends TestCase
{
    public function testGetConfigMapsAplicaServicioInFieldsAndVisualChecks(): void
    {
        $pdo = new AuditConfigFakePdo();
        $pdo->headerRow = [
            'FacNitSec' => '2624',
            'SystemPrompt' => null,
            'Activo' => 1,
            'FactorConv' => 0,
        ];
        $pdo->fieldRows = [
            [
                'docId' => 3,
                'docNombre' => 'FORMULA MEDICA',
                'CampoNombre' => 'NombrePaciente',
                'TipoCampo' => 'S',
                'TipoDato' => 'person_name',
                'CodigoCampo' => 'PAC',
                'EsVisual' => 0,
                'DescripcionDefault' => 'Nombre',
                'SeveridadDefault' => 'alta',
                'Orden' => 6,
                'DescripcionOverride' => null,
                'SeveridadOverride' => null,
                'AplicaServicio' => 'TODOS',
            ],
            [
                'docId' => 3,
                'docNombre' => 'FORMULA MEDICA',
                'CampoNombre' => 'FirmaPrescriptor',
                'TipoCampo' => 'V',
                'TipoDato' => 'visual',
                'CodigoCampo' => 'FPRE',
                'EsVisual' => 1,
                'DescripcionDefault' => 'Firma',
                'SeveridadDefault' => 'alta',
                'Orden' => 13,
                'DescripcionOverride' => null,
                'SeveridadOverride' => null,
                'AplicaServicio' => 'POS',
            ],
        ];

        $model = $this->makeModel($pdo);
        $result = $model->getConfig('2624');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('documents', $result);
        $documents = $result['documents'];
        $this->assertArrayHasKey('FORMULA MEDICA', $documents);

        $formulaDoc = $documents['FORMULA MEDICA'];
        $this->assertCount(1, $formulaDoc['fields']);
        $this->assertSame('TODOS', $formulaDoc['fields'][0]['aplicaServicio']);

        $this->assertCount(1, $formulaDoc['visualChecks']);
        $this->assertSame('POS', $formulaDoc['visualChecks'][0]['aplicaServicio']);
    }

    public function testSaveConfigInsertsAplicaServicioInAudDispCampo(): void
    {
        $pdo = new AuditConfigFakePdo();
        $model = $this->makeModel($pdo);

        $fields = [
            [
                'docId' => 3,
                'campoNombre' => 'FirmaPrescriptor',
                'orden' => 13,
                'description' => null,
                'severity' => 'alta',
                'aplicaServicio' => 'POS',
            ],
        ];

        $success = $model->saveConfig('2624', $fields, null, false);
        $this->assertTrue($success);

        $this->assertSame(1, $pdo->beginCount);
        $this->assertSame(1, $pdo->commitCount);

        // Verify INSERT SQL has AplicaServicio and bound value is 'POS'
        $insertStmt = null;
        foreach ($pdo->statements as $stmt) {
            if (str_contains($stmt->sql, 'INSERT INTO Discolnet.dbo.AudDispCampo')) {
                $insertStmt = $stmt;
                break;
            }
        }

        $this->assertNotNull($insertStmt);
        $this->assertStringContainsString('AplicaServicio', $insertStmt->sql);
        $this->assertSame('POS', $insertStmt->boundValues[':aplicaServicio']);
    }

    private function makeModel(AuditConfigFakePdo $pdo): AuditConfigModel
    {
        $executor = new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => $pdo,
            sleeper: static function (int $milliseconds): void {}
        );

        return new AuditConfigModel($executor);
    }
}

final class AuditConfigFakePdo extends PDO
{
    public ?array $headerRow = null;
    public array $fieldRows = [];
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public array $statements = [];
    private bool $inTx = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->beginCount++;
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->commitCount++;
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCount++;
        $this->inTx = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = new AuditConfigFakeStatement($query, $this);
        $this->statements[] = $stmt;
        return $stmt;
    }
}

final class AuditConfigFakeStatement extends PDOStatement
{
    public string $sql;
    public array $boundValues = [];
    private AuditConfigFakePdo $pdo;

    public function __construct(string $sql, AuditConfigFakePdo $pdo)
    {
        $this->sql = $sql;
        $this->pdo = $pdo;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->boundValues[(string) $param] = $value;
        return true;
    }

    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->boundValues[(string) $param] = $var;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            foreach ($params as $k => $v) {
                $this->boundValues[(string) $k] = $v;
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM Discolnet.dbo.AudDisp WITH (NOLOCK)')) {
            return $this->pdo->headerRow ?: false;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->sql, 'FROM Discolnet.dbo.AudDispCampo ac WITH (NOLOCK)')) {
            return $this->pdo->fieldRows;
        }
        return [];
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
