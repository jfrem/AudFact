<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

final class ResolvedAuditValue
{
    public const SOURCE_FDV = 'fdv';
    public const SOURCE_DOCUMENT = 'document';

    /**
     * @param array<int,string> $values
     * @param array<int,string> $normalizedValues
     * @param array<string,mixed> $evidenceMeta
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $displayValue,
        public readonly array $values,
        public readonly array $normalizedValues,
        public readonly bool $ambiguous,
        public readonly array $evidenceMeta = [],
    ) {
    }

    public function hasValue(): bool
    {
        return $this->displayValue !== null;
    }
}

