<?php

declare(strict_types=1);

namespace App\Services\Audit;

/** Clasifica disponibilidad de evidencia; los parsers validan el contenido. */
final class GeminiResponseGuard
{
    public static function assertUsable(array $response, string $taskType): array
    {
        if (!in_array($taskType, ['extraction', 'semantic'], true)) {
            throw new \InvalidArgumentException('gemini_response_task_invalid');
        }
        $context = ['task_type' => $taskType];
        $block = $response['promptFeedback']['blockReason'] ?? null;
        $candidate = $response['candidates'][0] ?? null;
        $finish = is_array($candidate) ? ($candidate['finishReason'] ?? null) : null;
        foreach (['block_reason' => $block, 'finish_reason' => $finish,
            'model_version' => $response['modelVersion'] ?? null] as $key => $value) {
            if ($value !== null) {
                $pattern = $key === 'model_version' ? '/^[A-Za-z0-9._-]{1,128}$/D' : '/^[A-Z0-9_]{1,64}$/D';
                $context[$key] = is_string($value) && preg_match($pattern, $value) === 1 ? $value : 'UNKNOWN';
            }
        }
        foreach (['prompt_tokens' => 'promptTokenCount', 'candidates_tokens' => 'candidatesTokenCount',
            'thoughts_tokens' => 'thoughtsTokenCount'] as $key => $source) {
            $value = $response['usageMetadata'][$source] ?? null;
            if (is_int($value) && $value >= 0) {
                $context[$key] = $value;
            }
        }
        $reason = match (true) {
            is_string($block) && $block !== '' && $block !== 'BLOCK_REASON_UNSPECIFIED' => GeminiResponseFailureReason::PROVIDER_BLOCKED,
            !is_array($candidate) => GeminiResponseFailureReason::MISSING_CANDIDATE,
            $finish === 'MAX_TOKENS' => GeminiResponseFailureReason::OUTPUT_TRUNCATED,
            $finish !== 'STOP' => GeminiResponseFailureReason::UNSAFE_FINISH_REASON,
            default => null,
        };
        if ($reason !== null) {
            throw new GeminiResponseException($reason, $context);
        }
        return $candidate;
    }
}
