<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use Core\RedisClient;

/**
 * Intake y reparación independientes de los procesos que esperan HTTP.
 *
 * SDD-BATCH-000 (hotfix de mantenimiento): tick() acotado en tiempo (TICK_BUDGET_MS), GC incremental y
 * reanudable (audit:execution:gc:cursors), lectura compacta de estados (sin cachear JSON completos),
 * intake por lotes con prioridad `.priority` antes que `.batch` y heartbeat unificado con meta.
 */
final class AuditExecutionMaintenance
{
    public const GC_PAGE_SIZE = 100;
    public const TICK_BUDGET_MS = 2500;
    public const GC_INTERVAL_MS = 5000;
    public const GC_MAX_ITEMS = 500;
    public const GC_TIME_BUDGET_MS = 500;
    public const GC_MIN_BUDGET_MS = 100;
    public const GC_MEMORY_SOFT_LIMIT_BYTES = 268435456; // 256 MiB
    public const GC_STATUS_CACHE_MAX = 4096;   // tope defensivo: con GC_MAX_ITEMS = 500 la caché local nunca lo alcanza (DEC-F1-01)
    public const OUTBOX_MAX_BATCHES_PER_TICK = 20;
    public const INTAKE_READ_COUNT = 200;
    public const INTAKE_ACK_CHUNK = 1000;
    public const LEGACY_IDEM_TTL_SECONDS = 14400;
    public const GC_PENDING_MAX = 1000;
    public const GC_CURSORS_KEY = 'audit:execution:gc:cursors';
    public const HEARTBEAT_META_KEY = 'audit:execution:heartbeat:meta';
    public const ADMISSION_PAUSED_WARN_INTERVAL_MS = 300000;
    public const ADMISSION_PAUSED_CLI_NOTICE = "AVISO: audit:admission:paused=1 sin TTL; worker-batch no leera audit.batch.inbox ni la API admitira hasta ejecutar resume\n";
    private const HEARTBEAT_META_TTL_SECONDS = 60;
    /** SDD-BATCH-001: `job:{id}:state` v2 mide < 2048 B; uno mayor es un job v1 residual que gc() no decodifica. */
    public const JOB_STATE_MAX_BYTES = 2048;
    /** Patrón legado recorrido una vez por ciclo dentro del índice 5 mientras `legacy_done = '0'` (W-F2-I3). */
    public const LEGACY_RESERVATION_PATTERN = 'audit:reservation:disid:*';
    private const TERMINAL_JOB_STATUSES = ['completed', 'completed_with_errors', 'failed'];
    private const GC_PATTERNS = [
        ['name' => 'tasks', 'pattern' => 'audit:task:v2:*'],
        ['name' => 'plans', 'pattern' => 'audit:semantic:v2:*'],
        ['name' => 'audits', 'pattern' => 'audit:*:state'],
        ['name' => 'gemini_attempts', 'pattern' => 'gemini:attempt:v2:*'],
        ['name' => 'jobs', 'pattern' => 'job:*:state'],
        ['name' => 'reservations', 'pattern' => 'audit:reservation:disdetnro:*'],
        ['name' => 'idempotency', 'pattern' => 'batch:idem:*'],
    ];

    private const GC_DELETE_TASK_LUA = <<<'LUA'
        local raw = redis.call('HGET', KEYS[1], 'record')
        if raw ~= ARGV[1] then
            return 0
        end
        local r = cjson.decode(raw)
        if redis.call('SREM', KEYS[2], r.task_id) == 1 then
            redis.call('HINCRBY', KEYS[3], r.stage .. ':' .. r.lane .. ':' .. r.state, -1)
        end
        redis.call('SREM', KEYS[4], 'task:' .. r.task_id)
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    private const GC_DELETE_SEMANTIC_PLAN_LUA = <<<'LUA'
        local raw = redis.call('HGET', KEYS[1], 'record')
        if not raw then
            return 0
        end
        local p = cjson.decode(raw)
        if p.revision ~= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('SREM', KEYS[2], KEYS[1])
        redis.call('HINCRBY', KEYS[3], p.state, -1)
        redis.call('SREM', KEYS[4], 'plan:' .. p.plan_id)
        return redis.call('DEL', KEYS[1])
    LUA;

    private const GC_DELETE_AUDIT_STATE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw or redis.sha1hex(raw) ~= ARGV[1] then
            return 0
        end
        for _, member in ipairs(redis.call('SMEMBERS', KEYS[2])) do
            if string.sub(member, 1, 5) ~= 'plan:' then
                return 0
            end
        end
        return redis.call('DEL', KEYS[1], KEYS[2])
    LUA;

    private const GC_DELETE_GEMINI_ATTEMPT_LUA = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        local r = cjson.decode(ARGV[1])
        if r.state == 'unknown' then
            redis.call('HINCRBY', KEYS[3], 'unknown_attempts', -1)
            redis.call('HINCRBY', KEYS[3], (r.stage or 'extraction') .. '_unknown_attempts', -1)
        end
        redis.call('SREM', KEYS[2], r.attempt_id)
        return redis.call('DEL', KEYS[1])
    LUA;

    /**
     * Borrado atómico de un job v2 terminal (SDD-BATCH-001 DEC-06): solo si `sha1(job:{id}:state)` sigue siendo el
     * persistido al iniciar el HSCAN (`hscan_sha1`, W-F2-M2). Las referencias ya se comprobaron por rebanadas.
     * KEYS: 1=job:{id}:state 2=jobs:index 3=job:{id}:audits 4=job:{id}:by_dispensa 5=job:{id}:candidates
     *       6=jobs:active:nit:{nit}. ARGV: 1=sha1 2=jobId.
     */
    private const GC_DELETE_JOB_STATE_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw or redis.sha1hex(raw) ~= ARGV[1] then
            return 0
        end
        redis.call('ZREM', KEYS[2], ARGV[2])
        redis.call('HDEL', KEYS[6], ARGV[2])
        redis.call('DEL', KEYS[3], KEYS[4], KEYS[5])
        return redis.call('DEL', KEYS[1])
    LUA;

    /**
     * Una rebanada `HSCAN COUNT 500` de `job:{id}:audits`: devuelve `{cursor, 1}` si alguna auditoría tiene una
     * referencia activa (miembro sin prefijo `plan:`), `{cursor, 0}` si no. El prefijo se deriva de KEYS[1].
     */
    private const GC_JOB_REFS_SLICE_LUA = <<<'LUA'
        local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:audits$') or ''
        local scan = redis.call('HSCAN', KEYS[1], ARGV[1], 'COUNT', 500)
        local fields = scan[2]
        for i = 1, #fields, 2 do
            for _, member in ipairs(redis.call('SMEMBERS', prefix .. 'audit:execution:refs:' .. fields[i])) do
                if string.sub(member, 1, 5) ~= 'plan:' then
                    return {scan[1], 1}
                end
            end
        end
        return {scan[1], 0}
    LUA;

    /** Status de un job sin transferir su JSON a PHP; `false` si no existe. */
    private const GC_JOB_STATUS_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return false end
        local ok, job = pcall(cjson.decode, raw)
        if not ok or type(job) ~= 'table' or job.status == nil or job.status == cjson.null then return '' end
        return tostring(job.status)
    LUA;

