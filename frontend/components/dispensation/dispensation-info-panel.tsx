"use client";

import * as React from "react";
import {
  User,
  Stethoscope,
  Building2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { SectionCard } from "@/components/shared/section-card";
import { DispensationDatesTimeline } from "@/components/dispensation/dispensation-dates-timeline";
import { DispensationItemCard } from "@/components/dispensation/dispensation-item-card";

/** Safe string extraction — returns null for empty/undefined */
function s(val: unknown): string | null {
  if (val == null) return null;
  const str = String(val).trim();
  return str === "" ? null : str;
}

/** Format currency */
function currency(val: unknown): string | null {
  const raw = s(val);
  if (!raw) return null;
  const num = Number(raw);
  if (isNaN(num)) return raw;
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(num);
}

function InfoRow({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: string | null;
  mono?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-3 py-1.5">
      <span className="shrink-0 text-[12px] text-slate-500">{label}</span>
      <span
        className={cn(
          "min-w-0 truncate text-right text-[12px] font-medium",
          value ? "text-slate-200" : "text-slate-600",
          mono && "font-mono tabular-nums"
        )}
        title={value ?? "N/D"}
      >
        {value ?? "N/D"}
      </span>
    </div>
  );
}

function RegimenBadge({ regimen }: { regimen: string | null }) {
  if (!regimen) return null;
  const lower = regimen.toLowerCase();
  let variant: "info" | "success" | "warning" | "neutral" = "neutral";
  if (lower.includes("contributivo")) variant = "info";
  else if (lower.includes("subsidiado")) variant = "success";
  else if (lower.includes("arl")) variant = "warning";

  return (
    <Badge variant={variant} className="text-[10px]">
      {regimen}
    </Badge>
  );
}

export function DispensationInfoPanel({
  header,
  items,
}: {
  header: Record<string, unknown> | undefined;
  items: Record<string, unknown>[];
}) {
  if (!header || Object.keys(header).length === 0) {
    return (
      <SectionCard title="Información">
        <div className="rounded-lg border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-400">
          No se encontró información de la dispensación.
        </div>
      </SectionCard>
    );
  }

  const disId = s(header.DisId);
  const numFactura = s(header.NumeroFactura);
  const copago = currency(header.VlrCobrado);
  const nombrePaciente = s(header.NombrePaciente);
  const tipoDocPac = s(header.TipoDocumentoPaciente);
  const docPaciente = s(header.DocumentoPaciente);
  const fechaNac = s(header.FechaNacimiento);
  const regimen = s(header.RegimenPaciente);
  const cliente = s(header.Cliente);
  const nitCliente = s(header.NITCliente);
  const diagCodigo = s(header.CodigoDiagnostico);
  const medico = s(header.Medico);
  const tipoDocMed = s(header.TipoDocumentoMedico);
  const docMedico = s(header.DocumentoMedico);
  const ips = s(header.IPS);
  const ipsNit = s(header.IPS_NIT);

  return (
    <div className="space-y-4">
      {/* ── Section 2: Patient / Prescriber Tabs ── */}
      <SectionCard>
        <Tabs defaultValue="paciente" className="w-full">
          <TabsList className="mb-3 w-full">
            <TabsTrigger value="paciente" className="flex-1 gap-1.5">
              <User className="h-3.5 w-3.5" />
              Paciente
            </TabsTrigger>
            <TabsTrigger value="prescriptor" className="flex-1 gap-1.5">
              <Stethoscope className="h-3.5 w-3.5" />
              Prescriptor
            </TabsTrigger>
          </TabsList>

          <TabsContent value="paciente">
            <div className="space-y-0 divide-y divide-white/[0.06]">
              {/* Patient name + regime badge */}
              <div className="pb-2.5">
                <p className="text-[14px] font-semibold text-white leading-snug">
                  {nombrePaciente ?? "Paciente no disponible"}
                </p>
                <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <RegimenBadge regimen={regimen} />
                  {diagCodigo && (
                    <Badge variant="neutral" className="text-[10px]">
                      Dx: {diagCodigo}
                    </Badge>
                  )}
                </div>
              </div>

              {/* Patient details */}
              <div className="pt-2.5">
                <InfoRow
                  label="Documento"
                  value={
                    tipoDocPac && docPaciente
                      ? `${tipoDocPac} ${docPaciente}`
                      : docPaciente ?? tipoDocPac
                  }
                  mono
                />
                <InfoRow label="Nacimiento" value={fechaNac} />
                <InfoRow label="EPS" value={cliente} />
                <InfoRow label="NIT EPS" value={nitCliente} mono />
              </div>
            </div>
          </TabsContent>

          <TabsContent value="prescriptor">
            <div className="space-y-0 divide-y divide-white/[0.06]">
              {/* Doctor info */}
              <div className="pb-2.5">
                <p className="text-[14px] font-semibold text-white leading-snug">
                  {medico ?? "Médico no disponible"}
                </p>
              </div>

              {/* Doctor details */}
              <div className="pt-2.5">
                <InfoRow
                  label="Documento"
                  value={
                    tipoDocMed && docMedico
                      ? `${tipoDocMed} ${docMedico}`
                      : docMedico ?? tipoDocMed
                  }
                  mono
                />
              </div>

              {/* IPS info */}
              <div className="pt-2.5">
                <div className="flex items-center gap-2 pb-2">
                  <Building2 className="h-3.5 w-3.5 text-slate-500" />
                  <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                    IPS
                  </p>
                </div>
                <InfoRow label="Nombre" value={ips} />
                <InfoRow label="NIT" value={ipsNit} mono />
              </div>
            </div>
          </TabsContent>
        </Tabs>
      </SectionCard>

      {/* ── Section 3: Dates & Authorization ── */}
      <SectionCard title="Fechas y Autorización">
        <DispensationDatesTimeline
          fechaFormula={header.FechaFormula}
          fechaAutorizacion={header.FechaAutorizacion}
          fechaEntrega={header.FechaEntrega}
          numeroAutorizacion={header.NumeroAutorizacion}
        />
      </SectionCard>

      {/* ── Section 4: Dispensed Items ── */}
      <SectionCard
        title="Ítems Dispensados"
        actions={
          <span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-[10px] font-semibold tabular-nums text-slate-400">
            {items.length}
          </span>
        }
      >
        {items.length === 0 ? (
          <div className="rounded-lg border border-dashed border-white/10 px-4 py-5 text-center text-sm text-slate-400">
            No se encontraron ítems dispensados.
          </div>
        ) : (
          <div className="space-y-2">
            {items.map((item, index) => (
              <DispensationItemCard
                key={`${s(item.CodigoArticulo) ?? "item"}-${index}`}
                item={item}
                index={index}
              />
            ))}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
