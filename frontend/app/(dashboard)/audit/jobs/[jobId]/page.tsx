import { PageHeader } from "@/components/layout/page-header";
import { JobDetailClient } from "@/components/jobs/job-detail-client";

export default async function AuditJobDetailPage({
  params,
}: {
  params: Promise<{ jobId: string }>;
}) {
  const { jobId } = await params;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Async"
        title="Detalle del job"
        description="Seguimiento con polling del estado, progreso y resultado operativo del job en segundo plano."
      />
      <JobDetailClient jobId={jobId} />
    </div>
  );
}
