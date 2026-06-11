"use client";

import * as React from "react";
import {
  Search,
  X,
  AlertTriangle,
  CheckCircle2,
  FileText,
  Package,
  Plus,
  ClipboardCheck,
  Pill,
} from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { getDispensationDetail } from "@/lib/api/audfact";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

import type { FieldCatalogItem } from "@/lib/schemas/domain";

// ─── Types ───────────────────────────────────────────────────────────────────

type DiscoveredField = {
  name: string;
  sampleValue: string;
  source: "header" | "item";
  selected: boolean;
  auditability: "auditable" | "blocked";
  reason?: string;
};

export type DocumentOption = {
  docId: number;
  docName: string;
  existingFields: string[];
};

type Props = {
  open: boolean;
  onClose: () => void;
  clientId: string;
  documents: DocumentOption[];
  initialDocName: string;
  onAddFields: (docName: string, fields: string[]) => void;
  catalog: FieldCatalogItem[];
};


// ─── Icons per document type ─────────────────────────────────────────────────
const DOC_ICONS: Record<string, React.ReactNode> = {
  DISPENSA: <FileText className="h-4 w-4" />,
  AUTORIZACION: <ClipboardCheck className="h-4 w-4" />,
  "FORMULA MEDICA": <Pill className="h-4 w-4" />,
};

const BLOCKED_FIELDS: Record<string, string> = {
  DisId: "Llave interna de base de datos",
  NitSec: "Identificador interno del cliente",
};

function getDocIcon(docName: string) {
  return DOC_ICONS[docName] ?? <FileText className="h-4 w-4" />;
}

function getFieldAuditability(name: string, catalog: FieldCatalogItem[]) {
  const reason = BLOCKED_FIELDS[name];
  if (reason) {
    return { auditability: "blocked" as const, reason };
  }
  const inCatalog = catalog.some((c) => c.campoNombre.toLowerCase() === name.toLowerCase() && !c.esVisual);
  if (!inCatalog) {
    return { auditability: "blocked" as const, reason: "No existe en el catálogo maestro." };
  }
  return { auditability: "auditable" as const, reason: undefined };
}

// ─── Component ───────────────────────────────────────────────────────────────

