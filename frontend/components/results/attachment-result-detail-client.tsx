"use client";

import * as React from "react";

import type { AttachmentRecord, DispensationDetail } from "@/lib/schemas/domain";
import { Card } from "@/components/ui/card";
import { SectionCard } from "@/components/shared/section-card";
import { AttachmentList } from "@/components/attachments/attachment-list";
import { AttachmentViewerPanel } from "@/components/attachments/attachment-viewer-panel";
import { DispensationInfoPanel } from "@/components/dispensation/dispensation-info-panel";

export function AttachmentResultDetailClient({
  disDetNro,
  attachments,
  dispensation,
  items,
}: {
  disDetNro: string;
  attachments: AttachmentRecord[];
  dispensation: DispensationDetail | null;
  items: Record<string, unknown>[];
}) {
  const [selected, setSelected] = React.useState<AttachmentRecord | undefined>(
    attachments[0],
  );

  React.useEffect(() => {
    setSelected((current) => current ?? attachments[0]);
  }, [attachments]);

  const header = dispensation?.header;

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[320px_1fr] xl:grid-cols-[340px_1fr]">
      {/* Sidebar: Full dispensation context & attachment list */}
      <div className="space-y-4 lg:max-h-[calc(100vh-140px)] lg:overflow-y-auto lg:scrollbar-thin lg:pr-1 lg:pb-8">
        <DispensationInfoPanel
          header={header as Record<string, unknown> | undefined}
          items={items}
        />

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
