<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use RuntimeException;

/**
 * Value Object inmutable con la configuración del modelo Gemini.
 *
 * Encapsula todos los parámetros de generación para que GeminiGateway
 * no necesite recibirlos individualmente en el constructor.
 * Incluye factory estático `fromEnv()` (antes en GeminiGatewayFactory).
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
     * Construye GeminiConfig desde variables de entorno.
     */
    public static function fromEnv(): self
    {
        Env::load();

        return new self(
            model:           (string) Env::get('GEMINI_MODEL', 'gemini-3.1-pro-preview'),
            temperature:     self::nullableFloat(Env::get('GEMINI_TEMPERATURE', null)),
            topP:            self::nullableFloat(Env::get('GEMINI_TOP_P', null)),
            topK:            self::nullableInt(Env::get('GEMINI_TOP_K', null)),
            maxOutputTokens: (int) Env::get('GEMINI_MAX_OUTPUT_TOKENS', 8192),
            responseMimeType:(string) Env::get('GEMINI_RESPONSE_MIME', 'application/json'),
            mediaResolution: self::nullableString(Env::get('GEMINI_MEDIA_RESOLUTION', null)),
            thinkingBudget:  self::nullableInt(Env::get('GEMINI_THINKING_BUDGET', null)),
            seed:            self::nullableInt(Env::get('GEMINI_SEED', null)),
        );
    }

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

    private static function nullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
