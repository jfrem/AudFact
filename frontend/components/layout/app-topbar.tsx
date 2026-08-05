"use client";

import * as React from "react";
import { LayoutPanelTop } from "lucide-react";

import { MobileSidebarToggle } from "@/components/layout/app-sidebar";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";

export function AppTopbar() {
  return (
    <header className="flex items-center justify-between gap-3 border-b border-white/[0.07] px-1 pb-3">
      <div className="flex min-w-0 items-center gap-3">
        <div className="lg:hidden">
          <MobileSidebarToggle />
        </div>
        <div className="min-w-0">
          <Breadcrumbs />
        </div>
      </div>
      <div className="hidden items-center gap-2 rounded-md border border-white/8 bg-white/[0.03] px-2.5 py-1.5 text-xs text-slate-500 md:flex">
        <LayoutPanelTop className="h-3.5 w-3.5" />
        <span>Interfaz operativa</span>
      </div>
    </header>
  );
}
