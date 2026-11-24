<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Services\Audit\AuditBatchOrchestrator;
use App\Services\Audit\BatchChunkState;
use Core\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\CapturesLogs;
use Tests\Support\FakeBatchInvoicesModel;
use Tests\Support\InMemoryAuditStateStore;
use Tests\Support\InMemoryBatchJobStore;
use Tests\Support\RecordingAuditEventPublisher;

final class LoggerSanitizationTest extends TestCase
{
    use CapturesLogs;

    protected function tearDown(): void
    {
        $this->stopLogCapture();
    }

    public function testFacNitSecIsRedactedAtTopLevel(): void
    {
        $sanitized = $this->sanitize(['fac_nit_sec' => 2624, 'job_id' => 'abc']);

        self::assertSame('[REDACTED]', $sanitized['fac_nit_sec']);
        self::assertSame('abc', $sanitized['job_id']);
    }

    public function testFacNitSecIsRedactedInNestedContextAndCaseInsensitively(): void
    {
        $sanitized = $this->sanitize(['meta' => ['FAC_NIT_SEC' => '2624', 'count' => 3]]);

        self::assertSame('[REDACTED]', $sanitized['meta']['FAC_NIT_SEC']);
        self::assertSame(3, $sanitized['meta']['count']);
    }

    public function testRawNitIsNotPresentInSerializedContext(): void
    {
        $sanitized = $this->sanitize(['fac_nit_sec' => 2624, 'message_note' => 'ok']);

        self::assertStringNotContainsString('2624', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function testMaskedNitKeyPassesThroughWithOnlyTheLastThreeDigits(): void
    {
        $sanitized = $this->sanitize(['fac_nit_sec_masked' => AuditBatchOrchestrator::maskNit(2624)]);

        self::assertSame('***624', $sanitized['fac_nit_sec_masked']);
    }

    /** R-PII: el cron no puede volcar el NIT crudo en ningún contexto de log. */
    public function testScheduleDailyBatchesLogContextsNeverCarryTheRawNit(): void
    {
        $source = (string) file_get_contents(self::scheduleScriptPath());
        $calls = self::loggerCalls($source);

        self::assertGreaterThanOrEqual(8, count($calls), 'Se esperaban al menos 8 llamadas a Logger en el cron.');
        foreach ($calls as $call) {
            self::assertDoesNotMatchRegularExpression("/'fac_nit_sec'\\s*=>/", $call, "Clave cruda en: {$call}");
            self::assertDoesNotMatchRegularExpression('/(?<!maskNit\()\$facNitSec\b/', $call, "NIT crudo en: {$call}");
        }

        $perClient = array_filter($calls, static fn (string $call): bool => str_contains($call, 'fac_nit_sec_masked'));
        self::assertGreaterThanOrEqual(5, count($perClient), 'Los logs por cliente deben usar fac_nit_sec_masked.');
        foreach ($perClient as $call) {
            self::assertStringContainsString('AuditBatchOrchestrator::maskNit($facNitSec)', $call);
        }
    }

    public function testScheduleDailyBatchesUsesExclusiveInitAndReleasesIdempotencyOnOverlap(): void
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(self::scheduleScriptPath()));

        self::assertStringNotContainsString('->initJob(', $source);
        self::assertStringContainsString('$jobStore->initJobExclusive($jobId, $facNitSec, $dateFrom, $dateTo, $limit)', $source);

        $overlapBlock = self::between($source, 'if (($initResult[\'created\'] ?? false) !== true) {', 'continue;');
        self::assertStringContainsString('releaseIdempotencyKeyIfMatches($idempotencyKey, $jobId)', $overlapBlock);
        self::assertStringContainsString('$summary[\'skipped_duplicate\']++', $overlapBlock);
        self::assertStringContainsString("'active_job_id'", $overlapBlock);

        $catchBlock = self::between($source, '} catch (\\Throwable $e) {', '$summary[\'errors\']++;' . "\n    }\n}");
        self::assertStringContainsString('$jobStore->deleteJob($jobId)', $catchBlock);
        self::assertStringContainsString('releaseIdempotencyKeyIfMatches($idempotencyKey, $jobId)', $catchBlock);
    }

