"use client";

import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import {
  CheckCircle2,
  Eye,
  FileWarning,
  Files,
  LoaderCircle,
} from "lucide-react";

import type {
  AttachmentRecord,
  AuditFinding,
  AuditResultRecord,
} from "@/lib/schemas/domain";
import { getAttachments, getDispensationDetail } from "@/lib/api/audfact";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { AuditStatusBadge } from "@/components/audit/status-badge";
import { SeverityBadge } from "@/components/shared/severity-badge";
import { AttachmentList } from "@/components/attachments/attachment-list";
import { AttachmentViewerPanel } from "@/components/attachments/attachment-viewer-panel";
import { formatDurationMs } from "@/lib/formatters";
import { ResultItemsTable } from "@/components/audit/result-items-table";
import { AuditTimingsPanel } from "@/components/audit/audit-timings-panel";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

type TabId = "incidencias" | "campos" | "rendimiento" | "adjuntos";

export function AuditResultDetailModal({
  record,
  open,
  onClose,
}: {
  record: AuditResultRecord;
  open: boolean;
  onClose: () => void;
}) {
  const [tab, setTab] = React.useState<TabId>("incidencias");
  const [selectedAttachment, setSelectedAttachment] = React.useState<
    AttachmentRecord | undefined
  >();

  const facNro = record.FacNro ?? "";
  const facNitSec = String(record.FacNitSec ?? "");

  const attachmentsQ = useQuery({
    queryKey: ["modal-attachments", facNro, facNitSec],
    queryFn: () => getAttachments(facNro, facNitSec),
    enabled: open && Boolean(facNro) && Boolean(facNitSec),
  });

  const dispensationQ = useQuery({
    queryKey: ["modal-dispensation", facNro],
    queryFn: () => getDispensationDetail(facNro),
    enabled: open && Boolean(facNro),
  });

  const attachments = attachmentsQ.data ?? [];
  const patientName = String(
    dispensationQ.data?.header.NombrePaciente ?? "Paciente no disponible",
  );

  React.useEffect(() => {
    if (!open) {
      return;
    }

    setTab("incidencias");
    setSelectedAttachment(undefined);
  }, [open]);

  React.useEffect(() => {
    if (tab === "adjuntos" && attachments.length > 0 && !selectedAttachment) {
      setSelectedAttachment(attachments[0]);
    }
  }, [tab, attachments, selectedAttachment]);

  const allFindings = record.HallazgosItems ?? [];
  const findings = allFindings.filter((item) => item.status !== "MATCH");
  const fieldDecisions = record.CriticalFieldDecisions ?? [];
  const meta = record._meta;
  const findingCount = findings.length;
  const criticalCount = findings.filter((item) => item.severity === "CRITICO").length;
  const documentCount = Number(meta?.documentsProcessed ?? 0);
  const updatedAt = String(meta?.updatedAt ?? record.FechaActualizacion ?? "");

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent className="max-h-[96vh] w-[96vw] max-w-[1600px] gap-0 overflow-hidden rounded-xl border-white/10 bg-[#0d1724] p-0">
        <DialogHeader className="border-b border-white/8 px-5 py-4 pr-14 text-left sm:px-6 sm:pr-16">
          <div className="space-y-3">
            {/* Title row */}
            <div className="flex flex-wrap items-center gap-2.5">
              <DialogTitle className="[font-family:var(--font-heading)] text-lg font-semibold text-white sm:text-xl">
                {facNro || "Sin factura"}
              </DialogTitle>
              <AuditStatusBadge status={record.EstadoDetallado} />
              <SeverityBadge severity={record.Severidad} />
            </div>

            {/* Metadata bar: compact inline, replaces hero-metric cards */}
            <div className="flex flex-wrap gap-x-5 gap-y-1.5 text-[13px] text-slate-400">
              <span>
                <span className="text-slate-600">FacSec</span>{" "}
                <span className="font-mono text-[12px] text-slate-500">{String(record.FacSec)}</span>
              </span>
              <span>
                <span className="text-slate-600">Cliente</span>{" "}
                {facNitSec || "N/D"}
              </span>
              <span>
                <span className="text-slate-600">Paciente</span>{" "}
                {patientName}
              </span>
              <span className="hidden sm:inline text-slate-600">·</span>
              <span>
                <span className="text-slate-600">Incidencias</span>{" "}
                <span className="tabular-nums text-slate-300">{findingCount}</span>
                {criticalCount > 0 && (
                  <span className="ml-1 text-rose-400">({criticalCount} crít.)</span>
                )}
              </span>
              <span>
                <span className="text-slate-600">Docs</span>{" "}
                <span className="tabular-nums text-slate-300">{documentCount > 0 ? documentCount : "—"}</span>
              </span>
              {(meta?.total_duration_ms ?? meta?.totalTimeMs) != null && (
                <span>
                  <span className="text-slate-600">IA</span>{" "}
                  <span className="tabular-nums text-slate-300">
                    {formatDurationMs(Number(meta?.total_duration_ms ?? meta?.totalTimeMs))}
                  </span>
                </span>
              )}
              {updatedAt && (
                <span className="text-slate-500 text-[12px]">{updatedAt}</span>
              )}
            </div>
          </div>
        </DialogHeader>

        <Tabs value={tab} onValueChange={(value) => setTab(value as TabId)} className="w-full">
          <TabsList className="w-full justify-start gap-2 rounded-none border-b border-white/8 bg-[#09111d]/60 px-5 py-2 sm:px-6">
            <TabsTrigger value="incidencias">Incidencias ({findingCount})</TabsTrigger>
            <TabsTrigger value="campos">Campos auditados ({fieldDecisions.length})</TabsTrigger>
            <TabsTrigger value="rendimiento">Rendimiento</TabsTrigger>
            <TabsTrigger value="adjuntos">Adjuntos ({attachments.length})</TabsTrigger>
          </TabsList>

          <div className="max-h-[calc(96vh-160px)] flex-1 overflow-y-auto p-4 scrollbar-thin sm:p-5">
            <TabsContent value="incidencias" className="mt-0">
              <FindingsTab findings={findings} />
            </TabsContent>
            <TabsContent value="campos" className="mt-0">
              <FieldDecisionsTab items={fieldDecisions} />
            </TabsContent>
            <TabsContent value="rendimiento" className="mt-0">
              <AuditTimingsPanel meta={record._meta} />
            </TabsContent>
            <TabsContent value="adjuntos" className="mt-0">
              <AttachmentsTab
                invoiceId={facNro}
                attachments={attachments}
                isLoading={attachmentsQ.isLoading}
                selected={selectedAttachment}
                onSelect={setSelectedAttachment}
              />
            </TabsContent>
          </div>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
}

