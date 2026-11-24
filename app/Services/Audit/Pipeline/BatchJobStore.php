<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

/**
 * Estado Redis de los jobs batch (SDD-BATCH-001 v1.2, sección 13).
 *
 * Modelo v2: `job:{id}:state` (string JSON compacto, sin `audits`), `job:{id}:audits` (hash),
 * `job:{id}:by_dispensa` (hash), `job:{id}:candidates` (list) y `jobs:active:nit:{nit}` (hash).
 *
 * Reglas transversales de los Lua:
 *  - Prefijo (`W-F2-B2`): `RedisClient::eval()` solo antepone `REDIS_PREFIX` a KEYS; todo script que
 *    construye claves dinámicas deriva `prefix` desde `KEYS[1]`.
 *  - Guarda de generación (`W-F2-B3`): los scripts de generación rechazan (`-2`) jobs sellados,
 *    en `reaping` o terminales antes de validar el epoch.
 */
class BatchJobStore
{
    use JsonRedisStoreTrait;

    public const JOB_STATUS_PENDING             = 'pending';
    public const JOB_STATUS_QUEUED              = 'queued';      // Auditorias registradas en stream, ninguna ha terminado aun
    public const JOB_STATUS_PROCESSING          = 'processing';
    public const JOB_STATUS_COMPLETED           = 'completed';
    public const JOB_STATUS_COMPLETED_WITH_ERR  = 'completed_with_errors';
    public const JOB_STATUS_FAILED              = 'failed';

    private const DEFAULT_JOB_TTL_SECONDS = 86400;

    /** Clave del hash Redis donde se acumulan contadores operativos del pipeline. */
    public const TELEMETRY_KEY = 'telemetry:async_metrics';

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    private static function jobTtlSeconds(): int
    {
        return self::positiveIntEnv('AUDIT_JOB_TTL', self::DEFAULT_JOB_TTL_SECONDS);
    }

