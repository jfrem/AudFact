<?php

declare(strict_types=1);

namespace App\Services\Audit;

final class ResolvedSemanticResolver implements SemanticMatchResolver
{
    public function __construct(private readonly array $results)
    {
    }

    public function evaluate(string $expected, string $actual, array $context = []): array
    {
        [, $pair] = SemanticCandidateCollector::identity($expected, $actual, $context);
        $result = $this->results[$pair] ?? null;
        if (!is_array($result) || !is_bool($result['is_match'] ?? null) || !is_string($result['reasoning'] ?? null)) {
            throw new \DomainException('semantic_result_missing');
        }
        // Las métricas remotas pertenecen al request, nunca se replican por par.
        return ['is_match' => $result['is_match'], 'reasoning' => $result['reasoning'], 'cache_hit' => ($result['source'] ?? '') === 'cache'];
    }
}
