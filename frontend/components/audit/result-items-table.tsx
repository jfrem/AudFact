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

function FindingStatusLabel({ status }: { status: AuditFinding["resultado"] }) {
  const config: Record<AuditFinding["resultado"], { label: string; className: string }> = {
    COINCIDE: { label: "Coincide", className: "text-emerald-400" },
    VALOR_DISTINTO: { label: "Valor distinto", className: "text-rose-400" },
    NO_ENCONTRADO: { label: "No encontrado", className: "text-amber-400" },
    OMITIDO: { label: "Omitido", className: "text-slate-400" },
    NO_CONCLUYENTE: { label: "No concluyente", className: "text-violet-300" },
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
            <TableRow key={`${item.campo}-${index}`} className="align-top">
              <TableCell className="text-white" title={item.campo}>{item.campo}</TableCell>
              <TableCell className="text-slate-400 text-xs" title={item.documento ?? "N/D"}>
                {item.documento ? (
                  <span className="inline-flex items-center rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-300">
                    {item.documento}
                  </span>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell className="min-w-[100px]" title={item.resultado}>
                <FindingStatusLabel status={item.resultado} />
              </TableCell>
              <TableCell className="min-w-[100px]" title={item.severidad}>
                <SeverityBadge severity={item.severidad} />
              </TableCell>
              <TableCell className="min-w-[200px] leading-6 text-slate-300" title={formatFindingDetail(item)}>
                {formatFindingDetail(item)}
              </TableCell>
              <TableCell className="min-w-[200px] space-y-1 text-xs leading-5 text-slate-400">
                {item.valorFuenteVerdad ? (
                  <div title={item.valorFuenteVerdad}>
                    <span className="text-slate-500">Esperado:</span> {item.valorFuenteVerdad}
                  </div>
                ) : null}
                {item.valorDocumento ? (
                  <div title={item.valorDocumento}>
                    <span className="text-slate-500">Observado:</span> {item.valorDocumento}
                  </div>
                ) : null}
                {!item.valorFuenteVerdad && !item.valorDocumento ? (
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

function formatFindingDetail(item: AuditFinding) {
  if (typeof item.detalle === "string" && item.detalle.trim() !== "") {
    return item.detalle;
  }

  if (item.detalle && typeof item.detalle === "object") {
    return JSON.stringify(item.detalle);
  }

  return item.resultado === "COINCIDE"
    ? "Coincide con fuente de verdad"
    : "Requiere validación";
}
