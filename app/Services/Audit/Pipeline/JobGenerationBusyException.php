<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use RuntimeException;

final class JobGenerationBusyException extends RuntimeException
{
    public function __construct(public readonly string $jobId, public readonly int $chunkIndex)
    {
        parent::__construct("Lock de generación ocupado para job {$jobId} en chunk {$chunkIndex}", 423);
    }
}
