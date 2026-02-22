<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Core\Env;
use Core\Router;
use Core\RateLimit;
use Core\Middleware;
use Core\Logger;

header('Content-Type: application/json; charset=utf-8');

// Cargar .env lo antes posible para que aplique al bootstrap
Env::load();

// CORS configurable por entorno
if (Env::get('APP_ENV') === 'development') {
    header('Access-Control-Allow-Origin: *');
} else {
    $allowedOrigins = explode(',', Env::get('ALLOWED_ORIGINS', ''));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: {$origin}");
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Manejo global de excepciones
set_exception_handler(function ($e) {
    Logger::error('Unhandled exception: ' . $e->getMessage(), ['exception' => $e]);

    if (Env::get('APP_ENV') === 'production') {
        \Core\Response::error('Internal server error', 500);
    } else {
        \Core\Response::error($e->getMessage(), 500);
    }
});

try {
    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    try {
        RateLimit::check($clientIp);
    } catch (\Exception $e) {
        // En producción, fallar silenciosamente es mejor que exponer errores
        if (Env::get('APP_ENV') === 'development') {
            throw $e;
        }
        // En producción, permitir la solicitud si el rate limiting falla
        Logger::error('Rate limiting failed: ' . $e->getMessage());
    }

    // Registrar middlewares
    Middleware::register('auth', 'Core\AuthMiddleware::handle');

    $router = new Router();
    require __DIR__ . '/../app/Routes/web.php';

    $router->dispatch();
} catch (\Exception $e) {
    Logger::error('Application bootstrap failed: ' . $e->getMessage());
    \Core\Response::error('Application error', 500);
}
