<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Closure;

/**
 * Limita nuevas descargas; la recuperación pendiente y los extractores siguen drenando.
 *
 * Cierra por capacidad (bytes o entradas >= 90 %) o por residencia (el blob más antiguo
 * alcanza el 50 % del TTL con ocupación >= 10 %, DEC-F3-04). Reabre con capacidad <= 70 %
 * y edad del blob más antiguo <= 25 % del TTL (o ocupación insuficiente para contar).
 */
final class AuditDocumentPrefetchGate
{
    public const HIGH_PERCENT = 90;
    public const LOW_PERCENT = 70;
    public const CHECK_INTERVAL_MS = 1000;
    public const RESIDENCY_HIGH_PERCENT = 50;
    public const RESIDENCY_LOW_PERCENT = 25;
    public const RESIDENCY_MIN_OCCUPANCY_PERCENT = 10;

    private ?int $checkedAtMs = null;
    private ?int $closedAtMs = null;
    private readonly Closure $clockMs;

    /** @param Closure(AuditDocumentPrefetchEvent,int):void $record */
    public function __construct(
        private readonly AuditDocumentCache $cache,
        private readonly Closure $record,
        ?Closure $clockMs = null
    ) {
        $this->clockMs = $clockMs ?? static fn(): int => intdiv(hrtime(true), 1000000);
    }

    public function canRead(): bool
    {
        $now = ($this->clockMs)();
        if ($this->checkedAtMs !== null && $now - $this->checkedAtMs < self::CHECK_INTERVAL_MS) {
            return $this->closedAtMs === null;
        }

        $usage = $this->cache->usage();
        $this->checkedAtMs = $now;
        $occupied = $usage['reserved_entries'] * 100 >= $usage['max_entries'] * self::RESIDENCY_MIN_OCCUPANCY_PERCENT;
        $atCapacityHigh = $usage['reserved_bytes'] * 100 >= $usage['max_bytes'] * self::HIGH_PERCENT
            || $usage['reserved_entries'] * 100 >= $usage['max_entries'] * self::HIGH_PERCENT;
        $residencyHigh = $occupied
            && $usage['oldest_age_ms'] * 100 >= $usage['ttl_ms'] * self::RESIDENCY_HIGH_PERCENT;
        $residencyLow = $occupied
            && $usage['oldest_age_ms'] * 100 > $usage['ttl_ms'] * self::RESIDENCY_LOW_PERCENT;

        if ($this->closedAtMs === null) {
            if ($atCapacityHigh || $residencyHigh) {
                $this->closedAtMs = $now;
                ($this->record)(AuditDocumentPrefetchEvent::PAUSED, 0);
            }
        } elseif ($usage['reserved_bytes'] * 100 <= $usage['max_bytes'] * self::LOW_PERCENT
            && $usage['reserved_entries'] * 100 <= $usage['max_entries'] * self::LOW_PERCENT
            && !$residencyLow) {
            $waitMs = max(0, $now - $this->closedAtMs);
            $this->closedAtMs = null;
            ($this->record)(AuditDocumentPrefetchEvent::WAITED, $waitMs);
        }

        return $this->closedAtMs === null;
    }
}
