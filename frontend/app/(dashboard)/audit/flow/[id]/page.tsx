"use client";

import { use } from "react";
import { AuditFlowGraph } from "@/components/audit-flow/audit-flow-graph";
import { useAuditTelemetry } from "@/hooks/use-audit-telemetry";

export default function AuditFlowPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ uuid?: string }>;
}) {
  const resolvedParams = use(params);
  const resolvedSearchParams = use(searchParams);
  const auditId = resolvedParams.id;
  const uuid = resolvedSearchParams.uuid;

  useAuditTelemetry(auditId, uuid);

  return (
    <div className="flex h-[calc(100vh-4rem)] w-full flex-col gap-4 p-4">
      <div className="flex shrink-0 items-center justify-between">
        <div>
          <h1 className="[font-family:var(--font-heading)] text-2xl font-semibold tracking-tight text-foreground">
            Trazabilidad de auditoría
          </h1>
          <p className="mt-1 text-sm text-slate-400">
            Visualización técnica en vivo del ciclo de vida y validación documental.
            <span className="ml-3 rounded border border-border bg-muted px-2 py-0.5 font-mono text-[11px] tracking-wider text-muted-foreground">
              ID: {auditId}
            </span>
          </p>
        </div>
      </div>

      <div className="relative flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-lg border border-border bg-card">
        <div className="relative h-full w-full flex-1">
          <AuditFlowGraph />
        </div>
      </div>
    </div>
  );
}
