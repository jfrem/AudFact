import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import {
  Activity,
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  Clock3,
  Code2,
  Hash,
  Info,
  Layers3,
  LoaderCircle,
  X,
  XCircle,
} from "lucide-react";

import type { AuditNodeState } from "@/lib/audit-flow/dag-builder";
import { useAuditFlowStore } from "@/store/use-audit-flow-store";
import { auditJobQuery } from "@/lib/query/audit";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

const statusConfig = {
  completed: { label: "Completado", icon: CheckCircle2, className: "border-emerald-500/25 bg-emerald-500/10 text-emerald-300" },
  running: { label: "Ejecutando", icon: LoaderCircle, className: "border-sky-500/25 bg-sky-500/10 text-sky-300" },
  failed: { label: "Fallido", icon: XCircle, className: "border-rose-500/25 bg-rose-500/10 text-rose-300" },
  rejected: { label: "Revisión", icon: AlertCircle, className: "border-amber-500/25 bg-amber-500/10 text-amber-300" },
  pending: { label: "Pendiente", icon: Clock3, className: "border-border bg-muted text-muted-foreground" },
} satisfies Record<
  AuditNodeState,
  { label: string; icon: typeof Clock3; className: string }
>;

const FAILED_STAGE_MAP: Record<string, string> = {
  "App\\Services\\Audit\\Pipeline\\DocumentAuditOrchestrator": "orchestration",
  "App\\Services\\Audit\\Pipeline\\DocumentExtractionWorker": "extraction",
  "App\\Services\\Audit\\Pipeline\\DocumentNormalizer": "normalization",
  "App\\Services\\Audit\\Pipeline\\RulesEvaluationWorker": "policy",
  final_persistence: "aggregation",
};

export function NodeInspector() {
  const { selectedNode, setSelectedNode } = useAuditFlowStore();
  const params = useParams();
  const jobId = typeof params?.jobId === "string" ? params.jobId : undefined;
  const { data: jobData } = useQuery({
    ...auditJobQuery(jobId!),
    enabled: Boolean(jobId),
  });

  if (!selectedNode) return null;

  const { data, id } = selectedNode;
  const failedAudits =
    jobData?.audits?.filter(
      (audit) =>
        audit.status === "failed" &&
        audit.failed_stage &&
        FAILED_STAGE_MAP[audit.failed_stage] === id,
    ) ?? [];
  const reviewAudits =
    jobData?.audits?.filter(
      (audit) =>
        audit.status === "manual_review" &&
        audit.failed_stage &&
        FAILED_STAGE_MAP[audit.failed_stage] === id,
    ) ?? [];

  const observation =
    typeof data.details?.observation === "string" ? data.details.observation : null;
  const documentName =
    typeof data.details?.documentName === "string" ? data.details.documentName : null;
  const rawDetails = data.details ? { ...data.details } : null;

  if (rawDetails) {
    delete rawDetails.observation;
    delete rawDetails.documentName;
  }

  const showRawDetails = rawDetails && Object.keys(rawDetails).length > 0;
  const status = statusConfig[data.state];
  const StatusIcon = status.icon;

  return (
    <aside className="absolute right-3 top-3 z-30 flex max-h-[calc(100%-1.5rem)] w-[min(22rem,calc(100%-1.5rem))] flex-col overflow-hidden rounded-lg border border-border bg-popover shadow-[0_20px_56px_oklch(0.08_0.02_252/0.48)]">
      <header className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
        <div className="min-w-0">
          <p className="text-[9px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
            Inspector de nodo
          </p>
          <h3 className="mt-1 truncate font-display text-sm font-semibold text-foreground">
            {data.label}
          </h3>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8"
          onClick={() => setSelectedNode(null)}
          aria-label="Cerrar inspector"
        >
          <X />
        </Button>
      </header>

      <div className="overflow-y-auto p-4">
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3">
            <InspectorDatum icon={<Activity />} label="Estado">
              <span className={cn("inline-flex items-center gap-1.5 rounded border px-2 py-1 text-xs font-semibold", status.className)}>
                <StatusIcon className={cn("h-3.5 w-3.5", data.state === "running" && "animate-spin")} aria-hidden="true" />
                {status.label}
              </span>
            </InspectorDatum>
            <InspectorDatum icon={<Hash />} label="Nodo ID">
              <code className="block truncate rounded border border-border bg-muted/45 px-2 py-1 font-mono text-xs text-muted-foreground" title={id}>
                {id.split("-").pop() || id}
              </code>
            </InspectorDatum>
          </div>

          {data.metrics ? (
            <section>
              <SectionLabel icon={<Layers3 />}>Rendimiento del lote</SectionLabel>
              <dl className="mt-2 grid grid-cols-4 overflow-hidden rounded-md border border-border">
                <Metric label="Éxitos" value={data.metrics.completed} tone="success" />
                <Metric label="Fallos" value={data.metrics.failed} tone="danger" />
                <Metric label="Revisión" value={data.metrics.rejected ?? 0} tone="warning" />
                <Metric label="Total" value={data.metrics.total} />
              </dl>
            </section>
          ) : null}

          {failedAudits.length > 0 && (data.metrics?.failed ? data.metrics.failed > 0 : data.state === "failed") ? (
            <AuditBadgeList
              tone="danger"
              title={`Facturas con fallo crítico (${failedAudits.length})`}
              description="Ejecuciones enviadas a DLQ por error técnico o contenido no procesable."
              audits={failedAudits}
            />
          ) : null}

          {reviewAudits.length > 0 ? (
            <AuditBadgeList
              tone="warning"
              title={`Documentos en revisión (${reviewAudits.length})`}
              description="Ejecuciones que requieren verificación humana."
              audits={reviewAudits}
            />
          ) : null}

          {data.error ? (
            <Alert variant="destructive">
              <AlertTriangle />
              <AlertDescription>{data.error}</AlertDescription>
            </Alert>
          ) : null}

          {observation ? (
            <Alert variant="warning">
              <Info />
              <AlertDescription>
                {observation}
                {documentName ? (
                  <span className="mt-2 block border-t border-amber-500/20 pt-2 text-xs">
                    Documento: <strong>{documentName}</strong>
                  </span>
                ) : null}
              </AlertDescription>
            </Alert>
          ) : null}

          {data.durationMs !== undefined || data.worker ? (
            <div className="grid grid-cols-2 gap-4 border-y border-border py-4">
              {data.durationMs !== undefined ? (
                <InspectorDatum icon={<Activity />} label="Duración">
                  <span className="font-mono text-xs tabular-nums text-foreground">{data.durationMs} ms</span>
                </InspectorDatum>
              ) : null}
              {data.worker ? (
                <InspectorDatum icon={<Layers3 />} label="Worker">
                  <code className="block truncate font-mono text-xs text-muted-foreground" title={data.worker}>
                    {data.worker.split("-").pop() || data.worker}
                  </code>
                </InspectorDatum>
              ) : null}
            </div>
          ) : null}

          {showRawDetails ? (
            <section>
              <SectionLabel icon={<Code2 />}>Metadata</SectionLabel>
              <pre className="mt-2 max-h-64 overflow-auto rounded-md border border-border bg-[var(--surface-base)] p-3 font-mono text-[11px] leading-relaxed text-foreground/80">
                <code>{JSON.stringify(rawDetails, null, 2)}</code>
              </pre>
            </section>
          ) : null}
        </div>
      </div>
    </aside>
  );
}

