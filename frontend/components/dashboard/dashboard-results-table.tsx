"use client";

import * as React from "react";
import { Eye } from "lucide-react";

import type { AuditResultRecord } from "@/lib/schemas/domain";
import { formatDateTime, formatDurationMs } from "@/lib/formatters";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { AuditResultDetailModal } from "@/components/results/audit-result-detail-modal";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

export function DashboardResultsTable({
  items,
}: {
  items: AuditResultRecord[];
}) {
  const [selectedRecord, setSelectedRecord] = React.useState<AuditResultRecord | null>(null);

  return (
    <>
      {selectedRecord && (
        <AuditResultDetailModal
          record={selectedRecord}
          open={true}
          onClose={() => setSelectedRecord(null)}
        />
      )}
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Factura</TableHead>
            <TableHead>Estado</TableHead>
            <TableHead>Tiempo</TableHead>
            <TableHead>Fecha</TableHead>
            <TableHead className="sr-only">Ver</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
            {items.map((item) => (
              <TableRow
                key={String(item.FacSec)}
                className="group cursor-pointer"
                onClick={() => setSelectedRecord(item)}
                role="button"
                tabIndex={0}
                onKeyDown={(e) => e.key === "Enter" && setSelectedRecord(item)}
                aria-label={`Abrir detalle: ${item.FacNro ?? item.FacSec}`}
              >
                <TableCell>
                  <p className="truncate font-medium text-white" title={String(item.FacNro ?? "N/D")}>{item.FacNro ?? "N/D"}</p>
                  <p className="mt-0.5 font-mono text-[11px] text-slate-600 truncate" title={String(item.FacSec)}>
                    {String(item.FacSec)}
                  </p>
                </TableCell>
                <TableCell title={String(item.EstadoDetallado ?? "N/D")}>
                  <AuditStatusBadge status={item.EstadoDetallado} />
                </TableCell>
                <TableCell className="tabular-nums text-slate-300" title={formatDurationMs(Number(item._meta?.totalTimeMs ?? 0))}>
                  {formatDurationMs(Number(item._meta?.totalTimeMs ?? 0))}
                </TableCell>
                <TableCell className="text-slate-400" title={formatDateTime(String(item._meta?.updatedAt ?? item.FechaActualizacion ?? ""))}>
                  {formatDateTime(
                    String(item._meta?.updatedAt ?? item.FechaActualizacion ?? ""),
                  )}
                </TableCell>
                <TableCell className="text-right">
                  <Eye
                    className="ml-auto h-4 w-4 text-slate-600 transition-colors group-hover:text-sky-400"
                    aria-hidden="true"
                  />
                </TableCell>
              </TableRow>
            ))}
        </TableBody>
      </Table>
    </>
  );
}
