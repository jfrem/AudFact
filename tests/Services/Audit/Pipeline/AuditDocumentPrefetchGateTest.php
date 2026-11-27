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
        $redis->method('eval')->willReturn([$bytes, $entries, 0]);
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
            [900, 90, 0], [700, 71, 0], [701, 70, 0], [700, 70, 0]
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

    public function testClosesByResidencyWhenOldestBlobExceedsHalfTtl(): void
    {
        // Arrange: TTL 3600 s; el blob más antiguo tiene 50 % del TTL con ocupación 20 %.
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([100, 20, 1800000]);
        $events = [];
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (AuditDocumentPrefetchEvent $event, int $duration) use (&$events): void {
                $events[] = [$event, $duration];
            }, static fn(): int => 0);

        // Act:
        $actual = $gate->canRead();

        // Assert:
        self::assertFalse($actual);
        self::assertSame([[AuditDocumentPrefetchEvent::PAUSED, 0]], $events);
    }

    public function testDoesNotCloseByResidencyBelowTenPercentOccupancy(): void
    {
        // Arrange: un único blob huérfano envejecido no cierra la compuerta (ocupación 5 % < 10 %).
        $redis = $this->createStub(RedisClient::class);
        $redis->method('eval')->willReturn([50, 5, 3600000]);
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (): void {}, static fn(): int => 0);

        // Act / Assert:
        self::assertTrue($gate->canRead());
    }

    public function testReopensOnlyWhenResidencyIsAtOrBelowLowWatermark(): void
    {
        // Arrange: cierra por residencia en t=0; en t=1000 la edad es exactamente 25 % del TTL.
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(2))->method('eval')->willReturnOnConsecutiveCalls(
            [100, 20, 1800000], [100, 20, 900000]
        );
        $now = 0;
        $events = [];
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (AuditDocumentPrefetchEvent $event, int $duration) use (&$events): void {
                $events[] = [$event, $duration];
            }, static function () use (&$now): int { return $now; });

        // Act:
        $results = [$gate->canRead()];
        $now = 1000;
        $results[] = $gate->canRead();

        // Assert:
        self::assertSame([false, true], $results);
        self::assertSame([[AuditDocumentPrefetchEvent::PAUSED, 0], [AuditDocumentPrefetchEvent::WAITED, 1000]], $events);
    }

    public function testStaysClosedWhileResidencyIsAboveLowWatermark(): void
    {
        // Arrange: edad 25,0001 % del TTL todavía impide reabrir.
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(2))->method('eval')->willReturnOnConsecutiveCalls(
            [100, 20, 1800000], [100, 20, 900001]
        );
        $now = 0;
        $gate = new AuditDocumentPrefetchGate(new AuditDocumentCache($redis, 1000, 100),
            static function (): void {}, static function () use (&$now): int { return $now; });

        // Act:
        $results = [$gate->canRead()];
        $now = 1000;
        $results[] = $gate->canRead();

        // Assert:
        self::assertSame([false, false], $results);
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
