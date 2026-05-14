"use client";

import { Download, FileText } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { attachmentPreviewQuery } from "@/lib/query/audit";
import { getAttachmentDownloadUrl } from "@/lib/api/audfact";
import type { AttachmentRecord } from "@/lib/schemas/domain";
import { AttachmentIframeViewer } from "@/components/attachments/attachment-iframe-viewer";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";

export function AttachmentViewerPanel({
  invoiceId,
  attachment,
}: {
  invoiceId: string;
  attachment?: AttachmentRecord;
}) {
  const attachmentId = attachment?.id_documento;
  const attachmentName = attachment?.nombre_documento ?? "Adjunto";
  const attachmentAlias = attachment?.nombre_alternativo ?? "Sin alias";
  const storageType = attachment?.TipoAlmacenamiento ?? "N/D";
  const { data, isLoading, isError, error } = useQuery({
    ...attachmentPreviewQuery(invoiceId, attachmentId ?? ""),
    enabled: Boolean(invoiceId) && Boolean(attachmentId),
  });

  if (!attachment) {
    return <AttachmentIframeViewer title="Visor de adjuntos" preview={null} />;
  }

  return (
    <section className="flex flex-col overflow-hidden">
      <div className="border-b border-white/10 pb-3 sm:pb-3.5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-1.5">
            <div className="flex items-center gap-2 text-[11px] uppercase tracking-[0.12em] text-slate-500">
              <FileText className="h-3.5 w-3.5" />
              <span>Visor de evidencia</span>
            </div>
            <div className="truncate text-sm font-semibold text-white sm:text-base">
              {attachmentName}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
              <span>Alias: {attachmentAlias}</span>
              <span>Tipo: {storageType}</span>
              <span>ID: {String(attachmentId ?? "N/D")}</span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[11px] uppercase tracking-[0.08em] text-slate-400">
              {storageType}
            </span>
            <Button asChild variant="secondary" size="sm">
              <a
                href={getAttachmentDownloadUrl(invoiceId, attachmentId ?? "")}
                target="_blank"
                rel="noreferrer"
              >
                <Download className="h-4 w-4" />
                Descargar
              </a>
            </Button>
          </div>
        </div>
      </div>

      <div className="pt-3 sm:pt-4">
        {isLoading ? (
          <div className="flex h-[65vh] min-h-[500px] items-center justify-center rounded-lg border border-white/10 bg-slate-950/50 text-slate-400">
            <Spinner className="mr-2" />
            Cargando preview del adjunto...
          </div>
        ) : isError ? (
          <div className="flex h-[65vh] min-h-[500px] items-center justify-center rounded-lg border border-dashed border-rose-500/20 bg-rose-500/[0.05] px-6 text-center text-sm text-rose-200">
            No fue posible preparar la visualización embebida.
            {error instanceof Error ? ` ${error.message}` : ""}
          </div>
        ) : (
          <AttachmentIframeViewer title={attachmentName} preview={data ?? null} />
        )}
      </div>
    </section>
  );
}
