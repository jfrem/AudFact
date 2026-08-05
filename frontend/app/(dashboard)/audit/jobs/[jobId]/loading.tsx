import { CardSkeleton, PageLoadingHeader } from "@/components/shared/loading-skeleton";

export default function JobDetailLoading() {
  return (
    <div className="space-y-6">
      <PageLoadingHeader eyebrowWidth="w-20" titleWidth="w-44" />
      {/* Progress bar skeleton */}
      <div className="panel space-y-4 rounded-xl border px-5 py-5">
        <div className="flex items-center justify-between">
          <div className="h-6 w-24 animate-pulse rounded-full bg-white/[0.06]" />
          <div className="h-8 w-14 animate-pulse rounded-lg bg-white/[0.06]" />
        </div>
        <div className="h-3 animate-pulse rounded-full bg-slate-800" />
        <div className="h-4 w-52 animate-pulse rounded-lg bg-white/[0.04]" />
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="surface-subtle animate-pulse rounded-lg p-4"
          >
            <div className="h-3 w-16 rounded-lg bg-white/[0.06]" />
            <div className="mt-4 h-6 w-20 rounded-lg bg-white/[0.06]" />
          </div>
        ))}
      </div>
      <CardSkeleton />
    </div>
  );
}
