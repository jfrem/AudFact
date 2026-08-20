"use client";

import { useEffect, useMemo, useState, useTransition } from "react";
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
  Clock,
  Layers,
  RefreshCw,
  Search,
  Timer,
  X,
} from "lucide-react";

import { getAuditJobs } from "@/lib/api/audfact";
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
import { EmptyState } from "@/components/shared/empty-state";
import { SectionCard } from "@/components/shared/section-card";

// ── Helpers de Formateo y Telemetría Impecable ───────────────────────

function formatDuration(ms: number): string {
  if (!ms || ms <= 0) return "-";
  const totalSeconds = Math.floor(ms / 1000);
  if (totalSeconds < 60) return `${totalSeconds}s`;
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  if (minutes < 60) {
    return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
  }
  const hours = Math.floor(minutes / 60);
  const remMinutes = minutes % 60;
  return `${hours}h ${remMinutes}m`;
}

function calculateEta(job: AuditJobSummary): string | null {
  if (job.status !== "processing" && job.status !== "pending") {
    return null;
  }

  const total = job.total || 0;
  const processed = (job.done || 0) + (job.failed || 0);
  const remaining = Math.max(0, total - processed);

  if (remaining === 0) {
    return "< 5s";
  }

  // 1. Prioridad: Usar avg_duration_ms del worker
  if (job.avg_duration_ms > 0 && processed > 0) {
    const etaSeconds = Math.round((remaining * job.avg_duration_ms) / 1000);
    if (etaSeconds < 60) return `~${etaSeconds}s`;
    const mins = Math.floor(etaSeconds / 60);
    const secs = etaSeconds % 60;
    return secs > 0 ? `~${mins}m ${secs}s` : `~${mins}m`;
  }

  // 2. Fallback: Calcular velocidad basada en tiempo transcurrido desde created_at
  if (job.created_at && processed > 0) {
    const elapsedMs = Date.now() - new Date(job.created_at).getTime();
    if (elapsedMs > 0) {
      const msPerDoc = elapsedMs / processed;
      const etaSeconds = Math.round((remaining * msPerDoc) / 1000);
      if (etaSeconds < 60) return `~${etaSeconds}s`;
      const mins = Math.floor(etaSeconds / 60);
      const secs = etaSeconds % 60;
      return secs > 0 ? `~${mins}m ${secs}s` : `~${mins}m`;
    }
  }

  return "Estimando...";
}

function formatSpeed(avgDurationMs: number): string {
  if (!avgDurationMs || avgDurationMs <= 0) return "-";
  const seconds = avgDurationMs / 1000;
  if (seconds < 60) {
    return `${seconds.toFixed(1)}s / doc`;
  }
  const mins = (seconds / 60).toFixed(1);
  return `${mins}m / doc`;
}

function formatRelativeTime(dateStr?: string | null): string {
  if (!dateStr) return "-";
  const diffMs = Date.now() - new Date(dateStr).getTime();
  if (diffMs < 0) return "hace instantes";
  const diffSecs = Math.floor(diffMs / 1000);
  if (diffSecs < 60) return `hace ${diffSecs}s`;
  const diffMins = Math.floor(diffSecs / 60);
  if (diffMins < 60) return `hace ${diffMins}m`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `hace ${diffHours}h`;
  const diffDays = Math.floor(diffHours / 24);
  return `hace ${diffDays}d`;
}

function formatFullDateTime(dateStr?: string | null): { date: string; time: string; relative: string } {
  if (!dateStr) return { date: "-", time: "-", relative: "-" };
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return { date: dateStr, time: "-", relative: "-" };

  const months = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
  const day = String(d.getDate()).padStart(2, "0");
  const month = months[d.getMonth()];
  const year = d.getFullYear();
  const dateFormatted = `${day} ${month} ${year}`;

  const timeFormatted = d.toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });

  const relative = formatRelativeTime(dateStr);

  return { date: dateFormatted, time: timeFormatted, relative };
}

