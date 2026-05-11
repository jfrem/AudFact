import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function AuditBatchLoading() {
  return (
    <div className="space-y-5">
      <PageLoadingHeader eyebrowWidth="w-24" titleWidth="w-56" />
      <div className="grid gap-5 xl:grid-cols-[1fr_1fr]">
        <CardSkeleton />
        <div className="space-y-5">
          <CardSkeleton />
          <CardSkeleton />
        </div>
      </div>
    </div>
  );
}
