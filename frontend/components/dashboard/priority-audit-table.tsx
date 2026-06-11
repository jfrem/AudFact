import Link from "next/link";
import { ArrowUpRight, FileSearch } from "lucide-react";

import type { AuditResultRecord } from "@/lib/schemas/domain";
import { formatDateTime, formatDurationMs } from "@/lib/formatters";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const actionableStates = new Set([
  "DISCREPANCIA",
  "ERROR",
  "FAILED",
  "MANUAL_REVIEW",
]);

export function PriorityAuditTable({
  items,
}: {
  items: AuditResultRecord[];
}) {
  if (items.length === 0) {
    return (
      <EmptyState
        icon={<FileSearch className="h-6 w-6" />}
        title="Sin casos prioritarios recientes"
        description="Los resultados recientes no contienen revisión manual, fallas ni discrepancias."
        action={
          <Button asChild variant="secondary">
            <Link href="/audit/results">Ver historial completo</Link>
          </Button>
        }
      />
    );
  }

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Factura</TableHead>
            <TableHead>Estado</TableHead>
            <TableHead>Prioridad</TableHead>
            <TableHead>Hallazgo principal</TableHead>
            <TableHead>Duración</TableHead>
            <TableHead>Fecha</TableHead>
            <TableHead className="text-right">Acción</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.map((item) => (
            <TableRow key={String(item.DisId)}>
              <TableCell>
                <p
                  className="max-w-[12rem] truncate font-medium text-white"
                  title={String(item.FacNro ?? "N/D")}
                >
                  {item.FacNro ?? "N/D"}
                </p>
                <p
                  className="mt-0.5 max-w-[12rem] truncate font-mono text-[11px] text-slate-600"
                  title={String(item.DisId)}
                >
                  {String(item.DisId)}
                </p>
              </TableCell>
              <TableCell title={String(item.EstadoDetallado ?? "N/D")}>
                <AuditStatusBadge status={item.EstadoDetallado} />
              </TableCell>
              <TableCell>
                <PriorityPill item={item} />
              </TableCell>
              <TableCell>
                <p
                  className="max-w-[28rem] truncate text-sm text-slate-300"
                  title={getPrimaryIssue(item)}
                >
                  {getPrimaryIssue(item)}
                </p>
                <p className="mt-0.5 text-[11px] uppercase tracking-[0.16em] text-slate-600">
                  {String(item.DocumentoFallido ?? item.Severidad ?? "Sin documento señalado")}
                </p>
              </TableCell>
              <TableCell className="tabular-nums text-slate-300">
                {formatDurationMs(Number(item.DuracionProcesamientoMs ?? 0))}
              </TableCell>
              <TableCell
                className="whitespace-nowrap text-slate-400"
                title={formatDateTime(String(item.FechaActualizacion ?? ""))}
              >
                {formatDateTime(String(item.FechaActualizacion ?? ""))}
              </TableCell>
              <TableCell className="text-right">
                <Button asChild size="sm" variant="secondary">
                  <Link href={buildResultHref(item)}>
                    Revisar
                    <ArrowUpRight className="h-3.5 w-3.5" />
                  </Link>
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

export function getPriorityAuditItems(items: AuditResultRecord[]) {
  return [...items]
    .filter((item) => isPriorityAudit(item))
    .sort((a, b) => getPriorityWeight(b) - getPriorityWeight(a));
}

function isPriorityAudit(item: AuditResultRecord) {
  const status = normalizeState(item.EstadoDetallado);
  const severity = normalizeState(item.Severidad);

  return (
    actionableStates.has(status) ||
    severity === "CRITICO" ||
    severity === "ALTA" ||
    Boolean(item.DetalleError)
  );
}

function getPriorityWeight(item: AuditResultRecord) {
  const status = normalizeState(item.EstadoDetallado);
  const severity = normalizeState(item.Severidad);

  if (status === "FAILED" || status === "ERROR") return 50;
  if (severity === "CRITICO") return 45;
  if (status === "MANUAL_REVIEW") return 40;
  if (status === "DISCREPANCIA") return 35;
  if (severity === "ALTA") return 30;
  return 10;
}

function PriorityPill({ item }: { item: AuditResultRecord }) {
  const status = normalizeState(item.EstadoDetallado);
  const severity = normalizeState(item.Severidad);
  const label =
    status === "FAILED" || status === "ERROR"
      ? "Falla"
      : status === "MANUAL_REVIEW"
        ? "Revisión"
        : severity === "CRITICO" || severity === "ALTA"
          ? severity.toLowerCase()
          : "Atención";
  const tone =
    status === "FAILED" || status === "ERROR" || severity === "CRITICO"
      ? "border-rose-500/25 bg-rose-500/14 text-rose-300"
      : status === "MANUAL_REVIEW"
        ? "border-violet-500/25 bg-violet-500/14 text-violet-300"
        : "border-amber-500/25 bg-amber-500/14 text-amber-300";

  return (
    <span className={`inline-flex min-h-6 items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold capitalize ${tone}`}>
      {label}
    </span>
  );
}

function getPrimaryIssue(item: AuditResultRecord) {
  const detail = String(item.DetalleError ?? "").trim();
  if (detail) return detail;

  const failedDocument = String(item.DocumentoFallido ?? "").trim();
  if (failedDocument) return `Documento con hallazgo: ${failedDocument}`;

  const severity = String(item.Severidad ?? "").trim();
  if (severity) return `Severidad reportada: ${severity}`;

  return "Resultado requiere revisión operativa.";
}

function buildResultHref(item: AuditResultRecord) {
  return `/audit/results?facNro=${encodeURIComponent(String(item.FacNro ?? ""))}`;
}

function normalizeState(value?: string | null) {
  return String(value ?? "").trim().toUpperCase();
}
