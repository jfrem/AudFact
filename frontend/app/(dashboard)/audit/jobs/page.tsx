import { PageHeader } from "@/components/layout/page-header";
import { JobsListLive } from "@/components/jobs/jobs-list-live";

export default function AuditJobsPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Pipeline Asíncrono"
        title="Monitoreo de Jobs Batch"
        description="Visualización en tiempo real de lotes asíncronos y ejecuciones batch programadas en Redis."
      />
      <JobsListLive />
    </div>
  );
}
