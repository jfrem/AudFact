<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Resuelve comparaciones semánticas a partir de resultados previamente persistidos.
 *
 * Implementa búsqueda directa O(1) por hash de par determinista, complementada
 * con un fallback secundario por signatura canónica para garantizar resiliencia
 * ante variaciones leves de contexto o planes legados en vuelo.
 */
final class ResolvedSemanticResolver implements SemanticMatchResolver
{
    /**
     * @param array<string, array<string, mixed>> $results Resultados resueltos indexados por pair_id.
     */
    public function __construct(private readonly array $results)
    {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{is_match: bool, reasoning: string, cache_hit: bool}
     *
     * @throws \DomainException Si el resultado no existe o no tiene estructura válida.
     */
    public function evaluate(string $expected, string $actual, array $context = []): array
    {
        [, $pair] = SemanticCandidateCollector::identity($expected, $actual, $context);
        $result = $this->results[$pair] ?? null;

        if (!$this->isValidResult($result)) {
            $result = $this->fallbackMatch($expected, $actual, $context);
            if ($result === null) {
                throw new \DomainException('semantic_result_missing');
            }
        }

        // Las métricas remotas pertenecen al request original, nunca se replican por par.
        return [
            'is_match'  => (bool) $result['is_match'],
            'reasoning' => (string) $result['reasoning'],
            'cache_hit' => ($result['source'] ?? '') === 'cache',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function fallbackMatch(string $expected, string $actual, array $context): ?array
    {
        $purpose = SemanticMatchPurpose::from((string) ($context['call_purpose'] ?? SemanticMatchPurpose::PRODUCT->value));
        $field = (string) ($context['field'] ?? '');

        foreach ($this->results as $entry) {
            if (!$this->isValidResult($entry)) {
                continue;
            }
            if ($this->matchesCanonicalSignature($entry, $purpose, $field, $expected, $actual)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param mixed $result
     * @phpstan-assert-if-true array{is_match: bool, reasoning: string} $result
     */
    private function isValidResult(mixed $result): bool
    {
        return is_array($result)
            && is_bool($result['is_match'] ?? null)
            && is_string($result['reasoning'] ?? null);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function matchesCanonicalSignature(
        array $entry,
        SemanticMatchPurpose $purpose,
        string $field,
        string $expected,
        string $actual
    ): bool {
        $matchesPurpose = ($entry['purpose'] ?? '') === $purpose->value;
        $matchesExpected = ($entry['expected'] ?? '') === $expected;
        $matchesActual = ($entry['actual'] ?? '') === $actual;
        $matchesField = ($entry['field'] ?? '') === $field || $field === '';

        return $matchesPurpose && $matchesExpected && $matchesActual && $matchesField;
    }
}
