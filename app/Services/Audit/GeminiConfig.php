<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Value Object inmutable con la configuración del modelo Gemini.
 *
 * Encapsula todos los parámetros de generación para que GeminiGateway
 * no necesite recibirlos individualmente en el constructor.
 */
final class GeminiConfig
{
   public function __construct(
        public string $model,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public int $maxOutputTokens = 8192,
        public string $responseMimeType = 'application/json',
        public ?string $mediaResolution = null,
        public ?int $thinkingBudget = null,
        public ?int $seed = null,
    ) {}

    /**
     * Genera el array `generationConfig` filtrado (sin nulls) para el payload de Gemini.
     *
     * @param  array<string, mixed> $overrides Sobrecargas opcionales del caller.
     * @return array<string, mixed>
     */
    public function toGenerationConfig(array $overrides = []): array
    {
        $base = array_filter([
            'temperature' => $this->temperature ?? 0.0,
            'topP'        => $this->topP,
            'topK'        => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'seed'        => $this->seed,
        ], fn($value) => $value !== null);

        // Thinking budget: override > default
        $thinkingBudget = $overrides['thinkingBudget'] ?? $this->thinkingBudget;
        unset($overrides['thinkingBudget']);

        $merged = array_merge($base, $overrides);

        if ($thinkingBudget !== null) {
            $merged['thinkingConfig'] = ['thinkingBudget' => (int) $thinkingBudget];
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            'model'           => $this->model,
            'temperature'     => $this->temperature ?? 0.0,
            'topP'            => $this->topP,
            'topK'            => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'responseMimeType'=> $this->responseMimeType,
            'mediaResolution' => $this->mediaResolution,
            'thinkingBudget'  => $this->thinkingBudget,
            'seed'            => $this->seed,
        ];
    }
}
