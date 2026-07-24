"use client";

import type { ReactNode } from "react";
import Link from "next/link";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, ExternalLink, XCircle, AlertTriangle } from "lucide-react";

import { auditJobQuery } from "@/lib/query/audit";
import { formatNumber, formatDurationMs } from "@/lib/formatters";
import { JobStatusBadge } from "@/components/jobs/job-status-badge";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { AuditFlowGraph } from "@/components/audit-flow/audit-flow-graph";
import { useAuditTelemetry } from "@/hooks/use-audit-telemetry";

export function JobDetailClient({ jobId }: { jobId: string }) {
  const { data, isLoading, isError, error } = useQuery(auditJobQuery(jobId));
  const hasPerformanceMetrics =
    Number(data?.performance.accumulatedDurationMs ?? 0) > 0;

  useAuditTelemetry(jobId, undefined, "job");

  if (isLoading) {
    return (
      <div className="py-8">
        <BackendRequestSkeleton
          description="El backend está consultando estado, progreso y métricas del job."
          title="Consultando job"
          variant="panel"
        />
      </div>
    );
  }

  if (isError) {
    return (
      <Alert variant="destructive" className="mt-6">
        <AlertTriangle />
        <AlertDescription>
          {error instanceof Error ? error.message : "No se pudo consultar el job."}
        </AlertDescription>
      </Alert>
    );
  }

  const progressPct = data?.progress ?? 0;
  const processed = data?.processed ?? 0;
  const total = data?.total ?? 0;
  const status = data?.status ?? "queued";
  const isTerminal = status === "completed" || status === "completed_with_errors" || status === "failed";
  const result = data?.result;

  return (
    <div className="flex h-[calc(100vh-14rem)] flex-col space-y-6">
      <div className="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-3">
            <p className="text-3xl font-semibold tabular-nums text-white">
              {formatNumber(progressPct)}%
            </p>
            <JobStatusBadge status={status} />
          </div>
          <p className="text-sm text-slate-400">
            {formatNumber(processed)} de {formatNumber(total)} facturas procesadas
          </p>
        </div>

        <div className="flex flex-col gap-5 md:items-end">
          <div className="flex gap-8">
            <Metric
              label="Éxitos"
              value={formatNumber(result?.succeeded ?? 0)}
              icon={<CheckCircle2 className="h-4 w-4 text-emerald-400" />}
            />
            <Metric
              label="Fallos"
              value={formatNumber(result?.failed ?? 0)}
              icon={<XCircle className="h-4 w-4 text-rose-400" />}
            />
            <Metric
              label="Revisión"
              value={formatNumber(result?.skipped ?? 0)}
              icon={<AlertTriangle className="h-4 w-4 text-amber-400" />}
            />
          </div>
          {isTerminal && status !== "failed" && (
            <Button
              variant="secondary"
              asChild
              className="group h-9 rounded-lg border-0 bg-slate-800/40 px-5 font-medium tracking-wide text-slate-300 transition-colors duration-200 hover:bg-slate-800/80 hover:text-white"
            >
              <Link href="/audit/results" className="flex items-center gap-2">
                Ver resultados detallados
                <ExternalLink className="h-3.5 w-3.5 text-slate-500 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-slate-300" />
              </Link>
            </Button>
          )}
        </div>
      </div>

      <div className="h-1 flex-shrink-0 overflow-hidden rounded-full bg-slate-800/50">
        <div
          className="h-full rounded-full bg-sky-500 transition-all duration-300"
          style={{ width: `${Math.min(progressPct, 100)}%` }}
        />
      </div>

      {data?.error && (
        <Alert
          variant="destructive"
          className="border-rose-900/50 bg-rose-950/20 text-rose-200"
        >
          <AlertTriangle className="h-4 w-4 text-rose-500" />
          <AlertDescription>{data.error}</AlertDescription>
        </Alert>
      )}

      <div className="min-h-[400px] flex-1">
        <AuditFlowGraph />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-x-8 gap-y-4 border-t border-slate-800/60 pb-2 pt-4 font-mono text-[11px] text-slate-500">
        <div className="flex flex-wrap gap-x-6 gap-y-2">
          <span>
            ID: <span className="text-slate-300">{jobId}</span>
          </span>
          <span>
            Creado:{" "}
            <span className="text-slate-300">
              {String(data?.createdAt ?? "—")}
            </span>
          </span>
          <span>
            Iniciado:{" "}
            <span className="text-slate-300">
              {String(data?.startedAt ?? "Pendiente")}
            </span>
          </span>
          <span>
            Completado:{" "}
            <span className="text-slate-300">
              {String(data?.completedAt ?? "En curso")}
            </span>
          </span>
        </div>

        {hasPerformanceMetrics && data?.performance && (
          <div className="flex flex-wrap gap-x-6 gap-y-2">
            <span className="flex items-center gap-1.5">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-500/50" />
              Throughput:{" "}
              <span className="text-slate-300">
                {formatNumber(data.performance.throughputPerSec)}/s
              </span>
            </span>
            <span>
              Promedio activo:{" "}
              <span className="text-slate-300">
                {formatDurationMs(data.performance.avgDurationMs)}
              </span>
            </span>
            <span>
              Activo acumulado:{" "}
              <span className="text-slate-300">
                {formatDurationMs(data.performance.accumulatedDurationMs)}
              </span>
            </span>
          </div>
        )}
      </div>
    </div>
  );
}

function Metric({
  label,
  value,
  mono = false,
  icon,
}: {
  label: string;
  value: ReactNode;
  mono?: boolean;
  icon?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center gap-1.5">
        {icon}
        <p className="text-[10px] uppercase tracking-wider text-slate-500">
          {label}
        </p>
      </div>
      <div
        className={`text-2xl font-light text-slate-200 ${mono ? "break-all font-mono text-sm" : ""}`}
      >
        {value}
      </div>
    </div>
  );
}
