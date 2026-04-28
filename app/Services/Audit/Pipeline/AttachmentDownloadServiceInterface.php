<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Contrato para la descarga de adjuntos de dispensación usada por el pipeline de auditoría.
 *
 * Abstrae la fuente del binario (BLOB SQL Server o Google Drive URL) y retorna
 * siempre el mismo contrato: mime + data en base64.
 *
 * Implementaciones posibles:
 *   - AttachmentDownloadService : acceso directo a AttachmentsModel + GoogleDriveAuthService
 *   - Stub en tests unitarios   : datos en memoria sin BD ni red
 */
interface AttachmentDownloadServiceInterface
{
    /**
     * Descarga un adjunto y lo retorna como base64.
     *
     * @param  string $attachmentId  AdjDisId (clave del adjunto en AdjuntosDispensacion)
     * @param  string $disDetNro     Número de factura/dispensación (para validar la relación)
     * @return array{mime:string,data:string,duration_ms:int}
     * @throws \RuntimeException  Si el adjunto no existe, está vacío o la descarga falla
     */
    public function download(string $attachmentId, string $disDetNro): array;
}
