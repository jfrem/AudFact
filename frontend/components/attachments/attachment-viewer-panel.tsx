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
  const attachmentId = attachment?.id_adjunto_fisico ?? attachment?.AdjDisId ?? attachment?.attachment_id;
  const attachmentName = attachment?.nombre_documento || attachment?.AdjDisNom || "Adjunto";
  const attachmentAlias = attachment?.nombre_alternativo || attachment?.physical_stored_alias || "Sin alias";
  const storageType = attachment?.TipoAlmacenamiento ?? "N/D";
  const isSinDocumentos = storageType === "SIN_DOCUMENTOS";

  const { data, isLoading, isError, error } = useQuery({
    ...attachmentPreviewQuery(disDetNro, attachmentId ?? ""),
    enabled: Boolean(disDetNro) && Boolean(attachmentId) && !isSinDocumentos,
  });

  if (!attachment) {
    return <AttachmentIframeViewer title="Visor de adjuntos" preview={null} />;
  }

  return (
    <section className="flex flex-col overflow-hidden">
      <div className="border-b border-border pb-3 sm:pb-3.5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-1.5">
            <div className="flex items-center gap-2 text-[11px] uppercase tracking-[0.12em] text-slate-500">
              <FileText className="h-3.5 w-3.5" />
              <span>Visor de evidencia</span>
            </div>
            <div className="truncate text-sm font-semibold text-foreground sm:text-base">
              {attachmentName}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
              <span>Alias: {attachmentAlias}</span>
              <span>ID: {String(attachmentId ?? "N/D")}</span>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <span className="rounded border border-border bg-muted px-2.5 py-1 text-[11px] uppercase tracking-[0.08em] text-muted-foreground">
              {storageType}
            </span>
            {!isSinDocumentos && attachmentId ? (
              <Button asChild variant="secondary" size="sm">
                <a
                  href={getAttachmentDownloadUrl(disDetNro, attachmentId)}
                  target="_blank"
                  rel="noreferrer"
                >
                  <Download className="h-4 w-4" />
                  Descargar
                </a>
              </Button>
            ) : null}
          </div>
        </div>
      </div>

      <div className="pt-3 sm:pt-4">
        {isSinDocumentos ? (
          <div className="flex h-[65vh] min-h-[500px] flex-col items-center justify-center space-y-3 rounded-lg border border-dashed border-border bg-muted/40 px-6 text-center">
            <FileText className="h-8 w-8 text-slate-500/50" strokeWidth={1.5} />
            <div className="space-y-1">
              <p className="text-sm font-medium text-slate-300">Documento sin archivo físico</p>
              <p className="max-w-md text-xs text-slate-500">
                Este registro no cuenta con archivo binario (BLOB) ni enlace remoto en la fuente de datos.
              </p>
            </div>
          </div>
        ) : isLoading ? (
          <BackendRequestSkeleton
            className="min-h-[500px]"
            description="El backend está preparando el preview del documento."
            title="Cargando preview"
            variant="detail"
          />
        ) : isError ? (
          <div className="flex h-[65vh] min-h-[500px] flex-col items-center justify-center space-y-3 rounded-lg border border-dashed border-border bg-muted/40 px-6 text-center">
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
