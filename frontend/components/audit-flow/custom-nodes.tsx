import React from "react";
import { Handle, Position } from "@xyflow/react";
import { CircleAlert, CheckCircle2, Loader2, XCircle, Clock } from "lucide-react";

import type { AuditNodeData } from "@/lib/audit-flow/dag-builder";

const StateIcon = ({ state, className }: { state: AuditNodeData["state"]; className?: string }) => {
  switch (state) {
    case "pending":
      return <Clock className={`h-4 w-4 ${className}`} />;
    case "running":
      return <Loader2 className={`h-4 w-4 animate-spin ${className}`} />;
    case "completed":
      return <CheckCircle2 className={`h-4 w-4 ${className}`} />;
    case "failed":
      return <XCircle className={`h-4 w-4 ${className}`} />;
    case "rejected":
      return <CircleAlert className={`h-4 w-4 ${className}`} />;
    default:
      return null;
  }
};

function getNodeStyles(state: AuditNodeData["state"]) {
  switch (state) {
    case "pending":
      return {
        bg: "bg-white dark:bg-slate-800/90",
        border: "border-slate-200 dark:border-slate-700/80",
        text: "text-slate-500 dark:text-slate-400",
        icon: "text-slate-400",
        handle: "border-slate-200 bg-slate-50 dark:border-slate-600 dark:bg-slate-800",
      };
    case "running":
      return {
        bg: "bg-white dark:bg-slate-800/90",
        border: "border-blue-500 dark:border-blue-500 shadow-[0_0_15px_rgba(59,130,246,0.3)]",
        text: "text-slate-900 dark:text-white font-medium",
        icon: "text-blue-500 dark:text-blue-400",
        handle: "border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-900",
      };
    case "completed":
      return {
        bg: "bg-white dark:bg-slate-800/90",
        border: "border-emerald-500/60 dark:border-emerald-400/70",
        text: "text-slate-900 dark:text-slate-100",
        icon: "text-emerald-500 dark:text-emerald-400",
        handle: "border-slate-200 bg-slate-50 dark:border-slate-600 dark:bg-slate-800",
      };
    case "failed":
      return {
        bg: "bg-red-50 dark:bg-red-950/80",
        border: "border-red-500/70 dark:border-red-500/80",
        text: "text-red-900 dark:text-red-100",
        icon: "text-red-500 dark:text-red-400",
        handle: "border-red-200 bg-red-100 dark:border-red-800 dark:bg-red-900",
      };
    case "rejected":
      return {
        bg: "bg-white dark:bg-slate-800/90",
        border: "border-orange-500/70 dark:border-orange-400/80",
        text: "text-slate-900 dark:text-slate-100",
        icon: "text-orange-500 dark:text-orange-400",
        handle: "border-slate-200 bg-slate-50 dark:border-slate-600 dark:bg-slate-800",
      };
    default:
      return {
        bg: "bg-white dark:bg-slate-800/90",
        border: "border-slate-200 dark:border-slate-700/80",
        text: "text-slate-900 dark:text-slate-100",
        icon: "text-slate-400",
        handle: "border-slate-200 bg-slate-50 dark:border-slate-600 dark:bg-slate-800",
      };
  }
}

export function AuditNodeComponent({ data }: { data: AuditNodeData }) {
  const styles = getNodeStyles(data.state);

  return (
    <div
      className={`group/node relative min-w-[180px] rounded-lg border px-4 py-3 shadow-sm transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md hover:ring-2 hover:ring-slate-400/20 dark:hover:ring-slate-500/30 ${styles.bg} ${styles.border} ${data.state === "running" ? "ring-2 ring-blue-500/20 ring-offset-1 dark:ring-blue-400/20 dark:ring-offset-slate-950 animate-[pulse_2s_ease-in-out_infinite]" : ""}`}
    >
      <Handle
        type="target"
        position={Position.Left}
        className={`h-3 w-3 !rounded-sm !border-2 transition-colors ${styles.handle}`}
      />

      <div className="flex items-start gap-3">
        <div className={`mt-0.5 shrink-0 ${styles.icon}`}>
          <StateIcon state={data.state} />
        </div>

        <div className="flex flex-col gap-0.5">
          <div className={`text-sm tracking-tight ${styles.text}`}>
            {data.label}
          </div>
          {data.durationMs !== undefined && (
            <div className="font-mono text-[10px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
              {data.durationMs} ms
            </div>
          )}
          {data.metrics && (
            <div className="mt-1 flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-mono bg-slate-100 dark:bg-slate-900/50 px-1.5 py-0.5 rounded-md w-fit">
              <span className="text-emerald-600 dark:text-emerald-400 font-medium" title="Completado">{data.metrics.completed}</span>
              <span className="text-slate-400">/</span>
              <span className="text-red-600 dark:text-red-400 font-medium" title="Fallido">{data.metrics.failed}</span>
              {(data.metrics.rejected ?? 0) > 0 && (
                <>
                  <span className="text-slate-400">/</span>
                  <span className="text-amber-600 dark:text-amber-400 font-medium" title="Revisión">{data.metrics.rejected}</span>
                </>
              )}
              <span className="text-slate-400">/</span>
              <span className="text-slate-600 dark:text-slate-300" title="Total">{data.metrics.total}</span>
            </div>
          )}
        </div>
      </div>

      {data.metrics && data.metrics.total > 0 && (
        <div className="absolute bottom-0 left-0 flex h-1 w-full overflow-hidden rounded-b-lg">
          <div
            className="bg-emerald-500 transition-all duration-500 ease-out"
            style={{ width: `${(data.metrics.completed / data.metrics.total) * 100}%` }}
          />
          <div
            className="bg-amber-500 transition-all duration-500 ease-out"
            style={{ width: `${((data.metrics.rejected ?? 0) / data.metrics.total) * 100}%` }}
          />
          <div
            className="bg-red-500 transition-all duration-500 ease-out"
            style={{ width: `${(data.metrics.failed / data.metrics.total) * 100}%` }}
          />
          <div className="flex-1 bg-slate-100 dark:bg-slate-800/50" />
        </div>
      )}

      <Handle
        type="source"
        position={Position.Right}
        className={`h-3 w-3 !rounded-sm !border-2 transition-colors ${styles.handle}`}
      />
    </div>
  );
}

export const nodeTypes = {
  auditNode: AuditNodeComponent,
  documentNode: AuditNodeComponent,
};
