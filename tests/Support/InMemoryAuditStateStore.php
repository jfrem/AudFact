<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Audit\Pipeline\AuditStateStore;

/**
 * Doble con estado de `AuditStateStore` (solo lo que usa `AuditBatchOrchestrator`): `initAudit` es `SET NX`.
 */
final class InMemoryAuditStateStore extends AuditStateStore
{
    /** @var array<string,array<string,mixed>> */
    public array $audits = [];
    /** @var array<int,string> */
    public array $deleted = [];
    /** Si es `true`, `initAudit()` siempre devuelve `false` aunque no exista el estado. */
    public bool $failInit = false;
    /** @var array<string,callable> */
    public array $interceptors = [];

    public function __construct()
    {
        // Sin Redis.
    }

    public function initAudit(
        string $auditId,
        string $disDetNro,
        ?string $jobId = null,
        ?string $facNitSec = null,
        ?string $disId = null
    ): bool {
        $this->intercept('initAudit', func_get_args());
        if ($this->failInit || isset($this->audits[$auditId])) {
            return false;
        }
        $this->audits[$auditId] = [
            'audit_id' => $auditId,
            'dis_det_nro' => $disDetNro,
            'job_id' => $jobId,
            'fac_nit_sec' => $facNitSec,
            'dis_id' => $disId,
            'status' => AuditStateStore::AUDIT_STATUS_PENDING,
        ];

        return true;
    }

    public function getAudit(string $auditId): ?array
    {
        return $this->audits[$auditId] ?? null;
    }

    public function patchAudit(string $auditId, array $patch): bool
    {
        $this->intercept('patchAudit', func_get_args());
        if (!isset($this->audits[$auditId])) {
            return false;
        }
        $this->audits[$auditId] = array_merge($this->audits[$auditId], $patch);

        return true;
    }

    public function deleteAudit(string $auditId): bool
    {
        $this->intercept('deleteAudit', func_get_args());
        $this->deleted[] = $auditId;
        $existed = isset($this->audits[$auditId]);
        unset($this->audits[$auditId]);

        return $existed;
    }

    /**
     * @param array<int,mixed> $args
     */
    private function intercept(string $method, array $args): void
    {
        if (isset($this->interceptors[$method])) {
            ($this->interceptors[$method])($args, $this);
        }
    }
}
