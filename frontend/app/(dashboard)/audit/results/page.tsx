import { Suspense } from "react";
import { getClients, getAuditResults } from "@/lib/api/audfact";
import { PageHeader } from "@/components/layout/page-header";
import { AuditResultsTable } from "@/components/results/audit-results-table";
import { AuditResultsFilterForm } from "@/components/results/audit-results-filter-form";
import { TableSkeleton } from "@/components/shared/loading-skeleton";

export default async function AuditResultsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const filters = await searchParams;
  const page = Math.max(1, Number(filters.page ?? 1));
  const pageSize = Math.min(100, Math.max(1, Number(filters.pageSize ?? 20)));
  const facNitSec = typeof filters.facNitSec === "string" ? filters.facNitSec : undefined;
  const facNro = typeof filters.facNro === "string" ? filters.facNro : undefined;
  const dateFrom = typeof filters.dateFrom === "string" ? filters.dateFrom : undefined;
  const dateTo = typeof filters.dateTo === "string" ? filters.dateTo : undefined;

  const allClients = (await getClients().catch(() => [])) ?? [];

  const searchParamsKey = JSON.stringify({ facNitSec, facNro, dateFrom, dateTo, page, pageSize });

  return (
    <div className="space-y-5">
      <PageHeader
        eyebrow="Historial"
        title="Resultados de auditoría"
        description="Consulta corridas persistidas por cliente, factura y ventana de tiempo."
      />

      {/* Filters: inline, no SectionCard wrapper — reduces vertical noise */}
      <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4 md:px-5">
        <AuditResultsFilterForm
          allClients={allClients}
          initialFacNitSec={facNitSec}
          initialFacNro={facNro}
          initialDateFrom={dateFrom}
          initialDateTo={dateTo}
          initialPageSize={pageSize}
        />
      </div>

      <Suspense
        key={searchParamsKey}
        fallback={
          <div className="pt-2">
            <TableSkeleton rows={10} />
          </div>
        }
      >
        <AuditResultsTableFetcher
          facNitSec={facNitSec}
          facNro={facNro}
          dateFrom={dateFrom}
          dateTo={dateTo}
          page={page}
          pageSize={pageSize}
        />
      </Suspense>
    </div>
  );
}

async function AuditResultsTableFetcher({
  facNitSec,
  facNro,
  dateFrom,
  dateTo,
  page,
  pageSize,
}: {
  facNitSec?: string;
  facNro?: string;
  dateFrom?: string;
  dateTo?: string;
  page: number;
  pageSize: number;
}) {
  const results = await getAuditResults({
    page,
    pageSize,
    facNitSec,
    facNro,
    dateFrom,
    dateTo,
  }).catch(() => null);

  return (
    <AuditResultsTable
      items={results?.items ?? []}
      page={page}
      totalPages={results?.totalPages ?? 1}
      total={results?.total ?? 0}
      filters={{ pageSize, facNitSec, facNro, dateFrom, dateTo }}
    />
  );
}
