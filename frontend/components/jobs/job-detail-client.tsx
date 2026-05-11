"use client";

import type { ReactNode } from "react";
import Link from "next/link";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, ExternalLink, Loader2, XCircle, AlertTriangle } from "lucide-react";

import { auditJobQuery } from "@/lib/query/audit";
import { formatNumber } from "@/lib/formatters";
import { JobStatusBadge } from "@/components/jobs/job-status-badge";
import { SectionCard } from "@/components/shared/section-card";
import { Button } from "@/components/ui/button";

export function JobDetailClient({ jobId }: { jobId: string }) {
  const { data, isLoading, isError, error } = useQuery(auditJobQuery(jobId));

  if (isLoading) {
    return (
      <SectionCard title="Cargando job...">
        <div className="surface-subtle flex items-center gap-3 rounded-lg p-5 text-sm text-slate-300">
          <Loader2 className="h-4 w-4 animate-spin text-sky-400" />
          Consultando estado del job.
        </div>
      </SectionCard>
    );
  }

  if (isError) {
    return (
      <SectionCard title="Error">
        <div className="rounded-lg border border-rose-500/20 bg-rose-500/6 p-5 text-sm text-rose-200">
          {error instanceof Error ? error.message : "No se pudo consultar el job."}
        </div>
      </SectionCard>
    );
  }

  const progressPct = data?.progress ?? 0;
  const processed = data?.processed ?? 0;
  const total = data?.total ?? 0;
  const status = data?.status ?? "queued";
  const isTerminal = status === "completed" || status === "failed";
  const result = data?.result;

  return (
    <div className="space-y-6">
      {/* Barra de progreso */}
      <SectionCard title="Progreso">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <JobStatusBadge status={status} />
            <span className="text-2xl font-semibold text-white">
              {formatNumber(progressPct)}%
            </span>
          </div>
          <div className="h-3 overflow-hidden rounded-full bg-slate-800">
            <div
              className="h-full rounded-full bg-sky-500 transition-all duration-300"
              style={{ width: `${Math.min(progressPct, 100)}%` }}
            />
          </div>
          <p className="text-sm text-slate-400">
            {formatNumber(processed)} de {formatNumber(total)} facturas procesadas
          </p>
        </div>
      </SectionCard>

      {/* Métricas del job */}
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Metric label="Job ID" value={jobId} mono />
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
          label="Omitidos"
          value={formatNumber(result?.skipped ?? 0)}
          icon={<AlertTriangle className="h-4 w-4 text-amber-400" />}
        />
      </div>

      {/* Timestamps */}
      <SectionCard title="Tiempos">
        <div className="grid gap-4 md:grid-cols-3">
          <Metric label="Creado" value={String(data?.createdAt ?? "—")} />
          <Metric label="Iniciado" value={String(data?.startedAt ?? "Pendiente")} />
          <Metric label="Completado" value={String(data?.completedAt ?? "En curso")} />
        </div>
      </SectionCard>

      {/* Error si existe */}
      {data?.error && (
        <SectionCard title="Error del job">
          <div className="rounded-lg border border-rose-500/20 bg-rose-500/6 p-4 text-sm text-rose-200">
            {data.error}
          </div>
        </SectionCard>
      )}

      {/* Link a resultados si completó */}
      {isTerminal && status !== "failed" && (
        <div className="flex justify-end">
          <Button asChild>
            <Link href="/audit/results">
              Ver resultados de auditoría
              <ExternalLink className="h-4 w-4" />
            </Link>
          </Button>
        </div>
      )}
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
    <div className="surface-subtle rounded-lg p-4">
      <div className="flex items-center gap-2">
        {icon}
        <p className="text-[11px] uppercase tracking-[0.2em] text-slate-500">{label}</p>
      </div>
      <div className={`mt-3 text-lg font-semibold text-white ${mono ? "font-mono text-sm break-all" : ""}`}>
        {value}
      </div>
    </div>
  );
}
