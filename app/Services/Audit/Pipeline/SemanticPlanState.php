<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum SemanticPlanState: string
{
    case PLANNED = 'planned';
    case WAITING = 'waiting';
    case READY = 'ready';
    case CONSUMED = 'consumed';
    case FAILED = 'failed';
}