function FindingsTab({ findings }: { findings: AuditFinding[] }) {
  if (findings.length === 0) {
    return (
      <div className="flex min-h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-emerald-500/20 bg-emerald-500/[0.04] px-6 text-center">
        <CheckCircle2 className="h-10 w-10 text-emerald-500/40" />
        <p className="mt-3 font-medium text-emerald-300">Sin incidencias</p>
        <p className="mt-1 text-sm text-slate-400">
          Todos los campos reportados por el motor coinciden con la fuente de verdad.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <section className="rounded-lg border border-white/8 bg-[#09111d]/40 px-4 py-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
          <span>{findings.length} incidencias estructuradas</span>
          <span>{findings.filter((item) => item.severity === "CRITICO").length} críticos</span>
          <span>{findings.filter((item) => item.status === "NOT_FOUND").length} no encontrados</span>
        </div>
      </section>
      <ResultItemsTable items={findings} />
    </div>
  );
}

function FieldDecisionsTab({ items }: { items: AuditFinding[] }) {
  if (items.length === 0) {
    return (
      <div className="flex min-h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-white/10 bg-[#09111d]/30 px-6 text-center">
        <FileWarning className="h-10 w-10 text-slate-500/40" />
        <p className="mt-3 font-medium text-slate-300">Sin campos persistidos</p>
        <p className="mt-1 text-sm text-slate-400">
          El backend no persistió decisiones de campos para este registro.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-white/8 bg-[#09111d]/30">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Campo</TableHead>
            <TableHead>Documento</TableHead>
            <TableHead>Estado</TableHead>
            <TableHead>Esperado</TableHead>
            <TableHead>Observado</TableHead>
            <TableHead>Severidad</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.map((item, index) => (
            <TableRow key={`${item.field}-${index}`}>
              <TableCell className="font-medium text-white" title={item.field}>{item.field}</TableCell>
              <TableCell className="text-slate-400 text-xs" title={item.documento ?? "N/D"}>
                {item.documento ? (
                  <span className="inline-flex items-center rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-slate-300">
                    {item.documento}
                  </span>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell title={item.status}>
                <FieldStatusLabel status={item.status} />
              </TableCell>
              <TableCell className="max-w-[220px] truncate font-mono text-xs text-slate-400" title={item.expected_value ?? "N/D"}>
                {item.expected_value ?? "—"}
              </TableCell>
              <TableCell className="max-w-[220px] truncate font-mono text-xs text-slate-400" title={item.observed_value ?? "N/D"}>
                {item.observed_value ?? "—"}
              </TableCell>
              <TableCell title={item.severity}>
                <SeverityBadge severity={item.severity} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/** Semantic coloring for field comparison status */
function FieldStatusLabel({ status }: { status: string }) {
  const config: Record<string, { label: string; className: string }> = {
    MATCH: { label: "Coincide", className: "text-emerald-400" },
    DISCREPANCY: { label: "Discrepancia", className: "text-rose-400" },
    NOT_FOUND: { label: "No encontrado", className: "text-amber-400" },
  };
  const entry = config[status] ?? { label: status, className: "text-slate-400" };
  return <span className={`text-xs font-medium ${entry.className}`}>{entry.label}</span>;
}

function AttachmentsTab({
  invoiceId,
  attachments,
  isLoading,
  selected,
  onSelect,
}: {
  invoiceId: string;
  attachments: AttachmentRecord[];
  isLoading: boolean;
  selected?: AttachmentRecord;
  onSelect: (attachment: AttachmentRecord) => void;
}) {
  if (isLoading) {
    return (
      <div className="flex min-h-[240px] items-center justify-center rounded-lg border border-white/8 bg-[#09111d]/30 text-slate-400">
        <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
        Cargando adjuntos...
      </div>
    );
  }

  if (attachments.length === 0) {
    return (
      <div className="flex min-h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-white/10 bg-[#09111d]/30 px-6 text-center">
        <Eye className="h-10 w-10 text-slate-500/40" />
        <p className="mt-3 font-medium text-slate-300">Sin adjuntos</p>
        <p className="mt-1 text-sm text-slate-400">
          No se encontraron documentos adjuntos para esta dispensación.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <AttachmentList
        items={attachments}
        selectedId={selected?.id_documento ? String(selected.id_documento) : undefined}
        onSelect={onSelect}
        orientation="horizontal"
      />
      <AttachmentViewerPanel invoiceId={invoiceId} attachment={selected} />
    </div>
  );
}
