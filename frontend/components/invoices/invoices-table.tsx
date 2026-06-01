"use client";

import * as React from "react";
import { AlertTriangle } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Pagination } from "@/components/shared/pagination";
import { Button } from "@/components/ui/button";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

type InvoiceItem = Record<string, unknown>;
type AuditTarget = { facSec: string };
type InvoiceTableFilters = {
  facNitSec: string;
  dateFrom: string;
  dateTo: string;
  pageSize: number;
};

export function InvoicesTable({
  invoices,
  canQuery,
  currentPage,
  filters,
  queryError,
  total,
  totalPages,
}: {
  invoices: InvoiceItem[];
  canQuery: boolean;
  currentPage: number;
  filters: InvoiceTableFilters;
  queryError?: string | null;
  total: number;
  totalPages: number;
}) {
  const navigation = usePendingNavigation();
  const [auditTarget, setAuditTarget] = React.useState<AuditTarget | null>(null);
  const [pendingRoute, setPendingRoute] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!navigation.isPending) {
      setPendingRoute(null);
    }
  }, [navigation.isPending]);

  const rows = invoices.map((item, index) => {
    const dispensa = String(item.Dispensa ?? "N/D");
    const nitSec = String(item.NitSec ?? "N/D");
    const facSec = String(item.facsec ?? item.FacSec ?? "N/D");

    return { dispensa, nitSec, facSec, index };
  });

  const buildHref = React.useCallback(
    (targetPage: number) => {
      const params = new URLSearchParams();
      params.set("page", String(targetPage));
      params.set("pageSize", String(filters.pageSize));
      params.set("facNitSec", filters.facNitSec);
      params.set("dateFrom", filters.dateFrom);
      if (filters.dateTo) {
        params.set("dateTo", filters.dateTo);
      }

      return `/invoices?${params.toString()}`;
    },
    [filters],
  );

  const showPagination = canQuery && total > 0 && totalPages > 0;
  const emptyTitle =
    queryError
      ? "No se pudo consultar facturas"
      : canQuery && total > 0
        ? "Página sin registros"
        : canQuery
          ? "Sin dispensaciones"
          : "Esperando filtros";
  const emptyDescription =
    queryError
      ? queryError
      : canQuery && total > 0
        ? "La página solicitada no contiene registros. Usa la paginación para volver a una página disponible."
        : canQuery
          ? "No se encontraron resultados para los filtros indicados."
          : "Selecciona un cliente y una fecha inicial para realizar la búsqueda.";

  return (
    <>
      <ConfirmDialog
        open={auditTarget !== null}
        variant="info"
        title="Ejecutar auditoría"
        description={`Se ejecutará la auditoría IA sobre la factura ${auditTarget?.facSec ?? ""}. El proceso puede tomar entre 10 y 60 segundos.`}
        confirmLabel="Auditar"
        loading={navigation.isPending && pendingRoute === `audit:${auditTarget?.facSec ?? ""}`}
        onConfirm={() => {
          if (auditTarget) {
            setPendingRoute(`audit:${auditTarget.facSec}`);
            navigation.push(`/audit/single?facSec=${encodeURIComponent(auditTarget.facSec)}`);
          }
          setAuditTarget(null);
        }}
        onCancel={() => setAuditTarget(null)}
      />

      {navigation.isPending ? (
        <BackendRequestSkeleton
          className="mb-4"
          description="La vista solicitada se está cargando desde el backend."
          title="Abriendo solicitud"
          variant="detail"
        />
      ) : null}

      {invoices.length > 0 ? (
        <Table>
          <TableHeader>
            <TableRow className="border-slate-800 bg-slate-900/50">
              <TableHead className="text-slate-400">Dispensación</TableHead>
              <TableHead className="text-slate-400">NIT Cliente</TableHead>
              <TableHead className="text-slate-400">ID Factura</TableHead>
              <TableHead className="text-right text-slate-400">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map(({ dispensa, nitSec, facSec, index }) => {
              return (
                <TableRow
                  key={`${facSec}-${index}`}
                  className="group border-slate-800/50 transition-colors hover:bg-slate-800/50"
                >
                  <TableCell className="font-mono text-sm text-white" title={dispensa}>
                    {dispensa}
                  </TableCell>
                  <TableCell className="font-mono text-sm text-slate-300" title={nitSec}>
                    {nitSec}
                  </TableCell>
                  <TableCell className="font-mono text-xs text-slate-400" title={`#${facSec}`}>
                    #{facSec}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2 opacity-60 transition-opacity hover:opacity-100 focus-within:opacity-100">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8 border-slate-700 bg-slate-900/50 hover:bg-slate-800 hover:text-white"
                        loading={navigation.isPending && pendingRoute === `detail:${dispensa}`}
                        loadingLabel="Abriendo"
                        onClick={() => {
                          setPendingRoute(`detail:${dispensa}`);
                          navigation.push(`/dispensation/${dispensa}`);
                        }}
                      >
                        Detalle
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        onClick={() => setAuditTarget({ facSec })}
                        className="h-8 bg-blue-600/10 text-blue-400 hover:bg-blue-600/20 hover:text-blue-300 border border-blue-500/20"
                      >
                        Auditar
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      ) : (
        <EmptyState
          icon={queryError ? <AlertTriangle className="h-6 w-6" /> : undefined}
          title={emptyTitle}
          description={emptyDescription}
        />
      )}

      {showPagination ? (
        <div className="mt-4">
          <Pagination
            page={currentPage}
            totalPages={totalPages}
            total={total}
            buildHref={buildHref}
            label="facturas"
          />
        </div>
      ) : null}
    </>
  );
}
