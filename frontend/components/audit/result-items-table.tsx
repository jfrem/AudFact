import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { AuditFinding } from "@/lib/schemas/domain";
import { SeverityBadge } from "@/components/shared/severity-badge";

function FindingStatusLabel({ status }: { status: AuditFinding["status"] }) {
  const config: Record<AuditFinding["status"], { label: string; className: string }> = {
    MATCH: { label: "Coincide", className: "text-emerald-400" },
    DISCREPANCY: { label: "Discrepancia", className: "text-rose-400" },
    NOT_FOUND: { label: "No encontrado", className: "text-amber-400" },
  };
  const entry = config[status];

  return <span className={`text-xs font-medium ${entry.className}`}>{entry.label}</span>;
}

export function ResultItemsTable({ items }: { items: AuditFinding[] }) {
  if (items.length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-white/10 bg-card px-4 py-5 text-sm text-slate-400">
        No hay hallazgos estructurados en este resultado.
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-white/10 bg-slate-950/35">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Campo</TableHead>
            <TableHead>Documento</TableHead>
            <TableHead>Estado</TableHead>
            <TableHead>Severidad</TableHead>
            <TableHead>Resumen</TableHead>
            <TableHead>Valores</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.map((item, index) => (
            <TableRow key={`${item.field}-${index}`} className="align-top">
              <TableCell className="text-white" title={item.field}>{item.field}</TableCell>
              <TableCell className="text-slate-400 text-xs" title={item.documento ?? "N/D"}>
                {item.documento ? (
                  <span className="inline-flex items-center rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-300">
                    {item.documento}
                  </span>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell className="min-w-[100px]" title={item.status}>
                <FindingStatusLabel status={item.status} />
              </TableCell>
              <TableCell className="min-w-[100px]" title={item.severity}>
                <SeverityBadge severity={item.severity} />
              </TableCell>
              <TableCell className="min-w-[200px] leading-6 text-slate-300" title={item.reason_short}>
                {item.reason_short}
              </TableCell>
              <TableCell className="min-w-[200px] space-y-1 text-xs leading-5 text-slate-400">
                {item.expected_value ? (
                  <div title={item.expected_value}>
                    <span className="text-slate-500">Esperado:</span> {item.expected_value}
                  </div>
                ) : null}
                {item.observed_value ? (
                  <div title={item.observed_value}>
                    <span className="text-slate-500">Observado:</span> {item.observed_value}
                  </div>
                ) : null}
                {!item.expected_value && !item.observed_value ? (
                  <div title="Sin valores adjuntos">Sin valores adjuntos</div>
                ) : null}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
