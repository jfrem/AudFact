#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Audit\Pipeline\AuditBatchMaintenanceCommands;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionInspector;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use Core\Env;
use Core\Logger;
use Core\RedisClient;

Env::load();
ini_set('memory_limit', '512M');
$command = $argv[1] ?? '';
// SDD-BATCH-001: segundo argumento solo para `migrate-batch-v2 --clear-claims` y `repair-jobs --force-stale[=jobId]`.
try {
    $arguments = AuditBatchMaintenanceCommands::parseArguments($argv);
} catch (InvalidArgumentException) {
    fwrite(STDERR, "Opcion desconocida\n");
    exit(1);
}

try {
    $redis = RedisClient::getInstance();
    $config = AuditExecutionConfig::fromEnv();
    $maintenance = new AuditExecutionMaintenance($redis, $config);
    if (in_array($command, ['pause', 'observe', 'resume', 'protocol'], true)) {
        if ($command === 'pause') {
            $redis->eval('redis.call("SET",KEYS[1],"1"); return redis.call("SET",KEYS[2],"1")', ['audit:admission:paused', 'audit:execution:paused']);
            fwrite(STDERR, AuditExecutionMaintenance::ADMISSION_PAUSED_CLI_NOTICE);
        } else {
            if ($command === 'protocol') {
                $redis->eval('return redis.call("SET",KEYS[1],"2","NX")', ['audit:execution:version']);
            }
            $config->assertProtocol($redis);
            new \App\Services\Audit\Pipeline\GeminiQuotaBudget($redis);
            if ($command === 'observe') {
                $redis->eval('redis.call("SET",KEYS[1],"1"); return redis.call("DEL",KEYS[2])', ['audit:admission:paused', 'audit:execution:paused']);
                fwrite(STDERR, AuditExecutionMaintenance::ADMISSION_PAUSED_CLI_NOTICE);
            } elseif ($command === 'resume') {
                $heartbeat = $redis->get('audit:execution:heartbeat');
                $now = (new \App\Services\Audit\Pipeline\FairAuditTaskQueue($redis, $config))->nowMs();
                if ($heartbeat === null || !ctype_digit($heartbeat) || (int) $heartbeat > $now || $now - (int) $heartbeat > AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS
                    || (new \App\Services\Audit\Pipeline\AuditAdmissionGuard($redis, $config))->atCapacity()) {
                    throw new RuntimeException('maintenance_unhealthy');
                }
                // DEC-10: el umbral del reaper se mide desde la última reanudación (la pausa no cuenta como falta de progreso).
                $redis->eval('redis.call("DEL",KEYS[1],KEYS[2]); return redis.call("SET",KEYS[3],ARGV[1])',
                    ['audit:admission:paused', 'audit:execution:paused', 'audit:execution:resumed_at_ms'], [(string) $now]);
            }
        }
        fwrite(STDOUT, json_encode(['command' => $command, 'execution_version' => AuditExecutionConfig::VERSION]) . PHP_EOL);
        exit(0);
    }
    if ($command === 'run') {
        $redis->eval('return redis.call("SET",KEYS[1],"2","NX")', ['audit:execution:version']);
        $config->assertProtocol($redis);
        $stop = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
            pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
        }
        Logger::info('Audit execution maintenance started', ['instance' => gethostname() . ':' . getmypid(),
            'execution_version' => 2, 'page_size' => AuditExecutionMaintenance::GC_PAGE_SIZE, 'max_queued_per_stage' => $config->maxQueued,
            'lease_ms' => AuditExecutionConfig::LEASE_MS, 'tick_budget_ms' => AuditExecutionMaintenance::TICK_BUDGET_MS,
            'gc_max_items' => AuditExecutionMaintenance::GC_MAX_ITEMS, 'gc_time_budget_ms' => AuditExecutionMaintenance::GC_TIME_BUDGET_MS,
            'gc_memory_soft_limit_bytes' => AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES,
            'heartbeat_max_age_ms' => AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS,
            'job_stale_after_seconds' => AuditExecutionConfig::jobStaleAfterSeconds(),
            'freeze_query_timeout_seconds' => AuditExecutionConfig::freezeQueryTimeoutSeconds()]);
        while (!$stop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            $maintenance->tick();
            usleep(100000);
        }
        exit(0);
    }
    if ($command === 'durability-check') {
        $settings = $redis->serverConfiguration(['appendonly', 'appendfsync', 'maxmemory-policy']);
        $info = $redis->serverInfo('persistence');
        if (($settings['appendonly'] ?? '') !== 'yes' || ($settings['appendfsync'] ?? '') !== 'always'
            || ($settings['maxmemory-policy'] ?? '') !== 'noeviction' || ($info['aof_last_write_status'] ?? '') !== 'ok'
            || (int) ($info['aof_rewrite_in_progress'] ?? 1) !== 0) {
            throw new RuntimeException('execution_durability_required');
        }
        fwrite(STDOUT, "Durabilidad verificada\n");
        exit(0);
    }
    $result = match ($command) {
        'outbox' => ['published' => $maintenance->flush()],
        'reconcile' => (new AuditExecutionInspector($redis))->reconcile(),
        'gc' => ['removed' => $maintenance->gc()],
        'idem-expire-legacy' => $maintenance->expireLegacyIdempotency(),
        'migrate-batch-v2', 'rollback-batch-v2', 'repair-jobs' => AuditBatchMaintenanceCommands::run($arguments, $redis, $maintenance),
        default => throw new InvalidArgumentException('unknown_maintenance_command'),
    };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    // Motivos operativos sin datos sensibles (DEC-11 / migración) se muestran para que el workflow los registre.
    $reason = in_array($error->getMessage(), ['rollback_requires_no_active_jobs', 'rollback_requires_no_active_reservations',
        'rollback_reservation_failed', 'migrate_reservation_failed'], true) ? $error->getMessage() : null;
    Logger::error('Audit execution maintenance failed', ['command' => $command, 'error_class' => get_class($error), 'reason' => $reason]);
    if ($reason !== null) {
        fwrite(STDERR, "Motivo: {$reason}\n");
    }
    fwrite(STDERR, "Mantenimiento fallido; revise el registro operacional y conserve la pausa.\n");
    exit(1);
}
