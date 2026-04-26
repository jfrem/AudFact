<?php

declare(strict_types=1);

namespace App\Services\Audit\Events;

use Core\Env;
use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class AuditStateStore
{
    public const AUDIT_STATUS_PENDING       = 'pending';
    public const AUDIT_STATUS_PROCESSING    = 'processing';
    public const AUDIT_STATUS_COMPLETED     = 'completed';
    public const AUDIT_STATUS_MANUAL_REVIEW = 'manual_review';
    public const AUDIT_STATUS_ERROR         = 'error';
    public const AUDIT_STATUS_FAILED        = 'failed';

    public const JOB_STATUS_PENDING             = 'pending';
    public const JOB_STATUS_PROCESSING          = 'processing';
    public const JOB_STATUS_COMPLETED           = 'completed';
    public const JOB_STATUS_COMPLETED_WITH_ERR  = 'completed_with_errors';
    public const JOB_STATUS_FAILED              = 'failed';

    private const AUDIT_TTL_SECONDS = 86400;
    private const JOB_TTL_SECONDS   = 86400;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $facSec = null
    ): bool {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $state = [
            'audit_id'    => $auditId,
            'status'      => self::AUDIT_STATUS_PENDING,
            'dis_det_nro' => $disDetNro,
            'job_id'      => $jobId,
            'fac_nit_sec' => $facNitSec,
            'fac_sec'     => $facSec,
            'docs_total'  => 0,
            'docs_done'   => 0,
            'docs_extracted' => 0,
            'docs_evaluated' => 0,
            'documents'   => new \stdClass(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $encoded = self::encode($state);
        return $this->redis->setnx(self::auditKey($auditId), $encoded, self::AUDIT_TTL_SECONDS);
    }

    public function getAudit(string $auditId): ?array
    {
        try {
            $raw = $this->redis->get(self::auditKey($auditId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer auditoría', 0, $e);
        }

        return $raw === null ? null : self::decode($raw);
    }

    public function updateAuditStatus(string $auditId, string $status): bool
    {
        return $this->patchAudit($auditId, ['status' => $status]);
    }

    public function deleteAudit(string $auditId): bool
    {
        return $this->redis->del(self::auditKey($auditId));
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $patch['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        try {
            $result = $this->redis->eval(
                self::MERGE_LUA,
                [self::auditKey($auditId)],
                [$patch, self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: patchAudit falló', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar la auditoría en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    public function setAuditDocumentsTotal(string $auditId, int $total): bool
    {
        return $this->patchAudit($auditId, [
            'docs_total' => $total,
            'status' => self::AUDIT_STATUS_PROCESSING,
        ]);
    }

    public function registerDocument(string $auditId, string $documentId, array $documentState): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local documentId = ARGV[1]
local documentState = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(audit['documents']) ~= 'table' then
    audit['documents'] = {}
end

audit['documents'][documentId] = documentState
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$documentId, $documentState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: registerDocument falló', [
                'audit_id' => $auditId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo registrar el documento en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    public function markDocumentExtracted(string $auditId, string $documentId, array $extractionState): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local documentId = ARGV[1]
local patch = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(audit['documents']) ~= 'table' or type(audit['documents'][documentId]) ~= 'table' then
    return 0
end

local document = audit['documents'][documentId]
local previousStatus = tostring(document['status'] or '')

for k, v in pairs(patch) do
    document[k] = v
end

document['updated_at'] = now
audit['documents'][documentId] = document

if previousStatus ~= 'extracted' then
    audit['docs_extracted'] = (tonumber(audit['docs_extracted']) or 0) + 1
end

audit['status'] = 'processing'

audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$documentId, $extractionState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: markDocumentExtracted falló', [
                'audit_id' => $auditId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar la extracción del documento en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    public function markDocumentNormalized(string $auditId, string $documentId, array $normalizedState): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local documentId = ARGV[1]
local patch = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(audit['documents']) ~= 'table' or type(audit['documents'][documentId]) ~= 'table' then
    return 0
end

local document = audit['documents'][documentId]
local previousStatus = tostring(document['status'] or '')

for k, v in pairs(patch) do
    document[k] = v
end

document['updated_at'] = now
audit['documents'][documentId] = document

if previousStatus ~= 'normalized' then
    audit['docs_done'] = (tonumber(audit['docs_done']) or 0) + 1
end

audit['status'] = 'processing'
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$documentId, $normalizedState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: markDocumentNormalized falló', [
                'audit_id' => $auditId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar la normalización del documento en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    /**
     * Persiste la evaluación de policy de un documento y actualiza el contador agregado.
     *
     * @param  string $auditId
     * @param  string $documentId
     * @param  array<string,mixed> $policyState
     * @return bool
     */
    public function markDocumentEvaluated(string $auditId, string $documentId, array $policyState): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local documentId = ARGV[1]
local patch = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])

if type(audit['documents']) ~= 'table' or type(audit['documents'][documentId]) ~= 'table' then
    return 0
end

local document = audit['documents'][documentId]
local previousStatus = tostring(document['status'] or '')

for k, v in pairs(patch) do
    document[k] = v
end

document['updated_at'] = now
audit['documents'][documentId] = document

if previousStatus ~= 'evaluated' then
    audit['docs_evaluated'] = (tonumber(audit['docs_evaluated']) or 0) + 1
end

audit['status'] = 'processing'
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$documentId, $policyState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: markDocumentEvaluated falló', [
                'audit_id' => $auditId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar la evaluación del documento en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    /**
     * Guarda el resultado agregado de `rules_evaluated` una sola vez por auditoría.
     *
     * @param  string $auditId
     * @param  array<string,mixed> $rulesEvaluation
     * @return bool
     */
    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
if type(audit['rules_evaluated_result']) == 'table' then
    return 2
end

local payload = cjson.decode(ARGV[1])
local now = ARGV[2]
local ttl = tonumber(ARGV[3])

audit['rules_evaluated_result'] = payload
audit['rules_evaluated_at'] = now
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$rulesEvaluation, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: storeRulesEvaluation falló', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo persistir rules_evaluated en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    /**
     * Marca una auditoría como cerrada con su estado final y resultado agregado.
     *
     * @param  string $auditId
     * @param  array<string,mixed> $completionState
     * @return bool
     */
    public function completeAudit(string $auditId, array $completionState): bool
    {
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
if tostring(audit['status'] or '') == 'completed'
or tostring(audit['status'] or '') == 'manual_review'
or tostring(audit['status'] or '') == 'error'
or tostring(audit['status'] or '') == 'failed' then
    return 2
end

local patch = cjson.decode(ARGV[1])
local now = ARGV[2]
local ttl = tonumber(ARGV[3])

for k, v in pairs(patch) do
    audit[k] = v
end

audit['completed_at'] = now
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

        try {
            $result = $this->redis->eval(
                $lua,
                [self::auditKey($auditId)],
                [$completionState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: completeAudit falló', [
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo completar la auditoría en Redis', 0, $e);
        }

        return (int) $result === 1;
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

        return $this->redis->setnx(self::jobKey($jobId), self::encode($state), self::JOB_TTL_SECONDS);
    }

    public function getJob(string $jobId): ?array
    {
        try {
            $raw = $this->redis->get(self::jobKey($jobId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer job', 0, $e);
        }

        return $raw === null ? null : self::decode($raw);
    }

    public function registerAuditInJob(string $jobId, string $auditId, string $disDetNro): bool
    {
        $lua = <<<'LUA'
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

        try {
            $result = $this->redis->eval(
                $lua,
                [self::jobKey($jobId)],
                [$auditId, $disDetNro, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: registerAuditInJob falló', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo registrar auditoría en el job', 0, $e);
        }

        return (int) $result === 1;
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

        try {
            $result = $this->redis->eval(
                self::MERGE_LUA,
                [self::jobKey($jobId)],
                [$patch, self::JOB_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: patchJob falló', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar el job en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    /**
     * Actualiza el progreso de un job batch cuando una auditoría termina.
     *
     * @param  string $jobId
     * @param  string $auditId
     * @param  string $auditStatus
     * @return bool
     */
    public function markAuditCompletedInJob(string $jobId, string $auditId, string $auditStatus): bool
    {
        $lua = <<<'LUA'
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

        try {
            $result = $this->redis->eval(
                $lua,
                [self::jobKey($jobId)],
                [$auditId, $auditStatus, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: markAuditCompletedInJob falló', [
                'job_id' => $jobId,
                'audit_id' => $auditId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo actualizar el progreso del job en Redis', 0, $e);
        }

        return (int) $result === 1;
    }

    /**
     * Marca en el job que el evento terminal de batch ya fue publicado para evitar duplicados.
     *
     * @param  string $jobId
     * @param  string $eventType
     * @return bool
     */
    public function claimBatchTerminalEvent(string $jobId, string $eventType): bool
    {
        $lua = <<<'LUA'
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

        try {
            $result = $this->redis->eval(
                $lua,
                [self::jobKey($jobId)],
                [$eventType, gmdate('Y-m-d\TH:i:s\Z'), self::JOB_TTL_SECONDS]
            );
        } catch (\Exception $e) {
            Logger::error('AuditStateStore: claimBatchTerminalEvent falló', [
                'job_id' => $jobId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo reclamar el evento terminal del batch en Redis', 0, $e);
        }

        return (int) $result === 1;
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

    public static function auditKey(string $auditId): string
    {
        return "audit:{$auditId}:state";
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

    private static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('AuditStateStore: encoding falló — ' . json_last_error_msg());
        }
        return $json;
    }

    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('AuditStateStore: decoding falló — payload inválido en Redis');
        }
        return $data;
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
}
