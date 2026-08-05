<?php

declare(strict_types=1);

namespace Core;

enum SqlServerOperationMode: string
{
    case READ = 'read';
    case IDEMPOTENT_WRITE = 'idempotent_write';
    case NON_REPLAYABLE_WRITE = 'non_replayable_write';

    public function allowsReplay(): bool
    {
        return $this !== self::NON_REPLAYABLE_WRITE;
    }
}
