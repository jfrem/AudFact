import { PageLoadingHeader, TableSkeleton } from "@/components/shared/loading-skeleton";

export default function InvoicesLoading() {
  return (
    <div className="space-y-6">
      <PageLoadingHeader eyebrowWidth="w-20" titleWidth="w-32" />
      <div className="panel rounded-xl border px-5 py-5">
        <TableSkeleton rows={8} />
      </div>
    </div>
  );
}
