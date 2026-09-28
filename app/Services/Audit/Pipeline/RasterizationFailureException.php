<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use RuntimeException;
use Throwable;

/**
 * Excepción emitida cuando la rasterización de un PDF produce 0 imágenes.
 *
 * Transporta el documento original para que el llamador pueda clasificar
 * el fallo como rechazo documental con contexto completo.
 */
final class RasterizationFailureException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $document;

    /**
     * @param array<string,mixed> $document Documento original (mime+data) que falló
     */
    public function __construct(string $message, array $document, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->document = $document;
    }

    /** @return array<string,mixed> */
    public function getDocument(): array
    {
        return $this->document;
    }
}