    /**
     * X-F2-B1: libera la reserva de una auditoría no registrada en un job terminal (o inexistente) y lleva su
     * estado vivo del mismo job a `failed` (`orphaned_job_terminal`), conservando `documents` y sin EX.
     * KEYS: 1=reserva 2=audit:{id}:state. ARGV: 1=reserva leída 2=nowIso.
     */
    private const GC_RELEASE_ORPHAN_RESERVATION_LUA = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return 0
        end
        local raw = redis.call('GET', KEYS[2])
        if raw then
            local okRes, res = pcall(cjson.decode, ARGV[1])
            local okAudit, audit = pcall(cjson.decode, raw)
            if okRes and okAudit and type(res) == 'table' and type(audit) == 'table' then
                local st = tostring(audit.status or '')
                local terminal = st == 'completed' or st == 'manual_review' or st == 'error' or st == 'failed'
                if not terminal and tostring(audit.job_id or '') == tostring(res.job_id or '') then
                    audit.status = 'failed'
                    audit.failed_stage = 'orphaned_job_terminal'
                    audit.completed_at = ARGV[2]
                    audit.updated_at = ARGV[2]
                    redis.call('SET', KEYS[2], cjson.encode(audit))
                end
            end
        end
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    private const GC_IDEMPOTENCY_LUA = <<<'LUA'
        if redis.call('EXISTS', KEYS[2]) == 1 then
            return redis.call('EXPIRE', KEYS[1], ARGV[1], 'GT')
        end
        if redis.call('TTL', KEYS[1]) == -1 then
            return redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return 0
    LUA;

    private const LEGACY_IDEMPOTENCY_LUA = <<<'LUA'
        local n = 0
        for i = 1, #KEYS do
            if redis.call('TTL', KEYS[i]) == -1 then
                redis.call('EXPIRE', KEYS[i], tonumber(ARGV[1]))
                n = n + 1
            end
        end
        return n
    LUA;

