<?php

declare(strict_types=1);

namespace App\Services\Audit;

/** Recorre las reglas existentes sin HTTP ni efectos; conserva todos los candidatos. */
final class SemanticCandidateCollector implements SemanticMatchResolver
{
    private array $candidates = [];

    public static function identity(string $expected, string $actual, array $context): array
    {
        $purpose = SemanticMatchPurpose::from((string) ($context['call_purpose'] ?? SemanticMatchPurpose::PRODUCT->value));
        $row = hash('sha256', json_encode([$purpose->value, (string) ($context['field'] ?? ''), $context['semantic_row_index'] ?? 'scalar'], JSON_THROW_ON_ERROR));
        $pair = hash('sha256', json_encode([$purpose->value, (string) ($context['field'] ?? ''), $expected, $actual, (string) ($context['document_context'] ?? '')], JSON_THROW_ON_ERROR));
        return [$row, $pair, $purpose];
    }

    public function evaluate(string $expected, string $actual, array $context = []): array
    {
        [$row, $pair, $purpose] = self::identity($expected, $actual, $context);
        $this->candidates[$pair] ??= [
            'pair_id' => $pair, 'row_id' => $row, 'row_ids' => [], 'purpose' => $purpose->value,
            'expected' => $expected, 'actual' => $actual,
            'context' => array_intersect_key($context, array_flip(['field', 'tipoCampo', 'tipoDato', 'document_type', 'document_context', 'call_purpose'])),
        ];
        if (!in_array($row, $this->candidates[$pair]['row_ids'], true)) {
            $this->candidates[$pair]['row_ids'][] = $row;
        }
        return ['is_match' => false, 'reasoning' => 'Comparación semántica pendiente.'];
    }

    public function candidates(): array
    {
        return array_values($this->candidates);
    }
}
