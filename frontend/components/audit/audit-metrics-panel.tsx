import { formatNumber } from "@/lib/formatters";

type MetricsMap = Record<string, string | number>;

export function AuditMetricsPanel({ metrics }: { metrics?: MetricsMap }) {
  if (!metrics || Object.keys(metrics).length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-5 text-sm text-muted-foreground">
        Este resultado no expuso métricas estructuradas.
      </div>
    );
  }

  return (
    <div className="grid overflow-hidden rounded-lg border border-border sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
      {Object.entries(metrics).map(([key, value]) => (
        <div
          key={key}
          className="border-b border-border bg-background px-4 py-4 last:border-b-0 sm:border-r xl:border-b-0 xl:last:border-r-0"
        >
          <p className="text-[11px] uppercase tracking-[0.12em] text-slate-400">
            {key}
          </p>
          <p className="mt-2 text-2xl font-semibold tracking-tight text-foreground">
            {typeof value === "number" ? formatNumber(value) : String(value)}
          </p>
        </div>
      ))}
    </div>
  );
}
