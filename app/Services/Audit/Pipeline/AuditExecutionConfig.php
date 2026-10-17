<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;

final readonly class AuditExecutionConfig
{
    public const LEASE_MS = 600000;
    public const DEADLINE_MS = 450000;
    public const MAX_PAIRS = 16;
    public const MAX_REQUEST_BYTES = 32768;
    public const MAX_INPUT_TOKENS = 8192;

    public function __construct(public int $version = 1, public int $maxQueued = 20000)
    {
        if (!in_array($version, [1, 2], true) || $maxQueued < 100 || $maxQueued > 100000) {
            throw new \InvalidArgumentException('execution_configuration_invalid');
        }
    }

    public static function fromEnv(): self
    {
        return new self(self::integer('AUDIT_PIPELINE_EXECUTION_VERSION', 1, 1, 2),
            self::integer('AUDIT_EXECUTION_MAX_QUEUED_PER_STAGE', 20000, 100, 100000));
    }

    public static function integer(string $key, ?int $default, int $min, int $max = PHP_INT_MAX): int
    {
        $raw = (string) Env::get($key, $default === null ? '' : (string) $default);
        if (preg_match('/^[0-9]+$/D', $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false
            || (int) $raw < $min || (int) $raw > $max) {
            throw new \InvalidArgumentException($key . '_invalid');
        }
        return (int) $raw;
    }

    public function assertProtocol(RedisClient $redis): void
    {
        $marker = $redis->get('audit:execution:version') ?? '1';
        if ($marker !== (string) $this->version) {
            throw new \RuntimeException('execution_protocol_mismatch');
        }
        if ($this->version === 2) {
            if ((string) Env::get('REDIS_MODE', 'standalone') === 'cluster') {
                throw new \RuntimeException('execution_cluster_unsupported');
            }
            $config = $redis->serverConfiguration(['appendonly', 'appendfsync', 'maxmemory-policy']);
            $persistence = $redis->serverInfo('persistence');
            if (($config['appendonly'] ?? '') !== 'yes' || ($config['appendfsync'] ?? '') !== 'always'
                || ($config['maxmemory-policy'] ?? '') !== 'noeviction'
                || ($persistence['aof_last_write_status'] ?? '') !== 'ok') {
                throw new \RuntimeException('execution_durability_required');
            }
        }
    }
}
