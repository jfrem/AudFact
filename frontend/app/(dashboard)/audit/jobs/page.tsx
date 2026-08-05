import { PageHeader } from "@/components/layout/page-header";
import { JobTracker } from "@/components/jobs/job-tracker";

export default function AuditJobsPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Cola de trabajo"
        title="Tracking de jobs"
        description="El backend expone consulta por `jobId`, no un feed global. Esta vista asume esa restricción y la resuelve como buscador de seguimiento."
      />
      <JobTracker />
    </div>
  );
}
