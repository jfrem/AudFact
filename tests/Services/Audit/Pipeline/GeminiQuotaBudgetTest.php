<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\GeminiQuotaBudget;
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
}
