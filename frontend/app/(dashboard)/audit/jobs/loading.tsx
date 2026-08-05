import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function AuditJobsLoading() {
  return (
    <div className="space-y-5">
      <PageLoadingHeader eyebrowWidth="w-24" titleWidth="w-40" />
      <CardSkeleton />
    </div>
  );
}