function formatCompactDate(dateStr?: string | null): string {
  if (!dateStr) return "-";
  const parts = dateStr.split("-");
  if (parts.length === 3) {
    const months = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
    const mIdx = parseInt(parts[1], 10) - 1;
    return `${parts[2]} ${months[mIdx] || parts[1]} ${parts[0]}`;
  }
  return dateStr;
}

// ── Componente Principal ─────────────────────────────────────────────

export function JobsListLive() {
  const [jobs, setJobs] = useState<AuditJobSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [lastRefreshed, setLastRefreshed] = useState<Date | null>(null);
  const [countdown, setCountdown] = useState(5);
  const [filterQuery, setFilterQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [pageSize, setPageSize] = useState<number>(10);
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [isPending, startTransition] = useTransition();

  const fetchJobs = async () => {
    try {
      setError(null);
      const data = await getAuditJobs({ limit: 50 });
      setJobs(data || []);
      setLastRefreshed(new Date());
      setCountdown(5);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error al consultar los jobs");
    } finally {
      setLoading(false);
    }
  };

  // Carga inicial
  useEffect(() => {
    fetchJobs();
  }, []);

  // Polling cada 5 segundos y cuenta regresiva de heartbeat
  useEffect(() => {
    if (!autoRefresh) return;

    const timer = setInterval(() => {
      setCountdown((prev) => (prev > 1 ? prev - 1 : 5));
    }, 1000);

    const interval = setInterval(() => {
      startTransition(() => {
        fetchJobs();
      });
    }, 5000);

    return () => {
      clearInterval(timer);
      clearInterval(interval);
    };
  }, [autoRefresh]);

  // Filtrado reactivo en memoria
  const filteredJobs = useMemo(() => {
    return jobs.filter((job) => {
      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && (job.status === "processing" || job.status === "pending")) ||
        (statusFilter === "completed" && job.status === "completed") ||
        (statusFilter === "errors" && (job.status === "completed_with_errors" || job.status === "failed"));

      const query = filterQuery.toLowerCase().trim();
      const matchesQuery =
        !query ||
        job.job_id.toLowerCase().includes(query) ||
        (job.client_name && job.client_name.toLowerCase().includes(query)) ||
        String(job.fac_nit_sec).includes(query);

      return matchesStatus && matchesQuery;
    });
  }, [jobs, statusFilter, filterQuery]);

  // Paginación en cliente
  const totalPages = Math.max(1, Math.ceil(filteredJobs.length / pageSize));
  const validPage = Math.min(currentPage, totalPages);
  const startIndex = (validPage - 1) * pageSize;
  const paginatedJobs = filteredJobs.slice(startIndex, startIndex + pageSize);

  const activeCount = jobs.filter((j) => j.status === "processing" || j.status === "pending").length;
  const completedCount = jobs.filter((j) => j.status === "completed").length;
  const errorCount = jobs.filter((j) => j.status === "completed_with_errors" || j.status === "failed").length;

  return (
    <SectionCard
      title="Monitoreo de Jobs en Vivo"
      description="Centro de telemetría y control en tiempo real de lotes asíncronos en cola de Redis."
      actions={
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2.5 text-xs text-muted-foreground bg-muted/40 px-3 py-1.5 rounded-lg border border-border/60 shadow-xs">
            <Switch
              id="auto-refresh"
              checked={autoRefresh}
              onCheckedChange={setAutoRefresh}
            />
            <label htmlFor="auto-refresh" className="cursor-pointer font-medium text-foreground select-none flex items-center gap-1.5">
              <span>Auto-refresco</span>
              <span className="font-mono text-xs opacity-75">({autoRefresh ? `${countdown}s` : "Pausado"})</span>
            </label>
            {autoRefresh ? (
              <span className="relative flex h-2 w-2 ml-0.5">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
              </span>
            ) : null}
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setLoading(true);
              fetchJobs();
            }}
            disabled={loading || isPending}
            className="h-9 gap-1.5 shadow-xs font-medium"
          >
            <RefreshCw className={`h-3.5 w-3.5 ${loading || isPending ? "animate-spin" : ""}`} />
            Refrescar
          </Button>
        </div>
      }
    >
      {/* ── Barra de Métricas Rápidas (Mission Control KPIs) ── */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div
          onClick={() => {
            setStatusFilter("all");
            setCurrentPage(1);
          }}
          className={`cursor-pointer p-4 rounded-xl border transition-all duration-150 relative ${
            statusFilter === "all"
              ? "bg-slate-900/80 border-sky-500/50 shadow-md ring-1 ring-sky-500/20"
              : "bg-card/70 hover:bg-muted/50 border-border/60 hover:border-border"
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">Total Jobs</span>
            <Layers className="h-4 w-4 text-muted-foreground/80" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <p className="text-2xl font-bold tracking-tight text-foreground">{jobs.length}</p>
            <span className="text-[11px] text-muted-foreground">en retención</span>
          </div>
          {statusFilter === "all" ? (
            <div className="absolute bottom-0 left-3 right-3 h-[2px] bg-sky-500 rounded-full" />
          ) : null}
        </div>

        <div
          onClick={() => {
            setStatusFilter("active");
            setCurrentPage(1);
          }}
          className={`cursor-pointer p-4 rounded-xl border transition-all duration-150 relative ${
            statusFilter === "active"
              ? "bg-blue-950/40 border-blue-500/60 shadow-md ring-1 ring-blue-500/30"
              : "bg-card/70 hover:bg-muted/50 border-border/60 hover:border-border"
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-blue-400">En Ejecución</span>
            <Activity className={`h-4 w-4 text-blue-400 ${activeCount > 0 ? "animate-pulse" : ""}`} />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <p className="text-2xl font-bold text-blue-400 tracking-tight">{activeCount}</p>
            <span className="text-[11px] text-blue-400/80">{activeCount > 0 ? "procesando" : "en reposo"}</span>
          </div>
          {statusFilter === "active" ? (
            <div className="absolute bottom-0 left-3 right-3 h-[2px] bg-blue-500 rounded-full" />
          ) : null}
        </div>

        <div
          onClick={() => {
            setStatusFilter("completed");
            setCurrentPage(1);
          }}
          className={`cursor-pointer p-4 rounded-xl border transition-all duration-150 relative ${
            statusFilter === "completed"
              ? "bg-emerald-950/30 border-emerald-500/60 shadow-md ring-1 ring-emerald-500/30"
              : "bg-card/70 hover:bg-muted/50 border-border/60 hover:border-border"
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-emerald-400">Completados</span>
            <CheckCircle2 className="h-4 w-4 text-emerald-400" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <p className="text-2xl font-bold text-emerald-400 tracking-tight">{completedCount}</p>
            <span className="text-[11px] text-emerald-400/80">100% éxito</span>
          </div>
          {statusFilter === "completed" ? (
            <div className="absolute bottom-0 left-3 right-3 h-[2px] bg-emerald-500 rounded-full" />
          ) : null}
        </div>

        <div
          onClick={() => {
            setStatusFilter("errors");
            setCurrentPage(1);
          }}
          className={`cursor-pointer p-4 rounded-xl border transition-all duration-150 relative ${
            statusFilter === "errors"
              ? "bg-amber-950/30 border-amber-500/60 shadow-md ring-1 ring-amber-500/30"
              : "bg-card/70 hover:bg-muted/50 border-border/60 hover:border-border"
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-amber-400">Con Errores / DLQ</span>
            <AlertCircle className="h-4 w-4 text-amber-400" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <p className="text-2xl font-bold text-amber-400 tracking-tight">{errorCount}</p>
            <span className="text-[11px] text-amber-400/80">{errorCount > 0 ? "revisión" : "limpio"}</span>
          </div>
          {statusFilter === "errors" ? (
            <div className="absolute bottom-0 left-3 right-3 h-[2px] bg-amber-500 rounded-full" />
          ) : null}
        </div>
      </div>

      {/* ── Filtros, Búsqueda y Paginación Superior ── */}
      <div className="flex flex-col sm:flex-row gap-3 items-center justify-between mb-4">
        <div className="relative w-full sm:w-88">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Filtrar por EPS, NIT o Job ID..."
            value={filterQuery}
            onChange={(e) => {
              setFilterQuery(e.target.value);
              setCurrentPage(1);
            }}
            className="pl-9 pr-8 h-9 text-sm bg-muted/20 border-border/70 focus:bg-background transition-colors"
          />
          {filterQuery ? (
            <button
              onClick={() => {
                setFilterQuery("");
                setCurrentPage(1);
              }}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              title="Limpiar búsqueda"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          ) : null}
        </div>

        <div className="flex items-center gap-3 text-xs text-muted-foreground w-full sm:w-auto justify-between sm:justify-end">
          {lastRefreshed ? (
            <span className="font-mono">
              Actualizado: {lastRefreshed.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
            </span>
          ) : null}

          <div className="flex items-center gap-1.5">
            <span className="font-medium text-foreground/80">Mostrar:</span>
            <Select
              value={String(pageSize)}
              onValueChange={(val) => {
                setPageSize(Number(val));
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="h-8 w-[76px] text-xs font-medium border-border/80 bg-muted/40 text-foreground hover:bg-muted/70">
                <SelectValue placeholder="10" />
              </SelectTrigger>
              <SelectContent align="end" className="min-w-[5rem] bg-slate-900 border-slate-700/80 text-slate-100 shadow-2xl">
                <SelectItem value="10">10</SelectItem>
                <SelectItem value="25">25</SelectItem>
                <SelectItem value="50">50</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      {/* ── Error Banner ── */}
      {error ? (
        <div className="p-4 mb-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-sm flex items-center gap-2">
          <AlertCircle className="h-4 w-4 flex-shrink-0" />
          <span>{error}</span>
        </div>
      ) : null}

      {/* ── Tabla de Jobs Mission Control ── */}
      {loading && jobs.length === 0 ? (
        <div className="py-12 flex flex-col items-center justify-center gap-3">
          <RefreshCw className="h-6 w-6 text-primary animate-spin" />
          <p className="text-sm text-muted-foreground">Cargando telemetría de jobs desde Redis...</p>
        </div>
      ) : filteredJobs.length === 0 ? (
        <div className="p-8 text-center rounded-xl border border-dashed border-border/70 bg-card/30">
          <EmptyState
            title="No se encontraron jobs"
            description={
              filterQuery || statusFilter !== "all"
                ? "No hay jobs que coincidan con los filtros aplicados."
                : "No hay jobs batch activos ni recientes en la cola de Redis."
            }
          />
          {(filterQuery || statusFilter !== "all") ? (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setFilterQuery("");
                setStatusFilter("all");
                setCurrentPage(1);
              }}
              className="mt-4 text-xs font-medium"
            >
              Restablecer filtros
            </Button>
          ) : null}
        </div>
      ) : (
        <div className="space-y-3">
          <div className="rounded-xl border border-border/80 overflow-hidden bg-card/60 shadow-xs">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/40 border-b border-border/80">
                  <TableHead className="w-[260px]">Cliente / Lote</TableHead>
                  <TableHead className="w-[180px]">Ejecutado</TableHead>
                  <TableHead className="w-[130px]">Estado</TableHead>
                  <TableHead className="w-[240px]">Progreso y Volumen</TableHead>
                  <TableHead className="w-[190px]">Tiempo y Ritmo</TableHead>
                  <TableHead className="text-right w-[100px]">Acción</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedJobs.map((job) => {
                  const total = job.total || 0;
                  const done = job.done || 0;
                  const failed = job.failed || 0;
                  const processed = done + failed;
                  const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
                  const donePercent = total > 0 ? (done / total) * 100 : 0;
                  const failedPercent = total > 0 ? (failed / total) * 100 : 0;
                  const isRunning = job.status === "processing" || job.status === "pending";
                  const eta = calculateEta(job);
                  const execDateTime = formatFullDateTime(job.created_at);

                  return (
                    <TableRow key={job.job_id} className="hover:bg-muted/30 transition-colors border-b border-border/40">
                      {/* 1. Cliente / Lote & UUID */}
                      <TableCell className="py-3.5">
                        <div className="flex flex-col gap-1">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-sm text-foreground">
                              {job.client_name || `Cliente #${job.fac_nit_sec}`}
                            </span>
                            {job.fac_nit_sec > 0 ? (
                              <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-muted/60 text-muted-foreground border border-border/40">
                                NIT {job.fac_nit_sec}
                              </span>
                            ) : null}
                          </div>

                          {(job.date_from || job.date_to) ? (
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-mono mt-0.5">
                              <Calendar className="h-3.5 w-3.5 text-muted-foreground/70 flex-shrink-0" />
                              <span>
                                {formatCompactDate(job.date_from)} → {formatCompactDate(job.date_to)}
                              </span>
                            </div>
                          ) : null}
                        </div>
                      </TableCell>

                      {/* 2. Fecha y Hora de Ejecución */}
                      <TableCell className="py-3.5">
                        <div className="flex flex-col gap-1">
                          <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                            <Calendar className="h-3.5 w-3.5 text-sky-400 flex-shrink-0" />
                            <span>{execDateTime.date}</span>
                          </div>
                          <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground font-mono">
                            <Clock className="h-3 w-3 text-muted-foreground/70" />
                            <span>{execDateTime.time}</span>
                            <span className="text-muted-foreground/70">({execDateTime.relative})</span>
                          </div>
                        </div>
                      </TableCell>

                      {/* 3. Estado Visual */}
                      <TableCell className="py-3.5">
                        {job.status === "processing" ? (
                          <Badge variant="info" className="gap-1.5 py-1 px-2.5 bg-sky-500/10 text-sky-400 border-sky-500/30">
                            <span className="relative flex h-2 w-2">
                              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75" />
                              <span className="relative inline-flex rounded-full h-2 w-2 bg-sky-500" />
                            </span>
                            Procesando
                          </Badge>
                        ) : job.status === "completed" ? (
                          <Badge variant="success" className="gap-1.5 py-1 px-2.5 bg-emerald-500/10 text-emerald-400 border-emerald-500/30 font-medium">
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                            Completado
                          </Badge>
                        ) : job.status === "completed_with_errors" ? (
                          <Badge variant="warning" className="gap-1.5 py-1 px-2.5 bg-amber-500/10 text-amber-400 border-amber-500/30 font-medium">
                            <AlertTriangle className="h-3.5 w-3.5 text-amber-400" />
                            Con Errores
                          </Badge>
                        ) : job.status === "failed" ? (
                          <Badge variant="danger" className="gap-1.5 py-1 px-2.5 bg-rose-500/10 text-rose-400 border-rose-500/30 font-medium">
                            <AlertCircle className="h-3.5 w-3.5 text-rose-400" />
                            Fallido
                          </Badge>
                        ) : (
                          <Badge variant="warning" className="gap-1.5 py-1 px-2.5 bg-slate-500/10 text-slate-300 border-slate-500/30 font-medium">
                            <Clock className="h-3.5 w-3.5" />
                            En Cola
                          </Badge>
                        )}
                      </TableCell>

                      {/* 4. Progreso y Volumen Bicolor */}
                      <TableCell className="py-3.5">
                        <div className="flex flex-col gap-1.5">
                          <div className="flex justify-between items-center text-xs">
                            <span className="font-semibold text-foreground font-mono">
                              {processed.toLocaleString()} / {total.toLocaleString()}
                            </span>
                            <span className="font-bold text-foreground/90 font-mono">{percent}%</span>
                          </div>
                          
                          {/* Barra de progreso bicolor */}
                          <div className="w-full bg-slate-800 rounded-full h-2 overflow-hidden flex">
                            {isRunning ? (
                              <div
                                className="h-full rounded-full transition-all duration-500 bg-gradient-to-r from-blue-500 to-indigo-500 animate-pulse"
                                style={{ width: `${percent}%` }}
                              />
                            ) : (
                              <>
                                <div
                                  className="h-full bg-emerald-500 transition-all duration-300"
                                  style={{ width: `${donePercent}%` }}
                                  title={`${done} exitosas`}
                                />
                                {failed > 0 ? (
                                  <div
                                    className="h-full bg-rose-500 transition-all duration-300"
                                    style={{ width: `${failedPercent}%` }}
                                    title={`${failed} en DLQ`}
                                  />
                                ) : null}
                              </>
                            )}
                          </div>

                          <div className="flex items-center gap-2 text-[11px] font-mono">
                            <span className="text-emerald-400 font-medium">{done.toLocaleString()} OK</span>
                            {failed > 0 ? (
                              <span className="text-rose-400 font-semibold">• {failed.toLocaleString()} DLQ</span>
                            ) : (
                              <span className="text-muted-foreground/60">• 0 DLQ</span>
                            )}
                          </div>
                        </div>
                      </TableCell>

                      {/* 5. Tiempo de Proceso y Ritmo */}
                      <TableCell className="py-3.5">
                        <div className="flex flex-col gap-1">
                          {isRunning ? (
                            <>
                              <div className="flex items-center gap-1.5 text-xs text-sky-400 font-semibold font-mono">
                                <Timer className="h-3.5 w-3.5 animate-spin text-sky-400" />
                                <span>Faltan {eta}</span>
                              </div>
                              <div className="flex items-center gap-1 text-[11px] text-sky-400/80 font-mono">
                                <span className="text-muted-foreground/70">Ritmo:</span>
                                <span>{job.avg_duration_ms > 0 ? formatSpeed(job.avg_duration_ms) : "Calculando..."}</span>
                              </div>
                            </>
                          ) : (
                            <>
                              <div className="flex items-center gap-1.5 text-xs text-foreground/90 font-mono font-medium">
                                <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                <span>
                                  {job.accumulated_duration_ms > 0
                                    ? formatDuration(job.accumulated_duration_ms)
                                    : "Completado"}
                                </span>
                                <span className="text-[10px] text-muted-foreground/70 font-sans font-normal">(total)</span>
                              </div>
                              {job.avg_duration_ms > 0 ? (
                                <div className="flex items-center gap-1 text-[11px] text-muted-foreground/80 font-mono">
                                  <span className="text-muted-foreground/60">Promedio:</span>
                                  <span>{formatSpeed(job.avg_duration_ms)}</span>
                                </div>
                              ) : null}
                            </>
                          )}
                        </div>
                      </TableCell>

                      {/* 6. Acción Directa */}
                      <TableCell className="text-right py-3.5">
                        <Link href={`/audit/jobs/${job.job_id}`}>
                          <Button variant="outline" size="sm" className="h-8 gap-1 text-xs font-medium border-border/80 hover:bg-primary/10 hover:border-primary/50 hover:text-primary transition-all">
                            Monitorear
                            <ArrowRight className="h-3 w-3" />
                          </Button>
                        </Link>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>

          {/* ── Paginación Compacta en Cliente ── */}
          {totalPages > 1 ? (
            <div className="flex items-center justify-between px-2 pt-2 text-xs text-muted-foreground">
              <span>
                Mostrando {startIndex + 1}–{Math.min(startIndex + pageSize, filteredJobs.length)} de {filteredJobs.length} jobs
              </span>

              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={validPage === 1}
                  className="h-8 px-2.5 gap-1 text-xs font-medium"
                >
                  <ChevronLeft className="h-3.5 w-3.5" />
                  Anterior
                </Button>

                <span className="font-semibold text-foreground px-2 font-mono">
                  Página {validPage} de {totalPages}
                </span>

                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  disabled={validPage === totalPages}
                  className="h-8 px-2.5 gap-1 text-xs font-medium"
                >
                  Siguiente
                  <ChevronRight className="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      )}
    </SectionCard>
  );
}
