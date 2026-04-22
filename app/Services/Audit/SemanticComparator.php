<?php

namespace App\Services\Audit;

use Core\Logger;

class SemanticComparator
{
    private const THRESHOLDS = [
        'NombrePaciente'  => 0.88,
        'Medico'          => 0.88,
        'NombreArticulo'  => 0.82,
        'Laboratorio'     => 0.85,
        'IPS'             => 0.80,
        'Cliente.Entidad' => 0.85,
    ];

    private float $defaultThreshold;

    public function __construct(?float $defaultThreshold = null)
    {
        $this->defaultThreshold = $defaultThreshold
            ?? (float) (\Core\Env::get('SEMANTIC_THRESHOLD_DEFAULT', '0.85'));
    }

    public function compareBatch(array $pairs, EmbeddingGateway $gateway): array
    {
        if (empty($pairs)) {
            return [];
        }

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

        $embeddings = $gateway->embedBatch($textsToEmbed);

        $vectorMap = [];
        foreach ($embeddings as $item) {
            $vectorMap[$item['text']] = $item['vector'];
        }

        Logger::info('SemanticComparator: vectores obtenidos', [
            'totalTexts' => count($textsToEmbed),
            'vectorsReceived' => count($vectorMap),
            'pairsToCompare' => count($pairs),
        ]);

        $results = [];
        foreach ($pairs as $pair) {
            $field = $pair['field'];
            $normalizedFdv = $this->preNormalize($pair['fdvValue']);
            $normalizedDoc = $this->preNormalize($pair['docValue']);

            // Shortcut: si los textos normalizados son idénticos, match exacto
            if ($normalizedFdv === $normalizedDoc && $normalizedFdv !== '') {
                $results[] = [
                    'field' => $field,
                    'document' => $pair['document'] ?? null,
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

            if ($vectorA === null || $vectorB === null) {
                $results[] = [
                    'field' => $field,
                    'document' => $pair['document'] ?? null,
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
                'document' => $pair['document'] ?? null,
                'fdvValue' => $pair['fdvValue'],
                'docValue' => $pair['docValue'],
                'similarity' => round($similarity, 4),
                'threshold' => $threshold,
                'match' => $similarity >= $threshold,
            ];
        }

        return $results;
    }

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

    public function getThreshold(string $field): float
    {
        return self::THRESHOLDS[$field] ?? $this->defaultThreshold;
    }

    private function preNormalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = $this->removeAccents($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

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
