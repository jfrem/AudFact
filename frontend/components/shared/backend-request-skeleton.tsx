import { LoadingSkeleton, TableSkeleton } from "@/components/shared/loading-skeleton";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

type BackendRequestSkeletonVariant = "compact" | "detail" | "form" | "panel" | "table";

interface BackendRequestSkeletonProps {
  className?: string;
  description?: string;
  rows?: number;
  title?: string;
  variant?: BackendRequestSkeletonVariant;
}

export function BackendRequestSkeleton({
  className,
  description = "La solicitud sigue en curso.",
  rows = 5,
  title = "Procesando petición",
  variant = "panel",
}: BackendRequestSkeletonProps) {
  return (
    <section
      aria-label={title}
      aria-live="polite"
      className={cn(
        "rounded-xl border border-sky-500/15 bg-sky-500/[0.04] px-4 py-4 text-slate-100",
        className,
      )}
      role="status"
    >
      <div className="mb-4 flex min-w-0 items-center gap-3">
        <span className="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-70" />
          <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-sky-400" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-sky-200">{title}</p>
          <p className="text-xs leading-5 text-slate-400">{description}</p>
        </div>
      </div>
      <SkeletonBody rows={rows} variant={variant} />
    </section>
  );
}

function SkeletonBody({
  rows,
  variant,
}: {
  rows: number;
  variant: BackendRequestSkeletonVariant;
}) {
  if (variant === "table") {
    return <TableSkeleton rows={rows} />;
  }

  if (variant === "detail") {
    return (
      <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
        <div className="space-y-3" aria-hidden="true">
          <Skeleton className="h-5 w-36 rounded-lg bg-white/[0.06]" />
          <Skeleton className="h-16 rounded-lg bg-white/[0.04]" />
          <Skeleton className="h-16 rounded-lg bg-white/[0.035]" />
          <Skeleton className="h-16 rounded-lg bg-white/[0.03]" />
        </div>
        <div aria-hidden="true">
          <Skeleton className="h-6 w-44 rounded-lg bg-white/[0.06]" />
          <div className="mt-5 grid gap-3 sm:grid-cols-2">
            {Array.from({ length: 6 }).map((_, index) => (
              <Skeleton
                key={index}
                className="h-12 rounded-lg bg-white/[0.04]"
              />
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (variant === "form") {
    return (
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => (
          <div key={index} className="space-y-2" aria-hidden="true">
            <Skeleton className="h-3 w-20 rounded-lg bg-white/[0.04]" />
            <Skeleton className="h-10 rounded-lg bg-white/[0.06]" />
          </div>
        ))}
      </div>
    );
  }

  if (variant === "compact") {
    return <LoadingSkeleton lines={2} />;
  }

  return (
    <div className="space-y-3">
      <Skeleton className="h-16 rounded-lg bg-white/[0.04]" aria-hidden="true" />
      <LoadingSkeleton lines={rows} />
    </div>
  );
}