    private static function positiveIntEnv(string $key, int $default): int
    {
        $value = Env::get($key, (string) $default);
        $value = is_string($value) ? trim($value) : $value;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Ejecuta un script y devuelve su resultado crudo; los fallos de Redis se envuelven en RuntimeException.
     *
     * @param  array<int,string>  $keys
     * @param  array<int,mixed>  $args
     * @param  array<string,mixed>  $logContext
     */
    private function evalScript(string $lua, array $keys, array $args, string $errorMessage, array $logContext = []): mixed
    {
        try {
            return $this->redis->eval($lua, $keys, $args);
        } catch (\Exception $e) {
            Logger::error($errorMessage, array_merge($logContext, ['error' => $e->getMessage()]));
            throw new RuntimeException($errorMessage, 0, $e);
        }
    }

    // ── Claves ───────────────────────────────────────────────────────────

    public static function jobKey(string $jobId): string
    {
        return "job:{$jobId}:state";
    }

    /** Hash `auditId` → `{dis_det_nro, status, failed_stage?}` del job. */
    public static function jobAuditsKey(string $jobId): string
    {
        return "job:{$jobId}:audits";
    }

    /** Hash `disDetNro` → `{audit_id, dispatched}` del job. */
    public static function jobByDispensaKey(string $jobId): string
    {
        return "job:{$jobId}:by_dispensa";
    }

    /** Lista de candidatas congeladas del job (orden SQL ASC). */
    public static function jobCandidatesKey(string $jobId): string
    {
        return "job:{$jobId}:candidates";
    }

    /** Hash `jobId` → `{date_from, date_to}` de jobs activos por NIT. */
    public static function activeNitJobsKey(int $facNitSec): string
    {
        return "jobs:active:nit:{$facNitSec}";
    }

    /**
     * Construye la key Redis de reserva global por DisDetNro (1:1 con `AudDispEst.FacNro`).
     *
     * @param  string  $disDetNro  Identificador operativo de la dispensa.
     */
    public static function auditReservationKey(string $disDetNro): string
    {
        return "audit:reservation:disdetnro:{$disDetNro}";
    }

    /**
     * Construye la key Redis de lock distribuido de generación por Job ID.
     */
    public static function jobGenerationLockKey(string $jobId): string
    {
        return "batch:claim:{$jobId}";
    }

    /**
     * Construye la key Redis de idempotencia por cliente.
     */
    public static function idempotencyKey(string $key): string
    {
        return "batch:idem:{$key}";
    }

    // ── Creación, lectura y listado de jobs ──────────────────────────────

    /**
     * Crea un job de forma atómica rechazando solapamientos por (NIT, rango de fechas) (`INV-03`, `DEC-05`).
     *
     * @return array{created:bool, conflict_job_id:?string, reason:?string}
     *         `reason`: `null` si se creó; `overlap` si hay un job activo solapado; `job_exists` si el `jobId` ya existe.
     */
    public function initJobExclusive(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): array {
        $now = self::nowIso();
        $state = [
            'job_id' => $jobId,
            'execution_version' => AuditExecutionConfig::VERSION,
            'status' => self::JOB_STATUS_PENDING,
            'fac_nit_sec' => $facNitSec,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
            'sealed' => false,
            'total' => 0,
            'accepted' => 0,
            'done' => 0,
            'failed' => 0,
            'skipped_locked' => 0,
            'skipped_existing' => 0,
            'accumulated_duration_ms' => 0,
            'avg_duration_ms' => 0,
            'generation_epoch' => 0,
            'candidates_frozen' => false,
            'candidates_count' => 0,
            'next_candidate_offset' => 0,
            'last_committed_chunk' => 0,
            'last_continuation_dispatched_chunk' => 0,
            'batch_created_published' => false,
            'last_progress_at' => self::nowMs(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $range = ['date_from' => $dateFrom, 'date_to' => $dateTo];

        $result = $this->evalScript(
            self::INIT_JOB_EXCLUSIVE_LUA,
            [self::jobKey($jobId), 'jobs:index', self::activeNitJobsKey($facNitSec), self::TELEMETRY_KEY],
            [
                $jobId,
                (string) $facNitSec,
                $dateFrom,
                $dateTo,
                self::encodeJson($state, 'BatchJobStore::initJobExclusive'),
                self::encodeJson($range, 'BatchJobStore::initJobExclusive'),
                (string) time(),
            ],
            'No se pudo inicializar el job exclusivo',
            ['job_id' => $jobId]
        );

        $status = is_array($result) ? (string) ($result[0] ?? '') : '';
        if ($status === 'created') {
            return ['created' => true, 'conflict_job_id' => null, 'reason' => null];
        }
        if ($status === 'conflict') {
            $conflict = trim((string) ($result[1] ?? ''));
            return ['created' => false, 'conflict_job_id' => $conflict !== '' ? $conflict : null, 'reason' => 'overlap'];
        }

        return ['created' => false, 'conflict_job_id' => null, 'reason' => 'job_exists'];
    }

    /**
     * Lista los jobs batch recientes/activos con un resumen ligero.
     *
     * @param int $limit Cantidad máxima de jobs a retornar (1..100)
     * @return array<int, array<string, mixed>>
     */
    public function listJobs(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $rawJson = $this->redis->eval(
                self::LIST_JOBS_LUA,
                ['jobs:index'],
                [(string) $limit]
            );

            if (!is_string($rawJson) || $rawJson === '') {
                return [];
            }

            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                return [];
            }

            usort($decoded, static fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            foreach ($decoded as &$job) {
                $job = array_merge($job, self::calculatePerformance($job, $now));
            }
            unset($job);

            return $decoded;
        } catch (\Throwable $e) {
            Logger::error('BatchJobStore::listJobs falló', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Rendimiento de pared; las duraciones de auditorías concurrentes no son elapsed.
     * @return array{avg_duration_ms:int,accumulated_duration_ms:int,elapsed_ms:int,throughput_per_sec:float}
     */
    public static function calculatePerformance(array $state, ?\DateTimeImmutable $now = null): array
    {
        $result = [
            'avg_duration_ms' => max(0, (int) ($state['avg_duration_ms'] ?? 0)),
            'accumulated_duration_ms' => max(0, (int) ($state['accumulated_duration_ms'] ?? 0)),
            'elapsed_ms' => 0,
            'throughput_per_sec' => 0.0,
        ];
        $createdAt = trim((string) ($state['created_at'] ?? ''));
        if ($createdAt === '') {
            return $result;
        }
        $terminal = in_array($state['status'] ?? '', [
            self::JOB_STATUS_COMPLETED, self::JOB_STATUS_COMPLETED_WITH_ERR, self::JOB_STATUS_FAILED,
        ], true);
        $completedAt = trim((string) ($state['completed_at'] ?? $state['updated_at'] ?? ''));
        if ($terminal && $completedAt === '') {
            return $result;
        }
        try {
            $start = new \DateTimeImmutable($createdAt);
            $end = $terminal ? new \DateTimeImmutable($completedAt)
                : ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Exception) {
            // Estado histórico sin timestamp interpretable: duración desconocida.
            return $result;
        }
        $elapsedMs = max(0, (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000));
        $processed = max(0, (int) ($state['done'] ?? 0)) + max(0, (int) ($state['failed'] ?? 0));
        $result['elapsed_ms'] = $elapsedMs;
        if ($elapsedMs > 0 && $processed > 0) {
            $result['throughput_per_sec'] = round($processed / ($elapsedMs / 1000), 4);
        }
        return $result;
    }

    /**
     * Lee únicamente `job:{id}:state` (~600 B), sin el mapa de auditorías.
     *
     * @return array<string,mixed>|null
     */
    public function getJobSummary(string $jobId): ?array
    {
        try {
            $raw = $this->redis->get(self::jobKey($jobId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer job', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'BatchJobStore');
    }

    /**
     * Lee el job completo reconstruyendo `audits` desde `job:{id}:audits` (contrato JSON externo sin cambios).
     *
     * @return array<string,mixed>|null
     */
    public function getJob(string $jobId): ?array
    {
        try {
            $raw = $this->redis->eval(
                self::GET_JOB_LUA,
                [self::jobKey($jobId), self::jobAuditsKey($jobId)],
                []
            );
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer job', 0, $e);
        }

        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }

        return self::decodeJson((string) $raw, 'BatchJobStore');
    }

    // ── Registro de auditorías ───────────────────────────────────────────

    /**
     * Registra una auditoría dentro del job batch junto con su identidad global.
     *
     * Idempotente por `(jobId, disDetNro)`: `audits_in_flight` solo se incrementa si la auditoría es nueva en el hash.
     *
     * @param  string  $jobId  UUID del job batch.
     * @param  string  $auditId  UUID de la auditoría.
     * @param  string  $disDetNro  Identificador operativo de la dispensa.
     * @param  string|null  $disId  Identificador global de la factura.
     * @param  string|null  $reservationToken  Token propietario de la reserva Redis.
     * @param  int|null  $expectedEpoch  Epoch de generación esperado (`null` = sin fencing, solo compatibilidad).
     * @throws RuntimeException `generation_fence_stale` (409) si el epoch está obsoleto; `job_closed_or_reaping` (409)
     *                          si el job está sellado, en reaping o terminal; `audit_dispensa_conflict` (409) si la
     *                          dispensa ya está registrada con otra auditoría.
     */
    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $disId = null,
        ?string $reservationToken = null,
        ?int $expectedEpoch = null
    ): bool {
        $result = (int) $this->evalScript(
            self::REGISTER_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId), self::TELEMETRY_KEY, self::jobAuditsKey($jobId), self::jobByDispensaKey($jobId)],
            [
                $auditId,
                $disDetNro,
                $disId ?? '',
                $reservationToken ?? '',
                self::nowIso(),
                (string) self::jobTtlSeconds(),
                $expectedEpoch === null ? '' : (string) $expectedEpoch,
                (string) self::nowMs(),
            ],
            'No se pudo registrar auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );

        return match (true) {
            $result === 1 => true,
            $result === -1 => throw new RuntimeException('generation_fence_stale', 409),
            $result === -2 => throw new RuntimeException('job_closed_or_reaping', 409),
            $result === -4 => throw new RuntimeException('audit_dispensa_conflict', 409),
            default => false,
        };
    }

    /**
     * Lee la entrada de idempotencia por dispensa del job.
     *
     * @return array{audit_id:string,dispatched:bool}|null
     */
    public function getBatchDispensaEntry(string $jobId, string $disDetNro): ?array
    {
        $raw = $this->evalScript(
            "return redis.call('HGET', KEYS[1], ARGV[1])",
            [self::jobByDispensaKey($jobId)],
            [$disDetNro],
            'No se pudo leer la entrada by_dispensa del job',
            ['job_id' => $jobId]
        );
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'audit_id' => (string) ($decoded['audit_id'] ?? ''),
            'dispatched' => ($decoded['dispatched'] ?? false) === true,
        ];
    }

    /**
     * Marca como despachadas las auditorías publicadas y, opcionalmente, `batch_created_published`.
     *
     * Formato ÚNICO de `$pairs`: mapa `disDetNro => auditId` (p. ej. `['T123' => '<uuid>']`). PHP convierte las claves
     * numéricas a `int`; se normalizan con `(string)`. El Lua solo marca `dispatched = true` si el `audit_id` de la
     * entrada `by_dispensa` coincide (o si `auditId` viene vacío).
     *
     * @param  array<int|string,string>  $pairs  Mapa `disDetNro => auditId`.
     * @param  bool  $batchCreatedPublished  Fija `batch_created_published = true` en el estado del job.
     * @param  int  $expectedEpoch  Epoch de generación vigente.
     * @return bool `false` si falta el job, el epoch es obsoleto o el job está sellado/en reaping/terminal.
     */
    public function markAuditsDispatched(string $jobId, array $pairs, bool $batchCreatedPublished, int $expectedEpoch): bool
    {
        $flat = [];
        foreach ($pairs as $key => $value) {
            $ddn = trim((string) $key);
            $auditId = trim((string) $value);
            if ($ddn === '') {
                continue;
            }
            $flat[] = $ddn;
            $flat[] = $auditId;
        }

        $result = (int) $this->evalScript(
            self::MARK_AUDITS_DISPATCHED_LUA,
            [self::jobKey($jobId), self::jobByDispensaKey($jobId)],
            array_merge(
                [(string) $expectedEpoch, $batchCreatedPublished ? '1' : '0', self::nowIso(), (string) self::nowMs()],
                $flat
            ),
            'No se pudo marcar auditorías despachadas',
            ['job_id' => $jobId]
        );

        return $result === 1;
    }

    // ── Congelación de candidatas y checkpoints ──────────────────────────

    /**
     * Congela las candidatas del job en `job:{id}:candidates` (una sola vez; idempotente si ya estaba congelado).
     *
     * @param  array<int,array<string,mixed>>  $candidates  Tuplas compactas en orden SQL ASC.
     * @return bool `false` si falta el job, el epoch es obsoleto o el job está cerrado/en reaping.
     */
    public function storeFrozenCandidates(string $jobId, array $candidates, int $expectedEpoch): bool
    {
        $args = [(string) $expectedEpoch, self::nowIso()];
        foreach ($candidates as $candidate) {
            $args[] = self::encodeJson((array) $candidate, 'BatchJobStore::storeFrozenCandidates');
        }

        $result = (int) $this->evalScript(
            self::STORE_FROZEN_CANDIDATES_LUA,
            [self::jobKey($jobId), self::jobCandidatesKey($jobId)],
            $args,
            'No se pudieron congelar las candidatas del job',
            ['job_id' => $jobId, 'candidates' => count($candidates)]
        );

        return $result === 1;
    }

    /**
     * Lee un tramo de candidatas congeladas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function readFrozenCandidates(string $jobId, int $offset, int $count): array
    {
        if ($count < 1) {
            return [];
        }
        $offset = max(0, $offset);

        $rows = $this->evalScript(
            "return redis.call('LRANGE', KEYS[1], tonumber(ARGV[1]), tonumber(ARGV[2]))",
            [self::jobCandidatesKey($jobId)],
            [(string) $offset, (string) ($offset + $count - 1)],
            'No se pudieron leer las candidatas congeladas',
            ['job_id' => $jobId]
        );
        if (!is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $decoded = is_string($row) ? json_decode($row, true) : null;
            if (is_array($decoded)) {
                $candidates[] = $decoded;
            }
        }

        return $candidates;
    }

    /**
     * Confirma el checkpoint de un chunk (offset, cursor y deltas `skipped_*`); idempotente por `chunkIndex`.
     *
     * @param  array<string,mixed>|null  $lastCursor
     * @return bool `false` si falta el job, el epoch es obsoleto o el job está cerrado/en reaping.
     */
    public function commitChunkCheckpoint(
        string $jobId,
        int $chunkIndex,
        int $nextOffset,
        int $skippedLockedDelta,
        int $skippedExistingDelta,
        int $expectedEpoch,
        ?array $lastCursor = null
    ): bool {
        $result = (int) $this->evalScript(
            self::COMMIT_CHUNK_CHECKPOINT_LUA,
            [self::jobKey($jobId)],
            [
                (string) $chunkIndex,
                (string) $nextOffset,
                (string) max(0, $skippedLockedDelta),
                (string) max(0, $skippedExistingDelta),
                (string) $expectedEpoch,
                $lastCursor === null ? '' : self::encodeJson($lastCursor, 'BatchJobStore::commitChunkCheckpoint'),
                self::nowIso(),
                (string) self::nowMs(),
            ],
            'No se pudo confirmar el checkpoint del chunk',
            ['job_id' => $jobId, 'chunk_index' => $chunkIndex]
        );

        return $result === 1;
    }

    /**
     * Confirma que la continuación del chunk `$chunkIndex` fue publicada (`R-CONT`: sin validar epoch).
     *
     * @return bool `false` si el job no existe o `$chunkIndex < 1`.
     * @throws RuntimeException `continuation_before_checkpoint` si `last_committed_chunk < $chunkIndex`.
     */
    public function markContinuationDispatched(string $jobId, int $chunkIndex): bool
    {
        $result = (int) $this->evalScript(
            self::MARK_CONTINUATION_DISPATCHED_LUA,
            [self::jobKey($jobId)],
            [(string) $chunkIndex],
            'No se pudo confirmar la continuación del chunk',
            ['job_id' => $jobId, 'chunk_index' => $chunkIndex]
        );

        if ($result === -1) {
            throw new RuntimeException('continuation_before_checkpoint');
        }

        return $result === 1;
    }

    // ── Cierre y finalización ────────────────────────────────────────────

    /**
     * Elimina el job y todas sus claves v2 (`audits`, `by_dispensa`, `candidates`, lock) en un único Lua.
     */
    public function deleteJob(string $jobId): bool
    {
        $result = (int) $this->evalScript(
            self::LUA_HELPERS . "\n" . self::DELETE_JOB_LUA,
            [
                self::jobKey($jobId),
                self::jobAuditsKey($jobId),
                self::jobByDispensaKey($jobId),
                self::jobCandidatesKey($jobId),
                self::jobGenerationLockKey($jobId),
                'jobs:index',
                self::TELEMETRY_KEY,
            ],
            [$jobId],
            'No se pudo eliminar el job',
            ['job_id' => $jobId]
        );

        return $result === 1;
    }

    public function patchJob(string $jobId, array $patch): bool
    {
        $patch['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->runScript(
            self::$MERGE_LUA,
            [self::jobKey($jobId)],
            [$patch, self::jobTtlSeconds()],
            'No se pudo actualizar el job en Redis',
            ['job_id' => $jobId]
        );
    }

    /**
     * Sella el job (no se registrarán más auditorías) y lo cierra si `done + failed >= total` (`SEAL_JOB_LUA`).
     *
     * Firma: `sealJob($jobId, $skippedLockedDelta, $skippedExistingDelta, $expectedEpoch, $chunkIndex, $nextOffset)`.
     * (La firma legada `sealJob($jobId, $total, array $metadata)` fue retirada en SDD-BATCH-001 B2.)
     *
     * @param  int  $skippedLockedDelta  Delta de `skipped_locked`.
     * @param  int  $skippedExistingDelta  Delta de `skipped_existing`.
     * @return bool `false` si el job no existe, el epoch es obsoleto o el job ya está sellado/en reaping/terminal.
     */
    public function sealJob(
        string $jobId,
        int $skippedLockedDelta = 0,
        int $skippedExistingDelta = 0,
        ?int $expectedEpoch = null,
        ?int $chunkIndex = null,
        ?int $nextOffset = null
    ): bool {
        $result = (int) $this->evalScript(
            self::LUA_HELPERS . "\n" . self::SEAL_JOB_LUA,
            [self::jobKey($jobId), self::TELEMETRY_KEY, self::jobAuditsKey($jobId), self::jobCandidatesKey($jobId)],
            [
                (string) max(0, $skippedLockedDelta),
                (string) max(0, $skippedExistingDelta),
                $expectedEpoch === null ? '' : (string) $expectedEpoch,
                $chunkIndex === null ? '' : (string) $chunkIndex,
                $nextOffset === null ? '' : (string) $nextOffset,
                self::nowIso(),
                (string) self::nowMs(),
            ],
            'No se pudo sellar el job',
            ['job_id' => $jobId]
        );

        return $result === 1;
    }

    /**
     * Cierra como `completed` un job sin candidatas (solo Chunk 1 con `$snapshot === []`, `R-EMPTY`).
     *
     * @return bool `false` si falta el job, el epoch es obsoleto o el job está sellado/en reaping/terminal.
     */
    public function publishEmptyBatch(string $jobId, int $skippedLocked, int $skippedExisting, ?int $expectedEpoch = null): bool
    {
        $result = (int) $this->evalScript(
            self::LUA_HELPERS . "\n" . self::PUBLISH_EMPTY_BATCH_LUA,
            [self::jobKey($jobId), self::TELEMETRY_KEY, self::jobCandidatesKey($jobId)],
            [
                (string) max(0, $skippedLocked),
                (string) max(0, $skippedExisting),
                $expectedEpoch === null ? '' : (string) $expectedEpoch,
                self::nowIso(),
                (string) self::nowMs(),
            ],
            'No se pudo publicar el lote vacío',
            ['job_id' => $jobId]
        );

        return $result === 1;
    }

    /**
     * Marca una auditoría del job como terminal y actualiza métricas agregadas.
     *
     * @param  string  $jobId  UUID del job batch.
     * @param  string  $auditId  UUID de la auditoría.
     * @param  string  $status  Estado terminal de la auditoría.
     * @param  int  $durationMs  Duración activa de la auditoría en milisegundos.
     * @param  string|null $failedStage Etapa donde falló la auditoría.
     */
    public function markAuditCompletedInJob(
        string $jobId,
        string $auditId,
        string $status,
        int $durationMs = 0,
        ?string $failedStage = null
    ): bool {
        return $this->runScript(
            self::MARK_AUDIT_COMPLETED_IN_JOB_LUA,
            [self::jobKey($jobId), self::TELEMETRY_KEY, self::jobAuditsKey($jobId)],
            [
                $auditId,
                $status,
                gmdate('Y-m-d\TH:i:s\Z'),
                self::jobTtlSeconds(),
                max(0, $durationMs),
                $failedStage ?? '',
                (string) self::nowMs(),
            ],
            'No se pudo actualizar el progreso del job en Redis',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Lleva el job a `failed` (DLQ de `batch_requested`). Retorna `false` sin lanzar excepción cuando el job ya es
     * terminal/sellado/en reaping, el epoch es obsoleto o el chunk ya fue confirmado y continuado.
     *
     * @param  int|null  $expectedEpoch  Epoch del propietario vigente; `null` o `<= 0` omite la validación.
     */
    public function markJobFailed(string $jobId, string $errorMessage, ?int $expectedEpoch = null, int $failedChunkIndex = 0): bool
    {
        $result = (int) $this->evalScript(
            self::LUA_HELPERS . "\n" . self::MARK_JOB_FAILED_LUA,
            [self::jobKey($jobId), self::TELEMETRY_KEY, self::jobCandidatesKey($jobId)],
            [
                mb_substr($errorMessage, 0, 500),
                (string) ($expectedEpoch ?? 0),
                (string) max(0, $failedChunkIndex),
                self::nowIso(),
                (string) self::nowMs(),
            ],
            'No se pudo marcar el job como fallido',
            ['job_id' => $jobId]
        );

        return $result === 1;
    }

    /**
     * Cancela las auditorías registradas pero no despachadas de un job `failed` (`W-F2-B4`).
     *
     * @return int Total de auditorías canceladas (itera `CANCEL_UNDISPATCHED_AUDITS_LUA` hasta cursor `'0'`).
     */
    public function cancelUndispatchedAudits(string $jobId, string $failedStage = 'batch_dlq_unpublished'): int
    {
        $cursor = '0';
        $cancelled = 0;
        $iterations = 0;
        do {
            $result = $this->evalScript(
                self::LUA_HELPERS . "\n" . self::CANCEL_UNDISPATCHED_AUDITS_LUA,
                [self::jobKey($jobId), self::jobByDispensaKey($jobId), self::jobAuditsKey($jobId), self::TELEMETRY_KEY],
                [$cursor, $failedStage, self::nowIso()],
                'No se pudieron cancelar las auditorías no despachadas',
                ['job_id' => $jobId]
            );
            if (!is_array($result)) {
                break;
            }
            $cursor = (string) ($result[0] ?? '0');
            $cancelled += (int) ($result[1] ?? 0);
        } while ($cursor !== '0' && ++$iterations < 100000);

        return $cancelled;
    }

    /**
     * Vence un job activo sin progreso (`DEC-10`, `R-STALE`) por rebanadas `HSCAN COUNT 500`.
     *
     * @param  int  $nowMs  Marca actual en ms UTC.
     * @param  int  $thresholdMs  Umbral de inactividad; `0` ignora el progreso (`repair-jobs --force-stale`).
     * @param  string|null  $cursor  Cursor de la rebanada anterior (`null` = primera rebanada).
     * @return array{done:bool,cursor:?string,cancelled_audits:int,reaped:bool}
     *         `done = true` cuando no quedan rebanadas; `reaped = true` solo si el job quedó cerrado en esta llamada.
     *         `cancelled_audits` es el de la rebanada ejecutada (el llamador acumula entre rebanadas).
     */
    public function reapStaleJob(
        string $jobId,
        int $nowMs,
        int $thresholdMs,
        ?string $cursor = null,
        string $reason = 'stale_no_progress'
    ): array {
        $summary = $this->getJobSummary($jobId);
        if ($summary === null) {
            return ['done' => true, 'cursor' => null, 'cancelled_audits' => 0, 'reaped' => false];
        }

        $facNitSec = (int) ($summary['fac_nit_sec'] ?? 0);
        $fallbackUpdatedAtMs = 0;
        $updatedAt = trim((string) ($summary['updated_at'] ?? ''));
        if ($updatedAt !== '') {
            try {
                $fallbackUpdatedAtMs = max(0, ((int) (new \DateTimeImmutable($updatedAt))->format('U')) * 1000);
            } catch (\Exception) {
                $fallbackUpdatedAtMs = 0;
            }
        }

        $result = $this->evalScript(
            self::LUA_HELPERS . "\n" . self::REAP_STALE_JOB_LUA,
            [
                self::jobKey($jobId),
                self::jobAuditsKey($jobId),
                self::activeNitJobsKey($facNitSec),
                self::TELEMETRY_KEY,
                self::jobCandidatesKey($jobId),
                self::jobGenerationLockKey($jobId),
            ],
            [
                (string) $nowMs,
                (string) max(0, $thresholdMs),
                $reason,
                $cursor === null || $cursor === '' ? '0' : $cursor,
                self::nowIso(),
                (string) $fallbackUpdatedAtMs,
            ],
            'No se pudo vencer el job sin progreso',
            ['job_id' => $jobId]
        );

        $code = is_array($result) ? (int) ($result[0] ?? -1) : -1;
        $cancelled = is_array($result) ? (int) ($result[2] ?? 0) : 0;
        if ($code === 0) {
            return ['done' => false, 'cursor' => (string) ($result[1] ?? '0'), 'cancelled_audits' => $cancelled, 'reaped' => false];
        }

        return ['done' => true, 'cursor' => null, 'cancelled_audits' => $cancelled, 'reaped' => $code === 1];
    }

    // ── Idempotencia HTTP/cron ───────────────────────────────────────────

    /**
     * Intenta reclamar una llave de idempotencia por cliente (X-Idempotency-Key).
     *
     * Usa un único eval atómico `SET NX EX` y, si la llave ya existía, `GET` en el mismo script:
     * no hay ventana entre ambas operaciones en la que la llave pueda expirar y producir un falso reclamo.
     * TTL efectivo = max($ttl, AUDIT_JOB_TTL); el GC lo amplía mientras exista el job.
     *
     * @param  string  $key    Valor del header X-Idempotency-Key (o UUID auto-generado)
     * @param  string  $jobId  UUID v4 del job nuevo a registrar
     * @param  int     $ttl    Segundos mínimos de vida de la llave (AUDIT_IDEMPOTENCY_KEY_TTL), debe ser > 0
     *
     * @return string|null  null → llave reclamada exitosamente (primera solicitud)
     *                      string → job_id existente (solicitud duplicada → 409)
     *
     * @throws \InvalidArgumentException Si $ttl <= 0 (`idempotency_ttl_invalid`)
     * @throws RuntimeException Si Redis no está disponible o falla el script
     */
    public function claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('idempotency_ttl_invalid');
        }
        $redisKey = self::idempotencyKey($key);

        try {
            $existing = $this->redis->eval(
                'local r=redis.call("SET",KEYS[1],ARGV[1],"NX","EX",ARGV[2]); if r then return false end; '
                . 'return redis.call("GET",KEYS[1])',
                [$redisKey],
                [$jobId, max($ttl, self::jobTtlSeconds())]
            );
            return $existing === null || $existing === false ? null : (string) $existing;
        } catch (RedisUnavailableException|\Predis\PredisException $e) {
            // eval() no lanza RedisUnavailableException hoy (se conserva por compatibilidad con dobles);
            // PredisException no deriva de \RuntimeException: se envuelve para que async() responda 503.
            throw new RuntimeException('Redis no disponible al reclamar llave de idempotencia', 0, $e);
        }
    }

    /**
     * Elimina la llave de idempotencia solo si todavía apunta a `$jobId` (compensación por 409/fallo de creación).
     *
     * @param  string  $key    Valor del header X-Idempotency-Key.
     * @param  string  $jobId  Job que reclamó la llave.
     * @return bool `true` si la llave fue eliminada.
     */
    public function releaseIdempotencyKeyIfMatches(string $key, string $jobId): bool
    {
        if ($key === '' || $jobId === '') {
            return false;
        }

        $result = $this->evalScript(
            'if redis.call("GET",KEYS[1]) == ARGV[1] then return redis.call("DEL",KEYS[1]) end return 0',
            [self::idempotencyKey($key)],
            [$jobId],
            'No se pudo liberar la llave de idempotencia',
            ['job_id' => $jobId]
        );

        return (int) $result === 1;
    }

    // ── Reservas por DisDetNro ───────────────────────────────────────────

    /**
     * Reserva `audit:reservation:disdetnro:{disDetNro}` con `SET NX` sin TTL (`INV-04`).
     *
     * @param  string  $disDetNro  Identificador operativo de la dispensa (1:1 con `AudDispEst.FacNro`).
     * @param  string  $ownerToken  Token propietario.
     * @param  array<string,mixed>  $reservation  `{job_id, audit_id, fac_nit_sec, dis_id, source, ...}`.
     */
    public function claimAuditReservation(string $disDetNro, string $ownerToken, array $reservation): bool
    {
        $reservation['dis_det_nro'] = $disDetNro;
        $reservation['token'] = $ownerToken;
        $reservation['claimed_at'] = $reservation['claimed_at'] ?? gmdate('Y-m-d\TH:i:s\Z');

        return $this->redis->eval(
            'local r=redis.call("SET",KEYS[1],ARGV[1],"NX"); return r and 1 or 0',
            [self::auditReservationKey($disDetNro)],
            [self::encodeJson($reservation, 'BatchJobStore')]
        ) === 1;
    }

    /**
     * Lee la reserva activa de auditoría por DisDetNro.
     *
     * @param  string  $disDetNro  Identificador operativo de la dispensa.
     * @return array<string,mixed>|null
     */
    public function getAuditReservation(string $disDetNro): ?array
    {
        try {
            $raw = $this->redis->get(self::auditReservationKey($disDetNro));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer reserva de auditoría', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'BatchJobStore::getAuditReservation');
    }

    /**
     * Libera una reserva de auditoría solo si el token coincide con el propietario.
     *
     * @param  string  $disDetNro  Identificador operativo de la dispensa.
     * @param  string  $ownerToken  Token propietario de la reserva Redis.
     */
    public function releaseAuditReservation(string $disDetNro, string $ownerToken): bool
    {
        if ($disDetNro === '' || $ownerToken === '') {
            return false;
        }

        return $this->runScript(
            self::RELEASE_AUDIT_RESERVATION_LUA,
            [self::auditReservationKey($disDetNro)],
            [$ownerToken],
            'No se pudo liberar reserva de auditoría',
            ['dis_det_nro' => $disDetNro]
        );
    }

    /**
     * Libera la reserva asociada a un estado de auditoría (`dis_det_nro` + `reservation_token`).
     *
     * @param  array<string,mixed>  $audit
     */
    public function releaseAuditReservationFromAudit(array $audit): bool
    {
        $disDetNro = trim((string) ($audit['dis_det_nro'] ?? ''));
        $token = trim((string) ($audit['reservation_token'] ?? ''));

        return $this->releaseAuditReservation($disDetNro, $token);
    }

    // ── Lock de generación (fencing) ─────────────────────────────────────

    /**
     * Adquiere el lock de generación incrementando `generation_epoch` (único punto donde crece el epoch).
     *
     * @param  int  $chunkIndex  Chunk recibido; `c > 1` promueve `last_continuation_dispatched_chunk` a `c - 1` (`R-CONT`).
     * @throws RuntimeException `invalid_generation_lock_claim_response` ante un estado desconocido; `invalid_job_state`
     *                          (envuelto) si `job:{id}:state` no es JSON.
     */
    public function claimJobGenerationFencedLock(
        string $jobId,
        string $workerId,
        int $chunkIndex,
        int $ttlSeconds = 1800
    ): JobLockClaimResult {
        if ($jobId === '' || $workerId === '') {
            return new JobLockClaimResult(JobLockClaimStatus::MISSING);
        }

        $result = $this->evalScript(
            self::CLAIM_JOB_GENERATION_FENCED_LOCK_LUA,
            [self::jobGenerationLockKey($jobId), self::jobKey($jobId)],
            [$workerId, (string) $ttlSeconds, (string) $chunkIndex],
            'No se pudo adquirir el lock de generación batch',
            ['job_id' => $jobId]
        );

        if (!is_array($result) || !isset($result[0])) {
            throw new RuntimeException('invalid_generation_lock_claim_response');
        }
        $status = JobLockClaimStatus::tryFrom((string) $result[0]);
        if ($status === null) {
            throw new RuntimeException('invalid_generation_lock_claim_response');
        }

        return new JobLockClaimResult($status, (int) ($result[1] ?? 0));
    }

    /**
     * Renueva el TTL de `batch:claim:{jobId}` solo si coinciden `worker_id` y `epoch`.
     */
    public function renewJobGenerationLock(string $jobId, string $workerId, int $epoch, int $ttlSeconds = 1800): bool
    {
        if ($jobId === '' || $workerId === '') {
            return false;
        }

        return (int) $this->evalScript(
            self::RENEW_JOB_GENERATION_LOCK_LUA,
            [self::jobGenerationLockKey($jobId)],
            [$workerId, (string) $epoch, (string) max(60, $ttlSeconds)],
            'No se pudo renovar el lock de generación batch',
            ['job_id' => $jobId]
        ) === 1;
    }

    /**
     * Libera el lock de generación si coincide `worker_id` (y `epoch` cuando no es `null`).
     *
     * Acepta también el valor legado (string plano con el `workerId`) que pudo dejar la versión anterior del lock;
     * `migrateBatchV2()` (tarea C) lo normaliza al formato JSON `{worker_id, epoch}`.
     */
    public function releaseJobGenerationLock(string $jobId, string $workerId, ?int $epoch = null): bool
    {
        if ($jobId === '' || $workerId === '') {
            return false;
        }

        return (int) $this->evalScript(
            self::RELEASE_JOB_GENERATION_LOCK_LUA,
            [self::jobGenerationLockKey($jobId)],
            [$workerId, $epoch === null ? '' : (string) $epoch],
            'No se pudo liberar el lock de generación batch',
            ['job_id' => $jobId]
        ) === 1;
    }

    // ── Lua ──────────────────────────────────────────────────────────────

    /** Utilidades compartidas por los scripts de cierre (se antepone en PHP). */
    private const LUA_HELPERS = <<<'LUA'
local function auditEntryTerminal(status)
    status = tostring(status or '')
    return status == 'completed' or status == 'manual_review' or status == 'error' or status == 'failed'
end
local function leaveStatusCounter(tkey, status)
    status = tostring(status or 'pending')
    if status == 'pending' then
        redis.call('HINCRBY', tkey, 'jobs_queued', -1)
    elseif status == 'queued' then
        redis.call('HINCRBY', tkey, 'jobs_active', -1)
    elseif status == 'processing' then
        redis.call('HINCRBY', tkey, 'jobs_running', -1)
    elseif status == 'completed' or status == 'completed_with_errors' then
        redis.call('HINCRBY', tkey, 'jobs_completed', -1)
    elseif status == 'failed' then
        redis.call('HINCRBY', tkey, 'jobs_failed', -1)
    end
end
LUA;

    private const INIT_JOB_EXCLUSIVE_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=jobs:index KEYS[3]=jobs:active:nit:{nit} KEYS[4]=telemetry
-- ARGV: 1=jobId 2=nit 3=dateFrom 4=dateTo 5=stateJson 6=rangeJson 7=epochSeconds
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
if redis.call('EXISTS', KEYS[1]) == 1 then return {'exists', ''} end
local active = redis.call('HGETALL', KEYS[3])
for i = 1, #active, 2 do
    local activeId = active[i]
    local activeRange = active[i + 1]
    local raw = redis.call('GET', prefix .. 'job:' .. activeId .. ':state')
    local terminal = false
    if not raw then
        terminal = true
    else
        local okJob, activeJob = pcall(cjson.decode, raw)
        if okJob and type(activeJob) == 'table' then
            local st = tostring(activeJob.status or '')
            terminal = st == 'completed' or st == 'completed_with_errors' or st == 'failed'
        end
    end
    if terminal then
        redis.call('HDEL', KEYS[3], activeId)
    else
        local aFrom = ''
        local aTo = ''
        local okRange, range = pcall(cjson.decode, activeRange)
        if okRange and type(range) == 'table' then
            aFrom = tostring(range.date_from or '')
            aTo = tostring(range.date_to or '')
        end
        -- Entrada legada sin rango interpretable: se trata como solapada (conservador).
        if aFrom == '' or aTo == '' or (aFrom <= ARGV[4] and aTo >= ARGV[3]) then
            return {'conflict', activeId}
        end
    end
end
redis.call('SET', KEYS[1], ARGV[5])
redis.call('ZADD', KEYS[2], tonumber(ARGV[7]) or 0, ARGV[1])
redis.call('HSET', KEYS[3], ARGV[1], ARGV[6])
redis.call('HINCRBY', KEYS[4], 'jobs_queued', 1)
return {'created', ''}
LUA;

    private const GET_JOB_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=job:{id}:audits
local raw = redis.call('GET', KEYS[1])
if not raw then return false end
local flat = redis.call('HGETALL', KEYS[2])
local job = cjson.decode(raw)
-- Job legado sin migrar: conserva el mapa inline si el hash no tiene entradas.
if #flat == 0 and type(job['audits']) == 'table' then return raw end
job['audits'] = nil
local encoded = cjson.encode(job)
local parts = {}
for i = 1, #flat, 2 do
    parts[#parts + 1] = cjson.encode(flat[i]) .. ':' .. flat[i + 1]
end
return string.sub(encoded, 1, -2) .. ',"audits":{' .. table.concat(parts, ',') .. '}}'
LUA;

    private const CLAIM_JOB_GENERATION_FENCED_LOCK_LUA = <<<'LUA'
-- CLAIM_JOB_GENERATION_FENCED_LOCK_LUA
-- KEYS[1]=batch:claim:{jobId}  KEYS[2]=job:{jobId}:state  (RedisClient::eval antepone REDIS_PREFIX)
-- ARGV[1]=worker_id  ARGV[2]=ttl_seconds  ARGV[3]=chunk_index
local jobRaw = redis.call('GET', KEYS[2])
if not jobRaw then return {'missing', 0} end
local ok, job = pcall(cjson.decode, jobRaw)
if not ok or type(job) ~= 'table' then return redis.error_reply('invalid_job_state') end
local cur = tonumber(job.generation_epoch or 0) or 0
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then
    return {'terminal', cur}
end
if redis.call('EXISTS', KEYS[1]) == 1 then return {'busy', cur} end
local epoch = cur + 1                      -- único punto donde crece el epoch
job.generation_epoch = epoch
job.generation_owner = ARGV[1]
local c = tonumber(ARGV[3]) or 1
-- Recibir el chunk c prueba que la continuación de c-1 ya fue publicada (R-CONT)
if c > 1 and (tonumber(job.last_committed_chunk or 0) or 0) >= c - 1
   and (tonumber(job.last_continuation_dispatched_chunk or 0) or 0) < c - 1 then
    job.last_continuation_dispatched_chunk = c - 1
end
redis.call('SET', KEYS[2], cjson.encode(job))          -- sin EX (INV-07)
redis.call('SET', KEYS[1], cjson.encode({worker_id = ARGV[1], epoch = epoch}),
    'EX', math.max(60, tonumber(ARGV[2]) or 1800))
return {'acquired', epoch}
LUA;

    private const RENEW_JOB_GENERATION_LOCK_LUA = <<<'LUA'
-- KEYS[1]=batch:claim:{jobId}  ARGV: 1=worker_id 2=epoch 3=ttl_seconds
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local ok, claim = pcall(cjson.decode, raw)
if not ok or type(claim) ~= 'table' then return 0 end
if tostring(claim.worker_id or '') ~= tostring(ARGV[1] or '') then return 0 end
if (tonumber(claim.epoch) or -1) ~= (tonumber(ARGV[2]) or -2) then return 0 end
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]) or 1800)
return 1
LUA;

    private const RELEASE_JOB_GENERATION_LOCK_LUA = <<<'LUA'
-- KEYS[1]=batch:claim:{jobId}  ARGV: 1=worker_id 2=epoch ('' = no validar)
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local workerId = tostring(ARGV[1] or '')
local expectedEpoch = tostring(ARGV[2] or '')

-- Valor legado (string plano con el worker_id) que pudo dejar la versión anterior del lock.
if tostring(raw) == workerId then
    redis.call('DEL', KEYS[1])
    return 1
end

local ok, claim = pcall(cjson.decode, raw)
if not ok or type(claim) ~= 'table' then return 0 end
if tostring(claim.worker_id or '') ~= workerId then return 0 end
if expectedEpoch ~= '' and (tonumber(claim.epoch) or -1) ~= (tonumber(expectedEpoch) or -2) then return 0 end

redis.call('DEL', KEYS[1])
return 1
LUA;

    private const STORE_FROZEN_CANDIDATES_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=job:{id}:candidates
-- ARGV: 1=expectedEpoch 2=nowIso 3..N=candidatas (JSON compacto)
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[1]) or -1) then return -1 end
-- Ya congelado: no se re-inserta ni se reinicia el offset (idempotente ante reentrega).
if job.candidates_frozen == true then return 1 end
redis.call('DEL', KEYS[2])
local n = #ARGV
local i = 3
while i <= n do
    local stop = math.min(i + 499, n)
    local batch = {}
    for j = i, stop do batch[#batch + 1] = ARGV[j] end
    redis.call('RPUSH', KEYS[2], unpack(batch))
    i = stop + 1
end
job.candidates_frozen = true
job.candidates_count = redis.call('LLEN', KEYS[2])
job.next_candidate_offset = 0
job.updated_at = ARGV[2]
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const REGISTER_AUDIT_IN_JOB_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=telemetry KEYS[3]=job:{id}:audits KEYS[4]=job:{id}:by_dispensa
-- ARGV: 1=auditId 2=disDetNro 3=disId 4=reservationToken 5=nowIso 6=ttl(sin uso) 7=expectedEpoch('' = sin fencing) 8=nowMs
-- disId y reservationToken viven en audit:{id}:state; los hashes solo conservan la proyección mínima.
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if ARGV[7] ~= '' and (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[7]) or -1) then return -1 end

local auditId = ARGV[1]
local disDetNro = ARGV[2]

-- Una dispensa solo puede tener una auditoría por job (INV-01).
local existingRaw = redis.call('HGET', KEYS[4], disDetNro)
if existingRaw then
    local okEx, existing = pcall(cjson.decode, existingRaw)
    if okEx and type(existing) == 'table' and tostring(existing.audit_id or '') ~= auditId then return -4 end
end

redis.call('HSETNX', KEYS[4], disDetNro, cjson.encode({audit_id = auditId, dispatched = false}))
local added = redis.call('HSETNX', KEYS[3], auditId, cjson.encode({dis_det_nro = disDetNro, status = 'pending'}))

local total = redis.call('HLEN', KEYS[3])
job['total'] = total
job['accepted'] = total
job['updated_at'] = ARGV[5]
job['last_progress_at'] = tonumber(ARGV[8]) or 0

-- El job existe y acaba de recibir su primera auditoria: ya no esta vacio.
if tostring(job['status'] or 'pending') == 'pending' then
    job['status'] = 'queued'
    redis.call('HINCRBY', KEYS[2], 'jobs_queued', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_active', 1)
end
-- audits_in_flight rastrea auditorias unicas activas: solo sube si la auditoria es nueva en el hash
-- (la recuperacion R-PREPUB re-invoca el registro y no debe contar dos veces).
if added == 1 then
    redis.call('HINCRBY', KEYS[2], 'audits_in_flight', 1)
end

redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const MARK_AUDITS_DISPATCHED_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=job:{id}:by_dispensa
-- ARGV: 1=expectedEpoch 2=batchCreatedPublished('1'/'0') 3=nowIso 4=nowMs 5..N = disDetNro, auditId (pares)
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[1]) or -1) then return -1 end
local i = 5
while i + 1 <= #ARGV do
    local ddn = ARGV[i]
    local auditId = ARGV[i + 1]
    local entryRaw = redis.call('HGET', KEYS[2], ddn)
    if entryRaw then
        local ok, entry = pcall(cjson.decode, entryRaw)
        if ok and type(entry) == 'table' and (auditId == '' or tostring(entry.audit_id or '') == auditId) then
            entry.dispatched = true
            redis.call('HSET', KEYS[2], ddn, cjson.encode(entry))
        end
    end
    i = i + 2
end
if ARGV[2] == '1' then job.batch_created_published = true end
job.updated_at = ARGV[3]
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const COMMIT_CHUNK_CHECKPOINT_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state
-- ARGV: 1=chunkIndex 2=nextOffset 3=skippedLockedDelta 4=skippedExistingDelta 5=expectedEpoch 6=lastCursorJson('' = sin cambio) 7=nowIso 8=nowMs
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[5]) or -1) then return -1 end
local chunkIdx = tonumber(ARGV[1]) or 0
local prevCommitted = tonumber(job.last_committed_chunk or 0) or 0
-- Recuperación: un checkpoint ya confirmado no duplica deltas.
if chunkIdx <= prevCommitted then return 1 end
job.next_candidate_offset = tonumber(ARGV[2]) or 0
job.skipped_locked = (tonumber(job.skipped_locked) or 0) + (tonumber(ARGV[3]) or 0)
job.skipped_existing = (tonumber(job.skipped_existing) or 0) + (tonumber(ARGV[4]) or 0)
if ARGV[6] ~= '' then
    local okCursor, cursor = pcall(cjson.decode, ARGV[6])
    if okCursor and type(cursor) == 'table' then job.last_cursor = cursor end