    /** R-PII: el orquestador no escribe el NIT crudo en ninguno de sus logs (congelación, candidatas inválidas, fallos). */
    public function testOrchestratorLogContextsNeverCarryTheRawNit(): void
    {
        $jobId = '33333333-3333-4333-8333-333333333333';
        $rows = [
            ['NitSec' => 2624, 'DisId' => '', 'Dispensa' => 'D0', 'DisFecSol' => '2026-06-12T00:00:00'],
            ['NitSec' => 2624, 'DisId' => '1001', 'Dispensa' => 'D1', 'DisFecSol' => '2026-06-12T00:00:01'],
            ['NitSec' => 2624, 'DisId' => '1002', 'Dispensa' => 'D2', 'DisFecSol' => '2026-06-12T00:00:02'],
        ];
        $store = new InMemoryBatchJobStore();
        $invoices = new FakeBatchInvoicesModel($rows);
        $orchestrator = new AuditBatchOrchestrator(new InMemoryAuditStateStore(), $store, new RecordingAuditEventPublisher(), $invoices);
        $store->initJobExclusive($jobId, 2624, '2026-06-01', '2026-06-30', 10);
        $this->startLogCapture();

        $orchestrator->enqueueBatch(2624, '2026-06-01', '2026-06-30', 10, $jobId, 'w1', new BatchChunkState(chunkSizeOverride: 2));
        // Chunk fallido: el log de error del orquestador tampoco debe llevar el NIT.
        $invoices->onQuery = static function (): void {
            throw new \RuntimeException('falla simulada');
        };
        $other = '44444444-4444-4444-8444-444444444444';
        $store->initJobExclusive($other, 9999, '2026-06-01', '2026-06-30', 10);
        try {
            $orchestrator->enqueueBatch(9999, '2026-06-01', '2026-06-30', 10, $other, 'w1');
        } catch (\RuntimeException) {
        }

        $messages = array_column($this->capturedLogs(), 'message');
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'snapshot SQL batch congelado')));
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'candidata inválida')));
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'enqueueBatch falló')));
        self::assertStringNotContainsString('2624', $this->capturedContextsJson());
        self::assertStringNotContainsString('9999', $this->capturedContextsJson());
        $freeze = $this->logContextsContaining('snapshot SQL batch congelado');
        self::assertSame('***624', $freeze[0]['fac_nit_sec_masked']);
    }

    /** @return array<string,mixed> */
    private function sanitize(array $context): array
    {
        $method = new ReflectionMethod(Logger::class, 'sanitizeContext');
        /** @var array<string,mixed> $result */
        $result = $method->invoke(null, $context);

        return $result;
    }

    private static function scheduleScriptPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/schedule-daily-batches.php';
    }

    /**
     * Extrae el texto completo de cada llamada `Logger::nivel(...)` (balanceando paréntesis y saltando strings).
     *
     * @return array<int,string>
     */
    private static function loggerCalls(string $source): array
    {
        $calls = [];
        $offset = 0;
        while (preg_match('/Logger::(info|warning|error|critical)\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $i = $start + strlen($match[0][0]);
            $depth = 1;
            $length = strlen($source);
            while ($i < $length && $depth > 0) {
                $char = $source[$i];
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $i++;
                    while ($i < $length && $source[$i] !== $quote) {
                        $i += $source[$i] === '\\' ? 2 : 1;
                    }
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
                $i++;
            }
            $calls[] = substr($source, $start, $i - $start);
            $offset = $i;
        }

        return $calls;
    }

    private static function between(string $source, string $from, string $to): string
    {
        $start = strpos($source, $from);
        self::assertNotFalse($start, "No se encontró: {$from}");
        $end = strpos($source, $to, $start);
        self::assertNotFalse($end, "No se encontró: {$to}");

        return substr($source, $start, $end - $start);
    }
}
