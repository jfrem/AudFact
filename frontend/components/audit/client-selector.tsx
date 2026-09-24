"use client";

import * as React from "react";
import { Check, ChevronDown, Building2, Plus, Search, Users } from "lucide-react";
import { cn } from "@/lib/utils";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import {
  Popover,
  PopoverTrigger,
  PopoverContent,
} from "@/components/ui/popover";
import {
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
  CommandSeparator,
} from "@/components/ui/command";
import type { ClientRecord } from "@/lib/schemas/domain";
import { extractClient } from "@/lib/helpers/extract-client";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

export function ClientSelector({
  clients,
  currentClientId,
  onCreateNew,
}: {
  clients: ClientRecord[];
  currentClientId: string;
  onCreateNew?: () => void;
}) {
  const navigation = usePendingNavigation();
  const listboxId = React.useId();
  const [open, setOpen] = React.useState(false);
  const [pendingClientId, setPendingClientId] = React.useState<string | null>(null);

  const selected = clients.find((c) => extractClient(c).nitSec === currentClientId) ?? null;
  const selectedDisplay = selected ? extractClient(selected) : null;

  React.useEffect(() => {
    if (!navigation.isPending) {
      setPendingClientId(null);
    }
  }, [navigation.isPending]);

  const handleSelect = (record: ClientRecord) => {
    const nextClientId = extractClient(record).nitSec;
    setOpen(false);
    setPendingClientId(nextClientId);
    navigation.push(`/clients/audit-config?clientId=${nextClientId}`);
  };

  return (
    <>
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          role="combobox"
          aria-expanded={open}
          aria-controls={listboxId}
          aria-busy={navigation.isPending}
          aria-label="Seleccionar cliente EPS"
          className={cn(
            "group flex h-11 w-full min-w-0 max-w-full cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-left transition-colors",
            open
              ? "border-primary/45 bg-primary/10"
              : "border-border bg-background hover:border-[var(--border-strong)] hover:bg-[var(--surface-hover)]",
          )}
        >
          {/* Icon */}
          <div
            className={cn(
              "flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors",
              open || selected
                ? "border border-primary/30 bg-primary/10 text-primary"
                : "border border-border bg-muted text-muted-foreground",
            )}
          >
            <Building2 className="h-3.5 w-3.5" />
          </div>

          {/* Label */}
          <div className="min-w-0 flex-1 overflow-hidden">
            {selectedDisplay ? (
              <>
                <p
                  className="truncate text-xs sm:text-sm font-semibold text-foreground leading-tight"
                  title={selectedDisplay.nitCom}
                >
                  {selectedDisplay.nitCom}
                </p>
                <p className="truncate text-[10px] text-muted-foreground font-mono leading-none mt-0.5">
                  NitSec: {selectedDisplay.nitSec}
                </p>
              </>
            ) : (
              <p className="truncate text-xs sm:text-sm text-muted-foreground">
                Seleccionar EPS / cliente...
              </p>
            )}
          </div>

          {/* Right side */}
          <div className="flex shrink-0 items-center gap-1.5">
            <ChevronDown
              className={cn(
                "h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200",
                open && "rotate-180",
              )}
            />
          </div>
        </button>
      </PopoverTrigger>

      <PopoverContent
        className="w-[--radix-popover-trigger-width] min-w-[320px] max-w-[calc(100vw-2rem)] p-0"
        align="end"
        sideOffset={6}
      >
        <Command className="border-0 bg-transparent">
          {/* Search */}
          <div className="flex items-center border-b border-border px-3">
            <Search className="mr-2 h-4 w-4 shrink-0 text-slate-500" />
            <CommandInput
              placeholder="Buscar por nombre o código..."
              className="h-11 flex-1 border-0 bg-transparent text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-0"
            />
          </div>

          <CommandList id={listboxId} className="max-h-72 overflow-y-auto">
            <CommandEmpty>
              <div className="flex flex-col items-center gap-2 py-8 text-center">
                <Users className="h-8 w-8 text-slate-700" />
                <p className="text-sm text-slate-500">Sin resultados</p>
                <p className="text-xs text-slate-700">
                  Intenta con el nombre o NitSec del cliente
                </p>
              </div>
            </CommandEmpty>

            {clients.length > 0 && (
              <CommandGroup
                heading=""
                className="px-1 py-1"
              >
                {clients.map((client) => {
                  const c = extractClient(client);
                  const isSelected = c.nitSec === currentClientId;
                  const initials = c.nitCom
                    .split(" ")
                    .slice(0, 2)
                    .map((w) => w[0])
                    .join("")
                    .toUpperCase();

                  return (
                    <CommandItem
                      key={c.nitSec}
                      value={`${c.nitCom} ${c.nitSec}`}
                      onSelect={() => handleSelect(client)}
                      className={cn(
                        "group flex cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 transition-colors",
                        isSelected
                          ? "border border-primary/25 bg-primary/10 text-foreground"
                          : "text-muted-foreground hover:bg-[var(--surface-hover)] hover:text-foreground",
                        pendingClientId === c.nitSec && "pointer-events-none bg-sky-500/[0.08] text-sky-200",
                      )}
                    >
                      {/* Avatar */}
                      <div
                        className={cn(
                          "flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-xs font-bold transition-colors",
                          isSelected
                            ? "border border-primary/25 bg-primary/10 text-primary"
                            : "border border-border bg-muted text-muted-foreground",
                        )}
                      >
                        {initials || "?"}
                      </div>

                      {/* Info */}
                      <div className="min-w-0 flex-1 overflow-hidden">
                        <p className="truncate text-sm font-medium leading-tight" title={c.nitCom}>
                          {c.nitCom}
                        </p>
                        <p className="mt-0.5 font-mono text-[11px] text-slate-500">
                          {c.nitSec}
                        </p>
                      </div>

                      {/* Check */}
                      {isSelected ? (
                        <Check className="h-4 w-4 shrink-0 text-cyan-400" />
                      ) : pendingClientId === c.nitSec ? (
                        <span className="h-4 w-4 shrink-0 animate-pulse rounded-full bg-sky-400" />
                      ) : (
                        <div className="h-4 w-4 shrink-0" />
                      )}
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            )}
            {/* Create new */}
            {onCreateNew && (
              <>
                <CommandSeparator />
                <CommandGroup className="px-1 py-1">
                  <CommandItem
                    value="__create_new__"
                    onSelect={() => {
                      setOpen(false);
                      onCreateNew();
                    }}
                    className="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-muted-foreground transition-colors hover:bg-[var(--surface-hover)] hover:text-primary"
                  >
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-dashed border-primary/35 bg-primary/5 text-primary">
                      <Plus className="h-4 w-4" />
                    </div>
                    <div className="flex-1">
                      <p className="text-sm font-medium">Crear configuración nueva</p>
                      <p className="mt-0.5 text-[11px] text-slate-600">
                        Inicializar config para un cliente sin configuración
                      </p>
                    </div>
                  </CommandItem>
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    {navigation.isPending ? (
      <BackendRequestSkeleton
        className="mt-3"
        description="El backend está cargando la configuración del cliente."
        title="Cambiando cliente"
        variant="compact"
      />
    ) : null}
    </>
  );
}
