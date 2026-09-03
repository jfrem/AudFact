<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
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

    private const DEFAULT_AUDIT_TTL_SECONDS = 604800;

    private RedisClient $redis;

    public function __construct(?RedisClient $redis = null)
    {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    private static function auditTtlSeconds(): int
    {
        return self::positiveIntEnv('AUDIT_STATE_TTL', self::DEFAULT_AUDIT_TTL_SECONDS);
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

    private static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $disId = null
    ): bool {
        $now = self::nowUtc();
        $state = [
            'audit_id'    => $auditId,
            'status'      => self::AUDIT_STATUS_PENDING,
            'dis_det_nro' => $disDetNro,
            'job_id'      => $jobId,
            'fac_nit_sec' => $facNitSec,
            'dis_id'      => $disId,
            'docs_total'  => 0,
            'docs_done'   => 0,
            'docs_extracted' => 0,
            'docs_evaluated' => 0,
            'docs_rejected'  => 0,
            'documents'   => new \stdClass(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $encoded = self::encodeJson($state, 'AuditStateStore');
        return $this->redis->setnx(self::auditKey($auditId), $encoded, self::auditTtlSeconds());
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

    /**
     * Reabre atómicamente una auditoría terminal para reproceso desde DLQ (QUAL-018).
     *
     * Transiciona status de 'failed'/'error' a 'processing', limpia campos de error,
     * y registra trazabilidad del reproceso.
     *
     * @param  string  $auditId           UUID de la auditoría a reabrir
     * @param  string  $reprocessEventId  UUID del nuevo evento que disparó el reproceso
     * @return bool    true si se reabrió exitosamente, false si no existe o no está en estado terminal compatible
     */
    public function reopenAuditForReprocess(string $auditId, string $reprocessEventId): bool
    {
        return $this->runScript(
            self::REOPEN_AUDIT_LUA,
            [self::auditKey($auditId)],
            [$reprocessEventId, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo reabrir la auditoría para reproceso',
            ['audit_id' => $auditId, 'reprocess_event_id' => $reprocessEventId]
        );
    }

    /**
     * Revierte un reproceso que no pudo completar su publicación (QUAL-001).
     *
     * Restaura el status previo (o 'failed' si no existe), limpia campos de reproceso
     * y registra el error que provocó la compensación.
     *
     * @param  string  $auditId      UUID de la auditoría a revertir
     * @param  string  $errorMessage Mensaje del error que provocó la compensación
     * @return bool    true si se revirtió exitosamente, false si no existe o no está en processing
     */
    public function revertReprocess(string $auditId, string $errorMessage): bool
    {
        return $this->runScript(
            self::REVERT_REPROCESS_LUA,
            [self::auditKey($auditId)],
            [$errorMessage, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo revertir el reproceso de la auditoría',
            ['audit_id' => $auditId]
        );
    }

    /**
     * Registra un fallo de reconciliación en una clave durable para auditoría operativa (QUAL-001).
     *
     * @param  string  $auditId UUID de la auditoría.
     * @param  array   $data    Metadatos de la desincronización detectada.
     * @param  int     $ttl     TTL de retención en segundos (default: 7 días).
     * @return bool True si se persistió exitosamente.
     */
    public function recordFailedReconciliation(string $auditId, array $data, int $ttl = 604800): bool
    {
        try {
            $key = "audit:reconcile:dlq:{$auditId}";
            return $this->redis->set($key, json_encode($data, JSON_UNESCAPED_UNICODE), $ttl);
        } catch (\Throwable) {
            return false;
        }
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $patch['updated_at'] = self::nowUtc();

        return $this->runScript(
            self::$MERGE_LUA,
            [self::auditKey($auditId)],
            [$patch, self::auditTtlSeconds()],
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

    /**
     * Marca el inicio del procesamiento activo de una auditoría.
     *
     * @param  string  $auditId  Identificador UUID de la auditoría.
     */
    public function markAuditStarted(string $auditId): bool
    {
        return $this->runScript(
            self::MARK_AUDIT_STARTED_LUA,
            [self::auditKey($auditId)],
            [self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo marcar el inicio de la auditoría en Redis',
            ['audit_id' => $auditId]
        );
    }

    public function registerDocument(string $auditId, string $documentId, array $documentState): bool
    {
        return $this->runScript(
            self::REGISTER_DOCUMENT_LUA,
            [self::auditKey($auditId)],
            [$documentId, $documentState, self::nowUtc(), self::auditTtlSeconds()],
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
     * Marca un documento como rechazado por validación de integridad.
     *
     * Incrementa atómicamente `docs_rejected`. La evaluación funcional del
     * rechazo ocurre después en `RulesEvaluationWorker`, que incrementa
     * `docs_evaluated` al persistir el `policy_result` canónico.
     *
     * @param  array<string,mixed> $patch  Metadata del rechazo (rejection_reason, document_type, mime, etc.)
     */
    public function markDocumentRejected(string $auditId, string $documentId, array $patch): bool
    {
        return $this->runScript(
            self::DOCUMENT_REJECTION_LUA,
            [self::auditKey($auditId)],
            [$documentId, $patch, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo marcar el documento como rechazado en Redis',
            ['audit_id' => $auditId, 'document_id' => $documentId]
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
            [$documentId, $patch, self::nowUtc(), self::auditTtlSeconds(), $counterField, $expectedStatus],
            $errorMessage,
            ['audit_id' => $auditId, 'document_id' => $documentId]
        );
    }

    public function storeRulesEvaluation(string $auditId, array $rulesEvaluation): bool
    {
        return $this->runScript(
            self::STORE_RULES_EVALUATION_LUA,
            [self::auditKey($auditId)],
            [$rulesEvaluation, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo persistir rules_evaluated en Redis',
            ['audit_id' => $auditId]
        );
    }

    public function completeAudit(string $auditId, array $completionState): bool
    {
        return $this->runScript(
            self::COMPLETE_AUDIT_LUA,
            [self::auditKey($auditId)],
            [$completionState, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo completar la auditoría en Redis',
            ['audit_id' => $auditId],
            acceptValues: [1, 2]
        );
    }

    /**
     * Registra telemetría mínima de un evento procesado por un consumer.
     *
     * @param  string  $auditId  Identificador UUID de la auditoría.
     * @param  array<string,mixed>  $telemetry  Métricas escalares del evento, sin payload documental.
     */
    public function recordEventTelemetry(string $auditId, array $telemetry): bool
    {
        return $this->runScript(
            self::RECORD_EVENT_TELEMETRY_LUA,
            [self::auditKey($auditId)],
            [$telemetry, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo registrar telemetría del evento en Redis',
            ['audit_id' => $auditId]
        );
    }

    public static function auditKey(string $auditId): string
    {
        return "audit:{$auditId}:state";
    }




    private const REGISTER_DOCUMENT_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return redis.error_reply('Auditoria no encontrada o expirada') end

        local audit = cjson.decode(raw)
        local status = tostring(audit['status'] or '')
        if status == 'completed'
        or status == 'manual_review'
        or status == 'error'
        or status == 'failed' then
            return 1
        end

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

    private const MARK_AUDIT_STARTED_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return redis.error_reply('Auditoria no encontrada o expirada') end

        local audit = cjson.decode(raw)
        local status = tostring(audit['status'] or '')

        if status == 'completed'
        or status == 'manual_review'
        or status == 'error'
        or status == 'failed' then
            return 1
        end

        local now = ARGV[1]
        if tostring(audit['started_at'] or '') == '' then
            audit['started_at'] = now
        end

        audit['status'] = 'processing'
        audit['updated_at'] = now

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', tonumber(ARGV[2]))
        return 1
    LUA;

    private const DOCUMENT_TRANSITION_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return redis.error_reply('Auditoria no encontrada o expirada') end

        local audit = cjson.decode(raw)
        local auditStatus = tostring(audit['status'] or '')
        if auditStatus == 'completed'
        or auditStatus == 'manual_review'
        or auditStatus == 'error'
        or auditStatus == 'failed' then
            return 1
        end

        local documentId = ARGV[1]
        local patch = cjson.decode(ARGV[2])
        local now = ARGV[3]
        local ttl = tonumber(ARGV[4])
        local counterField = ARGV[5]
        local expectedStatus = ARGV[6]

        if type(audit['documents']) ~= 'table' then
            return redis.error_reply('Estado corrupto: documents no es una tabla')
        end
        if type(audit['documents'][documentId]) ~= 'table' then
            return redis.error_reply('Documento no registrado en la auditoria: ' .. tostring(documentId))
        end

        local document = audit['documents'][documentId]
        local previousStatus = tostring(document['status'] or '')

        if previousStatus == 'evaluated' then
            return 1
        end

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

    private const DOCUMENT_REJECTION_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return redis.error_reply('Auditoria no encontrada o expirada') end

        local audit = cjson.decode(raw)
        local auditStatus = tostring(audit['status'] or '')
        if auditStatus == 'completed'
        or auditStatus == 'manual_review'
        or auditStatus == 'error'
        or auditStatus == 'failed' then
            return 1
        end

        local documentId = ARGV[1]
        local patch = cjson.decode(ARGV[2])
        local now = ARGV[3]
        local ttl = tonumber(ARGV[4])

        if type(audit['documents']) ~= 'table' then
            return redis.error_reply('Estado corrupto: documents no es una tabla')
        end
        if type(audit['documents'][documentId]) ~= 'table' then
            return redis.error_reply('Documento no registrado en la auditoria: ' .. tostring(documentId))
        end

        local document = audit['documents'][documentId]
        local previousStatus = tostring(document['status'] or '')

        if previousStatus == 'evaluated' then
            return 1
        end

        for k, v in pairs(patch) do
            document[k] = v
        end

        document['status'] = 'rejected'
        document['updated_at'] = now
        audit['documents'][documentId] = document

        if previousStatus ~= 'rejected' then
            audit['docs_rejected'] = (tonumber(audit['docs_rejected']) or 0) + 1
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

        -- QUAL-002: Limpiar snapshot de reproceso al completar exitosamente
        audit['_pre_reprocess_snapshot'] = nil

        audit['completed_at'] = now
        audit['updated_at'] = now

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
        return 1
    LUA;

    private const REOPEN_AUDIT_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end

        local audit = cjson.decode(raw)
        local status = tostring(audit['status'] or '')

        if status ~= 'failed' and status ~= 'error' then
            return 0
        end

        local reprocessEventId = ARGV[1]
        local now = ARGV[2]
        local ttl = tonumber(ARGV[3])

        -- QUAL-002: Guardar snapshot de campos terminales antes de limpiarlos
        audit['_pre_reprocess_snapshot'] = cjson.encode({
            detail_error = audit['detail_error'],
            requires_manual_review = audit['requires_manual_review'],
            failed_stage = audit['failed_stage'],
            failed_event_type = audit['failed_event_type'],
            completed_at = audit['completed_at'],
        })

        audit['previous_status'] = status
        audit['status'] = 'processing'
        audit['reprocessed_at'] = now
        audit['reprocessed_by_event_id'] = reprocessEventId
        audit['reprocess_count'] = (tonumber(audit['reprocess_count']) or 0) + 1
        audit['updated_at'] = now

        -- Limpiar campos de error de la ejecución previa
        audit['detail_error'] = nil
        audit['requires_manual_review'] = nil
        audit['failed_stage'] = nil
        audit['failed_event_type'] = nil
        audit['completed_at'] = nil

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
        return 1
    LUA;

    private const REVERT_REPROCESS_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end

        local audit = cjson.decode(raw)
        local status = tostring(audit['status'] or '')
        if status ~= 'processing' then return 0 end

        local prevStatus = tostring(audit['previous_status'] or 'failed')
        audit['status'] = prevStatus

        -- QUAL-002: Restaurar campos terminales desde snapshot
        local snapshotRaw = audit['_pre_reprocess_snapshot']
        if snapshotRaw then
            local ok, snapshot = pcall(cjson.decode, snapshotRaw)
            if ok and type(snapshot) == 'table' then
                for k, v in pairs(snapshot) do
                    audit[k] = v
                end
            end
            audit['_pre_reprocess_snapshot'] = nil
        end

        -- Sobreescribir detail_error con el error actual de compensación
        audit['detail_error'] = ARGV[1]
        audit['reprocessed_at'] = nil
        audit['reprocessed_by_event_id'] = nil
        audit['updated_at'] = ARGV[2]

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', tonumber(ARGV[3]))
        return 1
    LUA;

    private const RECORD_EVENT_TELEMETRY_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end

        local audit = cjson.decode(raw)
        local telemetry = cjson.decode(ARGV[1])
        local now = ARGV[2]
        local ttl = tonumber(ARGV[3])

        if type(audit['event_timings']) ~= 'table' then
            audit['event_timings'] = {}
        end

        table.insert(audit['event_timings'], telemetry)
        audit['updated_at'] = now

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
        return 1
    LUA;
}
