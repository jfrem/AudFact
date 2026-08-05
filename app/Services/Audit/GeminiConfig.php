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
 */
final class GeminiConfig
{
    public function __construct(
        public string $model,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public int $maxOutputTokens = 8192,
        public ?string $mediaResolution = null,
        public ?int $thinkingBudget = null,
        public ?string $thinkingLevel = null,
        public ?int $seed = null,
    ) {}

    /**
     * Construye GeminiConfig desde variables de entorno.
     */
    public static function fromEnv(): self
    {
        Env::load();

        return new self(
            model: (string) Env::get('GEMINI_MODEL', 'gemini-3.5-flash'),
            temperature: self::nullableFloat(Env::get('GEMINI_TEMPERATURE', null)),
            topP: self::nullableFloat(Env::get('GEMINI_TOP_P', null)),
            topK: self::nullableInt(Env::get('GEMINI_TOP_K', null)),
            maxOutputTokens: (int) Env::get('GEMINI_MAX_OUTPUT_TOKENS', 8192),
            mediaResolution: self::nullableString(Env::get('GEMINI_MEDIA_RESOLUTION', null)),
            thinkingBudget: self::nullableInt(Env::get('GEMINI_THINKING_BUDGET', null)),
            thinkingLevel: self::nullableString(Env::get('GEMINI_THINKING_LEVEL', null)),
            seed: self::nullableInt(Env::get('GEMINI_SEED', null)),
        );
    }

    /**
     * Genera el array `generationConfig` filtrado (sin nulls) para el payload de Gemini.
     *
     * @param  array<string, mixed> $overrides Sobrecargas opcionales del caller.
     * @param  bool $includeMediaResolution Incluye resolución solo para tareas multimodales.
     * @return array<string, mixed>
     */
    public function toGenerationConfig(array $overrides = [], bool $includeMediaResolution = false): array
    {
        $base = array_filter([
            'temperature' => $this->temperature ?? 0.0,
            'topP'        => $this->topP,
            'topK'        => $this->topK,
            'maxOutputTokens' => $this->maxOutputTokens,
            'seed'        => $this->seed,
        ], fn($value) => $value !== null);


        // Gemini 3 usa thinkingLevel; Gemini 2.5 usa thinkingBudget.
        $thinkingBudget = $overrides['thinkingBudget'] ?? $this->thinkingBudget;
        $thinkingLevel  = $overrides['thinkingLevel']  ?? $this->thinkingLevel;
        $cleanOverrides = array_diff_key($overrides, array_flip(['thinkingBudget', 'thinkingLevel']));

        $merged = array_merge($base, $cleanOverrides);

        if ($thinkingLevel !== null) {
            $merged['thinkingConfig'] = ['thinkingLevel' => $thinkingLevel];
        } elseif ($thinkingBudget !== null) {
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
            'mediaResolution' => $this->mediaResolution,
            'thinkingBudget'  => $this->thinkingBudget,
            'thinkingLevel'   => $this->thinkingLevel,
            'seed'            => $this->seed,
        ];
    }

    /**
     * Construye overrides de generación desde variables de entorno con prefijo de tarea.
     *
     * @return array<string,mixed>
     */
    public static function generationOverridesFromEnv(string $prefix, array $defaults = []): array
    {
        Env::load();

        $normalizedPrefix = strtoupper(trim($prefix));
        if ($normalizedPrefix === '' || preg_match('/[^A-Z0-9_]/', $normalizedPrefix)) {
            throw new RuntimeException('Prefijo de configuración Gemini inválido');
        }

        return array_filter([
            'maxOutputTokens' => self::intFromEnvWithDefault(
                "{$normalizedPrefix}_MAX_OUTPUT_TOKENS",
                $defaults['maxOutputTokens'] ?? null
            ),
            'thinkingBudget' => self::intFromEnvWithDefault(
                "{$normalizedPrefix}_THINKING_BUDGET",
                $defaults['thinkingBudget'] ?? null
            ),
            'thinkingLevel' => self::stringFromEnvWithDefault(
                "{$normalizedPrefix}_THINKING_LEVEL",
                $defaults['thinkingLevel'] ?? null
            ),
        ], static fn(mixed $value): bool => $value !== null);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function intFromEnvWithDefault(string $key, mixed $default): ?int
    {
        return self::nullableInt(Env::get($key, null)) ?? self::nullableInt($default);
    }

    private static function stringFromEnvWithDefault(string $key, mixed $default): ?string
    {
        return self::nullableString(Env::get($key, null)) ?? self::nullableString($default);
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
