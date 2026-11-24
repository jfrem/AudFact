<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\BatchJobStore;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class BatchJobStoreMetricsTest extends TestCase
{
    public function testCompletionMetricsTransitionUsesPreviousJobStatus(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis
            ->expects($this->once())
            ->method('eval')
            ->with(
                $this->callback(function (string $script): bool {
                    $capture = strpos(
                        $script,
                        "local oldJobStatus = tostring(job['status'] or 'pending')"
                    );
                    $jobMutation = strpos($script, "job['status'] =");

                    $this->assertNotFalse($capture);
                    $this->assertNotFalse($jobMutation);
                    $this->assertLessThan($jobMutation, $capture);
                    $this->assertStringNotContainsString(
                        'local oldJobStatus = previousStatus',
                        $script
                    );
                    $this->assertStringContainsString(
                        "if oldJobStatus == 'queued' or oldJobStatus == 'pending' then",
                        $script
                    );
                    $this->assertStringContainsString(
                        "elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' then",
                        $script
                    );

                    return true;
                }),
                [
                    BatchJobStore::jobKey('job-metrics'),
                    'telemetry:async_metrics',
                    BatchJobStore::jobAuditsKey('job-metrics'),
                ],
                $this->callback(
                    static fn (array $args): bool =>
                        ($args[0] ?? null) === 'audit-metrics'
                        && ($args[1] ?? null) === 'completed'
                        && count($args) === 7
                )
            )
            ->willReturn(1);

        $store = new BatchJobStore($redis);

        $this->assertTrue(
            $store->markAuditCompletedInJob(
                'job-metrics',
                'audit-metrics',
                'completed',
                1250
            )
        );
    }

    /** `INV-08`: se preserva `failed`, no se reabre un job terminal y no se re-escanea durante `reaping`. */
    public function testCompletionScriptPreservesFailedAndDoesNotMutateTerminalJobs(): void
    {
        $captured = $this->captureCompletionScript();

        // Preserva el estado 'failed' (sentencia validada por la suite previa).
        $this->assertStringContainsString("if oldJobStatus ~= 'failed' then", $captured);

        // Definición de job terminal: estados terminales, reaping o stale_reason.
        $this->assertStringContainsString("oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors'", $captured);
        $this->assertStringContainsString("job['reaping'] == true", $captured);
        $this->assertStringContainsString("job['stale_reason'] ~= nil and job['stale_reason'] ~= cjson.null", $captured);

        // Orden: reaping retorna antes de tocar KEYS[3]; el decremento de audits_in_flight precede a la guarda terminal.
        $reaping = strpos($captured, "if job['reaping'] == true then return 1 end");
        $hget = strpos($captured, "redis.call('HGET', KEYS[3], auditId)");
        $decrement = strpos($captured, "redis.call('HINCRBY', KEYS[2], 'audits_in_flight', -1)");
        $terminalGuard = strpos($captured, 'if jobTerminal then return 1 end');
        $firstJobMutation = strpos($captured, "job['failed'] = (tonumber(job['failed']) or 0) + 1");

        $this->assertNotFalse($reaping);
        $this->assertNotFalse($hget);
        $this->assertNotFalse($decrement);
        $this->assertNotFalse($terminalGuard);
        $this->assertNotFalse($firstJobMutation);
        $this->assertLessThan($hget, $reaping);
        $this->assertLessThan($terminalGuard, $decrement);
        $this->assertLessThan($firstJobMutation, $terminalGuard);
    }

    /** `W-F2-B2`: la clave dinámica `jobs:active:nit` se construye con el prefijo derivado de `KEYS[1]`. */
    public function testCompletionScriptDerivesPrefixForActiveNitCleanupAndWritesProgress(): void
    {
        $captured = $this->captureCompletionScript();

        $this->assertStringContainsString(
            "local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state\$') or ''",
            $captured
        );
        $this->assertStringContainsString("prefix .. 'jobs:active:nit:'", $captured);
        $this->assertStringNotContainsString("redis.call('HDEL', 'jobs:active:nit:", $captured);
        $this->assertStringContainsString("job['last_progress_at'] = nowMs", $captured);
        // Sin mapa inline `job['audits']` (INV-06): las entradas viven en KEYS[3].
        $this->assertStringNotContainsString("job['audits']", $captured);
    }

    private function captureCompletionScript(): string
    {
        $captured = '';
        $redis = $this->createMock(RedisClient::class);
        $redis->method('eval')->willReturnCallback(
            static function (string $script) use (&$captured): int {
                $captured = $script;
                return 1;
            }
        );

        (new BatchJobStore($redis))->markAuditCompletedInJob('job-x', 'audit-x', 'failed', 10, 'extraction');

        $this->assertNotSame('', $captured);

        return $captured;
    }
}
