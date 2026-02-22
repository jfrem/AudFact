<?php

namespace App\Services\Audit;

use App\Models\AttachmentsModel;
use App\Services\GoogleDriveAuthService;
use Core\Logger;

class AuditFileManager
{
    // Constantes de Tipos MIME aceptados
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif'
    ];

    private string $tmpDir;
    private GoogleDriveAuthService $driveService;
    private AttachmentsModel $attachmentsModel;
    /** @var array<string, array{path: string, mime: ?string}> Caché de archivos descargados por FileID */
    private array $downloadCache = [];

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/audfact';
        if (!is_dir($this->tmpDir)) {
            // FIX #1: Permisos restrictivos para datos sensibles (contexto médico/auditoría)
            if (!mkdir($this->tmpDir, 0750, true) && !is_dir($this->tmpDir)) {
                $this->tmpDir = sys_get_temp_dir();
            }
        }
        $this->driveService = new GoogleDriveAuthService();
        $this->attachmentsModel = new AttachmentsModel();
    }

    /**
     * Prepara un archivo adjunto para su procesamiento.
     *
     * @param array $attachment Datos del adjunto
     * @return array Estructura ['mime', 'data', 'tmp_path']
     * @throws \RuntimeException Si falla la obtención del archivo
     */
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

    /**
     * Prepara todos los adjuntos requeridos.
     * FIX #2: Si un adjunto falla, limpia todos los anteriores ya acumulados.
     *
     * @param array $attachments Lista de adjuntos
     * @param array $dispensationData Datos de la dispensación
     * @return array Lista de archivos preparados
     * @throws \RuntimeException Si un adjunto requerido no puede prepararse o tiene MIME inválido
     */
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
            // FIX #2: Limpiar todos los archivos acumulados antes de la excepción
            foreach ($files as $accumulated) {
                $this->cleanup($accumulated);
            }
            throw $e;
        }

        return $files;
    }

    /**
     * Devuelve la lista de documentos faltantes requeridos.
     * Regla: Si NumeroAutorizacion está vacío en los registros, no se requiere autorización.
     *
     * @param array $attachments Lista de adjuntos
     * @param array $dispensationData Datos de la dispensación
     * @return array Lista de nombres de documentos faltantes
     */
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

    /**
     * Elimina el archivo temporal si existe.
     * Puede recibir un path string o el array de resultado de prepareAttachment.
     */
    public function cleanup($file): void
    {
        $path = is_array($file) ? ($file['tmp'] ?? null) : $file;
        $this->cleanupPath($path);
    }

    /**
     * Elimina un archivo temporal por path.
     */
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

        // Caché de descargas con invalidación si el archivo ya fue eliminado.
        // Nota: en PHP-FPM (un proceso por request) no hay race condition.
        // Si se migra a contexto async/fibers, considerar mutex.
        if (isset($this->downloadCache[$fileId])) {
            $cached = $this->downloadCache[$fileId];
            if (file_exists($cached['path'])) {
                copy($cached['path'], $destPath);
                Logger::info('Archivo obtenido desde caché local (descarga duplicada evitada)', [
                    'fileId' => $fileId,
                ]);
                return $cached['mime'];
            }
            // Path ya no existe, invalidar entrada del caché
            unset($this->downloadCache[$fileId]);
        }

        // FIX #6: Métricas de rendimiento para descargas URL
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

    /**
     * O3: Procesa BLOB directamente en memoria sin pasar por disco.
     * Flujo optimizado: SQL stream → memoria → detectMime → base64
     *
     * FIX #5: Usa nombre_documento como fallback para detección MIME.
     */
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

        // O3: leer stream directamente a memoria (sin archivo temporal)
        $binaryContent = stream_get_contents($stream);
        if (isset($blob['close']) && is_callable($blob['close'])) {
            $blob['close']();
        }

        $elapsed = round((hrtime(true) - $startTime) / 1e6);

        if ($binaryContent === false || $binaryContent === '') {
            throw new \RuntimeException('BLOB vacío o no leído correctamente');
        }

        Logger::info("BLOB procesado directo en memoria", [
            'attachmentId' => $attachmentId,
            'sizeBytes' => strlen($binaryContent),
            'elapsedMs' => $elapsed
        ]);

        // FIX #4: Detección MIME unificada (magic numbers + finfo + extensión fallback)
        $mime = $this->detectMimeFromBinary($binaryContent, $documentName);

        return [
            'mime' => $mime,
            'data' => base64_encode($binaryContent),
            'tmp' => null,
        ];
    }

    /**
     * Método centralizado para detección MIME por magic numbers (header bytes).
     * Reutilizado por detectMimeFromBinary() y detectMime().
     *
     * Nota: HEIC/HEIF no se detecta aquí porque su firma 'ftyp' está en bytes 4-7,
     * no al inicio del archivo. Para HEIC se depende de finfo o fallback por extensión.
     *
     * @param string $header Primeros 16 bytes del contenido
     * @return string|null MIME detectado o null si no se reconoce
     */
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

    /**
     * Detecta MIME type a partir del contenido binario en memoria.
     * FIX #4: Usa detectMimeFromHeader() centralizado.
     * FIX #5: Acepta nombre de documento para fallback por extensión.
     *
     * @param string $content Contenido binario completo
     * @param string $documentName Nombre del documento (para fallback por extensión)
     */
    private function detectMimeFromBinary(string $content, string $documentName = ''): string
    {
        // 1. Magic numbers
        $header = substr($content, 0, 16);
        $mime = $this->detectMimeFromHeader($header);
        if ($mime !== null) {
            return $mime;
        }

        // 2. finfo en memoria
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($content);
            if ($detected !== false && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        // 3. FIX #5: Fallback por extensión del nombre del documento
        if ($documentName !== '') {
            $fallback = $this->detectMimeFromExtension($documentName);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Fallback MIME por extensión de archivo.
     * FIX #5: Extraído para reutilizar en flujo BLOB y URL.
     */
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
                    fn($d) => $d['FacSec'] ?? $d['DisId'] ?? 'N/A',
                    $dispensationData
                )))
            ]);
            return false;
        }

        return true;
    }

    /**
     * Determina si la dispensación requiere documento de autorización.
     */
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
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Error leyendo contenido del archivo: $path");
        }

        $data = base64_encode($content);

        return [
            'mime' => $mime,
            'data' => $data,
            'tmp' => $tmpPath,
        ];
    }

    /**
     * Detecta MIME de un archivo en disco.
     * FIX #4: Usa detectMimeFromHeader() centralizado para magic numbers.
     */
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

        // FIX #4: Magic numbers centralizados
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

        // Fallback por extensión
        if ($mime === 'application/octet-stream' && $originalName !== '') {
            $fallback = $this->detectMimeFromExtension($originalName);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return $mime;
    }
}
