import { formatNumber } from "@/lib/formatters";
import { PendingPaginationControls } from "@/components/shared/pending-pagination-controls";

export function Pagination({
  page,
  totalPages,
  total,
  buildHref,
  label = "registros",
}: {
  page: number;
  totalPages: number;
  total: number;
  buildHref: (page: number) => string;
  label?: string;
}) {
  const previousPage = Math.max(1, page - 1);
  const nextPage = Math.min(totalPages, page + 1);

  return (
    <nav aria-label="Paginación">
      <div className="surface-subtle flex flex-col items-center justify-between gap-3 rounded-lg px-4 py-3 sm:flex-row">
        <p className="text-sm text-slate-400">
          {formatNumber(total)} {label} · Página {formatNumber(page)} de{" "}
          {formatNumber(totalPages)}
        </p>
        <PendingPaginationControls
          nextDisabled={page >= totalPages}
          nextHref={buildHref(nextPage)}
          previousDisabled={page <= 1}
          previousHref={buildHref(previousPage)}
        />
      </div>
    </nav>
  );
}