end
job.last_committed_chunk = chunkIdx
job.last_progress_at = tonumber(ARGV[8]) or 0
job.updated_at = ARGV[7]
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const MARK_CONTINUATION_DISPATCHED_LUA = <<<'LUA'
-- MARK_CONTINUATION_DISPATCHED_LUA  KEYS[1]=job:{jobId}:state  ARGV[1]=chunk_index
local raw = redis.call('GET', KEYS[1]); if not raw then return 0 end
local job = cjson.decode(raw); local k = tonumber(ARGV[1]) or 0
if k < 1 then return 0 end
if job.sealed == true or job.reaping == true or job.status == 'failed' then return 1 end
if (tonumber(job.last_committed_chunk or 0) or 0) < k then return -1 end
if (tonumber(job.last_continuation_dispatched_chunk or 0) or 0) >= k then return 1 end
job.last_continuation_dispatched_chunk = k
redis.call('SET', KEYS[1], cjson.encode(job))   -- sin EX (INV-07)
return 1
LUA;

    private const SEAL_JOB_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=telemetry KEYS[3]=job:{id}:audits KEYS[4]=job:{id}:candidates
-- ARGV: 1=skippedLockedDelta 2=skippedExistingDelta 3=expectedEpoch('' = sin fencing) 4=chunkIndex('' = n/a) 5=nextOffset('' = n/a) 6=nowIso 7=nowMs
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local jobId = string.match(KEYS[1], 'job:([^:]+):state$') or ''
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if ARGV[3] ~= '' and (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[3]) or -1) then return -1 end

