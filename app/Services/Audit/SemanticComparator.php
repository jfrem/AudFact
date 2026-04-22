<?php

namespace App\Services\Audit;

use Core\Logger;

/**
 * Comparador semántico basado en cosine similarity de embeddings.
 *
 * Compara campos extraídos de documentos contra la fuente de verdad (BD)
 * usando vectores de la Gemini Embedding API, con umbrales calibrados
 * por campo.
 *
 * @version 4.0
 */
class SemanticComparator
{
    /**
     * Umbrales de similitud coseno por campo.
     *
     * Valores calibrados para el dominio farmacéutico/médico colombiano.
     * Un match por debajo del umbral se reporta como discrepancia.
     *
     * Calibración pendiente: ajustar con 20+ dispensaciones reales.
     */
    private const THRESHOLDS = [
        'NombrePaciente'  => 0.88,
        'Medico'          => 0.88,
        'NombreArticulo'  => 0.82,
        'Laboratorio'     => 0.85,
        'IPS'             => 0.80,
        'Cliente.Entidad' => 0.85,
    ];

    /**
     * Umbral por defecto para campos no configurados explícitamente.
     */
    private float $defaultThreshold;

    public function __construct(?float $defaultThreshold = null)
    {
        $this->defaultThreshold = $defaultThreshold
            ?? (float) (\Core\Env::get('SEMANTIC_THRESHOLD_DEFAULT', '0.85'));
    }

    /**
     * Compara un batch de pares (fdv vs doc) usando embeddings.
     *
     * Cada par genera dos textos a vectorizar. Se envían todos los textos
     * únicos en una sola llamada batch al EmbeddingGateway, y se calculan
     * las similitudes localmente en PHP.
     *
     * @param array<array{field: string, fdvValue: string, docValue: string}> $pairs
     * @param EmbeddingGateway $gateway
     * @return array<array{field: string, fdvValue: string, docValue: string, similarity: float, threshold: float, match: bool}>
     */
    public function compareBatch(array $pairs, EmbeddingGateway $gateway): array
    {
        if (empty($pairs)) {
            return [];
        }

        // Recopilar todos los textos únicos a vectorizar
        $textsToEmbed = [];
        foreach ($pairs as $pair) {
            $normalizedFdv = $this->preNormalize($pair['fdvValue']);
            $normalizedDoc = $this->preNormalize($pair['docValue']);

            if ($normalizedFdv !== '') {
                $textsToEmbed[] = $normalizedFdv;
            }
            if ($normalizedDoc !== '') {
                $textsToEmbed[] = $normalizedDoc;
            }
        }

        $textsToEmbed = array_values(array_unique($textsToEmbed));

        if (empty($textsToEmbed)) {
            Logger::warning('SemanticComparator: no hay textos para vectorizar');
            return [];
        }

        // Batch embedding (una sola llamada API)
        $embeddings = $gateway->embedBatch($textsToEmbed);

        // Indexar vectores por texto normalizado
        $vectorMap = [];
        foreach ($embeddings as $item) {
            $vectorMap[$item['text']] = $item['vector'];
        }

        Logger::info('SemanticComparator: vectores obtenidos', [
            'totalTexts' => count($textsToEmbed),
            'vectorsReceived' => count($vectorMap),
            'pairsToCompare' => count($pairs),
        ]);

        // Calcular similitudes
        $results = [];
        foreach ($pairs as $pair) {
            $field = $pair['field'];
            $normalizedFdv = $this->preNormalize($pair['fdvValue']);
            $normalizedDoc = $this->preNormalize($pair['docValue']);

            // Shortcut: si los textos normalizados son idénticos, match exacto
            if ($normalizedFdv === $normalizedDoc && $normalizedFdv !== '') {
                $results[] = [
                    'field' => $field,
                    'fdvValue' => $pair['fdvValue'],
                    'docValue' => $pair['docValue'],
                    'similarity' => 1.0,
                    'threshold' => $this->getThreshold($field),
                    'match' => true,
                    'reason' => 'exact_normalized_match',
                ];
                continue;
            }

            $vectorA = $vectorMap[$normalizedFdv] ?? null;
            $vectorB = $vectorMap[$normalizedDoc] ?? null;

            // Si no hay vector para alguno de los dos, no hay match
            if ($vectorA === null || $vectorB === null) {
                $results[] = [
                    'field' => $field,
                    'fdvValue' => $pair['fdvValue'],
                    'docValue' => $pair['docValue'],
                    'similarity' => 0.0,
                    'threshold' => $this->getThreshold($field),
                    'match' => false,
                    'reason' => 'missing_vector',
                ];
                continue;
            }

            $similarity = self::cosineSimilarity($vectorA, $vectorB);
            $threshold = $this->getThreshold($field);

            // Diagnóstico: similarity sospechosamente alta para textos diferentes
            if ($similarity >= 0.99 && $normalizedFdv !== $normalizedDoc) {
                Logger::warning('SemanticComparator: similarity ≥0.99 para textos distintos', [
                    'field' => $field,
                    'normalizedFdv' => mb_substr($normalizedFdv, 0, 80),
                    'normalizedDoc' => mb_substr($normalizedDoc, 0, 80),
                    'similarity' => round($similarity, 6),
                    'vectorADim' => count($vectorA),
                    'vectorBDim' => count($vectorB),
                ]);
            }

            $results[] = [
                'field' => $field,
                'fdvValue' => $pair['fdvValue'],
                'docValue' => $pair['docValue'],
                'similarity' => round($similarity, 4),
                'threshold' => $threshold,
                'match' => $similarity >= $threshold,
            ];
        }

        return $results;
    }

    /**
     * Calcula la similitud coseno entre dos vectores.
     *
     * @param array<float> $a Vector A
     * @param array<float> $b Vector B
     * @return float Similitud entre -1.0 y 1.0
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator < 1e-10) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    /**
     * Retorna el umbral de similitud para un campo.
     *
     * @param string $field Nombre del campo
     * @return float Umbral de similitud
     */
    public function getThreshold(string $field): float
    {
        return self::THRESHOLDS[$field] ?? $this->defaultThreshold;
    }

    /**
     * Pre-normaliza texto antes de vectorizar.
     *
     * - Lowercase
     * - Elimina acentos/diacríticos
     * - Elimina puntuación excesiva
     * - Normaliza espacios
     *
     * @param string $text Texto original
     * @return string Texto normalizado
     */
    private function preNormalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Eliminar acentos (transliterate)
        $text = $this->removeAccents($text);

        // Eliminar puntuación excepto letras, números y espacios
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Normalizar espacios múltiples
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Elimina acentos y diacríticos de un texto.
     *
     * @param string $text Texto con posibles acentos
     * @return string Texto sin acentos
     */
    private function removeAccents(string $text): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
            'ü' => 'u', 'Ü' => 'u',
        ];

        return strtr($text, $map);
    }
}
