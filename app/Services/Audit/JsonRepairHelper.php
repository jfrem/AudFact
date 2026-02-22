<?php

namespace App\Services\Audit;

class JsonRepairHelper
{
    /**
     * Intenta reparar un JSON truncado cerrando comillas y brackets pendientes.
     */
    public static function repairTruncatedResult(string $json): ?string
    {
        // 1. Si está vacío, nada que hacer
        if (trim($json) === '') {
            return null;
        }

        // 2. Comprobar si está dentro de una cadena (comillas impares)
        // Contamos comillas no escapadas
        // Esto es simplificado. Un parser real es mejor, pero esto cubre el 90% de casos de LLM.
        $metrics = self::analyzeBalance($json);

        $fixed = $json;

        // Si terminó dentro de un string, cerramos comillas
        if ($metrics['inString']) {
            $fixed .= '"';
        }

        // Cerramos objetos y arrays según la pila
        // La pila se llena: { -> push }, [ -> push ]
        // Necesitamos cerrar en orden inverso (LIFO)
        $stack = $metrics['stack']; // array de caracteres esperados para cerrar ('}' o ']')

        while (!empty($stack)) {
            $char = array_pop($stack);
            $fixed .= $char;
        }

        return $fixed;
    }

    private static function analyzeBalance(string $str): array
    {
        $inString = false;
        $escaped = false;
        $stack = []; // Stores expected closing chars

        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            // Not in string
            if ($char === '{') {
                $stack[] = '}';
            } elseif ($char === '[') {
                $stack[] = ']';
            } elseif ($char === '}' || $char === ']') {
                if (!empty($stack)) {
                    $expected = end($stack);
                    if ($char === $expected) {
                        array_pop($stack);
                    } else {
                        // Mismatch - JSON inválido estructuralmente antes del truncado
                    }
                }
            }
        }

        return [
            'inString' => $inString,
            'stack' => $stack
        ];
    }
}