    private FairAuditTaskQueue $queue;
    private AuditTaskOutbox $outbox;
    private array $reclaimCursors = [];
    private bool $groupsReady = false;
    private ?GeminiQuotaBudget $quota = null;
    private int $intakeOffset = 0;
    private bool $gcBudgetStopped = false;
    private int $lastAdmissionPausedWarnMs = 0;
    /** Jobs cerrados por el reaper en el último gc(); tick() lo reinicia y lo publica como `jobs_reaped`. */
    private int $lastGcReapedJobs = 0;
    private ?BatchJobStore $batchJobStore;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly AuditExecutionConfig $config,
        /** Costura de prueba para la memoria del proceso (la clase es final). */
        private readonly ?\Closure $memoryProbe = null,
        /** Costura de prueba para el reaper (`reapStaleJob`); por defecto usa la misma conexión Redis. */
        ?BatchJobStore $batchJobStore = null
    ) {
        $this->queue = new FairAuditTaskQueue($redis, $config);
        $this->outbox = new AuditTaskOutbox($redis, new AuditPersistenceQueue($redis));
        $this->batchJobStore = $batchJobStore;
    }

    public static function stages(): array
    {
        return [
            AuditTaskStage::ORCHESTRATION->value => [
                'group'   => AuditEventPublisher::GROUP_ORCHESTRATOR,
                'streams' => [AuditEventPublisher::STREAM_INBOX_PRIORITY, AuditEventPublisher::STREAM_INBOX_BATCH],
            ],
            AuditTaskStage::EXTRACTION->value => [
                'group'   => AuditEventPublisher::GROUP_EXTRACTORS,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH],
            ],
            AuditTaskStage::POLICY->value => [
                'group'   => AuditEventPublisher::GROUP_POLICY,
                'streams' => [AuditEventPublisher::STREAM_DOCUMENTS_PRIORITY, AuditEventPublisher::STREAM_DOCUMENTS_BATCH],
            ],
            AuditTaskStage::SEMANTIC->value => [
                'group'   => AuditEventPublisher::GROUP_SEMANTIC,
                'streams' => [AuditEventPublisher::STREAM_SEMANTIC_PRIORITY, AuditEventPublisher::STREAM_SEMANTIC_BATCH],
            ],
        ];
    }

    /**
     * Un tick acotado a TICK_BUDGET_MS entre unidades: outbox en ráfaga, intake por lotes, GC incremental
     * y heartbeat con meta. El deadline se comprueba entre unidades, nunca dentro de una unidad atómica.
     */
    public function tick(): int
    {
        if ($this->config->version !== AuditExecutionConfig::VERSION || ($this->redis->get('audit:execution:version') ?? '2') !== '2') {
            throw new \RuntimeException('execution_protocol_mismatch');
        }
        $started = hrtime(true);
        $deadline = $started + self::TICK_BUDGET_MS * 1_000_000;
        $this->ensureGroups();
        ($this->quota ??= new GeminiQuotaBudget($this->redis))->recoverExpired();

        $flushed = 0;
        for ($i = 0; $i < self::OUTBOX_MAX_BATCHES_PER_TICK; $i++) {
            if ($i > 0 && hrtime(true) >= $deadline) {
                break;
            }
            $published = $this->outbox->flush(100);
            $flushed += $published;
            if ($published === 0) {
                break;
            }
        }

        $admitted = 0;
        $acked = 0;
        $executionPaused = $this->redis->get('audit:execution:paused') === '1';
        if (!$executionPaused) {
            [$admitted, $acked] = $this->intakeAll($deadline);
        }

        $now = $this->queue->nowMs();
        $atCapacity = (new AuditAdmissionGuard($this->redis, $this->config))->orchestrationAtCapacity();
        $removed = 0;
        $this->gcBudgetStopped = false;
        $this->lastGcReapedJobs = 0;   // un tick sin gc() publica jobs_reaped = 0 (W-F1-M7)
        $state = $this->loadGcState();
        if ($atCapacity || $now - $state['last_gc_ms'] >= self::GC_INTERVAL_MS) {
            $remainingMs = intdiv(max(0, $deadline - hrtime(true)), 1_000_000);
            $removed = $this->gc(max(self::GC_MIN_BUDGET_MS, min(self::GC_TIME_BUDGET_MS, $remainingMs)), $now, $state);
            (new AuditEventPublisher($this->redis))->trimWorkStreams();
        }

        // REQ-F1-13 (P-F1-01): la pausa de admisión no tiene TTL y bloquea la intake de worker-batch sin otro aviso.
        $admissionPaused = $this->redis->get('audit:admission:paused') === '1';
        if ($admissionPaused && ($this->lastAdmissionPausedWarnMs === 0
                || $now - $this->lastAdmissionPausedWarnMs >= self::ADMISSION_PAUSED_WARN_INTERVAL_MS)) {
            $this->lastAdmissionPausedWarnMs = $now;
            Logger::warning('Audit admission paused', [
                'execution_paused' => $executionPaused ? 1 : 0,
                'action' => 'php bin/audit-execution-maintenance.php resume',
            ]);
        }
        if (!$admissionPaused) {
            $this->lastAdmissionPausedWarnMs = 0;
        }

        $this->writeHeartbeat(
            intdiv(hrtime(true) - $started, 1_000_000),
            $flushed,
            $admitted,
            $acked,
            $removed,
            ['jobs_reaped' => $this->lastGcReapedJobs],
            $admissionPaused,
            $executionPaused
        );

        return $flushed + $admitted;
    }

    public function flush(): int
    {
        $count = 0;
        do {
            $published = $this->outbox->flush();
            $count += $published;
        } while ($published > 0);
        if ($this->redis->eval('return redis.call("ZCARD",KEYS[1])', [AuditTaskCommitter::OUTBOX]) !== 0) {
            throw new \RuntimeException('outbox_publication_pending');
        }
        return $count;
    }

    /**
     * Recolección incremental y reanudable. Cada llamada respeta el presupuesto de tiempo, de ítems y de memoria;
     * el estado (patrón, cursores, claves pendientes) se persiste en audit:execution:gc:cursors.
     *
     * @param array|null $state Estado ya cargado por tick() (evita un HGETALL extra)
     */
    public function gc(int $budgetMs = self::GC_TIME_BUDGET_MS, ?int $nowMs = null, ?array $state = null): int
    {
        $this->gcBudgetStopped = false;
        $retention = max(
            AuditExecutionConfig::integer('AUDIT_STATE_TTL', 86400, 1),
            AuditExecutionConfig::integer('AUDIT_JOB_TTL', 86400, 1),
            AuditExecutionConfig::integer('AUDIT_PERSISTENCE_QUEUE_TTL', 86400, 1)
        );
        $now = $nowMs ?? $this->queue->nowMs();
        $stateStore = new AuditStateStore($this->redis);
        $statuses = [];
        $statusOf = static function (string $auditId) use ($stateStore, &$statuses): ?string {
            if (!array_key_exists($auditId, $statuses)) {
                if (count($statuses) >= self::GC_STATUS_CACHE_MAX) {
                    $statuses = [];
                }
                $statuses[$auditId] = $stateStore->getAuditStatus($auditId);
            }
            return $statuses[$auditId];
        };

        $jobStatuses = [];
        $jobStatusOf = function (string $jobId) use (&$jobStatuses): ?string {
            if (!array_key_exists($jobId, $jobStatuses)) {
                if (count($jobStatuses) >= self::GC_STATUS_CACHE_MAX) {
                    $jobStatuses = [];
                }
                $result = $this->redis->eval(self::GC_JOB_STATUS_LUA, [BatchJobStore::jobKey($jobId)]);
                $jobStatuses[$jobId] = is_string($result) ? $result : null;   // null = job inexistente
            }
            return $jobStatuses[$jobId];
        };

        $state ??= $this->loadGcState();
        if ($state['cycle_started_at_ms'] <= 0) {
            $state['cycle_started_at_ms'] = $now;
        }
        $deadline = hrtime(true) + $budgetMs * 1_000_000;
        $items = 0;
        $count = 0;
        $seen = [];
        $visited = 0;
        $keys = [];
        $idx = $state['pattern_index'];
        $scanned = false;
        $patternAdvanced = true;
        $p = null;
        $enteredPatternLoop = false;   // R5-F2-B2
        // DEC-10: una pausa (corrección hacia adelante) no consume el umbral ni dispara el reaper automático.
        $resumedAt = $this->redis->get('audit:execution:resumed_at_ms');
        $reaper = [
            'paused' => $this->redis->get('audit:execution:paused') === '1',
            'resumed_at_ms' => is_string($resumedAt) && ctype_digit($resumedAt) ? (int) $resumedAt : 0,
            'threshold_ms' => AuditExecutionConfig::jobStaleAfterSeconds() * 1000,
        ];
        try {
            // W-F2-M7: un reap_job_id pendiente se reanuda antes del bucle de patrones (salvo pausa).
            if ($state['reap_job_id'] !== '' && !$reaper['paused'] && $this->resumePendingReap($state, $now, $reaper, $items, $deadline) === null) {
                return $count;   // corte pre-bucle: finally no toca pending_pattern/pending_keys
            }
            while ($visited < count(self::GC_PATTERNS)) {
                $enteredPatternLoop = true;
                $idx = $state['pattern_index'];
                $p = self::GC_PATTERNS[$idx];
                $patternAdvanced = false;
                // Las pendientes provienen de un SCAN ya hecho cuyo cursor está persistido: si ese cursor es '0',
                // al agotarlas el patrón está completo y NO se reinicia el barrido (DEC-F1-02).
                $keys = $state['pending_pattern'] === $idx ? $state['pending_keys'] : [];
                $scanned = $keys !== [];
                while (true) {
                    // W-F2-I3 / X-F2-B3: mientras legacy_done = '0', el índice 5 hace primero UNA pasada sobre disid:*.
                    $inLegacy = $idx === 5 && $state['legacy_done'] !== '1' && $state['legacy_pass_done'] !== '1';
                    if ($keys === [] && $inLegacy) {
                        if ($scanned && $state['cursors']['reservations_legacy'] === '0') {
                            // R5-F2-I1: solo con la página final drenada ($keys === []).
                            $state['legacy_pass_done'] = '1';
                            if ($state['legacy_saw_keys'] === '0') {
                                $state['legacy_done'] = '1';
                            }
                            $scanned = false;   // sin break: continúa en disdetnro:* dentro del mismo índice
                            continue;
                        }
                        if ($this->gcBudgetExceeded($items, $deadline)) {
                            return $count;
                        }
                        [$cursor, $page] = $this->redis->scanKeys(self::LEGACY_RESERVATION_PATTERN, $state['cursors']['reservations_legacy'], self::GC_PAGE_SIZE);
                        $state['cursors']['reservations_legacy'] = $cursor;
                        if ($page !== []) {
                            $state['legacy_saw_keys'] = '1';
                        }
                        $scanned = true;
                        $keys = array_values(array_filter($page, static fn(string $k): bool => !isset($seen[$k])));
                        if (count($keys) > self::GC_PENDING_MAX) {
                            Logger::warning('gc_scan_page_truncated', ['pattern' => 'reservations_legacy', 'keys' => count($keys)]);
                            $keys = array_slice($keys, 0, self::GC_PENDING_MAX);
                        }
                        continue;
                    }
                    if ($keys === []) {
                        if ($scanned && $state['cursors'][$p['name']] === '0') {
                            break;
                        }
                        if ($this->gcBudgetExceeded($items, $deadline)) {
                            return $count;
                        }
                        [$cursor, $page] = $this->redis->scanKeys($p['pattern'], $state['cursors'][$p['name']], self::GC_PAGE_SIZE);
                        $state['cursors'][$p['name']] = $cursor;
                        $scanned = true;
                        $keys = array_values(array_filter($page, static fn(string $k): bool => !isset($seen[$k])));
                        if (count($keys) > self::GC_PENDING_MAX) {   // SCAN COUNT es orientativo
                            Logger::warning('gc_scan_page_truncated', ['pattern' => $p['name'], 'keys' => count($keys)]);
                            $keys = array_slice($keys, 0, self::GC_PENDING_MAX); // el resto se revisita en el siguiente barrido
                        }
                        continue;
                    }
                    if ($this->gcBudgetExceeded($items, $deadline)) {
                        return $count;
                    }
                    $key = array_shift($keys);
                    $seen[$key] = true;
                    $items++;
                    $collected = $p['name'] === 'jobs'
                        ? $this->collectJob($key, $now, $retention, $state, $items, $deadline, $reaper)
                        : $this->collectKey($p['name'], $key, $now, $retention, $statusOf, $stateStore, $jobStatusOf);
                    if ($collected === null) {             // rama jobs (memoria, HSCAN o reaper cortados): reencolar al frente
                        array_unshift($keys, $key);
                        unset($seen[$key]);
                        $items--;
                        $this->gcBudgetStopped = true;
                        return $count;
                    }
                    $count += $collected;
                }
                if ($idx === 5 && $state['legacy_done'] !== '1') {
                    // Índice 5 completo: la pasada legada del ciclo siguiente empieza de cero.
                    $state['legacy_pass_done'] = '0';
                    $state['legacy_saw_keys'] = '0';
                }
                $state['pattern_index'] = ($idx + 1) % count(self::GC_PATTERNS);
                if ($state['pattern_index'] === 0) {
                    $state['cycle_started_at_ms'] = $now;
                }
                $patternAdvanced = true;
                $visited++;
            }
            return $count;
        } finally {
            if ($enteredPatternLoop) {   // R5-F2-B2: un corte pre-bucle conserva pending_pattern/pending_keys
                $legacyBlocks = $idx === 5 && $state['legacy_done'] !== '1' && $state['legacy_pass_done'] !== '1';
                $jobsBlocks = $idx === 4 && ($state['hscan_job_id'] !== '' || $state['reap_job_id'] !== '');
                if ($legacyBlocks && !$patternAdvanced && $scanned && $keys === []
                    && $state['cursors']['reservations_legacy'] === '0') {
                    // R5-F2-I1: excepción en la última clave de la página final de disid:*; se cierra la pasada
                    // legada sin avanzar pattern_index para continuar en disdetnro:* en el próximo tick.
                    $state['legacy_pass_done'] = '1';
                    if ($state['legacy_saw_keys'] === '0') {
                        $state['legacy_done'] = '1';
                    }
                } elseif (!$patternAdvanced && $scanned && $keys === [] && $p !== null && $state['cursors'][$p['name']] === '0'
                    && !$legacyBlocks && !$jobsBlocks) {
                    // Excepción en la última clave de la última página: el patrón quedó agotado aunque no se llegó al
                    // `break`; avanzar aquí evita reiniciar el SCAN del mismo patrón en bucle (V-F1-B1, DEC-F1-04).
                    if ($idx === 5 && $state['legacy_done'] !== '1') {
                        $state['legacy_pass_done'] = '0';   // R4-F2-I2
                        $state['legacy_saw_keys'] = '0';
                    }
                    $state['pattern_index'] = ($idx + 1) % count(self::GC_PATTERNS);
                    if ($state['pattern_index'] === 0) {
                        $state['cycle_started_at_ms'] = $now;
                    }
                }
                // Corte por presupuesto, excepción o fin: las claves aún no extraídas de la página quedan pendientes.
                $state['pending_pattern'] = $keys === [] ? null : $idx;
                $state['pending_keys'] = $keys;
            }
            $state['last_gc_ms'] = $now;
            $this->saveGcState($state);
            if ($this->gcBudgetStopped) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Expira las claves legadas `batch:idem:*` sin TTL, en páginas de 1000 claves. Repetible: la segunda
     * ejecución devuelve expired = 0.
     *
     * @return array{scanned:int,expired:int}
     */
    public function expireLegacyIdempotency(): array
    {
        $cursor = '0';
        $scanned = 0;
        $expired = 0;
        do {
            [$cursor, $page] = $this->redis->scanKeys('batch:idem:*', $cursor, 1000);
            $scanned += count($page);
            if ($page !== []) {
                $expired += (int) $this->redis->eval(self::LEGACY_IDEMPOTENCY_LUA, $page, [self::LEGACY_IDEM_TTL_SECONDS]);
            }
        } while ($cursor !== '0');

        return ['scanned' => $scanned, 'expired' => $expired];
    }

    /**
     * Ejecuta el cuerpo de un patrón (salvo `jobs`, que va por collectJob()) para una clave.
     * Devuelve el número de claves borradas (int ≥ 0).
     */
    private function collectKey(
        string $name,
        string $key,
        int $now,
        int $retention,
        \Closure $statusOf,
        AuditStateStore $stateStore,
        \Closure $jobStatusOf
    ): int {
        switch ($name) {
            case 'tasks':
                $raw = $this->redis->eval('return redis.call("HGET",KEYS[1],"record")', [$key]);
                if ($raw === null) {
                    return 0;
                }
                $task = new AuditTask(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
                if (!in_array($task->record['state'], ['completed', 'failed'], true)) {
                    return 0;
                }
                $status = $statusOf((string) $task->record['audit_id']);
                if ($status !== null && !AuditStateStore::isTerminalStatus($status)) {
                    return 0;
                }
                return (int) $this->redis->eval(
                    self::GC_DELETE_TASK_LUA,
                    [
                        $key,
                        FairAuditTaskQueue::ALL,
                        FairAuditTaskQueue::COUNTS,
                        'audit:execution:refs:' . $task->record['audit_id'],
                    ],
                    [$raw]
                );

            case 'plans':
                $raw = $this->redis->eval('if redis.call("TYPE",KEYS[1]).ok~="hash" then return nil end; return redis.call("HGET",KEYS[1],"record")', [$key]);
                if (!is_string($raw)) {
                    return 0;
                }
                $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $status = $statusOf((string) $plan['audit_id']);
                if ($status === null || AuditStateStore::isTerminalStatus($status)
                    || in_array($plan['state'], ['consumed', 'failed'], true)) {
                    return (int) $this->redis->eval(
                        self::GC_DELETE_SEMANTIC_PLAN_LUA,
                        [
                            $key,
                            SemanticEvaluationStore::PLANS,
                            'audit:semantic:v2:counts',
                            'audit:execution:refs:' . $plan['audit_id'],
                        ],
                        [$plan['revision']]
                    );
                }
                return 0;

            case 'audits':
                // Solo metadatos de retención y sha1 del valor: el JSON completo nunca llega a PHP.
                $meta = $stateStore->readAuditRetentionMetadata($key);
                if ($meta === null) {
                    return 0;
                }
                $completed = strtotime((string) ($meta['completed_at'] ?? $meta['updated_at'] ?? ''));
                if (!AuditStateStore::isTerminalStatus((string) ($meta['status'] ?? ''))
                    || $completed === false || $now < ($completed + $retention) * 1000) {
                    return 0;
                }
                return (int) $this->redis->eval(
                    self::GC_DELETE_AUDIT_STATE_LUA,
                    [$key, 'audit:execution:refs:' . ($meta['audit_id'] ?? '')],
                    [$meta['sha1']]
                );

            case 'gemini_attempts':
                $raw = $this->redis->get($key);
                if (!is_string($raw) || !str_starts_with($raw, '{')) {
                    return 0;
                }
                $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if ($record['state'] === 'inflight') {
                    return 0;
                }
                $task = $this->queue->get($record['task_id']);
                $status = isset($record['audit_id']) ? $statusOf((string) $record['audit_id']) : null;
                if (($status === null || AuditStateStore::isTerminalStatus($status))
                    && ($task === null || in_array($task->record['state'], ['completed', 'failed'], true))) {
                    return (int) $this->redis->eval(
                        self::GC_DELETE_GEMINI_ATTEMPT_LUA,
                        [
                            $key,
                            'audit:gemini:v2:attempts:' . ($record['audit_id'] ?? 'none'),
                            $record['quota_base'] . ':metrics',
                        ],
                        [$raw]
                    );
                }
                return 0;

            case 'reservations':
                // Índice 5: claves `disdetnro:*` y, durante la pasada legada, `disid:*` (misma regla de liberación).
                $raw = $this->redis->get($key);
                if (!is_string($raw) || !str_starts_with($raw, '{')) {
                    return 0;
                }
                $reservation = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $auditId = (string) ($reservation['audit_id'] ?? '');
                $claimed = strtotime((string) ($reservation['claimed_at'] ?? ''));
                $status = $auditId !== '' ? $statusOf($auditId) : null;
                if (($status !== null && AuditStateStore::isTerminalStatus($status))
                    || ($status === null && ($claimed === false || $now - ($claimed * 1000) >= 60000))) {
                    return (int) $this->redis->eval(
                        'if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end; return redis.call("DEL",KEYS[1])',
                        [$key],
                        [$raw]
                    );
                }
                // X-F2-B1: auditoría viva cuya reserva pertenece a un job inexistente, o terminal sin la fila registrada.
                // Una fila registrada en job:{id}:audits nunca se toca aquí (R4-F2-B1).
                $jobId = (string) ($reservation['job_id'] ?? '');
                if (str_starts_with($key, 'audit:reservation:disdetnro:') && $status !== null
                    && !AuditStateStore::isTerminalStatus($status) && $jobId !== ''
                    && $claimed !== false && $now - ($claimed * 1000) >= 60000) {
                    $jobStatus = $jobStatusOf($jobId);
                    if ($jobStatus === null
                        || (in_array($jobStatus, self::TERMINAL_JOB_STATUSES, true)
                            && (int) $this->redis->eval('return redis.call("HEXISTS",KEYS[1],ARGV[1])', [BatchJobStore::jobAuditsKey($jobId)], [$auditId]) === 0)) {
                        return (int) $this->redis->eval(
                            self::GC_RELEASE_ORPHAN_RESERVATION_LUA,
                            [$key, AuditStateStore::auditKey($auditId)],
                            [$raw, gmdate('Y-m-d\TH:i:s\Z')]
                        );
                    }
                }
                return 0;

            case 'idempotency':
                // Conservar mientras viva el job; al retirarlo comienza retención adicional (REQ-F1-12).
                $jobId = $this->redis->get($key);
                if ($jobId !== null) {
                    $this->redis->eval(
                        self::GC_IDEMPOTENCY_LUA,
                        [$key, BatchJobStore::jobKey($jobId)],
                        [$retention]
                    );
                }
                return 0;

            default:
                throw new \LogicException('gc_pattern_unknown');
        }
    }

    /**
     * Rama `jobs` (SDD-BATCH-001, delta Fase 2): solo metadatos de `job:{id}:state` v2 (< 2048 B).
     *  - terminal y vencido: comprobación de referencias por rebanadas HSCAN reanudables y borrado por sha1;
     *  - no terminal: reaper (`DEC-10`) salvo pausa, con umbral medido desde `max(progreso, resumed_at_ms)`;
     *    un job `reaping` se reanuda de inmediato.
     * Devuelve las claves borradas o null si un corte de presupuesto obliga a reencolar la clave al frente.
     *
     * @param array{paused:bool,resumed_at_ms:int,threshold_ms:int} $reaper
     */
    private function collectJob(string $key, int $now, int $retention, array &$state, int &$items, int $deadline, array $reaper): ?int
    {
        // Una lectura por clave y solo si hay memoria: el JSON del job no se reenvía como ARGV (DEC-F1-08).
        if ($this->memoryBytes() >= self::GC_MEMORY_SOFT_LIMIT_BYTES) {
            return null;
        }
        $jobId = preg_match('/^job:([^:]+):state$/D', $key, $match) === 1 ? $match[1] : '';
        $length = (int) $this->redis->eval('return redis.call("STRLEN",KEYS[1])', [$key]);
        if ($length >= self::JOB_STATE_MAX_BYTES) {
            Logger::critical('gc_v1_job_state_after_migration', ['job_id' => $jobId]);
            return 0;
        }
        $raw = $length > 0 ? $this->redis->get($key) : null;
        if ($raw === null) {
            if ($jobId !== '' && $state['hscan_job_id'] === $jobId) {
                self::clearHscan($state);
            }
            if ($jobId !== '' && $state['reap_job_id'] === $jobId) {
                $state['reap_job_id'] = '';
                $state['reap_cursor'] = '0';
            }
            return 0;
        }
        $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($jobId === '') {
            $jobId = (string) ($job['job_id'] ?? '');
        }

        if (in_array($job['status'] ?? '', self::TERMINAL_JOB_STATUSES, true)) {
            $completed = strtotime((string) ($job['completed_at'] ?? $job['updated_at'] ?? ''));
            if ($completed === false || $now < ($completed + $retention) * 1000) {
                if ($state['hscan_job_id'] === $jobId) {
                    self::clearHscan($state);
                }
                return 0;
            }
            return $this->collectTerminalJob($key, $jobId, $raw, (int) ($job['fac_nit_sec'] ?? 0), $state, $items, $deadline);
        }

        if ($reaper['paused']) {
            return 0;
        }
        if (($job['reaping'] ?? false) !== true) {
            $progress = is_numeric($job['last_progress_at'] ?? null) ? (int) $job['last_progress_at'] : 0;
            if ($progress <= 0) {
                $progress = self::isoToMs((string) ($job['updated_at'] ?? ''));
            }
            if ($progress <= 0 || $now - max($progress, $reaper['resumed_at_ms']) < $reaper['threshold_ms']) {
                return 0;   // sin marca de progreso interpretable el reaper automático no actúa
            }
        }

        return $this->runReaper($jobId, $state['reap_job_id'] === $jobId ? $state['reap_cursor'] : null, $now, $reaper['threshold_ms'], $state, $items, $deadline);
    }

    /**
     * HSCAN reanudable de `job:{id}:audits` (`hscan_job_id`/`hscan_cursor`/`hscan_sha1`); cada rebanada cuenta como
     * un ítem. Referencia activa → aborta el borrado de ese job. Al terminar, borra por sha1 persistido.
     */
    private function collectTerminalJob(string $key, string $jobId, string $raw, int $facNitSec, array &$state, int &$items, int $deadline): ?int
    {
        if ($state['hscan_job_id'] !== $jobId) {
            $state['hscan_job_id'] = $jobId;
            $state['hscan_cursor'] = '0';
            $state['hscan_sha1'] = sha1($raw);
        }
        $auditsKey = BatchJobStore::jobAuditsKey($jobId);
        do {
            if ($this->gcBudgetExceeded($items, $deadline)) {
                return null;
            }
            $items++;
            $slice = $this->redis->eval(self::GC_JOB_REFS_SLICE_LUA, [$auditsKey], [$state['hscan_cursor']]);
            if (!is_array($slice) || !isset($slice[0])) {
                throw new \RuntimeException('gc_job_refs_slice_invalid');
            }
            if ((int) ($slice[1] ?? 0) === 1) {
                self::clearHscan($state);   // R4-F2-M3
                return 0;
            }
            $state['hscan_cursor'] = (string) $slice[0];
        } while ($state['hscan_cursor'] !== '0');

        $deleted = (int) $this->redis->eval(
            self::GC_DELETE_JOB_STATE_LUA,
            [
                $key,
                'jobs:index',
                $auditsKey,
                BatchJobStore::jobByDispensaKey($jobId),
                BatchJobStore::jobCandidatesKey($jobId),
                BatchJobStore::activeNitJobsKey($facNitSec),
            ],
            [$state['hscan_sha1'], $jobId]
        );
        self::clearHscan($state);

        return $deleted;
    }

    /**
     * Ejecuta rebanadas de `BatchJobStore::reapStaleJob()` hasta terminar o agotar el presupuesto (cada rebanada
     * cuenta como un ítem). Un corte tras una rebanada persiste `reap_job_id`/`reap_cursor`.
     *
     * @return int|null 0 al terminar; null ante corte de presupuesto.
     */
    private function runReaper(string $jobId, ?string $cursor, int $now, int $thresholdMs, array &$state, int &$items, int $deadline): ?int
    {
        while (true) {
            if ($this->gcBudgetExceeded($items, $deadline)) {
                if ($cursor !== null) {
                    $state['reap_job_id'] = $jobId;
                    $state['reap_cursor'] = $cursor;
                }
                return null;
            }
            $items++;
            $slice = $this->jobStore()->reapStaleJob($jobId, $now, $thresholdMs, $cursor);
            if (!$slice['done']) {
                $cursor = (string) $slice['cursor'];
                $state['reap_job_id'] = $jobId;
                $state['reap_cursor'] = $cursor;
                continue;
            }
            if ($state['reap_job_id'] === $jobId) {
                $state['reap_job_id'] = '';
                $state['reap_cursor'] = '0';
            }
            if ($slice['reaped']) {
                $this->lastGcReapedJobs++;
                Logger::warning('stale_no_progress', ['job_id' => $jobId, 'threshold_ms' => $thresholdMs]);
            }
            return 0;
        }
    }

    /**
     * Reanuda al inicio de gc() el `reap_job_id` pendiente (W-F2-M7). La invocación cuenta como un ítem. Un fallo no
     * bloquea gc(): se registra y se descarta el cursor (el job `reaping` se reanuda al recorrer `job:*:state`).
     *
     * @param array{paused:bool,resumed_at_ms:int,threshold_ms:int} $reaper
     * @return int|null null si el presupuesto se agotó antes de terminar.
     */
    private function resumePendingReap(array &$state, int $now, array $reaper, int &$items, int $deadline): ?int
    {
        $jobId = $state['reap_job_id'];
        $items++;
        try {
            return $this->runReaper($jobId, $state['reap_cursor'], $now, $reaper['threshold_ms'], $state, $items, $deadline);
        } catch (\Throwable $error) {
            Logger::error('gc_reap_resume_failed', ['job_id' => $jobId, 'error_class' => get_class($error)]);
            $state['reap_job_id'] = '';
            $state['reap_cursor'] = '0';
            return 0;
        }
    }

    private function jobStore(): BatchJobStore
    {
        return $this->batchJobStore ??= new BatchJobStore($this->redis);
    }

    private static function clearHscan(array &$state): void
    {
        $state['hscan_job_id'] = '';
        $state['hscan_cursor'] = '0';
        $state['hscan_sha1'] = '';
    }

    /** ISO-8601 → ms UTC; 0 si no se puede interpretar. */
    private static function isoToMs(string $iso): int
    {
        $seconds = $iso === '' ? false : strtotime($iso);
        return $seconds === false ? 0 : max(0, $seconds * 1000);
    }

    /** Presupuesto de GC: ítems, tiempo y memoria del proceso (INV-F1-01/02). */
    private function gcBudgetExceeded(int $items, int $deadline): bool
    {
        if ($items >= self::GC_MAX_ITEMS || hrtime(true) >= $deadline || $this->memoryBytes() >= self::GC_MEMORY_SOFT_LIMIT_BYTES) {
            $this->gcBudgetStopped = true;
            return true;
        }
        return false;
    }

    private function memoryBytes(): int
    {
        return $this->memoryProbe !== null ? (int) ($this->memoryProbe)() : memory_get_usage(true);
    }

    /** @return array{pattern_index:int,cursors:array<string,string>,pending_pattern:?int,pending_keys:list<string>,last_gc_ms:int,cycle_started_at_ms:int} */
    private static function defaultGcState(): array
    {
        $cursors = [];
        foreach (self::GC_PATTERNS as $pattern) {
            $cursors[$pattern['name']] = '0';
        }
        $cursors['reservations_legacy'] = '0';
        return [
            'pattern_index' => 0,
            'cursors' => $cursors,
            'pending_pattern' => null,
            'pending_keys' => [],
            'last_gc_ms' => 0,
            'cycle_started_at_ms' => 0,
            // SDD-BATCH-001 (Fase 2): HSCAN reanudable, reaper por rebanadas y pasada legada de reservas.
            'hscan_job_id' => '',
            'hscan_cursor' => '0',
            'hscan_sha1' => '',
            'reap_job_id' => '',
            'reap_cursor' => '0',
            'legacy_done' => '0',
            'legacy_pass_done' => '0',
            'legacy_saw_keys' => '0',
        ];
    }

    /**
     * Valida campo a campo. Un estado inválido se reemplaza por defaults sin lanzar excepción (DEC-F1-03).
     * Campos desconocidos (extensiones de Fase 2) se ignoran sin warning.
     *
     * @return array{pattern_index:int,cursors:array<string,string>,pending_pattern:?int,pending_keys:list<string>,last_gc_ms:int,cycle_started_at_ms:int}
     */
    private function loadGcState(): array
    {
        $state = self::defaultGcState();
        $raw = $this->redis->eval('return redis.call("HGETALL",KEYS[1])', [self::GC_CURSORS_KEY]);
        if (!is_array($raw) || count($raw) % 2 !== 0) {
            Logger::warning('gc_cursor_state_reset');
            return $state;
        }
        $fields = [];
        for ($i = 0; $i < count($raw); $i += 2) {
            $fields[(string) $raw[$i]] = (string) $raw[$i + 1];
        }

        $patternIndex = $fields['pattern_index'] ?? '';
        if (preg_match('/^[0-6]$/', $patternIndex) === 1) {
            $state['pattern_index'] = (int) $patternIndex;
        }
        foreach (self::GC_PATTERNS as $pattern) {
            $cursor = $fields['cursor:' . $pattern['name']] ?? '';
            if (ctype_digit($cursor)) {
                $state['cursors'][$pattern['name']] = $cursor;
            }
        }

        // pending_keys: lista JSON de strings ≤ GC_PENDING_MAX. Ausente o '' ⇒ [] sin warning.
        $pendingKeysRaw = $fields['pending_keys'] ?? '';
        $pendingKeys = [];
        $discardCount = 0;
        $invalid = false;
        if ($pendingKeysRaw !== '') {
            $decoded = json_decode($pendingKeysRaw, true);
            if (is_array($decoded) && array_is_list($decoded) && count($decoded) <= self::GC_PENDING_MAX
                && array_reduce($decoded, static fn(bool $ok, mixed $v): bool => $ok && is_string($v), true)) {
                $pendingKeys = $decoded;
            } else {
                $invalid = true;
                $discardCount = is_array($decoded) && array_is_list($decoded) ? count($decoded) : 0;
            }
        }

        // pending_pattern: '' o ausente ⇒ null; ‘0’..‘6’ ⇒ int; cualquier otro valor descarta las pendientes.
        $pendingPatternRaw = $fields['pending_pattern'] ?? '';
        $pendingPattern = null;
        if ($pendingPatternRaw !== '') {
            if (preg_match('/^[0-6]$/', $pendingPatternRaw) === 1) {
                $pendingPattern = (int) $pendingPatternRaw;
            } else {
                $invalid = true;
                $discardCount = max($discardCount, count($pendingKeys));
            }
        }

        if ($invalid) {
            Logger::warning('gc_pending_keys_discarded', ['count' => $discardCount]);
            $pendingKeys = [];
            $pendingPattern = null;
        }
        if ($pendingPattern === null) {
            $pendingKeys = [];
        }
        $state['pending_pattern'] = $pendingPattern;
        $state['pending_keys'] = $pendingKeys;

        $lastGc = $fields['last_gc_ms'] ?? '';
        $state['last_gc_ms'] = ctype_digit($lastGc) ? (int) $lastGc : 0;
        $cycle = $fields['cycle_started_at_ms'] ?? '';
        $state['cycle_started_at_ms'] = ctype_digit($cycle) ? (int) $cycle : 0;

        // Campos de Fase 2: un valor inválido vuelve a su default sin warning.
        $legacyCursor = $fields['cursor:reservations_legacy'] ?? '';
        if (ctype_digit($legacyCursor)) {
            $state['cursors']['reservations_legacy'] = $legacyCursor;
        }
        foreach (['legacy_done', 'legacy_pass_done', 'legacy_saw_keys'] as $flag) {
            if (in_array($fields[$flag] ?? '', ['0', '1'], true)) {
                $state[$flag] = $fields[$flag];
            }
        }
        $hscanJob = $fields['hscan_job_id'] ?? '';
        $hscanCursor = $fields['hscan_cursor'] ?? '';
        $hscanSha1 = $fields['hscan_sha1'] ?? '';
        if (preg_match('/^[^:\s]{1,128}$/D', $hscanJob) === 1 && ctype_digit($hscanCursor)
            && preg_match('/^[0-9a-f]{40}$/D', $hscanSha1) === 1) {
            $state['hscan_job_id'] = $hscanJob;
            $state['hscan_cursor'] = $hscanCursor;
            $state['hscan_sha1'] = $hscanSha1;
        }
        $reapJob = $fields['reap_job_id'] ?? '';
        $reapCursor = $fields['reap_cursor'] ?? '';
        if (preg_match('/^[^:\s]{1,128}$/D', $reapJob) === 1 && ctype_digit($reapCursor)) {
            $state['reap_job_id'] = $reapJob;
            $state['reap_cursor'] = $reapCursor;
        }

        return $state;
    }

    /** Un único HSET: los campos desconocidos del Hash (extensiones) se preservan. Nunca DEL ni reescritura. */
    private function saveGcState(array $state): void
    {
        $fields = [
            'pattern_index' => (string) $state['pattern_index'],
            'pending_pattern' => $state['pending_pattern'] === null ? '' : (string) $state['pending_pattern'],
            'pending_keys' => json_encode($state['pending_keys'], JSON_THROW_ON_ERROR),
            'last_gc_ms' => (string) $state['last_gc_ms'],
            'cycle_started_at_ms' => (string) $state['cycle_started_at_ms'],
            'cursor:reservations_legacy' => (string) ($state['cursors']['reservations_legacy'] ?? '0'),
            'hscan_job_id' => (string) ($state['hscan_job_id'] ?? ''),
            'hscan_cursor' => (string) ($state['hscan_cursor'] ?? '0'),
            'hscan_sha1' => (string) ($state['hscan_sha1'] ?? ''),
            'reap_job_id' => (string) ($state['reap_job_id'] ?? ''),
            'reap_cursor' => (string) ($state['reap_cursor'] ?? '0'),
            'legacy_done' => (string) ($state['legacy_done'] ?? '0'),
            'legacy_pass_done' => (string) ($state['legacy_pass_done'] ?? '0'),
            'legacy_saw_keys' => (string) ($state['legacy_saw_keys'] ?? '0'),
        ];
        foreach (self::GC_PATTERNS as $pattern) {
            $fields['cursor:' . $pattern['name']] = (string) $state['cursors'][$pattern['name']];
        }
        $argv = [];
        foreach ($fields as $field => $value) {
            $argv[] = $field;
            $argv[] = $value;
        }
        $this->redis->eval('return redis.call("HSET",KEYS[1],unpack(ARGV))', [self::GC_CURSORS_KEY], $argv);
    }

    /**
     * Intake por etapa: `.priority` antes que `.batch`, capacidad compartida entre sus streams (DEC-F1-05),
     * rotación del inicio por etapa y al menos una ronda por tick (DEC-F1-07).
     *
     * @return array{int,int} [admitidos, confirmados por ackBatch]
     */
    private function intakeAll(int $deadline): array
    {
        $metrics = $this->queue->metrics();
        $stages = self::stages();
        $names = array_keys($stages);
        $capacity = [];
        foreach ($names as $name) {
            $queued = array_sum(array_map(static fn(array $lane): int => $lane['queued'] + $lane['delayed'], $metrics[$name]));
            $capacity[$name] = min(100, $this->config->maxQueued - $queued);
        }
        $offset = $this->intakeOffset;
        $this->intakeOffset = ($this->intakeOffset + 1) % count($names);
        $rounds = 0;
        $admitted = 0;
        $acked = 0;
        for ($k = 0; $k < count($names); $k++) {
            $name = $names[($offset + $k) % count($names)];
            $stage = AuditTaskStage::from($name);
            foreach ($stages[$name]['streams'] as $stream) {
                if ($rounds > 0 && hrtime(true) >= $deadline) {
                    break 2;
                }
                $lane = str_ends_with($stream, '.priority') ? AuditLane::PRIORITY : AuditLane::BATCH;
                $this->queue->maintain($stage, $lane);
                if ($capacity[$name] < 1) {
                    continue;
                }
                [$a, $c, $r] = $this->intakeStream($stage, $stream, $stages[$name]['group'], $capacity[$name], $deadline, $rounds === 0);
                $admitted += $a;
                $acked += $c;
                $rounds += $r;
            }
        }

        return [$admitted, $acked];
    }

    /**
     * @param int $capacity Capacidad compartida de la etapa (se decrementa por admisión)
     * @return array{int,int,int} [admitidos, confirmados, rondas]
     */
    private function intakeStream(
        AuditTaskStage $stage,
        string $stream,
        string $group,
        int &$capacity,
        int $deadline,
        bool $forceFirst
    ): array {
        $admitted = 0;
        $acked = 0;
        $rounds = 0;
        $reference = $stage->value . ':' . $stream;
        $reclaimed = $this->redis->eval(
            'return redis.call("XAUTOCLAIM",KEYS[1],ARGV[1],"execution-intake",5000,ARGV[2],"COUNT",ARGV[3])',
            [$stream],
            [$group, $this->reclaimCursors[$reference] ?? '0-0', $capacity]
        );
        if (!is_array($reclaimed) || !isset($reclaimed[0], $reclaimed[1]) || !is_array($reclaimed[1])) {
            throw new \RuntimeException('intake_reclaim_invalid');
        }
        if (($reclaimed[2] ?? []) !== []) {
            throw new \RuntimeException('intake_pending_message_deleted');
        }
        $this->reclaimCursors[$reference] = $reclaimed[0];
        if ($reclaimed[1] !== []) {
            [$a, $c] = $this->processEntries($stage, $stream, $group, $reclaimed[1], $capacity);
            $admitted += $a;
            $acked += $c;
            $rounds++;   // cuenta como ronda: habilita el corte por deadline en intakeAll()
        }

        while ($capacity > 0 && (($forceFirst && $rounds === 0) || hrtime(true) < $deadline)) {
            $raw = $this->redis->eval(
                'return redis.call("XREADGROUP","GROUP",ARGV[1],"execution-intake","COUNT",ARGV[2],"STREAMS",KEYS[1],">")',
                [$stream],
                [$group, self::INTAKE_READ_COUNT]
            );
            if ($raw === null || $raw === []) {
                break;
            }
            if (!is_array($raw) || !is_array($raw[0][1] ?? null)) {
                throw new \RuntimeException('intake_read_invalid');
            }
            $entries = $raw[0][1];
            if ($entries === []) {
                break;
            }
            [$a, $c] = $this->processEntries($stage, $stream, $group, $entries, $capacity);
            $admitted += $a;
            $acked += $c;
            $rounds++;
            if (count($entries) < self::INTAKE_READ_COUNT) {   // página corta: no quedan entradas nuevas
                break;
            }
        }

        return [$admitted, $acked, $rounds];
    }

    /**
     * Clasifica un lote: inválidos (DLQ) y no coincidentes se confirman en un único XACK-lote; los coincidentes
     * se admiten mientras haya capacidad. Los no admitidos quedan en PEL para XAUTOCLAIM.
     *
     * @param list<array{0:string,1:array}> $entries
     * @return array{int,int} [admitidos, confirmados por ackBatch]
     */
    private function processEntries(AuditTaskStage $stage, string $stream, string $group, array $entries, int &$capacity): array
    {
        $ackIds = [];
        $matching = [];
        foreach ($entries as $entry) {
            if (!is_string($entry[0] ?? null) || !is_array($entry[1] ?? null)) {
                throw new \RuntimeException('intake_message_invalid');
            }
            $fields = [];
            for ($i = 0; $i < count($entry[1]); $i += 2) {
                $fields[$entry[1][$i]] = $entry[1][$i + 1];
            }
            try {
                $event = AuditEvent::fromArray(json_decode($fields['event'] ?? '', true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException|\InvalidArgumentException|\TypeError $error) {
                $record = AuditEvent::create(AuditEvent::TYPE_DEAD_LETTER, null, payload: [
                    'original_event_json' => $fields['event'] ?? '',
                    'error'               => 'intake_event_contract_invalid',
                    'stage'               => $stage->value,
                    'stream'              => $stream,
                    'stream_id'           => $entry[0],
                    'group'               => $group,
                ])->toArray();
                $record['event_id'] = AuditTaskEffects::uuid($stage->value . ':' . $stream . ':' . $entry[0]);
                (new AuditEventPublisher($this->redis))->publishDeadLetter(AuditEvent::fromArray($record));
                $ackIds[] = $entry[0];
                continue;
            }
            if (!in_array($event->eventType, $stage->eventTypes(), true)) {
                $ackIds[] = $entry[0];
                continue;
            }
            $matching[] = [$entry[0], $event];
        }

        $this->ackBatch($stream, $group, $ackIds);
        $ackedBatch = count($ackIds);

        $admitted = 0;
        foreach ($matching as [$id, $event]) {
            if ($capacity <= 0) {
                break;
            }
            if ($this->queue->admit(AuditTask::fromEvent($stage, $event), $stream, $group, $id)) {
                $capacity--;
                $admitted++;
            } else {
                $capacity = 0;   // etapa llena: el resto queda en PEL
            }
        }

        return [$admitted, $ackedBatch];
    }

    /** @param list<string> $ids */
    private function ackBatch(string $stream, string $group, array $ids): void
    {
        foreach (array_chunk($ids, self::INTAKE_ACK_CHUNK) as $chunk) {
            $this->redis->eval(
                'return redis.call("XACK",KEYS[1],ARGV[1],unpack(ARGV,2))',
                [$stream],
                array_merge([$group], $chunk)
            );
        }
    }

    /**
     * Escribe el heartbeat y su meta en un único eval (DEC-F1-11). `$extraMeta` permite extensiones; los campos
     * base y `at_ms` no pueden sobrescribirse.
     *
     * @param array<string,int|string> $extraMeta
     */
    private function writeHeartbeat(
        int $durationMs,
        int $flushed,
        int $admitted,
        int $acked,
        int $removed,
        array $extraMeta = [],
        bool $admissionPaused = false,
        bool $executionPaused = false
    ): void {
        unset($extraMeta['at_ms']);
        $fields = [
            'tick_duration_ms' => $durationMs,
            'outbox_flushed' => $flushed,
            'intake_admitted' => $admitted,
            'intake_acked' => $acked,
            'gc_removed' => $removed,
            'memory_bytes' => $this->memoryBytes(),
            'gc_budget_stopped' => $this->gcBudgetStopped ? 1 : 0,
            'admission_paused' => $admissionPaused ? 1 : 0,
            'execution_paused' => $executionPaused ? 1 : 0,
        ] + $extraMeta;
        $argv = [AuditExecutionConfig::HEARTBEAT_TTL_SECONDS, self::HEARTBEAT_META_TTL_SECONDS];
        foreach ($fields as $field => $value) {
            $argv[] = $field;
            $argv[] = $value;
        }
        $this->redis->eval(
            'local t=redis.call("TIME"); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); '
            . 'redis.call("SET",KEYS[1],now,"EX",ARGV[1]); '
            . 'redis.call("HSET",KEYS[2],"at_ms",now,unpack(ARGV,3)); '
            . 'redis.call("EXPIRE",KEYS[2],ARGV[2]); return now',
            ['audit:execution:heartbeat', self::HEARTBEAT_META_KEY],
            $argv
        );
    }

    private function ensureGroups(): void
    {
        if ($this->groupsReady) {
            return;
        }
        foreach (self::stages() as $transport) {
            foreach ($transport['streams'] as $stream) {
                $this->redis->eval(
                    'local result=redis.pcall("XGROUP","CREATE",KEYS[1],ARGV[1],"0","MKSTREAM"); if type(result)=="table" and result.err and not string.find(result.err,"BUSYGROUP") then return redis.error_reply(result.err) end; return 1',
                    [$stream],
                    [$transport['group']]
                );
            }
        }
        $this->groupsReady = true;
    }
}
