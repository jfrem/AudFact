import * as React from "react";
import { AuditFlowGraph } from "@/components/audit-flow/audit-flow-graph";
import { useAuditTelemetry } from "@/hooks/use-audit-telemetry";

interface LiveAuditFlowProps {
  auditId?: string | null;
}

export function LiveAuditFlow({ auditId }: LiveAuditFlowProps) {
  useAuditTelemetry(auditId, undefined, "audit");

  return (
    <div className="relative flex h-[calc(100vh-28rem)] min-h-[400px] w-full flex-col overflow-hidden rounded-xl border border-white/10 bg-card">
      <div className="relative h-full w-full flex-1">
        <AuditFlowGraph />
      </div>
    </div>
  );
}
