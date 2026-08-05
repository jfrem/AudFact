import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function AuditSingleLoading() {
  return (
    <div className="space-y-5">
      <PageLoadingHeader eyebrowWidth="w-24" titleWidth="w-56" />
      <CardSkeleton />
      <CardSkeleton />
    </div>
  );
}
