<?php

namespace App\Services\Audit;

class JsonResponseParser
{
    /**
     * Parsea una respuesta de texto crudo intentando extraer un JSON válido.
     * Mantiene el flujo simple y valida estructura mínima.
     */
    public function parse(string $text): ?array
    {
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }

        // 1. Extraer contenido de bloque markdown si existe.
        $normalized = $this->stripCodeFences($raw);

        // 2. Extraer el primer objeto JSON balanceando llaves.
        $jsonString = $this->extractFirstJsonObject($normalized);
        if ($jsonString === null) {
            return null;
        }

        // 3. Limpieza simple de comas finales (común en LLMs).
        $jsonString = preg_replace('/,\s*([}\]])/', '$1', $jsonString);

        // 4. Decodificar y validar estructura mínima.
        $decoded = json_decode($jsonString, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->isValidSchema($decoded) ? $decoded : null;
    }

    private function stripCodeFences(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            return $matches[1];
        }
        return $text;
    }

    private function extractFirstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $length = strlen($text);
        $depth = 0;
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function isValidSchema(array $data): bool
    {
        if (!isset($data['response']) || !is_string($data['response'])) {
            return false;
        }
        if (!isset($data['message']) || !is_string($data['message'])) {
            return false;
        }
        if (!isset($data['data']) || !is_array($data['data'])) {
            return false;
        }
        if (!array_key_exists('items', $data['data']) || !is_array($data['data']['items'])) {
            return false;
        }

        return true;
    }
}
