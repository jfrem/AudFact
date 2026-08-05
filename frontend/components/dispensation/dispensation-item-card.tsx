"use client";

import * as React from "react";
import { ChevronDown, Pill, Package } from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";

/** Safe string helper — returns null for empty/undefined */
function s(val: unknown): string | null {
  if (val == null) return null;
  const str = String(val).trim();
  return str === "" ? null : str;
}

function QuantityBadge({
  entregada,
  prescrita,
}: {
  entregada: string | null;
  prescrita: string | null;
}) {
  if (!entregada || !prescrita) return null;
  const ent = Number(entregada);
  const pres = Number(prescrita);
  const isComplete = !isNaN(ent) && !isNaN(pres) && ent >= pres;

  return (
    <Badge variant={isComplete ? "success" : "warning"} className="text-[10px]">
      {entregada}/{prescrita}
    </Badge>
  );
}

function TipoBadge({ tipo }: { tipo: string | null }) {
  if (!tipo) return null;
  const upper = tipo.toUpperCase();
  let variant: "info" | "neutral" | "warning" = "neutral";
  if (upper.includes("POS")) variant = "info";
  else if (upper.includes("MIPRES") || upper.includes("NO POS")) variant = "warning";

  return (
    <Badge variant={variant} className="text-[10px]">
      {tipo}
    </Badge>
  );
}

export function DispensationItemCard({
  item,
  index,
}: {
  item: Record<string, unknown>;
  index: number;
}) {
  const [expanded, setExpanded] = React.useState(false);

  const nombre = s(item.NombreArticulo) ?? "Artículo sin nombre";
  const codigoArticulo = s(item.CodigoArticulo);
  const codigoProducto = s(item.CodigoProducto);
  const cum = s(item.CUM);
  const laboratorio = s(item.Laboratorio);
  const lote = s(item.Lote);
  const fechaVenc = s(item.FechaVencimiento);
  const cantEntregada = s(item.CantidadEntregada);
  const cantPrescrita = s(item.CantidadPrescrita);
  const tipo = s(item.Tipo);
  const mipres = s(item.Mipres);

  // Traceability IDs
  const traceIds = [
    { label: "IdPrincipal", value: s(item.IdPrincipal) },
    { label: "IdDirec", value: s(item.IdDirec) },
    { label: "IdProg", value: s(item.IdProg) },
    { label: "IdEntr", value: s(item.IdEntr) },
    { label: "IdRepEnt", value: s(item.IdRepEnt) },
    { label: "IdFact", value: s(item.IdFact) },
  ].filter((t) => t.value !== null);

  const hasTraceability = mipres !== null || traceIds.length > 0;

  return (
    <div className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
      {/* Title row */}
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-2">
          <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-white/10 bg-white/[0.04] text-slate-400">
            <Pill className="h-3 w-3" />
          </div>
          <div className="min-w-0">
            <p className="text-[13px] font-medium leading-snug text-white">{nombre}</p>
            {laboratorio && (
              <p className="mt-0.5 text-[11px] text-slate-500">{laboratorio}</p>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <TipoBadge tipo={tipo} />
          <QuantityBadge entregada={cantEntregada} prescrita={cantPrescrita} />
        </div>
      </div>

      {/* Detail grid */}
      <div className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-1.5">
        {cum && (
          <DataPair label="CUM" value={cum} />
        )}
        {codigoArticulo && (
          <DataPair label="Código" value={codigoArticulo} />
        )}
        {codigoProducto && codigoProducto !== codigoArticulo && (
          <DataPair label="Cód. Producto" value={codigoProducto} />
        )}
        {lote && (
          <DataPair label="Lote" value={lote} />
        )}
        {fechaVenc && (
          <DataPair label="Vence" value={fechaVenc} />
        )}
      </div>

      {/* Collapsible traceability */}
      {hasTraceability && (
        <div className="mt-2.5 border-t border-white/[0.06] pt-2">
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="flex w-full items-center gap-1.5 text-[11px] font-medium text-slate-400 transition-colors hover:text-slate-300"
            aria-expanded={expanded}
          >
            <Package className="h-3 w-3" />
            <span>Trazabilidad MIPRES</span>
            <ChevronDown
              className={cn(
                "ml-auto h-3 w-3 transition-transform duration-200",
                expanded && "rotate-180"
              )}
            />
          </button>

          <div
            className={cn(
              "grid transition-[grid-template-rows] duration-200 ease-out",
              expanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]"
            )}
          >
            <div className="overflow-hidden">
              <div className="pt-2 pb-1 space-y-1.5">
                {mipres && (
                  <DataPair label="MIPRES" value={mipres} mono />
                )}
                {traceIds.map((t) => (
                  <DataPair key={t.label} label={t.label} value={t.value!} mono />
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function DataPair({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) {
  return (
    <div className="min-w-0">
      <p className="text-[10px] font-medium uppercase tracking-[0.08em] text-slate-500">
        {label}
      </p>
      <p
        className={cn(
          "mt-0.5 truncate text-[12px] text-slate-200",
          mono && "font-mono tabular-nums"
        )}
        title={value}
      >
        {value}
      </p>
    </div>
  );
}
