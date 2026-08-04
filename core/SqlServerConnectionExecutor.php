<?php

declare(strict_types=1);

namespace Core;

use Closure;
use PDO;
use PDOException;
use Throwable;

final class SqlServerConnectionExecutor
{
    private const MAX_ATTEMPTS = 4;
    private const RETRY_DELAYS_MS = [1000, 5000, 30000];

    private Closure $connector;
    private Closure $sleeper;
    private Closure $clock;

    public function __construct(
        ?Closure $connector = null,
        ?Closure $sleeper = null,
        ?Closure $clock = null
    ) {
        $this->connector = $connector
            ?? static fn(string $name): PDO => Database::openConnection($name);
        $this->sleeper = $sleeper
            ?? static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
        $this->clock = $clock
            ?? static fn(): int => hrtime(true);
    }

    public function execute(
        string $connectionName,
        SqlServerOperationMode $mode,
        callable $operation
    ): mixed {
        $maximumAttempts = $mode->allowsReplay() ? self::MAX_ATTEMPTS : 1;
        $startedAt = ($this->clock)();

        for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
            try {
                $connection = ($this->connector)($connectionName);
            } catch (Throwable $error) {
                if (!$this->isSqlFailure($error)) {
                    throw $error;
                }

                $sqlState = $this->extractSqlState($error);
                $retryable = $mode->allowsReplay()
                    && $this->isRetryableConnectFailure($error, $sqlState);

                if (!$retryable || $attempt === $maximumAttempts) {
                    throw $this->operationException(
                        $connectionName,
                        'connect',
                        $mode,
                        $attempt,
                        $sqlState,
                        $retryable && $attempt === $maximumAttempts,
                        $error
                    );
                }

                $this->waitBeforeRetry(
                    $connectionName,
                    'connect',
                    $mode,
                    $attempt,
                    $sqlState,
                    $startedAt
                );
                continue;
            }

            try {
                return $operation($connection);
            } catch (Throwable $error) {
                if (!$this->isSqlFailure($error)) {
                    throw $error;
                }

                $sqlState = $this->extractSqlState($error);
                $retryable = $mode->allowsReplay()
                    && $this->isRetryableOperationFailure($error, $sqlState);

                if (!$retryable || $attempt === $maximumAttempts) {
                    throw $this->operationException(
                        $connectionName,
                        'operation',
                        $mode,
                        $attempt,
                        $sqlState,
                        $retryable && $attempt === $maximumAttempts,
                        $error
                    );
                }

                $this->waitBeforeRetry(
                    $connectionName,
                    'operation',
                    $mode,
                    $attempt,
                    $sqlState,
                    $startedAt
                );
            } finally {
                unset($connection);
            }
        }

        throw new \LogicException('Politica SQL Server termino sin resultado.');
    }

    private function waitBeforeRetry(
        string $connectionName,
        string $phase,
        SqlServerOperationMode $mode,
        int $attempt,
        ?string $sqlState,
        int $startedAt
    ): void {
        $delayMs = self::RETRY_DELAYS_MS[$attempt - 1];
        $elapsedMs = max(
            0,
            (int) round(((($this->clock)() - $startedAt) / 1_000_000))
        );

        Logger::warning('SqlServerConnectionExecutor: reintento transitorio', [
            'connection_name' => $connectionName,
            'phase' => $phase,
            'mode' => $mode->value,
            'attempt' => $attempt,
            'sql_state' => $sqlState,
            'delay_ms' => $delayMs,
            'elapsed_ms' => $elapsedMs,
        ]);

        ($this->sleeper)($delayMs);
    }

    private function operationException(
        string $connectionName,
        string $phase,
        SqlServerOperationMode $mode,
        int $attempts,
        ?string $sqlState,
        bool $retryExhausted,
        Throwable $previous
    ): SqlServerOperationException {
        Logger::error('SqlServerConnectionExecutor: operacion fallida', [
            'connection_name' => $connectionName,
            'phase' => $phase,
            'mode' => $mode->value,
            'attempts' => $attempts,
            'sql_state' => $sqlState,
            'retry_exhausted' => $retryExhausted,
            'error_class' => $previous::class,
        ]);

        return new SqlServerOperationException(
            $connectionName,
            $phase,
            $mode,
            $attempts,
            $sqlState,
            $retryExhausted,
            $previous
        );
    }

    private function isRetryableConnectFailure(Throwable $error, ?string $sqlState): bool
    {
        return $this->isConnectionSqlState($sqlState)
            || $sqlState === 'HYT00'
            || $this->containsShutdownMarker($error);
    }

    private function isRetryableOperationFailure(Throwable $error, ?string $sqlState): bool
    {
        return $this->isConnectionSqlState($sqlState)
            || $this->containsShutdownMarker($error);
    }

    private function isConnectionSqlState(?string $sqlState): bool
    {
        return $sqlState !== null && str_starts_with($sqlState, '08');
    }

    private function containsShutdownMarker(Throwable $error): bool
    {
        foreach ($this->throwableChain($error) as $current) {
            if (stripos($current->getMessage(), 'SHUTDOWN is in progress') !== false) {
                return true;
            }
        }

        return false;
    }

    private function isSqlFailure(Throwable $error): bool
    {
        foreach ($this->throwableChain($error) as $current) {
            if (
                $current instanceof PDOException
                || $this->extractSqlStateFromThrowable($current) !== null
                || stripos($current->getMessage(), 'SHUTDOWN is in progress') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    private function extractSqlState(Throwable $error): ?string
    {
        foreach ($this->throwableChain($error) as $current) {
            $sqlState = $this->extractSqlStateFromThrowable($current);
            if ($sqlState !== null) {
                return $sqlState;
            }
        }

        return null;
    }

    private function extractSqlStateFromThrowable(Throwable $error): ?string
    {
        if ($error instanceof PDOException) {
            $errorInfo = $error->errorInfo ?? null;
            if (is_array($errorInfo) && isset($errorInfo[0])) {
                $normalized = $this->normalizeSqlState($errorInfo[0]);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $normalizedCode = $this->normalizeSqlState($error->getCode());
        if ($normalizedCode !== null) {
            return $normalizedCode;
        }

        if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/i', $error->getMessage(), $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function normalizeSqlState(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z0-9]{5}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return iterable<Throwable>
     */
    private function throwableChain(Throwable $error): iterable
    {
        $current = $error;
        do {
            yield $current;
            $current = $current->getPrevious();
        } while ($current instanceof Throwable);
    }
}
