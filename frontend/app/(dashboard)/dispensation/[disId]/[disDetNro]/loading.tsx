import { CardSkeleton, PageLoadingHeader, TableSkeleton } from "@/components/shared/loading-skeleton";

export default function DispensationDetailLoading() {
  return (
    <div className="space-y-6">
      <PageLoadingHeader eyebrowWidth="w-20" titleWidth="w-64" />
      <CardSkeleton />
      <div className="panel rounded-xl border px-5 py-5">
        <TableSkeleton rows={4} />
      </div>
    </div>
  );
}
