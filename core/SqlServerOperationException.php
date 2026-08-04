<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

final class SqlServerOperationException extends RuntimeException
{
    public function __construct(
        private readonly string $connectionName,
        private readonly string $phase,
        private readonly SqlServerOperationMode $mode,
        private readonly int $attempts,
        private readonly ?string $sqlState,
        private readonly bool $retryExhausted,
        Throwable $previous
    ) {
        parent::__construct(
            sprintf(
                'Operacion SQL Server fallida en conexion %s durante %s.',
                $connectionName,
                $phase
            ),
            0,
            $previous
        );
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function mode(): SqlServerOperationMode
    {
        return $this->mode;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    public function retryExhausted(): bool
    {
        return $this->retryExhausted;
    }
}
