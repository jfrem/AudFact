<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

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

    private const JOB_TTL_SECONDS   = 86400;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
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

        return $this->redis->setnx(self::jobKey($jobId), self::encodeJson($state, 'BatchJobStore'), self::JOB_TTL_SECONDS);
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
     * @param  string|null  $facSec  Identificador global idempotente de la factura.
     * @param  string|null  $reservationToken  Token propietario de la reserva Redis.
     */
    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $facSec = null,
        ?string $reservationToken = null
    ): bool {
        return $this->runScript(
            self::REGISTER_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [
                $auditId,
                $disDetNro,
                $facSec ?? '',
                $reservationToken ?? '',
                gmdate('Y-m-d\TH:i:s\Z'),
                self::JOB_TTL_SECONDS,
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
            [$patch, self::JOB_TTL_SECONDS],
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
     * @param  string  $auditStatus  Estado terminal de la auditoría.
     * @param  int  $auditDurationMs  Duración activa de la auditoría en milisegundos.
     */
    public function markAuditCompletedInJob(
        string $jobId,
        string $auditId,
        string $auditStatus,
        int $auditDurationMs = 0
    ): bool {
        return $this->runScript(
            self::MARK_AUDIT_COMPLETED_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [$auditId, $auditStatus, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS, max(0, $auditDurationMs)],
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
            [$eventType, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS],
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
    public function claimAuditReservation(string $facSec, string $ownerToken, array $reservation, int $ttl = self::JOB_TTL_SECONDS): bool
    {
        $reservation['fac_sec'] = $facSec;
        $reservation['token'] = $ownerToken;
        $reservation['claimed_at'] = $reservation['claimed_at'] ?? gmdate('Y-m-d\TH:i:s\Z');

        return $this->redis->setnx(
            self::auditReservationKey($facSec),
            self::encodeJson($reservation, 'BatchJobStore::claimAuditReservation'),
            $ttl
        );
    }

    /**
     * Lee la reserva activa de auditoría por FacSec.
     *
     * @param  string  $facSec  Identificador global idempotente de la factura.
     * @return array<string,mixed>|null
     */
    public function getAuditReservation(string $facSec): ?array
    {
        try {
            $raw = $this->redis->get(self::auditReservationKey($facSec));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer reserva de auditoría', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'BatchJobStore::getAuditReservation');
    }

    /**
     * Libera una reserva de auditoría solo si el token coincide con el propietario.
     *
     * @param  string  $facSec  Identificador global idempotente de la factura.
     * @param  string  $ownerToken  Token propietario de la reserva Redis.
     */
    public function releaseAuditReservation(string $facSec, string $ownerToken): bool
    {
        if ($facSec === '' || $ownerToken === '') {
            return false;
        }

        return $this->runScript(
            self::RELEASE_AUDIT_RESERVATION_LUA,
            [self::auditReservationKey($facSec)],
            [$ownerToken],
            'No se pudo liberar reserva de auditoría',
            ['fac_sec' => $facSec]
        );
    }

    /**
     * Libera la reserva asociada a un estado de auditoría.
     *
     * @param  array<string,mixed>  $audit
     */
    public function releaseAuditReservationFromAudit(array $audit): bool
    {
        $facSec = trim((string) ($audit['fac_sec'] ?? ''));
        $token = trim((string) ($audit['reservation_token'] ?? ''));

        return $this->releaseAuditReservation($facSec, $token);
    }

    public static function jobKey(string $jobId): string
    {
        return "job:{$jobId}:state";
    }

    /**
     * Construye la key Redis de reserva global por FacSec.
     *
     * @param  string  $facSec  Identificador global idempotente de la factura.
     */
    public static function auditReservationKey(string $facSec): string
    {
        return "audit:reservation:facsec:{$facSec}";
    }

    private const REGISTER_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local auditId = ARGV[1]
local disDetNro = ARGV[2]
local facSec = ARGV[3]
local reservationToken = ARGV[4]
local now = ARGV[5]
local ttl = tonumber(ARGV[6])

if type(job['audits']) ~= 'table' then
    job['audits'] = {}
end
job['audits'][auditId] = {
    dis_det_nro = disDetNro,
    fac_sec = facSec,
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
}