local oldStatus = tostring(job.status or 'pending')
job.skipped_locked = (tonumber(job.skipped_locked) or 0) + (tonumber(ARGV[1]) or 0)
job.skipped_existing = (tonumber(job.skipped_existing) or 0) + (tonumber(ARGV[2]) or 0)
if ARGV[4] ~= '' and (tonumber(ARGV[4]) or 0) > (tonumber(job.last_committed_chunk or 0) or 0) then
    job.last_committed_chunk = tonumber(ARGV[4])
end
if ARGV[5] ~= '' then job.next_candidate_offset = tonumber(ARGV[5]) or 0 end
redis.call('DEL', KEYS[4])

local total = redis.call('HLEN', KEYS[3])
job.sealed = true
job.total = total
job.accepted = total
job.last_progress_at = tonumber(ARGV[7]) or 0
job.updated_at = ARGV[6]

local done = tonumber(job.done) or 0
local failed = tonumber(job.failed) or 0
-- R-EMPTY: cierra siempre que done + failed >= total, incluso con total == 0 en jobs multichunk.
if done + failed >= total then
    if failed > 0 then
        job.status = 'completed_with_errors'
    else
        job.status = 'completed'
    end
    job.completed_at = ARGV[6]
    redis.call('HDEL', prefix .. 'jobs:active:nit:' .. tostring(job.fac_nit_sec), jobId)
    leaveStatusCounter(KEYS[2], oldStatus)
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
end

redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const PUBLISH_EMPTY_BATCH_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=telemetry KEYS[3]=job:{id}:candidates
-- ARGV: 1=skippedLocked 2=skippedExisting 3=expectedEpoch('' = sin fencing) 4=nowIso 5=nowMs
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local jobId = string.match(KEYS[1], 'job:([^:]+):state$') or ''
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end
if ARGV[3] ~= '' and (tonumber(job.generation_epoch or 0) or 0) ~= (tonumber(ARGV[3]) or -1) then return -1 end

local oldStatus = tostring(job.status or 'pending')
job.status = 'completed'
job.sealed = true
job.total = 0
job.accepted = 0
job.skipped_locked = (tonumber(job.skipped_locked) or 0) + (tonumber(ARGV[1]) or 0)
job.skipped_existing = (tonumber(job.skipped_existing) or 0) + (tonumber(ARGV[2]) or 0)
job.completed_at = ARGV[4]
job.updated_at = ARGV[4]
job.last_progress_at = tonumber(ARGV[5]) or 0
redis.call('DEL', KEYS[3])
redis.call('HDEL', prefix .. 'jobs:active:nit:' .. tostring(job.fac_nit_sec), jobId)
leaveStatusCounter(KEYS[2], oldStatus)
redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const MARK_JOB_FAILED_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=telemetry KEYS[3]=job:{id}:candidates
-- ARGV: 1=errorMessage 2=expectedEpoch('0' = sin fencing) 3=failedChunkIndex 4=nowIso 5=nowMs
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local jobId = string.match(KEYS[1], 'job:([^:]+):state$') or ''
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local st = tostring(job.status or '')
if st == 'completed' or st == 'completed_with_errors' or st == 'failed' or job.sealed == true or job.reaping == true then return 0 end
local expectedEpoch = tonumber(ARGV[2]) or 0
if expectedEpoch > 0 and (tonumber(job.generation_epoch or 0) or 0) ~= expectedEpoch then return -1 end
local failedChunk = tonumber(ARGV[3]) or 0
if failedChunk > 0
   and (tonumber(job.last_committed_chunk or 0) or 0) >= failedChunk
   and (tonumber(job.last_continuation_dispatched_chunk or 0) or 0) >= failedChunk then
    return 0
