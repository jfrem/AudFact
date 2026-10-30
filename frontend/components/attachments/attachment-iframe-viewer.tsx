"use client";

import * as React from "react";
import { Eye, FileText } from "lucide-react";

import type { AttachmentPreview } from "@/lib/schemas/domain";
import { base64ToBlob, cn } from "@/lib/utils";

export function AttachmentIframeViewer({
  preview,
  title,
  zoom = 1,
  rotation = 0,
  className,
}: {
  preview?: AttachmentPreview | null;
  title: string;
  zoom?: number;
  rotation?: number;
  className?: string;
}) {
  const [objectUrl, setObjectUrl] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!preview) {
      setObjectUrl(null);
      return undefined;
    }

    const blob = base64ToBlob(preview.data, preview.mime);
    const url = URL.createObjectURL(blob);
    setObjectUrl(url);

    return () => {
      URL.revokeObjectURL(url);
    };
  }, [preview]);

  if (!preview) {
    return (
      <div
        className={cn(
          "flex h-full min-h-[400px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center",
          className,
        )}
      >
        <Eye className="h-10 w-10 text-slate-500/45" />
        <p className="mt-3 text-sm font-medium text-slate-300">Selecciona un adjunto</p>
        <p className="mt-1 max-w-sm text-xs text-slate-500">
          El visor mostrará aquí la evidencia embebida cuando el documento seleccionado tenga una
          vista previa disponible.
        </p>
      </div>
    );
  }

  const isPdf = preview.mime === "application/pdf";
  const isImage = preview.mime.startsWith("image/");
  const canEmbed = isPdf || isImage;

  if (!canEmbed || !objectUrl) {
    return (
      <div
        className={cn(
          "flex h-full min-h-[400px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center",
          className,
        )}
      >
        <FileText className="h-10 w-10 text-slate-500/45" />
        <p className="mt-3 text-sm font-medium text-slate-300">Vista previa no disponible</p>
        <p className="mt-1 max-w-sm text-xs text-slate-500">
          Este formato de archivo no puede renderizarse de manera embebida. Descarga el archivo para revisarlo externamente.
        </p>
      </div>
    );
  }

  if (isImage) {
    return (
      <div
        className={cn(
          "relative flex h-full min-h-[400px] w-full items-center justify-center overflow-auto rounded-lg border border-border bg-slate-950/80 p-4 select-none scrollbar-thin",
          className,
        )}
      >
        <div
          className="relative flex items-center justify-center transition-transform duration-200 ease-out"
          style={{
            transform: `scale(${zoom}) rotate(${rotation}deg)`,
            transformOrigin: "center center",
          }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={objectUrl}
            alt={title}
            className="max-h-[calc(90vh-180px)] max-w-full rounded object-contain shadow-2xl"
          />
        </div>
      </div>
    );
  }

  return (
    <div
      className={cn(
        "relative h-full min-h-[450px] w-full overflow-hidden rounded-lg border border-border bg-card",
        className,
      )}
    >
      <iframe
        title={title}
        src={objectUrl}
        className="h-full w-full border-0 bg-white"
      />
    </div>
  );
}
