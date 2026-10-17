<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum AuditTaskState: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case DELAYED = 'delayed';
    case COMMITTING = 'committing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
