"use client";

import * as React from "react";
import {
  AlertTriangle,
  Download,
  ExternalLink,
  FileImage,
  FileText,
  Maximize2,
  Minimize2,
  RotateCcw,
  RotateCw,
  ZoomIn,
  ZoomOut,
} from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { attachmentPreviewQuery } from "@/lib/query/audit";
import { getAttachmentDownloadUrl } from "@/lib/api/audfact";
import type { AttachmentRecord } from "@/lib/schemas/domain";
import { AttachmentIframeViewer } from "@/components/attachments/attachment-iframe-viewer";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function AttachmentViewerPanel({
  disDetNro,
  attachment,
  className,
}: {
  disDetNro: string;
  attachment?: AttachmentRecord;
  className?: string;
}) {
  const attachmentId = attachment?.id_adjunto_fisico ?? attachment?.AdjDisId ?? attachment?.attachment_id;
  const attachmentName = attachment?.nombre_documento || attachment?.AdjDisNom || "Adjunto";
  const attachmentAlias = attachment?.nombre_alternativo || attachment?.physical_stored_alias || "Sin alias";
  const storageType = attachment?.TipoAlmacenamiento ?? "N/D";
  const isSinDocumentos = storageType === "SIN_DOCUMENTOS";

  // Estado de inspección forense (zoom y rotación)
  const [zoom, setZoom] = React.useState<number>(1);
  const [rotation, setRotation] = React.useState<number>(0);
  const [isFullscreen, setIsFullscreen] = React.useState<boolean>(false);

  // Restablecer zoom y giro al cambiar de documento
  React.useEffect(() => {
    setZoom(1);
    setRotation(0);
  }, [attachmentId]);

  const { data, isLoading, isError, error } = useQuery({
    ...attachmentPreviewQuery(disDetNro, attachmentId ?? ""),
    enabled: Boolean(disDetNro) && Boolean(attachmentId) && !isSinDocumentos,
  });

  const isImage = data?.mime?.startsWith("image/");
  const isPdf = data?.mime === "application/pdf";
  const downloadUrl = attachmentId ? getAttachmentDownloadUrl(disDetNro, attachmentId) : null;

  const handleZoomIn = () => {
    setZoom((prev) => Math.min(2.5, Number((prev + 0.25).toFixed(2))));
  };

  const handleZoomOut = () => {
    setZoom((prev) => Math.max(0.5, Number((prev - 0.25).toFixed(2))));
  };

  const handleResetView = () => {
    setZoom(1);
    setRotation(0);
  };

  const handleRotate = () => {
    setRotation((prev) => (prev + 90) % 360);
  };

  if (!attachment) {
    return <AttachmentIframeViewer title="Visor de adjuntos" preview={null} />;
  }

  const content = (
    <section className={cn("flex h-full flex-col overflow-hidden", className)}>
      {/* Barra de herramientas forense integrada */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/30 px-4 py-2.5">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-sky-500/10 text-sky-400 ring-1 ring-inset ring-sky-500/20">
            {isImage ? (
              <FileImage className="h-4 w-4" />
            ) : (
              <FileText className="h-4 w-4" />
            )}
          </span>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <span className="truncate text-xs font-semibold text-foreground sm:text-sm">
                {attachmentName}
              </span>
              <span className="rounded bg-slate-800/80 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-slate-300">
                {storageType}
              </span>
              {isPdf ? (
                <span className="rounded bg-rose-500/10 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-rose-300 ring-1 ring-inset ring-rose-500/20">
                  PDF
                </span>
              ) : isImage ? (
                <span className="rounded bg-amber-500/10 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-amber-300 ring-1 ring-inset ring-amber-500/20">
                  IMG
                </span>
              ) : null}
            </div>
            <p className="truncate text-[10px] text-slate-500">
              Alias: {attachmentAlias} · ID: {String(attachmentId ?? "N/D")}
            </p>
          </div>
        </div>

        {/* Controles de inspección forense */}
        <div className="flex items-center gap-1.5">
          {isImage ? (
            <div className="flex items-center gap-1 rounded-md border border-border bg-background/80 p-0.5 text-xs text-slate-300">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-6 w-6 text-slate-400 hover:text-slate-100"
                onClick={handleZoomOut}
                title="Reducir zoom"
              >
                <ZoomOut className="h-3.5 w-3.5" />
              </Button>
              <button
                type="button"
                onClick={handleResetView}
                className="px-1 font-mono text-[11px] tabular-nums text-slate-300 hover:text-sky-300"
                title="Restablecer a 100%"
              >
                {Math.round(zoom * 100)}%
              </button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-6 w-6 text-slate-400 hover:text-slate-100"
                onClick={handleZoomIn}
                title="Aumentar zoom"
              >
                <ZoomIn className="h-3.5 w-3.5" />
              </Button>
              <div className="mx-0.5 h-3.5 w-px bg-border" />
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-6 w-6 text-slate-400 hover:text-slate-100"
                onClick={handleRotate}
                title="Girar 90° (útil para fórmulas escaneadas horizontalmente)"
              >
                <RotateCw className="h-3.5 w-3.5" />
              </Button>
              {(zoom !== 1 || rotation !== 0) && (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6 text-amber-400 hover:text-amber-300"
                  onClick={handleResetView}
                  title="Restablecer zoom y orientación"
                >
                  <RotateCcw className="h-3.5 w-3.5" />
                </Button>
              )}
            </div>
          ) : null}

          {downloadUrl && !isSinDocumentos ? (
            <>
              <Button
                asChild
                variant="ghost"
                size="icon"
                className="h-7 w-7 text-slate-400 hover:text-slate-100"
                title="Abrir en pestaña nueva"
              >
                <a href={downloadUrl} target="_blank" rel="noreferrer">
                  <ExternalLink className="h-3.5 w-3.5" />
                </a>
              </Button>
              <Button asChild variant="outline" size="sm" className="h-7 px-2.5 text-xs">
                <a href={downloadUrl} target="_blank" rel="noreferrer">
                  <Download className="mr-1 h-3.5 w-3.5" />
                  Descargar
                </a>
              </Button>
            </>
          ) : null}

          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-7 w-7 text-slate-400 hover:text-slate-100"
            onClick={() => setIsFullscreen((prev) => !prev)}
            title={isFullscreen ? "Salir de pantalla completa" : "Modo inmersivo a pantalla completa"}
          >
            {isFullscreen ? (
              <Minimize2 className="h-3.5 w-3.5" />
            ) : (
              <Maximize2 className="h-3.5 w-3.5" />
            )}
          </Button>
        </div>
      </div>

      {/* Canvas principal de visualización del documento */}
      <div className="relative flex-1 overflow-hidden p-2 sm:p-3">
        {isSinDocumentos ? (
          <div className="flex h-full min-h-[400px] flex-col items-center justify-center space-y-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center">
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
            className="h-full min-h-[400px]"
            description="El backend está preparando el preview del documento."
            title="Cargando preview"
            variant="detail"
          />
        ) : isError ? (
          <div className="flex h-full min-h-[400px] flex-col items-center justify-center space-y-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center">
            <AlertTriangle className="h-8 w-8 text-rose-500/50" strokeWidth={1.5} />
            <div className="space-y-1">
              <p className="text-sm font-medium text-slate-300">No se pudo cargar el documento</p>
              <p className="max-w-md text-[11px] font-mono text-slate-500">
                {error instanceof Error ? error.message : "Error interno del servidor"}
              </p>
            </div>
          </div>
        ) : (
          <AttachmentIframeViewer
            title={attachmentName}
            preview={data ?? null}
            zoom={zoom}
            rotation={rotation}
            className="h-full"
          />
        )}
      </div>
    </section>
  );

  if (isFullscreen) {
    return (
      <div className="fixed inset-0 z-50 flex flex-col bg-background/95 backdrop-blur-md p-3 sm:p-5">
        <div className="flex-1 overflow-hidden rounded-lg border border-border bg-card shadow-2xl">
          {content}
        </div>
      </div>
    );
  }

  return content;
}
