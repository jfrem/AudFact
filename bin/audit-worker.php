#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Env;
use Core\Logger;

Env::load();

// ─── Worker registry ─────────────────────────────────────────────────────────

$registry = [
    'orchestrator' => [
        'class' => \App\Services\Audit\Pipeline\DocumentAuditOrchestrator::class,
        'requiredEnv' => ['AUDIT_INTERNAL_API_BASE'],
    ],
    'extraction' => [
        'class' => \App\Services\Audit\Pipeline\DocumentExtractionWorker::class,
        'requiredEnv' => ['AUDIT_INTERNAL_API_BASE', 'GEMINI_API_KEY'],
    ],
    'downloader' => [
        'class' => \App\Services\Audit\Pipeline\AttachmentDownloadWorker::class,
        'requiredEnv' => ['AUDIT_INTERNAL_API_BASE'],
    ],
    'normalizer' => [
        'class' => \App\Services\Audit\Pipeline\DocumentNormalizer::class,
        'requiredEnv' => [],
    ],
    'policy' => [
        'class' => \App\Services\Audit\Pipeline\RulesEvaluationWorker::class,
        'requiredEnv' => ['GEMINI_API_KEY'],
    ],
    'persistence' => [
        'class' => \App\Services\Audit\Pipeline\AuditPersistenceWorker::class,
        'requiredEnv' => [],
    ],
    'batch' => [
        'class' => \App\Services\Audit\Pipeline\BatchRequestedWorker::class,
        'requiredEnv' => [],
    ],
];

// ─── Resolve worker from CLI argument ────────────────────────────────────────

$workerName = $argv[1] ?? null;

if ($workerName === null || !isset($registry[$workerName])) {
    $available = implode(', ', array_keys($registry));
    fwrite(STDERR, "Uso: php bin/audit-worker.php <worker> [--priority-only|--batch-only|--lane=priority|batch|all]\nWorkers disponibles: {$available}\n");
    exit(1);
}

use App\Services\Audit\Pipeline\AuditLane;

// ─── Resolve Lane ────────────────────────────────────────────────────────────

$laneEnum = AuditLane::ALL;
foreach ($argv as $arg) {
    if ($arg === '--priority-only') {
        $laneEnum = AuditLane::PRIORITY;
    } elseif ($arg === '--batch-only') {
        $laneEnum = AuditLane::BATCH;
    } elseif (str_starts_with($arg, '--lane=')) {
        $laneEnum = AuditLane::fromString(substr($arg, 7));
    }
}
if ($laneEnum->isAll()) {
    $laneEnum = AuditLane::fromString(Env::get('AUDIT_WORKER_LANE'));
}

$lane = $laneEnum->value;
$config = $registry[$workerName];
$label = "audit-{$workerName}-worker" . (!$laneEnum->isAll() ? "-{$lane}" : '');

// ─── Validate required env vars ──────────────────────────────────────────────

foreach ($config['requiredEnv'] as $envVar) {
    if ($envVar === 'GEMINI_API_KEY') {
        $laneSpecificKey = match ($laneEnum) {
            AuditLane::PRIORITY => Env::get('GEMINI_API_KEY_PRIORITY', ''),
            AuditLane::BATCH => Env::get('GEMINI_API_KEY_BATCH', ''),
            AuditLane::ALL => '',
        };

        if ($laneSpecificKey === '' && Env::get('GEMINI_API_KEY', '') === '') {
            fwrite(STDERR, "{$label}: GEMINI_API_KEY (o específica de carril) no configurada\n");
            exit(1);
        }
        continue;
    }

    if (Env::get($envVar, '') === '') {
        fwrite(STDERR, "{$label}: {$envVar} no configurada\n");
        exit(1);
    }
}

// ─── Signal handling ─────────────────────────────────────────────────────────

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

$consumer = new $config['class'](lane: $laneEnum);

$stop = static function (int $signal) use ($consumer, $label): void {
    Logger::info("{$label}: señal recibida, deteniendo", ['signal' => $signal]);
    $consumer->requestStop();
};

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

// ─── Run ─────────────────────────────────────────────────────────────────────

try {
    Logger::info("{$label}: iniciando");
    $processed = $consumer->run();
    Logger::info("{$label}: terminado", ['processed' => $processed]);
    exit(0);
} catch (\Core\RedisUnavailableException $e) {
    Logger::error("{$label}: Redis no disponible", ['error' => $e->getMessage()]);
    exit(1);
} catch (\Throwable $e) {
    Logger::error("{$label}: error fatal", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
