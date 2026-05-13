"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { X, Building2, Hash, AlertTriangle, Loader2, Settings2 } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { describeError } from "@/lib/api/errors";
import { getClientDocuments, saveAuditConfig } from "@/lib/api/audfact";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

export function CreateConfigDialog({
  open,
  initialNitSec,
  onClose,
}: {
  open: boolean;
  initialNitSec?: string;
  onClose: () => void;
}) {
  const router = useRouter();
  const [nitSec, setNitSec] = React.useState("");
  const [loading, setLoading] = React.useState(false);
  const inputRef = React.useRef<HTMLInputElement>(null);

  React.useEffect(() => {
    if (open) {
      setNitSec(initialNitSec ?? "");
      if (!initialNitSec) {
        setTimeout(() => inputRef.current?.focus(), 50);
      }
    }
  }, [open, initialNitSec]);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Escape") onClose();
  };

  const handleCreate = async () => {
    const trimmed = nitSec.trim();
    if (!trimmed) {
      toast.error("Ingresa el NitSec del cliente");
      return;
    }
    if (!/^\d+$/.test(trimmed)) {
      toast.error("El NitSec debe ser numérico");
      return;
    }
    setLoading(true);
    try {
      const documents = (await getClientDocuments(trimmed)) ?? [];
      if (documents.length === 0) {
        throw new Error("El cliente no tiene catálogo documental real para inicializar.");
      }

      await saveAuditConfig(trimmed, { systemPrompt: null, fields: [] });

      toast.success(`Configuración inicializada para cliente ${trimmed}`);
      onClose();
      router.push(`/clients/audit-config?clientId=${trimmed}`);
      router.refresh();
    } catch (err) {
      toast.error(describeError(err));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    /* Backdrop */
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center p-4 backdrop-animate"
      style={{ background: "rgba(0,0,0,0.72)" }}
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      {/* Modal */}
      <div
        className="modal-animate w-full max-w-md rounded-3xl border border-white/[0.08] bg-[#0d1526] shadow-2xl shadow-black/60"
        onKeyDown={handleKeyDown}
      >
        {/* Header */}
        <div className="flex items-start justify-between border-b border-white/[0.06] px-6 py-5">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-white/[0.08] bg-white/[0.03] text-cyan-400">
              <Settings2 className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-base font-semibold text-white">
                Nueva configuración
              </h2>
              <p className="text-xs text-slate-500">
                Inicializar campos auditables para un cliente
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="cursor-pointer rounded-lg p-1.5 text-slate-600 transition-colors hover:bg-white/[0.05] hover:text-slate-300"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Body */}
        <div className="space-y-5 px-6 py-6">
          {/* Notice */}
          <div className="flex gap-3 rounded-lg border border-amber-500/15 bg-amber-500/[0.05] px-4 py-3">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
            <p className="text-[13px] leading-relaxed text-amber-200/70">
              Se validará el catálogo documental del cliente y se creará una configuración sin campos
              precargados. Después podrás agregar campos desde una factura real o activar verificaciones visuales.
            </p>
          </div>

          {/* NitSec input */}
          <div className="space-y-1.5">
            <label htmlFor="nitsec-input" className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
              <Hash className="h-3 w-3" />
              NitSec del cliente
            </label>
            <div className="relative">
              <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                <Building2 className="h-4 w-4 text-slate-600" />
              </div>
              <Input
                id="nitsec-input"
                ref={inputRef}
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                value={nitSec}
                readOnly={!!initialNitSec}
                onChange={(e) => setNitSec(e.target.value.replace(/\D/g, ""))}
                onKeyDown={(e) => { if (e.key === "Enter") handleCreate(); }}
                placeholder="Ej: 2426"
                className={cn(
                  "h-12 rounded-xl bg-white/[0.03] pl-10 pr-4",
                  initialNitSec && "opacity-70 cursor-not-allowed bg-white/[0.05]"
                )}
              />
            </div>
            <p className="text-[11px] text-slate-700">
              El identificador numérico del cliente en el sistema
            </p>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 border-t border-white/[0.06] px-6 py-4">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={handleCreate}
            disabled={loading || !nitSec.trim()}
            className={cn(
              "gap-2 rounded-xl px-5 py-2.5",
              nitSec.trim() && !loading
                ? "bg-cyan-500 text-white hover:bg-cyan-400"
                : "cursor-not-allowed bg-slate-800/60 text-slate-600",
            )}
          >
            {loading ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Settings2 className="h-4 w-4" />
            )}
            {loading ? "Creando..." : "Crear configuración"}
          </Button>
        </div>
      </div>
    </div>
  );
}
