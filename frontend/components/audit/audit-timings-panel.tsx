import { Activity, Cpu, FileText, Gauge, Timer } from "lucide-react";
import type { ReactNode } from "react";

import type {
  AuditGeminiTimingSummary,
  AuditPhaseTimings,
  AuditTimingSummary,
} from "@/lib/schemas/domain";
import { formatDurationMs, formatNumber } from "@/lib/formatters";
import { cn } from "@/lib/utils";

type AuditTimingsPanelProps = {
  timings?: AuditPhaseTimings | null;
  totalDurationMs?: number | null;
  documentsProcessed?: number | null;
  className?: string;
};

type PhaseRow = {
  id: string;
  label: string;
  timing?: AuditTimingSummary | null;
};

type GeminiRow = {
  id: string;
  label: string;
  timing?: AuditGeminiTimingSummary | null;
};

export function AuditTimingsPanel({
  timings,
  totalDurationMs = null,
  documentsProcessed = null,
  className,
}: AuditTimingsPanelProps) {
  if (!timings) {
    return (
      <div
        className={cn(
          "rounded-lg border border-dashed border-white/10 bg-white/[0.02] px-4 py-5 text-sm",
          className,
        )}
      >
        <p className="font-medium text-slate-200">Sin timings persistidos</p>
        <p className="mt-1 max-w-2xl text-slate-400">
          Este resultado no incluye métricas de fase. Los registros anteriores a la
          persistencia de timings pueden mostrar solo la duración total.
        </p>
      </div>
    );
  }

  const phaseRows = buildPhaseRows(timings);
  const geminiRows = buildGeminiRows(timings);
  const dominantPhase = getDominantPhase(phaseRows);
  const docsTotal = timings.docs_total || documentsProcessed || 0;
  const geminiTotal = timings.gemini_total;

  return (
    <div className={cn("space-y-5", className)}>
      <div className="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-white/10 pb-4">
        <InlineMetric
          icon={<Timer className="h-4 w-4" />}
          label="Duración total"
          value={formatMaybeDuration(totalDurationMs)}
        />
        <InlineMetric
          icon={<Gauge className="h-4 w-4" />}
          label="Fase dominante"
          value={dominantPhase ? dominantPhase.label : "N/D"}
          detail={dominantPhase ? formatMaybeDuration(dominantPhase.timing?.avg_ms) : undefined}
        />
        <InlineMetric
          icon={<FileText className="h-4 w-4" />}
          label="Documentos"
          value={formatMaybeNumber(docsTotal)}
        />
        <InlineMetric
          icon={<Cpu className="h-4 w-4" />}
          label="Tokens Gemini"
          value={formatMaybeNumber(geminiTotal?.total_tokens)}
          detail={formatFinishReasons(geminiTotal)}
        />
        <InlineMetric
          icon={<Activity className="h-4 w-4" />}
          label="Cache"
          value={formatPercent(timings.cache_hit_rate)}
          detail={`${formatMaybeNumber(timings.semantic_cache_hits)} hits semánticos`}
        />
      </div>

      <section className="space-y-3">
        <SectionTitle
          title="Fases del pipeline"
          description="Promedios y p95 por fase persistida en el resultado."
        />
        <div className="overflow-x-auto rounded-lg border border-white/8 bg-[#09111d]/35 scrollbar-thin">
          <table className="w-full min-w-[680px] text-sm">
            <thead>
              <tr className="border-b border-white/8 text-left text-[11px] uppercase tracking-[0.16em] text-slate-500">
                <th scope="col" className="px-4 py-3 font-semibold">
                  Fase
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Docs
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Promedio
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  P95
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Máximo
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Lectura
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/[0.04]">
              {phaseRows.map((row) => {
                const tier = getTimingTier(row.timing?.p95_ms ?? row.timing?.avg_ms ?? 0);
                return (
                  <tr key={row.id}>
                    <th scope="row" className="px-4 py-3 text-left font-medium text-slate-200">
                      {row.label}
                    </th>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                      {formatMaybeNumber(row.timing?.count)}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-300">
                      {formatMaybeDuration(row.timing?.avg_ms)}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-300">
                      {formatMaybeDuration(row.timing?.p95_ms)}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums text-slate-300">
                      {formatMaybeDuration(row.timing?.max_ms)}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <span
                        className={cn(
                          "inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset",
                          tier.className,
                        )}
                      >
                        {tier.label}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>

      <section className="space-y-3">
        <SectionTitle
          title="Consumo Gemini"
          description="Latencia y tokens separados por extracción documental y homologación semántica."
        />
        <div className="overflow-x-auto rounded-lg border border-white/8 bg-[#09111d]/35 scrollbar-thin">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="border-b border-white/8 text-left text-[11px] uppercase tracking-[0.16em] text-slate-500">
                <th scope="col" className="px-4 py-3 font-semibold">
                  Tarea
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Llamadas
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Cache
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Prom.
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  P95
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Prompt
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Output
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Thinking
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Total
                </th>
                <th scope="col" className="px-4 py-3 text-right font-semibold">
                  Finish
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/[0.04]">
              {geminiRows.map((row) => (
                <tr key={row.id}>
                  <th scope="row" className="px-4 py-3 text-left font-medium text-slate-200">
                    {row.label}
                  </th>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                    {formatMaybeNumber(row.timing?.count)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                    {formatMaybeNumber(row.timing?.cache_hits)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-300">
                    {formatMaybeDuration(row.timing?.avg_ms)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-300">
                    {formatMaybeDuration(row.timing?.p95_ms)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                    {formatMaybeNumber(row.timing?.prompt_tokens)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                    {formatMaybeNumber(row.timing?.output_tokens)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-400">
                    {formatMaybeNumber(row.timing?.thoughts_tokens)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums font-medium text-slate-200">
                    {formatMaybeNumber(row.timing?.total_tokens)}
                  </td>
                  <td className="px-4 py-3 text-right text-xs text-slate-400">
                    {formatFinishReasons(row.timing)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function InlineMetric({
  icon,
  label,
  value,
  detail,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  detail?: string;
}) {
  return (
    <div className="flex min-w-[10rem] items-center gap-2.5">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/10 text-sky-300 ring-1 ring-inset ring-sky-500/20">
        {icon}
      </span>
      <span className="min-w-0">
        <span className="block text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
          {label}
        </span>
        <span className="mt-0.5 block truncate text-sm font-semibold tabular-nums text-white">
          {value}
        </span>
        {detail ? (
          <span className="block truncate text-xs text-slate-500">{detail}</span>
        ) : null}
      </span>
    </div>
  );
}

function SectionTitle({
  title,
  description,
}: {
  title: string;
  description: string;
}) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-slate-100">{title}</h3>
      <p className="mt-1 text-sm text-slate-400">{description}</p>
    </div>
  );
}

function buildPhaseRows(timings: AuditPhaseTimings): PhaseRow[] {
  return [
    { id: "download", label: "Descarga", timing: timings.download },
    { id: "extraction", label: "Extracción", timing: timings.extraction },
    { id: "normalization", label: "Normalización", timing: timings.normalization },
    { id: "policy", label: "Reglas", timing: timings.policy },
  ];
}

function buildGeminiRows(timings: AuditPhaseTimings): GeminiRow[] {
  return [
    { id: "gemini_extraction", label: "Extracción", timing: timings.gemini_extraction },
    { id: "gemini_semantic", label: "Semántica", timing: timings.gemini_semantic },
    { id: "gemini_total", label: "Total Gemini", timing: timings.gemini_total },
  ];
}

function getDominantPhase(rows: PhaseRow[]) {
  return rows.reduce<PhaseRow | null>((current, row) => {
    const currentMs = current?.timing?.avg_ms ?? -1;
    const rowMs = row.timing?.avg_ms ?? -1;
    return rowMs > currentMs ? row : current;
  }, null);
}

function getTimingTier(ms: number): { label: string; className: string } {
  if (ms < 1_000) {
    return {
      label: "Rápido",
      className: "bg-emerald-500/10 text-emerald-300 ring-emerald-500/20",
    };
  }
  if (ms < 5_000) {
    return {
      label: "Normal",
      className: "bg-amber-500/10 text-amber-300 ring-amber-500/20",
    };
  }

  return {
    label: "Lento",
    className: "bg-rose-500/10 text-rose-300 ring-rose-500/20",
  };
}

function formatMaybeDuration(value?: number | null) {
  return value === null || value === undefined ? "N/D" : formatDurationMs(value);
}

function formatMaybeNumber(value?: number | string | null) {
  return value === null || value === undefined || value === ""
    ? "N/D"
    : formatNumber(value);
}

function formatPercent(value?: number | null) {
  if (value === null || value === undefined) {
    return "N/D";
  }

  const pct = value <= 1 ? value * 100 : value;
  return `${pct % 1 === 0 ? pct.toFixed(0) : pct.toFixed(1)}%`;
}

function formatFinishReasons(timing?: AuditGeminiTimingSummary | null) {
  const entries = Object.entries(timing?.finish_reasons ?? {});
  if (entries.length === 0) {
    return "N/D";
  }

  return entries.map(([reason, count]) => `${reason} ${formatNumber(count)}`).join(", ");
}
