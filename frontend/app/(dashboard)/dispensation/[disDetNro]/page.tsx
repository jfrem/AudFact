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

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Detalle técnico"
        title={`Dispensación ${disDetNro}`}
        description="Contexto técnico de la dispensación con sus ítems y la misma política de visor embebido para adjuntos asociados."
      />
      <div className="mt-6">
        <AttachmentResultDetailClient
          disDetNro={disDetNro}
          attachments={attachments}
          dispensation={dispensation}
          items={items}
        />
      </div>
    </div>
  );
}
