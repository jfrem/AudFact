import { Skeleton } from "@/components/ui/skeleton";

export function LoadingSkeleton({
  lines = 4,
  className = "",
}: {
  lines?: number;
  className?: string;
}) {
  return (
    <div className={`space-y-3 ${className}`} role="status" aria-label="Cargando">
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton
          key={i}
          className="h-4"
          style={{ width: `${85 - i * 12}%` }}
          aria-hidden="true"
        />
      ))}
    </div>
  );
}

export function PageLoadingHeader({
  eyebrowWidth = "w-24",
  titleWidth = "w-56",
  descriptionWidth = "w-80",
  withDescription = false,
}: {
  eyebrowWidth?: string;
  titleWidth?: string;
  descriptionWidth?: string;
  withDescription?: boolean;
}) {
  return (
    <div className="space-y-2" role="status" aria-label="Cargando encabezado">
      <Skeleton className={`h-4 ${eyebrowWidth}`} aria-hidden="true" />
      <Skeleton className={`h-8 ${titleWidth}`} aria-hidden="true" />
      {withDescription ? (
        <Skeleton className={`h-4 ${descriptionWidth}`} aria-hidden="true" />
      ) : null}
    </div>
  );
}

export function CardSkeleton() {
  return (
    <div className="rounded-xl border border-white/10 bg-card px-5 py-5" role="status" aria-label="Cargando tarjeta">
      <div className="mb-4 space-y-2 border-b border-white/8 pb-4" aria-hidden="true">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-3 w-64" />
      </div>
      <LoadingSkeleton />
    </div>
  );
}

export function TableSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-2" role="status" aria-label="Cargando tabla">
      <Skeleton className="h-10" aria-hidden="true" />
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton
          key={i}
          className="h-12"
          aria-hidden="true"
        />
      ))}
    </div>
  );
}

export function MetricGridSkeleton({ items = 4 }: { items?: number }) {
  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4" role="status" aria-label="Cargando métricas">
      {Array.from({ length: items }).map((_, i) => (
        <div key={i} className="rounded-xl border border-white/10 bg-card p-5" aria-hidden="true">
          <div className="flex items-center gap-2">
            <Skeleton className="h-8 w-8" />
            <Skeleton className="h-4 w-24" />
          </div>
          <Skeleton className="mt-4 h-8 w-20" />
          <Skeleton className="mt-5 h-3 w-32" />
        </div>
      ))}
    </div>
  );
}
