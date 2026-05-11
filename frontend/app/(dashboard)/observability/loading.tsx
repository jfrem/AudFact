import { CardSkeleton } from "@/components/shared/loading-skeleton";

export default function ObservabilityLoading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <div className="h-4 w-28 animate-pulse rounded-lg bg-white/[0.06]" />
        <div className="h-8 w-52 animate-pulse rounded-lg bg-white/[0.06]" />
        <div className="h-4 w-96 animate-pulse rounded-lg bg-white/[0.04]" />
      </div>
      <CardSkeleton />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="animate-pulse rounded-lg border border-white/8 bg-white/[0.03] p-4"
          >
            <div className="h-3 w-20 rounded-lg bg-white/[0.06]" />
            <div className="mt-4 h-7 w-16 rounded-lg bg-white/[0.06]" />
            <div className="mt-2 h-3 w-32 rounded-lg bg-white/[0.04]" />
          </div>
        ))}
      </div>
    </div>
  );
}
