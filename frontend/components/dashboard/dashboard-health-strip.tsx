import type { ReactNode } from "react";
import { AlertTriangle, CheckCircle2, Database, HardDrive, ListRestart, Server } from "lucide-react";

import type { AsyncMetrics, HealthStatus } from "@/lib/schemas/domain";
import { formatNumber } from "@/lib/formatters";

export function DashboardHealthStrip({
  health,
  healthError,
  asyncMetrics,
  asyncError,
}: {
  health: HealthStatus | null;
  healthError: string | null;
  asyncMetrics: AsyncMetrics | null;
  asyncError: string | null;
}) {
  const backendOk = !healthError && health?.status === "healthy";
  const databaseStatus = health?.services?.database?.status ?? null;
  const redisStatus = health?.services?.redis?.status ?? null;
  const diskStatus = health?.services?.disk?.status ?? null;
  const queueIncidents = asyncMetrics
    ? asyncMetrics.deadLetterDepth + asyncMetrics.terminalFailures + asyncMetrics.jobs.failed
    : null;

  return (
    <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-5" aria-label="Estado compacto del sistema">
      <HealthChip
        icon={backendOk ? <CheckCircle2 className="h-4 w-4" /> : <AlertTriangle className="h-4 w-4" />}
        label="API"
        value={healthError ? "No verificada" : backendOk ? "Operativa" : "Degradada"}
        detail={healthError ?? `Entorno ${health?.environment ?? "N/D"}`}
        tone={healthError || !backendOk ? "warning" : "success"}
      />
      <HealthChip
        icon={<Database className="h-4 w-4" />}
        label="SQL Server"
        value={databaseStatus === "ok" ? "Conectado" : databaseStatus ?? "N/D"}
        detail={
          healthError
            ? "Sin lectura de health"
            : `Latencia ${health?.services?.database?.latency_ms ?? "N/D"} ms`
        }
        tone={databaseStatus === "ok" ? "success" : "warning"}
      />
      <HealthChip
        icon={<Server className="h-4 w-4" />}
        label="Redis"
        value={redisStatus === "ok" ? "Conectado" : redisStatus ?? "N/D"}
        detail={
          healthError
            ? "Sin lectura de health"
            : `Latencia ${health?.services?.redis?.latency_ms ?? "N/D"} ms`
        }
        tone={redisStatus === "ok" ? "success" : "warning"}
      />
      <HealthChip
        icon={<HardDrive className="h-4 w-4" />}
        label="Disco"
        value={diskStatus === "ok" ? "OK" : diskStatus ?? "N/D"}
        detail={healthError ? "Sin lectura de health" : "Reserva operativa"}
        tone={diskStatus === "ok" ? "success" : "warning"}
      />
      <HealthChip
        icon={queueIncidents && queueIncidents > 0 ? <AlertTriangle className="h-4 w-4" /> : <ListRestart className="h-4 w-4" />}
        label="Cola"
        value={asyncError ? "No verificada" : `${formatNumber(asyncMetrics?.queueDepth ?? 0)} pendientes`}
        detail={asyncError ?? `${formatNumber(queueIncidents ?? 0)} incidentes`}
        tone={asyncError || (queueIncidents ?? 0) > 0 ? "warning" : "success"}
      />
    </div>
  );
}

function HealthChip({
  icon,
  label,
  value,
  detail,
  tone,
}: {
  icon: ReactNode;
  label: string;
  value: string;
  detail: string;
  tone: "success" | "warning";
}) {
  const toneClass = {
    success: "border-emerald-500/18 bg-emerald-500/[0.045] text-emerald-300",
    warning: "border-amber-500/20 bg-amber-500/[0.05] text-amber-300",
  };

  return (
    <div className={`min-w-0 rounded-lg border px-3 py-3 ${toneClass[tone]}`}>
      <div className="flex min-w-0 items-center gap-2">
        <span className="shrink-0">{icon}</span>
        <span className="truncate text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">
          {label}
        </span>
      </div>
      <p className="mt-2 truncate text-sm font-semibold text-white">{value}</p>
      <p className="mt-0.5 truncate text-xs text-slate-500">{detail}</p>
    </div>
  );
}
