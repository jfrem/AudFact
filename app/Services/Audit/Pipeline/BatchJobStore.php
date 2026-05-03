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

    public function initJob(
        string $jobId,
        int $facNitSec,
        string $dateFrom,
        ?string $dateTo,
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
            'total'       => 0,
            'done'        => 0,
            'failed'      => 0,
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

    public function registerAuditInJob(string $jobId, string $auditId, string $disDetNro): bool
    {
        return $this->runScript(
            self::REGISTER_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [$auditId, $disDetNro, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS],
            'No se pudo registrar auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

    public function updateJobStatus(string $jobId, string $status): bool
    {
        return $this->patchJob($jobId, ['status' => $status]);
    }

    public function deleteJob(string $jobId): bool
    {
        return $this->redis->del(self::jobKey($jobId));
    }

    public function patchJob(string $jobId, array $patch): bool
    {
        $patch['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->runScript(
            self::MERGE_LUA,
            [self::jobKey($jobId)],
            [$patch, self::JOB_TTL_SECONDS],
            'No se pudo actualizar el job en Redis',
            ['job_id' => $jobId]
        );
    }

    public function markAuditCompletedInJob(string $jobId, string $auditId, string $auditStatus): bool
    {
        return $this->runScript(
            self::MARK_AUDIT_COMPLETED_IN_JOB_LUA,
            [self::jobKey($jobId)],
            [$auditId, $auditStatus, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS],
            'No se pudo actualizar el progreso del job en Redis',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }

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

    public function claimBatchSlot(int $facNitSec, string $dateFrom, ?string $dateTo, string $jobId): bool
    {
        return $this->redis->setnx(
            self::batchLockKey($facNitSec, $dateFrom, $dateTo),
            $jobId,
            self::JOB_TTL_SECONDS
        );
    }

    public function getActiveBatchJobId(int $facNitSec, string $dateFrom, ?string $dateTo): ?string
    {
        try {
            return $this->redis->get(self::batchLockKey($facNitSec, $dateFrom, $dateTo));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al consultar lock de batch', 0, $e);
        }
    }

    public function releaseBatchSlot(int $facNitSec, string $dateFrom, ?string $dateTo): bool
    {
        return $this->redis->del(self::batchLockKey($facNitSec, $dateFrom, $dateTo));
    }

    public static function jobKey(string $jobId): string
    {
        return "job:{$jobId}:state";
    }

    public static function batchLockKey(int $facNitSec, string $dateFrom, ?string $dateTo): string
    {
        $to = $dateTo ?? $dateFrom;
        return "job:active:{$facNitSec}:{$dateFrom}:{$to}";
    }


    private const MERGE_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local state = cjson.decode(raw)
local patch = cjson.decode(ARGV[1])

for k, v in pairs(patch) do
    state[k] = v
end

redis.call('SET', KEYS[1], cjson.encode(state), 'EX', tonumber(ARGV[2]))
return 1
LUA;

    private const REGISTER_AUDIT_IN_JOB_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local auditId = ARGV[1]
local disDetNro = ARGV[2]
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(job['audits']) ~= 'table' then
    job['audits'] = {}
end
job['audits'][auditId] = { dis_det_nro = disDetNro, status = 'pending' }
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

if type(job['audits']) ~= 'table' or type(job['audits'][auditId]) ~= 'table' then
    return 0
end

local auditState = job['audits'][auditId]
local previousStatus = tostring(auditState['status'] or '')

auditState['status'] = auditStatus
auditState['completed_at'] = now
job['audits'][auditId] = auditState

if previousStatus ~= 'completed' and previousStatus ~= 'manual_review'
and previousStatus ~= 'error' and previousStatus ~= 'failed' then
    if auditStatus == 'failed' then
        job['failed'] = (tonumber(job['failed']) or 0) + 1
    else
        job['done'] = (tonumber(job['done']) or 0) + 1
    end
end

local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0

if processed >= total and total > 0 then
    if (tonumber(job['failed']) or 0) > 0 then
        job['status'] = 'completed_with_errors'
    else
        job['status'] = 'completed'
    end
else
    job['status'] = 'processing'
end

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
LUA;

    private const CLAIM_BATCH_TERMINAL_EVENT_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
if tostring(job['batch_event_published'] or '') ~= '' then
    return 2
end

job['batch_event_published'] = ARGV[1]
job['updated_at'] = ARGV[2]
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', tonumber(ARGV[3]))
return 1
LUA;
}
