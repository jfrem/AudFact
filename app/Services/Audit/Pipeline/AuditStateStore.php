<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use Core\RedisClient;
use Core\RedisUnavailableException;
use RuntimeException;

class AuditStateStore
{
    use JsonRedisStoreTrait;

    /** Auditoría pendiente de procesamiento */
    public const AUDIT_STATUS_PENDING       = 'pending';
    /** Auditoría en curso — al menos un documento está siendo procesado */
    public const AUDIT_STATUS_PROCESSING    = 'processing';
    /** Auditoría completada sin hallazgos críticos */
    public const AUDIT_STATUS_COMPLETED     = 'completed';
    /** Auditoría completada con hallazgos de alta severidad que requieren revisión humana */
    public const AUDIT_STATUS_MANUAL_REVIEW = 'manual_review';
    /** Auditoría completada con discrepancias documentales no críticas — requiere análisis posterior */
    public const AUDIT_STATUS_ERROR         = 'error';
    /** Error fatal de pipeline (timeout, Gemini down, excepción no recuperable) — no se completó el análisis */
    public const AUDIT_STATUS_FAILED        = 'failed';

    private const AUDIT_TTL_SECONDS = 86400;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    private static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $facSec = null
    ): bool {
        $now = self::nowUtc();
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

        $encoded = self::encodeJson($state, 'AuditStateStore');
        return $this->redis->setnx(self::auditKey($auditId), $encoded, self::AUDIT_TTL_SECONDS);
    }

    public function getAudit(string $auditId): ?array
    {
        try {
            $raw = $this->redis->get(self::auditKey($auditId));
        } catch (RedisUnavailableException $e) {
            throw new RuntimeException('Redis no disponible al leer auditoría', 0, $e);
        }

        return $raw === null ? null : self::decodeJson($raw, 'AuditStateStore');
    }



    public function deleteAudit(string $auditId): bool
    {
        return $this->redis->del(self::auditKey($auditId));
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $patch['updated_at'] = self::nowUtc();

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
            [$documentId, $documentState, self::nowUtc(), self::AUDIT_TTL_SECONDS],
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
            [$documentId, $patch, self::nowUtc(), self::AUDIT_TTL_SECONDS, $counterField, $expectedStatus],
            $errorMessage,
            ['audit_id' => $auditId, 'document_id' => $documentId]
        );
    }

    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        return $this->runScript(
            self::STORE_RULES_EVALUATION_LUA,
            [self::auditKey($auditId)],
            [$rulesEvaluation, self::nowUtc(), self::AUDIT_TTL_SECONDS],
            'No se pudo persistir rules_evaluated en Redis',
            ['audit_id' => $auditId]
        );
    }

    public function completeAudit(string $auditId, array $completionState): bool
    {
        return $this->runScript(
            self::COMPLETE_AUDIT_LUA,
            [self::auditKey($auditId)],
            [$completionState, self::nowUtc(), self::AUDIT_TTL_SECONDS],
            'No se pudo completar la auditoría en Redis',
            ['audit_id' => $auditId]
        );
    }

    public static function auditKey(string $auditId): string
    {
        return "audit:{$auditId}:state";
    }




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
}
