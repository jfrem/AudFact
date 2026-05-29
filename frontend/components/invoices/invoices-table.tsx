"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
import { Button } from "@/components/ui/button";

type InvoiceItem = Record<string, unknown>;
type AuditTarget = { facSec: string };

export function InvoicesTable({
  invoices,
  canQuery,
}: {
  invoices: InvoiceItem[];
  canQuery: boolean;
}) {
  const router = useRouter();
  const [auditTarget, setAuditTarget] = React.useState<AuditTarget | null>(null);

  const rows = invoices.map((item, index) => {
    const dispensa = String(item.Dispensa ?? "N/D");
    const nitSec = String(item.NitSec ?? "N/D");
    const facSec = String(item.facsec ?? item.FacSec ?? "N/D");

    return { dispensa, nitSec, facSec, index };
  });

  return (
    <>
      <ConfirmDialog
        open={auditTarget !== null}
        variant="info"
        title="Ejecutar auditoría"
        description={`Se ejecutará la auditoría IA sobre la factura ${auditTarget?.facSec ?? ""}. El proceso puede tomar entre 10 y 60 segundos.`}
        confirmLabel="Auditar"
        onConfirm={() => {
          if (auditTarget) {
            router.push(`/audit/single?facSec=${encodeURIComponent(auditTarget.facSec)}`);
          }
          setAuditTarget(null);
        }}
        onCancel={() => setAuditTarget(null)}
      />

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
                        <Button asChild size="sm" variant="outline" className="h-8 border-slate-700 bg-slate-900/50 hover:bg-slate-800 hover:text-white">
                          <Link href={`/dispensation/${dispensa}`}>Detalle</Link>
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
          title={canQuery ? "Sin dispensaciones" : "Esperando filtros"}
          description={
            canQuery
              ? "No se encontraron resultados para los filtros indicados."
              : "Ingresa el NIT y una fecha para realizar la búsqueda."
          }
        />
      )}
    </>
  );
}
