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
    /** TTL en segundos de la clave audit:execution:heartbeat (SDD-BATCH-000, REQ-F1-06). */
    public const HEARTBEAT_TTL_SECONDS = 30;
    /** Edad máxima aceptada del heartbeat en milisegundos; fuente única para guarda, Lua y resume. */
    public const HEARTBEAT_MAX_AGE_MS = 30000;

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

    /**
     * Lector común de enteros decimales de configuración (W-F2-M9 de SDD-BATCH-001 y lectores de SDD-BATCH-002).
     *
     * Valor vacío (tras `trim`) usa el defecto; cualquier valor que no sean solo dígitos lanza `{key}_invalid`.
     * Sin límites (3 argumentos) no valida desbordamiento ni rango: el llamador aplica su piso con `max()`.
     * Con `$min` o `$max` exige además un entero representable (`FILTER_VALIDATE_INT`) dentro de
     * `[$min ?? 0, $max ?? PHP_INT_MAX]`.
     *
     * @param  string    $key      Nombre de la variable (prefijo del mensaje de error)
     * @param  string    $raw      Valor crudo leído del entorno
     * @param  int       $default  Valor si `$raw` está vacío
     * @param  int|null  $min      Mínimo inclusivo (activa la validación de rango)
     * @param  int|null  $max      Máximo inclusivo (activa la validación de rango)
     * @return int                 Valor configurado o defecto
     * @throws \InvalidArgumentException  Si el valor no es un entero decimal válido o está fuera del rango
     */
    private static function digitsOrDefault(string $key, string $raw, int $default, ?int $min = null, ?int $max = null): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
            throw new \InvalidArgumentException($key . '_invalid');
        }
        if (($min !== null || $max !== null)
            && (filter_var($raw, FILTER_VALIDATE_INT) === false
                || (int) $raw < ($min ?? 0) || (int) $raw > ($max ?? PHP_INT_MAX))) {
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

    /** Punto de lectura único del TTL del lock de generación (R4-F2-B2). */
    public static function batchLockTtlSeconds(): int
    {
        $raw = trim((string) Env::get('AUDIT_BATCH_LOCK_TTL_SECONDS', '1800'));
        return max(60, self::digitsOrDefault('AUDIT_BATCH_LOCK_TTL_SECONDS', $raw, 1800));
    }

    /** Edad sin progreso (segundos) tras la cual el reaper cierra un job (piso: max(lockTtl, 3600)). */
    public static function jobStaleAfterSeconds(): int
    {
        $floor = max(self::batchLockTtlSeconds(), 3600);
        $raw = trim((string) Env::get('AUDIT_JOB_STALE_AFTER_SECONDS', '21600'));
        return max($floor, self::digitsOrDefault('AUDIT_JOB_STALE_AFTER_SECONDS', $raw, 21600));
    }

    /** Timeout (segundos) de la consulta SQL de congelación de candidatas (piso 30). */
    public static function freezeQueryTimeoutSeconds(): int
    {
        $raw = trim((string) Env::get('AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS', '300'));
        return max(30, self::digitsOrDefault('AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS', $raw, 300));
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
