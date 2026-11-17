<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class DocumentRejectionReason
{
    public const REJECTION_CLASS = 'document_content';
    public const EMPTY_DOCUMENT = 'EMPTY_DOCUMENT';
    public const DOCUMENT_TOO_SMALL = 'DOCUMENT_TOO_SMALL';
    public const UNSUPPORTED_MIME = 'UNSUPPORTED_MIME';
    public const UNKNOWN_FILE_SIGNATURE = 'UNKNOWN_FILE_SIGNATURE';
    public const MIME_MISMATCH = 'MIME_MISMATCH';
    public const ENCRYPTED_DOCUMENT = 'ENCRYPTED_DOCUMENT';
    public const EMPTY_PDF_NO_PAGES = 'EMPTY_PDF_NO_PAGES';
    public const GEMINI_DECODE_FAILURE = 'GEMINI_DECODE_FAILURE';
    public const CORRUPTED_DOCUMENT = 'CORRUPTED_DOCUMENT';
    public const RASTERIZATION_FAILURE = 'RASTERIZATION_FAILURE';
    public const GEMINI_PROVIDER_BLOCKED = 'GEMINI_PROVIDER_BLOCKED';
    public const GEMINI_OUTPUT_TRUNCATED = 'GEMINI_OUTPUT_TRUNCATED';
    public const GEMINI_UNSAFE_FINISH    = 'GEMINI_UNSAFE_FINISH';
    public const DOCUMENT_EXCEEDS_PAGE_LIMIT = 'DOCUMENT_EXCEEDS_PAGE_LIMIT';

    /**
     * Fuente única de verdad: razones de rechazo permitidas y plantilla legible para el auditor.
     *
     * @var array<string, string>
     */
    private const AUDITOR_MESSAGES = [
        self::EMPTY_DOCUMENT => "El archivo adjunto de '{doc}' está vacío (sin contenido legible). Solicite cargar nuevamente el soporte digitalizado y realice la validación manual.",
        self::DOCUMENT_TOO_SMALL => "El archivo adjunto de '{doc}' está incompleto o es demasiado pequeño para contener un soporte válido. Solicite una nueva digitalización del documento.",
        self::UNSUPPORTED_MIME => "El tipo de archivo adjunto de '{doc}' no es compatible con la lectura automática (solo se admiten PDF e imágenes estándar). Abra el soporte en el visor o realice la validación manual.",
        self::UNKNOWN_FILE_SIGNATURE => "El archivo adjunto de '{doc}' no corresponde a un PDF ni a una imagen válida, o está dañado. Abra el soporte en el visor para confirmar su legibilidad o solicite cargarlo nuevamente.",
        self::MIME_MISMATCH => "La extensión o formato declarado del archivo adjunto de '{doc}' no coincide con su contenido real. Verifique el documento en el visor y valide manualmente el soporte.",
        self::ENCRYPTED_DOCUMENT => "El PDF adjunto de '{doc}' está protegido con contraseña o cifrado, lo que impide su lectura automática. Cargue una versión sin contraseña o valide el soporte manualmente.",
        self::EMPTY_PDF_NO_PAGES => "El PDF adjunto de '{doc}' no contiene páginas visibles o utilizables. Cargue nuevamente el documento completo y verifique manualmente la entrega.",
        self::GEMINI_DECODE_FAILURE => "El archivo adjunto de '{doc}' no pudo leerse automáticamente porque su formato o contenido interno es inválido o ilegible. Verifique el documento en el visor o solicite un nuevo soporte.",
        self::CORRUPTED_DOCUMENT => "El archivo adjunto de '{doc}' está corrupto o incompleto y no pudo abrirse para lectura automática. Solicite un reemplazo legible del soporte.",
        self::RASTERIZATION_FAILURE => "No fue posible procesar visualmente el PDF adjunto de '{doc}' debido a un daño interno en el archivo. Revise el documento manualmente en el visor.",
        self::GEMINI_PROVIDER_BLOCKED => "La lectura automática del adjunto de '{doc}' fue restringida por filtros de seguridad de contenido. Revise directamente el documento en el visor para completar la auditoría.",
        self::GEMINI_OUTPUT_TRUNCATED => "El archivo adjunto de '{doc}' contiene demasiada información para procesarse automáticamente de forma completa. Realice la verificación manual del soporte en el visor.",
        self::GEMINI_UNSAFE_FINISH => "La lectura automática del adjunto de '{doc}' se detuvo de forma incompleta por restricciones de seguridad del contenido. Verifique manualmente el soporte en el visor.",
        self::DOCUMENT_EXCEEDS_PAGE_LIMIT => "El PDF adjunto de '{doc}' supera el {limit} para lectura automática. Revise si se cargaron anexos no requeridos (como historia clínica o epicrisis) y realice la validación manual.",
    ];

    private function __construct()
    {
    }

    public static function isAllowed(string $reason): bool
    {
        return isset(self::AUDITOR_MESSAGES[$reason]);
    }

    /**
     * Devuelve un mensaje claro y accionable en español para que el auditor humano
     * comprenda la causal del rechazo de integridad y pueda levantar el hallazgo.
     */
    public static function describeForAuditor(
        string $reason,
        string $documentName,
        ?int $maxPages = null
    ): string {
        $doc = trim($documentName);
        if ($doc === '') {
            $doc = 'DOCUMENTO';
        }

        $template = self::AUDITOR_MESSAGES[$reason]
            ?? "El archivo adjunto de '{doc}' no pudo procesarse automáticamente por un problema de integridad ({reason}). Realice la revisión manual del documento en el visor.";

        $limitPhrase = ($maxPages !== null && $maxPages > 0)
            ? "límite máximo permitido de {$maxPages} páginas"
            : 'límite máximo de páginas permitido';

        return strtr($template, [
            '{doc}'    => $doc,
            '{limit}'  => $limitPhrase,
            '{reason}' => $reason,
        ]);
    }
}
