import { Activity, Coins, Cpu, HelpCircle, Timer } from "lucide-react";
import type { ReactNode } from "react";

import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip";

import type {
  AuditCostInvoice,
  AuditGeminiTimingSummary,
  AuditPhaseTimings,
  AuditTimingSummary,
} from "@/lib/schemas/domain";
import { formatCurrencyCop, formatDurationMs, formatNumber } from "@/lib/formatters";
import { cn } from "@/lib/utils";

type AuditTimingsPanelProps = {
  timings?: AuditPhaseTimings | null;
  cost?: AuditCostInvoice | null;
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
  cost = null,
  totalDurationMs = null,
  className,
}: AuditTimingsPanelProps) {
  if (!timings) {
    return (
      <div
        className={cn(
          "rounded-lg border border-dashed border-border bg-muted/40 px-4 py-5 text-sm",
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
  const geminiTotal = timings.gemini_total;

  const queueWaitMs = timings.queue_wait_ms ?? 0;
  const executionDetail =
    queueWaitMs > 0
      ? `+ ${formatDurationMs(queueWaitMs)} en cola`
      : timings.total_elapsed_ms
      ? "Neto sin espera"
      : undefined;

  return (
    <TooltipProvider delayDuration={250}>
      <div className={cn("space-y-5", className)}>
        {/* Barra forense de 4 métricas esenciales (sin redundancias ni duplicados) */}
        <div className="grid grid-cols-2 gap-4 border-b border-border pb-4 sm:grid-cols-4">
          <InlineMetric
            icon={<Timer className="h-4 w-4 text-emerald-400" />}
            label="Ejecución"
            value={formatMaybeDuration(timings.processing_duration_ms ?? totalDurationMs)}
            detail={executionDetail}
            tooltip={`Tiempo neto de procesamiento del worker: ${formatMaybeDuration(timings.processing_duration_ms ?? totalDurationMs)}. Duración total del ciclo: ${formatMaybeDuration(timings.total_elapsed_ms ?? totalDurationMs)} (Espera en cola Redis: ${formatMaybeDuration(queueWaitMs)}).`}
          />
          <InlineMetric
            icon={<Coins className="h-4 w-4 text-emerald-400" />}
            label="Costo IA"
            value={formatCurrencyCop(cost?.costo_total_acumulado_cop)}
            detail={cost?.costo_total_acumulado_usd !== undefined ? `$${cost.costo_total_acumulado_usd.toFixed(4)} USD` : undefined}
            tooltip={
              cost
                ? `Costo liquidado en COP aplicando TRM oficial de $${formatNumber(cost.intentos?.[0]?.trm_aplicada ?? 0)} COP/USD para ${formatNumber(cost.gran_total_tokens)} tokens consumidos.`
                : "Costo no disponible para esta factura."
            }
          />
          <InlineMetric
            icon={<Cpu className="h-4 w-4 text-sky-400" />}
            label="Tokens Gemini"
            value={formatMaybeNumber(geminiTotal?.total_tokens)}
            detail={formatFinishReasons(geminiTotal) !== "N/D" ? formatFinishReasons(geminiTotal) : undefined}
            tooltip="Consumo total de tokens facturados por Google Gemini (prompt + output + razonamiento)."
          />
          <InlineMetric
            icon={<Activity className="h-4 w-4 text-slate-400" />}
            label="Caché"
            value={formatPercent(timings.cache_hit_rate)}
            detail={`${formatMaybeNumber(timings.semantic_cache_hits)} hits semánticos`}
            tooltip="Tasa de aciertos de extracción documental y homologaciones resueltas desde caché sin llamar a Gemini."
          />
        </div>

        <section className="space-y-3">
          <SectionTitle
            title="Fases del pipeline"
            description="Promedios y percentil 95 por fase de procesamiento."
          />
          <div className="overflow-x-auto rounded-lg border border-border bg-background scrollbar-thin">
            <table className="w-full min-w-[680px] text-sm">
              <thead>
                <tr className="border-b border-border text-left text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
                  <th scope="col" className="px-4 py-3 font-semibold">
                    Fase
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Docs" tip="Cantidad de documentos que pasaron por esta fase." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Promedio" tip="Duración promedio por documento en esta fase." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="P95" tip="Percentil 95: el 95% de los documentos se procesaron en este tiempo o menos." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Máximo" tip="Tiempo máximo registrado para un solo documento en esta fase." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Lectura" tip="Clasificación automática: Rápido (<1s), Normal (1–5s), Lento (>5s), basada en el P95." />
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-white/[0.04]">
                {phaseRows.map((row) => {
                  const tier = getTimingTier(row.timing?.p95_ms ?? row.timing?.avg_ms ?? 0);
                  const isDominant = dominantPhase?.id === row.id && (row.timing?.avg_ms ?? 0) > 0;
                  return (
                    <tr key={row.id}>
                      <th scope="row" className="px-4 py-3 text-left font-medium text-slate-200">
                        <div className="flex items-center gap-2">
                          <PhaseLabel phase={row.id} label={row.label} />
                          {isDominant && (
                            <span className="rounded bg-amber-500/10 px-1.5 py-0.5 font-mono text-[10px] font-medium text-amber-300 ring-1 ring-inset ring-amber-500/20">
                              Mayor tiempo
                            </span>
                          )}
                        </div>
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
            description="Latencia y tokens desglosados por extracción documental y homologación semántica."
          />
          <div className="overflow-x-auto rounded-lg border border-border bg-background scrollbar-thin">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b border-border text-left text-[11px] uppercase tracking-[0.16em] text-muted-foreground">
                  <th scope="col" className="px-4 py-3 font-semibold">
                    Tarea
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Llamadas" tip="Número total de peticiones HTTP enviadas a la API de Gemini para esta tarea." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Cache" tip="Llamadas resueltas desde caché local sin consultar a Gemini." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Prom." tip="Latencia promedio de ida y vuelta con la API de Gemini." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="P95" tip="Percentil 95 de la latencia de Gemini." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Prompt" tip="Tokens de entrada enviados a Gemini (instrucciones + contenido del documento)." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Output" tip="Tokens de salida generados por Gemini (datos extraídos o resultados semánticos)." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Thinking" tip="Tokens de razonamiento interno del modelo (thinking mode). Solo aplica a modelos con capacidad de razonamiento." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Total" tip="Suma de tokens: Prompt + Output + Thinking. Representa el consumo total facturado por la API." />
                  </th>
                  <th scope="col" className="px-4 py-3 text-right font-semibold">
                    <HeaderTooltip label="Finish" tip="Razón de finalización de Gemini. STOP = generación completa. MAX_TOKENS = límite alcanzado." />
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
          {cost ? (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/50 pt-2 text-xs text-slate-400">
              <div className="flex items-center gap-2">
                <span className="text-slate-500">Modelo:</span>
                <span className="font-mono text-slate-300">
                  {cost.intentos?.[0]?.modelo_ia || "Google Gemini"}
                </span>
                {cost.intentos?.[0]?.trm_aplicada ? (
                  <>
                    <span className="text-slate-600">·</span>
                    <span>
                      TRM: <span className="font-mono text-slate-300">${formatNumber(cost.intentos[0].trm_aplicada)} COP/USD</span>
                    </span>
                  </>
                ) : null}
              </div>
              <div className="flex items-center gap-2">
                <span className="text-slate-500">Liquidación IA:</span>
                <span className="font-semibold text-emerald-400 tabular-nums">
                  {formatCurrencyCop(cost.costo_total_acumulado_cop)}
                </span>
                <span className="text-slate-500">
                  (${cost.costo_total_acumulado_usd.toFixed(4)} USD)
                </span>
              </div>
            </div>
          ) : null}
        </section>
      </div>
    </TooltipProvider>
  );
}

function InlineMetric({
  icon,
  label,
  value,
  detail,
  tooltip,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  detail?: string;
  tooltip?: string;
}) {
  const content = (
    <div className="flex items-center gap-2.5">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-500/10 text-sky-300 ring-1 ring-inset ring-sky-500/20">
        {icon}
      </span>
      <span className="min-w-0">
        <span className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500">
          {label}
          {tooltip && <HelpCircle className="h-2.5 w-2.5 text-slate-600" />}
        </span>
        <span className="mt-0.5 block truncate text-sm font-semibold tabular-nums text-foreground">
          {value}
        </span>
        {detail ? (
          <span className="block truncate text-xs text-slate-500">{detail}</span>
        ) : null}
      </span>
    </div>
  );

  if (!tooltip) return content;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button type="button" className="cursor-help text-left">
          {content}
        </button>
      </TooltipTrigger>
      <TooltipContent side="bottom" className="max-w-xs text-[11px] leading-relaxed">
        {tooltip}
      </TooltipContent>
    </Tooltip>
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

/** Tooltip inline en headers de tabla */
function HeaderTooltip({ label, tip }: { label: string; tip: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          className="inline-flex cursor-help items-center gap-1"
        >
          {label}
          <HelpCircle className="h-2.5 w-2.5 text-slate-600" />
        </button>
      </TooltipTrigger>
      <TooltipContent side="bottom" className="max-w-xs text-left text-[11px] font-normal normal-case tracking-normal leading-relaxed">
        {tip}
      </TooltipContent>
    </Tooltip>
  );
}

/** Tooltip descriptivo para cada fase del pipeline */
const PHASE_TIPS: Record<string, string> = {
  download: "Tiempo para descargar el archivo binario desde su almacenamiento original (BD o Google Drive).",
  extraction: "Tiempo de inferencia de Gemini para extraer datos estructurados del documento (OCR + Function Calling).",
  normalization: "Tiempo para estandarizar y formatear el payload JSON de salida de los datos extraídos.",
  policy: "Tiempo para evaluar las reglas de negocio y políticas documentales contra los datos normalizados.",
  policy_v2: "Tiempo de evaluación local de reglas con la evidencia documental y las comparaciones semánticas ya resueltas.",
  semantic_wait: "Tiempo transcurrido desde la solicitud de comparaciones semánticas hasta la reanudación de las reglas. Incluye espera en cola, cuota y procesamiento.",
};

function PhaseLabel({ phase, label }: { phase: string; label: string }) {
  const tip = PHASE_TIPS[phase];
  if (!tip) return <>{label}</>;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          className="inline-flex cursor-help items-center gap-1.5"
        >
          {label}
          <HelpCircle className="h-2.5 w-2.5 text-slate-600" />
        </button>
      </TooltipTrigger>
      <TooltipContent side="right" className="max-w-xs text-left text-[11px] font-normal leading-relaxed">
        {tip}
      </TooltipContent>
    </Tooltip>
  );
}

function buildPhaseRows(timings: AuditPhaseTimings): PhaseRow[] {
  return [
    { id: "download", label: "Descarga", timing: timings.download },
    { id: "extraction", label: "Extracción", timing: timings.extraction },
    { id: "normalization", label: "Normalización", timing: timings.normalization },
    { id: timings.timing_schema_version === 2 ? "policy_v2" : "policy", label: "Reglas", timing: timings.policy },
    ...(timings.timing_schema_version === 2
      ? [{ id: "semantic_wait", label: "Espera semántica", timing: timings.semantic_wait }]
      : []),
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
