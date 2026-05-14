import Link from "next/link";
import {
  AlertTriangle,
  ArrowRight,
  ListChecks,
  RefreshCw,
} from "lucide-react";

import {
  getAsyncMetrics,
  getAuditDocumentsHistory,
  getAuditResults,
  getHealth,
  getAuditStats,
} from "@/lib/api/audfact";
import { describeError } from "@/lib/api/errors";
import {
  formatDateTime,
  formatNumber,
} from "@/lib/formatters";
import { PageHeader } from "@/components/layout/page-header";
import { StatCard } from "@/components/dashboard/stat-card";
import { StateDistributionChart } from "@/components/dashboard/state-distribution-chart";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { AsyncQueueSummary } from "@/components/dashboard/async-queue-summary";
import { DashboardHealthStrip } from "@/components/dashboard/dashboard-health-strip";
import {
  getPriorityAuditItems,
  PriorityAuditTable,
} from "@/components/dashboard/priority-audit-table";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Item, ItemContent, ItemMedia, ItemTitle } from "@/components/ui/item";

export default async function DashboardPage() {
  const [healthState, asyncMetricsState, auditResultsState, documentsHistoryState, auditStatsState] = await Promise.all([
    loadDashboardSource(() => getHealth()),
    loadDashboardSource(() => getAsyncMetrics()),
    loadDashboardSource(() => getAuditResults({ page: 1, pageSize: 8 })),
    loadDashboardSource(() => getAuditDocumentsHistory({ page: 1, pageSize: 5 })),
    loadDashboardSource(() => getAuditStats()),
  ]);

  const health = healthState.data;
  const asyncMetrics = asyncMetricsState.data;
  const auditResults = auditResultsState.data;
  const documentsHistory = documentsHistoryState.data;
  const auditStats = auditStatsState.data;

  const items = auditResults?.items ?? [];
  const documentItems = documentsHistory?.items ?? [];
  const priorityItems = getPriorityAuditItems(items);
  const documentItemsWithIssues = documentItems.filter((item) => hasDocumentIssue(item));

  const manualReviewCount = auditStats?.byState["MANUAL_REVIEW"] ?? 0;
  const visibleDiscrepancies = auditStats?.byState["DISCREPANCIA"] ?? 0;
  const failedAuditCount =
    (auditStats?.byState["FAILED"] ?? 0) + (auditStats?.byState["ERROR"] ?? 0);
  const activeQueueCount =
    (asyncMetrics?.queueDepth ?? 0) +
    (asyncMetrics?.jobs.running ?? 0) +
    (asyncMetrics?.jobs.queued ?? 0);
  const chartData = buildAuditStateChartData(auditStats?.byState ?? {});

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Centro de control"
        title="Dashboard"
        description="Triage operativo para priorizar revisiones, fallas de auditoría y cola asíncrona."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button asChild>
              <Link href="/audit/single">Auditar dispensación</Link>
            </Button>
            <Button asChild variant="secondary">
              <Link href="/audit/batch">Encolar batch</Link>
            </Button>
          </div>
        }
      />

      <DashboardHealthStrip
        health={health}
        healthError={healthState.error}
        asyncMetrics={asyncMetrics}
        asyncError={asyncMetricsState.error}
      />

      <div className="stagger-children grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Revisión manual"
          value={auditStatsState.error ? "No disponible" : formatNumber(manualReviewCount)}
          hint={
            auditStatsState.error
              ? auditStatsState.error
              : "Casos que requieren decisión del auditor"
          }
          tone={auditStatsState.error ? "amber" : "violet"}
        />
        <StatCard
          label="Discrepancias"
          value={auditStatsState.error ? "No disponible" : formatNumber(visibleDiscrepancies)}
          hint={
            auditStatsState.error
              ? auditStatsState.error
              : `De ${formatNumber(auditStats?.total ?? 0)} auditorías históricas`
          }
          tone="amber"
        />
        <StatCard
          label="Fallas"
          value={auditStatsState.error ? "No disponible" : formatNumber(failedAuditCount)}
          hint={
            auditStatsState.error
              ? auditStatsState.error
              : "Auditorías con estado técnico fallido"
          }
          tone="amber"
        />
        <StatCard
          label="Cola async"
          value={asyncMetricsState.error ? "No disponible" : formatNumber(activeQueueCount)}
          hint={
            asyncMetricsState.error
              ? asyncMetricsState.error
              : `${formatNumber(asyncMetrics?.jobs.running ?? 0)} running · ${formatNumber(asyncMetrics?.deadLetterDepth ?? 0)} DLQ`
          }
          tone={asyncMetricsState.error ? "amber" : activeQueueCount > 0 ? "blue" : "emerald"}
        />
      </div>

      <SectionCard
        title="Bandeja prioritaria"
        description="Casos recientes que requieren revisión humana, diagnóstico de falla o seguimiento de discrepancias."
        actions={
          <Link
            href="/audit/results"
            className="inline-flex min-h-10 items-center gap-1.5 text-sm text-sky-400 transition hover:text-sky-300"
          >
            Ver historial <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        }
      >
        {auditResultsState.error ? (
          <DashboardDataError
            title="No se pudo cargar la bandeja prioritaria"
            detail={auditResultsState.error}
          />
        ) : (
          <PriorityAuditTable items={priorityItems} />
        )}
      </SectionCard>

      <div className="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
        <SectionCard
          title="Histórico de estados"
          description="Distribución agregada de los estados persistidos por el backend."
        >
          {auditStatsState.error ? (
            <DashboardDataError
              title="No se pudo cargar la distribución"
              detail={auditStatsState.error}
            />
          ) : (
            <StateDistributionChart data={chartData} />
          )}
        </SectionCard>

        <SectionCard
          title="Cola asíncrona"
          description="Lectura operativa de jobs, DLQ y eventos pendientes."
        >
          <AsyncQueueSummary
            metrics={asyncMetrics}
            error={asyncMetricsState.error}
          />
        </SectionCard>
      </div>

      <SectionCard
        title="Documentos con observación reciente"
        description="Soportes recientes que no están conformes o tienen observación de auditoría."
        actions={
          <Link
            href="/audit/documents-history"
            className="inline-flex min-h-10 items-center gap-1.5 text-sm text-sky-400 transition hover:text-sky-300"
          >
            Ver historial <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        }
      >
        {documentsHistoryState.error ? (
          <DashboardDataError
            title="No se pudo cargar la actividad documental"
            detail={documentsHistoryState.error}
          />
        ) : documentItemsWithIssues.length === 0 ? (
          <EmptyState
            icon={<ListChecks className="h-6 w-6" />}
            title="Sin documentos observados recientes"
            description="El historial reciente no reporta soportes con observación o estado no conforme."
          />
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {documentItemsWithIssues.map((item, index) => {
              const supportStatus = String(item.EstadoSoporte ?? "").toUpperCase();
              const isRejected = supportStatus === "R";
              const statusLabel =
                supportStatus === "R"
                  ? "Rechazado"
                  : supportStatus === "C"
                    ? "Conforme"
                    : String(item.EstadoSoporte ?? "—");

              return (
                <Item
                  key={`${String(item.AdjuntoID ?? "adj")}-${index}`}
                  variant="subtle"
                  size="lg"
                >
                  <ItemMedia
                    className={
                      isRejected
                        ? "border-rose-500/20 bg-rose-500/10 text-rose-300"
                        : "border-amber-500/20 bg-amber-500/10 text-amber-300"
                    }
                  >
                    <AlertTriangle className="h-4 w-4" />
                  </ItemMedia>
                  <ItemContent>
                    <div className="flex items-start justify-between gap-3">
                      <ItemTitle title={String(item.NombreDocumento ?? "Documento")}>
                        {String(item.NombreDocumento ?? "Documento")}
                      </ItemTitle>
                      <span
                        className={`shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset ${
                          isRejected
                            ? "bg-rose-500/14 text-rose-300 ring-rose-500/25"
                            : "bg-emerald-500/14 text-emerald-300 ring-emerald-500/25"
                        }`}
                      >
                        {statusLabel}
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
                  </ItemContent>
                </Item>
              );
            })}
          </div>
        )}
      </SectionCard>
    </div>
  );
}

