"use client";

import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import {
  AlertTriangle,
  BarChart3,
  ChevronDown,
  FileSearch,
  ShieldCheck,
} from "lucide-react";

import type {
  AttachmentRecord,
  AuditSingleResponse,
  DispensationDetail,
} from "@/lib/schemas/domain";
import { attachmentsQuery, dispensationQuery } from "@/lib/query/audit";
import { formatDurationMs, formatNumber } from "@/lib/formatters";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { SeverityBadge } from "@/components/shared/severity-badge";
import { AuditMetricsPanel } from "@/components/audit/audit-metrics-panel";
import { ResultItemsTable } from "@/components/audit/result-items-table";
import { AttachmentList } from "@/components/attachments/attachment-list";
import { AttachmentViewerPanel } from "@/components/attachments/attachment-viewer-panel";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";

/* ─────────────────────────────────────────────────── */

export function AuditSingleWorkspace({
  disId,
  disDetNro,
  result,
}: {
  disId: string;
  disDetNro: string;
  result: AuditSingleResponse;
}) {
  const dispensationState = useQuery(dispensationQuery(disId, disDetNro));
  const dispensation = dispensationState.data;
  const header = dispensation?.header;
  const nitSec = header?.NitSec != null ? String(header.NitSec) : "";

  const attachmentsState = useQuery({
    ...attachmentsQuery(disDetNro, nitSec),
    enabled: Boolean(disDetNro) && Boolean(nitSec),
  });

  const [selectedAttachment, setSelectedAttachment] =
    React.useState<AttachmentRecord | undefined>(undefined);

  const [messageExpanded, setMessageExpanded] = React.useState(false);

  React.useEffect(() => {
    if (!attachmentsState.data?.length) return;
    setSelectedAttachment((current) => current ?? attachmentsState.data?.[0]);
  }, [attachmentsState.data]);

  const patientSummary = getPatientSummary(dispensation);
  const findingsCount = result.findings?.length ?? 0;
  const attachmentsCount = attachmentsState.data?.length ?? 0;
  const message =
    result.message ?? "El backend no devolvió un mensaje funcional.";
  const isMessageLong = message.length > 160;

  return (
    <div className="space-y-4 fade-in-up">
      {/* ── L1: Sticky KPI Strip ── */}
      <div className="sticky-bar rounded-lg px-5 py-3.5">
        <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
          <KpiChip label="Estado">
            <AuditStatusBadge status={result.status} />
          </KpiChip>
          <KpiChip label="Severidad">
            <SeverityBadge severity={result.severity} />
          </KpiChip>
          <KpiChip label="Tiempo">
            <span className="text-lg font-semibold tracking-tight text-white">
              {formatDurationMs(result._meta?.totalTimeMs)}
            </span>
          </KpiChip>
          <KpiChip label="Intentos">
            <span className="text-lg font-semibold tracking-tight text-white">
              {formatNumber(result._meta?.attempts ?? 1)}
            </span>
          </KpiChip>
          {patientSummary ? (
            <div className="ml-auto hidden text-right text-xs text-slate-400 xl:block">
              <p className="font-medium text-slate-200">
                {patientSummary.patientName}
              </p>
              <p>
                {patientSummary.clientName} · Dx{" "}
                {patientSummary.diagnosis}
              </p>
            </div>
          ) : null}
        </div>

        {/* Mensaje funcional — colapsable */}
        <div className="mt-3 rounded-xl border border-white/10 bg-black/10 px-4 py-2.5">
          <p
            className={cn(
              "text-sm leading-6 text-slate-300",
              !messageExpanded && isMessageLong && "line-clamp-2",
            )}
          >
            {message}
          </p>
          {isMessageLong ? (
            <button
              type="button"
              onClick={() => setMessageExpanded(!messageExpanded)}
              className="mt-1 inline-flex items-center gap-1 text-xs text-sky-400 transition hover:text-sky-300"
            >
              {messageExpanded ? "Menos" : "Ver más"}
              <ChevronDown
                className={cn(
                  "h-3 w-3 transition-transform",
                  messageExpanded && "rotate-180",
                )}
              />
            </button>
          ) : null}
        </div>
      </div>

      {/* ── L1/L2/L3: Tabs ── */}
      <Tabs defaultValue="findings" className="w-full">
        <TabsList className="w-full justify-start gap-1">
          <TabsTrigger value="findings" className="gap-2">
            <AlertTriangle className="h-3.5 w-3.5" />
            Hallazgos
            {findingsCount > 0 ? (
              <span className="ml-1 rounded-full bg-rose-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-rose-300 ring-1 ring-inset ring-rose-500/25">
                {findingsCount}
              </span>
            ) : null}
          </TabsTrigger>
          <TabsTrigger value="metrics" className="gap-2">
            <BarChart3 className="h-3.5 w-3.5" />
            Métricas
          </TabsTrigger>
          <TabsTrigger value="evidence" className="gap-2">
            <FileSearch className="h-3.5 w-3.5" />
            Evidencia
            {attachmentsCount > 0 ? (
              <span className="ml-1 rounded-full bg-sky-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-sky-300 ring-1 ring-inset ring-sky-500/25">
                {attachmentsCount}
              </span>
            ) : null}
          </TabsTrigger>
        </TabsList>

        {/* ── Tab: Hallazgos (L1 — default) ── */}
        <TabsContent value="findings">
          <div className="rounded-xl border border-white/10 bg-card px-5 py-5">
            <header className="mb-4 flex items-start justify-between border-b border-white/10 pb-4">
              <div className="space-y-1">
                <h2 className="[font-family:var(--font-heading)] text-lg font-semibold tracking-tight text-white">
                  Hallazgos críticos
                </h2>
                <p className="max-w-2xl text-sm text-slate-400">
                  Navega cada hallazgo hacia el soporte documental asociado
                  en la columna de evidencia.
                </p>
              </div>
            </header>
            <div className="max-h-[65vh] overflow-y-auto scrollbar-thin">
              <ResultItemsTable items={result.findings} />
            </div>
          </div>
        </TabsContent>

        {/* ── Tab: Métricas (L2) ── */}
        <TabsContent value="metrics">
          <div className="rounded-xl border border-white/10 bg-card px-5 py-5">
            <header className="mb-4 border-b border-white/10 pb-4">
              <h2 className="[font-family:var(--font-heading)] text-lg font-semibold tracking-tight text-white">
                Métricas y configuración
              </h2>
              <p className="mt-1 max-w-2xl text-sm text-slate-400">
                Tiempos de ejecución, cobertura del pipeline IA y policy
                aplicada.
              </p>
            </header>

            <AuditMetricsPanel metrics={result.metrics ?? undefined} />

            <div className="mt-5 grid gap-3 sm:grid-cols-2">
              <div className="surface-subtle flex flex-col rounded-lg p-5 justify-center">
                <div className="flex items-center gap-2 mb-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-[10px] bg-sky-500/10 text-sky-400 ring-1 ring-inset ring-sky-500/20">
                    <ShieldCheck className="h-4 w-4" />
                  </div>
                  <p className="text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">
                    Reglas de Negocio
                  </p>
                </div>
                <div>
                  <h3 className="text-base font-medium text-white mb-1">
                    Conjunto de auditoría
                  </h3>
                  <p className="text-xs text-slate-400 leading-relaxed">
                    Evaluado bajo el marco de reglas: 
                    <span className="text-sky-300 font-mono text-[11px] py-0.5 px-1.5 bg-sky-500/10 rounded ml-1.5 border border-sky-500/20">
                      {result.policy?.policyKey ?? "Estándar Global"}
                    </span>
                  </p>
                </div>
              </div>
              <div className="surface-subtle rounded-lg p-4">
                <div className="mb-3 flex items-center justify-between">
                  <p className="text-[11px] uppercase tracking-[0.2em] text-slate-400">
                    Fases medidas
                  </p>
                  <div className="flex items-center gap-2 text-[10px] text-slate-500">
                    <span className="flex items-center gap-1">
                      <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                      Rápido
                    </span>
                    <span className="flex items-center gap-1">
                      <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                      Normal
                    </span>
                    <span className="flex items-center gap-1">
                      <span className="h-1.5 w-1.5 rounded-full bg-rose-400" />
                      Lento
                    </span>
                  </div>
                </div>
                <div className="divide-y divide-white/[0.04]">
                  {Object.entries(result._meta?.phases ?? {}).map(
                    ([phase, value]) => {
                      const tier = getPhaseTier(value as number);
                      return (
                        <div
                          key={phase}
                          className="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                        >
                          <span className="text-[13px] text-slate-300">
                            {phase}
                          </span>
                          <div className="flex shrink-0 items-center gap-2">
                            <span
                              className={cn(
                                "rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset",
                                tier.className,
                              )}
                            >
                              {tier.label}
                            </span>
                            <span className="min-w-[4rem] text-right text-[13px] font-semibold tabular-nums text-white">
                              {formatDurationMs(value as number)}
                            </span>
                          </div>
                        </div>
                      );
                    },
                  )}
                </div>
              </div>
            </div>
          </div>
        </TabsContent>

        {/* ── Tab: Evidencia (L3) ── */}
        <TabsContent value="evidence">
          <div className="rounded-xl border border-white/10 bg-card px-5 py-5">
            <header className="mb-4 border-b border-white/10 pb-4">
              <h2 className="[font-family:var(--font-heading)] text-lg font-semibold tracking-tight text-white">
                Evidencia documental
              </h2>
              <p className="mt-1 max-w-2xl text-sm text-slate-400">
                Documentos adjuntos asociados a la dispensación auditada.
              </p>
            </header>

            {patientSummary ? (
              <div className="mb-4 surface-subtle rounded-lg p-4 text-sm text-slate-300 xl:hidden">
                <p className="font-medium text-white">
                  {patientSummary.patientName}
                </p>
                <p className="mt-1 text-slate-400">
                  {patientSummary.clientName} · Diagnostico{" "}
                  {patientSummary.diagnosis}
                </p>
              </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
              <AttachmentList
                items={attachmentsState.data ?? []}
                selectedId={
                  selectedAttachment?.id_documento
                    ? String(selectedAttachment.id_documento)
                    : undefined
                }
                onSelect={setSelectedAttachment}
              />
              <AttachmentViewerPanel
                disDetNro={disDetNro}
                attachment={selectedAttachment}
              />
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}

/* ── Helpers ───────────────────────────────────────── */

function KpiChip({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-center gap-2.5">
      <span className="text-[10px] uppercase tracking-[0.2em] text-slate-500">
        {label}
      </span>
      {children}
    </div>
  );
}

function getPatientSummary(dispensation?: DispensationDetail) {
  if (!dispensation) return null;

  return {
    patientName: String(
      dispensation.header.NombrePaciente ?? "Paciente no disponible",
    ),
    clientName: String(
      dispensation.header.Cliente ?? "Cliente no disponible",
    ),
    diagnosis: String(dispensation.header.CodigoDiagnostico ?? "N/D"),
  };
}

/** Clasifica un valor en ms en un tier de velocidad con estilos Tailwind */
function getPhaseTier(ms: number): { label: string; className: string } {
  if (ms < 1_000) {
    return {
      label: "Rápido",
      className:
        "bg-emerald-500/10 text-emerald-300 ring-emerald-500/20",
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
