<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final readonly class JobLockClaimResult
{
    public function __construct(
        public JobLockClaimStatus $status,
        public ?int $epoch = null,
    ) {
        if ($this->status === JobLockClaimStatus::ACQUIRED && ($this->epoch === null || $this->epoch < 1)) {
            throw new \InvalidArgumentException('Lock ACQUIRED requiere epoch >= 1');
        }
    }
}
