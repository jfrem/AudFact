<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Services\GoogleDriveAuthService;
use Core\Logger;
use Core\RedisClient;
use Throwable;

/**
 * Servicio de descarga de adjuntos de dispensación para el pipeline de auditoría.
 *
 * Resuelve adjuntos BLOB/URL desde el modelo y entrega el contenido binario
 * en base64 al worker de descarga del pipeline.
 *
 * Contrato de retorno: array{mime:string, data:string (base64), duration_ms:int}
 */
class AttachmentDownloadService
{
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_NOT_FOUND     = 404;

    private AttachmentsModel $model;
    private ?GoogleDriveAuthService $driveAuth;
    private ?RedisClient $redis;

    public function __construct(
        ?AttachmentsModel $model = null,
        ?GoogleDriveAuthService $driveAuth = null,
        ?RedisClient $redis = null
    ) {
        $this->model = $model ?? new AttachmentsModel();
        $this->driveAuth = $driveAuth;
        $this->redis = $redis;
    }

    /**
     * @param string $attachmentId Identificador de adjunto
     * @param string $disDetNro Número de dispensación/factura
     * @param array<string,mixed>|null $metadata Metadatos físicos precalculados (opcional para evitar query redundante)
     * @return array{mime:string,data:string,duration_ms:int}
     * @throws AttachmentDownloadException
     */
    public function download(string $attachmentId, string $disDetNro, ?array $metadata = null): array
    {
        $start = microtime(true);
        $attachment = $this->resolveAttachmentRow($attachmentId, $disDetNro, $metadata);

        if (!$attachment) {
            throw new AttachmentDownloadException(
                AttachmentDownloadException::SOURCE_NOT_FOUND,
                "Adjunto '{$attachmentId}' no encontrado para dispensación '{$disDetNro}'"
            );
        }

        $rawStorage = $attachment['TipoAlmacenamiento'] ?? '';
        $storageType = AttachmentStorageType::fromTransport($rawStorage);

        if ($storageType === AttachmentStorageType::Url) {
            return $this->downloadFromDrive($attachment, $start);
        }

        if ($storageType === AttachmentStorageType::Blob) {
            return $this->downloadFromBlob($attachmentId, $disDetNro, $attachment, $start);
        }

        $rawNormalized = strtoupper(trim((string) $rawStorage));
        throw new AttachmentDownloadException(
            AttachmentDownloadException::SOURCE_EMPTY,
            "Adjunto '{$attachmentId}' sin contenido (TipoAlmacenamiento='{$rawNormalized}')"
        );
    }

    /**
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>|null
     */
    private function resolveAttachmentRow(string $attachmentId, string $disDetNro, ?array $metadata): ?array
    {
        if (is_array($metadata) && !empty($metadata['TipoAlmacenamiento'])) {
            return $metadata;
        }

        $attachments = $this->model->getPhysicalAttachmentsByDisDetNro($disDetNro, attachmentId: $attachmentId);
        return $attachments[0] ?? null;
    }

    private function driveAuth(): GoogleDriveAuthService
    {
        return $this->driveAuth ??= new GoogleDriveAuthService(redis: $this->redis);
    }

    /**
     * @param  array<string,mixed> $attachment
     * @return array{mime:string,data:string,duration_ms:int}
     */
    private function downloadFromDrive(array $attachment, float $start): array
    {
        $fileId = trim((string) ($attachment['AdjDisDocUrl'] ?? ''));
        if ($fileId === '') {
            throw new AttachmentDownloadException(
                AttachmentDownloadException::SOURCE_NOT_FOUND,
                'Adjunto URL sin identificador de Google Drive'
            );
        }

        try {
            $tmp = $this->driveAuth()->downloadFileToTemp($fileId, 'adj_');

            $mime = mime_content_type($tmp['path']) ?: 'application/octet-stream';
            $raw  = file_get_contents($tmp['path']);
            if ($raw === false) {
                throw new AttachmentDownloadException(
                    AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED,
                    'No se pudo leer el archivo temporal de Google Drive'
                );
            }
            if ($raw === '') {
                throw new AttachmentDownloadException(
                    AttachmentDownloadException::SOURCE_EMPTY,
                    'Google Drive entregó un adjunto vacío'
                );
            }
            $data = base64_encode($raw);
        } catch (AttachmentDownloadException $error) {
            throw $error;
        } catch (Throwable $error) {
            // Si el error es 401 Unauthorized de Drive, invalidamos el token en cache
            if ($this->isUnauthorizedError($error)) {
                $this->driveAuth?->clearCachedToken();
            }

            // Si es 404 Not Found de Drive, es un error permanente de la fuente
            if ($this->isNotFoundError($error)) {
                throw new AttachmentDownloadException(
                    AttachmentDownloadException::SOURCE_NOT_FOUND,
                    'El archivo no existe o no se tiene acceso en Google Drive: ' . $error->getMessage(),
                    $error
                );
            }

            throw new AttachmentDownloadException(
                AttachmentDownloadException::EXTERNAL_TRANSFER_FAILED,
                'Falló la transferencia del adjunto desde Google Drive',
                $error
            );
        } finally {
            try {
                if (
                    isset($tmp['path'])
                    && is_string($tmp['path'])
                    && file_exists($tmp['path'])
                ) {
                    unlink($tmp['path']);
                }
            } catch (Throwable $cleanupError) {
                Logger::warning('No se pudo limpiar el temporal de Google Drive', [
                    'error_class' => $cleanupError::class,
                ]);
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
        $blob = $this->model->getAttachmentBlobBytesByIdForDisDetNro(
            $attachmentId,
            $disDetNro
        );
        if ($blob === null) {
            throw new AttachmentDownloadException(
                AttachmentDownloadException::SOURCE_NOT_FOUND,
                "BLOB no encontrado para adjunto '{$attachmentId}'"
            );
        }

        $raw = $blob['bytes'];
        $expectedSize = $blob['expected_size'];
        if ($expectedSize <= 0 || $raw === '') {
            throw new AttachmentDownloadException(
                AttachmentDownloadException::SOURCE_EMPTY,
                "BLOB vacío para adjunto '{$attachmentId}'"
            );
        }

        if (strlen($raw) !== $expectedSize) {
            throw new AttachmentDownloadException(
                AttachmentDownloadException::INCOMPLETE_TRANSFER,
                "Transferencia BLOB incompleta para adjunto '{$attachmentId}'"
            );
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

    private function extractHttpStatusCode(Throwable $error): ?int
    {
        if ($error instanceof \GuzzleHttp\Exception\RequestException && $error->getResponse() !== null) {
            return $error->getResponse()->getStatusCode();
        }

        return null;
    }

    private function isUnauthorizedError(Throwable $error): bool
    {
        return $this->extractHttpStatusCode($error) === self::HTTP_UNAUTHORIZED;
    }

    private function isNotFoundError(Throwable $error): bool
    {
        return $this->extractHttpStatusCode($error) === self::HTTP_NOT_FOUND;
    }
}
