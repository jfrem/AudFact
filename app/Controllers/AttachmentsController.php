<?php

namespace App\Controllers;

use App\Models\AttachmentsModel;
use Core\Response;

class AttachmentsController extends Controller
{
    public function __construct()
    {
        $this->model = new AttachmentsModel();
    }

    public function showByDispensation(string $invoiceId, string $nitSec): void
    {
        $this->validateArray(['invoiceId' => $invoiceId, 'nitSec' => $nitSec], [
            'invoiceId' => 'required|string|max:255',
            'nitSec' => 'required|string|max:255'
        ]);
        $invoiceId = trim($invoiceId);
        $nitSec = trim($nitSec);
        $attachments = $this->model->getAttachmentsByInvoiceId($invoiceId, $nitSec);
        Response::success($attachments);
    }

    public function downloadByDispensation(string $invoiceId, string $attachmentId): void
    {
        $this->validateArray(['invoiceId' => $invoiceId, 'attachmentId' => $attachmentId], [
            'invoiceId' => 'required|string|max:255',
            'attachmentId' => 'required|string|max:255'
        ]);
        $invoiceId = trim($invoiceId);
        $attachmentId = trim($attachmentId);
        $this->handleDownloadForDispensation($attachmentId, $invoiceId);
    }


    private function handleDownloadForDispensation(string $attachmentId, string $invoiceId): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = stripos($accept, 'application/json') !== false;

        $attachment = $this->model->getAttachmentByIdForDispensation($attachmentId, $invoiceId);
        if (!$attachment) {
            Response::error('Adjunto no encontrado', 404);
        }

        $rawName = $attachment['AdjDisNom'] ?? ($attachment['AdjDisDocNom'] ?? 'adjunto');
        $nombre = $this->sanitizeFilename($rawName);
        $mimeForName = null;

        if ($attachment['TipoAlmacenamiento'] === 'URL') {
            $fileId = (string)($attachment['AdjDisDocUrl'] ?? '');
            if ($fileId === '') {
                Response::error('Adjunto invalido', 400);
            }

            $service = new \App\Services\GoogleDriveAuthService();
            $tmp = $service->downloadFileToTemp($fileId, 'adj_');

            if ($wantsJson) {
                $mime = mime_content_type($tmp['path']) ?: 'application/octet-stream';
                $data = base64_encode(file_get_contents($tmp['path']));
                unlink($tmp['path']);
                Response::json(['mime' => $mime, 'data' => $data]);
            }

            $mimeForName = mime_content_type($tmp['path']) ?: 'application/octet-stream';
            $nombre = $this->ensureExtension($nombre, $mimeForName);
            $disposition = 'attachment; filename="' . $nombre . '"';
            header('Content-Type: ' . $mimeForName);
            header('Content-Disposition: ' . $disposition);

            if (!empty($tmp['size'])) {
                header('Content-Length: ' . (int)$tmp['size']);
            }

            $this->streamFileAndCleanup($tmp['path']);
            return;
        }

        if ($attachment['TipoAlmacenamiento'] === 'BLOB') {
            $blob = $this->model->getAttachmentBlobStreamByIdForDispensation($attachmentId, $invoiceId);
            $stream = $blob['stream'] ?? null;
            if (!is_resource($stream)) {
                Response::error('Adjunto no disponible', 404);
            }

            if ($wantsJson) {
                $data = stream_get_contents($stream);
                if (is_callable($blob['close'])) {
                    $blob['close']();
                }
                if ($data === false) {
                    Response::error('No se pudo leer el adjunto', 500);
                }
                $name = (string)($attachment['AdjDisNom'] ?? ($attachment['AdjDisDocNom'] ?? ''));
                $mime = $this->mimeFromName($name) ?: 'application/octet-stream';
                Response::json(['mime' => $mime ?: 'application/octet-stream', 'data' => base64_encode($data)]);
            }

            $mimeForName = $this->mimeFromName($nombre) ?: 'application/octet-stream';
            $nombre = $this->ensureExtension($nombre, $mimeForName);
            $disposition = 'attachment; filename="' . $nombre . '"';
            header('Content-Type: ' . $mimeForName);
            header('Content-Disposition: ' . $disposition);

            if (!empty($attachment['BlobSize'])) {
                header('Content-Length: ' . (int)$attachment['BlobSize']);
            }

            fpassthru($stream);
            if (is_callable($blob['close'])) {
                $blob['close']();
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
        return;
    }
}
