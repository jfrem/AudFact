import { formatNumber } from "@/lib/formatters";

type MetricsMap = Record<string, string | number>;

export function AuditMetricsPanel({ metrics }: { metrics?: MetricsMap }) {
  if (!metrics || Object.keys(metrics).length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-white/10 bg-card px-4 py-5 text-sm text-slate-400">
        Este resultado no expuso métricas estructuradas.
      </div>
    );
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
      {Object.entries(metrics).map(([key, value]) => (
        <div
          key={key}
          className="surface-subtle rounded-lg px-4 py-4"
        >
          <p className="text-[11px] uppercase tracking-[0.12em] text-slate-400">
            {key}
          </p>
          <p className="mt-2 text-2xl font-semibold tracking-tight text-white">
            {typeof value === "number" ? formatNumber(value) : String(value)}
          </p>
        </div>
      ))}
    </div>
  );
}
