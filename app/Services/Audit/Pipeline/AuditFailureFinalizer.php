<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/**
 * Cierre idempotente de identidad, job y compactación de estado en Redis
 * después del commit de fallo técnico.
 */
final class AuditFailureFinalizer
{
    private const FINALIZE_FAILED_AUDIT_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then
            return 0
        end

        local audit = cjson.decode(raw)
        if audit.status ~= 'failed' then
            return 0
        end

        -- Liberar artefactos transitorios intermedios conservando únicamente
        -- metadatos de fallo, intentos de Gemini y recibo idempotente de conteo.
        audit.documents = nil
        audit.rules_evaluated_result = nil
        audit.audit_result = nil
        audit.operation_receipts = nil
        audit.event_timings = nil
        audit.synthetic_rejections = nil
        audit.reservation_token = nil

        if not audit.failure_counted then
            audit.failure_counted = true
            redis.call('HINCRBY', KEYS[2], 'terminal_failures', 1)
        end

        local ttl = redis.call('TTL', KEYS[1])
        if ttl and ttl > 0 then
            redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
        else
            redis.call('SET', KEYS[1], cjson.encode(audit))
        end

        return 1
    LUA;

    public function __construct(private readonly RedisClient $redis)
    {
    }

    public function finalize(AuditEvent $event): void
    {
        $auditId = (string) $event->auditId;
        $audit = (new AuditStateStore($this->redis))->getAudit($auditId);
        if ($audit === null || ($audit['status'] ?? '') !== 'failed') {
            throw new \RuntimeException('failed_audit_state_missing');
        }

        $jobs = new BatchJobStore($this->redis);
        if ($event->jobId !== null) {
            $jobs->markAuditCompletedInJob(
                $event->jobId,
                $auditId,
                'failed',
                0,
                (string) $event->payload['failed_stage']
            );
        }

        $jobs->releaseAuditReservationFromAudit($audit);
        $this->redis->eval(
            self::FINALIZE_FAILED_AUDIT_LUA,
            [AuditStateStore::auditKey($auditId), BatchJobStore::TELEMETRY_KEY]
        );
    }
}
