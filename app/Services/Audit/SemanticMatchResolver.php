<?php

declare(strict_types=1);

namespace App\Services\Audit;

interface SemanticMatchResolver
{
    /** @return array{is_match:bool,reasoning:string,cache_hit?:bool,gemini_metrics?:array} */
    public function evaluate(string $expected, string $actual, array $context = []): array;
}
