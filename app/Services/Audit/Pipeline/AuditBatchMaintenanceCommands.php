<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\RedisClient;

/**
 * Subcomandos batch de `bin/audit-execution-maintenance.php` (SDD-BATCH-001, Contrato 4) y validación del segundo
 * argumento de la CLI. Se extraen del script para probar la composición sin lanzar un proceso.
 *
 *  - `migrate-batch-v2 [--clear-claims]`: `BatchJobStore::migrateBatchV2()` y, solo si no lanza,
 *    `AuditExecutionMaintenance::expireLegacyIdempotency()` (`legacy_idempotency` en la salida, `W-F1-I4(a)`).
 *  - `rollback-batch-v2`: `BatchJobStore::rollbackBatchV2()` (lanza, y la CLI sale con `1`, si la precondición
 *    `DEC-11` falla).
 *  - `repair-jobs`: `AuditExecutionInspector::repairCorruptedJobs()`; con `--force-stale[=<jobId>]`,
 *    `reapStaleJobs($jobId, 0)` (umbral `0`, respeta `batch:claim`).
 */
final class AuditBatchMaintenanceCommands
{
    public const COMMANDS = ['migrate-batch-v2', 'rollback-batch-v2', 'repair-jobs'];

    private const FORCE_STALE = '--force-stale';

    /**
     * Valida `argv`: como máximo un segundo argumento, aceptado solo como `--clear-claims` con `migrate-batch-v2`
     * o `--force-stale[=<jobId UUIDv4>]` con `repair-jobs`. Los comandos sin segundo argumento no se ven afectados.
     *
     * @param  list<string>  $argv  `argv` completo del script (`$argv[0]` es el propio script).
     * @return array{command:string,option:?string,force_stale:bool,force_job_id:?string}
     * @throws \InvalidArgumentException `Opcion desconocida`
     */
    public static function parseArguments(array $argv): array
    {
        $argc = count($argv);
        $command = (string) ($argv[1] ?? '');
        if ($argc > 3) {
            throw new \InvalidArgumentException('Opcion desconocida');
        }
        $option = $argc === 3 ? (string) $argv[2] : null;
        $forceStale = false;
        $forceJobId = null;
        if ($option !== null) {
            if ($command === 'migrate-batch-v2' && $option === '--clear-claims') {
                // aceptado
            } elseif ($command === 'repair-jobs' && $option === self::FORCE_STALE) {
                $forceStale = true;
            } elseif ($command === 'repair-jobs' && str_starts_with($option, self::FORCE_STALE . '=')) {
                $forceJobId = substr($option, strlen(self::FORCE_STALE . '='));
                if ($forceJobId === '' || !AuditEvent::isUuidV4($forceJobId)) {   // X-F2-M4
                    throw new \InvalidArgumentException('Opcion desconocida');
                }
                $forceStale = true;
            } else {
                throw new \InvalidArgumentException('Opcion desconocida');
            }
        }

        return ['command' => $command, 'option' => $option, 'force_stale' => $forceStale, 'force_job_id' => $forceJobId];
    }

    /**
     * Ejecuta un subcomando batch ya validado por parseArguments().
     *
     * @param  array{command:string,option:?string,force_stale:bool,force_job_id:?string}  $arguments
     * @return array<string,mixed> Resultado JSON de la CLI.
     * @throws \InvalidArgumentException Si el comando no es batch.
     * @throws \RuntimeException Propaga los fallos de migración/rollback (la CLI sale con código `1`).
     */
    public static function run(
        array $arguments,
        RedisClient $redis,
        AuditExecutionMaintenance $maintenance,
        ?BatchJobStore $store = null
    ): array {
        $store ??= new BatchJobStore($redis);
        $inspector = new AuditExecutionInspector($redis, $store);

        return match ($arguments['command']) {
            // El operando izquierdo se evalúa primero: si migrateBatchV2() lanza, no se expiran claves legadas.
            'migrate-batch-v2' => $store->migrateBatchV2($arguments['option'] === '--clear-claims')
                + ['legacy_idempotency' => $maintenance->expireLegacyIdempotency()],
            'rollback-batch-v2' => $store->rollbackBatchV2(),
            'repair-jobs' => $arguments['force_stale']
                ? $inspector->reapStaleJobs($arguments['force_job_id'], 0)
                : $inspector->repairCorruptedJobs(),
            default => throw new \InvalidArgumentException('unknown_maintenance_command'),
        };
    }
}