type DashboardLoadState<T> = {
  data: NonNullable<T> | null;
  error: string | null;
};

async function loadDashboardSource<T>(
  loader: () => Promise<T>,
): Promise<DashboardLoadState<T>> {
  try {
    const data = await loader();
    if (data == null) {
      throw new Error("La API no retornó datos para esta sección.");
    }

    return { data: data as NonNullable<T>, error: null };
  } catch (error) {
    return { data: null, error: describeError(error) };
  }
}

function DashboardDataError({
  title,
  detail,
}: {
  title: string;
  detail: string;
}) {
  return (
    <Alert variant="destructive" className="block px-4 py-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-rose-500/20 bg-rose-500/10 text-rose-300">
            <AlertTriangle className="h-4 w-4" />
          </span>
          <div className="min-w-0">
            <AlertTitle className="text-sm">{title}</AlertTitle>
            <AlertDescription className="col-auto mt-1 text-sm leading-6 text-rose-100/80">
              {detail}
            </AlertDescription>
          </div>
        </div>
        <Button asChild variant="secondary" className="shrink-0 gap-2">
          <Link href="/dashboard">
            <RefreshCw className="h-4 w-4" />
            Reintentar
          </Link>
        </Button>
      </div>
    </Alert>
  );
}

function hasDocumentIssue(item: Record<string, unknown>) {
  const status = String(item.EstadoSoporte ?? "").trim().toUpperCase();
  const observation = String(item.ObservacionRechazo ?? "").trim();

  return Boolean(observation) || (status !== "" && status !== "C");
}

