<?php

namespace App\Services\Audit;

use App\Models\AttachmentsModel;
use App\Services\GoogleDriveAuthService;
use Core\Logger;

class AuditFileManager
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif'
    ];

    private const MAX_FILE_SIZE_BYTES = 15 * 1024 * 1024;

    private string $tmpDir;
    private GoogleDriveAuthService $driveService;
    private AttachmentsModel $attachmentsModel;
    private array $downloadCache = [];

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/audfact';
        if (!is_dir($this->tmpDir)) {
            if (!mkdir($this->tmpDir, 0750, true) && !is_dir($this->tmpDir)) {
                $this->tmpDir = sys_get_temp_dir();
            }
        }
        $this->driveService = new GoogleDriveAuthService();
        $this->attachmentsModel = new AttachmentsModel();
    }

    public function prepareAttachment(array $attachment): array
    {
        $storageType = $attachment['TipoAlmacenamiento'] ?? 'SIN_DOCUMENTOS';
        $documentName = (string)($attachment['nombre_documento'] ?? '');

        if ($storageType === 'URL') {
            $tmpPath = tempnam($this->tmpDir, 'audit_');
            if ($tmpPath === false) {
                throw new \RuntimeException('No se pudo crear archivo temporal');
            }
            try {
                $mimeOverride = $this->handleUrlStorage($attachment, $tmpPath);
                return $this->fileToBase64($tmpPath, $tmpPath, $documentName, $mimeOverride);
            } catch (\Throwable $e) {
                $this->cleanupPath($tmpPath);
                throw $e;
            }
        } elseif ($storageType === 'BLOB') {
            // O3: flujo directo sin disco — no hay tmpPath que limpiar si falla
            return $this->handleBlobDirect($attachment);
        } else {
            throw new \RuntimeException("Tipo de almacenamiento no soportado o vacío: $storageType");
        }
    }

    public function prepareAttachments(array $attachments, array $dispensationData): array
    {
        $files = [];

        try {
            foreach ($attachments as $attachment) {
                if (!$this->isAttachmentRequired($attachment, $dispensationData)) {
                    continue;
                }

                $file = $this->prepareAttachment($attachment);
                $mimeType = $file['mime'] ?? '';
                if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                    Logger::error('Tipo MIME no soportado', [
                        'mime' => $mimeType,
                        'allowed' => self::ALLOWED_MIME_TYPES
                    ]);
                    $this->cleanup($file);
                    throw new \RuntimeException("Tipo de archivo no soportado: {$mimeType}");
                }

                $label = (string)($attachment['nombre_documento'] ?? $attachment['id_documento'] ?? 'Documento');
                $file['label'] = $label;
                $files[] = $file;
            }
        } catch (\Throwable $e) {
            foreach ($files as $accumulated) {
                $this->cleanup($accumulated);
            }
            throw $e;
        }

        return $files;
    }

    public function getMissingRequiredAttachments(array $attachments, array $dispensationData): array
    {
        $missingDocuments = [];

        foreach ($attachments as $attachment) {
            $storageType = (string)($attachment['TipoAlmacenamiento'] ?? 'SIN_DOCUMENTOS');
            $documentName = (string)($attachment['nombre_documento'] ?? '');

            if ($storageType === 'SIN_DOCUMENTOS' && $this->isAttachmentRequired($attachment, $dispensationData)) {
                $missingDocuments[] = $documentName;
                Logger::warning('Documento requerido sin archivo adjunto', [
                    'documento' => $documentName,
                    'documentId' => $attachment['id_documento'] ?? 'N/A',
                    'dispensaId' => $attachment['dispiensa'] ?? 'N/A'
                ]);
            }
        }

        return $missingDocuments;
    }

    public function cleanup($file): void
    {
        $path = is_array($file) ? ($file['tmp'] ?? null) : $file;
        $this->cleanupPath($path);
    }

    private function cleanupPath(?string $path): void
    {
        if ($path !== null && $path !== '' && is_file($path)) {
            if (!@unlink($path)) {
                Logger::warning('No se pudo eliminar archivo temporal', ['path' => $path]);
            }
        }
    }

    private function handleUrlStorage(array $attachment, string $destPath): ?string
    {
        $fileId = (string)($attachment['almacenamiento_remoto'] ?? '');
        if ($fileId === '') {
            throw new \RuntimeException('Adjunto URL inválido (ID vacío)');
        }
        if (isset($this->downloadCache[$fileId])) {
            $cached = $this->downloadCache[$fileId];
            if (file_exists($cached['path'])) {
                copy($cached['path'], $destPath);
                Logger::info('Archivo obtenido desde caché local (descarga duplicada evitada)', [
                    'fileId' => $fileId,
                ]);
                return $cached['mime'];
            }
            unset($this->downloadCache[$fileId]);
        }

        $startTime = hrtime(true);
        $mime = $this->driveService->downloadFile($fileId, $destPath);
        $elapsed = round((hrtime(true) - $startTime) / 1e6);

        $this->downloadCache[$fileId] = ['path' => $destPath, 'mime' => $mime];

        Logger::info("URL descargada desde Google Drive", [
            'fileId' => $fileId,
            'sizeBytes' => filesize($destPath) ?: 0,
            'elapsedMs' => $elapsed
        ]);

        return $mime;
    }

    private function handleBlobDirect(array $attachment): array
    {
        $attachmentId = (string)($attachment['id_documento'] ?? '');
        $invoiceId = (string)($attachment['factura'] ?? $attachment['invoice_id_ref'] ?? $attachment['dispiensa'] ?? '');
        $documentName = (string)($attachment['nombre_documento'] ?? '');

        if ($attachmentId === '' || $invoiceId === '') {
            throw new \RuntimeException('Adjunto BLOB inválido (ID vacío)');
        }

        $startTime = hrtime(true);

        $blob = $this->attachmentsModel->getAttachmentBlobStreamByIdForDispensation($attachmentId, $invoiceId);
        $stream = $blob['stream'] ?? null;

        if (!is_resource($stream)) {
            throw new \RuntimeException('No se pudo obtener stream BLOB de base de datos');
        }

        $binaryContent = stream_get_contents($stream);
        if (isset($blob['close']) && is_callable($blob['close'])) {
            $blob['close']();
        }

        $elapsed = round((hrtime(true) - $startTime) / 1e6);

        if ($binaryContent === false || $binaryContent === '') {
            throw new \RuntimeException('BLOB vacío o no leído correctamente');
        }

        if (strlen($binaryContent) > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException(sprintf('Archivo %s excede el límite máximo (15MB)', $documentName));
        }

        Logger::info("BLOB procesado directo en memoria", [
            'attachmentId' => $attachmentId,
            'sizeBytes' => strlen($binaryContent),
            'elapsedMs' => $elapsed
        ]);

        $mime = $this->detectMimeFromBinary($binaryContent, $documentName);

        return [
            'mime' => $mime,
            'data' => base64_encode($binaryContent),
            'tmp' => null,
            'pages' => $this->countPagesByMime($binaryContent, $mime),
        ];
    }

    // HEIC/HEIF no se detecta aquí: su firma 'ftyp' está en bytes 4-7, no al inicio.
    private function detectMimeFromHeader(string $header): ?string
    {
        if (strpos($header, '%PDF-') === 0) {
            return 'application/pdf';
        }
        if (strpos($header, "\xFF\xD8\xFF") === 0) {
            return 'image/jpeg';
        }
        if (strpos($header, "\x89PNG\r\n\x1a\n") === 0) {
            return 'image/png';
        }
        if (strpos($header, "RIFF") === 0 && strpos(substr($header, 8), "WEBP") === 0) {
            return 'image/webp';
        }

        return null;
    }

    private function detectMimeFromBinary(string $content, string $documentName = ''): string
    {
        $header = substr($content, 0, 16);
        $mime = $this->detectMimeFromHeader($header);
        if ($mime !== null) {
            return $mime;
        }

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($content);
            if ($detected !== false && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        if ($documentName !== '') {
            $fallback = $this->detectMimeFromExtension($documentName);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return 'application/octet-stream';
    }

    private function detectMimeFromExtension(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];

        return $map[$ext] ?? null;
    }

    private function isAttachmentRequired(array $attachment, array $dispensationData): bool
    {
        $documentName = (string)($attachment['nombre_documento'] ?? '');
        if ($documentName === 'AUTORIZACION DE SERVICIOS' && !$this->dispensationRequiresAuthorization($dispensationData)) {
            Logger::info('Documento de autorización no requerido para la dispensación', [
                'documento' => $documentName,
                'dispensaIds' => array_values(array_unique(array_map(
                    fn($d) => $d['FacSec'] ?? 'N/A',
                    $dispensationData
                )))
            ]);
            return false;
        }

        return true;
    }

    private function dispensationRequiresAuthorization(array $dispensationData): bool
    {
        foreach ($dispensationData as $row) {
            $num = (string)($row['NumeroAutorizacion'] ?? '');
            if (trim($num) !== '') {
                return true;
            }
        }

        return false;
    }

    private function fileToBase64(string $path, ?string $tmpPath = null, string $originalName = '', ?string $mimeOverride = null): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("El archivo a procesar no existe: $path");
        }

        $mime = $mimeOverride ?: $this->detectMime($path, $originalName);

        $size = filesize($path);
        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException(sprintf('Archivo %s excede el límite máximo (15MB)', $originalName));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Error leyendo contenido del archivo: $path");
        }

        $data = base64_encode($content);

        return [
            'mime' => $mime,
            'data' => $data,
            'tmp' => $tmpPath,
            'pages' => $this->countPagesByMime($content, $mime),
        ];
    }

    private function countPagesByMime(string $binaryContent, string $mime): int
    {
        if ($mime !== 'application/pdf') {
            return 1;
        }

        $matches = preg_match_all('/\/Type\s*\/Page\b/', $binaryContent);
        if (!is_int($matches) || $matches < 1) {
            return 1;
        }

        return $matches;
    }

    private function detectMime(string $path, string $originalName = ''): string
    {
        $mime = 'application/octet-stream';

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path) ?: 'application/octet-stream';
        } elseif (function_exists('finfo_open')) {
            $finfo = \finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = \finfo_file($finfo, $path) ?: 'application/octet-stream';
                \finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = \mime_content_type($path) ?: 'application/octet-stream';
        }

        if (($mime === 'application/octet-stream' || $mime === 'text/plain') && is_readable($path)) {
            $handle = @fopen($path, 'rb');
            if ($handle) {
                $header = fread($handle, 16);
                fclose($handle);
                if ($header !== false) {
                    $detected = $this->detectMimeFromHeader($header);
                    if ($detected !== null) {
                        return $detected;
                    }
                }
            }
        }

        if ($mime === 'application/octet-stream' && $originalName !== '') {
            $fallback = $this->detectMimeFromExtension($originalName);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return $mime;
    }
}
