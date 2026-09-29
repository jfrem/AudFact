<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/** Diagnóstico interno del proceso; nunca serializar su salida en eventos o logs. */
final readonly class RasterizationProcessResult
{
    public function __construct(public int $exitCode, private string $output = '')
    {
    }

    public function contentFailure(): ?PdfContentFailure
    {
        if (!in_array($this->exitCode, [0, 1], true)) {
            return null;
        }
        // Una señal operativa prevalece sobre cualquier diagnóstico documental.
        if (preg_match('/permission denied|no space left|\/ioerror|cannot open|couldn.t open|out of memory/i', $this->output)) {
            return null;
        }
        if (preg_match('/number of pages in the file:\s*0\b|first page \(1\).*last page \(0\)|document has no pages/i', $this->output)) {
            return PdfContentFailure::NO_PAGES;
        }
        if (preg_match('/top-level pages object is wrong type|couldn.t find trailer dictionary|couldn.t read xref table/i', $this->output)) {
            return PdfContentFailure::CORRUPTED;
        }
        return null;
    }
}
