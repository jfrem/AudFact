import type { ReactNode } from "react";
import Link from "next/link";
import { AlertTriangle, ArrowRight, CheckCircle2, Clock3, ListRestart } from "lucide-react";

import type { AsyncMetrics } from "@/lib/schemas/domain";
import { formatNumber } from "@/lib/formatters";
import { Button } from "@/components/ui/button";

export function AsyncQueueSummary({
  metrics,
  error,
}: {
  metrics: AsyncMetrics | null;
  error: string | null;
}) {
  if (error) {
    return (
      <div className="rounded-lg border border-rose-500/20 bg-rose-500/[0.04] p-4" role="alert">
        <div className="flex gap-3">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-300" />
          <div className="min-w-0">
            <p className="text-sm font-semibold text-white">No se pudo cargar la cola</p>
            <p className="mt-1 text-sm leading-6 text-rose-100/80">{error}</p>
            <Button asChild variant="secondary" size="sm" className="mt-3">
              <Link href="/dashboard">Reintentar</Link>
            </Button>
          </div>
        </div>
      </div>
    );
  }

  if (!metrics) {
    return null;
  }

  const queuePressure = metrics.queueDepth + metrics.jobs.running + metrics.jobs.queued;
  const hasIncidents = metrics.deadLetterDepth > 0 || metrics.terminalFailures > 0 || metrics.jobs.failed > 0;

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <QueueMetric
          icon={<Clock3 className="h-4 w-4" />}
          label="Activos"
          value={queuePressure}
          detail={`${formatNumber(metrics.jobs.running)} running · ${formatNumber(metrics.jobs.queued)} queued`}
          tone={queuePressure > 0 ? "info" : "neutral"}
        />
        <QueueMetric
          icon={hasIncidents ? <AlertTriangle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
          label="Incidentes"
          value={metrics.deadLetterDepth + metrics.terminalFailures + metrics.jobs.failed}
          detail={`${formatNumber(metrics.deadLetterDepth)} DLQ · ${formatNumber(metrics.terminalFailures)} terminales`}
          tone={hasIncidents ? "danger" : "success"}
        />
        <QueueMetric
          icon={<ListRestart className="h-4 w-4" />}
          label="Reintentos"
          value={metrics.retries}
          detail="Eventos reintentados por workers"
          tone={metrics.retries > 0 ? "warning" : "neutral"}
        />
        <QueueMetric
          icon={<CheckCircle2 className="h-4 w-4" />}
          label="Completados"
          value={metrics.jobs.completed}
          detail="Jobs terminales exitosos"
          tone="success"
        />
      </div>

      <div className="flex flex-wrap gap-2">
        <Button asChild variant="secondary" size="sm">
          <Link href="/audit/jobs">
            Abrir tracking
            <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        </Button>
        <Button asChild variant="ghost" size="sm">
          <Link href="/observability">Ver observabilidad</Link>
        </Button>
      </div>
    </div>
  );
}

function QueueMetric({
  icon,
  label,
  value,
  detail,
  tone,
}: {
  icon: ReactNode;
  label: string;
  value: number;
  detail: string;
  tone: "danger" | "info" | "neutral" | "success" | "warning";
}) {
  const toneClass = {
    danger: "border-rose-500/20 bg-rose-500/[0.05] text-rose-300",
    info: "border-sky-500/20 bg-sky-500/[0.05] text-sky-300",
    neutral: "border-white/10 bg-white/[0.03] text-slate-300",
    success: "border-emerald-500/20 bg-emerald-500/[0.05] text-emerald-300",
    warning: "border-amber-500/20 bg-amber-500/[0.05] text-amber-300",
  };

  return (
    <div className={`rounded-lg border p-3.5 ${toneClass[tone]}`}>
      <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em]">
        {icon}
        <span>{label}</span>
      </div>
      <p className="mt-3 text-2xl font-semibold tabular-nums text-white">
        {formatNumber(value)}
      </p>
      <p className="mt-1 text-xs leading-5 text-slate-400">{detail}</p>
    </div>
  );
}