const auditStateChartConfig: Record<string, { label: string; color: string; order: number }> = {
  CONCILIADO: { label: "Conforme", color: "#16c784", order: 10 },
  MATCH: { label: "Conforme", color: "#16c784", order: 10 },
  CONCILIADO_PARCIAL: { label: "Parcial", color: "#ffb84d", order: 20 },
  DISCREPANCIA: { label: "Discrepancia", color: "#ffb84d", order: 30 },
  MANUAL_REVIEW: { label: "Revisión manual", color: "#a78bfa", order: 40 },
  FAILED: { label: "Fallido", color: "#ff6b7a", order: 50 },
  ERROR: { label: "Error", color: "#ff6b7a", order: 50 },
  PENDIENTE: { label: "Pendiente", color: "#facc15", order: 60 },
  EN_PROCESO: { label: "En proceso", color: "#38bdf8", order: 70 },
  UNKNOWN: { label: "Sin estado", color: "#94a3b8", order: 90 },
};

function buildAuditStateChartData(byState: Record<string, number>) {
  const grouped = new Map<string, { name: string; value: number; color: string; order: number }>();

  Object.entries(byState).forEach(([state, count]) => {
    if (count <= 0) return;

    const normalized = normalizeAuditState(state);
    const config = auditStateChartConfig[normalized] ?? {
      label: formatUnknownAuditStateLabel(normalized),
      color: "#94a3b8",
      order: 80,
    };
    const existing = grouped.get(config.label);

    if (existing) {
      existing.value += count;
      return;
    }

    grouped.set(config.label, {
      name: config.label,
      value: count,
      color: config.color,
      order: config.order,
    });
  });

  return [...grouped.values()]
    .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name))
    .map(({ order: _order, ...slice }) => slice);
}

function normalizeAuditState(state: string) {
  return state.trim().toUpperCase() || "UNKNOWN";
}

function formatUnknownAuditStateLabel(state: string) {
  return state
    .toLowerCase()
    .split("_")
    .filter(Boolean)
    .map((word) => `${word.charAt(0).toUpperCase()}${word.slice(1)}`)
    .join(" ") || "Sin estado";
}
