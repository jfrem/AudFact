<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use RuntimeException;
use Throwable;

/**
 * Rechazo respaldado por un diagnóstico conocido del parser PDF.
 * La ausencia de imágenes por sí sola no demuestra corrupción documental.
 */
final class RasterizationFailureException extends RuntimeException
{
    public function __construct(public readonly PdfContentFailure $reason, ?Throwable $previous = null)
    {
        parent::__construct('PDF no procesable: ' . $reason->value, 0, $previous);
    }
}
