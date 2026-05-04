<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Services\GoogleDriveAuthService;
use Core\Logger;
use RuntimeException;

/**
 * Servicio de descarga de adjuntos de dispensación para el pipeline de auditoría.
 *
 * Resuelve adjuntos BLOB/URL desde el modelo y entrega el contenido binario
 * en base64 al extractor documental del pipeline.
 *
 * Contrato de retorno: array{mime:string, data:string (base64), duration_ms:int}
 */
class AttachmentDownloadService
{
    private AttachmentsModel $model;

    public function __construct(?AttachmentsModel $model = null)
    {
        $this->model = $model ?? new AttachmentsModel();
    }

    /**
     * {@inheritDoc}
     */
    public function download(string $attachmentId, string $disDetNro): array
    {
        $start      = microtime(true);
        $attachment = $this->model->getAttachmentByIdForDispensation($attachmentId, $disDetNro);

        if (!$attachment) {
            throw new RuntimeException(
                "Adjunto '{$attachmentId}' no encontrado para dispensación '{$disDetNro}'"
            );
        }

        $storage = $attachment['TipoAlmacenamiento'] ?? '';

        if ($storage === 'URL') {
            return $this->downloadFromDrive($attachment, $start);
        }

        if ($storage === 'BLOB') {
            return $this->downloadFromBlob($attachmentId, $disDetNro, $attachment, $start);
        }

        throw new RuntimeException(
            "Adjunto '{$attachmentId}' sin contenido (TipoAlmacenamiento='{$storage}')"
        );
    }

    /**
     * @param  array<string,mixed> $attachment
     * @return array{mime:string,data:string,duration_ms:int}
     */
    private function downloadFromDrive(array $attachment, float $start): array
    {
        $fileId = trim((string) ($attachment['AdjDisDocUrl'] ?? ''));
        if ($fileId === '') {
            throw new RuntimeException('Adjunto URL sin fileId de Google Drive');
        }

        $service = new GoogleDriveAuthService();
        $tmp     = $service->downloadFileToTemp($fileId, 'adj_');

        try {
            $mime = mime_content_type($tmp['path']) ?: 'application/octet-stream';
            $raw  = file_get_contents($tmp['path']);
            if ($raw === false) {
                throw new RuntimeException("No se pudo leer el archivo temporal: {$tmp['path']}");
            }
            $data = base64_encode($raw);
        } finally {
            if (file_exists($tmp['path'])) {
                unlink($tmp['path']);
            }
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        Logger::info('AttachmentDownloadService: downloaded from Drive', [
            'file_id'     => $fileId,
            'mime'        => $mime,
            'duration_ms' => $durationMs,
        ]);

        return ['mime' => $mime, 'data' => $data, 'duration_ms' => $durationMs];
    }

    /**
     * @param  array<string,mixed> $attachment  Metadata row (para detectar MIME por nombre)
     * @return array{mime:string,data:string,duration_ms:int}
     */
    private function downloadFromBlob(
        string $attachmentId,
        string $disDetNro,
        array  $attachment,
        float  $start
    ): array {
        $blob   = $this->model->getAttachmentBlobStreamByIdForDispensation($attachmentId, $disDetNro);
        $stream = $blob['stream'] ?? null;

        if (!is_resource($stream)) {
            throw new RuntimeException("BLOB no disponible para adjunto '{$attachmentId}'");
        }

        $raw = stream_get_contents($stream);
        if (is_callable($blob['close'])) {
            ($blob['close'])();
        }

        if ($raw === false || $raw === '') {
            throw new RuntimeException("BLOB vacío para adjunto '{$attachmentId}'");
        }

        $name = (string) ($attachment['AdjDisNom'] ?? '');
        $mime = $this->mimeFromName($name)
            ?? $this->detectMimeFromContent($raw)
            ?? 'application/octet-stream';

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        Logger::info('AttachmentDownloadService: downloaded from BLOB', [
            'attachment_id' => $attachmentId,
            'mime'          => $mime,
            'size_bytes'    => strlen($raw),
            'duration_ms'   => $durationMs,
        ]);

        return ['mime' => $mime, 'data' => base64_encode($raw), 'duration_ms' => $durationMs];
    }

    private function mimeFromName(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
            'zip'  => 'application/zip',
        ];
        return $map[$ext] ?? null;
    }

    private function detectMimeFromContent(string $data): ?string
    {
        if (strlen($data) < 4) {
            return null;
        }
        $h = substr($data, 0, 16);
        if (str_starts_with($h, '%PDF'))                                        return 'application/pdf';
        if (str_starts_with($h, "\xFF\xD8\xFF"))                               return 'image/jpeg';
        if (str_starts_with($h, "\x89PNG"))                                    return 'image/png';
        if (str_starts_with($h, 'GIF87a') || str_starts_with($h, 'GIF89a'))  return 'image/gif';
        if (str_starts_with($h, 'RIFF') && substr($data, 8, 4) === 'WEBP')   return 'image/webp';
        if (str_starts_with($h, "\x49\x49\x2A\x00") || str_starts_with($h, "\x4D\x4D\x00\x2A")) return 'image/tiff';
        if (str_starts_with($h, 'PK'))                                         return 'application/zip';
        return null;
    }
}
