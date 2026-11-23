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
    private const TYPO_MIN_TOKEN_LEN_MULTI = 5;
    private const TYPO_MIN_TOKEN_LEN_MONO = 7;
    private const PERSON_MIN_EXACT_TOKENS = 2;
    private const TEXT_MIN_EXACT_TOKENS = 1;
    private const TEXT_MAX_TOKEN_CARDINALITY_DIFF = 1;
    private const GENDER_SUFFIX_MIN_STEM_LEN = 4;

    /**
     * Normaliza texto para comparación: uppercase, sin acentos, solo alfanumérico con espacios.
     *
     * Nota: diferente de normalizeToken() que elimina TODO lo no-alfanumérico (sin espacios).
     */
    public static function normalizeText(string $value): string
    {
        $withoutAccents = self::toUpperAsciiWithoutAccents($value);
        if ($withoutAccents === '') {
            return '';
        }

        $alphanumericOnly = (string) preg_replace('/[^A-Z0-9]+/', ' ', $withoutAccents);
        $normalized       = (string) preg_replace('/\s+/', ' ', trim($alphanumericOnly));

        return $normalized;
    }

    /**
     * Normaliza nombres de artículos farmacéuticos para comparación léxica.
     *
     * Extiende normalizeText() con limpieza previa de sufijos de embalaje
     * comercial, tamaños de lote y duplicados de presentación que son
     * exclusivos de la nomenclatura de bodega vs. prescripción médica.
     *
     * Ejemplos de transformación (antes → después de normalizeArticleName):
     *   "ACETAMINOFEN 500MG C*100 TABLETA"          → "ACETAMINOFEN 500MG TABLETA"
     *   "BETAMETASONA 0.05% CREMA TOPICA TUBO*40GR" → "BETAMETASONA 0 05 CREMA TOPICA"
     *   "BISACODILO 5 MG (GRAGEA) // TABLETA"       → "BISACODILO 5 MG GRAGEA"
     *   "CARVEDILOL 25 MG (TABLETA) (H)"            → "CARVEDILOL 25 MG TABLETA"
     *   "DUTASTERIDA+TAMSULOSINA 0.5 MG - GENERICO" → "DUTASTERIDA TAMSULOSINA 0 5 MG"
     *
     * @see DocumentPolicyEngine::evaluateArticleSetField() — único consumidor.
     */
    public static function normalizeArticleName(string $value): string
    {
        $upper = mb_strtoupper(trim($value), 'UTF-8');
        if ($upper === '') {
            return '';
        }

        // 1. Eliminar sufijo de presentación duplicada: "// TABLETA", "// UNIDAD"
        $upper = (string) preg_replace('/\s*\/\/\s*.+$/u', '', $upper);

        // 2. Eliminar tamaños de lote/empaque: "C*100", "C*300", "C *900"
        $upper = (string) preg_replace('/\bC\s*\*\s*\d+\b/u', '', $upper);

        // 3. Eliminar envases con dimensión: "TUBO*40GR", "FCO*10ML", "FRASCO*60ML"
        $upper = (string) preg_replace('/\b(?:TUBO|FCO|FRASCO)\s*\*?\s*\d+\s*(?:GR|ML|G|MG)?\b/u', '', $upper);

        // 4. Eliminar marcador hospitalario: "(H)"
        $upper = (string) preg_replace('/\s*\(H\)\s*/u', ' ', $upper);

        // 5. Eliminar sufijo genérico: "- GENERICO" al final
        $upper = (string) preg_replace('/\s*-\s*GENERICO\s*$/u', '', $upper);

        // Aplicar pipeline estándar de normalización (acentos, alfanumérico, espacios)
        return self::normalizeText($upper);
    }
    /**
     * Calcula similitud Jaccard con tokens ordenados alfabéticamente.
     *
     * Normaliza ambos valores con normalizeArticleName(), tokeniza,
     * ordena los tokens y calcula la intersección / unión.
     * Resuelve inversiones de principios activos compuestos sin LLM
     * (ej: TAMSULOSINA+DUTASTERIDA vs DUTASTERIDA+TAMSULOSINA).
     *
     * @return float Jaccard entre 0.0 y 1.0
     */
    public static function tokenSortedJaccard(string $left, string $right): float
    {
        $leftNorm = self::normalizeArticleName($left);
        $rightNorm = self::normalizeArticleName($right);

        if ($leftNorm === '' || $rightNorm === '') {
            return 0.0;
        }

        $leftTokens  = self::tokenize($leftNorm);
        $rightTokens = self::tokenize($rightNorm);
        sort($leftTokens, SORT_STRING);
        sort($rightTokens, SORT_STRING);

        if ($leftTokens === $rightTokens) {
            return 1.0;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $union        = array_unique(array_merge($leftTokens, $rightTokens));

        return $union === [] ? 0.0 : (count($intersection) / count($union));
    }

    /**
     * Normaliza un token de texto: elimina acentos, pasa a mayúsculas y
     * suprime caracteres no alfanuméricos.
     *
     * Helper compartido para DocumentPolicyEngine y DocumentNormalizer.
     */
    public static function normalizeToken(string $value): string
    {
        $withoutAccents = self::toUpperAsciiWithoutAccents($value);
        if ($withoutAccents === '') {
            return '';
        }

        return (string) preg_replace('/[^A-Z0-9]+/', '', $withoutAccents);
    }

    /**
     * Translitera acentos/ñ antes de convertir a mayúsculas para evitar que
     * strtoupper() de un solo byte mutile caracteres UTF-8 en minúscula.
     */
    private static function toUpperAsciiWithoutAccents(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return strtoupper(self::stripAccents($trimmed));
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
        sort($leftTokens, SORT_STRING);
        sort($rightTokens, SORT_STRING);

        return $leftTokens === $rightTokens;
    }

    /**
     * Compara dos nombres de persona con tolerancia estructural.
     *
     * Algoritmo puramente estructural (sin diccionarios hardcodeados):
     * - Tokens sobrantes del lado largo se ignoran (títulos, conectores, etc.)
     * - Iniciales (tokens de 1 letra) matchean por primera letra
     * - Se requiere que TODOS los tokens completos (len>1) del lado más corto
     *   tengan correspondencia, con un mínimo de 2 full-token matches.
     */
    public static function samePersonNameTokenSet(string $left, string $right): bool
    {
        $leftTokens = self::tokenize(self::normalizePersonNameForTokenSet($left));
        $rightTokens = self::tokenize(self::normalizePersonNameForTokenSet($right));

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        // El lado con menos tokens es el "corto" (perspectiva de verificación)
        if (count($leftTokens) <= count($rightTokens)) {
            return self::personNameTokensMatch($leftTokens, $rightTokens);
        }

        return self::personNameTokensMatch($rightTokens, $leftTokens);
    }

    /**
     * Verifica que todos los tokens completos del lado corto tengan correspondencia
     * en el lado largo, usando matching exacto/wildcard e iniciales.
     *
     * @param string[] $shortTokens Lado con menos tokens
     * @param string[] $longTokens  Lado con más tokens
     */
    private static function personNameTokensMatch(array $shortTokens, array $longTokens): bool
    {
        // Identificar tokens completos (length > 1) del lado corto
        $shortFullIndices = [];
        foreach ($shortTokens as $i => $token) {
            if (strlen($token) > 1) {
                $shortFullIndices[] = $i;
            }
        }

        // Mínimo 1 token completo para matching confiable
        if (count($shortFullIndices) < 1) {
            return false;
        }

        /** @var array<int,true> */
        $usedLong = [];
        /** @var array<int,true> */
        $matchedShort = [];

        // Pass 1: Exact matches y wildcard (prioridad más alta)
        foreach ($shortTokens as $si => $st) {
            foreach ($longTokens as $li => $lt) {
                if (isset($usedLong[$li])) {
                    continue;
                }
                if (self::tokensMatchWithWildcard($st, $lt)) {
                    $usedLong[$li] = true;
                    $matchedShort[$si] = true;
                    break;
                }
            }
        }

        // Pass 2: Initial matches para tokens restantes sin match
        foreach ($shortTokens as $si => $st) {
            if (isset($matchedShort[$si])) {
                continue;
            }

            foreach ($longTokens as $li => $lt) {
                if (isset($usedLong[$li])) {
                    continue;
                }

                // Inicial en cualquiera de los dos lados matchea por primera letra
                if ((strlen($st) === 1 || strlen($lt) === 1) && $st[0] === $lt[0]) {
                    $usedLong[$li] = true;
                    $matchedShort[$si] = true;
                    break;
                }
            }
        }

        // TODOS los tokens del lado corto deben haber matcheado (completos o iniciales)
        // para garantizar que sea un subconjunto válido.
        if (count($matchedShort) !== count($shortTokens)) {
            return false;
        }

        return true;
    }

    public static function normalizePersonNameForTokenSet(string $value): string
    {
        $withoutAccents = self::toUpperAsciiWithoutAccents($value);
        if ($withoutAccents === '') {
            return '';
        }

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

    /**
     * Evalúa si dos valores textuales coinciden por conjunto de tokens permitiendo
     * exactamente 1 token alfabético con error ortográfico menor (Damerau-Levenshtein = 1).
     *
     * Guardas deterministas:
     * - Normaliza ambos valores con normalizeText() (ASCII uppercase alfanumérico) y tokeniza sin orden.
     * - Si $isPersonName = true: requiere >= 2 tokens exactos coincidentes, exactamente 1 token
     *   divergente puramente alfabético (/^[A-Z]+$/), min(len) >= 5, damerauLevenshteinDistance1 === true
     *   y NO flexión terminal de género (intercambio A/O ni sufijo -A con raíz >= 4).
     * - Si $isPersonName = false (institution_name o text semántico como Laboratorio):
     *   multi-token exige diferencia de cardinalidad <= 1, >= 1 token exacto, min(len) >= 5;
     *   mono-token (1 vs 1) exige min(len) >= 7.
     *
     * @return array{fdv_token:string,doc_token:string}|null Par tolerado si coincide, null en caso contrario.
     */
    public static function matchTokenSetWithMinorAlphabeticTypo(
        string $fdvValue,
        string $docValue,
        bool $isPersonName = false
    ): ?array {
        $fdvTokens = self::tokenize(self::normalizeText($fdvValue));
        $docTokens = self::tokenize(self::normalizeText($docValue));

        if ($fdvTokens === [] || $docTokens === []) {
            return null;
        }

        // Caso mono-token (solo permitido fuera de person_name y cuando ambos lados tienen 1 token)
        if (count($fdvTokens) === 1 && count($docTokens) === 1) {
            if ($isPersonName) {
                return null;
            }
            $a = $fdvTokens[0];
            $b = $docTokens[0];
            if (self::isEligibleAlphabeticTypoPair($a, $b, self::TYPO_MIN_TOKEN_LEN_MONO, false)) {
                return ['fdv_token' => $a, 'doc_token' => $b];
            }
            return null;
        }

        $fdvIsShort  = count($fdvTokens) <= count($docTokens);
        $shortTokens = $fdvIsShort ? $fdvTokens : $docTokens;
        $longTokens  = $fdvIsShort ? $docTokens : $fdvTokens;

        // Nunca emparejar 1 token contra N > 1 por typo
        if (count($shortTokens) < 2) {
            return null;
        }

        // Fuera de person_name, acotar diferencia de cardinalidad para evitar omisiones múltiples + typo
        if (!$isPersonName && (count($longTokens) - count($shortTokens)) > self::TEXT_MAX_TOKEN_CARDINALITY_DIFF) {
            return null;
        }

        /** @var array<string,list<int>> $longIndicesByValue */
        $longIndicesByValue = [];
        foreach ($longTokens as $li => $lt) {
            $longIndicesByValue[$lt][] = $li;
        }

        $usedLong       = [];
        $unmatchedShort = [];
        $exactCount     = 0;

        foreach ($shortTokens as $st) {
            if (!empty($longIndicesByValue[$st])) {
                $matchedIdx            = array_shift($longIndicesByValue[$st]);
                $usedLong[$matchedIdx] = true;
                $exactCount++;
            } else {
                $unmatchedShort[] = $st;
            }
        }

        $minExactRequired = $isPersonName ? self::PERSON_MIN_EXACT_TOKENS : self::TEXT_MIN_EXACT_TOKENS;
        if (count($unmatchedShort) !== 1 || $exactCount < $minExactRequired) {
            return null;
        }

        return self::findUniqueTypoPair($unmatchedShort[0], $longTokens, $usedLong, $fdvIsShort, $isPersonName);
    }

    /**
     * Busca un único token no usado en $longTokens a distancia Damerau-Levenshtein 1.
     * Rechaza con null si existe ambigüedad (dos o más candidatos elegibles).
     *
     * @param list<string>    $longTokens
     * @param array<int,true> $usedLong
     * @return array{fdv_token:string,doc_token:string}|null
     */
    private static function findUniqueTypoPair(
        string $candidate,
        array $longTokens,
        array $usedLong,
        bool $fdvIsShort,
        bool $isPersonName
    ): ?array {
        $matchedPair = null;

        foreach ($longTokens as $li => $lt) {
            if (isset($usedLong[$li])) {
                continue;
            }
            if (!self::isEligibleAlphabeticTypoPair($candidate, $lt, self::TYPO_MIN_TOKEN_LEN_MULTI, $isPersonName)) {
                continue;
            }
            if ($matchedPair !== null) {
                return null;
            }
            $matchedPair = $fdvIsShort
                ? ['fdv_token' => $candidate, 'doc_token' => $lt]
                : ['fdv_token' => $lt, 'doc_token' => $candidate];
        }

        return $matchedPair;
    }

    /**
     * Verifica si dos tokens ASCII mayúsculas están a distancia Damerau-Levenshtein
     * (alineación óptima) exactamente igual a 1:
     * 1 sustitución, 1 inserción, 1 eliminación o 1 transposición de 2 caracteres adyacentes.
     */
    public static function damerauLevenshteinDistance1(string $left, string $right): bool
    {
        if ($left === $right) {
            return false;
        }

        $lenLeft  = strlen($left);
        $lenRight = strlen($right);
        if (abs($lenLeft - $lenRight) > 1) {
            return false;
        }

        if ($lenLeft === $lenRight) {
            for ($i = 0; $i < $lenLeft; $i++) {
                if ($left[$i] !== $right[$i]) {
                    // Sustitución única
                    if (substr($left, $i + 1) === substr($right, $i + 1)) {
                        return true;
                    }
                    // Transposición de dos caracteres adyacentes
                    return $i + 1 < $lenLeft
                        && $left[$i] === $right[$i + 1]
                        && $left[$i + 1] === $right[$i]
                        && substr($left, $i + 2) === substr($right, $i + 2);
                }
            }
            return false;
        }

        $short    = $lenLeft < $lenRight ? $left : $right;
        $long     = $lenLeft < $lenRight ? $right : $left;
        $lenShort = strlen($short);

        for ($i = 0; $i < $lenShort; $i++) {
            if ($short[$i] !== $long[$i]) {
                return substr($short, $i) === substr($long, $i + 1);
            }
        }

        return true;
    }

    /**
     * Detecta flexiones terminales de género en nombres de persona:
     * 1) Igual longitud con intercambio exclusivo de vocal final A/O (MARIA vs MARIO, CARLA vs CARLO).
     * 2) Diferencia de longitud 1 donde el token largo es la raíz (len >= 4) seguida de sufijo terminal 'A'
     *    (DANIEL vs DANIELA, GABRIEL vs GABRIELA, MANUEL vs MANUELA, RAFAEL vs RAFAELA).
     */
    public static function isFinalGenderInflectionDiff(string $left, string $right): bool
    {
        $lenLeft  = strlen($left);
        $lenRight = strlen($right);

        if ($lenLeft === $lenRight) {
            if ($lenLeft < 2 || substr($left, 0, -1) !== substr($right, 0, -1)) {
                return false;
            }
            $lastLeft  = $left[$lenLeft - 1];
            $lastRight = $right[$lenRight - 1];

            return ($lastLeft === 'A' && $lastRight === 'O')
                || ($lastLeft === 'O' && $lastRight === 'A');
        }

        if (abs($lenLeft - $lenRight) === 1) {
            $short = $lenLeft < $lenRight ? $left : $right;
            $long  = $lenLeft < $lenRight ? $right : $left;

            return strlen($short) >= self::GENDER_SUFFIX_MIN_STEM_LEN && $long === $short . 'A';
        }

        return false;
    }

    private static function isEligibleAlphabeticTypoPair(
        string $left,
        string $right,
        int $minLength,
        bool $blockGenderInflection
    ): bool {
        if (min(strlen($left), strlen($right)) < $minLength) {
            return false;
        }
        if (preg_match('/^[A-Z]+$/', $left) !== 1 || preg_match('/^[A-Z]+$/', $right) !== 1) {
            return false;
        }
        if ($blockGenderInflection && self::isFinalGenderInflectionDiff($left, $right)) {
            return false;
        }

        return self::damerauLevenshteinDistance1($left, $right);
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

    /**
     * Convierte un nombre de campo técnico (camelCase o PascalCase) a una cadena
     * legible para humanos (ej. "TipoDocumentoPaciente" -> "Tipo Documento Paciente").
     */
    public static function humanizeFieldName(string $fieldName): string
    {
        if ($fieldName === '') {
            return '';
        }

        // Inserta un espacio antes de cada mayúscula que no esté al inicio de la cadena
        $humanized = (string) preg_replace('/(?<!^)(?=[A-Z])/', ' ', $fieldName);

        // Asegura que la primera letra siempre sea mayúscula (para casos de camelCase inicial)
        return ucfirst(trim($humanized));
    }
}
