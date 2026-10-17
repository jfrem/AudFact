<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class QuotaDeferredException extends \RuntimeException
{
    public function __construct(public readonly int $dueAtMs, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
