import { Handle, Position } from "@xyflow/react";
import {
  AlertCircle,
  CheckCircle2,
  Clock3,
  LoaderCircle,
  XCircle,
} from "lucide-react";

import type { AuditNodeData } from "@/lib/audit-flow/dag-builder";
import { cn } from "@/lib/utils";

const stateConfig = {
  pending: {
    icon: Clock3,
    label: "Pendiente",
    shell: "border-border bg-[var(--surface-raised)]",
    iconClass: "text-muted-foreground",
    handle: "!border-border !bg-muted",
  },
  running: {
    icon: LoaderCircle,
    label: "Ejecutando",
    shell: "border-sky-500/45 bg-sky-500/[0.07]",
    iconClass: "text-sky-300",
    handle: "!border-sky-500/60 !bg-sky-500/25",
  },
  completed: {
    icon: CheckCircle2,
    label: "Completado",
    shell: "border-emerald-500/35 bg-emerald-500/[0.055]",
    iconClass: "text-emerald-300",
    handle: "!border-emerald-500/45 !bg-emerald-500/20",
  },
  failed: {
    icon: XCircle,
    label: "Fallido",
    shell: "border-rose-500/45 bg-rose-500/[0.07]",
    iconClass: "text-rose-300",
    handle: "!border-rose-500/55 !bg-rose-500/20",
  },
  rejected: {
    icon: AlertCircle,
    label: "Revisión",
    shell: "border-amber-500/45 bg-amber-500/[0.07]",
    iconClass: "text-amber-300",
    handle: "!border-amber-500/55 !bg-amber-500/20",
  },
} satisfies Record<
  AuditNodeData["state"],
  {
    icon: typeof Clock3;
    label: string;
    shell: string;
    iconClass: string;
    handle: string;
  }
>;

export function AuditNodeComponent({ data }: { data: AuditNodeData }) {
  const config = stateConfig[data.state];
  const Icon = config.icon;

  return (
    <article
      className={cn(
        "relative min-w-[188px] rounded-md border px-3.5 py-3 transition-colors duration-150",
        config.shell,
      )}
      aria-label={`${data.label}, ${config.label}`}
    >
      <Handle
        type="target"
        position={Position.Left}
        className={cn("!h-2.5 !w-2.5 !rounded-sm !border-2", config.handle)}
      />

      <div className="flex items-start gap-2.5">
        <Icon
          className={cn("mt-0.5 h-4 w-4 shrink-0", config.iconClass, data.state === "running" && "animate-spin")}
          aria-hidden="true"
        />
        <div className="min-w-0">
          <p className="text-sm font-semibold tracking-[-0.01em] text-foreground">{data.label}</p>
          <div className="mt-1 flex items-center gap-2">
            <span className={cn("text-[9px] font-semibold uppercase tracking-[0.16em]", config.iconClass)}>
              {config.label}
            </span>
            {data.durationMs !== undefined ? (
              <span className="font-mono text-[10px] tabular-nums text-muted-foreground">
                {data.durationMs} ms
              </span>
            ) : null}
          </div>
          {data.metrics ? (
            <div className="mt-2 flex items-center gap-1.5 font-mono text-[10px] tabular-nums">
              <span className="text-emerald-300" title="Completadas">{data.metrics.completed}</span>
              <span className="text-muted-foreground/55">/</span>
              <span className="text-rose-300" title="Fallidas">{data.metrics.failed}</span>
              <span className="text-muted-foreground/55">/</span>
              <span className="text-amber-300" title="Revisión">{data.metrics.rejected ?? 0}</span>
              <span className="text-muted-foreground/55">/</span>
              <span className="text-foreground" title="Total">{data.metrics.total}</span>
            </div>
          ) : null}
        </div>
      </div>

      {data.metrics && data.metrics.total > 0 ? (
        <div className="absolute inset-x-0 bottom-0 flex h-0.5 overflow-hidden rounded-b-md" aria-hidden="true">
          <span
            className="bg-emerald-400"
            style={{ width: `${(data.metrics.completed / data.metrics.total) * 100}%` }}
          />
          <span
            className="bg-amber-400"
            style={{ width: `${((data.metrics.rejected ?? 0) / data.metrics.total) * 100}%` }}
          />
          <span
            className="bg-rose-400"
            style={{ width: `${(data.metrics.failed / data.metrics.total) * 100}%` }}
          />
          <span className="flex-1 bg-muted" />
        </div>
      ) : null}

      <Handle
        type="source"
        position={Position.Right}
        className={cn("!h-2.5 !w-2.5 !rounded-sm !border-2", config.handle)}
      />
    </article>
  );
}

export const nodeTypes = {
  auditNode: AuditNodeComponent,
  documentNode: AuditNodeComponent,
};
