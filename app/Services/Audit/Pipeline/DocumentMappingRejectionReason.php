<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class DocumentMappingRejectionReason
{
    public const CATEGORY = 'DOCUMENT_MAPPING';
    public const DOCUMENT_ATTACHMENT_MISSING = 'DOCUMENT_ATTACHMENT_MISSING';
    public const DOCUMENT_ATTACHMENT_AMBIGUOUS = 'DOCUMENT_ATTACHMENT_AMBIGUOUS';
    public const DOCUMENT_ATTACHMENT_NO_CONTENT = 'DOCUMENT_ATTACHMENT_NO_CONTENT';
    public const DOCUMENT_ATTACHMENT_REUSED = 'DOCUMENT_ATTACHMENT_REUSED';

    private const ALLOWED = [
        self::DOCUMENT_ATTACHMENT_MISSING,
        self::DOCUMENT_ATTACHMENT_AMBIGUOUS,
        self::DOCUMENT_ATTACHMENT_NO_CONTENT,
        self::DOCUMENT_ATTACHMENT_REUSED,
    ];

    private function __construct()
    {
    }

    public static function isAllowed(string $reason): bool
    {
        return in_array($reason, self::ALLOWED, true);
    }
}
