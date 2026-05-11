"use client";

import * as React from "react";
import { Eye } from "lucide-react";

import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { AuditResultRecord } from "@/lib/schemas/domain";
import { formatDateTime, formatDurationMs, formatNumber } from "@/lib/formatters";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { SeverityBadge } from "@/components/shared/severity-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { Pagination } from "@/components/shared/pagination";
import { AuditResultDetailModal } from "@/components/results/audit-result-detail-modal";
import { cn } from "@/lib/utils";

export function AuditResultsTable({
  items,
  page,
  totalPages,
  total,
  basePath = "/audit/results",
  filters = {},
}: {
  items: AuditResultRecord[];
  page: number;
  totalPages: number;
  total: number;
  basePath?: string;
  filters?: Record<string, string | number | undefined>;
}) {
  const [selectedRecord, setSelectedRecord] = React.useState<AuditResultRecord | null>(null);

  const buildHref = React.useCallback(
    (targetPage: number) => {
      const p = new URLSearchParams();
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== "") {
          p.set(key, String(value));
        }
      });
      p.set("page", String(targetPage));
      return `${basePath}?${p.toString()}`;
    },
    [basePath, filters]
  );

  if (items.length === 0) {
    return (
      <EmptyState
        title="Sin resultados"
        description="No se encontraron registros de auditoría para los criterios indicados."
      />
    );
  }

  return (
    <>
      {selectedRecord && (
        <AuditResultDetailModal
          record={selectedRecord}
          open={true}
          onClose={() => setSelectedRecord(null)}
        />
      )}

      {/* Mobile cards */}
      <div className="space-y-2 md:hidden">
        {items.map((item) => (
          <article
            key={String(item.FacSec)}
            className="rounded-lg border border-white/8 bg-white/[0.02] p-4 transition-colors hover:bg-white/[0.04] active:bg-white/[0.05]"
            onClick={() => setSelectedRecord(item)}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => e.key === "Enter" && setSelectedRecord(item)}
            aria-label={`Ver detalle de factura ${item.FacNro ?? item.FacSec}`}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate font-medium text-white">{item.FacNro ?? "N/D"}</p>
                <p className="mt-0.5 font-mono text-[11px] leading-4 text-slate-600 truncate">
                  {String(item.FacSec)}
                </p>
              </div>
              <SeverityBadge severity={item.Severidad} />
            </div>
            <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Estado
                </p>
                <div className="mt-1">
                  <AuditStatusBadge status={item.EstadoDetallado} />
                </div>
              </div>
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Campos
                </p>
                <p className="mt-1 text-slate-200">
                  {formatNumber(item.HallazgosItems?.length ?? 0)}
                </p>
              </div>
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Tiempo IA
                </p>
                <p className="mt-1 text-slate-200">
                  {formatDurationMs(resolveAuditDurationMs(item))}
                </p>
              </div>
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Fecha
                </p>
                <p className="mt-1 text-slate-300">
                  {formatDateTime(
                    String(item._meta?.updatedAt ?? item.FechaActualizacion ?? ""),
                  )}
                </p>
              </div>
            </div>
          </article>
        ))}
      </div>

      {/* Desktop table */}
      <div className="hidden md:block">
        <Table className="w-full table-fixed">
          <colgroup>
            <col className="w-[28%]" />
            <col className="w-[16%]" />
            <col className="w-[12%]" />
            <col className="w-[9%]" />
            <col className="w-[12%]" />
            <col className="w-[17%]" />
            <col className="w-[6%]" />
          </colgroup>
          <TableHeader>
            <TableRow>
              <TableHead>Factura</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead>Severidad</TableHead>
              <TableHead>Campos</TableHead>
              <TableHead>Tiempo IA</TableHead>
              <TableHead>Fecha</TableHead>
              <TableHead className="text-right sr-only">Ver</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.map((item) => {
              const duration = formatDurationMs(resolveAuditDurationMs(item));
              return (
              <TableRow
                key={String(item.FacSec)}
                className="group cursor-pointer"
                onClick={() => setSelectedRecord(item)}
                role="button"
                tabIndex={0}
                onKeyDown={(e) => e.key === "Enter" && setSelectedRecord(item)}
                aria-label={`Abrir detalle: ${item.FacNro ?? item.FacSec}`}
              >
                <TableCell className="min-w-0">
                  <p className="truncate font-medium text-white" title={String(item.FacNro ?? "N/D")}>{item.FacNro ?? "N/D"}</p>
                  <p
                    className="mt-0.5 font-mono text-[11px] leading-4 text-slate-600 truncate"
                    title={String(item.FacSec)}
                  >
                    {String(item.FacSec)}
                  </p>
                </TableCell>
                <TableCell className="whitespace-nowrap" title={String(item.EstadoDetallado ?? "N/D")}>
                  <AuditStatusBadge status={item.EstadoDetallado} />
                </TableCell>
                <TableCell className="whitespace-nowrap" title={String(item.Severidad ?? "N/D")}>
                  <SeverityBadge severity={item.Severidad} />
                </TableCell>
                <TableCell className="tabular-nums text-slate-300" title={formatNumber(item.HallazgosItems?.length ?? 0)}>
                  {formatNumber(item.HallazgosItems?.length ?? 0)}
                </TableCell>
                <TableCell className="tabular-nums text-slate-300" title={duration}>
                  {duration}
                </TableCell>
                <TableCell className="text-slate-400" title={formatDateTime(String(item._meta?.updatedAt ?? item.FechaActualizacion ?? ""))}>
                  {formatDateTime(
                    String(item._meta?.updatedAt ?? item.FechaActualizacion ?? ""),
                  )}
                </TableCell>
                <TableCell className="text-right">
                  <Eye
                    className={cn(
                      "ml-auto h-4 w-4 text-slate-600 transition-colors",
                      "group-hover:text-sky-400"
                    )}
                    aria-hidden="true"
                  />
                </TableCell>
              </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      <div className="mt-4">
        <Pagination
          page={page}
          totalPages={totalPages}
          total={total}
          buildHref={buildHref}
          label="auditorías"
        />
      </div>
    </>
  );
}

function resolveAuditDurationMs(item: AuditResultRecord) {
  return Number(item._meta?.total_duration_ms ?? item._meta?.totalTimeMs ?? 0);
}
