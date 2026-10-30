<?php

declare(strict_types=1);

namespace Core;

class AuthMiddleware
{
    public static function handle(): void
    {
        $expectedSecret = trim((string) Env::get('MCP_WEBHOOK_SECRET', ''));
        if ($expectedSecret === '') {
            Logger::error('AuthMiddleware: MCP_WEBHOOK_SECRET no configurado');
            Response::error('No autorizado', 401);
        }

        $providedSecret = self::extractToken();
        if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            Response::error('No autorizado', 401);
        }
    }

    private static function extractToken(): string
    {
        $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($apiKey !== '') {
            return $apiKey;
        }

        $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authHeader !== '' && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }
}

