#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionInspector;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\AuditExecutionMigration;
use Core\Env;
use Core\Logger;
use Core\RedisClient;

Env::load();
ini_set('memory_limit', '512M');
$command = $argv[1] ?? '';
// Analizar únicamente opciones declaradas después del subcomando.
$options = [];
for ($i = 2; $i < $argc; $i++) {
    if ($argv[$i] === '--run-id' && isset($argv[$i + 1])) {
        $options['run-id'] = $argv[++$i];
    } elseif (str_starts_with($argv[$i], '--run-id=')) {
        $options['run-id'] = substr($argv[$i], 9);
    } elseif (in_array($argv[$i], ['--dry-run', '--apply'], true)) {
        $options[substr($argv[$i], 2)] = true;
    } else {
        fwrite(STDERR, "Opcion desconocida\n");
        exit(1);
    }
}

try {
    $redis = RedisClient::getInstance();
    $config = AuditExecutionConfig::fromEnv();
    $maintenance = new AuditExecutionMaintenance($redis, $config);
    $migration = new AuditExecutionMigration($redis, $config);
    if (in_array($command, ['pause', 'observe', 'resume', 'protocol'], true)) {
        if ($command === 'pause') {
            $redis->eval('redis.call("SET",KEYS[1],"1"); return redis.call("SET",KEYS[2],"1")', ['audit:admission:paused', 'audit:execution:paused']);
        } else {
            $config->assertProtocol($redis);
            if ($config->version === 2) {
                new \App\Services\Audit\Pipeline\GeminiQuotaBudget($redis);
            }
            if ($command === 'observe') {
                $redis->eval('redis.call("SET",KEYS[1],"1"); return redis.call("DEL",KEYS[2])', ['audit:admission:paused', 'audit:execution:paused']);
            } elseif ($command === 'resume') {
                if ($config->version === 2) {
                    $heartbeat = $redis->get('audit:execution:heartbeat');
                    $now = (new \App\Services\Audit\Pipeline\FairAuditTaskQueue($redis, $config))->nowMs();
                    if ($heartbeat === null || !ctype_digit($heartbeat) || (int) $heartbeat > $now || $now - (int) $heartbeat > 10000
                        || (new \App\Services\Audit\Pipeline\AuditAdmissionGuard($redis, $config))->atCapacity()) {
                        throw new RuntimeException('maintenance_unhealthy');
                    }
                }
                $redis->eval('return redis.call("DEL",KEYS[1],KEYS[2])', ['audit:admission:paused', 'audit:execution:paused']);
            }
        }
        fwrite(STDOUT, json_encode(['command' => $command, 'execution_version' => $config->version]) . PHP_EOL);
        exit(0);
    }
    if ($command === 'run') {
        $config->assertProtocol($redis);
        if ($config->version !== 2) {
            throw new RuntimeException('maintenance_requires_execution_v2');
        }
        $stop = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
            pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
        }
        Logger::info('Audit execution maintenance started', ['instance' => gethostname() . ':' . getmypid(),
            'execution_version' => 2, 'page_size' => 100, 'max_queued_per_stage' => $config->maxQueued,
            'lease_ms' => AuditExecutionConfig::LEASE_MS]);
        while (!$stop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            $maintenance->tick();
            usleep(100000);
        }
        exit(0);
    }
    if (in_array($command, ['migrate', 'rollback'], true)
        && (isset($options['apply']) === isset($options['dry-run']))) {
        throw new InvalidArgumentException('choose_apply_or_dry_run');
    }
    if ($command === 'durability-check' || ($command === 'migrate' && isset($options['apply']))) {
        // Validar durabilidad antes de escribir el marcador definitivo.
        $settings = $redis->serverConfiguration(['appendonly', 'appendfsync', 'maxmemory-policy']);
        $info = $redis->serverInfo('persistence');
        if (($settings['appendonly'] ?? '') !== 'yes' || ($settings['appendfsync'] ?? '') !== 'always'
            || ($settings['maxmemory-policy'] ?? '') !== 'noeviction' || ($info['aof_last_write_status'] ?? '') !== 'ok'
            || (int) ($info['aof_rewrite_in_progress'] ?? 1) !== 0) {
            throw new RuntimeException('migration_durability_required');
        }
        if ($command === 'durability-check') {
            fwrite(STDOUT, "Durabilidad verificada\n");
            exit(0);
        }
    }
    $result = match ($command) {
        'outbox' => ['published' => $maintenance->flush()],
        'reconcile' => (new AuditExecutionInspector($redis))->reconcile(),
        'gc' => ['removed' => $maintenance->gc()],
        'migrate' => $migration->migrate((string) ($options['run-id'] ?? ''), isset($options['apply'])),
        'rollback' => $migration->rollback((string) ($options['run-id'] ?? ''), isset($options['apply'])),
        'verify' => $migration->verify((string) ($options['run-id'] ?? '')),
        'accept' => $migration->accept((string) ($options['run-id'] ?? '')),
        default => throw new InvalidArgumentException('unknown_maintenance_command'),
    };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    Logger::error('Audit execution maintenance failed', ['command' => $command, 'error_class' => get_class($error)]);
    fwrite(STDERR, "Mantenimiento fallido; revise el registro operacional y conserve la pausa.\n");
    exit(1);
}
