<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\GeminiQuotaBudget;
use Core\Env;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeminiQuotaBudgetTest extends TestCase
{
    #[DataProvider('pacificDays')]
    public function testDailyQuotaUsesPacificMidnightAcrossDst(string $instant, string $date, int $hours): void
    {
        // Arrange:
        $milliseconds = (new \DateTimeImmutable($instant))->getTimestamp() * 1000;

        // Act:
        [$actualDate, $start, $end] = GeminiQuotaBudget::dayWindow($milliseconds);

        // Assert:
        self::assertSame($date, $actualDate);
        self::assertSame($hours * 3600000, $end - $start);
        self::assertLessThanOrEqual($milliseconds, $start);
        self::assertGreaterThan($milliseconds, $end);
    }

    public static function pacificDays(): iterable
    {
        yield 'spring before jump' => ['2026-03-08T09:59:59Z', '2026-03-08', 23];
        yield 'spring after jump' => ['2026-03-08T10:00:00Z', '2026-03-08', 23];
        yield 'fall first hour' => ['2026-11-01T08:30:00Z', '2026-11-01', 25];
        yield 'fall repeated hour' => ['2026-11-01T09:30:00Z', '2026-11-01', 25];
        yield 'normal day' => ['2026-10-08T12:00:00Z', '2026-10-08', 24];
    }

    public function testUnconfiguredProjectCannotActivateBudget(): void
    {
        // Arrange:
        $limits = ['scope' => 'UNCONFIGURED', 'model' => 'gemini-3.7-flash', 'rpm' => 1,
            'tpm' => 1, 'rpd' => 1, 'inflight' => 1];

        // Assert:
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gemini_quota_scope_required');

        // Act:
        new GeminiQuotaBudget($this->createStub(RedisClient::class), $limits);
    }

    public function testUsesSensibleDefaultsWhenEnvironmentQuotaVariablesAreOmitted(): void
    {
        $keys = ['GEMINI_QUOTA_SCOPE', 'GEMINI_MODEL', 'GEMINI_QUOTA_RPM', 'GEMINI_QUOTA_TPM', 'GEMINI_QUOTA_RPD', 'GEMINI_QUOTA_MAX_INFLIGHT'];
        foreach ($keys as $key) {
            Env::set($key, null);
        }

        $budget = new GeminiQuotaBudget($this->createStub(RedisClient::class));
        $this->assertInstanceOf(GeminiQuotaBudget::class, $budget);
    }

    #[DataProvider('missingOrInvalidEnvCases')]
    public function testRejectsMissingOrInvalidEnvironmentQuotaVariables(array $env, string $expectedMessage): void
    {
        $keys = ['GEMINI_QUOTA_SCOPE', 'GEMINI_MODEL', 'GEMINI_QUOTA_RPM', 'GEMINI_QUOTA_TPM', 'GEMINI_QUOTA_RPD', 'GEMINI_QUOTA_MAX_INFLIGHT'];
        foreach ($keys as $key) {
            Env::set($key, $env[$key] ?? null);
        }

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($expectedMessage);

            new GeminiQuotaBudget($this->createStub(RedisClient::class));
        } finally {
            foreach ($keys as $key) {
                Env::set($key, null);
            }
        }
    }

    public static function missingOrInvalidEnvCases(): iterable
    {
        $valid = [
            'GEMINI_QUOTA_SCOPE' => 'audfact-project-1',
            'GEMINI_MODEL' => 'gemini-3.7-flash',
            'GEMINI_QUOTA_RPM' => '100',
            'GEMINI_QUOTA_TPM' => '200000',
            'GEMINI_QUOTA_RPD' => '10000',
            'GEMINI_QUOTA_MAX_INFLIGHT' => '4',
        ];

        yield 'zero GEMINI_QUOTA_RPM' => [
            array_replace($valid, ['GEMINI_QUOTA_RPM' => '0']),
            'GEMINI_QUOTA_RPM_invalid',
        ];
        yield 'negative GEMINI_QUOTA_RPM' => [
            array_replace($valid, ['GEMINI_QUOTA_RPM' => '-1']),
            'GEMINI_QUOTA_RPM_invalid',
        ];
        yield 'zero GEMINI_QUOTA_TPM' => [
            array_replace($valid, ['GEMINI_QUOTA_TPM' => '0']),
            'GEMINI_QUOTA_TPM_invalid',
        ];
        yield 'zero GEMINI_QUOTA_RPD' => [
            array_replace($valid, ['GEMINI_QUOTA_RPD' => '0']),
            'GEMINI_QUOTA_RPD_invalid',
        ];
        yield 'UNCONFIGURED GEMINI_QUOTA_SCOPE' => [
            array_replace($valid, ['GEMINI_QUOTA_SCOPE' => 'UNCONFIGURED']),
            'gemini_quota_scope_required',
        ];
        yield 'zero GEMINI_QUOTA_MAX_INFLIGHT' => [
            array_replace($valid, ['GEMINI_QUOTA_MAX_INFLIGHT' => '0']),
            'GEMINI_QUOTA_MAX_INFLIGHT_invalid',
        ];
        yield 'excessive GEMINI_QUOTA_MAX_INFLIGHT' => [
            array_replace($valid, ['GEMINI_QUOTA_MAX_INFLIGHT' => '64']),
            'GEMINI_QUOTA_MAX_INFLIGHT_invalid',
        ];
    }
}
