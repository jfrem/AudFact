<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\AuditResultPersistenceModel;
use Core\SqlServerConnectionExecutor;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditResultPersistenceModelTest extends TestCase
{
    public function testPersistsBothDomainWritesWithSetBasedAttachmentUpdate(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->attachmentRows = [
            ['AdjDisId' => 41, 'AdjDisNom' => 'DISPENSA', 'DisId' => '87723098', 'DisDetId' => 7],
            ['AdjDisId' => 42, 'AdjDisNom' => 'FORMULA MEDICA', 'DisId' => '87723098', 'DisDetId' => 7],
        ];
        $model = $this->makeModel($pdo);

        $model->persist(self::auditResultData(), [
            ['documentName' => 'DISPENSA', 'approved' => true],
            [
                'documentName' => 'FORMULA MEDICA',
                'approved' => false,
                'payload' => ['hallazgos' => [['Codigo' => 'F01']]],
            ],
        ]);

        $this->assertSame(1, $pdo->beginCount);
        $this->assertSame(1, $pdo->commitCount);
        $this->assertSame(0, $pdo->rollbackCount);
        $this->assertCount(3, $pdo->preparedSql);
        $this->assertStringContainsString(
            'WITH (UPDLOCK, SERIALIZABLE)',
            $pdo->preparedSql[0]
        );
        $this->assertStringContainsString('SELECT DISTINCT', $pdo->preparedSql[1]);
        $this->assertStringContainsString(
            '(VALUES (:attachmentId0, :approved0, :observation0), ' .
            '(:attachmentId1, :approved1, :observation1))',
            $pdo->preparedSql[2]
        );
        $this->assertSame(41, $pdo->statements[2]->boundValues[':attachmentId0']);
        $this->assertSame(42, $pdo->statements[2]->boundValues[':attachmentId1']);
        $this->assertSame(1, $pdo->statements[2]->boundValues[':approved0']);
        $this->assertSame(0, $pdo->statements[2]->boundValues[':approved1']);
        $this->assertSame(['2426'], $model->invalidatedScopes);
    }

    public function testRollsBackBothWritesWhenAttachmentPersistenceFails(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->attachmentRows = [
            ['AdjDisId' => 41, 'AdjDisNom' => 'DISPENSA', 'DisId' => '87723098', 'DisDetId' => 7],
        ];
        $pdo->throwOnExecuteIndex = 2;
        $model = $this->makeModel($pdo);

        try {
            $model->persist(self::auditResultData(), [
                ['documentName' => 'DISPENSA', 'approved' => true],
            ]);
            self::fail('Se esperaba fallo de persistencia');
        } catch (RuntimeException $error) {
            $this->assertSame('database-write-failed', $error->getMessage());
        }

        $this->assertSame(0, $pdo->commitCount);
        $this->assertSame(1, $pdo->rollbackCount);
        $this->assertSame([], $model->invalidatedScopes);
    }

    public function testAssignsOrphanRejectionToDispensationFallback(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->attachmentRows = [
            ['AdjDisId' => 41, 'AdjDisNom' => 'DISPENSA', 'DisId' => '87723098', 'DisDetId' => 7],
            ['AdjDisId' => 42, 'AdjDisNom' => 'FORMULA MEDICA', 'DisId' => '87723098', 'DisDetId' => 7],
        ];
        $model = $this->makeModel($pdo);

        $model->persist(self::auditResultData(), [
            ['documentName' => 'DISPENSA', 'approved' => true],
            [
                'documentName' => 'AUTORIZACION',
                'approved' => false,
                'payload' => ['hallazgos' => [['Codigo' => 'AUT']]],
            ],
        ]);

        $update = $pdo->statements[2];
        $this->assertSame(41, $update->boundValues[':attachmentId0']);
        $this->assertSame(0, $update->boundValues[':approved0']);
        $observation = json_decode(
            (string) $update->boundValues[':observation0'],
            true
        );
        $this->assertSame('AUT', $observation['hallazgos'][0]['Codigo'] ?? null);
        $this->assertArrayNotHasKey(':attachmentId1', $update->boundValues);
    }

    public function testUpdatesFinalTimingsWithoutReadBeforeWrite(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->rowCountValue = 1;
        $model = $this->makeModel($pdo);
        $hallazgos = json_encode(
            ['items' => [], 'timings' => ['aggregation' => ['sql_persist_ms' => 25]]],
            JSON_THROW_ON_ERROR
        );

        $updated = $model->updateFinalTimings('T38250701547', $hallazgos, 42000);

        $this->assertTrue($updated);
        $this->assertCount(1, $pdo->preparedSql);
        $this->assertStringStartsWith('UPDATE', trim($pdo->preparedSql[0]));
        $this->assertStringNotContainsString('SELECT', $pdo->preparedSql[0]);
        $this->assertSame(
            'T38250701547',
            $pdo->statements[0]->boundValues[':FacNro']
        );
    }

    public function testRollbackFailurePreservesOriginalError(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->attachmentRows = [
            ['AdjDisId' => 41, 'AdjDisNom' => 'DISPENSA', 'DisId' => '87723098', 'DisDetId' => 7],
        ];
        $pdo->throwOnExecuteIndex = 2;
        $pdo->throwOnRollback = true;
        $model = $this->makeModel($pdo);

        try {
            $model->persist(self::auditResultData(), [
                ['documentName' => 'DISPENSA', 'approved' => true],
            ]);
            self::fail('Se esperaba el fallo primario.');
        } catch (RuntimeException $error) {
            $this->assertSame('database-write-failed', $error->getMessage());
        }

        $this->assertSame(1, $pdo->rollbackCount);
        $this->assertSame([], $model->invalidatedScopes);
    }

    public function testCacheInvalidatesOnceAfterRetriedCommit(): void
    {
        $first = new PersistenceFakePdo();
        $first->throwOnExecuteIndex = 0;
        $first->executeError = self::pdoError('08S01', 'Communication link failure');

        $second = new PersistenceFakePdo();
        $second->attachmentRows = [
            ['AdjDisId' => 41, 'AdjDisNom' => 'DISPENSA', 'DisId' => '87723098', 'DisDetId' => 7],
        ];

        $connections = [$first, $second];
        $opens = 0;
        $executor = new SqlServerConnectionExecutor(
            connector: static function (string $name) use (&$connections, &$opens): PDO {
                $opens++;
                $connection = array_shift($connections);
                if (!$connection instanceof PDO) {
                    throw new RuntimeException('Conexión fake agotada.');
                }

                return $connection;
            },
            sleeper: static function (int $milliseconds): void {}
        );
        $model = new TestableAuditResultPersistenceModel($executor);

        $model->persist(self::auditResultData(), [
            ['documentName' => 'DISPENSA', 'approved' => true],
        ]);

        $this->assertSame(2, $opens);
        $this->assertSame(1, $first->rollbackCount);
        $this->assertSame(1, $second->commitCount);
        $this->assertSame(['2426'], $model->invalidatedScopes);
    }

    /**
     * @return array<string,mixed>
     */
    private static function auditResultData(): array
    {
        return [
            'DisId' => '87723098',
            'FacNro' => 'T38250701547',
            'EstAud' => 0,
            'EstadoDetallado' => 'manual_review',
            'RequiereRevisionHumana' => 1,
            'Severidad' => 'alta',
            'Hallazgos' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
            'DetalleError' => 'Revisión requerida',
            'DocumentosProcesados' => 2,
            'DocumentoFallido' => 'FORMULA MEDICA',
            'DuracionProcesamientoMs' => 41000,
            'FacNitSec' => '2426',
        ];
    }

    private function makeModel(PersistenceFakePdo $pdo): TestableAuditResultPersistenceModel
    {
        return new TestableAuditResultPersistenceModel(new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => $pdo,
            sleeper: static function (int $milliseconds): void {}
        ));
    }

    private static function pdoError(string $sqlState, string $message): PDOException
    {
        $error = new PDOException("SQLSTATE[{$sqlState}] {$message}");
        $error->errorInfo = [$sqlState, 0, $message];

        return $error;
    }

    public function testMatchesDecisionsByAttachmentIdEvenWhenPhysicalNameDiffers(): void
    {
        $pdo = new PersistenceFakePdo();
        $pdo->attachmentRows = [
            ['AdjDisId' => 101, 'AdjDisNom' => 'SCAN_RAW_001.PDF', 'DisId' => '87723098', 'DisDetId' => 7],
            ['AdjDisId' => 102, 'AdjDisNom' => 'SCAN_RAW_002.PDF', 'DisId' => '87723098', 'DisDetId' => 7],
        ];
        $model = $this->makeModel($pdo);

        $model->persist(self::auditResultData(), [
            [
                'documentName' => 'FORMULA MEDICA',
                'approved' => true,
                'attachment_id' => 101,
            ],
            [
                'documentName' => 'AUTORIZACION',
                'approved' => false,
                'attachment_id' => 102,
                'payload' => ['hallazgos' => [['Codigo' => 'AUT01']]],
            ],
        ]);

        $update = $pdo->statements[2];
        $this->assertSame(101, $update->boundValues[':attachmentId0']);
        $this->assertSame(1, $update->boundValues[':approved0']);
        $this->assertSame(102, $update->boundValues[':attachmentId1']);
        $this->assertSame(0, $update->boundValues[':approved1']);
    }
}

