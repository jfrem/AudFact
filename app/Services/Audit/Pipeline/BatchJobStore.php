<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class BatchJobStore
{
    use JsonRedisStoreTrait;

    public const JOB_STATUS_PENDING             = 'pending';
    public const JOB_STATUS_PROCESSING          = 'processing';
    public const JOB_STATUS_COMPLETED           = 'completed';
    public const JOB_STATUS_COMPLETED_WITH_ERR  = 'completed_with_errors';
    public const JOB_STATUS_FAILED              = 'failed';

    private const DEFAULT_JOB_TTL_SECONDS = 604800;
    private const DEFAULT_RESERVATION_TTL_SECONDS = 86400;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    private static function jobTtlSeconds(): int
    {
        return self::positiveIntEnv('AUDIT_JOB_TTL', self::DEFAULT_JOB_TTL_SECONDS);
    }

    private static function reservationTtlSeconds(): int
    {
        return self::positiveIntEnv('AUDIT_RESERVATION_TTL', self::DEFAULT_RESERVATION_TTL_SECONDS);
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

    /**
     * Inicializa el estado Redis de un job batch.
     */
    public function initJob(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit
    ): bool {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $state = [
            'job_id'      => $jobId,
            'status'      => self::JOB_STATUS_PENDING,
            'fac_nit_sec' => $facNitSec,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'limit'       => $limit,
            'sealed'      => false,
            'total'       => 0,
            'done'        => 0,
            'failed'      => 0,
            'accepted'    => 0,
            'skipped_locked' => 0,
            'skipped_existing' => 0,
            'accumulated_duration_ms' => 0,
            'avg_duration_ms' => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
            'audits'      => new \stdClass(),
        ];

        $success = $this->redis->setnx(self::jobKey($jobId), self::encodeJson($state, 'BatchJobStore'), self::jobTtlSeconds());

        if ($success) {
            try {
                $this->redis->hIncrBy('telemetry:async_metrics', 'jobs_queued', 1);
                $this->redis->eval(
                    "redis.call('ZADD', KEYS[1], tonumber(ARGV[1]), ARGV[2])",
                    ['jobs:index'],
                    [(string) time(), $jobId]
                );
            } catch (\Throwable $e) {
                Logger::warning('BatchJobStore: No se pudo incrementar jobs_queued o indexar job', ['error' => $e->getMessage()]);
            }
        }

        return $success;
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

            return $decoded;
        } catch (\Throwable $e) {
            Logger::error('BatchJobStore::listJobs falló', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getJob(string $jobId): ?array
    {
        try {
            $raw = $this->redis->get(self::jobKey($jobId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer job', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'BatchJobStore');
    }

    /**
     * Registra una auditoría dentro del job batch junto con su identidad global.
     *
     * @param  string  $jobId  UUID del job batch.
     * @param  string  $auditId  UUID de la auditoría.
     * @param  string  $disDetNro  Identificador operativo de la dispensa.
     * @param  string|null  $disId  Identificador global idempotente de la factura.
     * @param  string|null  $reservationToken  Token propietario de la reserva Redis.
     */
    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $disId = null,
        ?string $reservationToken = null
    ): bool {
        return $this->runScript(
            self::REGISTER_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [
                $auditId,
                $disDetNro,
                $disId ?? '',
                $reservationToken ?? '',
                gmdate('Y-m-d\TH:i:s\Z'),
                self::jobTtlSeconds(),
            ],
            'No se pudo registrar auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    public function deleteJob(string $jobId): bool
    {
        return $this->redis->del(self::jobKey($jobId));
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
     * Sella el job cuando ya no se registrarán más auditorías.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function sealJob(string $jobId, int $total, array $metadata = []): bool
    {
        $patch = array_merge($metadata, [
            'sealed' => true,
            'total' => max(0, $total),
        ]);

        return $this->patchJob($jobId, $patch);
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
            [self::jobKey($jobId), 'telemetry:async_metrics'],
            [$auditId, $status, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds(), max(0, $durationMs), $failedStage ?? ''],
            'No se pudo actualizar el progreso del job en Redis',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Reclama la publicación única del evento terminal de un job ya sellado.
     *
     * @param  string  $jobId  UUID del job batch.
     * @param  string  $eventType  Tipo de evento terminal a publicar.
     */
    public function claimBatchTerminalEvent(string $jobId, string $eventType): bool
    {
        return $this->runScript(
            self::CLAIM_BATCH_TERMINAL_EVENT_LUA,
            [self::jobKey($jobId)],
            [$eventType, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo reclamar el evento terminal del batch en Redis',
            ['job_id' => $jobId, 'event_type' => $eventType]
        );
    }

    /**
     * Intenta reclamar una llave de idempotencia por cliente (X-Idempotency-Key).
     *
     * Usa SETNX atómico: si la llave no existe, la crea con el job_id y TTL.
     * Si ya existe, lee y retorna el job_id del primer reclamante.
     *
     * @param  string  $key    Valor del header X-Idempotency-Key (o UUID auto-generado)
     * @param  string  $jobId  UUID v4 del job nuevo a registrar
     * @param  int     $ttl    Segundos de vida de la llave (AUDIT_IDEMPOTENCY_KEY_TTL)
     *
     * @return string|null  null → llave reclamada exitosamente (primera solicitud)
     *                      string → job_id existente (solicitud duplicada → 409)
     *
     * @throws RuntimeException Si Redis no está disponible
     */
    public function claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string
    {
        $redisKey = self::idempotencyKey($key);

        try {
            $claimed = $this->redis->setnx($redisKey, $jobId, $ttl);
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al reclamar llave de idempotencia', 0, $e);
        }

        if ($claimed) {
            return null; // primera solicitud
        }

        // Duplicado: leer el job_id del primer reclamante
        try {
            $existingJobId = $this->redis->get($redisKey);
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer llave de idempotencia', 0, $e);
        }

        return $existingJobId;
    }

    /**
     * Construye la key Redis de idempotencia por cliente.
     */
    public static function idempotencyKey(string $key): string
    {
        return "batch:idem:{$key}";
    }

    /**
     *
     * @param  array<string,mixed>  $reservation
     */
    public function claimAuditReservation(string $disId, string $ownerToken, array $reservation, ?int $ttl = null): bool
    {
        $reservation['dis_id'] = $disId;
        $reservation['token'] = $ownerToken;
        $reservation['claimed_at'] = $reservation['claimed_at'] ?? gmdate('Y-m-d\TH:i:s\Z');
        $ttlSeconds = ($ttl !== null && $ttl > 0) ? $ttl : self::reservationTtlSeconds();

        return $this->redis->setnx(
            self::auditReservationKey($disId),
            self::encodeJson($reservation, 'BatchJobStore::claimAuditReservation'),
            $ttlSeconds
        );
    }

    /**
     * Lee la reserva activa de auditoría por DisId.
     *
     * @param  string  $disId  Identificador global idempotente de la factura.
     * @return array<string,mixed>|null
     */
    public function getAuditReservation(string $disId): ?array
    {
        try {
            $raw = $this->redis->get(self::auditReservationKey($disId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer reserva de auditoría', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'BatchJobStore::getAuditReservation');
    }

    /**
     * Libera una reserva de auditoría solo si el token coincide con el propietario.
     *
     * @param  string  $disId  Identificador global idempotente de la factura.
     * @param  string  $ownerToken  Token propietario de la reserva Redis.
     */
    public function releaseAuditReservation(string $disId, string $ownerToken): bool
    {
        if ($disId === '' || $ownerToken === '') {
            return false;
        }

        return $this->runScript(
            self::RELEASE_AUDIT_RESERVATION_LUA,
            [self::auditReservationKey($disId)],
            [$ownerToken],
            'No se pudo liberar reserva de auditoría',
            ['dis_id' => $disId]
        );
    }

    /**
     * Libera la reserva asociada a un estado de auditoría.
     *
     * @param  array<string,mixed>  $audit
     */
    public function releaseAuditReservationFromAudit(array $audit): bool
    {
        $disId = trim((string) ($audit['dis_id'] ?? ''));
        $token = trim((string) ($audit['reservation_token'] ?? ''));

        return $this->releaseAuditReservation($disId, $token);
    }

    public static function jobKey(string $jobId): string
    {
        return "job:{$jobId}:state";
    }

    /**
     * Construye la key Redis de reserva global por DisId.
     *
     * @param  string  $disId  Identificador global idempotente de la factura.
     */
    public static function auditReservationKey(string $disId): string
    {
        return "audit:reservation:disid:{$disId}";
    }

    private const REGISTER_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local auditId = ARGV[1]
local disDetNro = ARGV[2]
local disId = ARGV[3]
local reservationToken = ARGV[4]
local now = ARGV[5]
local ttl = tonumber(ARGV[6])

if type(job['audits']) ~= 'table' then
    job['audits'] = {}
end
job['audits'][auditId] = {
    dis_det_nro = disDetNro,
    dis_id = disId,
    reservation_token = reservationToken,
    status = 'pending'
}
job['total'] = (tonumber(job['total']) or 0) + 1
job['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const MARK_AUDIT_COMPLETED_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local auditStatus = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])
local auditDurationMs = math.max(0, tonumber(ARGV[5]) or 0)
local failedStage = ARGV[6]
local oldJobStatus = tostring(job['status'] or 'pending')

if type(job['audits']) ~= 'table' or type(job['audits'][auditId]) ~= 'table' then
    return 0
end

local auditState = job['audits'][auditId]
local previousStatus = tostring(auditState['status'] or '')
local wasTerminal = previousStatus == 'completed'
or previousStatus == 'manual_review'
or previousStatus == 'error'
or previousStatus == 'failed'

if wasTerminal then
    return 1
end

auditState['status'] = auditStatus
auditState['completed_at'] = now
auditState['duration_ms'] = auditDurationMs

if (auditStatus == 'failed' or auditStatus == 'manual_review') and failedStage and failedStage ~= '' then
    auditState['failed_stage'] = failedStage
end

job['audits'][auditId] = auditState

if auditStatus == 'failed' then
    job['failed'] = (tonumber(job['failed']) or 0) + 1
else
    job['done'] = (tonumber(job['done']) or 0) + 1
end

local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0
local sealed = job['sealed'] == true

if sealed and processed >= total and total > 0 then
    if (tonumber(job['failed']) or 0) > 0 then
        job['status'] = 'completed_with_errors'
    else
        job['status'] = 'completed'
    end
else
    job['status'] = 'processing'
end

job['accumulated_duration_ms'] = (tonumber(job['accumulated_duration_ms']) or 0) + auditDurationMs

if processed > 0 then
    job['avg_duration_ms'] = math.floor((tonumber(job['accumulated_duration_ms']) or 0) / processed)
end

local newJobStatus = job['status'] or 'pending'

if oldJobStatus == 'pending' then
    redis.call('HINCRBY', KEYS[2], 'jobs_queued', -1)
    if newJobStatus == 'processing' then
        redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)
    elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then
        redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
    elseif newJobStatus == 'failed' then
        redis.call('HINCRBY', KEYS[2], 'jobs_failed', 1)
    end
elseif oldJobStatus == 'processing' and (newJobStatus == 'completed' or newJobStatus == 'completed_with_errors') then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
elseif oldJobStatus == 'processing' and newJobStatus == 'failed' then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_failed', 1)
end

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const CLAIM_BATCH_TERMINAL_EVENT_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
if job['sealed'] ~= true then
    return 0
end
if tostring(job['batch_event_published'] or '') ~= '' then
    return 2
end

job['batch_event_published'] = ARGV[1]
job['updated_at'] = ARGV[2]
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', tonumber(ARGV[3]))
return 1
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

local prefix = string.match(indexKey, "^(.*:)jobs:index$") or "audfact:"

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
        local throughput = 0
        local processed = done + failed
        if processed > 0 and accDur > 0 then
            throughput = math.floor((processed / (accDur / 1000)) * 100) / 100
        end

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
            throughput_per_sec = throughput,
            created_at = tostring(job['created_at'] or ''),
            updated_at = tostring(job['updated_at'] or ''),
            date_from = tostring(job['date_from'] or ''),
            date_to = tostring(job['date_to'] or '')
        }
    end
end

return cjson.encode(results)
LUA;
}
