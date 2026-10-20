<?php

declare(strict_types=1);

namespace App\Services\Audit;

/** Contexto de diagnóstico sin texto remoto ni datos documentales. */
final class GeminiResponseException extends \RuntimeException
{
    public function __construct(
        public readonly GeminiResponseFailureReason $reason,
        private readonly array $safeContext
    ) {
        if (!in_array($safeContext['task_type'] ?? null, ['extraction', 'semantic'], true)) {
            throw new \InvalidArgumentException('gemini_response_context_invalid');
        }
        foreach ($safeContext as $key => $value) {
            $valid = match ($key) {
                'task_type' => true,
                'finish_reason', 'block_reason' => is_string($value) && preg_match('/^[A-Z0-9_]{1,64}$/D', $value) === 1,
                'model_version' => is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) === 1,
                'prompt_tokens', 'candidates_tokens', 'thoughts_tokens' => is_int($value) && $value >= 0,
                default => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException('gemini_response_context_invalid');
            }
        }
        parent::__construct($reason->value);
    }

    public function retryable(): bool
    {
        return $this->reason === GeminiResponseFailureReason::MISSING_CANDIDATE;
    }

    public function context(): array
    {
        return $this->safeContext;
    }
}
