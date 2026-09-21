import { AuditFlowGraph } from "@/components/audit-flow/audit-flow-graph";
import { useAuditTelemetry } from "@/hooks/use-audit-telemetry";

export function LiveAuditFlow({ auditId }: { auditId?: string | null }) {
  useAuditTelemetry(auditId, undefined, "audit");

  return (
    <div className="relative flex h-[calc(100vh-28rem)] min-h-[420px] w-full flex-col overflow-hidden rounded-lg border border-border bg-card">
      <div className="relative h-full w-full flex-1">
        <AuditFlowGraph />
      </div>
    </div>
  );
}
