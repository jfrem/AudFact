"use client";

import * as React from "react";
import {
  Save,
  Eye,
  Database,
  Plus,
  Trash2,
  FileText,
  ClipboardCheck,
  Pill,
  Sparkles,
  AlertCircle,
  CheckCircle2,
  Settings2,
} from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { AddFieldFromDispensaDialog } from "@/components/audit/add-field-from-dispensa-dialog";
import { saveAuditConfig, type AuditConfigPayload } from "@/lib/api/audfact";
import type { AuditConfig, FieldCatalogItem } from "@/lib/schemas/domain";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

// ─── Types ──────────────────────────────────────────────────────────────────

type FieldToggle = {
  campoNombre: string;
  tipoCampo: string;
  tipoDato?: string;
  orden: number;
  descripcionOverride?: string;
  severityOverride?: string;
  codigoCampo?: string;
  aplicaServicio?: string;
};

type DocState = {
  docId: number;
  docName: string;
  fields: FieldToggle[];
};

// ─── Doc icon map ────────────────────────────────────────────────────────────

const docIcons: Record<string, typeof FileText> = {
  DISPENSA: Pill,
  AUTORIZACION: ClipboardCheck,
  "FORMULA MEDICA": FileText,
};

const TIPO_DATO_LABELS: Record<string, string> = {
  text: "Texto",
  date: "Fecha",
  quantity: "Cantidad",
  money: "Dinero",
  identity_doc_type: "Tipo doc.",
  identity_doc_number: "Documento",
  code: "Código",
  trace_token: "Trazabilidad",
  person_name: "Persona",
  institution_name: "Institución",
  article_name: "Artículo",
};

export const APLICA_SERVICIO_OPTIONS = [
  {
    value: "TODOS",
    label: "Todos",
    description: "Auditar en todas las modalidades de entrega",
  },
  {
    value: "POS",
    label: "POS",
    description: "Auditar únicamente en entregas de tipo POS",
  },
  {
    value: "MIPRES",
    label: "MIPRES",
    description: "Auditar únicamente en entregas de tipo MIPRES",
  },
] as const;

function normalizeAplicaServicio(value?: string | null): string {
  const normalized = value?.trim().toUpperCase();
  return normalized === "POS" || normalized === "MIPRES" ? normalized : "TODOS";
}

function sameFieldName(left: string, right: string): boolean {
  return left.trim().toLowerCase() === right.trim().toLowerCase();
}

function normalizeSeverity(
  value?: string | null,
  fallback: "ALTA" | "MEDIA" | "BAJA" = "ALTA",
) {
  const normalized = value?.trim().toUpperCase();
  return normalized === "ALTA" ||
    normalized === "MEDIA" ||
    normalized === "BAJA"
    ? normalized
    : fallback;
}

// ─── Main Component ──────────────────────────────────────────────────────────

