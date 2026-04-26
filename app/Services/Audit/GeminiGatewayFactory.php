<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Core\Env;
use GuzzleHttp\Client;
use RuntimeException;

final class GeminiGatewayFactory
{
    public static function create(): GeminiGateway
    {
        Env::load();

        $apiKey = trim((string) Env::get('GEMINI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY es requerido para inicializar GeminiGateway');
        }

        $timeout = (int) Env::get('GEMINI_TIMEOUT', 300);
        $httpClient = new Client([
            'timeout' => $timeout > 0 ? $timeout : 300,
            'connect_timeout' => $timeout > 0 ? $timeout : 300,
        ]);

        return new GeminiGateway(
            $httpClient,
            $apiKey,
            (string) Env::get('GEMINI_MODEL', 'gemini-3-pro-preview'),
            self::nullableFloat(Env::get('GEMINI_TEMPERATURE', null)),
            self::nullableFloat(Env::get('GEMINI_TOP_P', null)),
            self::nullableInt(Env::get('GEMINI_TOP_K', null)),
            (int) Env::get('GEMINI_MAX_OUTPUT_TOKENS', 8192),
            (string) Env::get('GEMINI_RESPONSE_MIME', 'application/json'),
            self::nullableString(Env::get('GEMINI_MEDIA_RESOLUTION', null)),
            self::nullableInt(Env::get('GEMINI_THINKING_BUDGET', null)),
            self::nullableInt(Env::get('GEMINI_SEED', null))
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
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
