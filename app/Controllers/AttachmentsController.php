<?php

namespace App\Controllers;

use App\Models\AttachmentsModel;
use App\Services\Audit\Pipeline\AttachmentDownloadService;
use Core\Response;

class AttachmentsController extends Controller
{
    public function __construct()
    {
        $this->model = new AttachmentsModel();
    }

    public function showByDispensation(string $disDetNro, ?string $nitSec = null): void
    {
        $disDetNro = trim($disDetNro);
        if ($disDetNro === '') {
            Response::error('El número de dispensación es requerido', 422);
        }

        $nitSec = $nitSec ?? ($_GET['nitSec'] ?? null);
        $nitSec = ($nitSec !== null && trim((string) $nitSec) !== '') ? trim((string) $nitSec) : null;

        $attachments = $this->model->getPhysicalAttachmentsByDisDetNro($disDetNro, $nitSec);
        Response::success($attachments);
    }

    public function downloadByDispensation(string $disDetNro, string $attachmentId): void
    {
        $this->validateArray(['disDetNro' => $disDetNro, 'attachmentId' => $attachmentId], [
            'disDetNro' => 'required|string|max:255',
            'attachmentId' => 'required|string|max:255'
        ]);
        $disDetNro = trim($disDetNro);
        $attachmentId = trim($attachmentId);
        $this->handleDownloadForDispensation($attachmentId, $disDetNro);
    }


    private function handleDownloadForDispensation(string $attachmentId, string $disDetNro): void
    {
        $accept    = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = stripos($accept, 'application/json') !== false;

        if ($wantsJson) {
            // Pipeline de auditoría: delega al servicio de dominio y retorna JSON
            $downloader = new AttachmentDownloadService();
            $result     = $downloader->download($attachmentId, $disDetNro);
            Response::json(['mime' => $result['mime'], 'data' => $result['data']]);
            return;
        }

        $attachments = $this->model->getPhysicalAttachmentsByDisDetNro($disDetNro, attachmentId: $attachmentId);
        $attachment  = $attachments[0] ?? null;
        if (!$attachment) {
            Response::error('Adjunto no encontrado', 404);
        }

        $rawName     = $attachment['AdjDisNom'] ?? 'adjunto';
        $nombre      = $this->sanitizeFilename($rawName);
        $mimeForName = null;

        if ($attachment['TipoAlmacenamiento'] === 'URL') {
            $fileId = (string) ($attachment['AdjDisDocUrl'] ?? '');
            if ($fileId === '') {
                Response::error('Adjunto invalido', 400);
            }
            $service = new \App\Services\GoogleDriveAuthService();
            $tmp     = $service->downloadFileToTemp($fileId, 'adj_');

            $mimeForName = mime_content_type($tmp['path']) ?: 'application/octet-stream';
            $nombre      = $this->ensureExtension($nombre, $mimeForName);
            header('Content-Type: ' . $mimeForName);
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            if (!empty($tmp['size'])) {
                header('Content-Length: ' . (int) $tmp['size']);
            }
            $this->streamFileAndCleanup($tmp['path']);
            return;
        }

        if ($attachment['TipoAlmacenamiento'] === 'BLOB') {
            $blob   = $this->model->getAttachmentBlobStreamByIdForDisDetNro($attachmentId, $disDetNro);
            $stream = $blob['stream'] ?? null;
            if (!is_resource($stream)) {
                Response::error('Adjunto no disponible', 404);
            }
            $mimeForName = $this->mimeFromName($nombre) ?: 'application/octet-stream';
            $nombre      = $this->ensureExtension($nombre, $mimeForName);
            header('Content-Type: ' . $mimeForName);
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            if (!empty($attachment['BlobSize'])) {
                header('Content-Length: ' . (int) $attachment['BlobSize']);
            }
            fpassthru($stream);
            if (is_callable($blob['close'])) {
                ($blob['close'])();
            }
            return;
        }

        Response::error('Adjunto sin contenido', 404);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'adjunto';
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        return $name ?: 'adjunto';
    }

    private function ensureExtension(string $name, ?string $mime): string
    {
        $hasExt = pathinfo($name, PATHINFO_EXTENSION) !== '';
        if ($hasExt) {
            return $name;
        }

        if (!$mime) {
            return $name;
        }

        $map = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/tiff' => 'tiff',
            'application/zip' => 'zip',
        ];

        $ext = $map[strtolower($mime)] ?? null;
        return $ext ? ($name . '.' . $ext) : $name;
    }

    private function mimeFromName(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }

        $map = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'zip' => 'application/zip',
        ];

        return $map[$ext] ?? null;
    }

    private function streamFileAndCleanup(string $path): void
    {
        if (!is_file($path)) {
            Response::error('Archivo temporal no encontrado', 404);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            Response::error('No se pudo abrir el archivo', 500);
        }

        fpassthru($handle);
        fclose($handle);
        unlink($path);
    }
}
