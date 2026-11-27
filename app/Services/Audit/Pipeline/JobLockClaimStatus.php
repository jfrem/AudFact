<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum JobLockClaimStatus: string
{
    case ACQUIRED = 'acquired';
    case BUSY = 'busy';
    case TERMINAL = 'terminal';
    case MISSING = 'missing';
}
