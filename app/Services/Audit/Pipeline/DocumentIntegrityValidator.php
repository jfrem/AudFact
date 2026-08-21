<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Validacion preventiva de integridad documental.
 *
 * Se ejecuta en el borde del pipeline (post-descarga, pre-Gemini) para rechazar
 * documentos corruptos o vacios antes de consumir tokens de la API.
 */
final class DocumentIntegrityValidator
{
    /** Tamano minimo en bytes para que magic bytes sean viables */
    private const MIN_VALID_SIZE = 4;

    /** MIME types soportados por Gemini para extraccion documental */
    private const SUPPORTED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/tiff',
        'image/gif',
    ];

    /**
     * Magic bytes a MIME type esperado.
     *
     * Reutiliza las firmas probadas de AttachmentDownloadService::detectMimeFromContent.
     */
    private const MAGIC_SIGNATURES = [
        '%PDF'                         => 'application/pdf',
        "\xFF\xD8\xFF"                 => 'image/jpeg',
        "\x89PNG"                      => 'image/png',
        'GIF87a'                       => 'image/gif',
        'GIF89a'                       => 'image/gif',
        "\x49\x49\x2A\x00"            => 'image/tiff',
        "\x4D\x4D\x00\x2A"            => 'image/tiff',
    ];

    /**
     * Valida la integridad estructural de un documento descargado.
     *
     * @param  array{mime:string,data:string,duration_ms:int} $document
     * @return array{valid:bool,reason:?string,declared_mime:string,detected_mime:?string,size_bytes:int}
     */
    public static function validate(array $document): array
    {
        $base64Data = (string) ($document['data'] ?? '');
        $declaredMime = trim((string) ($document['mime'] ?? ''));

        if ($base64Data === '') {
            return self::rejected(DocumentRejectionReason::EMPTY_DOCUMENT, $declaredMime, null, 0);
        }

        $raw = base64_decode($base64Data, true);
        if ($raw === false || $raw === '') {
            return self::rejected(DocumentRejectionReason::EMPTY_DOCUMENT, $declaredMime, null, 0);
        }

        $sizeBytes = strlen($raw);
        if ($sizeBytes < self::MIN_VALID_SIZE) {
            return self::rejected(DocumentRejectionReason::DOCUMENT_TOO_SMALL, $declaredMime, null, $sizeBytes);
        }

        if (!in_array($declaredMime, self::SUPPORTED_MIMES, true)) {
            return self::rejected(DocumentRejectionReason::UNSUPPORTED_MIME, $declaredMime, null, $sizeBytes);
        }

        $detectedMime = self::detectMimeFromMagicBytes($raw);
        if ($detectedMime === null) {
            return self::rejected(DocumentRejectionReason::UNKNOWN_FILE_SIGNATURE, $declaredMime, null, $sizeBytes);
        }

        if ($detectedMime !== $declaredMime) {
            return self::rejected(DocumentRejectionReason::MIME_MISMATCH, $declaredMime, $detectedMime, $sizeBytes);
        }

        if ($declaredMime === 'application/pdf' && str_contains($raw, '/Encrypt')) {
            return self::rejected(DocumentRejectionReason::ENCRYPTED_DOCUMENT, $declaredMime, $detectedMime, $sizeBytes);
        }

        if ($declaredMime === 'application/pdf' && !self::pdfHasPages($raw)) {
            return self::rejected(DocumentRejectionReason::EMPTY_PDF_NO_PAGES, $declaredMime, $detectedMime, $sizeBytes);
        }

        if ($declaredMime === 'application/pdf' && !self::pdfHasEofMarker($raw)) {
            return self::rejected(DocumentRejectionReason::CORRUPTED_DOCUMENT, $declaredMime, $detectedMime, $sizeBytes);
        }

        return [
            'valid' => true,
            'reason' => null,
            'declared_mime' => $declaredMime,
            'detected_mime' => $detectedMime,
            'size_bytes' => $sizeBytes,
        ];
    }

    /**
     * @return array{valid:false,reason:string,declared_mime:string,detected_mime:?string,size_bytes:int}
     */
    private static function rejected(
        string $reason,
        string $declaredMime,
        ?string $detectedMime,
        int $sizeBytes
    ): array {
        return [
            'valid' => false,
            'reason' => $reason,
            'declared_mime' => $declaredMime,
            'detected_mime' => $detectedMime,
            'size_bytes' => $sizeBytes,
        ];
    }

    private static function detectMimeFromMagicBytes(string $raw): ?string
    {
        $header = substr($raw, 0, 16);

        foreach (self::MAGIC_SIGNATURES as $signature => $mime) {
            if (str_starts_with($header, $signature)) {
                return $mime;
            }
        }

        // WEBP requiere comprobacion RIFF + offset.
        if (str_starts_with($header, 'RIFF') && strlen($raw) >= 12 && substr($raw, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * Heurística ligera: verifica que un PDF contenga al menos un objeto /Page
     * (sin trailing 's') en su árbol de objetos. Un PDF con 0 páginas no contiene
     * este marcador y Gemini lo rechaza con HTTP 400 "The document has no pages".
     */
    private static function pdfHasPages(string $raw): bool
    {
        if (str_contains($raw, '/ObjStm')) {
            return true;
        }

        return (bool) preg_match('/\/Type\s*\/Page(?!s)\b/', $raw);
    }

    /**
     * Heurística preventiva: verifica que un PDF contenga el marcador de cierre '%%EOF'
     * en la cola del archivo (últimos 1024 bytes). Un PDF truncado sin %%EOF falla en el
     * parser de Gemini y se rechaza preventivamente como CORRUPTED_DOCUMENT.
     */
    private static function pdfHasEofMarker(string $raw): bool
    {
        $tail = substr($raw, -1024);
        return str_contains($tail, '%%EOF');
    }
}
