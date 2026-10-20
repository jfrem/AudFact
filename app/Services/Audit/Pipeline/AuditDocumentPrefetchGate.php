<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Closure;

/** Limita nuevas descargas; la recuperación pendiente y los extractores siguen drenando. */
final class AuditDocumentPrefetchGate
{
    public const HIGH_PERCENT = 90;
    public const LOW_PERCENT = 70;
    public const CHECK_INTERVAL_MS = 1000;

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
        if ($this->closedAtMs === null) {
            if ($usage['reserved_bytes'] * 100 >= $usage['max_bytes'] * self::HIGH_PERCENT
                || $usage['reserved_entries'] * 100 >= $usage['max_entries'] * self::HIGH_PERCENT) {
                $this->closedAtMs = $now;
                ($this->record)(AuditDocumentPrefetchEvent::PAUSED, 0);
            }
        } elseif ($usage['reserved_bytes'] * 100 <= $usage['max_bytes'] * self::LOW_PERCENT
            && $usage['reserved_entries'] * 100 <= $usage['max_entries'] * self::LOW_PERCENT) {
            $waitMs = max(0, $now - $this->closedAtMs);
            $this->closedAtMs = null;
            ($this->record)(AuditDocumentPrefetchEvent::WAITED, $waitMs);
        }

        return $this->closedAtMs === null;
    }
}
