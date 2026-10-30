"use client";

import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import {
  CheckCircle2,
  Eye,
  FileWarning,
  AlertTriangle,
  Activity,
  FileText,
  Link2,
  Paperclip,
} from "lucide-react";
import { cn } from "@/lib/utils";
import Link from "next/link";

import { buttonVariants } from "@/components/ui/button";

import type {
  AttachmentRecord,
  AuditFinding,
  AuditResultDetail,
  AuditResultRecord,
} from "@/lib/schemas/domain";
import { getAttachments, getAuditCostByInvoice, getAuditResultDetail, getDispensationDetail } from "@/lib/api/audfact";
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
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { AttachmentList } from "@/components/attachments/attachment-list";
import { AttachmentViewerPanel } from "@/components/attachments/attachment-viewer-panel";
import { formatDurationMs } from "@/lib/formatters";
import { ResultItemsTable } from "@/components/audit/result-items-table";
import { AuditTimingsPanel } from "@/components/audit/audit-timings-panel";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
  const disId = String(record.DisId);

  const detailQ = useQuery<AuditResultDetail>({
    queryKey: ["audit-result-detail", facNro],
    queryFn: () => getAuditResultDetail(facNro),
    enabled: open && Boolean(facNro),
  });

  const costQ = useQuery({
    queryKey: ["audit-cost-detail", facNro],
    queryFn: () => getAuditCostByInvoice(facNro),
    enabled: open && Boolean(facNro),
  });

  const attachmentsQ = useQuery({
    queryKey: ["modal-attachments", facNro, facNitSec],
    queryFn: () => getAttachments(facNro, facNitSec),
    enabled: open && Boolean(facNro) && Boolean(facNitSec),
  });

  const dispensationQ = useQuery({
    queryKey: ["modal-dispensation", disId, facNro],
    queryFn: () => getDispensationDetail(disId, facNro),
    enabled: open && Boolean(facNro) && Boolean(disId),
  });

  const attachments = React.useMemo(
    () => attachmentsQ.data ?? [],
    [attachmentsQ.data],
  );
  const detail = detailQ.data;
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

  const allFindings = detail?.findings ?? [];
  const findings = allFindings.filter((item) => item.resultado !== "COINCIDE");
  const fieldDecisions = detail?.fieldDecisions ?? [];
  const findingCount = findings.length;
  const criticalCount = findings.filter((item) => normalizeSeverity(item.severidad) === "ALTA").length;
  const documentCount = Number((detail ?? record).DocumentosProcesados ?? 0);
  const updatedAt = String((detail ?? record).FechaActualizacion ?? "");

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent className="max-h-[96vh] w-[96vw] max-w-[1600px] gap-0 overflow-hidden p-0">
        <DialogHeader className="border-b border-border px-5 py-4 pr-14 text-left sm:px-6 sm:pr-16">
          <div className="space-y-3">
            {/* Title row */}
            <div className="flex flex-wrap items-center gap-2.5">
              <DialogTitle className="[font-family:var(--font-heading)] text-lg font-semibold text-foreground sm:text-xl">
                {facNro || "Sin factura"}
              </DialogTitle>
              <AuditStatusBadge status={record.EstadoDetallado} />
              <SeverityBadge severity={record.Severidad} />

              <div className="flex-1" />
              {facNro && (
                <Link
                  href={`/audit/flow/${encodeURIComponent(facNro)}`}
                  className={buttonVariants({ variant: "outline", size: "sm" })}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={`Ver trazabilidad de ${facNro}`}
                >
                  <Activity className="mr-2 h-4 w-4" aria-hidden="true" />
                  Ver trazabilidad
                </Link>
              )}
            </div>

            {/* Metadata bar: compact inline, replaces hero-metric cards */}
            <div className="flex flex-wrap gap-x-5 gap-y-1.5 text-[13px] text-slate-400">
              <span>
                <span className="text-slate-600">DisId</span>{" "}
                <span className="font-mono text-[12px] text-slate-500">{String(record.DisId)}</span>
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
                  <span className="ml-1 text-rose-400">({criticalCount} alta)</span>
                )}
              </span>
              <span>
                <span className="text-slate-600">Docs</span>{" "}
                <span className="tabular-nums text-slate-300">{documentCount > 0 ? documentCount : "—"}</span>
              </span>
              {Number((detail ?? record).DuracionProcesamientoMs ?? 0) > 0 && (
                <span>
                  <span className="text-slate-600">IA</span>{" "}
                  <span className="tabular-nums text-slate-300">
                    {formatDurationMs(Number((detail ?? record).DuracionProcesamientoMs ?? 0))}
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
          <TabsList className="w-full justify-start gap-2 rounded-none border-b border-border bg-muted/40 px-5 py-2 sm:px-6">
            <TabsTrigger value="incidencias">Incidencias ({findingCount})</TabsTrigger>
            <TabsTrigger value="campos">Campos auditados ({fieldDecisions.length})</TabsTrigger>
            <TabsTrigger value="rendimiento">Rendimiento</TabsTrigger>
            <TabsTrigger value="adjuntos">Adjuntos ({attachments.length})</TabsTrigger>
          </TabsList>

          <div className={cn("flex-1", tab === "adjuntos" ? "h-[calc(96vh-140px)] overflow-hidden p-0" : "max-h-[calc(96vh-160px)] overflow-y-auto p-4 scrollbar-thin sm:p-5")}>
            {detailQ.isError ? (
              <Alert variant="destructive" className="mb-4">
                <AlertTriangle />
                <AlertDescription>
                  No se pudo cargar el detalle persistido de esta auditoría.
                </AlertDescription>
              </Alert>
            ) : null}
            <TabsContent value="incidencias" className="mt-0">
              <FindingsTab findings={findings} isLoading={detailQ.isLoading} />
            </TabsContent>
            <TabsContent value="campos" className="mt-0">
              <FieldDecisionsTab items={fieldDecisions} isLoading={detailQ.isLoading} />
            </TabsContent>
            <TabsContent value="rendimiento" className="mt-0">
              <AuditTimingsPanel
                timings={detail?.timings ?? null}
                cost={costQ.data ?? null}
                totalDurationMs={Number((detail ?? record).DuracionProcesamientoMs ?? 0)}
                documentsProcessed={documentCount}
              />
            </TabsContent>
            <TabsContent value="adjuntos" className="mt-0 h-full">
              <AttachmentsTab
                disDetNro={facNro}
                attachments={attachments}
                isLoading={attachmentsQ.isLoading}
                error={
                  attachmentsQ.isError
                    ? attachmentsQ.error instanceof Error
                      ? attachmentsQ.error.message
                      : "No se pudieron cargar los adjuntos."
                    : null
                }
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

function FindingsTab({
  findings,
  isLoading,
}: {
  findings: AuditFinding[];
  isLoading: boolean;
}) {
  if (isLoading) {
    return (
      <BackendRequestSkeleton
        description="El backend está cargando hallazgos e incidencias persistidas."
        rows={4}
        title="Cargando detalle"
        variant="table"
      />
    );
  }

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
      <section className="rounded-lg border border-border bg-muted/40 px-4 py-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
          <span>{findings.length} incidencias estructuradas</span>
          <span>{findings.filter((item) => normalizeSeverity(item.severidad) === "ALTA").length} alta severidad</span>
          <span>{findings.filter((item) => item.resultado === "NO_ENCONTRADO").length} no encontrados</span>
        </div>
      </section>
      <ResultItemsTable items={findings} />
    </div>
  );
}

function FieldDecisionsTab({
  items,
  isLoading,
}: {
  items: AuditFinding[];
  isLoading: boolean;
}) {
  if (isLoading) {
    return (
      <BackendRequestSkeleton
        description="El backend está cargando decisiones de campos auditados."
        rows={4}
        title="Cargando campos"
        variant="table"
      />
    );
  }

  if (items.length === 0) {
    return (
      <div className="flex min-h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/30 px-6 text-center">
        <FileWarning className="h-10 w-10 text-slate-500/40" />
        <p className="mt-3 font-medium text-slate-300">Sin campos persistidos</p>
        <p className="mt-1 text-sm text-slate-400">
          El backend no persistió decisiones de campos para este registro.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-border bg-background">
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
            <TableRow key={`${item.campo}-${index}`}>
              <TableCell className="font-medium text-foreground" title={item.campo}>{item.campo}</TableCell>
              <TableCell className="text-slate-400 text-xs" title={item.documento ?? "N/D"}>
                {item.documento ? (
                  <span className="inline-flex items-center rounded border border-border bg-muted px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                    {item.documento}
                  </span>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell title={item.resultado}>
                <FieldStatusLabel status={item.resultado} />
              </TableCell>
              <TableCell className="max-w-[220px] truncate font-mono text-xs text-slate-400" title={item.valorFuenteVerdad ?? "N/D"}>
                {item.valorFuenteVerdad ?? "—"}
              </TableCell>
              <TableCell className="max-w-[220px] truncate font-mono text-xs text-slate-400" title={item.valorDocumento ?? "N/D"}>
                {item.valorDocumento ?? "—"}
              </TableCell>
              <TableCell title={item.severidad}>
                <SeverityBadge severity={item.severidad} />
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
    COINCIDE: { label: "Coincide", className: "text-emerald-400" },
    VALOR_DISTINTO: { label: "Valor distinto", className: "text-rose-400" },
    NO_ENCONTRADO: { label: "No encontrado", className: "text-amber-400" },
    OMITIDO: { label: "Omitido", className: "text-slate-400" },
    NO_CONCLUYENTE: { label: "No concluyente", className: "text-violet-300" },
  };
  const entry = config[status] ?? { label: status, className: "text-slate-400" };
  return <span className={`text-xs font-medium ${entry.className}`}>{entry.label}</span>;
}

function normalizeSeverity(value?: string | null) {
  return String(value ?? "").trim().toUpperCase();
}

function AttachmentsTab({
  disDetNro,
  attachments,
  isLoading,
  error,
  selected,
  onSelect,
}: {
  disDetNro: string;
  attachments: AttachmentRecord[];
  isLoading: boolean;
  error: string | null;
  selected?: AttachmentRecord;
  onSelect: (attachment: AttachmentRecord) => void;
}) {
  if (isLoading) {
    return (
      <div className="p-6">
        <BackendRequestSkeleton
          description="El backend está consultando metadatos de adjuntos."
          rows={3}
          title="Cargando adjuntos"
          variant="detail"
        />
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6">
        <Alert variant="destructive">
          <AlertTriangle />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      </div>
    );
  }

  if (attachments.length === 0) {
    return (
      <div className="flex h-full min-h-[400px] flex-col items-center justify-center p-6 text-center">
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 px-8 py-12">
          <Eye className="h-10 w-10 text-slate-500/40" />
          <p className="mt-3 font-medium text-slate-300">Sin adjuntos</p>
          <p className="mt-1 max-w-sm text-xs text-slate-500">
            No se encontraron documentos adjuntos para esta dispensación.
          </p>
        </div>
      </div>
    );
  }

  const selectedId = String(
    selected?.id_adjunto_fisico ??
      selected?.AdjDisId ??
      selected?.id_documento ??
      selected?.nombre_documento ??
      "",
  );

  return (
    <div className="flex h-full flex-col md:flex-row overflow-hidden">
      {/* Riel lateral de documentos (compacto, forense) */}
      <div className="flex w-full md:w-64 lg:w-72 xl:w-80 shrink-0 flex-col border-b md:border-b-0 md:border-r border-border bg-muted/20 overflow-hidden">
        <div className="flex items-center justify-between border-b border-border bg-muted/40 px-3.5 py-2.5">
          <div className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">
            <Paperclip className="h-3.5 w-3.5 text-sky-400" />
            <span>Documentos ({attachments.length})</span>
          </div>
          <span className="rounded bg-sky-500/10 px-2 py-0.5 text-[10px] font-medium text-sky-300 ring-1 ring-inset ring-sky-500/20">
            Evidencia
          </span>
        </div>

        <div className="flex-1 overflow-y-auto p-2 space-y-1.5 scrollbar-thin">
          {attachments.map((item, index) => {
            const id = String(
              item.id_adjunto_fisico ??
                item.AdjDisId ??
                item.id_documento ??
                item.nombre_documento ??
                index,
            );
            const active = selectedId === id;
            const isBlob = item.TipoAlmacenamiento === "BLOB";
            const isUrl = item.TipoAlmacenamiento === "URL";

            return (
              <button
                key={`${id}-${index}`}
                type="button"
                onClick={() => onSelect(item)}
                aria-pressed={active}
                className={cn(
                  "group flex w-full items-start gap-2.5 rounded-lg p-2.5 text-left transition-all",
                  active
                    ? "bg-sky-500/10 text-sky-200 ring-1 ring-inset ring-sky-500/30 font-medium"
                    : "text-slate-400 hover:bg-muted/60 hover:text-slate-200",
                )}
              >
                <span
                  className={cn(
                    "flex h-8 w-8 shrink-0 items-center justify-center rounded-md border text-xs",
                    active
                      ? "border-sky-500/30 bg-sky-500/20 text-sky-300"
                      : "border-border bg-muted text-muted-foreground group-hover:border-slate-600",
                  )}
                >
                  {isUrl ? (
                    <Link2 className="h-4 w-4" />
                  ) : isBlob ? (
                    <Paperclip className="h-4 w-4" />
                  ) : (
                    <FileText className="h-4 w-4" />
                  )}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-xs font-semibold leading-tight text-foreground">
                    {item.nombre_documento || item.AdjDisNom || "Adjunto sin nombre"}
                  </p>
                  <p className="mt-1 truncate text-[10px] uppercase tracking-[0.14em] text-slate-500">
                    {item.nombre_alternativo || item.physical_stored_alias || "Sin alias"}
                  </p>
                  <div className="mt-1 flex items-center gap-1.5 text-[10px] text-slate-500">
                    <span className="rounded bg-slate-800/80 px-1.5 py-0.5 font-mono text-[9px] text-slate-400">
                      {item.TipoAlmacenamiento ?? "N/D"}
                    </span>
                    {item.id_adjunto_fisico ? (
                      <span className="font-mono text-[9px] text-slate-500">
                        #{item.id_adjunto_fisico}
                      </span>
                    ) : null}
                  </div>
                </div>
                {active ? (
                  <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-sky-400" />
                ) : null}
              </button>
            );
          })}
        </div>
      </div>

      {/* Stage: Visor de documentos con 100% de alto y ancho */}
      <div className="flex-1 flex flex-col h-full overflow-hidden bg-background">
        <AttachmentViewerPanel disDetNro={disDetNro} attachment={selected} />
      </div>
    </div>
  );
}
