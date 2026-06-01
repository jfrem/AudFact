"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, Menu, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";

import { navigationSections, productLabel } from "@/lib/constants/navigation";
import { cn } from "@/lib/utils";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

function SidebarContent({ isCollapsed = false }: { isCollapsed?: boolean }) {
  const pathname = usePathname();

  // Encontrar el item de navegación más específico (ruta más larga) que coincide
  const activeHref = navigationSections
    .flatMap((s) => s.items)
    .filter(
      (item) =>
        pathname === item.href ||
        (item.href !== "/dashboard" && pathname.startsWith(item.href + "/"))
    )
    .sort((a, b) => b.href.length - a.href.length)[0]?.href;

  return (
    <>
      <div className={cn("mb-6 flex items-center gap-3", isCollapsed ? "justify-center px-0" : "px-1")}>
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] text-sm font-bold text-sky-300">
          A
        </div>
        {!isCollapsed && (
          <div className="min-w-0 flex-1 overflow-hidden">
            <p className="truncate [font-family:var(--font-heading)] text-lg font-semibold text-white">
              {productLabel}
            </p>
            <p className="truncate text-[11px] uppercase tracking-[0.18em] text-slate-500">
              Centro de control
            </p>
          </div>
        )}
      </div>

      <nav className="scrollbar-thin flex-1 space-y-5 overflow-y-auto overflow-x-hidden pr-1">
        {navigationSections.map((section) => (
          <div key={section.label} className="space-y-2">
            {!isCollapsed ? (
              <p className="px-3 text-[10px] font-semibold uppercase tracking-[0.24em] text-slate-500">
                {section.label}
              </p>
            ) : (
              <div className="mx-auto my-2 h-px w-7 bg-white/10" />
            )}
            <div className="space-y-1.5">
              {section.items.map((item) => {
                const Icon = item.icon;
                const active = item.href === activeHref;

                const content = (
                  <Link
                    href={item.href}
                    className={cn(
                      "group relative flex min-h-11 items-center rounded-lg border transition-colors",
                      isCollapsed ? "justify-center px-0 py-2.5" : "justify-between px-3 py-2",
                      active
                        ? "border-sky-500/30 bg-white/[0.06] text-white"
                        : "border-transparent text-slate-400 hover:border-white/10 hover:bg-white/[0.03] hover:text-slate-100",
                    )}
                  >
                    <span className={cn("flex items-center gap-3", isCollapsed && "justify-center")}>
                      <Icon className={cn("h-5 w-5 shrink-0", active ? "text-sky-300" : "text-slate-500 group-hover:text-slate-200")} />
                      {!isCollapsed && (
                        <span className="truncate text-sm font-medium">{item.label}</span>
                      )}
                    </span>
                    {!isCollapsed && (
                      <span
                        className={cn(
                          "inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-[10px] transition-colors",
                          active
                            ? "text-sky-300"
                            : "text-slate-600 group-hover:text-slate-400",
                        )}
                        aria-hidden="true"
                      >
                        <ChevronRight className="h-3.5 w-3.5" />
                      </span>
                    )}
                  </Link>
                );

                return isCollapsed ? (
                  <Tooltip key={item.href}>
                    <TooltipTrigger asChild>{content}</TooltipTrigger>
                    <TooltipContent side="right">{item.label}</TooltipContent>
                  </Tooltip>
                ) : (
                  <React.Fragment key={item.href}>{content}</React.Fragment>
                );
              })}
            </div>
          </div>
        ))}
      </nav>
    </>
  );
}

/** Sidebar desktop (persistente) */
export function AppSidebar() {
  const [isCollapsed, setIsCollapsed] = React.useState(true);

  return (
    <aside
      className={cn(
        "hidden shrink-0 flex-col rounded-xl border border-white/10 bg-[#111c2b] py-4 transition-[width,padding] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] lg:flex",
        isCollapsed ? "w-[76px] px-2.5" : "w-[17.5rem] px-4"
      )}
    >
      <div className={cn("mb-3 flex items-center", isCollapsed ? "justify-center" : "justify-end px-1")}>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={() => setIsCollapsed(!isCollapsed)}
              className="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-400 transition-colors hover:border-white/14 hover:bg-white/[0.05] hover:text-white"
              aria-label={isCollapsed ? "Expandir barra lateral" : "Colapsar barra lateral"}
            >
              {isCollapsed ? <PanelLeftOpen className="h-[18px] w-[18px]" /> : <PanelLeftClose className="h-[18px] w-[18px]" />}
            </button>
          </TooltipTrigger>
          <TooltipContent side="right">
            {isCollapsed ? "Expandir" : "Colapsar"}
          </TooltipContent>
        </Tooltip>
      </div>
      <SidebarContent isCollapsed={isCollapsed} />
    </aside>
  );
}

/** Botón hamburguesa + drawer mobile */
export function MobileSidebarToggle() {
  const [open, setOpen] = React.useState(false);
  const pathname = usePathname();

  // Cerrar el drawer al navegar
  React.useEffect(() => {
    setOpen(false);
  }, [pathname]);

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-300 transition-colors hover:border-white/14 hover:bg-white/[0.05] hover:text-white lg:hidden"
            aria-label="Abrir menú"
          >
            <Menu className="h-5 w-5" />
          </button>
        </TooltipTrigger>
        <TooltipContent>Abrir menú</TooltipContent>
      </Tooltip>

      <SheetContent
        side="left"
        showCloseButton={false}
        className="flex w-72 max-w-none flex-col rounded-r-xl bg-slate-900 px-4 py-4 sm:max-w-none lg:hidden"
      >
        <SheetHeader className="sr-only">
          <SheetTitle>Menú principal</SheetTitle>
          <SheetDescription>Navegación principal de AudFact.</SheetDescription>
        </SheetHeader>
        <div className="mb-3 flex justify-end">
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-400 transition-colors hover:border-white/14 hover:bg-white/[0.05] hover:text-white"
                aria-label="Cerrar menú"
              >
                <X className="h-5 w-5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>Cerrar menú</TooltipContent>
          </Tooltip>
        </div>
        <SidebarContent />
      </SheetContent>
    </Sheet>
  );
}
