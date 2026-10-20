<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\AuditDocumentCache;
use App\Services\Audit\Pipeline\AuditDocumentPrefetchEvent;
use App\Services\Audit\Pipeline\AuditDocumentPrefetchGate;
use Core\RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditDocumentPrefetchGateTest extends TestCase
{
    #[DataProvider('capacityBoundaries')]
    public function testGateClosesWhenEitherCapacityLimitReachesHighWatermark(int $bytes, int $entries, bool $allowed): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([$bytes, $entries]);
        $events = [];
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (AuditDocumentPrefetchEvent $event, int $duration) use (&$events): void {
                $events[] = [$event, $duration];
            }, static fn(): int => 0);

        // Act:
        $actual = $gate->canRead();

        // Assert:
        self::assertSame($allowed, $actual);
        self::assertSame($allowed ? [] : [[AuditDocumentPrefetchEvent::PAUSED, 0]], $events);
    }

    public static function capacityBoundaries(): iterable
    {
        yield 'below both limits' => [899, 89, true];
        yield 'byte boundary' => [900, 0, false];
        yield 'entry boundary' => [0, 90, false];
        yield 'full' => [1000, 100, false];
    }

    public function testGateResumesOnlyBelowBothLowWatermarksAndCountsEachPauseOnce(): void
    {
        // Arrange:
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(4))->method('eval')->willReturnOnConsecutiveCalls(
            [900, 90], [700, 71], [701, 70], [700, 70]
        );
        $now = 0;
        $events = [];
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (AuditDocumentPrefetchEvent $event, int $duration) use (&$events): void {
                $events[] = [$event, $duration];
            }, static function () use (&$now): int { return $now; });

        // Act:
        $results = [$gate->canRead(), $gate->canRead()];
        $now = 1000;
        $results[] = $gate->canRead();
        $now = 2000;
        $results[] = $gate->canRead();
        $now = 3000;
        $results[] = $gate->canRead();

        // Assert:
        self::assertSame([false, false, false, false, true], $results);
        self::assertSame([[AuditDocumentPrefetchEvent::PAUSED, 0], [AuditDocumentPrefetchEvent::WAITED, 3000]], $events);
    }

    public function testUnobservableCapacityStopsIntakeInsteadOfAllowingUnbudgetedDownloads(): void
    {
        // Arrange:
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willThrowException(new \RuntimeException('Redis unavailable'));
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (): void {}, static fn(): int => 0);

        // Assert:
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Redis unavailable');

        // Act:
        $gate->canRead();
    }
}
