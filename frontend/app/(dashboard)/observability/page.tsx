"use client";

import { useQuery } from "@tanstack/react-query";
import {
  Activity,
  Database,
  HardDrive,
  Cpu,
  Server,
  RefreshCw,
  AlertTriangle,
} from "lucide-react";

import { formatDateTime } from "@/lib/formatters";
import { asyncMetricsQuery, healthQuery } from "@/lib/query/system";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { SectionCard } from "@/components/shared/section-card";
import { PageHeader } from "@/components/layout/page-header";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

function deriveServiceStatus(status?: string): "ok" | "warn" | "fail" | "unknown" {
  if (status === "ok") return "ok";
  if (status === "warn") return "warn";
  if (status === "fail") return "fail";
  return "unknown";
}

export default function ObservabilityPage() {
  const { data: health, isFetching, isLoading, isError, refetch, dataUpdatedAt } = useQuery(healthQuery());
  const { data: asyncMetrics } = useQuery(asyncMetricsQuery());

  const header = (
    <PageHeader
      eyebrow="Observabilidad"
      title="Vitales del Sistema"
      description='Estado en tiempo real de los servicios del backend. Datos obtenidos directamente del endpoint /health.'
    />
  );

  if (isLoading) {
    return (
      <div className="space-y-6">
        {header}
        <SectionCard title="Cargando...">
          <BackendRequestSkeleton
            description="El backend está reportando vitales del sistema."
            title="Consultando estado"
            variant="panel"
          />
        </SectionCard>
      </div>
    );
  }

  if (isError || !health) {
    return (
      <div className="space-y-6">
        {header}
        <SectionCard title="Sin conexión">
          <Alert variant="destructive">
            <AlertTriangle />
            <AlertDescription>
              No se pudo conectar al backend. Verifica que el servicio esté activo.
            </AlertDescription>
          </Alert>
        </SectionCard>
      </div>
    );
  }

  const db = health.services?.database;
  const disk = health.services?.disk;
  const memory = health.services?.memory;
  const lastUpdate = dataUpdatedAt ? formatDateTime(dataUpdatedAt) : "—";

  return (
    <div className="space-y-6">
      {header}

      {/* Estado global */}
      <SectionCard
        title="Estado del sistema"
        actions={
          <Button
            type="button"
            onClick={() => refetch()}
            loading={isFetching}
            loadingLabel="Actualizando"
            variant="secondary"
            size="sm"
          >
            <RefreshCw className="h-3.5 w-3.5" />
            Actualizar
          </Button>
        }
      >
        {isFetching ? (
          <BackendRequestSkeleton
            className="mb-4"
            description="El backend está refrescando los vitales operativos."
            title="Actualizando estado"
            variant="compact"
          />
        ) : null}
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {/* Base de Datos */}
          <VitalCard
            icon={<Database className="h-4 w-4" />}
            label="SQL Server"
            status={deriveServiceStatus(db?.status)}
            primary={db?.latency_ms !== undefined ? `${db.latency_ms} ms` : "—"}
            secondary={db?.message ?? "Sin información"}
          />

          {/* Memoria */}
          <VitalCard
            icon={<Cpu className="h-4 w-4" />}
            label="Memoria PHP"
            status={deriveServiceStatus(memory?.status)}
            primary={memory?.usage_mb !== undefined ? `${memory.usage_mb} MB` : "—"}
            secondary={`Peak: ${memory?.peak_mb ?? "—"} MB · Límite: ${memory?.limit ?? "—"}`}
          />

          {/* Disco */}
          <VitalCard
            icon={<HardDrive className="h-4 w-4" />}
            label="Almacenamiento"
            status={deriveServiceStatus(disk?.status)}
            primary={disk?.free_mb !== undefined ? `${disk.free_mb} MB libres` : "—"}
            secondary={disk?.threshold_mb ? `Umbral mínimo: ${disk.threshold_mb} MB` : "—"}
          />

          {/* Runtime */}
          <VitalCard
            icon={<Server className="h-4 w-4" />}
            label="Runtime"
            status="ok"
            primary={health.php_version ?? "—"}
            secondary={`Entorno: ${health.environment ?? "—"}`}
          />
        </div>
      </SectionCard>

      {/* Contexto operativo */}
      <SectionCard title="Contexto del runtime">
        <div className="grid gap-3 md:grid-cols-3">
          <MetricBlock label="Entorno" value={health.environment ?? "—"} />
          <MetricBlock label="PHP" value={health.php_version ?? "—"} />
          <MetricBlock label="Timestamp" value={health.timestamp ? formatDateTime(health.timestamp * 1000) : "—"} />
        </div>
      </SectionCard>

      <SectionCard title="Métricas async">
        <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
          <MetricBlock label="Queue Depth" value={String(asyncMetrics?.queueDepth ?? "—")} />
          <MetricBlock label="Dead Letter" value={String(asyncMetrics?.deadLetterDepth ?? "—")} />
          <MetricBlock label="Jobs Running" value={String(asyncMetrics?.jobs.running ?? "—")} />
          <MetricBlock label="Jobs Queued" value={String(asyncMetrics?.jobs.queued ?? "—")} />
          <MetricBlock label="Retries" value={String(asyncMetrics?.retries ?? "—")} />
          <MetricBlock label="Fallos" value={String(asyncMetrics?.terminalFailures ?? "—")} />
        </div>
      </SectionCard>
    </div>
  );
}

function VitalCard({
  icon,
  label,
  status,
  primary,
  secondary,
}: {
  icon: React.ReactNode;
  label: string;
  status: "ok" | "warn" | "fail" | "unknown";
  primary: string;
  secondary: string;
}) {
  const borderTone = {
    ok: "border-emerald-500/20",
    warn: "border-amber-500/20",
    fail: "border-rose-500/20",
    unknown: "border-white/10",
  };

  const dotTone = {
    ok: "bg-emerald-400",
    warn: "bg-amber-400",
    fail: "bg-rose-400",
    unknown: "bg-slate-500",
  };

  return (
    <div className={`rounded-lg border bg-white/[0.03] p-4 ${borderTone[status]}`}>
      <div className="flex items-center gap-2 text-slate-400">
        {icon}
        <span className="text-xs uppercase tracking-[0.18em]">{label}</span>
        <span className={`ml-auto h-2 w-2 rounded-full ${dotTone[status]}`} />
      </div>
      <p className="mt-3 text-2xl font-semibold text-white">{primary}</p>
      <p className="mt-1 text-xs text-slate-500">{secondary}</p>
    </div>
  );
}

function MetricBlock({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-white/10 bg-white/[0.03] p-4">
      <p className="text-xs uppercase tracking-[0.22em] text-slate-500">{label}</p>
      <p className="mt-3 text-lg font-semibold text-white">{value}</p>
    </div>
  );
}
