"use client";

import * as React from "react";

import type { AttachmentRecord, DispensationDetail } from "@/lib/schemas/domain";
import { Card } from "@/components/ui/card";
import { SectionCard } from "@/components/shared/section-card";
import { AttachmentList } from "@/components/attachments/attachment-list";
import { AttachmentViewerPanel } from "@/components/attachments/attachment-viewer-panel";

export function AttachmentResultDetailClient({
  disDetNro,
  attachments,
  dispensation,
  items,
}: {
  disDetNro: string;
  attachments: AttachmentRecord[];
  dispensation: DispensationDetail | null;
  items: any[];
}) {
  const [selected, setSelected] = React.useState<AttachmentRecord | undefined>(
    attachments[0],
  );

  React.useEffect(() => {
    setSelected((current) => current ?? attachments[0]);
  }, [attachments]);

  const header = dispensation?.header;

  return (
    <div className="grid items-start gap-6 xl:grid-cols-[320px_1fr]">
      {/* Sidebar: Metadata & Files */}
      <div className="space-y-6">
        <SectionCard title="Contexto">
          <div className="border-b border-white/10 pb-4 text-sm text-slate-300">
            <p className="font-medium text-white">
              {String(header?.NombrePaciente ?? "Paciente no disponible")}
            </p>
            <p className="mt-1 text-slate-400">
              {String(header?.Cliente ?? "Cliente no disponible")} ·{" "}
              {String(header?.CodigoDiagnostico ?? "N/D")}
            </p>
          </div>
          
          <div className="pt-4">
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">
              Ítems Dispensados
            </h3>
            <div className="space-y-0">
              {items.map((row, index) => (
                <div
                  key={`${String(row.CodigoArticulo ?? "item")}-${index}`}
                  className="border-b border-white/10 py-3 last:border-0"
                >
                  <p className="font-medium text-white text-[13px] leading-snug">
                    {String(row.NombreArticulo ?? "Articulo sin nombre")}
                  </p>
                  <p className="mt-1 text-[12px] text-slate-400">
                    Entregada {String(row.CantidadEntregada ?? "N/D")} / Prescrita{" "}
                    {String(row.CantidadPrescrita ?? "N/D")}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </SectionCard>

        <SectionCard>
          <AttachmentList
            items={attachments}
            selectedId={selected?.id_documento ? String(selected.id_documento) : undefined}
            onSelect={setSelected}
          />
        </SectionCard>
      </div>

      {/* Main Workspace: PDF Viewer */}
      <Card className="flex h-full flex-col overflow-hidden rounded-xl border border-white/10 bg-[#111c2b] p-4 md:p-5">
        <AttachmentViewerPanel disDetNro={disDetNro} attachment={selected} />
      </Card>
    </div>
  );
}
