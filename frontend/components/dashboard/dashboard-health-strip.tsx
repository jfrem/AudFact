import type { ReactNode } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Database,
  HardDrive,
  ListRestart,
  Server,
} from "lucide-react";

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
    <section
      className="grid overflow-hidden rounded-lg border border-border bg-card sm:grid-cols-2 xl:grid-cols-5"
      aria-label="Estado del sistema"
    >
      <HealthCell
        icon={backendOk ? <CheckCircle2 /> : <AlertTriangle />}
        label="API"
        value={healthError ? "No verificada" : backendOk ? "Operativa" : "Degradada"}
        detail={healthError ?? `Entorno ${health?.environment ?? "N/D"}`}
        tone={healthError || !backendOk ? "warning" : "success"}
      />
      <HealthCell
        icon={<Database />}
        label="SQL Server"
        value={databaseStatus === "ok" ? "Conectado" : databaseStatus ?? "N/D"}
        detail={healthError ? "Sin lectura de health" : `${health?.services?.database?.latency_ms ?? "N/D"} ms`}
        tone={databaseStatus === "ok" ? "success" : "warning"}
      />
      <HealthCell
        icon={<Server />}
        label="Redis"
        value={redisStatus === "ok" ? "Conectado" : redisStatus ?? "N/D"}
        detail={healthError ? "Sin lectura de health" : `${health?.services?.redis?.latency_ms ?? "N/D"} ms`}
        tone={redisStatus === "ok" ? "success" : "warning"}
      />
      <HealthCell
        icon={<HardDrive />}
        label="Disco"
        value={diskStatus === "ok" ? "Operativo" : diskStatus ?? "N/D"}
        detail={healthError ? "Sin lectura de health" : "Reserva operativa"}
        tone={diskStatus === "ok" ? "success" : "warning"}
      />
      <HealthCell
        icon={queueIncidents && queueIncidents > 0 ? <AlertTriangle /> : <ListRestart />}
        label="Pipeline"
        value={asyncError ? "No verificado" : `${formatNumber(asyncMetrics?.queueDepth ?? 0)} en vuelo`}
        detail={asyncError ?? `${formatNumber(queueIncidents ?? 0)} incidentes`}
        tone={asyncError || (queueIncidents ?? 0) > 0 ? "warning" : "success"}
      />
    </section>
  );
}

function HealthCell({
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
  return (
    <div className="min-w-0 border-b border-border p-4 last:border-b-0 sm:[&:nth-last-child(-n+2)]:border-b-0 sm:[&:nth-child(even)]:border-l xl:border-b-0 xl:border-l xl:first:border-l-0">
      <div
        className={tone === "success" ? "text-emerald-300" : "text-amber-300"}
        aria-hidden="true"
      >
        <span className="inline-flex [&_svg]:h-4 [&_svg]:w-4">{icon}</span>
      </div>
      <p className="mt-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 truncate text-sm font-semibold text-foreground">{value}</p>
      <p className="mt-0.5 truncate text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}