export function AuditConfigEditor({
  config,
  clientId,
  catalog,
}: {
  config: AuditConfig;
  clientId: string;
  catalog: FieldCatalogItem[];
}) {
  const visualCheckOptions = React.useMemo(
    () => catalog.filter((c) => c.esVisual),
    [catalog],
  );
  const router = useRouter();
  const [activeTab, setActiveTab] = React.useState<string>("");
  const [docs, setDocs] = React.useState<DocState[]>([]);
  const [systemPrompt, setSystemPrompt] = React.useState(
    config.systemPrompt ?? "",
  );
  const [saving, setSaving] = React.useState(false);
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [addFieldDialogOpen, setAddFieldDialogOpen] = React.useState(false);
  const [dirty, setDirty] = React.useState(false);

  React.useEffect(() => {
    const docEntries = Object.entries(config.documents).map(
      ([docName, doc]) => {
        // Unify data fields
        const dataFields: FieldToggle[] = doc.fields.map((f) => {
          const tipo = (f.tipoCampo || "E").trim().toUpperCase();

          return {
            campoNombre: f.campoNombre,
            tipoCampo: tipo,
            tipoDato: f.tipoDato ?? "",
            orden: f.orden,
            descripcionOverride:
              f.descripcionOverride ?? f.description ?? undefined,
            severityOverride: normalizeSeverity(
              f.severityOverride ?? f.severity,
            ),
            codigoCampo: f.codigoCampo ?? undefined,
            aplicaServicio: normalizeAplicaServicio(f.aplicaServicio),
          };
        });

        // Unify visual checks as type 'V'
        const visualFields: FieldToggle[] = doc.visualChecks.map((v) => ({
          campoNombre: v.check,
          tipoCampo: "V",
          orden: v.orden,
          descripcionOverride: v.description ?? undefined,
          severityOverride: normalizeSeverity(v.severity),
          codigoCampo: v.codigoCampo ?? undefined,
          aplicaServicio: normalizeAplicaServicio(v.aplicaServicio),
        }));

        // Deduplicate: If it exists as Visual, we don't need the Data version (usually for signatures)
        const visualNames = new Set(
          visualFields.map((v) => v.campoNombre.toLowerCase()),
        );
        const filteredDataFields = dataFields.filter(
          (df) => !visualNames.has(df.campoNombre.toLowerCase()),
        );

        return {
          docId: doc.docId,
          docName,
          fields: [...filteredDataFields, ...visualFields].sort(
            (a, b) => a.orden - b.orden,
          ),
        };
      },
    );
    setDocs(docEntries);
    setSystemPrompt(config.systemPrompt ?? "");
    setActiveTab((current) => {
      if (docEntries.length === 0) return "";
      return docEntries.some((doc) => doc.docName === current)
        ? current
        : docEntries[0].docName;
    });
  }, [config]);

  const updateField = (
    docName: string,
    campoNombre: string,
    updates: Partial<FieldToggle>,
  ) => {
    setDocs((prev) =>
      prev.map((d) =>
        d.docName === docName
          ? {
              ...d,
              fields: d.fields.map((f) =>
                f.campoNombre === campoNombre ? { ...f, ...updates } : f,
              ),
            }
          : d,
      ),
    );
    setDirty(true);
  };

  const removeField = (docName: string, campoNombre: string) => {
    setDocs((prev) =>
      prev.map((d) =>
        d.docName === docName
          ? {
              ...d,
              fields: d.fields.filter((f) => f.campoNombre !== campoNombre),
            }
          : d,
      ),
    );
    setDirty(true);
  };

  const addFieldsFromDispensa = (docName: string, fieldNames: string[]) => {
    setDocs((prev) =>
      prev.map((d) => {
        if (d.docName !== docName) return d;

        const existingNames = new Set(
          d.fields.map((f) => f.campoNombre.toLowerCase()),
        );
        const currentMaxOrden = d.fields.reduce(
          (max, f) => Math.max(max, f.orden),
          0,
        );

        const newFields: FieldToggle[] = [];
        let nextOrden = currentMaxOrden + 1;

        for (const name of fieldNames) {
          const lowerName = name.toLowerCase();
          if (existingNames.has(lowerName)) continue;

          const item = catalog.find((c) => sameFieldName(c.campoNombre, name));
          if (!item || item.esVisual) continue;

          newFields.push({
            campoNombre: item.campoNombre,
            tipoCampo: item.tipoCampo,
            tipoDato: item.tipoDato ?? "",
            orden: nextOrden++,
            codigoCampo: item.codigoCampo,
            aplicaServicio: "TODOS",
          });

          existingNames.add(lowerName);
        }

        if (newFields.length === 0) return d;

        return { ...d, fields: [...d.fields, ...newFields] };
      }),
    );
    setDirty(true);
  };

  const toggleVisualCheckOption = (
    docName: string,
    option: FieldCatalogItem,
  ) => {
    setDocs((prev) =>
      prev.map((d) => {
        if (d.docName !== docName) return d;

        const existingIndex = d.fields.findIndex(
          (f) =>
            f.tipoCampo === "V" &&
            sameFieldName(f.campoNombre, option.campoNombre),
        );

        if (existingIndex >= 0) {
          return {
            ...d,
            fields: d.fields.filter((_, index) => index !== existingIndex),
          };
        }

        const nextOrden =
          d.fields.reduce((max, field) => Math.max(max, field.orden), 0) + 1;
        const visualField: FieldToggle = {
          campoNombre: option.campoNombre,
          tipoCampo: "V",
          orden: nextOrden,
          descripcionOverride: option.descripcion ?? undefined,
          severityOverride: option.severidad,
          codigoCampo: option.codigoCampo,
          aplicaServicio: "TODOS",
        };

        return { ...d, fields: [...d.fields, visualField] };
      }),
    );
    setDirty(true);
  };

  const buildPayload = (): AuditConfigPayload => {
    const fields: AuditConfigPayload["fields"] = [];

    for (const doc of docs) {
      for (const f of doc.fields) {
        fields.push({
          docId: doc.docId,
          campoNombre: f.campoNombre,
          enabled: true,
          description: f.descripcionOverride ?? null,
          severity: f.severityOverride ?? null,
          orden: f.orden,
          aplicaServicio: normalizeAplicaServicio(f.aplicaServicio),
        });
      }
    }

    return {
      systemPrompt: systemPrompt.trim() || null,
      fields,
    };
  };

  const handleSave = async () => {
    setConfirmOpen(false);

    setSaving(true);
    try {
      await saveAuditConfig(clientId, buildPayload());
      toast.success("Configuración guardada correctamente");
      setDirty(false);
      router.refresh();
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "Error al guardar la configuración",
      );
    } finally {
      setSaving(false);
    }
  };

  const openConfirmIfValid = () => {
    setConfirmOpen(true);
  };

  const activeDoc = docs.find((d) => d.docName === activeTab);
  const dataFields = activeDoc?.fields.filter((f) => f.tipoCampo !== "V") ?? [];
  const visualFields =
    activeDoc?.fields.filter((f) => f.tipoCampo === "V") ?? [];
  const selectedVisualCount = visualCheckOptions.filter((option) =>
    visualFields.some((field) =>
      sameFieldName(field.campoNombre, option.campoNombre),
    ),
  ).length;
  const totalCount = activeDoc?.fields.length ?? 0;

  const totalAllFields = docs.reduce((acc, d) => acc + d.fields.length, 0);

  if (saving) {
    return (
      <div className="space-y-6">
        <BackendRequestSkeleton
          variant="detail"
          title="Guardando configuración"
          description="Sincronizando los campos y verificaciones visuales con el servidor..."
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* ── Stats Bar ──────────────────────────────────────────────── */}
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <StatCard label="Cliente" value={config.nitSec} tone="cyan" />
        <StatCard
          label="Estado"
          value={config.activo ? "Activo" : "Inactivo"}
          tone={config.activo ? "emerald" : "rose"}
          icon={config.activo ? CheckCircle2 : AlertCircle}
        />
        <StatCard
          label="Documentos"
          value={`${Object.keys(config.documents).length}`}
          tone="violet"
        />
        <StatCard
          label="Campos activos"
          value={`${totalAllFields} configurados`}
          tone="amber"
        />
      </div>

      {/* ── Document Tabs ───────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        {docs.map((doc) => {
          const Icon = docIcons[doc.docName] ?? FileText;
          const isActive = activeTab === doc.docName;
          const docTotal = doc.fields.length;
          const hasVisuals = doc.fields.some((f) => f.tipoCampo === "V");

          return (
            <button
              key={doc.docName}
              type="button"
              onClick={() => setActiveTab(doc.docName)}
              className={cn(
                "group relative flex cursor-pointer flex-col gap-3 overflow-hidden rounded-lg border p-5 text-left transition-all duration-200",
                isActive
                  ? "border-sky-500/25 bg-white/[0.04]"
                  : "border-white/[0.06] bg-white/[0.02] hover:border-white/[0.10] hover:bg-white/[0.04]",
              )}
            >
              {/* Active state styling relies purely on the solid background and border */}
              {/* Top row */}
              <div className="flex items-start justify-between">
                <div
                  className={cn(
                    "flex h-10 w-10 items-center justify-center rounded-xl transition-colors",
                    isActive
                      ? "border border-white/[0.08] bg-white/[0.03] text-cyan-400"
                      : "bg-white/[0.06] text-slate-500 group-hover:text-slate-400",
                  )}
                >
                  <Icon className="h-5 w-5" />
                </div>
                {isActive && (
                  <span className="h-2 w-2 rounded-full bg-cyan-400" />
                )}
              </div>
              {/* Label */}
              <div>
                <p
                  className={cn(
                    "text-sm font-semibold transition-colors",
                    isActive ? "text-white" : "text-slate-400",
                  )}
                >
                  {doc.docName}
                </p>
                <div className="mt-1 flex items-center gap-2 text-[11px] text-slate-600">
                  <span>{docTotal} campos activos</span>
                  {hasVisuals && (
                    <>
                      <span className="text-slate-700">·</span>
                      <span className="text-violet-400">
                        Verificaciones visuales
                      </span>
                    </>
                  )}
                </div>
              </div>
              {/* Indicator bar */}
              <div className="h-1 w-full overflow-hidden rounded-full bg-white/[0.05]">
                <div
                  className={cn(
                    "h-full rounded-full transition-all duration-500",
                    docTotal > 0
                      ? isActive
                        ? "bg-sky-500"
                        : "bg-emerald-500"
                      : "bg-slate-600",
                  )}
                  style={{ width: docTotal > 0 ? "100%" : "0%" }}
                />
              </div>
            </button>
          );
        })}
      </div>

      {/* ── Active Document Panel ────────────────────────────────────── */}
      {activeDoc && (
        <div className="space-y-5 rounded-3xl border border-white/[0.06] bg-white/[0.02] p-5 sm:p-6">
          {/* Panel header */}
          <div className="flex flex-col gap-3 border-b border-white/[0.05] pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="space-y-1">
              <h3 className="text-sm font-bold text-white">
                {activeDoc.docName}
              </h3>
              <p className="text-[11px] text-slate-500">
                Añade o elimina campos y ajusta verificaciones visuales del
                documento seleccionado.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <InlineMetric label="Campos activos" value={`${totalCount}`} />
            </div>
          </div>

          <div className="flex items-center justify-between gap-3">
            <div className="text-[11px] text-slate-500">
              Los cambios se aplican solo a{" "}
              <span className="font-semibold text-slate-300">
                {activeDoc.docName}
              </span>{" "}
              hasta guardar.
            </div>
            <button
              type="button"
              onClick={() => setAddFieldDialogOpen(true)}
              className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-dashed border-white/[0.12] bg-white/[0.03] px-3 py-1.5 text-xs font-medium text-cyan-400 transition hover:bg-white/[0.05]"
            >
              <Plus className="h-3.5 w-3.5" />
              Descubrir campos
            </button>
          </div>

          {/* ── Data Fields ─────────────────────────────────────────── */}
          {dataFields.length > 0 && (
            <div className="space-y-2.5">
              <SectionLabel
                icon={Database}
                text="Campos de datos"
                count={`${dataFields.length}`}
                color="cyan"
              />
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {dataFields.map((field, index) => (
                  <FieldRow
                    key={`${field.tipoCampo}-${field.campoNombre}-${index}`}
                    field={field}
                    onRemove={() =>
                      removeField(activeDoc.docName, field.campoNombre)
                    }
                    onUpdate={(updates) =>
                      updateField(activeDoc.docName, field.campoNombre, updates)
                    }
                  />
                ))}
              </div>
            </div>
          )}

          {/* ── Visual Checks ────────────────────────────────────────── */}
          <div className="space-y-2.5">
            <SectionLabel
              icon={Eye}
              text="Verificaciones visuales"
              count={`${selectedVisualCount}/${visualCheckOptions.length}`}
              color="violet"
            />
            <VisualCheckPicker
              options={visualCheckOptions}
              selectedFields={visualFields}
              onToggle={(option) =>
                toggleVisualCheckOption(activeDoc.docName, option)
              }
            />
            {visualFields.length > 0 && (
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {visualFields.map((field, index) => (
                  <VisualCheckRow
                    key={`V-${field.campoNombre}-${index}`}
                    field={field}
                    onRemove={() =>
                      removeField(activeDoc.docName, field.campoNombre)
                    }
                    onUpdate={(updates) =>
                      updateField(activeDoc.docName, field.campoNombre, updates)
                    }
                  />
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── System Prompt ────────────────────────────────────────────── */}
      <div className="rounded-3xl border border-white/[0.06] bg-white/[0.02] p-5 sm:p-6">
        <div className="mb-3 flex items-center gap-2">
          <Sparkles className="h-4 w-4 text-amber-400" />
          <span className="text-[11px] font-bold uppercase tracking-widest text-slate-400">
            System Prompt personalizado
          </span>
        </div>
        <Textarea
          value={systemPrompt}
          onChange={(e) => {
            setSystemPrompt(e.target.value);
            setDirty(true);
          }}
          rows={5}
          placeholder={`Instrucciones especiales para la IA al auditar dispensas del cliente ${config.nitSec}.\nEjemplo: "Verificar siempre que el diagnóstico coincida con el medicamento prescrito..."`}
          className="w-full resize-none rounded-lg border border-input bg-background/80 px-4 py-3 font-mono text-sm leading-relaxed text-slate-200 outline-none transition placeholder:text-slate-600 focus:ring-2 focus:ring-ring"
        />
        <p className="mt-2 text-[11px] leading-relaxed text-slate-600">
          Este prompt se inyecta como contexto adicional en cada auditoría del
          cliente. Déjalo vacío para usar el comportamiento por defecto del
          sistema.
        </p>
      </div>

      {/* ── Save Bar ─────────────────────────────────────────────────── */}
      <div
        className={cn(
          "flex items-center justify-between rounded-lg border px-5 py-4 transition-all duration-300",
          dirty
            ? "border-sky-500/20 bg-white/[0.04]"
            : "border-white/[0.06] bg-white/[0.02]",
        )}
      >
        <div className="flex items-center gap-2">
          {dirty ? (
            <>
              <span className="h-1.5 w-1.5 rounded-full bg-cyan-400" />
              <span className="text-sm text-cyan-300">Cambios sin guardar</span>
            </>
          ) : (
            <span className="text-sm text-slate-600">
              Sin cambios pendientes
            </span>
          )}
        </div>
        <Button
          type="button"
          onClick={openConfirmIfValid}
          disabled={saving || !dirty}
          loading={saving}
          loadingLabel="Guardando..."
          className={cn(
            "inline-flex cursor-pointer items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold transition-all duration-200",
            dirty && !saving
              ? "bg-sky-500 text-white hover:brightness-110 active:scale-[0.97]"
              : "cursor-not-allowed bg-slate-800/40 text-slate-600",
          )}
        >
          <Save className="h-4 w-4" />
          Guardar cambios
        </Button>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={handleSave}
        title="Guardar configuración"
        description={`Se reemplazará la configuración de auditoría del cliente ${config.nitSec}. Los campos que no estén en la lista no serán evaluados en futuras auditorías.`}
        confirmLabel="Confirmar y guardar"
        variant="info"
        loading={saving}
      />

      {activeDoc && (
        <AddFieldFromDispensaDialog
          open={addFieldDialogOpen}
          onClose={() => setAddFieldDialogOpen(false)}
          clientId={clientId}
          documents={docs.map((d) => ({
            docId: d.docId,
            docName: d.docName,
            existingFields: d.fields.map((f) => f.campoNombre),
          }))}
          initialDocName={activeDoc.docName}
          onAddFields={addFieldsFromDispensa}
          catalog={catalog}
        />
      )}
    </div>
  );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

function StatCard({
  label,
  value,
  tone = "cyan",
  icon: Icon,
}: {
  label: string;
  value: string;
  tone?: "cyan" | "emerald" | "violet" | "rose" | "amber";
  icon?: React.ElementType;
}) {
  const colors = {
    cyan: "border-white/[0.08] bg-white/[0.03] text-cyan-300",
    emerald: "border-white/[0.08] bg-white/[0.03] text-emerald-300",
    violet: "border-white/[0.08] bg-white/[0.03] text-violet-300",
    rose: "border-white/[0.08] bg-white/[0.03] text-rose-300",
    amber: "border-white/[0.08] bg-white/[0.03] text-amber-300",
  };
  return (
    <div
      className={`flex flex-col gap-1.5 rounded-lg border px-4 py-3.5 ${colors[tone]}`}
    >
      {Icon && <Icon className="h-4 w-4 opacity-60" />}
      <p className="text-[10px] font-semibold uppercase tracking-widest text-slate-500">
        {label}
      </p>
      <p className="text-lg font-bold">{value}</p>
    </div>
  );
}

function InlineMetric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2">
      <p className="text-[10px] font-semibold uppercase tracking-widest text-slate-500">
        {label}
      </p>
      <p className="text-sm font-bold text-white">{value}</p>
    </div>
  );
}

function SectionLabel({
  icon: Icon,
  text,
  count,
  color,
}: {
  icon: React.ElementType;
  text: string;
  count: string;
  color: "cyan" | "violet";
}) {
  const accent = {
    cyan: "text-cyan-400",
    violet: "text-violet-400",
  };
  return (
    <div className="flex items-center gap-2 px-0.5">
      <Icon className={`h-3.5 w-3.5 ${accent[color]}`} />
      <span className="text-[11px] font-bold uppercase tracking-widest text-slate-500">
        {text}
      </span>
      <span className="text-[10px] text-slate-700">({count})</span>
    </div>
  );
}

function VisualCheckPicker({
  options,
  selectedFields,
  onToggle,
}: {
  options: FieldCatalogItem[];
  selectedFields: FieldToggle[];
  onToggle: (option: FieldCatalogItem) => void;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
      {options.map((option) => {
        const checkboxId = `visual-check-${option.campoNombre}`;
        const checked = selectedFields.some((field) =>
          sameFieldName(field.campoNombre, option.campoNombre),
        );

        return (
          <div
            key={option.campoNombre}
            onClick={(event) => {
              const target = event.target as HTMLElement;
              if (target.closest("label")) return;
              onToggle(option);
            }}
            className={cn(
              "flex min-h-14 cursor-pointer items-start gap-3 rounded-lg border px-3 py-3 transition-all duration-150",
              checked
                ? "border-violet-400/30 bg-violet-500/10 text-slate-200"
                : "border-white/[0.06] bg-white/[0.02] text-slate-500 hover:border-white/[0.12] hover:bg-white/[0.04]",
            )}
          >
            <Checkbox
              id={checkboxId}
              checked={checked}
              onCheckedChange={(nextChecked) => {
                if (nextChecked !== checked) {
                  onToggle(option);
                }
              }}
              onClick={(event) => event.stopPropagation()}
              aria-label={`Seleccionar ${option.campoNombre}`}
              className="mt-0.5"
            />
            <Label
              htmlFor={checkboxId}
              className="min-w-0 cursor-pointer space-y-1 normal-case tracking-normal"
            >
              <span
                className={cn(
                  "block text-[12px] font-semibold",
                  checked ? "text-white" : "text-slate-400",
                )}
              >
                {option.campoNombre}
              </span>
              <span className="block text-[10px] leading-relaxed text-slate-600">
                {option.descripcion}
              </span>
            </Label>
          </div>
        );
      })}
    </div>
  );
}

function ServiceBadge({ service }: { service?: string }) {
  const normalized = normalizeAplicaServicio(service);
  if (normalized === "TODOS") return null;

  return (
    <span
      className={cn(
        "shrink-0 rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider transition-colors",
        normalized === "POS"
          ? "border-amber-500/30 bg-amber-500/10 text-amber-300/90 shadow-[0_0_10px_rgba(245,158,11,0.05)]"
          : "border-sky-500/30 bg-sky-500/10 text-sky-300/90 shadow-[0_0_10px_rgba(56,189,248,0.05)]",
      )}
    >
      {normalized === "POS" ? "POS" : "MIPRES"}
    </span>
  );
}

function ServiceSelect({
  value,
  onChange,
}: {
  value?: string;
  onChange: (val: string) => void;
}) {
  return (
    <div className="space-y-1">
      <span className="text-[10px] font-bold uppercase tracking-widest text-slate-500">
        Servicio
      </span>
      <Select value={normalizeAplicaServicio(value)} onValueChange={onChange}>
        <SelectTrigger className="h-8 rounded-lg border-white/[0.08] bg-background/50 text-[11px] text-slate-200 transition-colors hover:border-white/[0.16] hover:bg-background/80">
          <SelectValue />
        </SelectTrigger>
        <SelectContent className="border-white/[0.08] bg-[#0c1424]">
          {APLICA_SERVICIO_OPTIONS.map((opt) => (
            <SelectItem
              key={opt.value}
              value={opt.value}
              className="text-[11px] text-slate-300 focus:bg-white/[0.06] focus:text-white"
            >
              {opt.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

function FieldRow({
  field,
  onRemove,
  onUpdate,
}: {
  field: FieldToggle;
  onRemove: () => void;
  onUpdate: (u: Partial<FieldToggle>) => void;
}) {
  return (
    <div className="group rounded-lg border border-white/[0.08] bg-white/[0.03] transition-all duration-150 hover:border-white/[0.12]">
      <div className="flex items-center justify-between px-3 py-2.5">
        <div className="flex min-w-0 items-center gap-2">
          <Label className="truncate font-mono text-[12px] normal-case tracking-normal text-slate-300">
            {field.campoNombre}
          </Label>
          {field.tipoCampo !== "E" && (
            <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-cyan-400">
              {field.tipoCampo === "S"
                ? "Semántico"
                : field.tipoCampo === "B"
                  ? "Negocio"
                  : field.tipoCampo}
            </span>
          )}
          {field.tipoDato && (
            <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
              {TIPO_DATO_LABELS[field.tipoDato] ?? field.tipoDato}
            </span>
          )}
          <ServiceBadge service={field.aplicaServicio} />
        </div>
        <div className="flex items-center gap-1">
          <Dialog>
            <Tooltip>
              <TooltipTrigger asChild>
                <DialogTrigger asChild>
                  <button
                    type="button"
                    aria-label={`Configurar override de ${field.campoNombre}`}
                    className="shrink-0 flex h-7 w-7 items-center justify-center cursor-pointer rounded-lg text-slate-500 opacity-100 transition-all hover:bg-slate-800 hover:text-slate-300 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                  >
                    <Settings2 className="h-3.5 w-3.5" />
                  </button>
                </DialogTrigger>
              </TooltipTrigger>
              <TooltipContent>Configurar Prompt Específico</TooltipContent>
            </Tooltip>
            <DialogContent className="sm:max-w-[425px]">
              <DialogHeader>
                <DialogTitle>Configurar {field.campoNombre}</DialogTitle>
                <DialogDescription>
                  Ajusta cómo la IA debe interpretar o extraer este campo para
                  este cliente en específico.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4 py-4">
                <div className="space-y-2">
                  <Label
                    htmlFor={`desc-${field.campoNombre}`}
                    className="text-xs"
                  >
                    Prompt Específico / Descripción
                  </Label>
                  <Textarea
                    id={`desc-${field.campoNombre}`}
                    value={field.descripcionOverride ?? ""}
                    onChange={(e) =>
                      onUpdate({ descripcionOverride: e.target.value })
                    }
                    placeholder="Instrucción especial (sobrescribe la del catálogo)..."
                    className="min-h-[120px] resize-none bg-background/50 font-mono text-xs text-slate-300"
                  />
                </div>
              </div>
              <DialogFooter>
                <DialogClose asChild>
                  <Button type="button">Listo</Button>
                </DialogClose>
              </DialogFooter>
            </DialogContent>
          </Dialog>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={onRemove}
                aria-label={`Eliminar ${field.campoNombre}`}
                className="shrink-0 flex h-7 w-7 items-center justify-center cursor-pointer rounded-lg text-slate-700 opacity-100 transition-all hover:bg-rose-500/10 hover:text-rose-400 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>Eliminar campo</TooltipContent>
          </Tooltip>
        </div>
      </div>

      <div className="flex flex-col gap-2.5 border-t border-white/[0.06] px-3 pb-3 pt-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-1">
            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-600">
              Tipo
            </span>
            <div className="flex h-8 items-center rounded-lg bg-background/50 px-2.5 text-[11px] text-slate-400">
              {field.tipoCampo === "S"
                ? "Semántico"
                : field.tipoCampo === "B"
                  ? "Negocio"
                  : "Exacto"}
            </div>
          </div>
          <div className="space-y-1">
            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-600">
              Dato
            </span>
            <div className="flex h-8 items-center rounded-lg bg-background/50 px-2.5 text-[11px] text-slate-400">
              {TIPO_DATO_LABELS[field.tipoDato ?? ""] ?? field.tipoDato}
            </div>
          </div>
          <div className="space-y-1">
            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-600">
              Severidad
            </span>
            <Select
              value={field.severityOverride ?? "ALTA"}
              onValueChange={(val) => onUpdate({ severityOverride: val })}
            >
              <SelectTrigger className="h-8 rounded-lg bg-background/50 text-[11px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ALTA">Alta</SelectItem>
                <SelectItem value="MEDIA">Media</SelectItem>
                <SelectItem value="BAJA">Baja</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <ServiceSelect
            value={field.aplicaServicio}
            onChange={(val) => onUpdate({ aplicaServicio: val })}
          />
        </div>
      </div>
    </div>
  );
}

function VisualCheckRow({
  field,
  onRemove,
  onUpdate,
}: {
  field: FieldToggle;
  onRemove: () => void;
  onUpdate: (u: Partial<FieldToggle>) => void;
}) {
  return (
    <div className="group rounded-lg border border-white/[0.08] bg-white/[0.03] transition-all duration-150 hover:border-white/[0.12]">
      {/* Row header */}
      <div className="flex items-center justify-between px-4 py-3">
        <div className="flex min-w-0 items-center gap-2.5">
          <Label className="truncate font-mono text-[12px] normal-case tracking-normal text-slate-300">
            {field.campoNombre}
          </Label>
          <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-violet-400">
            Visual
          </span>
          <ServiceBadge service={field.aplicaServicio} />
        </div>
        <div className="flex items-center gap-1">
          <Dialog>
            <Tooltip>
              <TooltipTrigger asChild>
                <DialogTrigger asChild>
                  <button
                    type="button"
                    aria-label={`Configurar override de ${field.campoNombre}`}
                    className="shrink-0 flex h-7 w-7 items-center justify-center cursor-pointer rounded-lg text-slate-500 opacity-100 transition-all hover:bg-slate-800 hover:text-slate-300 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                  >
                    <Settings2 className="h-3.5 w-3.5" />
                  </button>
                </DialogTrigger>
              </TooltipTrigger>
              <TooltipContent>Configurar Hint Visual</TooltipContent>
            </Tooltip>
            <DialogContent className="sm:max-w-[425px]">
              <DialogHeader>
                <DialogTitle>Configurar {field.campoNombre}</DialogTitle>
                <DialogDescription>
                  Ajusta la instrucción para la IA sobre esta verificación
                  visual.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4 py-4">
                <div className="space-y-2">
                  <Label
                    htmlFor={`desc-${field.campoNombre}`}
                    className="text-xs"
                  >
                    Descripción / Hint Visual
                  </Label>
                  <Textarea
                    id={`desc-${field.campoNombre}`}
                    value={field.descripcionOverride ?? ""}
                    onChange={(e) =>
                      onUpdate({ descripcionOverride: e.target.value })
                    }
                    placeholder="Ej: Verificar firma del médico tratante"
                    className="min-h-[120px] resize-none bg-background/50 font-mono text-xs text-slate-300"
                  />
                </div>
              </div>
              <DialogFooter>
                <DialogClose asChild>
                  <Button type="button">Listo</Button>
                </DialogClose>
              </DialogFooter>
            </DialogContent>
          </Dialog>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={onRemove}
                aria-label={`Eliminar ${field.campoNombre}`}
                className="shrink-0 flex h-7 w-7 items-center justify-center cursor-pointer rounded-lg text-slate-700 opacity-100 transition-all hover:bg-rose-500/10 hover:text-rose-400 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>Eliminar verificación</TooltipContent>
          </Tooltip>
        </div>
      </div>

      {/* Expanded options */}
      <div className="flex flex-col gap-2.5 border-t border-white/[0.06] px-3 pb-3 pt-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <div className="space-y-1">
            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-600">
              Severidad
            </span>
            <Select
              value={field.severityOverride ?? "ALTA"}
              onValueChange={(val) => onUpdate({ severityOverride: val })}
            >
              <SelectTrigger className="h-8 rounded-lg bg-background/50 text-[11px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ALTA">Alta</SelectItem>
                <SelectItem value="MEDIA">Media</SelectItem>
                <SelectItem value="BAJA">Baja</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <ServiceSelect
            value={field.aplicaServicio}
            onChange={(val) => onUpdate({ aplicaServicio: val })}
          />
        </div>
      </div>
    </div>
  );
}
