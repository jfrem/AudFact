import { CardSkeleton } from "@/components/shared/loading-skeleton";

export default function ClientsLoading() {
  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <div className="h-4 w-20 animate-pulse rounded-lg bg-white/[0.06]" />
        <div className="h-8 w-44 animate-pulse rounded-lg bg-white/[0.06]" />
      </div>
      <CardSkeleton />
      <div className="panel rounded-3xl border px-5 py-5">
        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <div
              key={i}
              className="animate-pulse rounded-lg border border-white/8 bg-white/[0.02] p-4"
            >
              <div className="h-5 w-36 rounded-lg bg-white/[0.06]" />
              <div className="mt-3 h-4 w-24 rounded-lg bg-white/[0.04]" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
