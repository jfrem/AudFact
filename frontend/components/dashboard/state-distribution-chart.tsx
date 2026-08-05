"use client";

/**
 * State Distribution Chart — CSS-only stacked bar.
 *
 * Replaces the previous ECharts donut. Rationale:
 * 1. ~800KB bundle dependency eliminated for a 3-slice chart.
 * 2. Avoids the "hero-metric in donut center" anti-pattern.
 * 3. Stacked horizontal bar is denser and more forensic.
 * 4. Handles zero-data gracefully with an empty-state bar.
 */

import { formatNumber } from "@/lib/formatters";

type ChartSlice = {
  name: string;
  value: number;
  color: string;
};

export function StateDistributionChart({ data }: { data: ChartSlice[] }) {
  const total = data.reduce((sum, slice) => sum + slice.value, 0);
  const hasData = total > 0;

  return (
    <div className="space-y-4">
      {/* Stacked bar */}
      <div className="space-y-2">
        <div className="flex items-baseline justify-between">
          <span className="text-sm text-slate-400">
            {hasData
              ? `${formatNumber(total)} registros analizados`
              : "Sin registros analizados"}
          </span>
        </div>

        <div
          className="flex h-3 w-full overflow-hidden rounded-md bg-white/[0.04]"
          role="img"
          aria-label={`Distribución: ${data.map((s) => `${s.name} ${s.value}`).join(", ")}`}
        >
          {hasData ? (
            data.map((slice) => {
              const pct = (slice.value / total) * 100;
              if (pct === 0) return null;
              return (
                <div
                  key={slice.name}
                  className="transition-all duration-500 ease-out first:rounded-l-md last:rounded-r-md"
                  style={{
                    width: `${pct}%`,
                    backgroundColor: slice.color,
                    minWidth: pct > 0 ? "4px" : 0,
                  }}
                  title={`${slice.name}: ${formatNumber(slice.value)} (${pct.toFixed(1)}%)`}
                />
              );
            })
          ) : (
            <div className="h-full w-full rounded-md bg-white/[0.06]" />
          )}
        </div>
      </div>

      {/* Legend as inline row */}
      <div className="flex flex-wrap gap-x-5 gap-y-2">
        {data.map((slice) => {
          const pct = hasData ? ((slice.value / total) * 100).toFixed(1) : "0";
          return (
            <div key={slice.name} className="flex items-center gap-2">
              <span
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: slice.color }}
              />
              <span className="text-sm text-slate-400">{slice.name}</span>
              <span className="tabular-nums text-sm font-medium text-slate-200">
                {formatNumber(slice.value)}
              </span>
              {hasData && (
                <span className="text-xs tabular-nums text-slate-600">
                  {pct}%
                </span>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
