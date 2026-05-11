import { getClients, getAuditDocumentsHistory } from "@/lib/api/audfact";
import { formatDateTime, formatNumber } from "@/lib/formatters";
import { PageHeader } from "@/components/layout/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { Pagination } from "@/components/shared/pagination";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { DocumentsHistoryFilterForm } from "@/components/audit/documents-history-filter-form";

/** Translate raw EstadoSoporte to readable label + variant */
function docStateBadge(raw?: string | null): { label: string; variant: "success" | "danger" | "neutral" } {
  const normalized = String(raw ?? "").toUpperCase().trim();
  if (normalized === "C") return { label: "Conforme", variant: "success" };
  if (normalized === "R") return { label: "Rechazado", variant: "danger" };
  return { label: normalized || "N/D", variant: "neutral" };
}

export default async function AuditDocumentsHistoryPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const page = typeof params.page === "string" ? Math.max(1, parseInt(params.page, 10)) : 1;
  const facNitSec = typeof params.facNitSec === "string" ? params.facNitSec : "";
  const facNro = typeof params.facNro === "string" ? params.facNro : "";
  const pageSize = 20;

  const allClients = (await getClients().catch(() => [])) ?? [];

  const history = await getAuditDocumentsHistory({
    page,
    pageSize,
    facNitSec: facNitSec || undefined,
    facNro: facNro || undefined,
  }).catch(() => null);

  const totalPages = history?.totalPages ?? 1;

  const buildHref = (targetPage: number) => {
    const p = new URLSearchParams();
    p.set("page", String(targetPage));
    if (facNitSec) p.set("facNitSec", facNitSec);
    if (facNro) p.set("facNro", facNro);
    return `?${p.toString()}`;
  };

  return (
    <div className="space-y-5">
      <PageHeader eyebrow="Historial documental" title="Documentos auditados" />

      <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4 md:px-5">
        <DocumentsHistoryFilterForm
          allClients={allClients}
          initialFacNitSec={facNitSec}
          initialFacNro={facNro}
        />
      </div>

      {!history?.items?.length ? (
        <EmptyState
          title="Sin documentos"
          description="No se encontraron registros documentales para los filtros indicados."
        />
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Factura</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Auditor</TableHead>
                <TableHead>Fecha</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
                {history.items.map((item, index) => {
                  const state = docStateBadge(item.EstadoSoporte as string | undefined);
                  return (
                    <TableRow key={`${item.AdjuntoID ?? "adj"}-${index}`}>
                      <TableCell className="font-medium text-white">
                        {String(item.NroFactura ?? "N/D")}
                      </TableCell>
                      <TableCell>{String(item.NombreDocumento ?? "N/D")}</TableCell>
                      <TableCell>
                        <Badge variant={state.variant}>
                          {state.label}
                        </Badge>
                      </TableCell>
                      <TableCell>{String(item.UsuarioAuditor ?? "N/D")}</TableCell>
                      <TableCell className="text-slate-400">
                        {formatDateTime(String(item.FechaAuditoria ?? ""))}
                      </TableCell>
                    </TableRow>
                  );
                })}
            </TableBody>
          </Table>
          <div className="mt-4">
            <Pagination
              page={page}
              totalPages={totalPages}
              total={history.total ?? 0}
              buildHref={buildHref}
              label="documentos"
            />
          </div>
        </>
      )}
    </div>
  );
}
