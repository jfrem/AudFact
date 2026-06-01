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
          aria-busy={navigation.isPending}
          aria-label="Seleccionar cliente EPS"
          className={cn(
            "group flex h-14 w-full cursor-pointer items-center gap-3 rounded-lg border px-4 text-left transition-all duration-200",
            open
              ? "border-cyan-500/40 bg-white/[0.04]"
              : "border-white/[0.08] bg-white/[0.03] hover:border-white/[0.14] hover:bg-white/[0.05]",
          )}
        >
          {/* Icon */}
          <div
            className={cn(
              "flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors",
              open || selected
                ? "border border-white/[0.08] bg-white/[0.03] text-cyan-400"
                : "bg-white/[0.06] text-slate-500 group-hover:text-slate-400",
            )}
          >
            <Building2 className="h-4 w-4" />
          </div>

          {/* Label */}
          <div className="flex-1">
            {selectedDisplay ? (
              <>
                <p className="truncate text-sm font-semibold text-white">
                  {selectedDisplay.nitCom}
                </p>
                <p className="text-[11px] text-slate-500">NitSec: {selectedDisplay.nitSec}</p>
              </>
            ) : (
              <p className="text-sm text-slate-500">Seleccionar EPS / cliente...</p>
            )}
          </div>

          {/* Right side */}
          <div className="flex shrink-0 items-center gap-2">
            {selectedDisplay && (
              <span className="rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 text-[10px] font-bold tracking-wider text-cyan-300">
                {selectedDisplay.nitSec}
              </span>
            )}
            <ChevronDown
              className={cn(
                "h-4 w-4 text-slate-500 transition-transform duration-200",
                open && "rotate-180",
              )}
            />
          </div>
        </button>
      </PopoverTrigger>

      <PopoverContent
        className="w-[--radix-popover-trigger-width] border-white/[0.08] bg-[#0d1526] p-0 shadow-2xl shadow-black/50"
        align="start"
        sideOffset={6}
      >
        <Command className="border-0 bg-transparent">
          {/* Search */}
          <div className="flex items-center border-b border-white/[0.06] px-3">
            <Search className="mr-2 h-4 w-4 shrink-0 text-slate-500" />
            <CommandInput
              placeholder="Buscar por nombre o código..."
              className="h-11 flex-1 border-0 bg-transparent text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-0"
            />
          </div>

          <CommandList className="max-h-72 overflow-y-auto">
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
                        "group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors",
                        isSelected
                          ? "border border-white/[0.08] bg-white/[0.04] text-white"
                          : "text-slate-300 hover:bg-white/[0.04] hover:text-white",
                        pendingClientId === c.nitSec && "pointer-events-none bg-sky-500/[0.08] text-sky-200",
                      )}
                    >
                      {/* Avatar */}
                      <div
                        className={cn(
                          "flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-xs font-bold transition-colors",
                          isSelected
                            ? "border border-white/[0.08] bg-white/[0.03] text-cyan-300"
                            : "bg-white/[0.06] text-slate-500 group-hover:bg-white/[0.09] group-hover:text-slate-300",
                        )}
                      >
                        {initials || "?"}
                      </div>

                      {/* Info */}
                      <div className="flex-1">
                        <p className="truncate text-sm font-medium leading-tight">
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
                <CommandSeparator className="bg-white/[0.05]" />
                <CommandGroup className="px-1 py-1">
                  <CommandItem
                    value="__create_new__"
                    onSelect={() => {
                      setOpen(false);
                      onCreateNew();
                    }}
                    className="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-slate-300 transition-colors hover:bg-white/[0.04] hover:text-cyan-300"
                  >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-dashed border-white/[0.12] bg-white/[0.03] text-cyan-400">
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
