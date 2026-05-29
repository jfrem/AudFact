import { getAttachments, getDispensationDetail } from "@/lib/api/audfact";
import { PageHeader } from "@/components/layout/page-header";
import { SectionCard } from "@/components/shared/section-card";
import { AttachmentResultDetailClient } from "@/components/results/attachment-result-detail-client";

export default async function DispensationDetailPage({
  params,
}: {
  params: Promise<{ disDetNro: string }>;
}) {
  const { disDetNro } = await params;
  const dispensation = (await getDispensationDetail(disDetNro).catch(() => null)) ?? null;
  const nitSec = dispensation?.header.NitSec ? String(dispensation.header.NitSec) : "";
  const attachments =
    nitSec ? ((await getAttachments(disDetNro, nitSec).catch(() => [])) ?? []) : [];
  const items = dispensation?.items ?? [];

  const facSec = dispensation?.header?.FacSec ? String(dispensation.header.FacSec) : null;
  const numFactura = dispensation?.header?.NumeroFactura ? String(dispensation.header.NumeroFactura) : null;
  const copagoRaw = dispensation?.header?.VlrCobrado;
  const copagoNum = copagoRaw && !isNaN(Number(copagoRaw)) ? Number(copagoRaw) : null;
  const copago = copagoNum !== null
    ? new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", minimumFractionDigits: 0 }).format(copagoNum)
    : null;
  const isCopagoZero = copagoNum === 0;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Identificación"
        title={`Factura ${numFactura ?? disDetNro}`}
        description={facSec ? `FacSec ${facSec}` : undefined}
        actions={
          copago ? (
            <div className="text-right">
              <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">
                Copago
              </p>
              <p className={`text-xl font-semibold tabular-nums ${isCopagoZero ? "text-slate-400" : "text-white"}`}>
                {copago}
              </p>
            </div>
          ) : null
        }
      />
      <AttachmentResultDetailClient
        disDetNro={disDetNro}
        attachments={attachments}
        dispensation={dispensation}
        items={items}
      />
    </div>
  );
}
