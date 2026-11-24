<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use RuntimeException;

final class JobGenerationFailedException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, public readonly ?int $epoch = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
