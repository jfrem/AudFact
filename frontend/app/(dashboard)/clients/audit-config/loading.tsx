import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function AuditConfigLoading() {
  return (
    <div className="space-y-5">
      <PageLoadingHeader eyebrowWidth="w-24" titleWidth="w-64" />
      <CardSkeleton />
      <CardSkeleton />
    </div>
  );
}
