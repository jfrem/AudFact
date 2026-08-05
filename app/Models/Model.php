<?php

declare(strict_types=1);

namespace App\Models;

use Core\SqlServerConnectionExecutor;
use Core\SqlServerOperationMode;

class Model
{
    protected SqlServerConnectionExecutor $executor;
    protected string $table = '';
    protected string $readConnectionName = 'db2';
    protected string $writeConnectionName = 'default';

    public function __construct(?SqlServerConnectionExecutor $executor = null)
    {
        $this->executor = $executor ?? new SqlServerConnectionExecutor();
    }

    protected function read(callable $operation): mixed
    {
        return $this->executor->execute(
            $this->readConnectionName,
            SqlServerOperationMode::READ,
            $operation
        );
    }

    protected function idempotentWrite(callable $operation): mixed
    {
        return $this->executor->execute(
            $this->writeConnectionName,
            SqlServerOperationMode::IDEMPOTENT_WRITE,
            $operation
        );
    }

    protected function nonReplayableWrite(callable $operation): mixed
    {
        return $this->executor->execute(
            $this->writeConnectionName,
            SqlServerOperationMode::NON_REPLAYABLE_WRITE,
            $operation
        );
    }
}
