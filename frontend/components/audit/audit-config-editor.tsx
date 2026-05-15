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
} from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { AddFieldFromDispensaDialog } from "@/components/audit/add-field-from-dispensa-dialog";
import { saveAuditConfig, type AuditConfigPayload } from "@/lib/api/audfact";
import type { AuditConfig } from "@/lib/schemas/domain";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// ─── Types ──────────────────────────────────────────────────────────────────

type FieldToggle = {
  campoNombre: string;
  tipoCampo: string;
  tipoDato?: string;
  enabled: boolean;
  orden: number;
  descripcionOverride?: string;
  severityOverride?: string;
};

type DocState = {
  docId: number;
  docName: string;
  fields: FieldToggle[];
};

type VisualCheckOption = {
  campoNombre: string;
  label: string;
  description: string;
  severity: "ALTA" | "MEDIA" | "BAJA";
};

type TipoCampoValue = "E" | "S" | "B";

type TipoDatoOption = {
  value: string;
  label: string;
  tipoCampos: readonly TipoCampoValue[];
};

// ─── Doc icon map ────────────────────────────────────────────────────────────

const docIcons: Record<string, typeof FileText> = {
  DISPENSA: Pill,
  AUTORIZACION: ClipboardCheck,
  "FORMULA MEDICA": FileText,
};

const visualCheckOptions: VisualCheckOption[] = [
  {
    campoNombre: "FirmaActaEntrega",
    label: "Firma acta de entrega",
    description: "Verificar que el acta o soporte de entrega tenga firma de recibido.",
    severity: "ALTA",
  },
  {
    campoNombre: "VigenciaEntrega",
    label: "Vigencia de entrega",
    description:
      "Verificar que el documento indique la vigencia o plazo de entrega autorizado; extraer dias y fecha base si estan visibles.",
    severity: "ALTA",
  },
  {
    campoNombre: "FirmaPrescriptor",
    label: "Firma prescriptor",
    description: "Verificar que la formula medica tenga firma del prescriptor.",
    severity: "ALTA",
  },
];

const tipoDatoOptions: readonly TipoDatoOption[] = [
  { value: "text", label: "Texto", tipoCampos: ["E", "S"] },
  { value: "date", label: "Fecha", tipoCampos: ["E"] },
  { value: "quantity", label: "Cantidad", tipoCampos: ["E", "B"] },
  { value: "money", label: "Dinero", tipoCampos: ["E"] },
  { value: "identity_doc_type", label: "Tipo doc.", tipoCampos: ["E"] },
  { value: "identity_doc_number", label: "Documento", tipoCampos: ["E"] },
  { value: "code", label: "Código", tipoCampos: ["E"] },
  { value: "trace_token", label: "Trazabilidad", tipoCampos: ["E"] },
  { value: "person_name", label: "Persona", tipoCampos: ["E", "S"] },
  { value: "institution_name", label: "Institución", tipoCampos: ["E", "S"] },
  { value: "article_name", label: "Artículo", tipoCampos: ["E", "S"] },
];

function sameFieldName(left: string, right: string): boolean {
  return left.trim().toLowerCase() === right.trim().toLowerCase();
}

function normalizeSeverity(value?: string | null, fallback: "ALTA" | "MEDIA" | "BAJA" = "ALTA") {
  const normalized = value?.trim().toUpperCase();
  return normalized === "ALTA" || normalized === "MEDIA" || normalized === "BAJA"
    ? normalized
    : fallback;
}

function normalizeTipoCampo(value: string): TipoCampoValue | null {
  const normalized = value.trim().toUpperCase();
  return normalized === "E" || normalized === "S" || normalized === "B" ? normalized : null;
}

function isTipoDatoAllowed(tipoCampo: string, tipoDato?: string) {
  if (!tipoDato) return false;
  return tipoDatoOptionsFor(tipoCampo).some((option) => option.value === tipoDato);
}

function tipoDatoOptionsFor(tipoCampo: string) {
  const normalizedTipoCampo = normalizeTipoCampo(tipoCampo);
  return normalizedTipoCampo === null
    ? tipoDatoOptions
    : tipoDatoOptions.filter((option) => option.tipoCampos.includes(normalizedTipoCampo));
}

