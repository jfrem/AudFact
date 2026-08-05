<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use RuntimeException;
use Throwable;

final class AttachmentDownloadException extends RuntimeException
{
    public const SOURCE_NOT_FOUND = 'SOURCE_NOT_FOUND';
    public const SOURCE_EMPTY = 'SOURCE_EMPTY';
    public const INCOMPLETE_TRANSFER = 'INCOMPLETE_TRANSFER';
    public const EXTERNAL_TRANSFER_FAILED = 'EXTERNAL_TRANSFER_FAILED';

    public function __construct(
        private readonly string $reasonCode,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
