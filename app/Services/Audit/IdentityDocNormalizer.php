<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Normalización de documentos de identidad colombianos (RIPS/BDUA).
 *
 * Extraída de AuditFindingRules para cohesión (SRP).
 * Encapsula la tabla de aliases y la lógica de extracción de tipo/número.
 */
final class IdentityDocNormalizer
{
    /**
     * Tabla de aliases para tipos de documento de identidad colombianos.
     *
     * Cubre los 11 tipos oficiales del sistema RIPS/BDUA.
     * Keys: versión normalizada (sin acentos, sin espacios, uppercase).
     * Values: código canónico de 2-4 letras.
     */
    private const ALIASES = [
        // Cédula de Ciudadanía
        'CC' => 'CC',
        'CEDULA' => 'CC',
        'CEDULACIUDADANIA' => 'CC',
        'CEDULADECIUDADANIA' => 'CC',
        // Tarjeta de Identidad
        'TI' => 'TI',
        'TARJETAIDENTIDAD' => 'TI',
        'TARJETADEIDENTIDAD' => 'TI',
        // Cédula de Extranjería
        'CE' => 'CE',
        'CEDULAEXTRANJERIA' => 'CE',
        'CEDULADEEXTRANJERIA' => 'CE',
        // Registro Civil
        'RC' => 'RC',
        'REGISTROCIVIL' => 'RC',
        'REGISTROCIVILNACIMIENTO' => 'RC',
        'REGISTROCIVILDENACIMIENTO' => 'RC',
        // Pasaporte
        'PA' => 'PA',
        'PASAPORTE' => 'PA',
        'PT' => 'PA',
        // Permiso Especial de Permanencia
        'PE' => 'PE',
        'PEP' => 'PE',
        'PERMISOESPECIALPERMANENCIA' => 'PE',
        'PERMISOESPECIALDEPERMANENCIA' => 'PE',
        // Permiso por Protección Temporal
        'PPT' => 'PPT',
        'PERMISOPROTECCIONTEMPORAL' => 'PPT',
        'PERMISODEPROTECCIONTEMPORAL' => 'PPT',
        'PERMISOPORPROTECCIONTEMPORAL' => 'PPT',
        // Menor sin identificación
        'MS' => 'MS',
        'MENORSINIDENTIFICACION' => 'MS',
        // Adulto sin identificación
        'AS' => 'AS',
        'ADULTOSINIDENTIFICACION' => 'AS',
        // Número Único de Identificación Personal
        'NUIP' => 'NUIP',
        'NUMEROUNICODEIDENTIFICACION' => 'NUIP',
        'NUMEROUNICODEIDENTIFICACIONPERSONAL' => 'NUIP',
        // Salvoconducto
        'SC' => 'SC',
        'SALVOCONDUCTO' => 'SC',
    ];

    /**
     * Normaliza un tipo de documento de identidad al código canónico RIPS/BDUA.
     *
     * Resuelve tanto abreviaciones ("CC", "TI") como texto completo
     * ("Cédula de Ciudadanía", "Tarjeta de Identidad").
     */
    public static function normalizeDocType(string $value): string
    {
        $token = TextNormalization::normalizeToken($value);

        return self::ALIASES[$token] ?? $token;
    }

    /**
     * Normaliza número/token de identificación sin conservar nombres concatenados.
     *
     * Es deliberadamente conservador: solo extrae tokens al inicio del valor
     * después de etiquetas/tipos documentales esperados. Si no hay patrón claro,
     * conserva el valor original para que la comparación exacta falle de forma
     * visible en vez de aprobar por inferencia.
     */
    public static function normalizeDocNumber(string $value): string
    {
        $candidate = strtoupper(TextNormalization::stripAccents(trim($value)));
        if ($candidate === '') {
            return '';
        }

        $labelPattern = self::labelPattern();
        $typePattern = self::docTypePattern(includeNit: false);
        $candidate = (string) preg_replace(
            "/^\\s*(?:(?:{$labelPattern})\\s*[:#\\.\\-]+\\s*)?(?:(?:{$typePattern})\\s*[:#\\.\\-]?\\s*)?/u",
            '',
            $candidate,
            1
        );

        if (preg_match('/^\\s*(\\d{1,3}(?:[\\.\\s]\\d{3}){1,4})(?=\\D|$)/', $candidate, $matches) === 1) {
            return (string) preg_replace('/\\D+/', '', $matches[1]);
        }

        if (preg_match('/^\\s*([A-Z0-9]{4,20})(?=[\\s:;,\\-\\/]|$)/', $candidate, $matches) === 1) {
            $token = $matches[1];
            if (preg_match('/\\d/', $token) === 1) {
                return $token;
            }
        }

        $token = TextNormalization::normalizeToken($candidate);
        if (preg_match('/^(?=.*\\d)[A-Z0-9]{4,20}$/', $token) === 1) {
            return $token;
        }

        return trim($value);
    }

    /**
     * Limpia nombres cuando Gemini concatena tipo/documento con la persona.
     *
     * Ej: "CC 94229637 NORENA AGUDELO" -> "NORENA AGUDELO".
     * Si al retirar el prefijo no queda texto alfabético, conserva el original.
     */
    public static function normalizePersonNameFromMixedIdentityLine(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        $labelPattern = self::labelPattern();
        $typePattern = self::docTypePattern(includeNit: true);
        $numberPattern = '(?:\\d{1,3}(?:[\\.\\s]\\d{3}){1,4}|(?=[A-Z0-9]{0,19}\\d)[A-Z0-9]{4,20})';
        $cleaned = (string) preg_replace(
            "/^\\s*(?:(?:{$labelPattern})\\s*[:#\\.\\-]+\\s*)?(?:(?:{$typePattern})\\s*[:#\\.\\-]?\\s*)?{$numberPattern}\\s*[-:\\/\\s]+\\s*/iu",
            '',
            $candidate,
            1,
            $count
        );

        $cleaned = trim($cleaned);
        if ($count === 1 && $cleaned !== '' && preg_match('/[[:alpha:]]/u', $cleaned) === 1) {
            return $cleaned;
        }

        return $candidate;
    }

    /**
     * @internal Patrón regex de tipos documentales para extracción de prefijos.
     */
    public static function docTypePattern(bool $includeNit): string
    {
        $types = 'PPT|NUIP|PEP|CC|CE|TI|RC|PA|PE|MS|AS|SC';
        return $includeNit ? $types . '|NIT' : $types;
    }

    /**
     * @internal Patrón regex de etiquetas que preceden números de documento.
     */
    public static function labelPattern(): string
    {
        return 'PACIENTE|M[EÉ]DICO|MEDICO|DOCUMENTO|DOC|IDENTIFICACI[OÓ]N|IDENTIDAD|NUMERO|N[Oº°]?';
    }
}
