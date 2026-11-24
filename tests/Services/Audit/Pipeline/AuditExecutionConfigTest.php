<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditExecutionConfig;
use App\Services\Audit\Pipeline\AuditTaskStage;
use Core\Env;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditExecutionConfigTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (AuditTaskStage::cases() as $stage) {
            $key = AuditExecutionConfig::jobSlotsVariable($stage);
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
        }
        Env::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
        Env::clearCache();
    }

    public function testEveryStageKeepsTheSingleTurnContractByDefault(): void
    {
        // Arrange:
        $explicit = new AuditExecutionConfig();

        // Act:
        $fromEnv = AuditExecutionConfig::fromEnv();

        // Assert:
        foreach (AuditTaskStage::cases() as $stage) {
            self::assertSame(1, $explicit->jobSlots($stage), $stage->value);
            self::assertSame(1, $fromEnv->jobSlots($stage), $stage->value);
        }
    }

    public function testFromEnvReadsOneVariablePerStage(): void
    {
        // Arrange:
        putenv('AUDIT_EXTRACTION_JOB_SLOTS=4');
        putenv('AUDIT_ORCHESTRATION_JOB_SLOTS=2');
        putenv('AUDIT_SEMANTIC_JOB_SLOTS=16');
        Env::clearCache();

        // Act:
        $config = AuditExecutionConfig::fromEnv();

        // Assert:
        self::assertSame(2, $config->jobSlots(AuditTaskStage::ORCHESTRATION));
        self::assertSame(4, $config->jobSlots(AuditTaskStage::EXTRACTION));
        self::assertSame(1, $config->jobSlots(AuditTaskStage::POLICY));
        self::assertSame(16, $config->jobSlots(AuditTaskStage::SEMANTIC));
    }

    public function testJobSlotsVariableNamesTheStage(): void
    {
        // Arrange / Act:
        $names = array_map(AuditExecutionConfig::jobSlotsVariable(...), AuditTaskStage::cases());

        // Assert:
        self::assertSame([
            'AUDIT_ORCHESTRATION_JOB_SLOTS',
            'AUDIT_EXTRACTION_JOB_SLOTS',
            'AUDIT_POLICY_JOB_SLOTS',
            'AUDIT_SEMANTIC_JOB_SLOTS',
        ], $names);
    }

    #[DataProvider('invalidSlotValues')]
    public function testFromEnvRejectsOutOfRangeOrNonIntegerSlots(string $value): void
    {
        // Arrange:
        putenv('AUDIT_EXTRACTION_JOB_SLOTS=' . $value);
        Env::clearCache();

        // Act / Assert:
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AUDIT_EXTRACTION_JOB_SLOTS_invalid');
        AuditExecutionConfig::fromEnv();
    }

    /** @return iterable<string,array{string}> */
    public static function invalidSlotValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above maximum' => [(string) (AuditExecutionConfig::MAX_JOB_SLOTS + 1)];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['2.5'];
        yield 'text' => ['abc'];
    }

    public function testBatchLockTtlDefaultsAndFloor(): void
    {
        $this->withEnv(['AUDIT_BATCH_LOCK_TTL_SECONDS' => null], function (): void {
            self::assertSame(1800, AuditExecutionConfig::batchLockTtlSeconds());
        });
        $this->withEnv(['AUDIT_BATCH_LOCK_TTL_SECONDS' => ''], function (): void {
            self::assertSame(1800, AuditExecutionConfig::batchLockTtlSeconds());
        });
        $this->withEnv(['AUDIT_BATCH_LOCK_TTL_SECONDS' => '10'], function (): void {
            self::assertSame(60, AuditExecutionConfig::batchLockTtlSeconds());
        });
    }

    public function testJobStaleAfterSecondsDefaultsAndFloorUsesLockTtl(): void
    {
        $this->withEnv(['AUDIT_JOB_STALE_AFTER_SECONDS' => null, 'AUDIT_BATCH_LOCK_TTL_SECONDS' => null], function (): void {
            self::assertSame(21600, AuditExecutionConfig::jobStaleAfterSeconds());
        });
        $this->withEnv(['AUDIT_JOB_STALE_AFTER_SECONDS' => '', 'AUDIT_BATCH_LOCK_TTL_SECONDS' => ''], function (): void {
            self::assertSame(21600, AuditExecutionConfig::jobStaleAfterSeconds());
        });
        // Piso max(lockTtl, 3600): un valor menor se eleva al piso.
        $this->withEnv(['AUDIT_JOB_STALE_AFTER_SECONDS' => '100', 'AUDIT_BATCH_LOCK_TTL_SECONDS' => null], function (): void {
            self::assertSame(3600, AuditExecutionConfig::jobStaleAfterSeconds());
        });
        // Con un lock TTL mayor que 3600, el piso sube al lock TTL.
        $this->withEnv(['AUDIT_JOB_STALE_AFTER_SECONDS' => '100', 'AUDIT_BATCH_LOCK_TTL_SECONDS' => '5000'], function (): void {
            self::assertSame(5000, AuditExecutionConfig::jobStaleAfterSeconds());
        });
    }

    public function testFreezeQueryTimeoutDefaultsAndFloor(): void
    {
        $this->withEnv(['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => null], function (): void {
            self::assertSame(300, AuditExecutionConfig::freezeQueryTimeoutSeconds());
        });
        $this->withEnv(['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => ''], function (): void {
            self::assertSame(300, AuditExecutionConfig::freezeQueryTimeoutSeconds());
        });
        $this->withEnv(['AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS' => '5'], function (): void {
            self::assertSame(30, AuditExecutionConfig::freezeQueryTimeoutSeconds());
        });
    }

    public function testNonNumericTimingValuesAreConfigurationErrors(): void
    {
        $this->withEnv(['AUDIT_BATCH_LOCK_TTL_SECONDS' => 'abc'], function (): void {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('AUDIT_BATCH_LOCK_TTL_SECONDS_invalid');
            AuditExecutionConfig::batchLockTtlSeconds();
        });
    }

    /**
     * Fija variables de entorno (null = no definida) durante el callback y las restaura siempre.
     *
     * @param array<string,?string> $vars
     */
    private function withEnv(array $vars, callable $callback): void
    {
        $previous = [];
        foreach ($vars as $key => $value) {
            $previous[$key] = getenv($key);
            $value === null ? putenv($key) : putenv($key . '=' . $value);
        }
        Env::clearCache();
        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv($key . '=' . $value);
            }
            Env::clearCache();
        }
    }

    public function testConstructorRejectsUnknownStageAndInvalidSlots(): void
    {
        // Arrange:
        $invalid = [
            ['unknown' => 2],
            ['extraction' => 0],
            ['extraction' => AuditExecutionConfig::MAX_JOB_SLOTS + 1],
            ['extraction' => '4'],
        ];

        // Act / Assert:
        foreach ($invalid as $slots) {
            try {
                new AuditExecutionConfig(AuditExecutionConfig::VERSION, 20000, $slots);
                self::fail('La configuración inválida debe rechazarse: ' . json_encode($slots));
            } catch (InvalidArgumentException $error) {
                self::assertSame('execution_job_slots_invalid', $error->getMessage());
            }
        }
    }
}

