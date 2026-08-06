"use client";

import { Download, FileText, AlertTriangle } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { attachmentPreviewQuery } from "@/lib/query/audit";
import { getAttachmentDownloadUrl } from "@/lib/api/audfact";
import type { AttachmentRecord } from "@/lib/schemas/domain";
import { AttachmentIframeViewer } from "@/components/attachments/attachment-iframe-viewer";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";

export function AttachmentViewerPanel({
  disDetNro,
  attachment,
}: {
  disDetNro: string;
  attachment?: AttachmentRecord;
}) {
  const attachmentId = attachment?.id_adjunto_fisico;
  const attachmentName = attachment?.nombre_documento ?? "Adjunto";
  const attachmentAlias = attachment?.nombre_alternativo ?? "Sin alias";
  const storageType = attachment?.TipoAlmacenamiento ?? "N/D";
  const { data, isLoading, isError, error } = useQuery({
    ...attachmentPreviewQuery(disDetNro, attachmentId ?? ""),
    enabled: Boolean(disDetNro) && Boolean(attachmentId),
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
              <span>ID: {String(attachmentId ?? "N/D")}</span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[11px] uppercase tracking-[0.08em] text-slate-400">
              {storageType}
            </span>
            <Button asChild variant="secondary" size="sm">
              <a
                href={getAttachmentDownloadUrl(disDetNro, attachmentId ?? "")}
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
          <BackendRequestSkeleton
            className="min-h-[500px]"
            description="El backend está preparando el preview del documento."
            title="Cargando preview"
            variant="detail"
          />
        ) : isError ? (
          <div className="flex h-[65vh] min-h-[500px] flex-col items-center justify-center space-y-3 rounded-lg border border-dashed border-white/5 bg-white/[0.02] px-6 text-center">
            <AlertTriangle className="h-8 w-8 text-rose-500/50" strokeWidth={1.5} />
            <div className="space-y-1">
              <p className="text-sm font-medium text-slate-300">No se pudo cargar el documento</p>
              <p className="max-w-md text-[11px] font-mono text-slate-500">
                {error instanceof Error ? error.message : "Error interno del servidor"}
              </p>
            </div>
          </div>
        ) : (
          <AttachmentIframeViewer title={attachmentName} preview={data ?? null} />
        )}
      </div>
    </section>
  );
}
