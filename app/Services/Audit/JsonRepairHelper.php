<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * JsonRepairHelper — Repara JSON malformado/truncado proveniente de LLMs (Gemini).
 *
 * Estrategias de reparación (en orden de aplicación):
 *   1. Cierre de strings truncados (comillas sin cerrar).
 *   2. Eliminación de comas finales antes de cierre (,} / ,]).
 *   3. Eliminación de pares key-value incompletos al final del truncamiento.
 *   4. Cierre de brackets/llaves desbalanceados.
 *
 * Esta clase NO decodifica el JSON — solo devuelve un string reparado.
 * El caller es responsable de validar el resultado con json_decode().
 *
 * @since 4.0
 */
class JsonRepairHelper
{
    /**
     * Intenta reparar un string JSON malformado.
     *
     * @param string $raw JSON crudo (potencialmente truncado)
     * @return string JSON reparado (puede seguir siendo inválido en casos extremos)
     */
    public function repair(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return $text;
        }

        // Paso 1: Cerrar strings truncados (comilla abierta sin cierre).
        $text = $this->closeOpenStrings($text);

        // Paso 2: Limpiar pares key-value incompletos al final del truncamiento.
        $text = $this->cleanTrailingIncompleteEntries($text);

        // Paso 3: Eliminar comas finales antes de cierre (`,"` → sin coma previa al cierre).
        $text = preg_replace('/,\s*([}\]])/', '$1', $text) ?? $text;

        // Paso 4: Cerrar brackets/llaves desbalanceados.
        $text = $this->closeUnbalancedBrackets($text);

        return $text;
    }

    /**
     * Detecta y cierra comillas de string abiertas sin cierre.
     *
     * Recorre el texto carácter por carácter para distinguir strings
     * de contenido estructural, respetando escapes (\").
     */
    private function closeOpenStrings(string $text): string
    {
        $length = strlen($text);
        $inString = false;
        $lastOpenQuote = -1;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '\\' && $inString) {
                // Saltar carácter escapado.
                $i++;
                continue;
            }

            if ($char === '"') {
                if ($inString) {
                    $inString = false;
                    $lastOpenQuote = -1;
                } else {
                    $inString = true;
                    $lastOpenQuote = $i;
                }
            }
        }

        // Si quedó un string abierto, cerrarlo.
        if ($inString && $lastOpenQuote >= 0) {
            $text .= '"';
        }

        return $text;
    }

    /**
     * Elimina pares key-value incompletos al final del JSON truncado.
     *
     * Patrones detectados:
     *   - `"key":` sin valor (truncamiento justo después de los dos puntos)
     *   - `"key": "valor truncado"` seguido de coma sin siguiente par
     */
    private function cleanTrailingIncompleteEntries(string $text): string
    {
        // Eliminar `"key":` sin valor al final (truncamiento post-colon).
        $text = preg_replace('/,?\s*"[^"]*"\s*:\s*$/', '', $text) ?? $text;

        // Eliminar `,` final huérfana.
        $text = preg_replace('/,\s*$/', '', $text) ?? $text;

        return $text;
    }

    /**
     * Cierra brackets y llaves desbalanceados.
     *
     * Recorre el texto (fuera de strings) contando `{`, `}`, `[`, `]`
     * y añade los cierres faltantes al final.
     */
    private function closeUnbalancedBrackets(string $text): string
    {
        $stack = [];
        $length = strlen($text);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '\\' && $inString) {
                $i++;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $stack[] = '}';
            } elseif ($char === '[') {
                $stack[] = ']';
            } elseif ($char === '}' || $char === ']') {
                if (!empty($stack) && end($stack) === $char) {
                    array_pop($stack);
                }
            }
        }

        // Cerrar en orden inverso (LIFO).
        if (!empty($stack)) {
            $text .= implode('', array_reverse($stack));
        }

        return $text;
    }
}
