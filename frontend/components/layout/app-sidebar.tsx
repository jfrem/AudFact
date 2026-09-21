"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, Menu, PanelLeftClose, PanelLeftOpen, X } from "lucide-react";

import { navigationSections, productLabel } from "@/lib/constants/navigation";
import { cn } from "@/lib/utils";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

function getActiveHref(pathname: string) {
  return navigationSections
    .flatMap((section) => section.items)
    .filter(
      (item) =>
        pathname === item.href ||
        (item.href !== "/dashboard" && pathname.startsWith(`${item.href}/`)),
    )
    .sort((a, b) => b.href.length - a.href.length)[0]?.href;
}

function ProductMark({ collapsed, onNavigate }: { collapsed: boolean; onNavigate?: () => void }) {
  return (
    <Link
      href="/dashboard"
      onClick={onNavigate}
      className={cn(
        "flex min-w-0 flex-1 items-center gap-3 rounded-md p-1 text-foreground transition-colors hover:bg-[var(--surface-hover)] motion-reduce:transition-none",
        collapsed && "justify-center px-0",
      )}
      aria-label={`${productLabel}, ir a la mesa de control`}
    >
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-[var(--border-strong)] bg-secondary font-display text-sm font-semibold tracking-[-0.04em] text-foreground" aria-hidden="true">
        AF
      </span>
      {!collapsed ? (
        <span className="min-w-0">
          <span className="block truncate font-display text-lg font-semibold tracking-tight">
            {productLabel}
          </span>
          <span className="block truncate text-xs leading-5 text-muted-foreground">
            Auditoría documental
          </span>
        </span>
      ) : null}
    </Link>
  );
}

function SidebarContent({
  collapsed = false,
  onNavigate,
  headerAction,
  id,
}: {
  collapsed?: boolean;
  onNavigate?: () => void;
  headerAction?: React.ReactNode;
  id?: string;
}) {
  const pathname = usePathname();
  const activeHref = getActiveHref(pathname);

  return (
    <>
      <div className="flex min-h-16 shrink-0 items-center gap-2 border-b border-border pb-3">
        <ProductMark collapsed={collapsed} onNavigate={onNavigate} />
        {headerAction}
      </div>
      <nav id={id} className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto overscroll-contain px-1 py-5" aria-label="Navegación principal">
        {navigationSections.map((section, index) => (
          <section key={section.label} aria-label={section.label} className="shrink-0">
            {!collapsed ? (
              <p className="mb-2 px-3 text-[11px] font-semibold uppercase leading-4 tracking-[0.12em] text-muted-foreground">
                {section.label}
              </p>
            ) : index > 0 ? (
              <div className="mx-auto mb-5 h-px w-5 bg-border" aria-hidden="true" />
            ) : null}
            <ul className="flex flex-col gap-1">
              {section.items.map((item) => {
                const Icon = item.icon;
                const active = item.href === activeHref;
                const link = (
                  <Link
                    href={item.href}
                    onClick={onNavigate}
                    aria-label={item.label}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "group flex min-h-11 items-center gap-3 rounded-md border px-3 py-2 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary motion-reduce:transition-none",
                      collapsed && "justify-center px-0",
                      active
                        ? "border-primary/25 bg-[var(--surface-selected)] text-foreground"
                        : "border-transparent text-muted-foreground hover:bg-[var(--surface-hover)] hover:text-foreground",
                    )}
                  >
                    <Icon
                      className={cn(
                        "h-[18px] w-[18px] shrink-0",
                        active ? "text-primary" : "text-muted-foreground group-hover:text-foreground",
                      )}
                      aria-hidden="true"
                    />
                    {!collapsed ? (
                      <>
                        <span className={cn("min-w-0 flex-1 leading-5", active ? "font-semibold" : "font-medium")}>{item.label}</span>
                        {active ? <ChevronRight className="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" /> : null}
                      </>
                    ) : null}
                  </Link>
                );

                return (
                  <li key={item.href}>
                    {collapsed ? (
                      <Tooltip>
                        <TooltipTrigger asChild>{link}</TooltipTrigger>
                        <TooltipContent side="right" sideOffset={12}>{item.label}</TooltipContent>
                      </Tooltip>
                    ) : link}
                  </li>
                );
              })}
            </ul>
          </section>
        ))}
      </nav>
    </>
  );
}

