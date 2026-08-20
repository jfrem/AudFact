import Link from "next/link";
import {
  AlertTriangle,
  RefreshCw,
} from "lucide-react";

import {
  getAsyncMetrics,
  getAuditMonthlyPerformance,
  getHealth,
} from "@/lib/api/audfact";
import { describeError } from "@/lib/api/errors";
import { PageHeader } from "@/components/layout/page-header";
import { MonthlyClientPerformance } from "@/components/dashboard/monthly-client-performance";
import { DashboardHealthStrip } from "@/components/dashboard/dashboard-health-strip";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

export default async function DashboardPage() {
  const [
    healthState,
    asyncMetricsState,
    monthlyPerformanceState,
  ] = await Promise.all([
    loadDashboardSource(() => getHealth()),
    loadDashboardSource(() => getAsyncMetrics()),
    loadDashboardSource(() => getAuditMonthlyPerformance({ year: 2026 })),
  ]);

  const health = healthState.data;
  const asyncMetrics = asyncMetricsState.data;
  const monthlyPerformance = monthlyPerformanceState.data;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Centro de control & operaciones"
        title="Dashboard"
        description="Triage operativo, rendimiento histórico por cliente y estado de salud del sistema."
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

      {/* Módulo Principal: Rendimiento y Producción Mensual por EPS */}
      {monthlyPerformanceState.error || !monthlyPerformance ? (
        <DashboardDataError
          title="No se pudo cargar el módulo de rendimiento mensual"
          detail={
            monthlyPerformanceState.error ??
            "La API no retornó datos de rendimiento mensual."
          }
        />
      ) : (
        <MonthlyClientPerformance initialData={monthlyPerformance} />
      )}
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
