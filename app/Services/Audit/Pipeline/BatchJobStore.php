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
     * Lista los IDs de jobs batch indexados en el Sorted Set mediante ZSCAN cursor seguro (QUAL-004).
     *
     * @param int $limit Cantidad máxima de IDs a examinar por iteración (1..100)
     * @param string $cursor Cursor para ZSCAN ('0' para iniciar)
     * @return array{cursor: string, job_ids: array<int, string>}
     */
    public function listJobIds(int $limit = 50, string $cursor = '0'): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $rawJson = $this->redis->eval(
                self::LIST_JOB_IDS_LUA,
                ['jobs:index'],
                [$cursor, (string) $limit]
            );

            if (!is_string($rawJson) || $rawJson === '') {
                return ['cursor' => '0', 'job_ids' => []];
            }

            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                return ['cursor' => '0', 'job_ids' => []];
            }

            return [
                'cursor' => (string) ($decoded['cursor'] ?? '0'),
                'job_ids' => array_values(array_map('strval', (array) ($decoded['job_ids'] ?? []))),
            ];
        } catch (\Throwable $e) {
            Logger::error('BatchJobStore::listJobIds falló', ['error' => $e->getMessage()]);
            return ['cursor' => '0', 'job_ids' => []];
        }
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

            usort($decoded, static fn(array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

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
     * @param  string|null  $eventId  UUID estable del evento de auditoría.
     */
    public function registerAuditInJob(
        string $jobId,
        string $auditId,
        string $disDetNro,
        ?string $disId = null,
        ?string $reservationToken = null,
        ?string $eventId = null
    ): bool {
        return $this->runScript(
            self::REGISTER_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [
                $auditId,
                $disDetNro,
                $disId ?? '',
                $reservationToken ?? '',
                $eventId ?? '',
                gmdate('Y-m-d\TH:i:s\Z'),
                self::jobTtlSeconds(),
            ],
            'No se pudo registrar auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Marca una auditoría del job como efectivamente publicada en Redis Streams (QUAL-004).
     */
    public function markAuditPublishedInJob(
        string $jobId,
        string $auditId,
        string $streamId = ''
    ): bool {
        return $this->runScript(
            self::MARK_AUDIT_PUBLISHED_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [
                $auditId,
                $streamId,
                gmdate('Y-m-d\TH:i:s\Z'),
                self::jobTtlSeconds(),
            ],
            'No se pudo marcar auditoría como publicada en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    public function deleteJob(string $jobId): bool
    {
        try {
            $result = $this->redis->eval(
                self::$DELETE_JOB_LUA,
                [self::jobKey($jobId), 'jobs:index'],
                [$jobId]
            );
            return (int) $result > 0;
        } catch (\Throwable $e) {
            Logger::error('BatchJobStore::deleteJob falló', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            return false;
        }
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
     * Reabre una auditoría dentro de un job para reproceso DLQ (QUAL-018).
     *
     * Transiciona el estado de la auditoría de 'failed'/'error' a 'processing' en el job,
     * decrementa el contador 'failed' del job si estaba en failed, y actualiza el event_id.
     */
    public function reopenAuditInJob(string $jobId, string $auditId, string $newEventId): bool
    {
        return $this->runScript(
            self::REOPEN_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId), 'telemetry:async_metrics'],
            [$auditId, $newEventId, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo reabrir la auditoría en el job para reproceso',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Revierte la reapertura de una auditoría en el job tras fallo de publicación (QUAL-001).
     *
     * Restaura el audit status a 'failed', re-incrementa contadores,
     * recalcula estado terminal del job y ajusta métricas globales.
     */
    public function revertAuditReprocessInJob(string $jobId, string $auditId): bool
    {
        return $this->runScript(
            self::REVERT_AUDIT_REPROCESS_IN_JOB_LUA,
            [self::jobKey($jobId), 'telemetry:async_metrics'],
            [$auditId, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo revertir la reapertura de auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Reconcilia atómicamente una auditoría que falló en publicación o enrolamiento (QUAL-015):
     * 1. Elimina el estado de la auditoría en Redis (audit:state).
     * 2. Libera la reserva si el token de propietario coincide.
     * 3. Marca la auditoría en el job como 'failed' y actualiza contadores/métricas de forma atómica.
     *
     * @param string $jobId UUID del job.
     * @param string $auditId UUID de la auditoría.
     * @param string $disId Identificador global de la dispensa.
     * @param string $ownerToken Token de la reserva activa.
     * @param string|null $failedStage Etapa donde ocurrió el fallo.
     */
    public function reconcileFailedAuditInJob(
        string $jobId,
        string $auditId,
        string $disId,
        string $ownerToken,
        ?string $failedStage = null
    ): bool {
        if ($jobId === '' || $auditId === '') {
            return false;
        }

        return $this->runScript(
            self::RECONCILE_FAILED_AUDIT_IN_JOB_LUA,
            [
                self::jobKey($jobId),
                AuditStateStore::auditKey($auditId),
                self::auditReservationKey($disId),
                'telemetry:async_metrics',
            ],
            [
                $auditId,
                $ownerToken,
                gmdate('Y-m-d\TH:i:s\Z'),
                self::jobTtlSeconds(),
                $failedStage ?? '',
            ],
            'No se pudo reconciliar la auditoría fallida en el job en Redis',
            ['job_id' => $jobId, 'audit_id' => $auditId, 'dis_id' => $disId]
        );
    }

    /**
     * Aplica un patch a una auditoría específica dentro de un job batch (QUAL-015).
     *
     * @param array<string,mixed> $patch
     */
    public function patchAuditInJob(string $jobId, string $auditId, array $patch): bool
    {
        if ($jobId === '' || $auditId === '') {
            return false;
        }

        return $this->runScript(
            self::PATCH_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [$auditId, self::encodeJson($patch, 'BatchJobStore::patchAuditInJob'), gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo aplicar patch a la auditoría en el job en Redis',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    /**
     * Reclama la publicación única del evento terminal de un job ya sellado (QUAL-010).
     *
     * @param  string  $jobId       UUID del job batch.
     * @param  string  $eventType   Tipo de evento terminal a publicar.
     * @param  string  $claimToken  Token único del llamador (si se omite, se autogenera).
     */
    public function claimBatchTerminalEvent(string $jobId, string $eventType, string $claimToken = '', int $claimTtlSeconds = 120): bool
    {
        $token = $claimToken !== '' ? $claimToken : bin2hex(random_bytes(8));

        return $this->runScript(
            self::CLAIM_BATCH_TERMINAL_EVENT_LUA,
            [self::jobKey($jobId)],
            [$token, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds(), $claimTtlSeconds, time()],
            'No se pudo reclamar el evento terminal del batch en Redis',
            ['job_id' => $jobId, 'event_type' => $eventType, 'claim_token' => $token]
        );
    }

    /**
     * Confirma la publicación exitosa del evento terminal batch mediante CAS (QUAL-010).
     */
    public function confirmBatchTerminalEvent(string $jobId, string $eventType, string $claimToken): bool
    {
        return $this->runScript(
            self::CONFIRM_BATCH_TERMINAL_EVENT_LUA,
            [self::jobKey($jobId)],
            [$claimToken, $eventType, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo confirmar la publicación terminal del batch en Redis',
            ['job_id' => $jobId, 'event_type' => $eventType, 'claim_token' => $claimToken]
        );
    }

    /**
     * Libera el reclamo de evento terminal si la publicación falló (Rollback CAS - QUAL-010).
     */
    public function releaseBatchTerminalEvent(string $jobId, string $claimToken): bool
    {
        return $this->runScript(
            self::RELEASE_BATCH_TERMINAL_EVENT_LUA,
            [self::jobKey($jobId)],
            [$claimToken, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo liberar el claim del evento terminal del batch en Redis',
            ['job_id' => $jobId, 'claim_token' => $claimToken]
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
     * Libera una llave de idempotencia si una preparación preliminar falla o es descartada.
     */
    public function releaseIdempotencyKey(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        try {
            return (bool) $this->redis->del(self::idempotencyKey($key));
        } catch (\Throwable $e) {
            Logger::error('BatchJobStore::releaseIdempotencyKey falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
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
            ['dis_id' => $disId],
            acceptValues: [1, 2]
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

    /**
     * Construye la key Redis de lock distribuido de generación por Job ID.
     */
    public static function jobGenerationLockKey(string $jobId): string
    {
        return "batch:claim:{$jobId}";
    }

    /**
     * Intenta adquirir el lock atómico para la generación de un lote batch.
     *
     * @param  string  $jobId     UUID del job batch.
     * @param  string  $workerId  Identificador/token del worker solicitante.
     * @param  int     $ttlSeconds Tiempo de vida del lock (default 1800s = 30 min).
     * @return bool    true si adquirió el lock; false si otro worker ya lo tiene.
     */
    public function claimJobGenerationLock(string $jobId, string $workerId, int $ttlSeconds = 1800): bool
    {
        if ($jobId === '' || $workerId === '') {
            return false;
        }

        try {
            return $this->redis->setnx(
                self::jobGenerationLockKey($jobId),
                $workerId,
                max(60, $ttlSeconds)
            );
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al adquirir lock de generación batch', 0, $e);
        }
    }

    /**
     * Libera el lock de generación de lote batch si el token coincide.
     */
    public function releaseJobGenerationLock(string $jobId, string $workerId): bool
    {
        if ($jobId === '' || $workerId === '') {
            return false;
        }

        return $this->runScript(
            self::RELEASE_JOB_GENERATION_LOCK_LUA,
            [self::jobGenerationLockKey($jobId)],
            [$workerId],
            'No se pudo liberar el lock de generación batch',
            ['job_id' => $jobId]
        );
    }

    private const RELEASE_JOB_GENERATION_LOCK_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

if tostring(raw) ~= tostring(ARGV[1] or '') then
    return 0
end

redis.call('DEL', KEYS[1])
return 1
LUA;

    private const REGISTER_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local auditId = ARGV[1]
local disDetNro = ARGV[2]
local disId = ARGV[3]
local reservationToken = ARGV[4]
local eventId = ARGV[5]
local now = ARGV[6]
local ttl = tonumber(ARGV[7])

if type(job['audits']) ~= 'table' then
    job['audits'] = {}
end
job['audits'][auditId] = {
    dis_det_nro = disDetNro,
    dis_id = disId,
    reservation_token = reservationToken,
    event_id = eventId,
    publication_status = 'pending',
    status = 'pending'
}
job['total'] = (tonumber(job['total']) or 0) + 1
job['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const MARK_AUDIT_PUBLISHED_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local auditId = ARGV[1]
local streamId = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(job['audits']) ~= 'table' or type(job['audits'][auditId]) ~= 'table' then
    return 0
end

local auditState = job['audits'][auditId]
auditState['publication_status'] = 'published'
auditState['published_at'] = now
if streamId and streamId ~= '' then
    auditState['stream_id'] = streamId
end

job['audits'][auditId] = auditState
job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private static string $DELETE_JOB_LUA = <<<'LUA'
local jobKey = KEYS[1]
local indexKey = KEYS[2]
local jobId = ARGV[1]

redis.call('ZREM', indexKey, jobId)
return redis.call('DEL', jobKey)
LUA;

    private const LIST_JOB_IDS_LUA = <<<'LUA'
local indexKey = KEYS[1]
local cursor = ARGV[1] or "0"
local limit = tonumber(ARGV[2]) or 50
local prefix = string.match(indexKey, "^(.*:)jobs:index$") or "audfact:"

local indexExists = redis.call('EXISTS', indexKey)
if indexExists == 1 then
    local scanResult = redis.call('ZSCAN', indexKey, cursor, 'COUNT', limit)
    local nextCursor = tostring(scanResult[1])
    local entries = scanResult[2]
    local jobIds = {}

    for i = 1, #entries, 2 do
        local id = tostring(entries[i])
        local exists = redis.call('EXISTS', prefix .. 'job:' .. id .. ':state')
        if exists == 1 then
            jobIds[#jobIds + 1] = id
        else
            redis.call('ZREM', indexKey, id)
        end
    end

    return cjson.encode({
        cursor = nextCursor,
        job_ids = jobIds
    })
end

local scanResult = redis.call('SCAN', cursor, 'MATCH', prefix .. 'job:*:state', 'COUNT', limit)
local nextCursor = tostring(scanResult[1])
local foundKeys = scanResult[2]
local results = {}

for i = 1, #foundKeys do
    local key = foundKeys[i]
    local id = string.match(key, "job:(.+):state$")
    if id then
        results[#results + 1] = id
    end
end

return cjson.encode({
    cursor = nextCursor,
    job_ids = results
})
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

    private const RECONCILE_FAILED_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local ownerToken = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])
local failedStage = ARGV[5]

if type(job['audits']) ~= 'table' or type(job['audits'][auditId]) ~= 'table' then
    return 0
end

local auditState = job['audits'][auditId]
local previousStatus = tostring(auditState['status'] or '')
local wasTerminal = previousStatus == 'completed'
or previousStatus == 'manual_review'
or previousStatus == 'error'
or previousStatus == 'failed'

-- 1. Eliminar audit state (KEYS[2])
redis.call('DEL', KEYS[2])

-- 2. Liberar reserva si el token coincide (KEYS[3])
local resRaw = redis.call('GET', KEYS[3])
if resRaw then
    local res = cjson.decode(resRaw)
    if tostring(res['token'] or '') == tostring(ownerToken or '') then
        redis.call('DEL', KEYS[3])
    end
end

-- Limpiar metadatos de compensación de forma atómica en auditState (QUAL-015)
auditState['compensation_pending'] = nil
auditState['compensation_dis_id'] = nil
auditState['compensation_token'] = nil

if wasTerminal then
    job['audits'][auditId] = auditState
    job['updated_at'] = now
    redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
    return 1
end

-- 3. Marcar auditoría como failed en el job de forma atómica
auditState['status'] = 'failed'
auditState['publication_status'] = 'failed'
auditState['completed_at'] = now
auditState['duration_ms'] = 0
if failedStage and failedStage ~= '' then
    auditState['failed_stage'] = failedStage
end

job['audits'][auditId] = auditState
job['failed'] = (tonumber(job['failed']) or 0) + 1

local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0
local sealed = job['sealed'] == true

local oldJobStatus = tostring(job['status'] or 'pending')
if sealed and processed >= total and total > 0 then
    if (tonumber(job['failed']) or 0) > 0 then
        job['status'] = 'completed_with_errors'
    else
        job['status'] = 'completed'
    end
else
    job['status'] = 'processing'
end

local newJobStatus = job['status'] or 'pending'

if oldJobStatus == 'pending' then
    redis.call('HINCRBY', KEYS[4], 'jobs_queued', -1)
    if newJobStatus == 'processing' then
        redis.call('HINCRBY', KEYS[4], 'jobs_running', 1)
    elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then
        redis.call('HINCRBY', KEYS[4], 'jobs_completed', 1)
    elseif newJobStatus == 'failed' then
        redis.call('HINCRBY', KEYS[4], 'jobs_failed', 1)
    end
elseif oldJobStatus == 'processing' and (newJobStatus == 'completed' or newJobStatus == 'completed_with_errors') then
    redis.call('HINCRBY', KEYS[4], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[4], 'jobs_completed', 1)
elseif oldJobStatus == 'processing' and newJobStatus == 'failed' then
    redis.call('HINCRBY', KEYS[4], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[4], 'jobs_failed', 1)
end

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const PATCH_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local patch = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(job['audits']) ~= 'table' or type(job['audits'][auditId]) ~= 'table' then
    return 0
end

local auditState = job['audits'][auditId]
for k, v in pairs(patch) do
    auditState[k] = v
end

job['audits'][auditId] = auditState
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

-- QUAL-010: Validar que el job está en estado terminal
local jobStatus = tostring(job['status'] or '')
if jobStatus ~= 'completed' and jobStatus ~= 'completed_with_errors' then
    return 0
end

local published = tostring(job['batch_event_published'] or '')
if published == 'batch_completed' or published == 'batch_completed_with_errors' then
    return 2
end

local claimToken = ARGV[1]
local now = ARGV[2]
local ttl = tonumber(ARGV[3])
local claimTtl = tonumber(ARGV[4]) or 120
local nowUnix = tonumber(ARGV[5]) or 0

if string.sub(published, 1, 11) == 'publishing:' then
    local currentToken = string.sub(published, 12)
    if currentToken ~= claimToken then
        -- QUAL-010: Takeover de claims expirados
        local claimedAt = tonumber(job['batch_event_claimed_at'] or 0)
        if (nowUnix - claimedAt) < claimTtl then
            return 0  -- Claim vigente de otro proceso
        end
        -- Takeover: claim expirado, continuar con el nuevo token
    end
end

job['batch_event_published'] = 'publishing:' .. claimToken
job['batch_event_claimed_at'] = nowUnix
job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const CONFIRM_BATCH_TERMINAL_EVENT_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local claimToken = ARGV[1]
local eventType = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

local published = tostring(job['batch_event_published'] or '')
if published ~= ('publishing:' .. claimToken) then
    return 0
end

job['batch_event_published'] = eventType
job['batch_event_claimed_at'] = nil
job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const RELEASE_BATCH_TERMINAL_EVENT_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local claimToken = ARGV[1]
local now = ARGV[2]
local ttl = tonumber(ARGV[3])

local published = tostring(job['batch_event_published'] or '')
if published ~= ('publishing:' .. claimToken) then
    return 0
end

job['batch_event_published'] = nil
job['batch_event_claimed_at'] = nil
job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const RELEASE_AUDIT_RESERVATION_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 2 end

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

    private const REOPEN_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local newEventId = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(job['audits']) ~= 'table' then return 0 end
local auditState = job['audits'][auditId]
if type(auditState) ~= 'table' then return 0 end

local prevStatus = tostring(auditState['status'] or '')
if prevStatus ~= 'failed' and prevStatus ~= 'error' then return 0 end

auditState['status'] = 'processing'
auditState['event_id'] = newEventId
auditState['reprocessed_at'] = now
auditState['previous_status'] = prevStatus
job['audits'][auditId] = auditState

if prevStatus == 'failed' then
    job['failed'] = math.max(0, (tonumber(job['failed']) or 0) - 1)
elseif prevStatus == 'error' then
    job['done'] = math.max(0, (tonumber(job['done']) or 0) - 1)
end

-- QUAL-002: Transicionar job de terminal a processing y ajustar métricas
local oldJobStatus = tostring(job['status'] or 'pending')
if oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors' then
    job['status'] = 'processing'
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)
end

-- QUAL-002: Guardar valor exacto de batch_event_published antes de limpiar
job['_pre_reprocess_batch_event_published'] = job['batch_event_published']
job['_pre_reprocess_batch_event_claimed_at'] = job['batch_event_claimed_at']
job['batch_event_published'] = nil
job['batch_event_claimed_at'] = nil

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const REVERT_AUDIT_REPROCESS_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local now = ARGV[2]
local ttl = tonumber(ARGV[3])

if type(job['audits']) ~= 'table' then return 0 end
local auditState = job['audits'][auditId]
if type(auditState) ~= 'table' then return 0 end
if tostring(auditState['status'] or '') ~= 'processing' then return 0 end

local prevStatus = tostring(auditState['previous_status'] or 'failed')
auditState['status'] = prevStatus
auditState['reverted_at'] = now
job['audits'][auditId] = auditState

if prevStatus == 'failed' then
    job['failed'] = (tonumber(job['failed']) or 0) + 1
else
    job['done'] = (tonumber(job['done']) or 0) + 1
end

-- Recalcular estado del job
local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0
local sealed = job['sealed'] == true
local oldJobStatus = tostring(job['status'] or 'pending')

if sealed and processed >= total and total > 0 then
    if (tonumber(job['failed']) or 0) > 0 then
        job['status'] = 'completed_with_errors'
    else
        job['status'] = 'completed'
    end
else
    job['status'] = 'processing'
end

local newJobStatus = tostring(job['status'] or 'pending')

-- Ajustar métricas si el estado del job cambió
if oldJobStatus == 'processing' and (newJobStatus == 'completed' or newJobStatus == 'completed_with_errors') then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
    -- QUAL-002: Restaurar valor exacto de batch_event_published, no reconstruir por contadores
    local savedPublished = job['_pre_reprocess_batch_event_published']
    if savedPublished then
        job['batch_event_published'] = savedPublished
    else
        -- Fallback: reconstruir si no hay snapshot (jobs pre-migración)
        if (tonumber(job['failed']) or 0) > 0 then
            job['batch_event_published'] = 'batch_completed_with_errors'
        else
            job['batch_event_published'] = 'batch_completed'
        end
    end
end

-- Limpiar snapshots de reproceso
job['_pre_reprocess_batch_event_published'] = nil
job['_pre_reprocess_batch_event_claimed_at'] = nil

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;
}
