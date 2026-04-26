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

        return $this->runScript(
            self::MERGE_LUA,
            [self::auditKey($auditId)],
            [$patch, self::AUDIT_TTL_SECONDS],
            'No se pudo actualizar la auditoría en Redis',
            ['audit_id' => $auditId]
        );
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
        return $this->runScript(
            self::REGISTER_DOCUMENT_LUA,
            [self::auditKey($auditId)],
            [$documentId, $documentState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS],
            'No se pudo registrar el documento en Redis',
            ['audit_id' => $auditId, 'document_id' => $documentId]
        );
    }

    public function markDocumentExtracted(string $auditId, string $documentId, array $extractionState): bool
    {
        return $this->markDocumentTransition(
            $auditId,
            $documentId,
            $extractionState,
            'docs_extracted',
            'extracted',
            'No se pudo actualizar la extracción del documento en Redis'
        );
    }

    public function markDocumentNormalized(string $auditId, string $documentId, array $normalizedState): bool
    {
        return $this->markDocumentTransition(
            $auditId,
            $documentId,
            $normalizedState,
            'docs_done',
            'normalized',
            'No se pudo actualizar la normalización del documento en Redis'
        );
    }

    public function markDocumentEvaluated(string $auditId, string $documentId, array $policyState): bool
    {
        return $this->markDocumentTransition(
            $auditId,
            $documentId,
            $policyState,
            'docs_evaluated',
            'evaluated',
            'No se pudo actualizar la evaluación del documento en Redis'
        );
    }

    /**
     * @param  array<string,mixed> $patch
     */
    private function markDocumentTransition(
        string $auditId,
        string $documentId,
        array $patch,
        string $counterField,
        string $expectedStatus,
        string $errorMessage
    ): bool {
        return $this->runScript(
            self::DOCUMENT_TRANSITION_LUA,
            [self::auditKey($auditId)],
            [$documentId, $patch, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS, $counterField, $expectedStatus],
            $errorMessage,
            ['audit_id' => $auditId, 'document_id' => $documentId]
        );
    }

    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        return $this->runScript(
            self::STORE_RULES_EVALUATION_LUA,
            [self::auditKey($auditId)],
            [$rulesEvaluation, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS],
            'No se pudo persistir rules_evaluated en Redis',
            ['audit_id' => $auditId]
        );
    }

    public function completeAudit(string $auditId, array $completionState): bool
    {
        return $this->runScript(
            self::COMPLETE_AUDIT_LUA,
            [self::auditKey($auditId)],
            [$completionState, gmdate('Y-m-d\TH:i:s\Z'), self::AUDIT_TTL_SECONDS],
            'No se pudo completar la auditoría en Redis',
            ['audit_id' => $auditId]
        );
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

    /**
     * @param  array<int,string> $keys
     * @param  array<int,mixed> $args
     * @param  array<string,mixed> $logContext
     */
    private function runScript(string $lua, array $keys, array $args, string $errorMessage, array $logContext): bool
    {
        try {
            $result = $this->redis->eval($lua, $keys, $args);
        } catch (\Exception $e) {
            Logger::error($errorMessage, array_merge($logContext, ['error' => $e->getMessage()]));
            throw new RuntimeException($errorMessage, 0, $e);
        }

        return (int) $result === 1;
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

    private const REGISTER_DOCUMENT_LUA = <<<'LUA'
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

    private const DOCUMENT_TRANSITION_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local documentId = ARGV[1]
local patch = cjson.decode(ARGV[2])
local now = ARGV[3]
local ttl = tonumber(ARGV[4])
local counterField = ARGV[5]
local expectedStatus = ARGV[6]

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

if previousStatus ~= expectedStatus then
    audit[counterField] = (tonumber(audit[counterField]) or 0) + 1
end

audit['status'] = 'processing'
audit['updated_at'] = now

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
return 1
LUA;

    private const STORE_RULES_EVALUATION_LUA = <<<'LUA'
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

    private const COMPLETE_AUDIT_LUA = <<<'LUA'
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