export function AppSidebar() {
  const [collapsed, setCollapsed] = React.useState(true);
  const navigationId = React.useId();

  return (
    <aside
      className={cn(
        "sticky top-0 hidden h-dvh shrink-0 flex-col border-r border-border bg-[var(--surface-raised)] px-3 pt-4 pb-3 lg:flex",
        collapsed ? "w-[76px] px-2" : "w-[280px]",
      )}
    >
      <SidebarContent id={navigationId} collapsed={collapsed} />
      <div className="shrink-0 border-t border-border px-1 pt-3">
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={() => setCollapsed((value) => !value)}
              className={cn("flex min-h-11 w-full items-center gap-3 rounded-md px-3 text-sm text-muted-foreground transition-colors hover:bg-[var(--surface-hover)] hover:text-foreground motion-reduce:transition-none", collapsed && "justify-center px-0")}
              aria-label={collapsed ? "Expandir navegación" : "Colapsar navegación"}
              aria-expanded={!collapsed}
              aria-controls={navigationId}
            >
              {collapsed ? <PanelLeftOpen className="h-[18px] w-[18px] shrink-0" aria-hidden="true" /> : <PanelLeftClose className="h-[18px] w-[18px] shrink-0" aria-hidden="true" />}
              {!collapsed ? <span>Colapsar navegación</span> : null}
            </button>
          </TooltipTrigger>
          {collapsed ? <TooltipContent side="right" sideOffset={12}>Expandir navegación</TooltipContent> : null}
        </Tooltip>
      </div>
    </aside>
  );
}

export function MobileSidebarToggle() {
  const [open, setOpen] = React.useState(false);
  const pathname = usePathname();

  React.useEffect(() => {
    setOpen(false);
  }, [pathname]);

  React.useEffect(() => {
    const desktop = window.matchMedia("(min-width: 1024px)");
    const closeOnDesktop = () => {
      if (desktop.matches) setOpen(false);
    };
    desktop.addEventListener("change", closeOnDesktop);
    return () => desktop.removeEventListener("change", closeOnDesktop);
  }, []);

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <button
          type="button"
          className="inline-flex h-11 w-11 items-center justify-center rounded-md border border-border bg-transparent text-muted-foreground transition-colors hover:bg-[var(--surface-hover)] hover:text-foreground motion-reduce:transition-none lg:hidden"
          aria-label="Abrir navegación"
        >
          <Menu className="h-[18px] w-[18px]" aria-hidden="true" />
        </button>
      </SheetTrigger>
      <SheetContent
        side="left"
        showCloseButton={false}
        className="flex h-dvh w-[min(320px,calc(100vw-24px))] max-w-none flex-col gap-0 bg-[var(--surface-raised)] px-3 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:max-w-none motion-reduce:animate-none motion-reduce:transition-none lg:hidden"
      >
        <SheetHeader className="sr-only">
          <SheetTitle>Navegación principal</SheetTitle>
          <SheetDescription>Accesos operativos de AudFact.</SheetDescription>
        </SheetHeader>
        <SidebarContent
          onNavigate={() => setOpen(false)}
          headerAction={
            <SheetClose asChild>
              <button
                type="button"
                className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-[var(--surface-hover)] hover:text-foreground motion-reduce:transition-none"
                aria-label="Cerrar navegación"
              >
                <X className="h-[18px] w-[18px]" aria-hidden="true" />
              </button>
            </SheetClose>
          }
        />
      </SheetContent>
    </Sheet>
  );
}
