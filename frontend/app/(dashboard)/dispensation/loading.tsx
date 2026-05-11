import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function DispensationLoading() {
  return (
    <div className="space-y-6">
      <PageLoadingHeader eyebrowWidth="w-20" titleWidth="w-44" descriptionWidth="w-80" withDescription />
      <CardSkeleton />
    </div>
  );
}
