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
        $state = self::initialAuditState($auditId, $disDetNro, $jobId, $facNitSec, $disId);
        $encoded = self::encodeJson($state, 'AuditStateStore');
        return $this->redis->eval('local r=redis.call("SET",KEYS[1],ARGV[1],"NX"); return r and 1 or 0', [self::auditKey($auditId)], [$encoded]) === 1;
    }

    private static function initialAuditState(
        string $auditId, string $disDetNro, ?string $jobId, ?string $facNitSec, ?string $disId
    ): array {
        $now = self::nowUtc();
        return [
            'audit_id'    => $auditId,
            'execution_version' => AuditExecutionConfig::VERSION,
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

    }

    /** Crea y publica una sola recuperación por auditoría fallida, sin reabrir el job histórico. */
    public function recoverFailedAudit(AuditEvent $recovery, string $originalAuditId): array
    {
        if ($recovery->eventType !== AuditEvent::TYPE_AUDIT_CREATED || $recovery->auditId === null
            || $recovery->jobId !== null || $originalAuditId === $recovery->auditId) {
            throw new \InvalidArgumentException('La recuperación requiere una nueva auditoría independiente');
        }
        $execution = AuditExecutionConfig::fromEnv();
        (new AuditAdmissionGuard($this->redis, $execution))->assertAccepting();
        $result = $this->redis->eval(self::RECOVER_FAILED_AUDIT_LUA, [
            self::auditKey($originalAuditId), self::auditKey($recovery->auditId),
            'audit:recovery:' . $originalAuditId, AuditEventPublisher::streamForEvent($recovery),
            'audit:execution:version', 'audit:admission:paused', 'audit:execution:paused',
            'audit:execution:heartbeat', FairAuditTaskQueue::COUNTS,
        ], [
            self::encodeJson(self::initialAuditState($recovery->auditId, '', null, null, null)),
            $recovery->toJson(), self::auditTtlSeconds(),
            $execution->version, (int) floor($execution->maxQueued * 0.8),
            json_encode(array_column(AuditTaskStage::cases(), 'value'), JSON_THROW_ON_ERROR),
        ]);
        if (!is_string($result)) {
            throw new RuntimeException('Redis no confirmó la recuperación de la auditoría');
        }
        $response = self::decodeJson($result);
        if (isset($response['conflict'])) {
            throw new RuntimeException('La auditoría debe estar fallida y conservar su identidad para reprocesarse', 409);
        }
        return $response;
    }

    private const RECOVER_FAILED_AUDIT_LUA = <<<'LUA'
        -- Revalidar la barrera en la misma operación que crea/publica la raíz.
        if (redis.call('GET',KEYS[5]) or '2')~=ARGV[4]
        or redis.call('GET',KEYS[6])=='1' or redis.call('GET',KEYS[7])=='1' then
            return redis.error_reply('audit_capacity_unavailable')
        end
        local t=redis.call('TIME'); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
        local heartbeat=tonumber(redis.call('GET',KEYS[8]) or '')
        if not heartbeat or heartbeat>now or now-heartbeat>10000 then return redis.error_reply('audit_capacity_unavailable') end
        for _,stage in ipairs(cjson.decode(ARGV[6])) do
            local waiting=0
            for _,lane in ipairs({'priority','batch'}) do
                for _,status in ipairs({'pending','delayed'}) do
                    local n=tonumber(redis.call('HGET',KEYS[9],stage..':'..lane..':'..status) or '0')
                    if not n or n<0 then return redis.error_reply('task_metrics_invalid') end
                    waiting=waiting+n
                end
            end
            if waiting>=tonumber(ARGV[5]) then return redis.error_reply('audit_capacity_unavailable') end
        end
        local receipt = redis.call('GET', KEYS[3])
        if receipt then return receipt end
        local raw = redis.call('GET', KEYS[1])
        if not raw then return cjson.encode({conflict='missing_state'}) end
        local original = cjson.decode(raw)
        if original.status ~= 'failed' or type(original.dis_det_nro) ~= 'string'
        or original.dis_det_nro == '' then return cjson.encode({conflict='invalid_state'}) end
        local state = cjson.decode(ARGV[1])
        local event = cjson.decode(ARGV[2])
        state.dis_det_nro = original.dis_det_nro
        state.dis_id = original.dis_id
        state.fac_nit_sec = original.fac_nit_sec
        state.recovery_of_audit_id = original.audit_id
        state.recovery_of_job_id = original.job_id
        event.payload.dis_det_nro = original.dis_det_nro
        event.payload.dis_id = original.dis_id
        event.payload.fac_nit_sec = original.fac_nit_sec
        event.payload.recovery_of_audit_id = original.audit_id
        event.payload.recovery_of_job_id = original.job_id
        local response = cjson.encode({audit_id=state.audit_id, original_audit_id=original.audit_id,
            original_job_id=original.job_id, reprocessed_event_id=event.event_id, event_type=event.event_type})
        local created = redis.call('SET',KEYS[2],cjson.encode(state),'NX')
        if not created then return cjson.encode({conflict='audit_id_exists'}) end
        local saved = redis.pcall('SET', KEYS[3], response, 'EX', tonumber(ARGV[3]), 'NX')
        if not saved or (type(saved) == 'table' and saved.err) then
            redis.call('DEL', KEYS[2])
            return redis.error_reply('Could not save recovery receipt')
        end
        local published = redis.pcall('XADD', KEYS[4], '*', 'event', cjson.encode(event))
        if type(published) == 'table' and published.err then
            redis.call('DEL', KEYS[2], KEYS[3])
            return published
        end
        return response
    LUA;

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
            ['audit_id' => $auditId]
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

    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            self::AUDIT_STATUS_COMPLETED,
            self::AUDIT_STATUS_MANUAL_REVIEW,
            self::AUDIT_STATUS_ERROR,
            self::AUDIT_STATUS_FAILED,
        ], true);
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

        storeState(KEYS[1], audit, ttl)
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

        storeState(KEYS[1], audit, tonumber(ARGV[2]))
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

        storeState(KEYS[1], audit, ttl)
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

        storeState(KEYS[1], audit, ttl)
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

        storeState(KEYS[1], audit, ttl)
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

        storeState(KEYS[1], audit, ttl)
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

        storeState(KEYS[1], audit, ttl)
        return 1
    LUA;
}