end

local oldStatus = tostring(job.status or 'pending')
job.status = 'failed'
job.sealed = true
job.error_message = ARGV[1]
job.completed_at = ARGV[4]
job.updated_at = ARGV[4]
job.last_progress_at = tonumber(ARGV[5]) or 0
redis.call('DEL', KEYS[3])
redis.call('HDEL', prefix .. 'jobs:active:nit:' .. tostring(job.fac_nit_sec), jobId)
leaveStatusCounter(KEYS[2], oldStatus)
redis.call('HINCRBY', KEYS[2], 'jobs_failed', 1)
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const DELETE_JOB_LUA = <<<'LUA'
-- KEYS: 1=state 2=audits 3=by_dispensa 4=candidates 5=batch:claim 6=jobs:index 7=telemetry   ARGV[1]=jobId
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local jobId = ARGV[1]
local raw = redis.call('GET', KEYS[1])
if raw then
    local ok, job = pcall(cjson.decode, raw)
    if ok and type(job) == 'table' then
        local cursor = '0'
        repeat
            local scan = redis.call('HSCAN', KEYS[2], cursor, 'COUNT', 500)
            cursor = scan[1]
            local items = scan[2]
            for i = 1, #items, 2 do
                local okEntry, entry = pcall(cjson.decode, items[i + 1])
                if okEntry and type(entry) == 'table' and not auditEntryTerminal(entry.status) then
                    redis.call('HINCRBY', KEYS[7], 'audits_in_flight', -1)
                end
            end
        until cursor == '0'
        leaveStatusCounter(KEYS[7], job.status)
        if job.fac_nit_sec ~= nil and job.fac_nit_sec ~= cjson.null then
            redis.call('HDEL', prefix .. 'jobs:active:nit:' .. tostring(job.fac_nit_sec), jobId)
        end
    end
