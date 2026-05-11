"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, Home } from "lucide-react";

/** Human-readable label mapping for URL path segments. */
const segmentLabels: Record<string, string> = {
  dashboard: "Dashboard",
  audit: "Auditoría",
  single: "Individual",
  batch: "Lote",
  results: "Resultados",
  jobs: "Jobs",
  "documents-history": "Historial documental",
  invoices: "Facturas",
  clients: "Clientes",
  dispensation: "Dispensación",
  observability: "Observabilidad",
};

function labelFor(segment: string): string {
  return segmentLabels[segment] ?? decodeURIComponent(segment);
}

export function Breadcrumbs() {
  const pathname = usePathname();
  const segments = pathname.split("/").filter(Boolean);

  if (segments.length <= 1) return null;

  const crumbs = segments.map((seg, i) => ({
    label: labelFor(seg),
    href: `/${segments.slice(0, i + 1).join("/")}`,
    isLast: i === segments.length - 1,
  }));

  return (
    <nav aria-label="Breadcrumb" className="mb-1">
      <ol className="flex flex-wrap items-center gap-1.5 text-[12px] text-slate-500">
        <li className="flex items-center gap-1">
          <Link
            href="/dashboard"
            className="inline-flex h-6 w-6 items-center justify-center rounded-md border border-white/8 bg-white/[0.03] transition-colors hover:border-white/12 hover:text-slate-200"
            aria-label="Inicio"
          >
            <Home className="h-3.5 w-3.5" />
          </Link>
          <ChevronRight className="h-3 w-3" />
        </li>
        {crumbs.map((crumb) => (
          <li key={crumb.href} className="flex items-center gap-1">
            {crumb.isLast ? (
              <span className="font-medium text-slate-300" aria-current="page">
                {crumb.label}
              </span>
            ) : (
              <>
                <Link
                  href={crumb.href}
                  className="transition-colors hover:text-slate-300"
                >
                  {crumb.label}
                </Link>
                <ChevronRight className="h-3 w-3" />
              </>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
