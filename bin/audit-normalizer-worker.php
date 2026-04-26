#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Audit\Events\DocumentNormalizationWorker;
use Core\Env;
use Core\Logger;

Env::load();

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

$consumer = new DocumentNormalizationWorker();

$stop = static function (int $signal) use ($consumer): void {
    Logger::info('audit-normalizer-worker: señal recibida, deteniendo', ['signal' => $signal]);
    $consumer->requestStop();
};

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

try {
    Logger::info('audit-normalizer-worker: iniciando');
    $processed = $consumer->run();
    Logger::info('audit-normalizer-worker: terminado', ['processed' => $processed]);
    exit(0);
} catch (\Core\RedisUnavailableException $e) {
    Logger::error('audit-normalizer-worker: Redis no disponible', ['error' => $e->getMessage()]);
    exit(1);
} catch (\Throwable $e) {
    Logger::error('audit-normalizer-worker: error fatal', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
