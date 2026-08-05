import { PageHeader } from "@/components/layout/page-header";
import { AuditSingleConsole } from "@/components/audit/audit-single-console";

export default function AuditSinglePage() {
  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Auditoría"
        title="Auditoría individual 1:1"
        description="Workspace principal para ejecutar por `DisDetNro`, leer el resultado funcional y navegar evidencia documental sin salir del contexto."
      />
      <AuditSingleConsole />
    </div>
  );
}