export function AddFieldFromDispensaDialog({
  open,
  onClose,
  clientId,
  documents,
  initialDocName,
  onAddFields,
  catalog,
}: Props) {
  const [invoiceNumber, setInvoiceNumber] = React.useState("");
  const [loading, setLoading] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [validated, setValidated] = React.useState(false);
  const [selectedDocName, setSelectedDocName] = React.useState(initialDocName);
  const [dispensaInfo, setDispensaInfo] = React.useState<{
    cliente: string;
    paciente: string;
    factura: string;
  } | null>(null);
  const [fields, setFields] = React.useState<DiscoveredField[]>([]);

  const inputRef = React.useRef<HTMLInputElement>(null);

  // Sync initialDocName when dialog opens
  React.useEffect(() => {
    if (open) {
      setSelectedDocName(initialDocName);
      setTimeout(() => inputRef.current?.focus(), 100);
    }
  }, [open, initialDocName]);

  // Reset when closing
  React.useEffect(() => {
    if (!open) {
      setInvoiceNumber("");
      setLoading(false);
      setError(null);
      setValidated(false);
      setDispensaInfo(null);
      setFields([]);
    }
  }, [open]);

  // Current document's existing fields
  const currentDoc = documents.find((d) => d.docName === selectedDocName);
  const existingFields = currentDoc?.existingFields ?? [];

  // Re-compute field selection when target doc changes
  React.useEffect(() => {
    if (!validated || fields.length === 0) return;
    const existingSet = new Set(existingFields.map((f) => f.toLowerCase()));
    setFields((prev) =>
      prev.map((f) => ({
        ...f,
        selected: existingSet.has(f.name.toLowerCase()) ? false : f.selected,
      })),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedDocName]);

  const handleSearch = async () => {
    const trimmed = invoiceNumber.trim();
    if (!trimmed) {
      setError("Ingresa un número de factura.");
      return;
    }

    setLoading(true);
    setError(null);
    setValidated(false);
    setDispensaInfo(null);
    setFields([]);

    try {
      const data = await getDispensationDetail(trimmed);

      if (!data) {
        setError("No se encontró información para esa factura.");
        setLoading(false);
        return;
      }

      // ── Validate ownership ─────────────────────────────────────────
      const header = data.header ?? {};
      const headerNitSec = String(header.NitSec ?? "");
      if (headerNitSec !== String(clientId)) {
        setError(
          `Esta factura pertenece al cliente ${headerNitSec} (${String(header.Cliente ?? "Desconocido")}), no al cliente ${clientId} que estás configurando.`,
        );
        setLoading(false);
        return;
      }

      // ── Extract info ───────────────────────────────────────────────
      setDispensaInfo({
        cliente: String(header.Cliente ?? ""),
        paciente: String(header.NombrePaciente ?? ""),
        factura: trimmed,
      });

      // ── Discover fields ────────────────────────────────────────────
      const discovered: DiscoveredField[] = [];
      const seen = new Set<string>();
      const existingSet = new Set(existingFields.map((f) => f.toLowerCase()));

      // Header fields
      for (const [key, value] of Object.entries(header)) {
        if (seen.has(key.toLowerCase())) continue;
        seen.add(key.toLowerCase());
        discovered.push({
          name: key,
          sampleValue: formatValue(value),
          source: "header",
          selected: !existingSet.has(key.toLowerCase()),
          ...getFieldAuditability(key, catalog),
        });
      }

      // Item fields (from first item)
      const items = data.items ?? [];
      const firstItem = items[0];
      if (firstItem) {
        for (const [key, value] of Object.entries(firstItem)) {
          if (seen.has(key.toLowerCase())) continue;
          seen.add(key.toLowerCase());
          discovered.push({
            name: key,
            sampleValue: formatValue(value),
            source: "item",
            selected: !existingSet.has(key.toLowerCase()),
            ...getFieldAuditability(key, catalog),
          });
        }
      }

      setFields(discovered);
      setValidated(true);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "No se pudo obtener la dispensación. Verifica el número de factura.",
      );
    } finally {
      setLoading(false);
    }
  };

  const toggleField = (name: string) => {
    setFields((prev) =>
      prev.map((f) => (f.name === name ? { ...f, selected: !f.selected } : f)),
    );
  };

  const toggleAll = (selected: boolean) => {
    const existingSet = new Set(existingFields.map((f) => f.toLowerCase()));
    setFields((prev) =>
      prev.map((f) => ({
        ...f,
        selected:
          existingSet.has(f.name.toLowerCase()) || f.auditability === "blocked"
            ? false
            : selected,
      })),
    );
  };

  const handleConfirm = () => {
    const selected = fields
      .filter((f) => f.selected && f.auditability === "auditable")
      .map((f) => f.name);
    if (selected.length === 0) {
      toast.warning("Selecciona al menos un campo para agregar.");
      return;
    }
    onAddFields(selectedDocName, selected);
    toast.success(
      `${selected.length} campo(s) agregado(s) a ${selectedDocName}.`,
    );
    onClose();
  };

  const existingSet = new Set(existingFields.map((e) => e.toLowerCase()));
  const availableHeaderFields = fields.filter((f) => f.source === "header" && !existingSet.has(f.name.toLowerCase()));
  const availableItemFields = fields.filter((f) => f.source === "item" && !existingSet.has(f.name.toLowerCase()));
  const existingFilteredFields = fields.filter((f) => existingSet.has(f.name.toLowerCase()));
  const blockedHeaderFields = availableHeaderFields.filter((f) => f.auditability === "blocked");
  const blockedItemFields = availableItemFields.filter((f) => f.auditability === "blocked");
  const selectableHeaderFields = availableHeaderFields.filter((f) => f.auditability === "auditable");
  const selectableItemFields = availableItemFields.filter((f) => f.auditability === "auditable");

  const selectedCount = fields.filter((f) => f.selected && f.auditability === "auditable").length;
  const alreadyExistCount = existingFilteredFields.length;
  const blockedCount = fields.filter((f) => f.auditability === "blocked").length;

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent
        showCloseButton={false}
        className="flex h-[94vh] w-[calc(100vw-1.5rem)] max-w-[1380px] flex-col gap-0 overflow-hidden rounded-[1.75rem] border-white/[0.08] bg-[#090e17] p-0 shadow-black/40 sm:w-[calc(100vw-2.5rem)]"
      >
        {/* Header - Minimal & Elegant */}
        <div className="flex shrink-0 items-center justify-between border-b border-white/[0.05] bg-slate-900/60 px-5 py-3.5 sm:px-6">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-white/[0.08] bg-white/[0.03]">
              <Search className="h-4.5 w-4.5 text-cyan-400" />
            </div>
            <div>
              <DialogTitle className="text-base font-bold tracking-wide text-white sm:text-lg">
                Descubrir campos
              </DialogTitle>
              <DialogDescription className="mt-0.5 text-[11px] font-medium leading-5 text-slate-400 sm:text-xs">
                Inspecciona una factura real para extraer sus parámetros.
              </DialogDescription>
            </div>
          </div>
          <div className="flex items-center gap-3 sm:gap-5">
            <div className="hidden shrink-0 items-center gap-2 rounded-full border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 sm:flex">
              <span className="text-[10px] font-bold uppercase tracking-widest text-cyan-500/70">
                NIT CLIENTE
              </span>
              <span className="font-mono text-xs font-bold text-cyan-300">
                {clientId}
              </span>
            </div>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={onClose}
                  className="h-10 w-10 rounded-full text-slate-500 hover:bg-slate-800 hover:text-white"
                  aria-label="Cerrar"
                >
                  <X className="h-4.5 w-4.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>Cerrar</TooltipContent>
            </Tooltip>
          </div>
        </div>

        {/* Search bar area */}
        <div className="shrink-0 border-b border-white/[0.04] bg-slate-900/30 px-5 py-3 sm:px-6">
          <div className="mx-auto flex max-w-4xl gap-2.5">
            <div className="relative flex-1 group">
              <FileText className="absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-slate-500 transition-colors group-focus-within:text-cyan-400" />
              <Input
                ref={inputRef}
                type="text"
                value={invoiceNumber}
                onChange={(e) => {
                  setInvoiceNumber(e.target.value.toUpperCase());
                  setError(null);
                }}
                onKeyDown={(e) => e.key === "Enter" && handleSearch()}
                placeholder="Ingresa número de factura (ej: T38250701547)..."
                className="h-11 bg-slate-950/50 pl-11 pr-4 font-mono sm:text-[15px]"
              />
            </div>
            <Button
              type="button"
              onClick={handleSearch}
              disabled={loading || !invoiceNumber.trim()}
              loading={loading}
              loadingLabel="Buscando"
              className={cn(
                "h-11 rounded-lg px-5 font-bold transition-transform duration-200 active:scale-[0.98] sm:px-7",
                loading || !invoiceNumber.trim()
                  ? "bg-slate-800/50 text-slate-500"
                  : "bg-cyan-500 text-slate-950 hover:bg-cyan-400",
              )}
            >
              <Search className="h-4.5 w-4.5" />
              Buscar Factura
            </Button>
          </div>
          
          {/* Error state */}
          {error && (
            <Alert
              variant="destructive"
              className="mx-auto mt-2.5 max-w-4xl animate-in fade-in slide-in-from-top-2"
            >
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-400" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
        </div>

        {loading && !validated ? (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-[#090e17] px-5 py-4 sm:px-6">
            <BackendRequestSkeleton
              description="El backend está leyendo la dispensación real y extrayendo campos auditables."
              title="Buscando factura"
              variant="detail"
            />
          </div>
        ) : null}

        {/* ── Post-search results ─────────────────────────────────────── */}
        {!loading && validated && dispensaInfo && (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-[#090e17] animate-in fade-in slide-in-from-bottom-4 duration-500">
            
            {/* Header Result + Tabs section */}
            <div className="relative z-10 shrink-0 border-b border-white/[0.05] bg-slate-900/40">
              {/* Verification Bar */}
              <div className="flex items-center gap-3 border-b border-white/[0.03] bg-emerald-500/[0.04] px-5 py-2 sm:px-6">
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/12">
                  <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                </div>
                <div className="flex flex-wrap items-center gap-2 text-[13px] sm:text-sm">
                  <span className="text-emerald-400/80 font-medium">Validación exitosa:</span>
                  <span className="font-mono font-bold text-emerald-300 px-2 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20">{dispensaInfo.factura}</span>
                  <span className="text-slate-600">·</span>
                  <span className="font-semibold text-slate-300">{dispensaInfo.cliente}</span>
                  <span className="text-slate-600">·</span>
                  <span className="text-slate-400">Paciente:</span>
                  <span className="font-medium text-emerald-300">{dispensaInfo.paciente}</span>
                </div>
              </div>

              {/* Destination Tabs */}
              <div className="flex h-12 items-end gap-5 px-5 pb-0 pt-1.5 sm:px-6 sm:gap-6">
                <span className="mb-2 shrink-0 text-[10px] font-bold uppercase tracking-widest text-slate-500 sm:text-[11px]">
                  Destino:
                </span>
                <Tabs
                  value={selectedDocName}
                  onValueChange={setSelectedDocName}
                  className="min-w-0 flex-1"
                >
                  <TabsList
                    aria-label="Documento destino"
                    className="h-12 min-h-0 max-w-full justify-start gap-1 overflow-x-auto rounded-none border-0 bg-transparent p-0"
                  >
                    {documents.map((doc) => (
                      <TabsTrigger
                        key={doc.docName}
                        value={doc.docName}
                        className="group h-full rounded-none border-0 bg-transparent px-4 pb-2.5 pt-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500 data-[state=active]:bg-transparent data-[state=active]:text-cyan-400 data-[state=active]:after:absolute data-[state=active]:after:bottom-0 data-[state=active]:after:left-0 data-[state=active]:after:right-0 data-[state=active]:after:h-0.5 data-[state=active]:after:rounded-t-full data-[state=active]:after:bg-cyan-400 sm:px-5 sm:text-xs"
                      >
                        <span className="text-slate-600 transition-colors group-data-[state=active]:text-cyan-400">
                          {getDocIcon(doc.docName)}
                        </span>
                        {doc.docName}
                      </TabsTrigger>
                    ))}
                  </TabsList>
                </Tabs>
              </div>
            </div>

            {/* ── Fields section ─────────────────────────────────────── */}
            {fields.length > 0 && (
              <div className="flex flex-1 flex-col overflow-hidden relative">
                {/* Controls Bar */}
                <div className="sticky top-0 z-10 flex shrink-0 items-center justify-between border-b border-white/[0.03] bg-slate-950/92 px-5 py-2.5 sm:px-6">
                  <div className="flex items-center gap-2">
                    <span className="rounded bg-cyan-500/10 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-cyan-400 sm:text-xs">
                      {selectedCount} seleccionados
                    </span>
                    {blockedCount > 0 && (
                      <span className="ml-1.5 text-[11px] font-medium text-amber-400/80 sm:text-xs">
                        · {blockedCount} no auditables
                      </span>
                    )}
                    {alreadyExistCount > 0 && (
                      <span className="ml-1.5 text-[11px] font-medium text-slate-500 sm:ml-2 sm:text-xs">
                        · {alreadyExistCount} ya configurados en {selectedDocName}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      onClick={() => toggleAll(true)}
                      variant="outline"
                      size="sm"
                      className="h-8 px-2.5 text-[11px] font-semibold text-cyan-400 hover:text-cyan-300 sm:px-3 sm:text-xs"
                    >
                      Seleccionar nuevos
                    </Button>
                    <span className="text-slate-700">·</span>
                    <Button
                      type="button"
                      onClick={() => toggleAll(false)}
                      variant="ghost"
                      size="sm"
                      className="h-8 px-2.5 text-[11px] font-semibold text-slate-400 hover:text-slate-300 sm:px-3 sm:text-xs"
                    >
                      Limpiar
                    </Button>
                  </div>
                </div>

                {/* Scrollable field list */}
                <div className="flex-1 overflow-y-auto px-5 py-3.5 sm:px-6">
                  
                  {/* Header fields section */}
                  {availableHeaderFields.length > 0 && (
                    <div className="mb-5">
                      <div className="mb-2.5 flex items-center gap-2">
                        <FileText className="h-4 w-4 text-cyan-400" />
                        <span className="text-[11px] font-bold uppercase tracking-widest text-slate-300">
                          Campos del encabezado
                        </span>
                      </div>
                      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {selectableHeaderFields.map((field) => (
                          <FieldOption
                            key={field.name}
                            field={field}
                            isExisting={false}
                            onToggle={() => toggleField(field.name)}
                          />
                        ))}
                        {blockedHeaderFields.map((field) => (
                          <FieldOption
                            key={field.name}
                            field={field}
                            isExisting={false}
                            onToggle={() => {}}
                          />
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Item fields section */}
                  {availableItemFields.length > 0 && (
                    <div className="mb-5">
                      <div className="mb-2.5 flex items-center gap-2">
                        <Package className="h-4 w-4 text-violet-400" />
                        <span className="text-[11px] font-bold uppercase tracking-widest text-slate-300">
                          Campos de artículos
                        </span>
                      </div>
                      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {selectableItemFields.map((field) => (
                          <FieldOption
                            key={field.name}
                            field={field}
                            isExisting={false}
                            onToggle={() => toggleField(field.name)}
                          />
                        ))}
                        {blockedItemFields.map((field) => (
                          <FieldOption
                            key={field.name}
                            field={field}
                            isExisting={false}
                            onToggle={() => {}}
                          />
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Existing fields section - Demoted to the bottom */}
                  {existingFilteredFields.length > 0 && (
                    <div className="mt-6 border-t border-white/[0.04] pt-5">
                      <div className="mb-2.5 flex items-center gap-2 opacity-60">
                        <CheckCircle2 className="h-4 w-4 text-slate-500" />
                        <span className="text-[11px] font-bold uppercase tracking-widest text-slate-500">
                          Campos ya existentes en {selectedDocName}
                        </span>
                      </div>
                      <div className="grid gap-2.5 opacity-50 transition-opacity hover:opacity-100 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {existingFilteredFields.map((field) => (
                          <FieldOption
                            key={field.name}
                            field={field}
                            isExisting={true}
                            onToggle={() => {}}
                          />
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Empty fields state */}
            {fields.length === 0 && (
              <div className="flex flex-col items-center justify-center flex-1 px-6 text-center animate-in fade-in">
                <div className="h-20 w-20 bg-slate-900/80 rounded-full flex items-center justify-center mb-4 ring-1 ring-white/5">
                  <Search className="h-8 w-8 text-slate-600" />
                </div>
                <p className="text-slate-400 font-medium">
                  No se encontraron campos en la dispensación.
                </p>
              </div>
            )}

            {/* Footer */}
            {fields.length > 0 && (
              <div className="flex shrink-0 items-center justify-between border-t border-white/[0.05] bg-slate-950/88 px-5 py-3.5 sm:px-6">
                <Button
                  type="button"
                  onClick={onClose}
                  variant="ghost"
                  className="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-400 hover:text-white"
                >
                  Cancelar
                </Button>
                <Button
                  type="button"
                  onClick={handleConfirm}
                  disabled={selectedCount === 0}
                  className={cn(
                    "rounded-xl px-6 py-2.5 text-sm font-bold transition-transform duration-200 shadow-xl active:scale-[0.98]",
                    selectedCount > 0
                      ? "bg-cyan-500 text-slate-950 hover:bg-cyan-400"
                      : "border border-white/[0.04] bg-slate-800/40 text-slate-600 shadow-none",
                  )}
                >
                    <Plus className="h-4.5 w-4.5" />
                  Agregar {selectedCount} campo(s) a {selectedDocName}
                </Button>
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

function FieldOption({
  field,
  isExisting,
  onToggle,
}: {
  field: DiscoveredField;
  isExisting: boolean;
  onToggle: () => void;
}) {
  const isBlocked = field.auditability === "blocked";
  const checkboxId = React.useId();
  const isDisabled = isExisting || isBlocked;
  const isChecked = (field.selected || isExisting) && !isBlocked;

  return (
    <div
      className={cn(
        "group flex w-full items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition-all duration-200",
        isExisting
          ? "cursor-default border-transparent bg-white/[0.02]"
          : isBlocked
            ? "cursor-default border-amber-500/20 bg-amber-500/[0.04]"
          : field.selected
            ? "border-cyan-500/30 bg-cyan-500/[0.08] hover:border-cyan-500/50"
            : "border-white/[0.04] bg-white/[0.02] hover:border-white/[0.1] hover:bg-white/[0.04]",
      )}
    >
      <Checkbox
        id={checkboxId}
        checked={isChecked}
        disabled={isDisabled}
        aria-label={`${field.name}${isBlocked ? " no auditable" : isExisting ? " ya configurado" : ""}`}
        onCheckedChange={() => {
          if (!isDisabled) onToggle();
        }}
        className={cn(
          "mt-0.5 border-cyan-500/40 data-[state=checked]:border-cyan-500 data-[state=checked]:bg-cyan-500 data-[state=checked]:text-slate-950",
          isExisting
            ? "border-slate-700 bg-slate-800 data-[state=checked]:border-slate-700 data-[state=checked]:bg-slate-800 data-[state=checked]:text-slate-500"
            : isBlocked
              ? "border-amber-500/30 bg-amber-500/10 text-amber-400"
              : "group-hover:border-cyan-400/70",
        )}
      />
      {isBlocked ? (
        <AlertTriangle className="-ml-1 mt-1 h-2.5 w-2.5 shrink-0 text-amber-400" aria-hidden="true" />
      ) : null}

      {/* Field info */}
      <Label
        htmlFor={checkboxId}
        className={cn(
          "block min-w-0 flex-1 text-left normal-case tracking-normal",
          isDisabled ? "cursor-default" : "cursor-pointer",
        )}
      >
        <div className="flex flex-wrap items-center gap-1.5 leading-none">
          <span
            className={cn(
              "truncate font-mono text-[11px] font-bold transition-colors sm:text-xs",
              isExisting
                ? "text-slate-600"
                : isBlocked
                  ? "text-amber-200"
                : field.selected
                  ? "text-cyan-300"
                  : "text-slate-300 group-hover:text-white",
            )}
            title={field.name}
          >
            {field.name}
          </span>
          {isExisting && (
            <span className="shrink-0 rounded bg-slate-800 px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-slate-500">
              Configurado
            </span>
          )}
          {isBlocked && (
            <span className="shrink-0 rounded border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-amber-300">
              No auditable
            </span>
          )}
        </div>
        {field.sampleValue && (
          <p className="mt-1 truncate text-[10px] font-medium text-slate-500 transition-colors group-hover:text-slate-400 sm:text-[11px]" title={field.sampleValue}>
            {field.sampleValue}
          </p>
        )}
        {isBlocked && field.reason && (
          <p className="mt-1.5 text-[10px] leading-relaxed text-amber-300/80">
            {field.reason}
          </p>
        )}
      </Label>
    </div>
  );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return "(vacío)";
  const str = String(value);
  return str.length > 60 ? `${str.slice(0, 57)}...` : str;
}
