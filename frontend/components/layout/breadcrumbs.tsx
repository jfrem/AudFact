"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, Home } from "lucide-react";

const segmentLabels: Record<string, string> = {
  dashboard: "Mesa de control",
  audit: "Auditoría",
  single: "Individual",
  batch: "Batch",
  results: "Resultados",
  jobs: "Jobs",
  flow: "Flujo",
  "documents-history": "Historial documental",
  invoices: "Facturas",
  clients: "Clientes",
  "audit-config": "Configuración",
  dispensation: "Dispensación",
  observability: "Observabilidad",
};

function labelFor(segment: string): string {
  return segmentLabels[segment] ?? decodeURIComponent(segment);
}

export function Breadcrumbs() {
  const pathname = usePathname();
  const segments = pathname.split("/").filter(Boolean);

  if (segments.length <= 1) {
    return (
      <span className="text-xs font-medium text-muted-foreground">
        Mesa de control
      </span>
    );
  }

  const crumbs = segments.map((segment, index) => ({
    label: labelFor(segment),
    href: `/${segments.slice(0, index + 1).join("/")}`,
    isLast: index === segments.length - 1,
  }));

  return (
    <nav aria-label="Ruta actual" className="min-w-0">
      <ol className="flex min-w-0 items-center gap-1 text-xs">
        <li className="shrink-0">
          <Link
            href="/dashboard"
            className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-[var(--surface-hover)] hover:text-foreground"
            aria-label="Mesa de control"
          >
            <Home className="h-3.5 w-3.5" />
          </Link>
        </li>
        {crumbs.map((crumb) => (
          <li key={crumb.href} className="flex min-w-0 items-center gap-1">
            <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground/50" aria-hidden="true" />
            {crumb.isLast ? (
              <span className="max-w-44 truncate font-medium text-foreground" aria-current="page">
                {crumb.label}
              </span>
            ) : (
              <Link
                href={crumb.href}
                className="hidden max-w-36 truncate text-muted-foreground transition-colors hover:text-foreground sm:block"
              >
                {crumb.label}
              </Link>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
