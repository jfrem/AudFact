<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class DocumentHashMismatchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('document_hash no coincide con el BLOB descargado');
    }
}
