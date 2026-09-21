"use client";

import { useCallback, useEffect, useMemo, useState, useTransition } from "react";
import Link from "next/link";
import {
  Activity,
  AlertCircle,
  AlertTriangle,
  ArrowRight,
  Calendar,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Layers3,
  RefreshCw,
  Search,
  Timer,
  X,
} from "lucide-react";

import { getAuditJobs } from "@/lib/api/audfact";
import { appConfig } from "@/lib/api/config";
import type { AuditJobSummary } from "@/lib/schemas/domain";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionCard } from "@/components/shared/section-card";
import { cn } from "@/lib/utils";

type StatusFilter = "all" | "active" | "completed" | "errors";

const POLL_SECONDS = Math.max(1, Math.ceil(appConfig.pollingJobsMs / 1000));

function formatDuration(ms: number): string {
  if (!ms || ms <= 0) return "N/D";
  const totalSeconds = Math.floor(ms / 1000);
  if (totalSeconds < 60) return `${totalSeconds}s`;
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  if (minutes < 60) return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function calculateEta(job: AuditJobSummary): string | null {
  if (job.status !== "processing" && job.status !== "pending") return null;

  const processed = (job.done || 0) + (job.failed || 0);
  const remaining = Math.max(0, (job.total || 0) - processed);
  if (remaining === 0) return "< 5s";

  if (job.avg_duration_ms > 0 && processed > 0) {
    return formatDuration(remaining * job.avg_duration_ms);
  }

  if (job.created_at && processed > 0) {
    const elapsedMs = Date.now() - new Date(job.created_at).getTime();
    if (elapsedMs > 0) return formatDuration((elapsedMs / processed) * remaining);
  }

  return "Estimando";
}

function formatSpeed(avgDurationMs: number): string {
  if (!avgDurationMs || avgDurationMs <= 0) return "Calculando";
  const seconds = avgDurationMs / 1000;
  return seconds < 60 ? `${seconds.toFixed(1)}s/doc` : `${(seconds / 60).toFixed(1)}m/doc`;
}

function formatRelativeTime(dateStr?: string | null): string {
  if (!dateStr) return "N/D";
  const diffMs = Date.now() - new Date(dateStr).getTime();
  if (diffMs < 0) return "ahora";
  const seconds = Math.floor(diffMs / 1000);
  if (seconds < 60) return `hace ${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `hace ${minutes}m`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `hace ${hours}h`;
  return `hace ${Math.floor(hours / 24)}d`;
}

function formatFullDateTime(dateStr?: string | null) {
  if (!dateStr) return { absolute: "N/D", relative: "N/D" };
  const date = new Date(dateStr);
  if (Number.isNaN(date.getTime())) return { absolute: dateStr, relative: "N/D" };

  return {
    absolute: new Intl.DateTimeFormat(appConfig.locale, {
      dateStyle: "medium",
      timeStyle: "medium",
      timeZone: appConfig.timeZone,
    }).format(date),
    relative: formatRelativeTime(dateStr),
  };
}

function formatCompactDate(dateStr?: string | null): string {
  if (!dateStr) return "N/D";
  const [year, month, day] = dateStr.split("-");
  if (!year || !month || !day) return dateStr;

  return `${day}/${month}/${year}`;
}

export function JobsListLive() {
  const [jobs, setJobs] = useState<AuditJobSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [lastRefreshed, setLastRefreshed] = useState<Date | null>(null);
  const [countdown, setCountdown] = useState(POLL_SECONDS);
  const [filterQuery, setFilterQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [pageSize, setPageSize] = useState(10);
  const [currentPage, setCurrentPage] = useState(1);
  const [isPending, startTransition] = useTransition();

  const fetchJobs = useCallback(async () => {
    try {
      setError(null);
      setJobs((await getAuditJobs({ limit: 50 })) ?? []);
      setLastRefreshed(new Date());
      setCountdown(POLL_SECONDS);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : "No fue posible consultar los jobs.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void fetchJobs();
  }, [fetchJobs]);

  useEffect(() => {
    if (!autoRefresh) return;

    const ticker = window.setInterval(() => {
      setCountdown((value) => (value > 1 ? value - 1 : POLL_SECONDS));
    }, 1_000);
    const poller = window.setInterval(() => {
      startTransition(() => fetchJobs());
    }, appConfig.pollingJobsMs);

    return () => {
      window.clearInterval(ticker);
      window.clearInterval(poller);
    };
  }, [autoRefresh, fetchJobs]);

  const filteredJobs = useMemo(() => {
    const query = filterQuery.toLocaleLowerCase("es-CO").trim();

    return jobs.filter((job) => {
      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && (job.status === "processing" || job.status === "pending")) ||
        (statusFilter === "completed" && job.status === "completed") ||
        (statusFilter === "errors" && (job.status === "completed_with_errors" || job.status === "failed"));
      const matchesQuery =
        !query ||
        job.job_id.toLocaleLowerCase("es-CO").includes(query) ||
        job.client_name?.toLocaleLowerCase("es-CO").includes(query) ||
        String(job.fac_nit_sec).includes(query);

      return matchesStatus && matchesQuery;
    });
  }, [filterQuery, jobs, statusFilter]);

  const totalPages = Math.max(1, Math.ceil(filteredJobs.length / pageSize));
  const validPage = Math.min(currentPage, totalPages);
  const startIndex = (validPage - 1) * pageSize;
  const paginatedJobs = filteredJobs.slice(startIndex, startIndex + pageSize);
  const activeCount = jobs.filter((job) => job.status === "processing" || job.status === "pending").length;
  const completedCount = jobs.filter((job) => job.status === "completed").length;
  const errorCount = jobs.filter((job) => job.status === "completed_with_errors" || job.status === "failed").length;

  const selectFilter = (filter: StatusFilter) => {
    setStatusFilter(filter);
    setCurrentPage(1);
  };

  return (
    <SectionCard
      title="Registro de jobs"
      description="Lotes recientes, progreso, ritmo y resultado de ejecución."
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex h-8 items-center gap-2 rounded-md border border-border bg-muted/35 px-2.5 text-xs text-muted-foreground">
            <Switch checked={autoRefresh} onCheckedChange={setAutoRefresh} />
            <span>Auto</span>
            <span className="font-mono tabular-nums">{autoRefresh ? `${countdown}s` : "pausado"}</span>
          </label>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setLoading(true);
              void fetchJobs();
            }}
            disabled={loading || isPending}
          >
            <RefreshCw className={cn((loading || isPending) && "animate-spin")} />
            Actualizar
          </Button>
        </div>
      }
    >
      <div className="grid overflow-hidden rounded-md border border-border sm:grid-cols-4" aria-label="Filtrar por estado">
        <FilterMetric
          active={statusFilter === "all"}
          icon={<Layers3 />}
          label="Todos"
          value={jobs.length}
          detail="en retención"
          onClick={() => selectFilter("all")}
        />
        <FilterMetric
          active={statusFilter === "active"}
          icon={<Activity />}
          label="Activos"
          value={activeCount}
          detail="procesando o en cola"
          tone="info"
          onClick={() => selectFilter("active")}
        />
        <FilterMetric
          active={statusFilter === "completed"}
          icon={<CheckCircle2 />}
          label="Completos"
          value={completedCount}
          detail="sin errores"
          tone="success"
          onClick={() => selectFilter("completed")}
        />
        <FilterMetric
          active={statusFilter === "errors"}
          icon={<AlertCircle />}
          label="Incidentes"
          value={errorCount}
          detail="error o DLQ"
          tone="warning"
          onClick={() => selectFilter("errors")}
        />
      </div>

      <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="relative block w-full sm:max-w-sm">
          <span className="sr-only">Filtrar por cliente, NIT o job ID</span>
          <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <Input
            placeholder="Cliente, NIT o job ID"
            value={filterQuery}
            onChange={(event) => {
              setFilterQuery(event.target.value);
              setCurrentPage(1);
            }}
            className="pl-9 pr-9"
          />
          {filterQuery ? (
            <button
              type="button"
              onClick={() => {
                setFilterQuery("");
                setCurrentPage(1);
              }}
              className="absolute right-2 top-1.5 inline-flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:bg-[var(--surface-hover)] hover:text-foreground"
              aria-label="Limpiar búsqueda"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          ) : null}
        </label>

        <div className="flex items-center justify-between gap-3 sm:justify-end">
          <span className="text-xs text-muted-foreground">
            {lastRefreshed
              ? `Actualizado ${lastRefreshed.toLocaleTimeString(appConfig.locale, {
                  hour: "2-digit",
                  minute: "2-digit",
                  second: "2-digit",
                  timeZone: appConfig.timeZone,
                })}`
              : "Sin sincronización"}
          </span>
          <Select
            value={String(pageSize)}
            onValueChange={(value) => {
              setPageSize(Number(value));
              setCurrentPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-[74px]" aria-label="Filas por página">
              <SelectValue placeholder="10" />
            </SelectTrigger>
            <SelectContent align="end" className="min-w-[5rem]">
              <SelectItem value="10">10</SelectItem>
              <SelectItem value="25">25</SelectItem>
              <SelectItem value="50">50</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      {error ? (
        <Alert variant="destructive" className="mt-4">
          <AlertCircle />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      <div className="mt-4">
        {loading && jobs.length === 0 ? (
          <BackendRequestSkeleton
            title="Consultando jobs"
            description="Leyendo el registro asíncrono desde Redis."
            variant="table"
            rows={6}
          />
        ) : filteredJobs.length === 0 ? (
          <EmptyState
            title="Sin jobs"
            description={
              filterQuery || statusFilter !== "all"
                ? "No hay ejecuciones que coincidan con los filtros."
                : "No hay jobs batch activos ni recientes."
            }
            action={
              filterQuery || statusFilter !== "all" ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setFilterQuery("");
                    selectFilter("all");
                  }}
                >
                  Restablecer filtros
                </Button>
              ) : null
            }
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Cliente / lote</TableHead>
                <TableHead>Creado</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Progreso</TableHead>
                <TableHead>Ritmo</TableHead>
                <TableHead className="text-right">Acción</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {paginatedJobs.map((job) => (
                <JobRow key={job.job_id} job={job} />
              ))}
            </TableBody>
          </Table>
        )}
      </div>

      {totalPages > 1 ? (
        <div className="mt-4 flex flex-col gap-3 border-t border-border pt-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <span>
            {startIndex + 1} a {Math.min(startIndex + pageSize, filteredJobs.length)} de {filteredJobs.length}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
              disabled={validPage === 1}
            >
              <ChevronLeft />
              Anterior
            </Button>
            <span className="px-2 font-mono tabular-nums text-foreground">
              {validPage} / {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))}
              disabled={validPage === totalPages}
            >
              Siguiente
              <ChevronRight />
            </Button>
          </div>
        </div>
      ) : null}
    </SectionCard>
  );
}

function FilterMetric({
  active,
  icon,
  label,
  value,
  detail,
  tone = "neutral",
  onClick,
}: {
  active: boolean;
  icon: React.ReactNode;
  label: string;
  value: number;
  detail: string;
  tone?: "neutral" | "info" | "success" | "warning";
  onClick: () => void;
}) {
  const tones = {
    neutral: "text-foreground",
    info: "text-sky-300",
    success: "text-emerald-300",
    warning: "text-amber-300",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "min-w-0 border-b border-border p-3 text-left transition-colors last:border-b-0 hover:bg-[var(--surface-hover)] sm:border-b-0 sm:border-l sm:first:border-l-0",
        active && "bg-[var(--surface-selected)]",
      )}
    >
      <span className={cn("flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.15em]", tones[tone], "[&_svg]:h-3.5 [&_svg]:w-3.5")}>
        {icon}
        {label}
      </span>
      <span className="mt-2 block font-mono text-lg font-semibold tabular-nums text-foreground">{value}</span>
      <span className="mt-0.5 block truncate text-[10px] text-muted-foreground">{detail}</span>
    </button>
  );
}

function JobRow({ job }: { job: AuditJobSummary }) {
  const total = job.total || 0;
  const done = job.done || 0;
  const failed = job.failed || 0;
  const processed = done + failed;
  const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
  const donePercent = total > 0 ? (done / total) * 100 : 0;
  const failedPercent = total > 0 ? (failed / total) * 100 : 0;
  const isRunning = job.status === "processing" || job.status === "pending";
  const eta = calculateEta(job);
  const created = formatFullDateTime(job.created_at);

  return (
    <TableRow>
      <TableCell>
        <p className="max-w-64 truncate font-medium text-foreground">
          {job.client_name || `Cliente #${job.fac_nit_sec}`}
        </p>
        <div className="mt-1 flex flex-wrap items-center gap-2 font-mono text-[10px] text-muted-foreground">
          {job.fac_nit_sec > 0 ? <span>NIT {job.fac_nit_sec}</span> : null}
          {job.date_from || job.date_to ? (
            <span className="inline-flex items-center gap-1">
              <Calendar className="h-3 w-3" aria-hidden="true" />
              {formatCompactDate(job.date_from)} a {formatCompactDate(job.date_to)}
            </span>
          ) : null}
        </div>
      </TableCell>
      <TableCell>
        <p className="font-mono text-xs tabular-nums text-foreground/85">{created.absolute}</p>
        <p className="mt-1 text-[10px] text-muted-foreground">{created.relative}</p>
      </TableCell>
      <TableCell><JobStateBadge status={job.status} /></TableCell>
      <TableCell>
        <div className="min-w-40">
          <div className="flex items-center justify-between gap-3 font-mono text-[11px] tabular-nums">
            <span>{processed} / {total}</span>
            <span className="font-semibold">{percent}%</span>
          </div>
          <div className="mt-2 flex h-1.5 overflow-hidden rounded-full bg-muted" aria-label={`Progreso ${percent}%`}>
            {isRunning ? (
              <span className="bg-sky-400 transition-[width] duration-200" style={{ width: `${percent}%` }} />
            ) : (
              <>
                <span className="bg-emerald-400" style={{ width: `${donePercent}%` }} />
                <span className="bg-rose-400" style={{ width: `${failedPercent}%` }} />
              </>
            )}
          </div>
          <p className="mt-1.5 font-mono text-[10px] text-muted-foreground">
            <span className="text-emerald-300">{done} OK</span>
            <span aria-hidden="true"> · </span>
            <span className={failed > 0 ? "text-rose-300" : undefined}>{failed} DLQ</span>
          </p>
        </div>
      </TableCell>
      <TableCell>
        {isRunning ? (
          <div>
            <p className="flex items-center gap-1.5 font-mono text-xs text-sky-300">
              <Timer className="h-3.5 w-3.5" aria-hidden="true" />
              ETA {eta}
            </p>
            <p className="mt-1 font-mono text-[10px] text-muted-foreground">{formatSpeed(job.avg_duration_ms)}</p>
          </div>
        ) : (
          <div>
            <p className="flex items-center gap-1.5 font-mono text-xs text-foreground/85">
              <Clock3 className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
              {job.accumulated_duration_ms > 0 ? formatDuration(job.accumulated_duration_ms) : "Completado"}
            </p>
            {job.avg_duration_ms > 0 ? (
              <p className="mt-1 font-mono text-[10px] text-muted-foreground">{formatSpeed(job.avg_duration_ms)}</p>
            ) : null}
          </div>
        )}
      </TableCell>
      <TableCell className="text-right">
        <Button asChild variant="outline" size="sm">
          <Link href={`/audit/jobs/${job.job_id}`}>
            Monitorear
            <ArrowRight />
          </Link>
        </Button>
      </TableCell>
    </TableRow>
  );
}

function JobStateBadge({ status }: { status: string }) {
  if (status === "processing") {
    return <Badge variant="info"><Activity />Procesando</Badge>;
  }
  if (status === "completed") {
    return <Badge variant="success"><CheckCircle2 />Completado</Badge>;
  }
  if (status === "completed_with_errors") {
    return <Badge variant="warning"><AlertTriangle />Con errores</Badge>;
  }
  if (status === "failed") {
    return <Badge variant="danger"><AlertCircle />Fallido</Badge>;
  }
  return <Badge variant="neutral"><Clock3 />En cola</Badge>;
}
