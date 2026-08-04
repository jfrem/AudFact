<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\SqlServerConnectionExecutor;
use Core\SqlServerOperationException;
use Core\SqlServerOperationMode;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class SqlServerConnectionExecutorTest extends TestCase
{
    public function testOutagesAtOneSixAndThirtySecondsUseExactBackoff(): void
    {
        foreach ([
            2 => [1000],
            3 => [1000, 5000],
            4 => [1000, 5000, 30000],
        ] as $successAttempt => $expectedDelays) {
            $attempt = 0;
            $delays = [];
            $clockNs = 0;
            $seenPdoIds = [];
            $createdPdos = [];

            $executor = new SqlServerConnectionExecutor(
                connector: static function (string $name) use (&$createdPdos): PDO {
                    $connection = new ExecutorFakePdo();
                    $createdPdos[] = $connection;

                    return $connection;
                },
                sleeper: static function (int $milliseconds) use (&$delays, &$clockNs): void {
                    $delays[] = $milliseconds;
                    $clockNs += $milliseconds * 1_000_000;
                },
                clock: static fn(): int => $clockNs
            );

            $result = $executor->execute(
                'db2',
                SqlServerOperationMode::READ,
                static function (PDO $connection) use (
                    &$attempt,
                    $successAttempt,
                    &$seenPdoIds
                ): string {
                    $attempt++;
                    $seenPdoIds[] = spl_object_id($connection);
                    if ($attempt < $successAttempt) {
                        throw self::pdoError('08S01', 'Communication link failure');
                    }

                    return 'ok';
                }
            );

            $this->assertSame('ok', $result);
            $this->assertSame($expectedDelays, $delays);
            $this->assertCount($successAttempt, array_unique($seenPdoIds));
        }
    }

    public function testHyt00AtConnectIsRetried(): void
    {
        $connects = 0;
        $delays = [];
        $executor = new SqlServerConnectionExecutor(
            connector: static function (string $name) use (&$connects): PDO {
                $connects++;
                if ($connects === 1) {
                    throw self::pdoError('HYT00', 'Login timeout expired');
                }

                return new ExecutorFakePdo();
            },
            sleeper: static function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            }
        );

        $result = $executor->execute(
            'db2',
            SqlServerOperationMode::READ,
            static fn(PDO $connection): string => 'ok'
        );

        $this->assertSame('ok', $result);
        $this->assertSame(2, $connects);
        $this->assertSame([1000], $delays);
    }

    public function testHyt00AtOperationIsNotReplayed(): void
    {
        $calls = 0;
        $delays = [];
        $executor = new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => new ExecutorFakePdo(),
            sleeper: static function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            }
        );

        try {
            $executor->execute(
                'db2',
                SqlServerOperationMode::READ,
                static function (PDO $connection) use (&$calls): never {
                    $calls++;
                    throw self::pdoError('HYT00', 'Query timeout expired');
                }
            );
            self::fail('Se esperaba SqlServerOperationException.');
        } catch (SqlServerOperationException $error) {
            $this->assertSame('operation', $error->phase());
            $this->assertSame('HYT00', $error->sqlState());
            $this->assertSame(1, $error->attempts());
            $this->assertFalse($error->retryExhausted());
        }

        $this->assertSame(1, $calls);
        $this->assertSame([], $delays);
    }

    public function testRetryExhaustionReportsFourthAttempt(): void
    {
        $calls = 0;
        $delays = [];
        $executor = new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => new ExecutorFakePdo(),
            sleeper: static function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            }
        );

        try {
            $executor->execute(
                'default',
                SqlServerOperationMode::IDEMPOTENT_WRITE,
                static function (PDO $connection) use (&$calls): never {
                    $calls++;
                    throw self::pdoError('08S01', 'Communication link failure');
                }
            );
            self::fail('Se esperaba agotamiento de reintentos.');
        } catch (SqlServerOperationException $error) {
            $this->assertSame(4, $error->attempts());
            $this->assertTrue($error->retryExhausted());
            $this->assertSame('default', $error->connectionName());
        }

        $this->assertSame(4, $calls);
        $this->assertSame([1000, 5000, 30000], $delays);
    }

    public function testShutdownMarkerAtOperationIsRetried(): void
    {
        $calls = 0;
        $executor = new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => new ExecutorFakePdo(),
            sleeper: static function (int $milliseconds): void {}
        );

        $result = $executor->execute(
            'default',
            SqlServerOperationMode::IDEMPOTENT_WRITE,
            static function (PDO $connection) use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('SHUTDOWN is in progress.');
                }

                return 'ok';
            }
        );

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function testDeadlockIsNotRetried(): void
    {
        $calls = 0;
        $executor = new SqlServerConnectionExecutor(
            connector: static fn(string $name): PDO => new ExecutorFakePdo(),
            sleeper: static function (int $milliseconds): void {}
        );

        try {
            $executor->execute(
                'default',
                SqlServerOperationMode::IDEMPOTENT_WRITE,
                static function (PDO $connection) use (&$calls): never {
                    $calls++;
                    throw self::pdoError('40001', 'Deadlock victim 1205');
                }
            );
            self::fail('Se esperaba deadlock no reintentable.');
        } catch (SqlServerOperationException $error) {
            $this->assertSame('40001', $error->sqlState());
            $this->assertFalse($error->retryExhausted());
        }

        $this->assertSame(1, $calls);
    }

    public function testNonReplayableWriteUsesOneConnectionAttempt(): void
    {
        $connects = 0;
        $executor = new SqlServerConnectionExecutor(
            connector: static function (string $name) use (&$connects): PDO {
                $connects++;
                throw self::pdoError('HYT00', 'Login timeout expired');
            },
            sleeper: static function (int $milliseconds): void {}
        );

        try {
            $executor->execute(
                'default',
                SqlServerOperationMode::NON_REPLAYABLE_WRITE,
                static fn(PDO $connection): bool => true
            );
            self::fail('Se esperaba fallo de conexión no replayable.');
        } catch (SqlServerOperationException $error) {
            $this->assertSame(1, $error->attempts());
            $this->assertSame('connect', $error->phase());
        }

        $this->assertSame(1, $connects);
    }

    private static function pdoError(string $sqlState, string $message): PDOException
    {
        $error = new PDOException("SQLSTATE[{$sqlState}] {$message}");
        $error->errorInfo = [$sqlState, 0, $message];

        return $error;
    }
}

final class ExecutorFakePdo extends PDO
{
    public function __construct()
    {
    }
}
