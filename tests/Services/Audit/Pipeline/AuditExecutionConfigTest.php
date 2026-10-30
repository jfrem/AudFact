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
        yield 'above maximum' => ['17'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['2.5'];
        yield 'text' => ['abc'];
    }

    public function testConstructorRejectsUnknownStageAndInvalidSlots(): void
    {
        // Arrange:
        $invalid = [
            ['unknown' => 2],
            ['extraction' => 0],
            ['extraction' => 17],
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

