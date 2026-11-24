<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditBatchMaintenanceCommands;
use App\Services\Audit\Pipeline\AuditEvent;
use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditExecutionMaintenance;
use App\Services\Audit\Pipeline\BatchJobStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMaintenanceRedis;

/**
 * SDD-BATCH-001 (Contrato 4): validación del segundo argumento de `bin/audit-execution-maintenance.php` y composición de
 * `migrate-batch-v2`, `rollback-batch-v2` y `repair-jobs [--force-stale[=jobId]]`.
 */
final class AuditBatchMaintenanceCommandsTest extends TestCase
{
    /** @return iterable<string,array{0:list<string>,1:array<string,mixed>}> */
    public static function validArguments(): iterable
    {
        $uuid = AuditEvent::deterministicUuidV4('cli-force-stale');
        yield 'sin argumentos' => [['bin'], ['command' => '', 'option' => null, 'force_stale' => false, 'force_job_id' => null]];
        yield 'run sin opción' => [['bin', 'run'], ['command' => 'run', 'option' => null, 'force_stale' => false, 'force_job_id' => null]];
        yield 'migrate sin opción' => [['bin', 'migrate-batch-v2'], ['command' => 'migrate-batch-v2', 'option' => null, 'force_stale' => false, 'force_job_id' => null]];
        yield 'migrate --clear-claims' => [['bin', 'migrate-batch-v2', '--clear-claims'], ['command' => 'migrate-batch-v2', 'option' => '--clear-claims', 'force_stale' => false, 'force_job_id' => null]];
        yield 'repair-jobs' => [['bin', 'repair-jobs'], ['command' => 'repair-jobs', 'option' => null, 'force_stale' => false, 'force_job_id' => null]];
        yield 'repair-jobs --force-stale' => [['bin', 'repair-jobs', '--force-stale'], ['command' => 'repair-jobs', 'option' => '--force-stale', 'force_stale' => true, 'force_job_id' => null]];
        yield 'repair-jobs --force-stale=uuid' => [['bin', 'repair-jobs', '--force-stale=' . $uuid], ['command' => 'repair-jobs', 'option' => '--force-stale=' . $uuid, 'force_stale' => true, 'force_job_id' => $uuid]];
    }

    /** @param list<string> $argv */
    #[DataProvider('validArguments')]
    public function testParseArgumentsAcceptsOnlyDocumentedOptions(array $argv, array $expected): void
    {
        self::assertSame($expected, AuditBatchMaintenanceCommands::parseArguments($argv));
    }

    /** @return iterable<string,array{0:list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'tres argumentos' => [['bin', 'repair-jobs', '--force-stale', 'extra']];
        yield 'clear-claims en otro comando' => [['bin', 'repair-jobs', '--clear-claims']];
        yield 'force-stale en migrate' => [['bin', 'migrate-batch-v2', '--force-stale']];
        yield 'segundo argumento en pause' => [['bin', 'pause', '--clear-claims']];
        yield 'segundo argumento en run' => [['bin', 'run', 'x']];
        yield 'force-stale vacío' => [['bin', 'repair-jobs', '--force-stale=']];
        yield 'force-stale no uuid' => [['bin', 'repair-jobs', '--force-stale=abc']];
        yield 'force-stale uuid v1' => [['bin', 'repair-jobs', '--force-stale=6fa459ea-ee8a-1ca4-894e-db77e160355e']];
        yield 'opción parecida' => [['bin', 'migrate-batch-v2', '--clear-claims=1']];
    }

    /** @param list<string> $argv */
    #[DataProvider('invalidArguments')]
    public function testParseArgumentsRejectsUnknownOptions(array $argv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Opcion desconocida');
        AuditBatchMaintenanceCommands::parseArguments($argv);
    }

