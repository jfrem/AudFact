import { CardSkeleton, TableSkeleton } from "@/components/shared/loading-skeleton";

export default function DocumentsHistoryLoading() {
  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <div className="h-4 w-36 animate-pulse rounded bg-muted" />
        <div className="h-8 w-56 animate-pulse rounded bg-muted" />
      </div>
      <CardSkeleton />
      <div className="panel rounded-lg border px-5 py-5">
        <TableSkeleton rows={6} />
      </div>
    </div>
  );
}
