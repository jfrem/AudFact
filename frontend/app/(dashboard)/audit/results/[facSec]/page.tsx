import type { ReactNode } from "react";

import { getAttachments, getAuditResultDetail, getDispensationDetail } from "@/lib/api/audfact";
import { PageHeader } from "@/components/layout/page-header";
import { SectionCard } from "@/components/shared/section-card";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { AuditMetricsPanel } from "@/components/audit/audit-metrics-panel";
import { AuditTimingsPanel } from "@/components/audit/audit-timings-panel";
import { ResultItemsTable } from "@/components/audit/result-items-table";
import { AttachmentResultDetailClient } from "@/components/results/attachment-result-detail-client";

export default async function AuditResultDetailPage({
  params,
}: {
  params: Promise<{ facSec: string }>;
}) {
  const { facSec } = await params;
  const record = await getAuditResultDetail(facSec).catch(() => null);

  const disDetNro = record?.FacNro || "";
  const nitSec = String(record?.FacNitSec ?? "");
  const [dispensationResult, attachments] = await Promise.all([
    disDetNro
      ? getDispensationDetail(disDetNro).catch(() => null)
      : Promise.resolve<null>(null),
    disDetNro && nitSec
      ? getAttachments(disDetNro, nitSec).catch(() => [])
      : Promise.resolve([]),
  ]);
  const dispensation = dispensationResult ?? null;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Detalle de resultado"
        title={`Resultado ${record?.FacNro ?? facSec}`}
        description="Detalle reconstruido desde el historial persistido, con hallazgos, métricas y evidencia documental asociada."
      />

      {record ? (
        <div className="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
          <div className="space-y-6">
            <SectionCard title="Resumen persistido">
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <Metric label="FacSec" value={String(record.FacSec)} mono />
                <Metric label="Factura" value={record.FacNro ?? "N/D"} />
                <Metric
                  label="Estado"
                  value={<AuditStatusBadge status={record.EstadoDetallado} />}
                />
                <Metric label="Severidad" value={record.Severidad ?? "N/D"} />
              </div>
            </SectionCard>

            <SectionCard
              title="Hallazgos persistidos"
              description="Hallazgos persistidos usando el schema canonico del motor de auditoria."
            >
              <ResultItemsTable items={record.findings ?? []} />
            </SectionCard>

            <SectionCard
              title="Rendimiento del pipeline"
              description="Timings persistidos por fase y consumo Gemini incluidos en el resultado histórico."
            >
              <AuditTimingsPanel
                timings={record.timings}
                totalDurationMs={record.DuracionProcesamientoMs}
                documentsProcessed={record.DocumentosProcesados}
              />
            </SectionCard>

            {record.metrics ? (
              <SectionCard
                title="Métricas funcionales"
                description="Conteos calculados por el motor de reglas sobre los hallazgos de auditoría."
              >
                <AuditMetricsPanel metrics={record.metrics} />
              </SectionCard>
            ) : null}
          </div>

          <AttachmentResultDetailClient
            disDetNro={disDetNro}
            attachments={attachments ?? []}
            dispensation={dispensation}
            items={dispensation?.items ?? []}
          />
        </div>
      ) : (
        <SectionCard
          title="Sin contexto suficiente"
          description="El backend no encontró el FacSec solicitado en el historial persistido."
        >
          <p className="text-sm text-slate-400">
            No se pudo reconstruir el registro solicitado.
          </p>
        </SectionCard>
      )}
    </div>
  );
}

function Metric({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: ReactNode;
  mono?: boolean;
}) {
  return (
    <div className="surface-subtle rounded-lg p-4">
      <p className="text-[11px] uppercase tracking-[0.2em] text-slate-500">{label}</p>
      <div className={`mt-3 text-lg font-semibold text-white ${mono ? "font-mono text-sm" : ""}`}>
        {value}
      </div>
    </div>
  );
}
