import Link from "next/link";
import {
  ArrowRight,
  FileSearch,
  History,
  ListChecks,
  Workflow,
} from "lucide-react";

import {
  getAuditDocumentsHistory,
  getAuditResults,
  getHealth,
  getPublicConfig,
  getAuditStats,
} from "@/lib/api/audfact";
import {
  formatDateTime,
  formatDurationMs,
  formatNumber,
} from "@/lib/formatters";
import { PageHeader } from "@/components/layout/page-header";
import { StatCard } from "@/components/dashboard/stat-card";
import { StateDistributionChart } from "@/components/dashboard/state-distribution-chart";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { DashboardResultsTable } from "@/components/dashboard/dashboard-results-table";
import { Button } from "@/components/ui/button";

export default async function DashboardPage() {
  const [health, config, auditResults, documentsHistory, auditStats] = await Promise.all([
    getHealth().catch(() => null),
    getPublicConfig().catch(() => null),
    getAuditResults({ page: 1, pageSize: 8 }).catch(() => null),
    getAuditDocumentsHistory({ page: 1, pageSize: 5 }).catch(() => null),
    getAuditStats().catch(() => null),
  ]);

  const items = auditResults?.items ?? [];
  const documentItems = documentsHistory?.items ?? [];
  
  const stats = auditStats ?? { total: 0, byState: {}, documentsAudited: 0, lastAuditAt: null };
  const visibleDiscrepancies = stats.byState["DISCREPANCIA"] ?? 0;
  
  const dbLatency = health?.services?.database?.latency_ms ?? 0;
  const chartColors = { success: "#16c784", warning: "#ffb84d", danger: "#ff6b7a" } as const;
  const chartData = [
    {
      name: "Conforme",
      value: (stats.byState["CONCILIADO"] ?? 0) + (stats.byState["MATCH"] ?? 0),
      color: chartColors.success,
    },
    {
      name: "Discrepancia",
      value: stats.byState["DISCREPANCIA"] ?? 0,
      color: chartColors.warning,
    },
    {
      name: "Fallido",
      value: stats.byState["FAILED"] ?? 0,
      color: chartColors.danger,
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Centro de control"
        title="Dashboard"
        description="Visión rápida del estado operativo, actividad reciente y accesos a los flujos principales."
        actions={
          <Button asChild variant="secondary">
            <Link href="/audit/single">Nueva auditoría</Link>
          </Button>
        }
      />

      {/* ── KPIs ── */}
      <div className="stagger-children grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Estado backend"
          value={health?.status === "healthy" ? "Operativo" : "Degradado"}
          hint={`DB ${dbLatency}ms · Disco ${health?.services?.disk?.status ?? "?"}`}
          tone="emerald"
        />
        <StatCard
          label="Auditorías totales"
          value={formatNumber(stats.total)}
          hint={`Batch max: ${formatNumber(config?.auditBatchMaxLimit ?? 0)}`}
        />
        <StatCard
          label="Documentos auditados"
          value={formatNumber(stats.documentsAudited)}
          hint={`Timeout: ${formatDurationMs(config?.auditBatchTimeoutMs ?? 0)}`}
          tone="amber"
        />
        <StatCard
          label="Discrepancias"
          value={formatNumber(visibleDiscrepancies)}
          hint={`De ${formatNumber(stats.total)} resultados totales`}
          tone="violet"
        />
      </div>

      {/* ── Chart + Quick Actions ── */}
      <div className="grid gap-4 xl:grid-cols-2">
        <SectionCard title="Distribución de estados">
          <StateDistributionChart data={chartData} />
        </SectionCard>

        <SectionCard title="Acciones rápidas">
          <div className="grid gap-2 sm:grid-cols-2">
            <QuickAction
              href="/audit/single"
              icon={<FileSearch className="h-5 w-5" />}
              title="Auditoría 1:1"
              description="Ejecutar auditoría individual."
              highlighted
            />
            <QuickAction
              href="/audit/results?page=1&pageSize=20"
              icon={<ListChecks className="h-5 w-5" />}
              title="Resultados"
              description="Historial persistido."
            />
            <QuickAction
              href="/audit/documents-history"
              icon={<History className="h-5 w-5" />}
              title="Documentos"
              description="Trazabilidad documental."
            />
            <QuickAction
              href="/audit/jobs"
              icon={<Workflow className="h-5 w-5" />}
              title="Jobs async"
              description="Cola y progreso de batch."
            />
          </div>
        </SectionCard>
      </div>

      {/* ── Últimos resultados ── */}
      <SectionCard
        title="Últimos resultados"
        actions={
          <Link
            href="/audit/results"
            className="inline-flex items-center gap-1.5 text-sm text-sky-400 transition hover:text-sky-300"
          >
            Ver todos <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        }
      >
        {items.length === 0 ? (
          <EmptyState
            title="Sin resultados"
            description="No se han encontrado auditorías persistidas."
          />
        ) : (
          <DashboardResultsTable items={items} />
        )}
      </SectionCard>

      {/* ── Actividad documental ── */}
      <SectionCard
        title="Actividad documental reciente"
        actions={
          <Link
            href="/audit/documents-history"
            className="inline-flex items-center gap-1.5 text-sm text-sky-400 transition hover:text-sky-300"
          >
            Ver historial <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        }
      >
        {documentItems.length === 0 ? (
          <EmptyState
            title="Sin actividad"
            description="No hay documentos auditados recientemente."
          />
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {documentItems.map((item, index) => (
              <div
                key={`${String(item.AdjuntoID ?? "adj")}-${index}`}
                className="surface-subtle rounded-lg p-4 transition hover:border-white/12"
              >
                <div className="flex items-start justify-between gap-3">
                  <p className="text-sm font-medium text-white">
                    {String(item.NombreDocumento ?? "Documento")}
                  </p>
                  <span
                    className={`shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset ${
                      String(item.EstadoSoporte ?? "").toUpperCase() === "R"
                        ? "bg-rose-500/14 text-rose-300 ring-rose-500/25"
                        : "bg-emerald-500/14 text-emerald-300 ring-emerald-500/25"
                    }`}
                  >
                    {String(item.EstadoSoporte ?? "").toUpperCase() === "R" ? "Rechazado" : String(item.EstadoSoporte ?? "").toUpperCase() === "C" ? "Conforme" : String(item.EstadoSoporte ?? "—")}
                  </span>
                </div>
                <p className="mt-2 text-xs text-slate-400">
                  Factura {String(item.NroFactura ?? "N/D")}
                </p>
                <p className="mt-1 line-clamp-2 text-sm text-slate-400">
                  {String(
                    item.ObservacionRechazo ?? "Sin observación.",
                  )}
                </p>
                <p className="mt-2 text-[11px] text-slate-500">
                  {formatDateTime(String(item.FechaAuditoria ?? ""))}
                </p>
              </div>
            ))}
          </div>
        )}
      </SectionCard>
    </div>
  );
}

function QuickAction({
  href,
  icon,
  title,
  description,
  highlighted = false,
}: {
  href: string;
  icon: React.ReactNode;
  title: string;
  description: string;
  highlighted?: boolean;
}) {
  return (
    <Link
      href={href}
      className={`group flex items-start gap-3 rounded-lg border p-3.5 transition ${
        highlighted
          ? "border-sky-500/24 bg-white/[0.05] hover:border-sky-400/30"
          : "border-white/10 bg-white/[0.02] hover:border-white/14 hover:bg-white/[0.04]"
      }`}
    >
      <div
        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
          highlighted
            ? "border border-sky-500/20 bg-sky-500/12 text-sky-300"
            : "bg-white/[0.06] text-slate-400"
        }`}
      >
        {icon}
      </div>
      <div>
        <p className="text-sm font-medium text-white">{title}</p>
        <p className="mt-0.5 text-xs text-slate-400">{description}</p>
      </div>
    </Link>
  );
}