function fieldValidationError(field: FieldToggle): string | null {
  if (!field.enabled || field.tipoCampo === "V") return null;
  if (!field.tipoDato) return "Define el tipo de dato.";
  if (!isTipoDatoAllowed(field.tipoCampo, field.tipoDato)) {
    return "Combinación tipo/comparación inválida.";
  }
  return null;
}

// ─── Main Component ──────────────────────────────────────────────────────────

export function AuditConfigEditor({
  config,
  clientId,
}: {
  config: AuditConfig;
  clientId: string;
}) {
  const [activeTab, setActiveTab] = React.useState<string>("");
  const [docs, setDocs] = React.useState<DocState[]>([]);
  const [systemPrompt, setSystemPrompt] = React.useState(config.systemPrompt ?? "");
  const [saving, setSaving] = React.useState(false);
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [addFieldDialogOpen, setAddFieldDialogOpen] = React.useState(false);
  const [dirty, setDirty] = React.useState(false);

  React.useEffect(() => {
    const docEntries = Object.entries(config.documents).map(([docName, doc]) => {
      // Unify data fields
      const dataFields: FieldToggle[] = doc.fields.map((f) => {
        let tipo = (f.tipoCampo || "E").trim().toUpperCase();
        
        return {
          campoNombre: f.campoNombre,
          tipoCampo: tipo,
          tipoDato: f.tipoDato ?? "",
          enabled: f.enabled,
          orden: f.orden,
          descripcionOverride: f.descripcionOverride ?? f.description ?? undefined,
          severityOverride: normalizeSeverity(f.severityOverride ?? f.severity),
        };
      });

      // Unify visual checks as type 'V'
      const visualFields: FieldToggle[] = doc.visualChecks.map((v) => ({
        campoNombre: v.check,
        tipoCampo: "V",
        enabled: v.enabled,
        orden: v.orden,
        descripcionOverride: v.description ?? undefined,
        severityOverride: normalizeSeverity(v.severity),
      }));

      // Deduplicate: If it exists as Visual, we don't need the Data version (usually for signatures)
      const visualNames = new Set(visualFields.map((v) => v.campoNombre.toLowerCase()));
      const filteredDataFields = dataFields.filter(
        (df) => !visualNames.has(df.campoNombre.toLowerCase()),
      );

      return {
        docId: doc.docId,
        docName,
        fields: [...filteredDataFields, ...visualFields].sort((a, b) => a.orden - b.orden),
      };
    });
    setDocs(docEntries);
    setActiveTab((current) => {
      if (docEntries.length === 0) return "";
      return docEntries.some((doc) => doc.docName === current)
        ? current
        : docEntries[0].docName;
    });
  }, [config]);

  const toggleField = (docName: string, campoNombre: string, tipoCampo: string) => {
    setDocs((prev) =>
      prev.map((d) =>
        d.docName === docName
          ? {
              ...d,
              fields: d.fields.map((f) =>
                f.campoNombre === campoNombre && f.tipoCampo === tipoCampo
                  ? { ...f, enabled: !f.enabled }
                  : f,
              ),
            }
          : d,
      ),
    );
    setDirty(true);
  };

  const updateField = (
    docName: string,
    campoNombre: string,
    tipoCampo: string,
    updates: Partial<FieldToggle>,
  ) => {
    setDocs((prev) =>
      prev.map((d) =>
        d.docName === docName
          ? {
              ...d,
              fields: d.fields.map((f) =>
                f.campoNombre === campoNombre && f.tipoCampo === tipoCampo
                  ? { ...f, ...updates }
                  : f,
              ),
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
        const existingNames = new Set(d.fields.map((f) => f.campoNombre.toLowerCase()));
        const newFields = fieldNames
          .filter((name) => !existingNames.has(name.toLowerCase()))
          .map((name, idx) => ({
            campoNombre: name,
            tipoCampo: "E",
            tipoDato: "",
            enabled: true,
            orden: d.fields.length + idx + 1,
          }));
        return { ...d, fields: [...d.fields, ...newFields] };
      }),
    );
    setDirty(true);
  };

  const toggleVisualCheckOption = (docName: string, option: VisualCheckOption) => {
    setDocs((prev) =>
      prev.map((d) => {
        if (d.docName !== docName) return d;

        const existingIndex = d.fields.findIndex(
          (f) => f.tipoCampo === "V" && sameFieldName(f.campoNombre, option.campoNombre),
        );

        if (existingIndex >= 0) {
          const existing = d.fields[existingIndex];
          if (existing.enabled) {
            return {
              ...d,
              fields: d.fields.filter((_, index) => index !== existingIndex),
            };
          }

          return {
            ...d,
            fields: d.fields.map((f, index) =>
              index === existingIndex
                ? {
                    ...f,
                    enabled: true,
                    descripcionOverride: f.descripcionOverride ?? option.description,
                    severityOverride: f.severityOverride ?? option.severity,
                  }
                : f,
            ),
          };
        }

        const nextOrden = d.fields.reduce((max, field) => Math.max(max, field.orden), 0) + 1;
        const visualField: FieldToggle = {
          campoNombre: option.campoNombre,
          tipoCampo: "V",
          enabled: true,
          orden: nextOrden,
          descripcionOverride: option.description,
          severityOverride: option.severity,
        };

        return { ...d, fields: [...d.fields, visualField] };
      }),
    );
    setDirty(true);
  };

  const removeField = (docName: string, campoNombre: string, tipoCampo: string) => {
    setDocs((prev) =>
      prev.map((d) =>
        d.docName === docName
          ? {
              ...d,
              fields: d.fields.filter(
                (f) => !(f.campoNombre === campoNombre && f.tipoCampo === tipoCampo),
              ),
            }
          : d,
      ),
    );
    setDirty(true);
  };

  const buildPayload = (): AuditConfigPayload => {
    const fields: AuditConfigPayload["fields"] = [];
    
    for (const doc of docs) {
      for (const f of doc.fields) {
        // Solo enviamos los habilitados (el backend hace DELETE + INSERT de lo que llega)
        if (!f.enabled) continue;

        fields.push({
          docId: doc.docId,
          campoNombre: f.campoNombre,
          tipoCampo: f.tipoCampo,
          tipoDato: f.tipoCampo === "V" ? null : (f.tipoDato ?? null),
          enabled: true,
          description: f.descripcionOverride ?? null,
          severity: f.severityOverride ?? null,
          orden: f.orden
        });
      }
    }
    
    return { 
      systemPrompt: systemPrompt.trim() || null, 
      fields 
    };
  };

  const handleSave = async () => {
    setConfirmOpen(false);
    const errors = validationErrors();
    if (errors.length > 0) {
      toast.error(errors[0]);
      return;
    }

    setSaving(true);
    try {
      await saveAuditConfig(clientId, buildPayload());
      toast.success("Configuración guardada correctamente");
      setDirty(false);
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : "Error al guardar la configuración",
      );
    } finally {
      setSaving(false);
    }
  };

  const validationErrors = React.useCallback(() => {
    const errors: string[] = [];
    for (const doc of docs) {
      for (const field of doc.fields) {
        const error = fieldValidationError(field);
        if (error !== null) {
          errors.push(`${doc.docName} · ${field.campoNombre}: ${error}`);
        }
      }
    }
    return errors;
  }, [docs]);

  const openConfirmIfValid = () => {
    const errors = validationErrors();
    if (errors.length > 0) {
      toast.error(errors[0]);
      return;
    }
    setConfirmOpen(true);
  };

  const activeDoc = docs.find((d) => d.docName === activeTab);
  const dataFields = activeDoc?.fields.filter((f) => f.tipoCampo !== "V") ?? [];
  const visualFields = activeDoc?.fields.filter((f) => f.tipoCampo === "V") ?? [];
  const selectedVisualCount = visualCheckOptions.filter((option) =>
    visualFields.some((field) => sameFieldName(field.campoNombre, option.campoNombre) && field.enabled),
  ).length;
  const enabledCount = activeDoc?.fields.filter((f) => f.enabled).length ?? 0;
  const totalCount = activeDoc?.fields.length ?? 0;

  const totalAllEnabled = docs.reduce(
    (acc, d) => acc + d.fields.filter((f) => f.enabled).length,
    0,
  );
  const totalAllFields = docs.reduce((acc, d) => acc + d.fields.length, 0);
  const disabledCount = totalAllFields - totalAllEnabled;
  const validationErrorCount = validationErrors().length;

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
          label="Cobertura"
          value={`${totalAllEnabled} activos · ${disabledCount} off`}
          tone="amber"
        />
      </div>

      {/* ── Document Tabs ───────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        {docs.map((doc) => {
          const Icon = docIcons[doc.docName] ?? FileText;
          const isActive = activeTab === doc.docName;
          const docEnabled = doc.fields.filter((f) => f.enabled).length;
          const docTotal = doc.fields.length;
          const pct = docTotal > 0 ? Math.round((docEnabled / docTotal) * 100) : 0;

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
                  <span>{docEnabled} de {docTotal} activos</span>
                  <span className="text-slate-700">·</span>
                  <span>{pct}% cobertura</span>
                </div>
              </div>
              {/* Progress bar */}
              <div className="h-1 w-full overflow-hidden rounded-full bg-white/[0.05]">
                <div
                  className={cn(
                    "h-full rounded-full transition-all duration-500",
                    pct === 100
                      ? "bg-emerald-500"
                      : isActive
                        ? "bg-sky-500"
                        : "bg-slate-600",
                  )}
                  style={{ width: `${pct}%` }}
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
              <h3 className="text-sm font-bold text-white">{activeDoc.docName}</h3>
              <p className="text-[11px] text-slate-500">
                Activa o desactiva campos y ajusta verificaciones visuales del documento seleccionado.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <InlineMetric label="Activos" value={`${enabledCount}`} />
              <InlineMetric label="Totales" value={`${totalCount}`} />
            </div>
          </div>

          <div className="flex items-center justify-between gap-3">
            <div className="text-[11px] text-slate-500">
              Los cambios se aplican solo a <span className="font-semibold text-slate-300">{activeDoc.docName}</span> hasta guardar.
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
                count={`${dataFields.filter((f) => f.enabled).length}/${dataFields.length}`}
                color="cyan"
              />
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {dataFields.map((field, index) => (
                  <FieldRow
                    key={`${field.tipoCampo}-${field.campoNombre}-${index}`}
                    field={field}
                    onToggle={() => toggleField(activeDoc.docName, field.campoNombre, field.tipoCampo)}
                    onRemove={() => removeField(activeDoc.docName, field.campoNombre, field.tipoCampo)}
                    onUpdate={(updates) => updateField(activeDoc.docName, field.campoNombre, field.tipoCampo, updates)}
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
              onToggle={(option) => toggleVisualCheckOption(activeDoc.docName, option)}
            />
            {visualFields.length > 0 && (
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {visualFields.map((field, index) => (
                  <VisualCheckRow
                    key={`V-${field.campoNombre}-${index}`}
                    field={field}
                    onToggle={() =>
                      toggleField(activeDoc.docName, field.campoNombre, "V")
                    }
                    onRemove={() =>
                      removeField(activeDoc.docName, field.campoNombre, "V")
                    }
                    onUpdate={(updates) =>
                      updateField(activeDoc.docName, field.campoNombre, "V", updates)
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
          Este prompt se inyecta como contexto adicional en cada auditoría del cliente.
          Déjalo vacío para usar el comportamiento por defecto del sistema.
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
            <span className="text-sm text-slate-600">Sin cambios pendientes</span>
          )}
        </div>
        <button
          type="button"
          onClick={openConfirmIfValid}
          disabled={saving || !dirty}
          className={cn(
            "inline-flex cursor-pointer items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold transition-all duration-200",
            dirty && !saving
              ? "bg-sky-500 text-white hover:brightness-110 active:scale-[0.97]"
              : "cursor-not-allowed bg-slate-800/40 text-slate-600",
          )}
        >
          {saving ? (
            <Spinner />
          ) : (
            <Save className="h-4 w-4" />
          )}
          {saving ? "Guardando..." : "Guardar cambios"}
        </button>
      </div>
      {validationErrorCount > 0 && (
        <p className="text-xs font-medium text-amber-300">
          {validationErrorCount} campo(s) activo(s) requieren corregir tipo de dato antes de guardar.
        </p>
      )}

      <ConfirmDialog
        open={confirmOpen}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={handleSave}
        title="Guardar configuración"
        description={`Se reemplazará la configuración de auditoría del cliente ${config.nitSec}. Los campos desactivados no serán evaluados en futuras auditorías.`}
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

function InlineMetric({
  label,
  value,
}: {
  label: string;
  value: string;
}) {
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
  options: VisualCheckOption[];
  selectedFields: FieldToggle[];
  onToggle: (option: VisualCheckOption) => void;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
      {options.map((option) => {
        const checkboxId = `visual-check-${option.campoNombre}`;
        const checked = selectedFields.some(
          (field) => sameFieldName(field.campoNombre, option.campoNombre) && field.enabled,
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
              aria-label={`Seleccionar ${option.label}`}
              className="mt-0.5"
            />
            <Label htmlFor={checkboxId} className="min-w-0 cursor-pointer space-y-1 normal-case tracking-normal">
              <span
                className={cn(
                  "block text-[12px] font-semibold",
                  checked ? "text-white" : "text-slate-400",
                )}
              >
                {option.label}
              </span>
              <span className="block text-[10px] leading-relaxed text-slate-600">
                {option.campoNombre}
              </span>
            </Label>
          </div>
        );
      })}
    </div>
  );
}

function FieldRow({
  field,
  onToggle,
  onRemove,
  onUpdate,
}: {
  field: FieldToggle;
  onToggle: () => void;
  onRemove: () => void;
  onUpdate: (u: Partial<FieldToggle>) => void;
}) {
  const switchId = React.useId();
  const validationError = fieldValidationError(field);
  const allowedTipoDatoOptions = tipoDatoOptionsFor(field.tipoCampo);

  return (
    <div
      className={cn(
        "group rounded-lg border transition-all duration-150",
        field.enabled
          ? "border-white/[0.08] bg-white/[0.03] hover:border-white/[0.12]"
          : "border-white/[0.03] bg-transparent opacity-40 hover:opacity-55",
      )}
    >
      <div className="flex items-center justify-between px-3 py-2.5">
        <div className="flex min-w-0 items-center gap-2.5">
          <Switch
            id={switchId}
            checked={field.enabled}
            onCheckedChange={() => onToggle()}
            aria-label={`Activar campo ${field.campoNombre}`}
          />
          <Label
            htmlFor={switchId}
            className={cn(
              "cursor-pointer truncate font-mono text-[12px] normal-case tracking-normal transition-colors",
              field.enabled ? "text-slate-300" : "text-slate-600 line-through",
            )}
          >
            {field.campoNombre}
          </Label>
          {field.enabled && field.tipoCampo !== "E" && (
            <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-cyan-400">
              {field.tipoCampo === "S" ? "Semántico" : field.tipoCampo === "B" ? "Negocio" : field.tipoCampo}
            </span>
          )}
          {field.enabled && field.tipoDato && (
            <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-slate-400">
              {tipoDatoOptions.find((option) => option.value === field.tipoDato)?.label ?? field.tipoDato}
            </span>
          )}
        </div>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={onRemove}
              aria-label={`Eliminar ${field.campoNombre}`}
              className="shrink-0 cursor-pointer rounded-lg p-1 text-slate-700 opacity-0 transition-all focus-visible:opacity-100 group-hover:opacity-100 hover:bg-rose-500/10 hover:text-rose-400"
            >
              <Trash2 className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent>Eliminar campo</TooltipContent>
        </Tooltip>
      </div>

      {field.enabled && (
        <div className="flex flex-col gap-2.5 border-t border-white/[0.06] px-3 pb-3 pt-2">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div className="space-y-1">
              <span className="text-[9px] font-bold uppercase tracking-widest text-slate-600">Tipo</span>
              <Select
                value={field.tipoCampo}
                onValueChange={(val) => {
                  const nextTipoDato = isTipoDatoAllowed(val, field.tipoDato) ? field.tipoDato : "";
                  onUpdate({ tipoCampo: val, tipoDato: nextTipoDato });
                }}
              >
                <SelectTrigger className="h-8 rounded-lg bg-background/50 text-[11px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="E">Exacto</SelectItem>
                  <SelectItem value="S">Semántico</SelectItem>
                  <SelectItem value="B">Negocio</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <span className="text-[9px] font-bold uppercase tracking-widest text-slate-600">Dato</span>
              <Select
                value={field.tipoDato ?? ""}
                onValueChange={(val) => onUpdate({ tipoDato: val })}
              >
                <SelectTrigger
                  className={cn(
                    "h-8 rounded-lg bg-background/50 text-[11px]",
                    validationError ? "border-amber-400/50" : "",
                  )}
                >
                  <SelectValue placeholder="Requerido" />
                </SelectTrigger>
                <SelectContent>
                  {allowedTipoDatoOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <span className="text-[9px] font-bold uppercase tracking-widest text-slate-600">Severidad</span>
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
          </div>
          {validationError && (
            <p className="text-[11px] font-medium text-amber-300">
              {validationError}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function VisualCheckRow({
  field,
  onToggle,
  onRemove,
  onUpdate,
}: {
  field: FieldToggle;
  onToggle: () => void;
  onRemove: () => void;
  onUpdate: (u: Partial<FieldToggle>) => void;
}) {
  const switchId = React.useId();

  return (
    <div
      className={cn(
        "group rounded-lg border transition-all duration-150",
        field.enabled
          ? "border-white/[0.08] bg-white/[0.03] hover:border-white/[0.12]"
          : "border-white/[0.03] bg-transparent opacity-40 hover:opacity-55",
      )}
    >
      {/* Row header */}
      <div className="flex items-center justify-between px-4 py-3">
        <div className="flex min-w-0 items-center gap-2.5">
          <Switch
            id={switchId}
            checked={field.enabled}
            onCheckedChange={() => onToggle()}
            aria-label={`Activar verificación visual ${field.campoNombre}`}
          />
          <Label
            htmlFor={switchId}
            className={cn(
              "cursor-pointer truncate font-mono text-[12px] normal-case tracking-normal transition-colors",
              field.enabled ? "text-slate-300" : "text-slate-600 line-through",
            )}
          >
            {field.campoNombre}
          </Label>
          <span className="shrink-0 rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-violet-400">
            Visual
          </span>
        </div>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={onRemove}
              aria-label={`Eliminar ${field.campoNombre}`}
              className="shrink-0 cursor-pointer rounded-lg p-1 text-slate-700 opacity-0 transition-all focus-visible:opacity-100 group-hover:opacity-100 hover:bg-rose-500/10 hover:text-rose-400"
            >
              <Trash2 className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent>Eliminar verificación</TooltipContent>
        </Tooltip>
      </div>

      {/* Expanded options when enabled */}
      {field.enabled && (
        <div className="flex flex-col gap-3 border-t border-white/[0.06] px-4 pb-4 pt-3">
          <Field>
            <FieldLabel htmlFor={`desc-${field.campoNombre}`} className="text-[10px] text-slate-600">
              Descripción / Hint
            </FieldLabel>
            <Input
              id={`desc-${field.campoNombre}`}
              type="text"
              value={field.descripcionOverride ?? ""}
              onChange={(e) => onUpdate({ descripcionOverride: e.target.value })}
              placeholder="Ej: Verificar firma del médico tratante"
              className="h-10 rounded-xl bg-background/80 px-3"
            />
          </Field>
          <Field>
            <FieldLabel htmlFor={`sev-${field.campoNombre}`} className="text-[10px] text-slate-600">
              Severidad
            </FieldLabel>
            <Select
              value={field.severityOverride ?? "ALTA"}
              onValueChange={(value) =>
                onUpdate({ severityOverride: value })
              }
            >
              <SelectTrigger id={`sev-${field.campoNombre}`} className="h-10 rounded-xl">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ALTA">Alta</SelectItem>
                <SelectItem value="MEDIA">Media</SelectItem>
                <SelectItem value="BAJA">Baja</SelectItem>
              </SelectContent>
            </Select>
          </Field>

        </div>
      )}
    </div>
  );
}
