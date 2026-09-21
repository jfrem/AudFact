"use client";

import * as React from "react";
import { Building2, Hash, InfoIcon, Settings2 } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { describeError } from "@/lib/api/errors";
import { getClientDocuments, saveAuditConfig } from "@/lib/api/audfact";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

export function CreateConfigDialog({
  open,
  initialNitSec,
  onClose,
}: {
  open: boolean;
  initialNitSec?: string;
  onClose: () => void;
}) {
  const navigation = usePendingNavigation();
  const [nitSec, setNitSec] = React.useState("");
  const [loading, setLoading] = React.useState(false);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const isBusy = loading || navigation.isPending;

  React.useEffect(() => {
    if (open) {
      setNitSec(initialNitSec ?? "");
      if (!initialNitSec) {
        setTimeout(() => inputRef.current?.focus(), 50);
      }
    }
  }, [open, initialNitSec]);

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
      navigation.push(`/clients/audit-config?clientId=${trimmed}`);
      navigation.refresh();
    } catch (err) {
      toast.error(describeError(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent className="w-[calc(100vw-2rem)] max-w-md gap-0 overflow-hidden p-0">
        <DialogHeader className="border-b border-border px-6 py-5 pr-16">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-md border border-primary/30 bg-primary/10 text-primary">
              <Settings2 className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle className="text-base font-semibold text-foreground">
                Nueva configuración
              </DialogTitle>
              <DialogDescription className="text-xs leading-5 text-slate-500">
                Inicializar campos auditables para un cliente
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="space-y-5 px-6 py-6">
          {isBusy ? (
            <BackendRequestSkeleton
              description="Se valida el catálogo documental y se crea la configuración base."
              title="Creando configuración"
              variant="detail"
            />
          ) : (
            <>
              <Alert variant="info" role="status">
                <InfoIcon />
                <AlertDescription className="text-[13px] leading-relaxed">
                  Se validará el catálogo documental del cliente y se creará una configuración sin campos
                  precargados. Después podrás agregar campos desde una factura real o activar verificaciones visuales.
                </AlertDescription>
              </Alert>

              <Field>
                <FieldLabel htmlFor="nitsec-input" className="flex items-center gap-1.5">
                  <Hash className="h-3 w-3" />
                  NitSec del cliente
                </FieldLabel>
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
                    aria-describedby="nitsec-description"
                    value={nitSec}
                    readOnly={!!initialNitSec}
                    onChange={(e) => setNitSec(e.target.value.replace(/\D/g, ""))}
                    onKeyDown={(e) => { if (e.key === "Enter") handleCreate(); }}
                    placeholder="Ej: 2426"
                    className={cn(
                      "h-12 rounded-md bg-background pl-10 pr-4",
                      initialNitSec && "cursor-not-allowed bg-muted opacity-70"
                    )}
                  />
                </div>
                <FieldDescription id="nitsec-description" className="text-slate-700">
                  El identificador numérico del cliente en el sistema
                </FieldDescription>
              </Field>
            </>
          )}
        </div>

        <DialogFooter className="flex-row justify-end border-t border-border px-6 py-4">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={handleCreate}
            disabled={isBusy || !nitSec.trim()}
            loading={isBusy}
            loadingLabel="Creando..."
            className={cn(
              "gap-2 rounded-md px-5 py-2.5",
              nitSec.trim() && !isBusy
                ? "bg-primary text-primary-foreground hover:bg-primary/90"
                : "cursor-not-allowed bg-slate-800/60 text-slate-600",
            )}
          >
            <Settings2 className="h-4 w-4" />
            Crear configuración
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