    /** `W-F1-I4(a)`: migrate-batch-v2 añade `legacy_idempotency` tras migrar; `--clear-claims` llega a migrateBatchV2(true). */
    public function testMigrateAppendsLegacyIdempotencyAfterMigration(): void
    {
        $redis = new FakeMaintenanceRedis();
        $redis->pages['batch:idem:*'] = [['batch:idem:a', 'batch:idem:b']];
        $store = $this->createMock(BatchJobStore::class);
        $store->expects(self::once())->method('migrateBatchV2')->with(true)
            ->willReturnCallback(static function () use ($redis): array {
                $redis->log[] = 'migrate';
                return ['migrated_jobs' => 2];
            });

        $result = AuditBatchMaintenanceCommands::run(
            AuditBatchMaintenanceCommands::parseArguments(['bin', 'migrate-batch-v2', '--clear-claims']),
            $redis,
            new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2)),
            $store
        );

        self::assertSame(['migrated_jobs' => 2, 'legacy_idempotency' => ['scanned' => 2, 'expired' => 0]], $result);
        self::assertLessThan(
            array_search('scan:batch:idem:*:0', $redis->log, true),
            array_search('migrate', $redis->log, true),
            'La expiración de idempotencia legada corre después de la migración.'
        );
    }

    public function testMigrateWithoutClearClaimsAndFailureSkipsLegacyIdempotency(): void
    {
        $redis = new FakeMaintenanceRedis();
        $store = $this->createMock(BatchJobStore::class);
        $store->expects(self::once())->method('migrateBatchV2')->with(false)
            ->willThrowException(new \RuntimeException('migrate_reservation_failed'));

        try {
            AuditBatchMaintenanceCommands::run(
                AuditBatchMaintenanceCommands::parseArguments(['bin', 'migrate-batch-v2']),
                $redis,
                new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2)),
                $store
            );
            self::fail('Se esperaba la excepción de migración.');
        } catch (\RuntimeException $error) {
            self::assertSame('migrate_reservation_failed', $error->getMessage());
        }
        self::assertNotContains('scan:batch:idem:*:0', $redis->log, 'Si la migración falla no se expiran claves legadas.');
    }

    public function testRollbackAndRepairJobsDispatchToTheirImplementations(): void
    {
        $redis = new FakeMaintenanceRedis();
        $maintenance = new AuditExecutionMaintenance($redis, new AuditExecutionConfig(2));
        $store = $this->createMock(BatchJobStore::class);
        $store->expects(self::once())->method('rollbackBatchV2')->willReturn(['reverted_jobs' => 1]);
        $store->expects(self::once())->method('repairJobStates')->willReturn(['repaired_jobs' => 4, 'audits_recounted' => 9, 'generation_abandoned' => []]);
        $store->expects(self::once())->method('reconcileJobTelemetryMetrics')->willReturn([]);
        $forced = AuditEvent::deterministicUuidV4('cli-forced-job');
        $store->method('getJobSummary')->willReturn(['job_id' => $forced, 'status' => 'processing']);
        $store->expects(self::once())->method('reapStaleJob')->with($forced, self::isType('int'), 0, null)
            ->willReturn(['done' => true, 'cursor' => null, 'cancelled_audits' => 2, 'reaped' => true]);

        self::assertSame(['reverted_jobs' => 1],
            AuditBatchMaintenanceCommands::run(AuditBatchMaintenanceCommands::parseArguments(['bin', 'rollback-batch-v2']), $redis, $maintenance, $store));
        self::assertSame(['repaired_jobs' => 4, 'audits_recounted' => 9],
            AuditBatchMaintenanceCommands::run(AuditBatchMaintenanceCommands::parseArguments(['bin', 'repair-jobs']), $redis, $maintenance, $store));
        self::assertSame(['reaped_jobs' => 1, 'cancelled_audits' => 2, 'skipped_claimed' => 0],
            AuditBatchMaintenanceCommands::run(AuditBatchMaintenanceCommands::parseArguments(['bin', 'repair-jobs', '--force-stale=' . $forced]), $redis, $maintenance, $store));
    }

    public function testBatchJobStoreDoesNotDependOnMaintenance(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(BatchJobStore::class))->getFileName());

        self::assertStringNotContainsString('AuditExecutionMaintenance', $source, 'Evita el ciclo BatchJobStore → AuditExecutionMaintenance.');
    }
}
