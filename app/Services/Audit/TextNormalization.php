<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Operaciones de normalización y comparación de texto.
 *
 * Extraída de AuditFindingRules para cohesión (SRP).
 * Todas las funciones son puras, determinísticas y sin estado.
 */
final class TextNormalization
{
    /**
     * Normaliza texto para comparación: uppercase, sin acentos, solo alfanumérico con espacios.
     *
     * Nota: diferente de normalizeToken() que elimina TODO lo no-alfanumérico (sin espacios).
     */
    public static function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $withoutAccents   = self::stripAccents(strtoupper($trimmed));
        $alphanumericOnly = (string) preg_replace('/[^A-Z0-9]+/', ' ', $withoutAccents);
        $normalized       = (string) preg_replace('/\s+/', ' ', trim($alphanumericOnly));

        return $normalized;
    }

    /**
     * Normaliza un token de texto: elimina acentos, pasa a mayúsculas y
     * suprime caracteres no alfanuméricos.
     *
     * Helper compartido para DocumentPolicyEngine y DocumentNormalizer.
     */
    public static function normalizeToken(string $value): string
    {
        $ascii = strtr(trim($value), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($ascii));
    }

    /**
     * Elimina acentos y diacríticos de un string.
     *
     * Usa strtr determinístico (no depende de locale del servidor) como estrategia
     * primaria para los caracteres comunes en español/portugués. Solo recurre a
     * iconv como fallback para caracteres Unicode exóticos fuera de la tabla.
     */
    public static function stripAccents(string $value): string
    {
        // Estrategia primaria: strtr determinístico (independiente de locale)
        $stripped = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ç' => 'C', 'ç' => 'c',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'Ã' => 'A', 'Õ' => 'O',
            'ã' => 'a', 'õ' => 'o',
        ]);

        // Si strtr eliminó todo o no quedan non-ASCII, listo
        if (!preg_match('/[^\x00-\x7F]/', $stripped)) {
            return $stripped;
        }

        // Fallback: iconv para caracteres Unicode fuera de la tabla
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stripped);
        return $converted !== false ? $converted : $stripped;
    }

    /**
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        return array_values(array_unique(
            array_filter(explode(' ', $text), static fn(string $t): bool => $t !== '')
        ));
    }

    public static function sameTokenSet(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $leftTokens  = self::tokenize($left);
        $rightTokens = self::tokenize($right);
        sort($leftTokens);
        sort($rightTokens);

        return $leftTokens === $rightTokens;
    }

    public static function samePersonNameTokenSet(string $left, string $right): bool
    {
        $leftTokens = self::tokenize(self::normalizePersonNameForTokenSet($left));
        $rightTokens = self::tokenize(self::normalizePersonNameForTokenSet($right));

        if ($leftTokens === [] || count($leftTokens) !== count($rightTokens)) {
            return false;
        }

        sort($leftTokens);
        sort($rightTokens);

        foreach ($leftTokens as $index => $leftToken) {
            if (!self::tokensMatchWithWildcard($leftToken, $rightTokens[$index])) {
                return false;
            }
        }

        return true;
    }

    private static function normalizePersonNameForTokenSet(string $value): string
    {
        $withoutAccents = self::stripAccents(strtoupper(trim($value)));
        $normalized = (string) preg_replace('/[^A-Z0-9?]+/', ' ', $withoutAccents);
        return (string) preg_replace('/\s+/', ' ', trim($normalized));
    }

    private static function tokensMatchWithWildcard(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (strlen($left) !== strlen($right)) {
            return false;
        }

        $length = strlen($left);
        for ($i = 0; $i < $length; $i++) {
            if ($left[$i] !== $right[$i] && $left[$i] !== '?' && $right[$i] !== '?') {
                return false;
            }
        }

        return true;
    }

    public static function containsNormalizedSubstring(string $normalizedFdv, string $normalizedDoc): bool
    {
        if ($normalizedFdv === '' || $normalizedDoc === '') {
            return false;
        }

        return str_contains($normalizedDoc, $normalizedFdv)
            || str_contains($normalizedFdv, $normalizedDoc);
    }

    public static function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $similarPercent);
        $similarScore = $similarPercent / 100;

        $maxLength        = max(strlen($left), strlen($right));
        $levenshteinScore = $maxLength > 0
            ? max(0.0, 1 - (levenshtein($left, $right) / $maxLength))
            : 0.0;

        $leftTokens  = self::tokenize($left);
        $rightTokens = self::tokenize($right);
        $intersection = array_intersect($leftTokens, $rightTokens);
        $union        = array_unique(array_merge($leftTokens, $rightTokens));
        $jaccard      = $union === [] ? 0.0 : (count($intersection) / count($union));

        $composite = ($levenshteinScore * 0.6) + ($jaccard * 0.4);
        return max($similarScore, $composite);
    }
}
