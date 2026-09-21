"use client";

import Link from "next/link";
import { Activity, Plus } from "lucide-react";

import { MobileSidebarToggle } from "@/components/layout/app-sidebar";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";

export function AppTopbar() {
  return (
    <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-border bg-background px-4 sm:px-6 lg:px-8 xl:px-10">
      <div className="flex min-w-0 items-center gap-3">
        <MobileSidebarToggle />
        <Breadcrumbs />
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <div className="hidden items-center gap-2 text-xs text-muted-foreground md:flex">
          <Activity className="h-3.5 w-3.5 text-primary" aria-hidden="true" />
          <span>Consola operativa</span>
        </div>
        <span className="hidden h-5 w-px bg-border sm:block" aria-hidden="true" />
        <Link
          href="/audit/single"
          className="inline-flex h-9 items-center gap-2 rounded-md border border-primary/30 bg-primary px-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-[oklch(0.81_0.12_244)]"
        >
          <Plus className="h-3.5 w-3.5" aria-hidden="true" />
          <span className="hidden sm:inline">Nueva auditoría</span>
          <span className="sm:hidden">Nueva</span>
        </Link>
      </div>
    </header>
  );
}
