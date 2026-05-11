import { getClients, getInvoices } from "@/lib/api/audfact";
import { PageHeader } from "@/components/layout/page-header";
import { InvoicesTable } from "@/components/invoices/invoices-table";
import { InvoicesFilterForm } from "@/components/invoices/invoices-filter-form";

export default async function InvoicesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const facNitSec = typeof params.facNitSec === "string" ? params.facNitSec : "";
  const dateFrom = typeof params.dateFrom === "string" ? params.dateFrom : "";
  const dateTo = typeof params.dateTo === "string" ? params.dateTo : "";
  const limit = typeof params.limit === "string" ? Number(params.limit) : 20;

  const allClients = (await getClients().catch(() => [])) ?? [];
  const canQuery = facNitSec !== "" && dateFrom !== "";
  const invoices = canQuery
    ? ((await getInvoices({
        facNitSec,
        dateFrom,
        dateTo: dateTo || undefined,
        limit: Number.isFinite(limit) ? limit : 20,
      }).catch(() => [])) ?? [])
    : [];

  return (
    <div className="space-y-5">
      <PageHeader
        eyebrow="Consulta"
        title="Facturas / Dispensaciones"
        description="Busca dispensaciones disponibles para revisar detalle técnico o disparar una auditoría 1:1."
      />

      <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4 md:px-5">
        <InvoicesFilterForm
          allClients={allClients}
          initialFacNitSec={facNitSec}
          initialDateFrom={dateFrom}
          initialDateTo={dateTo}
          initialLimit={Number.isFinite(limit) ? limit : 20}
        />
      </div>

      <InvoicesTable invoices={invoices} canQuery={canQuery} />
    </div>
  );
}
