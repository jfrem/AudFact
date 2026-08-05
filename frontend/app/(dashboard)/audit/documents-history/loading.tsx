import { CardSkeleton, TableSkeleton } from "@/components/shared/loading-skeleton";

export default function DocumentsHistoryLoading() {
  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <div className="h-4 w-36 animate-pulse rounded-lg bg-white/[0.06]" />
        <div className="h-8 w-56 animate-pulse rounded-lg bg-white/[0.06]" />
      </div>
      <CardSkeleton />
      <div className="panel rounded-3xl border px-5 py-5">
        <TableSkeleton rows={6} />
      </div>
    </div>
  );
}
