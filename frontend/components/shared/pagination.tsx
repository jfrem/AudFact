import Link from "next/link";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { formatNumber } from "@/lib/formatters";
import { cn } from "@/lib/utils";

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
  return (
    <nav aria-label="Paginación">
      <div className="surface-subtle flex flex-col items-center justify-between gap-3 rounded-lg px-4 py-3 sm:flex-row">
        <p className="text-sm text-slate-400">
          {formatNumber(total)} {label} · Página {formatNumber(page)} de{" "}
          {formatNumber(totalPages)}
        </p>
        <div className="flex items-center gap-2">
          <Link
            href={buildHref(Math.max(1, page - 1))}
            className={cn(
              "inline-flex h-10 items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm text-slate-300 transition-colors hover:border-white/14 hover:bg-white/[0.05] hover:text-white",
              page <= 1 && "pointer-events-none opacity-40"
            )}
            aria-disabled={page <= 1}
            tabIndex={page <= 1 ? -1 : 0}
          >
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
            Anterior
          </Link>
          <Link
            href={buildHref(Math.min(totalPages, page + 1))}
            className={cn(
              "inline-flex h-10 items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm text-slate-300 transition-colors hover:border-white/14 hover:bg-white/[0.05] hover:text-white",
              page >= totalPages && "pointer-events-none opacity-40"
            )}
            aria-disabled={page >= totalPages}
            tabIndex={page >= totalPages ? -1 : 0}
          >
            Siguiente
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </nav>
  );
}