end
local removed = redis.call('DEL', KEYS[1], KEYS[2], KEYS[3], KEYS[4], KEYS[5])
redis.call('ZREM', KEYS[6], jobId)
if removed > 0 then return 1 end
return 0
LUA;

    private const MARK_AUDIT_COMPLETED_IN_JOB_LUA = <<<'LUA'
-- KEYS[1]=job:{id}:state KEYS[2]=telemetry KEYS[3]=job:{id}:audits
-- ARGV: 1=auditId 2=auditStatus 3=nowIso 4=ttl(sin uso) 5=durationMs 6=failedStage 7=nowMs
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local jobId = string.match(KEYS[1], 'job:([^:]+):state$') or ''
local auditId = ARGV[1]
local auditStatus = ARGV[2]
local now = ARGV[3]
local auditDurationMs = math.max(0, tonumber(ARGV[5]) or 0)
local failedStage = ARGV[6]
local nowMs = tonumber(ARGV[7]) or 0
local oldJobStatus = tostring(job['status'] or 'pending')
local jobTerminal = oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors'
    or oldJobStatus == 'failed' or job['reaping'] == true
    or (job['stale_reason'] ~= nil and job['stale_reason'] ~= cjson.null)

-- W-F2-I1: durante reaping el reaper es el único que contabiliza las entradas restantes
-- (evita re-escaneo O(N) en la rebanada final y la carrera done + failed < total)
if job['reaping'] == true then return 1 end   -- ANTES de tocar KEYS[3] o audits_in_flight

local entryRaw = redis.call('HGET', KEYS[3], auditId)
if not entryRaw then
    return 0
end

local auditState = cjson.decode(entryRaw)
local previousStatus = tostring(auditState['status'] or '')
local wasTerminal = previousStatus == 'completed'
or previousStatus == 'manual_review'
or previousStatus == 'error'
or previousStatus == 'failed'

if wasTerminal then
    return 1
end

auditState['status'] = auditStatus

if (auditStatus == 'failed' or auditStatus == 'manual_review') and failedStage and failedStage ~= '' then
    auditState['failed_stage'] = failedStage
end

redis.call('HSET', KEYS[3], auditId, cjson.encode(auditState))

-- I7: la entrada pasa de no terminal a terminal: decrementar audits_in_flight SIEMPRE (también con el job ya cerrado
-- por DLQ o reaper, reaping == nil) para no fugar el contador.
redis.call('HINCRBY', KEYS[2], 'audits_in_flight', -1)

-- INV-08: una auditoría tardía no muta status, contadores del job, telemetría de jobs ni jobs:active:nit.
if jobTerminal then return 1 end

if auditStatus == 'failed' then
    job['failed'] = (tonumber(job['failed']) or 0) + 1
else
    job['done'] = (tonumber(job['done']) or 0) + 1
end

local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0
local sealed = job['sealed'] == true

if oldJobStatus ~= 'failed' then
    if sealed and processed >= total then
        if (tonumber(job['failed']) or 0) > 0 then
            job['status'] = 'completed_with_errors'
        else
            job['status'] = 'completed'
        end
    else
        job['status'] = 'processing'
    end
end

job['accumulated_duration_ms'] = (tonumber(job['accumulated_duration_ms']) or 0) + auditDurationMs

if processed > 0 then
    job['avg_duration_ms'] = math.floor((tonumber(job['accumulated_duration_ms']) or 0) / processed)
end

local newJobStatus = job['status'] or 'pending'
if newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then
    job['completed_at'] = now
    redis.call('HDEL', prefix .. 'jobs:active:nit:' .. tostring(job['fac_nit_sec']), jobId)
end

-- Sincroniza los counters agregados de jobs segun la transicion de estado.
-- El flujo normal es queued->processing->completed*. El caso pending->* solo
-- puede ocurrir en jobs creados antes del commit que introdujo 'queued':
-- se mantiene como fallback para compatibilidad con jobs historicos en Redis.
if oldJobStatus == 'queued' or oldJobStatus == 'pending' then
    -- jobs_active fue incrementado en registerAuditInJob; aqui lo consumimos.
    if newJobStatus == 'processing' then
        redis.call('HINCRBY', KEYS[2], 'jobs_active', -1)
        redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)
    elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then
        redis.call('HINCRBY', KEYS[2], 'jobs_active', -1)
        redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
    elseif newJobStatus == 'failed' then
        redis.call('HINCRBY', KEYS[2], 'jobs_active', -1)
        redis.call('HINCRBY', KEYS[2], 'jobs_failed', 1)
    end
elseif oldJobStatus == 'processing' and (newJobStatus == 'completed' or newJobStatus == 'completed_with_errors') then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
elseif oldJobStatus == 'processing' and newJobStatus == 'failed' then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_failed', 1)
end

job['last_progress_at'] = nowMs
job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job))
return 1
LUA;

    private const CANCEL_UNDISPATCHED_AUDITS_LUA = <<<'LUA'
-- KEYS: 1=job:{id}:state 2=job:{id}:by_dispensa 3=job:{id}:audits 4=telemetry
-- ARGV: 1=cursor 2=failedStage 3=nowIso
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local raw = redis.call('GET', KEYS[1])
if not raw then return {'0', 0} end
local job = cjson.decode(raw)
if tostring(job.status or '') ~= 'failed' then return {'0', 0} end
local jobId = tostring(job.job_id or string.match(KEYS[1], 'job:([^:]+):state$') or '')

local scan = redis.call('HSCAN', KEYS[2], ARGV[1], 'COUNT', 500)
local nextCursor = scan[1]
local items = scan[2]
local cancelled = 0
local failedAdded = 0
for i = 1, #items, 2 do
    local ddn = items[i]
    local okDep, dep = pcall(cjson.decode, items[i + 1])
    if okDep and type(dep) == 'table' and dep.dispatched ~= true then
        local auditId = tostring(dep.audit_id or '')
        if auditId ~= '' then
            -- audit:{id}:state: solo si pertenece al job y no es terminal (documents y demás campos intactos).
            local stateKey = prefix .. 'audit:' .. auditId .. ':state'
            local stateRaw = redis.call('GET', stateKey)
            if stateRaw then
                local okState, audit = pcall(cjson.decode, stateRaw)
                if okState and type(audit) == 'table' and tostring(audit.job_id or '') == jobId
                   and not auditEntryTerminal(audit.status) then
                    audit.status = 'failed'
                    audit.failed_stage = ARGV[2]
                    audit.completed_at = ARGV[3]
                    audit.updated_at = ARGV[3]
                    redis.call('SET', stateKey, cjson.encode(audit))
                end
            end
            -- Entrada del hash del job.
            local entryRaw = redis.call('HGET', KEYS[3], auditId)
            if entryRaw then
                local okEntry, entry = pcall(cjson.decode, entryRaw)
                if okEntry and type(entry) == 'table' and not auditEntryTerminal(entry.status) then
                    entry.status = 'failed'
                    entry.failed_stage = ARGV[2]
                    redis.call('HSET', KEYS[3], auditId, cjson.encode(entry))
                    redis.call('HINCRBY', KEYS[4], 'audits_in_flight', -1)
                    failedAdded = failedAdded + 1
                    cancelled = cancelled + 1
                end
            end
            -- Reserva: solo si pertenece a este job y a esta auditoría.
            local reservationKey = prefix .. 'audit:reservation:disdetnro:' .. ddn
            local reservationRaw = redis.call('GET', reservationKey)
            if reservationRaw then
                local okRes, reservation = pcall(cjson.decode, reservationRaw)
                if okRes and type(reservation) == 'table' and tostring(reservation.job_id or '') == jobId
                   and tostring(reservation.audit_id or '') == auditId then
                    redis.call('DEL', reservationKey)
                end
            end
        end
    end
