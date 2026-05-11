"use client";

import * as React from "react";
import { Eye, FileImage, FileText } from "lucide-react";

import type { AttachmentPreview } from "@/lib/schemas/domain";
import { base64ToBlob } from "@/lib/utils";

export function AttachmentIframeViewer({
  preview,
  title,
}: {
  preview?: AttachmentPreview | null;
  title: string;
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
      <div className="flex h-[65vh] min-h-[600px] flex-col items-center justify-center rounded-xl border border-dashed border-white/10 px-6 text-center">
        <Eye className="h-10 w-10 text-slate-500/45" />
        <p className="mt-3 text-sm font-medium text-slate-300">Selecciona un adjunto</p>
        <p className="mt-1 max-w-sm text-sm text-slate-400">
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
      <div className="flex h-[65vh] min-h-[600px] flex-col items-center justify-center rounded-xl border border-dashed border-white/10 px-6 text-center">
        <FileText className="h-10 w-10 text-slate-500/45" />
        <p className="mt-3 text-sm font-medium text-slate-300">Vista previa no disponible</p>
        <p className="mt-1 max-w-sm text-sm text-slate-400">
          Este tipo de archivo no puede renderizarse dentro del visor embebido. Usa la descarga
          directa para revisarlo fuera de la aplicación.
        </p>
      </div>
    );
  }

  if (isImage) {
    return (
      <div className="flex h-[65vh] min-h-[600px] items-center justify-center overflow-hidden rounded-xl border border-white/10 bg-black/20 p-4">
        <img
          src={objectUrl}
          alt={title}
          className="max-h-full max-w-full rounded-lg object-contain"
        />
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-white/10">
      <div className="flex items-center gap-2 border-b border-white/10 px-4 py-2.5 text-[11px] uppercase tracking-[0.12em] text-slate-500">
        <FileImage className="h-3.5 w-3.5" />
        <span>{isPdf ? "Documento PDF" : "Vista embebida"}</span>
      </div>
      <iframe
        title={title}
        src={objectUrl}
        className="h-[65vh] min-h-[600px] w-full bg-white"
      />
    </div>
  );
}
