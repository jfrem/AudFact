import React from "react";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { X, Code2, Activity, Hash, Layers, AlertTriangle, Info } from "lucide-react";

import { useAuditFlowStore } from "@/store/use-audit-flow-store";
import { auditJobQuery } from "@/lib/query/audit";

const STATUS_CLASS = {
  completed: "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20",
  running: "bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20",
  failed: "bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20",
  rejected: "bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-500/10 dark:text-orange-400 dark:border-orange-500/20",
  pending: "bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700",
} as const;

const FAILED_STAGE_MAP: Record<string, string> = {
  "App\\Services\\Audit\\Pipeline\\DocumentAuditOrchestrator": "orchestration",
  "App\\Services\\Audit\\Pipeline\\DocumentExtractionWorker": "extraction",
  "App\\Services\\Audit\\Pipeline\\DocumentNormalizationWorker": "normalization",
  "App\\Services\\Audit\\Pipeline\\RulesEvaluationWorker": "policy",
  "final_persistence": "aggregation",
};

export function NodeInspector() {
  const { selectedNode, setSelectedNode } = useAuditFlowStore();

  const params = useParams();
  const jobId = typeof params?.jobId === "string" ? params.jobId : undefined;
  
  const { data: jobData } = useQuery({
    ...auditJobQuery(jobId!),
    enabled: Boolean(jobId),
  });

  if (!selectedNode) {
    return null;
  }

  const { data, id } = selectedNode;
  
  const failedAudits = jobData?.audits?.filter((a) => 
    a.status === "failed" && 
    a.failed_stage && 
    FAILED_STAGE_MAP[a.failed_stage] === id
  ) || [];

  const hasDetails = data.details && Object.keys(data.details).length > 0;

  const observation = typeof data.details?.observation === "string" ? data.details.observation : null;
  const documentName = typeof data.details?.documentName === "string" ? data.details.documentName : null;

  const rawDetails = hasDetails ? { ...data.details } : null;
  if (rawDetails) {
    delete rawDetails.observation;
    delete rawDetails.documentName;
  }
  const showRawDetails = rawDetails && Object.keys(rawDetails).length > 0;

  return (
    <aside className="absolute right-4 top-4 z-30 flex max-h-[calc(100%-2rem)] w-80 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-800 dark:bg-slate-950 animate-in slide-in-from-right-4 fade-in duration-200">
      <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
        <h3 className="font-semibold tracking-tight text-slate-900 dark:text-slate-100">{data.label}</h3>
        <button
          type="button"
          onClick={() => setSelectedNode(null)}
          className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-slate-800 dark:hover:text-slate-300"
          aria-label="Cerrar inspector"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <div className="flex-1 overflow-y-auto p-4">
        <div className="flex flex-col gap-6">
          <div className="flex gap-4">
            <div className="flex-1">
              <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <Activity className="h-3 w-3" /> Estado
              </span>
              <span className={`inline-flex rounded-md border px-2 py-1 text-xs font-semibold ${STATUS_CLASS[data.state]}`}>
                {data.state.toUpperCase()}
              </span>
            </div>
            <div className="flex-1">
              <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <Hash className="h-3 w-3" /> Nodo ID
              </span>
              <code className="block truncate rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400" title={id}>
                {id.split("-").pop() || id}
              </code>
            </div>
          </div>

          {data.metrics && (
            <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-800/60 dark:bg-slate-900/50">
              <span className="mb-3 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <Layers className="h-3 w-3" /> Rendimiento de Lote
              </span>
              <div className="grid grid-cols-3 gap-2 text-center">
                <div className="flex flex-col rounded-md border border-emerald-100 bg-emerald-50 py-1.5 dark:border-emerald-900/50 dark:bg-emerald-500/10">
                  <span className="text-[10px] font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Éxitos</span>
                  <span className="text-lg font-light text-emerald-700 dark:text-emerald-300">{data.metrics.completed}</span>
                </div>
                <div className="flex flex-col rounded-md border border-red-100 bg-red-50 py-1.5 dark:border-red-900/50 dark:bg-red-500/10">
                  <span className="text-[10px] font-medium text-red-600 dark:text-red-400 uppercase tracking-wider">Fallos</span>
                  <span className="text-lg font-light text-red-700 dark:text-red-300">{data.metrics.failed}</span>
                </div>
                <div className="flex flex-col rounded-md border border-slate-200 bg-slate-100 py-1.5 dark:border-slate-700 dark:bg-slate-800">
                  <span className="text-[10px] font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wider">Total</span>
                  <span className="text-lg font-light text-slate-700 dark:text-slate-300">{data.metrics.total}</span>
                </div>
              </div>
            </div>
          )}

          {failedAudits.length > 0 && (data.metrics?.failed ? data.metrics.failed > 0 : data.state === "failed") && (
            <div className="rounded-lg border border-rose-900/50 bg-rose-950/20 p-3 text-rose-200">
              <span className="mb-2 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-500">
                <AlertTriangle className="h-3 w-3" /> Facturas con Fallo Crítico ({failedAudits.length})
              </span>
              <div className="text-[11px] leading-relaxed text-rose-200/80 mb-2">
                Documentos trasladados al <strong>Dead Letter Queue (DLQ)</strong> por estar corruptos, sin páginas, o por errores de origen.
              </div>
              <div className="flex flex-wrap gap-1.5">
                {failedAudits.map((a) => (
                  <span
                    key={a.audit_id}
                    className="rounded border border-rose-800/60 bg-rose-950/40 px-1.5 py-0.5 font-mono text-[10px] text-rose-300 shadow-sm"
                    title={`Audit ID: ${a.audit_id}`}
                  >
                    {a.dis_det_nro || a.audit_id}
                  </span>
                ))}
              </div>
            </div>
          )}

          {data.error && (
            <div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900/50 dark:bg-red-950/30">
              <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-red-600 dark:text-red-400">
                <AlertTriangle className="h-3 w-3" /> Error del sistema
              </span>
              <p className="text-xs font-medium text-red-800 dark:text-red-300">{data.error}</p>
            </div>
          )}

          {observation && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
              <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                <Info className="h-3 w-3" /> Observación
              </span>
              <p className="text-xs font-medium leading-relaxed text-amber-900 dark:text-amber-200">{observation}</p>
              {documentName && (
                <p className="mt-2 text-[10px] text-amber-700/80 dark:text-amber-400/80 border-t border-amber-200 dark:border-amber-900/50 pt-2">
                  Relacionado al documento: <span className="font-semibold">{documentName}</span>
                </p>
              )}
            </div>
          )}

          <div className="grid grid-cols-2 gap-4 border-y border-slate-100 py-4 dark:border-slate-800/60">
            {data.durationMs !== undefined && (
              <div>
                <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                  <Activity className="h-3 w-3" /> Duración
                </span>
                <span className="font-mono text-xs tracking-tight text-slate-900 dark:text-slate-200">
                  {data.durationMs} ms
                </span>
              </div>
            )}
            {data.worker && (
              <div>
                <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                  <Layers className="h-3 w-3" /> Instancia Worker
                </span>
                <code className="block truncate rounded bg-slate-50 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-900 dark:text-slate-400" title={data.worker}>
                  {data.worker.split("-").pop() || data.worker}
                </code>
              </div>
            )}
          </div>

          {showRawDetails && (
            <div>
              <span className="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <Code2 className="h-3 w-3" /> Detalles extra
              </span>
              <div className="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800">
                <div className="bg-slate-50 px-3 py-1.5 border-b border-slate-200 dark:bg-slate-900 dark:border-slate-800">
                  <span className="text-[10px] font-medium text-slate-500">Payload metadata</span>
                </div>
                <pre className="max-h-64 overflow-x-auto overflow-y-auto bg-white p-3 text-[11px] leading-relaxed text-slate-700 dark:bg-slate-950 dark:text-slate-300">
                  <code>{JSON.stringify(rawDetails, null, 2)}</code>
                </pre>
              </div>
            </div>
          )}
        </div>
      </div>
    </aside>
  );
}
