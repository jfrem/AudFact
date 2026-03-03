<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Core\Env;
use Core\Router;
use Core\RateLimit;
use Core\Middleware;
use Core\Logger;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Cargar .env lo antes posible para que aplique al bootstrap
Env::load();

// Manejo global de excepciones
set_exception_handler(function ($e) {
    if ($e instanceof \Core\Exceptions\HttpResponseException) {
        \Core\Response::json($e->getData(), $e->getCode());
        return;
    }

    Logger::error('Unhandled exception: ' . $e->getMessage(), ['exception' => $e]);

    $message = Env::get('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage();
    \Core\Response::json(['success' => false, 'message' => $message], 500);
});

// CORS configurable por entorno
$appEnv = Env::get('APP_ENV', 'development');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($appEnv === 'development') {
    header('Access-Control-Allow-Origin: *');
} else {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', Env::get('ALLOWED_ORIGINS', '')))));
    header('Vary: Origin');

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
    } elseif ($origin !== '') {
        \Core\Response::error('Origen no permitido por CORS', 403);
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    RateLimit::check($clientIp);

    // Registrar middlewares
    Middleware::register('auth', 'Core\AuthMiddleware::handle');

    $router = new Router();
    require __DIR__ . '/../app/Routes/web.php';

    $router->dispatch();
} catch (\Core\Exceptions\HttpResponseException $e) {
    // Re-lanzar para que set_exception_handler la maneje con el código HTTP correcto
    throw $e;
} catch (\Exception $e) {
    Logger::error('Application bootstrap failed: ' . $e->getMessage());
    \Core\Response::error('Application error', 500);
}
