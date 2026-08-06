"use client";

import { FileText, Link2, Paperclip } from "lucide-react";

import type { AttachmentRecord } from "@/lib/schemas/domain";
import { cn } from "@/lib/utils";
import { Item, ItemContent, ItemMedia, ItemTitle } from "@/components/ui/item";

export function AttachmentList({
  items,
  selectedId,
  onSelect,
  orientation = "vertical",
}: {
  items: AttachmentRecord[];
  selectedId?: string;
  onSelect: (attachment: AttachmentRecord) => void;
  orientation?: "vertical" | "horizontal";
}) {
  if (items.length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-white/10 bg-card px-4 py-5 text-sm text-slate-400">
        No hay adjuntos asociados visibles para este caso.
      </div>
    );
  }

  return (
    <section className="flex flex-col gap-1 overflow-hidden">
      {/* Compact header */}
      <div className="flex items-center justify-between gap-2 border-b border-white/10 pb-2.5">
        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
          Adjuntos
        </p>
        <span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-[10px] font-semibold tabular-nums text-slate-400">
          {items.length}
        </span>
      </div>

      <div
        className={cn(
          "pt-2 scrollbar-thin",
          orientation === "horizontal"
            ? "flex gap-2 overflow-x-auto overflow-y-hidden"
            : "max-h-[65vh] space-y-1 overflow-y-auto",
        )}
      >
        {items.map((attachment) => {
          const id = String(attachment.id_adjunto_fisico ?? attachment.id_documento ?? attachment.nombre_documento ?? "");
          const active = selectedId === id;

          return (
            <Item
              asChild
              key={id}
              variant={active ? "default" : "ghost"}
              size="sm"
              align="center"
              className={cn("w-full", orientation === "horizontal" && "w-[220px] shrink-0")}
            >
              <button
                type="button"
                onClick={() => onSelect(attachment)}
                aria-pressed={active}
              >
                <ItemMedia
                  className={
                    active
                      ? "h-8 w-8 border-sky-500/20 bg-sky-500/12 text-sky-300"
                      : "h-8 w-8"
                  }
                >
                  {attachment.TipoAlmacenamiento === "URL" ? (
                    <Link2 className="h-3.5 w-3.5" />
                  ) : attachment.TipoAlmacenamiento === "BLOB" ? (
                    <Paperclip className="h-3.5 w-3.5" />
                  ) : (
                    <FileText className="h-3.5 w-3.5" />
                  )}
                </ItemMedia>
                <ItemContent>
                  <ItemTitle className="text-[13px] leading-tight">
                    {attachment.nombre_documento ?? "Adjunto sin nombre"}
                  </ItemTitle>
                  <p className="mt-0.5 truncate text-[10px] uppercase tracking-[0.14em] text-slate-500">
                    {attachment.nombre_alternativo ?? "Sin alias"} · {attachment.TipoAlmacenamiento ?? "N/D"}
                  </p>
                </ItemContent>
                {active ? (
                  <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-sky-400" />
                ) : null}
              </button>
            </Item>
          );
        })}
      </div>
    </section>
  );
}
