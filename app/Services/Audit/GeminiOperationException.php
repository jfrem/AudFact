<?php

declare(strict_types=1);

namespace App\Services\Audit;

final class GeminiOperationException extends \RuntimeException
{
    public function __construct(public readonly string $operation, public readonly int $httpCode = 0,
        public readonly int $retryAfterMs = 0, public readonly ?string $documentRejectionReason = null,
        public readonly int $durationMs = 0, public readonly bool $unknownResult = false)
    {
        parent::__construct('gemini_operation_failed', $httpCode);
    }
}
