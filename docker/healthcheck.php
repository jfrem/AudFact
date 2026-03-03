<?php

/**
 * Docker Healthcheck Script
 *
 * Verifica conectividad real con SQL Server ejecutando SELECT 1.
 * Usado por Docker healthcheck en docker-compose.yml.
 *
 * Exit 0 = healthy (BD accesible)
 * Exit 1 = unhealthy (BD inaccesible o error de bootstrap)
 */

// Timeout interno para no bloquear Docker (max 5s de ejecución)
set_time_limit(5);

try {
    require_once '/var/www/html/vendor/autoload.php';
    \Core\Env::load();

    $pdo = \Core\Database::getConnection();
    $pdo->query('SELECT 1');

    echo "OK\n";
    exit(0);
} catch (\Throwable $e) {
    // No loguear aquí (evitar ruido en logs cada 30s)
    echo 'FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
