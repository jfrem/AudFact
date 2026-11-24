<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\RedisClient;

final readonly class AuditExecutionConfig
{
    public const VERSION = 2;
    public const LEASE_MS = 600000;
    public const DEADLINE_MS = 450000;
    public const MAX_PAIRS = 16;
    public const MAX_REQUEST_BYTES = 32768;
    public const MAX_INPUT_TOKENS = 8192;
    public const DEFAULT_JOB_SLOTS = 1;
    public const MAX_JOB_SLOTS = 32;

    /**
     * @param array<string,int> $stageJobSlots Turnos simultáneos máximos por job y etapa
     *        (clave: AuditTaskStage::value). Una etapa ausente conserva el contrato RR/cap1.
     */
    public function __construct(
        public int $version = self::VERSION,
        public int $maxQueued = 20000,
        public array $stageJobSlots = []
    ) {
        if ($version !== self::VERSION || $maxQueued < 100 || $maxQueued > 100000) {
            throw new \InvalidArgumentException('execution_configuration_invalid');
        }
        foreach ($stageJobSlots as $stage => $slots) {
            if (AuditTaskStage::tryFrom((string) $stage) === null || !is_int($slots)
                || $slots < 1 || $slots > self::MAX_JOB_SLOTS) {
                throw new \InvalidArgumentException('execution_job_slots_invalid');
            }
        }
    }

    public static function fromEnv(): self
    {
        $slots = [];
        foreach (AuditTaskStage::cases() as $stage) {
            $slots[$stage->value] = self::integer(
                self::jobSlotsVariable($stage),
                self::DEFAULT_JOB_SLOTS,
                1,
                self::MAX_JOB_SLOTS
            );
        }
        return new self(
            self::VERSION,
            self::integer('AUDIT_EXECUTION_MAX_QUEUED_PER_STAGE', 20000, 100, 100000),
            $slots
        );
    }

    /** Variable de entorno que fija los turnos por job de una etapa (p. ej. AUDIT_EXTRACTION_JOB_SLOTS). */
    public static function jobSlotsVariable(AuditTaskStage $stage): string
    {
        return 'AUDIT_' . strtoupper($stage->value) . '_JOB_SLOTS';
    }

    /** Máximo de tareas de un mismo job que pueden estar activas a la vez en la etapa. */
    public function jobSlots(AuditTaskStage $stage): int
    {
        return $this->stageJobSlots[$stage->value] ?? self::DEFAULT_JOB_SLOTS;
    }

    /**
     * Mensajes por lectura del descargador (AUDIT_DOCUMENT_STREAM_READ_COUNT, rango 1..50, defecto 5).
     *
     * @throws \InvalidArgumentException Si el valor no es un entero dentro del rango.
     */
    public static function documentStreamReadCount(): int
    {
        return self::digitsOrDefault(
            'AUDIT_DOCUMENT_STREAM_READ_COUNT',
            (string) Env::get('AUDIT_DOCUMENT_STREAM_READ_COUNT', ''),
            5,
            1,
            50
        );
    }

    /**
     * Porcentaje de la caché documental asignado al carril prioritario
     * (AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT, rango 1..99, defecto 25).
     *
     * @throws \InvalidArgumentException Si el valor no es un entero dentro del rango.
     */
    public static function documentCachePriorityShare(): int
    {
        return self::digitsOrDefault(
            'AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT',
            (string) Env::get('AUDIT_DOCUMENT_CACHE_PRIORITY_SHARE_PERCENT', ''),
            25,
            1,
            99
        );
    }

    /**
     * Espera máxima (ms) de la tarea más antigua del carril prioritario antes de rechazar
     * `POST /audit/single` con 429 (AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS, mínimo 1000, defecto 1200000).
     *
     * @throws \InvalidArgumentException Si el valor no es un entero decimal mayor o igual al mínimo.
     */
    public static function priorityAdmissionMaxWaitMs(): int
    {
        return self::digitsOrDefault(
            'AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS',
            (string) Env::get('AUDIT_PRIORITY_ADMISSION_MAX_WAIT_MS', ''),
            1200000,
            1000,
            PHP_INT_MAX
        );
    }

    /** Valor vacío usa el defecto; cualquier otro valor debe ser un entero decimal dentro del rango. */
    private static function digitsOrDefault(string $key, string $raw, int $default, int $min, int $max): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        if (preg_match('/^[0-9]+$/D', $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false
            || (int) $raw < $min || (int) $raw > $max) {
            throw new \InvalidArgumentException($key . '_invalid');
        }
        return (int) $raw;
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
        $marker = $redis->get('audit:execution:version') ?? (string) self::VERSION;
        if ($marker !== (string) self::VERSION) {
            throw new \RuntimeException('execution_protocol_mismatch');
        }
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
