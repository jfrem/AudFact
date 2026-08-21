<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class DocumentRejectionReason
{
    public const REJECTION_CLASS = 'document_content';
    public const EMPTY_DOCUMENT = 'EMPTY_DOCUMENT';
    public const DOCUMENT_TOO_SMALL = 'DOCUMENT_TOO_SMALL';
    public const UNSUPPORTED_MIME = 'UNSUPPORTED_MIME';
    public const UNKNOWN_FILE_SIGNATURE = 'UNKNOWN_FILE_SIGNATURE';
    public const MIME_MISMATCH = 'MIME_MISMATCH';
    public const ENCRYPTED_DOCUMENT = 'ENCRYPTED_DOCUMENT';
    public const EMPTY_PDF_NO_PAGES = 'EMPTY_PDF_NO_PAGES';
    public const GEMINI_DECODE_FAILURE = 'GEMINI_DECODE_FAILURE';
    public const CORRUPTED_DOCUMENT = 'CORRUPTED_DOCUMENT';

    private const ALLOWED = [
        self::EMPTY_DOCUMENT,
        self::DOCUMENT_TOO_SMALL,
        self::UNSUPPORTED_MIME,
        self::UNKNOWN_FILE_SIGNATURE,
        self::MIME_MISMATCH,
        self::ENCRYPTED_DOCUMENT,
        self::EMPTY_PDF_NO_PAGES,
        self::GEMINI_DECODE_FAILURE,
        self::CORRUPTED_DOCUMENT,
    ];

    private function __construct()
    {
    }

    public static function isAllowed(string $reason): bool
    {
        return in_array($reason, self::ALLOWED, true);
    }
}
