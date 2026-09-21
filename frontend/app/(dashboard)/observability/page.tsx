"use client";

import { useQuery } from "@tanstack/react-query";
import {
  AlertTriangle,
  CheckCircle2,
  CircleHelp,
  Cpu,
  Database,
  HardDrive,
  RefreshCw,
  Server,
  XCircle,
} from "lucide-react";

import { formatDateTime, formatNumber } from "@/lib/formatters";
import { asyncMetricsQuery, healthQuery } from "@/lib/query/system";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { SectionCard } from "@/components/shared/section-card";
import { PageHeader } from "@/components/layout/page-header";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

type ServiceStatus = "ok" | "warn" | "fail" | "unknown";

function deriveServiceStatus(status?: string): ServiceStatus {
  if (status === "ok") return "ok";
  if (status === "warn") return "warn";
  if (status === "fail") return "fail";
  return "unknown";
}

export default function ObservabilityPage() {
  const {
    data: health,
    isFetching,
    isLoading,
    isError,
    refetch,
    dataUpdatedAt,
  } = useQuery(healthQuery());
  const { data: asyncMetrics, isError: asyncMetricsError } = useQuery(asyncMetricsQuery());

  const header = (
    <PageHeader
      eyebrow="Diagnóstico"
      title="Observabilidad"
      description="Vitales del backend, dependencias y presión del pipeline asíncrono."
    />
  );

  if (isLoading) {
    return (
      <div className="space-y-6">
        {header}
        <SectionCard title="Vitales del sistema">
          <BackendRequestSkeleton
            description="El backend está reuniendo los indicadores operativos."
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
        <Alert variant="destructive">
          <AlertTriangle />
          <AlertDescription>
            No fue posible leer el estado del backend. Verifica el servicio y vuelve a intentar.
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  const { database, database_read: databaseRead, redis, disk, memory } = health.services;
  const lastUpdate = dataUpdatedAt ? formatDateTime(dataUpdatedAt) : "Sin sincronización";

  return (
    <div className="space-y-6">
      {header}

      <SectionCard
        title="Vitales del sistema"
        description={`Última sincronización: ${lastUpdate}`}
        actions={
          <Button
            type="button"
            onClick={() => refetch()}
            loading={isFetching}
            loadingLabel="Actualizando"
            variant="secondary"
            size="sm"
          >
            <RefreshCw />
            Actualizar
          </Button>
        }
      >
        {isFetching ? (
          <BackendRequestSkeleton
            description="Actualizando servicios y latencias."
            title="Sincronizando"
            variant="detail"
          />
        ) : (
          <div className="divide-y divide-border rounded-md border border-border">
            <ServiceRow
              icon={<Database />}
              label="SQL Server, escritura"
              status={deriveServiceStatus(database?.status)}
              primary={database?.latency_ms !== undefined ? `${database.latency_ms} ms` : "N/D"}
              secondary={database?.message ?? "Conexión principal"}
            />
            {databaseRead ? (
              <ServiceRow
                icon={<Database />}
                label="SQL Server, lectura"
                status={deriveServiceStatus(databaseRead.status)}
                primary={databaseRead.latency_ms !== undefined ? `${databaseRead.latency_ms} ms` : "N/D"}
                secondary={databaseRead.message ?? "Conexión de consulta"}
              />
            ) : null}
            <ServiceRow
              icon={<Server />}
              label="Redis"
              status={deriveServiceStatus(redis?.status)}
              primary={redis?.latency_ms !== undefined ? `${redis.latency_ms} ms` : "N/D"}
              secondary={redis?.message ?? "Estado de colas y telemetría"}
            />
            <ServiceRow
              icon={<Cpu />}
              label="Memoria PHP"
              status={deriveServiceStatus(memory?.status)}
              primary={`${String(memory?.usage_mb ?? "N/D")} MB`}
              secondary={`Pico ${String(memory?.peak_mb ?? "N/D")} MB, límite ${String(memory?.limit ?? "N/D")}`}
            />
            <ServiceRow
              icon={<HardDrive />}
              label="Almacenamiento"
              status={deriveServiceStatus(disk?.status)}
              primary={`${String(disk?.free_mb ?? "N/D")} MB libres`}
              secondary={`Umbral ${String(disk?.threshold_mb ?? "N/D")} MB`}
            />
          </div>
        )}
      </SectionCard>

      <div className="grid gap-6 xl:grid-cols-[0.72fr_1.28fr]">
        <SectionCard title="Runtime" description="Identidad de la instancia consultada.">
          <MetricLedger
            items={[
              { label: "Entorno", value: health.environment ?? "N/D" },
              { label: "PHP", value: health.php_version ?? "N/D" },
              {
                label: "Timestamp",
                value: health.timestamp ? formatDateTime(health.timestamp * 1000) : "N/D",
              },
            ]}
          />
        </SectionCard>

        <SectionCard
          title="Pipeline asíncrono"
          description="Unidades separadas: eventos en vuelo, jobs y fallos."
        >
          {asyncMetricsError ? (
            <Alert variant="warning">
              <AlertTriangle />
              <AlertDescription>No fue posible consultar /metrics/async.</AlertDescription>
            </Alert>
          ) : (
            <MetricLedger
              columns={3}
              items={[
                { label: "Eventos en vuelo", value: formatNumber(asyncMetrics?.queueDepth ?? 0) },
                { label: "Dead letter", value: formatNumber(asyncMetrics?.deadLetterDepth ?? 0), tone: "danger" },
                { label: "Jobs ejecutando", value: formatNumber(asyncMetrics?.jobs.running ?? 0) },
                { label: "Jobs en cola", value: formatNumber(asyncMetrics?.jobs.queued ?? 0) },
                { label: "Reintentos", value: formatNumber(asyncMetrics?.retries ?? 0), tone: "warning" },
                { label: "Fallos terminales", value: formatNumber(asyncMetrics?.terminalFailures ?? 0), tone: "danger" },
              ]}
            />
          )}
        </SectionCard>
      </div>
    </div>
  );
}

const statusConfig = {
  ok: { label: "Operativo", icon: CheckCircle2, className: "text-emerald-300" },
  warn: { label: "Alerta", icon: AlertTriangle, className: "text-amber-300" },
  fail: { label: "Falla", icon: XCircle, className: "text-rose-300" },
  unknown: { label: "Sin señal", icon: CircleHelp, className: "text-muted-foreground" },
} satisfies Record<
  ServiceStatus,
  { label: string; icon: typeof CheckCircle2; className: string }
>;

function ServiceRow({
  icon,
  label,
  status,
  primary,
  secondary,
}: {
  icon: React.ReactNode;
  label: string;
  status: ServiceStatus;
  primary: string;
  secondary: string;
}) {
  const config = statusConfig[status];
  const StatusIcon = config.icon;

  return (
    <div className="grid gap-3 px-4 py-3.5 sm:grid-cols-[minmax(12rem,1fr)_auto_minmax(12rem,1.2fr)] sm:items-center">
      <div className="flex min-w-0 items-center gap-3">
        <span className="text-muted-foreground [&_svg]:h-4 [&_svg]:w-4" aria-hidden="true">{icon}</span>
        <span className="truncate text-sm font-medium text-foreground">{label}</span>
      </div>
      <span className="font-mono text-sm font-semibold tabular-nums text-foreground">{primary}</span>
      <div className="flex min-w-0 items-center gap-2 sm:justify-end">
        <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", config.className)}>
          <StatusIcon className="h-3.5 w-3.5" aria-hidden="true" />
          {config.label}
        </span>
        <span className="truncate text-xs text-muted-foreground">{secondary}</span>
      </div>
    </div>
  );
}

function MetricLedger({
  items,
  columns = 1,
}: {
  items: Array<{ label: string; value: string; tone?: "warning" | "danger" }>;
  columns?: 1 | 3;
}) {
  const valueTone = {
    warning: "text-amber-300",
    danger: "text-rose-300",
  };

  return (
    <dl className={cn("grid overflow-hidden rounded-md border border-border", columns === 3 && "sm:grid-cols-3")}>
      {items.map((item) => (
        <div
          key={item.label}
          className={cn(
            "border-b border-border p-3.5 last:border-b-0",
            columns === 3 && "sm:border-l sm:[&:nth-child(3n+1)]:border-l-0 sm:[&:nth-last-child(-n+3)]:border-b-0",
          )}
        >
          <dt className="text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
            {item.label}
          </dt>
          <dd className={cn("mt-2 font-mono text-base font-semibold tabular-nums text-foreground", item.tone && valueTone[item.tone])}>
            {item.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
