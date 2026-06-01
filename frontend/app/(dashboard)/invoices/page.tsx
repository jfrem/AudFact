import {
  getClients,
  getInvoices,
  INVOICE_SEARCH_DEFAULT_PAGE_SIZE,
} from "@/lib/api/audfact";
import { describeError } from "@/lib/api/errors";
import { PageHeader } from "@/components/layout/page-header";
import { InvoicesTable } from "@/components/invoices/invoices-table";
import { InvoicesFilterForm } from "@/components/invoices/invoices-filter-form";
import type { ClientRecord, InvoiceRecord } from "@/lib/schemas/domain";

export default async function InvoicesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const facNitSec = typeof params.facNitSec === "string" ? params.facNitSec : "";
  const dateFrom = typeof params.dateFrom === "string" ? params.dateFrom : "";
  const dateTo = typeof params.dateTo === "string" ? params.dateTo : "";
  const page = Math.max(1, Number(typeof params.page === "string" ? params.page : 1));
  const pageSize = Math.min(
    100,
    Math.max(
      1,
      Number(
        typeof params.pageSize === "string"
          ? params.pageSize
          : INVOICE_SEARCH_DEFAULT_PAGE_SIZE,
      ),
    ),
  );

  let allClients: ClientRecord[] = [];
  let clientsError: string | null = null;
  try {
    allClients = (await getClients()) ?? [];
  } catch (error) {
    clientsError = describeError(error);
  }

  const canQuery = facNitSec !== "" && dateFrom !== "";
  let invoicesError: string | null = null;
  const resolvedPage = Number.isFinite(page) ? page : 1;
  const resolvedPageSize = Number.isFinite(pageSize)
    ? pageSize
    : INVOICE_SEARCH_DEFAULT_PAGE_SIZE;

  let invoices: InvoiceRecord[] = [];
  let total = 0;
  let totalPages = 0;
  let currentPage = resolvedPage;
  if (canQuery) {
    const invoiceResult = await getInvoices({
      facNitSec,
      dateFrom,
      dateTo: dateTo || undefined,
      page: resolvedPage,
      pageSize: resolvedPageSize,
    }).catch((error) => {
      invoicesError = describeError(error);
      return null;
    });
    invoices = invoiceResult?.items ?? [];
    total = invoiceResult?.total ?? 0;
    totalPages = invoiceResult?.totalPages ?? 0;
    currentPage = invoiceResult?.page ?? resolvedPage;
  }

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
          initialPageSize={resolvedPageSize}
          clientsError={clientsError}
        />
      </div>

      <InvoicesTable
        invoices={invoices}
        canQuery={canQuery}
        currentPage={currentPage}
        filters={{ facNitSec, dateFrom, dateTo, pageSize: resolvedPageSize }}
        queryError={invoicesError}
        total={total}
        totalPages={totalPages}
      />
    </div>
  );
}
