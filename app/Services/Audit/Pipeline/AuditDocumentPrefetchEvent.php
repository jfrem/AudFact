<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum AuditDocumentPrefetchEvent: string
{
    case PAUSED = 'prefetch_pauses';
    case WAITED = 'prefetch_wait_ms';
}