function InspectorDatum({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="min-w-0">
      <span className="mb-1.5 flex items-center gap-1.5 text-[9px] font-semibold uppercase tracking-[0.16em] text-muted-foreground [&_svg]:h-3 [&_svg]:w-3">
        {icon}
        {label}
      </span>
      <div>{children}</div>
    </div>
  );
}

function SectionLabel({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <h4 className="flex items-center gap-1.5 text-[9px] font-semibold uppercase tracking-[0.16em] text-muted-foreground [&_svg]:h-3 [&_svg]:w-3">
      {icon}
      {children}
    </h4>
  );
}

function Metric({
  label,
  value,
  tone = "neutral",
}: {
  label: string;
  value: number;
  tone?: "neutral" | "success" | "warning" | "danger";
}) {
  const tones = {
    neutral: "text-foreground",
    success: "text-emerald-300",
    warning: "text-amber-300",
    danger: "text-rose-300",
  };

  return (
    <div className="border-l border-border p-2 text-center first:border-l-0">
      <dt className="text-[8px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">{label}</dt>
      <dd className={cn("mt-1 font-mono text-base font-semibold tabular-nums", tones[tone])}>{value}</dd>
    </div>
  );
}

function AuditBadgeList({
  tone,
  title,
  description,
  audits,
}: {
  tone: "danger" | "warning";
  title: string;
  description: string;
  audits: { audit_id: string; dis_det_nro?: string | null }[];
}) {
  const tones = {
    danger: {
      shell: "border-rose-500/25 bg-rose-500/[0.055]",
      title: "text-rose-300",
      badge: "border-rose-500/25 bg-rose-500/10 text-rose-200",
    },
    warning: {
      shell: "border-amber-500/25 bg-amber-500/[0.055]",
      title: "text-amber-300",
      badge: "border-amber-500/25 bg-amber-500/10 text-amber-200",
    },
  }[tone];

  return (
    <section className={cn("rounded-md border p-3", tones.shell)}>
      <h4 className={cn("flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em]", tones.title)}>
        <AlertTriangle className="h-3 w-3" aria-hidden="true" />
        {title}
      </h4>
      <p className="mt-1.5 text-[11px] leading-5 text-foreground/70">{description}</p>
      <div className="mt-2 flex flex-wrap gap-1.5">
        {audits.map((audit) => (
          <span
            key={audit.audit_id}
            className={cn("rounded border px-1.5 py-0.5 font-mono text-[10px]", tones.badge)}
            title={`Audit ID: ${audit.audit_id}`}
          >
            {audit.dis_det_nro || audit.audit_id}
          </span>
        ))}
      </div>
    </section>
  );
}
