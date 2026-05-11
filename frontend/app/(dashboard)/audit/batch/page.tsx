import { getPublicConfig } from "@/lib/api/audfact";
import { PageHeader } from "@/components/layout/page-header";
import { AuditBatchConsole } from "@/components/audit/audit-batch-console";

export default async function AuditBatchPage() {
  const config = await getPublicConfig().catch(() => null);

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Operación batch"
        title="Auditoría batch asíncrona"
        description="Formulario operativo para encolar auditorías masivas respetando los límites y contratos oficiales del backend."
      />
      <AuditBatchConsole
        defaultLimit={config?.auditBatchMaxLimit ?? 100}
        timeoutMs={config?.auditBatchTimeoutMs ?? 3_600_000}
      />
    </div>
  );
}