final class TestableAuditResultPersistenceModel extends AuditResultPersistenceModel
{
    /** @var array<int,string> */
    public array $invalidatedScopes = [];

    protected function invalidateResultCache(string $scope): void
    {
        $this->invalidatedScopes[] = $scope;
    }
}

final class PersistenceFakePdo extends PDO
{
    /** @var array<int,string> */
    public array $preparedSql = [];
    /** @var array<int,PersistenceFakePdoStatement> */
    public array $statements = [];
    /** @var array<int,array<string,mixed>> */
    public array $attachmentRows = [];
    public ?int $throwOnExecuteIndex = null;
    public ?\Throwable $executeError = null;
    public bool $throwOnRollback = false;
    public int $rowCountValue = 0;
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    private bool $transactionActive = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $index = count($this->preparedSql);
        $this->preparedSql[] = $query;
        $statement = new PersistenceFakePdoStatement($this, $index);
        if (str_contains($query, 'SELECT DISTINCT')) {
            $statement->result = $this->attachmentRows;
        }
        $this->statements[] = $statement;

        return $statement;
    }

    public function beginTransaction(): bool
    {
        $this->beginCount++;
        $this->transactionActive = true;
        return true;
    }

    public function commit(): bool
    {
        $this->commitCount++;
        $this->transactionActive = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCount++;
        if ($this->throwOnRollback) {
            throw new RuntimeException('rollback-failed');
        }
        $this->transactionActive = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }
}

final class PersistenceFakePdoStatement extends PDOStatement
{
    /** @var array<string|int,mixed> */
    public array $boundValues = [];
    /** @var array<int,array<string,mixed>> */
    public array $result = [];

    public function __construct(
        private PersistenceFakePdo $pdo,
        private int $index
    ) {
    }

    public function bindValue(
        string|int $param,
        mixed $value,
        int $type = PDO::PARAM_STR
    ): bool {
        $this->boundValues[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->pdo->throwOnExecuteIndex === $this->index) {
            throw $this->pdo->executeError ?? new RuntimeException('database-write-failed');
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result;
    }

    public function rowCount(): int
    {
        return $this->pdo->rowCountValue;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
