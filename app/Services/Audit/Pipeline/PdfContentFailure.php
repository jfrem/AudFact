<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum PdfContentFailure: string
{
    case NO_PAGES = 'EMPTY_PDF_NO_PAGES';
    case CORRUPTED = 'CORRUPTED_DOCUMENT';
}