end
if failedAdded > 0 then
    local total = tonumber(job.total) or 0
    local done = tonumber(job.done) or 0
    job.failed = math.min((tonumber(job.failed) or 0) + failedAdded, math.max(0, total - done))
    job.updated_at = ARGV[3]
    redis.call('SET', KEYS[1], cjson.encode(job))
end
return {nextCursor, cancelled}
LUA;

    private const REAP_STALE_JOB_LUA = <<<'LUA'
-- KEYS: 1=job:{id}:state 2=job:{id}:audits 3=jobs:active:nit:{nit} 4=telemetry 5=job:{id}:candidates 6=batch:claim:{id}
-- ARGV: 1=nowMs 2=thresholdMs 3=reason 4=cursor 5=nowIso 6=fallbackUpdatedAtMs
-- Retorno: {code, cursor, cancelled}  code: 1 = job cerrado, 0 = quedan rebanadas, -1 = sin acción.
local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''
local raw = redis.call('GET', KEYS[1])
if not raw then return {-1, '0', 0} end
local job = cjson.decode(raw)
local jobId = tostring(job.job_id or string.match(KEYS[1], 'job:([^:]+):state$') or '')
local oldStatus = tostring(job.status or 'pending')
if oldStatus == 'completed' or oldStatus == 'completed_with_errors' or oldStatus == 'failed' then return {-1, '0', 0} end
-- KEYS[3] debe corresponder al NIT del job (el llamador lo lee de getJobSummary()).
if string.match(KEYS[3], 'jobs:active:nit:(.*)$') ~= tostring(job.fac_nit_sec) then return {-1, '0', 0} end

local cursor = tostring(ARGV[4] or '0')
if job.reaping ~= true then
    -- 1.ª rebanada: comprueba el propietario de generación vivo (siempre, también con umbral 0) y el progreso.
    if redis.call('EXISTS', KEYS[6]) == 1 then return {-1, '0', 0} end
    local nowMs = tonumber(ARGV[1]) or 0
    local threshold = tonumber(ARGV[2]) or 0
    if threshold > 0 then
        local lp = tonumber(job.last_progress_at) or 0
        if lp <= 0 then lp = tonumber(ARGV[6]) or 0 end
        if lp <= 0 or (nowMs - lp) < threshold then return {-1, '0', 0} end  -- sin marca conocida: el reaper automático no actúa
    end
    -- threshold == 0 (repair-jobs --force-stale): el operador puede cerrar un job sin marca de progreso válida
    job.reaping = true
    job.generation_epoch = (tonumber(job.generation_epoch) or 0) + 1   -- W-F2-B3: invalida escrituras pendientes
    cursor = '0'
end

local scan = redis.call('HSCAN', KEYS[2], cursor, 'COUNT', 500)
local nextCursor = scan[1]
local items = scan[2]
local cancelled = 0
for i = 1, #items, 2 do
    local auditId = items[i]
    local okEntry, entry = pcall(cjson.decode, items[i + 1])
    if okEntry and type(entry) == 'table' and not auditEntryTerminal(entry.status) then
        entry.status = 'failed'
        entry.failed_stage = 'stale_no_progress'
        redis.call('HSET', KEYS[2], auditId, cjson.encode(entry))
        cancelled = cancelled + 1
        -- audit:{id}:state (conserva documents y demás campos; sin EX).
        local stateKey = prefix .. 'audit:' .. auditId .. ':state'
        local stateRaw = redis.call('GET', stateKey)
        if stateRaw then
            local okState, audit = pcall(cjson.decode, stateRaw)
            if okState and type(audit) == 'table' and tostring(audit.job_id or '') == jobId
               and not auditEntryTerminal(audit.status) then
                audit.status = 'failed'
                audit.failed_stage = 'stale_no_progress'
                audit.completed_at = ARGV[5]
                audit.updated_at = ARGV[5]
                redis.call('SET', stateKey, cjson.encode(audit))
            end
        end
        -- Reserva del job.
        local ddn = tostring(entry.dis_det_nro or '')
        if ddn ~= '' then
            local reservationKey = prefix .. 'audit:reservation:disdetnro:' .. ddn
            local reservationRaw = redis.call('GET', reservationKey)
            if reservationRaw then
                local okRes, reservation = pcall(cjson.decode, reservationRaw)
                if okRes and type(reservation) == 'table' and tostring(reservation.job_id or '') == jobId then
                    redis.call('DEL', reservationKey)
                end
            end
        end
        redis.call('HINCRBY', KEYS[4], 'audits_in_flight', -1)
    end
end
job.failed = (tonumber(job.failed) or 0) + cancelled
job.updated_at = ARGV[5]

if nextCursor ~= '0' then
    redis.call('SET', KEYS[1], cjson.encode(job))
    return {0, nextCursor, cancelled}
end

-- Rebanada final (W-F2-I1): sin re-escaneo; cada entrada no terminal fue contabilizada exactamente una vez.
local total = redis.call('HLEN', KEYS[2])
local done = tonumber(job.done) or 0
job.total = total
job.accepted = total
if (tonumber(job.failed) or 0) > math.max(0, total - done) then
    job.failed = math.max(0, total - done)
end
job.status = 'failed'
job.sealed = true
job.stale_reason = ARGV[3]
job.completed_at = ARGV[5]
job.updated_at = ARGV[5]
job.reaping = nil
job.error_message = ARGV[3]
redis.call('HDEL', KEYS[3], jobId)
redis.call('DEL', KEYS[5])
leaveStatusCounter(KEYS[4], oldStatus)
redis.call('HINCRBY', KEYS[4], 'jobs_failed', 1)
redis.call('SET', KEYS[1], cjson.encode(job))   -- sin EX (INV-07)
return {1, '0', cancelled}
LUA;

    private const RELEASE_AUDIT_RESERVATION_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local reservation = cjson.decode(raw)
if tostring(reservation['token'] or '') ~= tostring(ARGV[1] or '') then
    return 0
end

redis.call('DEL', KEYS[1])
return 1
LUA;

    private const LIST_JOBS_LUA = <<<'LUA'
local indexKey = KEYS[1]
local limit = tonumber(ARGV[1]) or 50
local jobIds = redis.call('ZREVRANGE', indexKey, 0, limit - 1)
local rawList = {}

local prefix = string.match(indexKey, "^(.*)jobs:index$") or ""

if #jobIds > 0 then
    for i = 1, #jobIds do
        local raw = redis.call('GET', prefix .. 'job:' .. tostring(jobIds[i]) .. ':state')
        if raw then
            rawList[#rawList + 1] = raw
        end
    end
else
    local foundKeys = redis.call('KEYS', prefix .. 'job:*:state')
    for i = 1, math.min(#foundKeys, limit) do
        local raw = redis.call('GET', foundKeys[i])
        if raw then
            rawList[#rawList + 1] = raw
        end
    end
end

local results = {}
for i = 1, #rawList do
    local job = cjson.decode(rawList[i])
    if job and type(job) == 'table' then
        local total = tonumber(job['total']) or 0
        local done = tonumber(job['done']) or 0
        local failed = tonumber(job['failed']) or 0
        local pending = math.max(0, total - done - failed)
        local progress = 0
        if total > 0 then
            progress = math.floor(((done + failed) / total) * 100)
        end
        local accDur = tonumber(job['accumulated_duration_ms']) or 0
        local avgDur = tonumber(job['avg_duration_ms']) or 0

        results[#results + 1] = {
            job_id = tostring(job['job_id'] or ''),
            fac_nit_sec = tonumber(job['fac_nit_sec']) or 0,
            status = tostring(job['status'] or 'pending'),
            total = total,
            done = done,
            failed = failed,
            pending = pending,
            progress_percent = progress,
            avg_duration_ms = avgDur,
            accumulated_duration_ms = accDur,
            created_at = tostring(job['created_at'] or ''),
            updated_at = tostring(job['updated_at'] or ''),
            completed_at = job['completed_at'],
            date_from = tostring(job['date_from'] or ''),
            date_to = tostring(job['date_to'] or '')
        }
    end
end

return cjson.encode(results)
LUA;
}
