import { CardSkeleton, MetricGridSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function DashboardLoading() {
  return (
    <div className="space-y-6" role="status" aria-label="Cargando dashboard">
      <PageLoadingHeader eyebrowWidth="w-24" titleWidth="w-48" />
      <MetricGridSkeleton items={4} />
      <CardSkeleton />
    </div>
  );
}
