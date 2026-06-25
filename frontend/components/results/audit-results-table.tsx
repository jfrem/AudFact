"use client";

import * as React from "react";
import { Eye, Activity } from "lucide-react";
import Link from "next/link";

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
import { Item, ItemContent, ItemTitle } from "@/components/ui/item";
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
        {items.map((item, index) => (
          <Item asChild key={`${item.DisId}-${item.FacNro ?? index}`} variant="subtle" size="lg">
            <article
              onClick={() => setSelectedRecord(item)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ") {
                  e.preventDefault();
                  setSelectedRecord(item);
                }
              }}
              aria-label={`Ver detalle de factura ${item.FacNro ?? item.DisId}`}
            >
              <ItemContent>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <ItemTitle>{item.FacNro ?? "N/D"}</ItemTitle>
                    <p className="mt-0.5 truncate font-mono text-[11px] leading-4 text-slate-600">
                      {String(item.DisId)}
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
                      {formatNumber(item.findingsCount ?? 0)}
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
                        String(item.FechaActualizacion ?? ""),
                      )}
                    </p>
                  </div>
                </div>
              </ItemContent>
            </article>
          </Item>
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
              <TableHead className="text-right w-[80px]">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.map((item, index) => {
              const duration = formatDurationMs(resolveAuditDurationMs(item));
              const flowId = item.FacNro || item.DisId || null;
              return (
                <TableRow
                  key={`${item.DisId}-${item.FacNro ?? index}`}
                  className="group cursor-pointer"
                  onClick={() => setSelectedRecord(item)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      setSelectedRecord(item);
                    }
                  }}
                  aria-label={`Abrir detalle: ${item.FacNro ?? item.DisId}`}
                >
                  <TableCell className="min-w-0">
                    <p className="truncate font-medium text-white" title={String(item.FacNro ?? "N/D")}>{item.FacNro ?? "N/D"}</p>
                    <p
                      className="mt-0.5 font-mono text-[11px] leading-4 text-slate-600 truncate"
                      title={String(item.DisId)}
                    >
                      {String(item.DisId)}
                    </p>
                  </TableCell>
                  <TableCell className="whitespace-nowrap" title={String(item.EstadoDetallado ?? "N/D")}>
                    <AuditStatusBadge status={item.EstadoDetallado} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap" title={String(item.Severidad ?? "N/D")}>
                    <SeverityBadge severity={item.Severidad} />
                  </TableCell>
                  <TableCell className="tabular-nums text-slate-300" title={formatNumber(item.findingsCount ?? 0)}>
                    {formatNumber(item.findingsCount ?? 0)}
                  </TableCell>
                  <TableCell className="tabular-nums text-slate-300" title={duration}>
                    {duration}
                  </TableCell>
                  <TableCell className="text-slate-400" title={formatDateTime(String(item.FechaActualizacion ?? ""))}>
                    {formatDateTime(
                      String(item.FechaActualizacion ?? ""),
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-3">
                      {flowId && (
                        <Link
                          href={`/audit/flow/${encodeURIComponent(flowId)}`}
                          target="_blank"
                          rel="noreferrer"
                          onClick={(e) => e.stopPropagation()}
                          className="flex items-center rounded-md p-1 text-slate-500 transition-colors hover:text-sky-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                          title="Ver trazabilidad histórica"
                          aria-label={`Ver trazabilidad de ${flowId}`}
                        >
                          <Activity className="h-4 w-4" aria-hidden="true" />
                        </Link>
                      )}
                      <Eye
                        className={cn(
                          "h-4 w-4 text-slate-600 transition-colors",
                          "group-hover:text-sky-400",
                        )}
                        aria-hidden="true"
                      />
                    </div>
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
  return Number(item.DuracionProcesamientoMs ?? 0);
}
