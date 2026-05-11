"use client";

import * as React from "react";
import { Check, ChevronDown, Building2, Search } from "lucide-react";
import { cn } from "@/lib/utils";
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
} from "@/components/ui/command";
import type { ClientRecord } from "@/lib/schemas/domain";
import { extractClient } from "@/lib/helpers/extract-client";

interface ClientSelectorComboProps {
  clients: ClientRecord[];
  value: string;
  onValueChange: (value: string) => void;
  placeholder?: string;
  id?: string;
}

export function ClientSelectorCombo({
  clients,
  value,
  onValueChange,
  placeholder = "Seleccionar cliente...",
  id,
}: ClientSelectorComboProps) {
  const [open, setOpen] = React.useState(false);
  const selected = clients.find((c) => String(extractClient(c).nitSec) === value) ?? null;
  const selectedDisplay = selected ? extractClient(selected) : null;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          id={id}
          role="combobox"
          aria-expanded={open}
          className={cn(
            "group flex h-11 w-full min-w-0 cursor-pointer items-center gap-2 rounded-lg border px-3 text-left text-sm transition-all duration-200",
            open
              ? "border-sky-500/40 bg-white/[0.04]"
              : "border-white/[0.08] bg-white/[0.03] hover:border-white/[0.14] hover:bg-white/[0.05]",
          )}
        >
          {/* Icon */}
          <div
            className={cn(
              "flex h-6 w-6 shrink-0 items-center justify-center rounded-lg transition-colors",
              open || selected
                ? "border border-white/[0.08] bg-white/[0.03] text-sky-400"
                : "bg-white/[0.06] text-slate-500 group-hover:text-slate-400",
            )}
          >
            <Building2 className="h-3.5 w-3.5" />
          </div>

          {/* Label */}
          <div className="min-w-0 flex-1 overflow-hidden">
            {selectedDisplay ? (
              <span className="block truncate font-medium text-white" title={selectedDisplay.nitCom}>
                {selectedDisplay.nitCom}
              </span>
            ) : (
              <span className="block truncate text-slate-500">{placeholder}</span>
            )}
          </div>

          {/* Chevron */}
          <ChevronDown
            className={cn(
              "h-4 w-4 shrink-0 text-slate-500 transition-transform duration-200",
              open && "rotate-180",
            )}
          />
        </button>
      </PopoverTrigger>

      <PopoverContent
        className="w-[--radix-popover-trigger-width] max-w-[calc(100vw-2rem)] border-white/[0.08] bg-[#0d1526] p-0 shadow-2xl shadow-black/50"
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
                <Building2 className="h-8 w-8 text-slate-700" />
                <p className="text-sm text-slate-500">Sin resultados</p>
                <p className="text-xs text-slate-700">
                  Intenta con el nombre o NitSec del cliente
                </p>
              </div>
            </CommandEmpty>

            {clients.length > 0 && (
              <CommandGroup className="px-1 py-1">
                {clients.map((client) => {
                  const c = extractClient(client);
                  const isSelected = c.nitSec === value;
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
                      onSelect={() => {
                        onValueChange(c.nitSec);
                        setOpen(false);
                      }}
                      className={cn(
                        "group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors",
                        isSelected
                          ? "border border-white/[0.08] bg-white/[0.04] text-white"
                          : "text-slate-300 hover:bg-white/[0.04] hover:text-white",
                      )}
                    >
                      {/* Avatar */}
                      <div
                        className={cn(
                          "flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold transition-colors",
                          isSelected
                            ? "border border-white/[0.08] bg-white/[0.03] text-sky-300"
                            : "bg-white/[0.06] text-slate-500 group-hover:bg-white/[0.09] group-hover:text-slate-300",
                        )}
                      >
                        {initials || "?"}
                      </div>

                      {/* Info */}
                      <div className="flex-1 min-w-0">
                        <p className="truncate text-sm font-medium">
                          {c.nitCom}
                        </p>
                        <p className="mt-0.5 font-mono text-[11px] text-slate-500">
                          {c.nitSec}
                        </p>
                      </div>

                      {/* Check */}
                      {isSelected ? (
                        <Check className="h-4 w-4 shrink-0 text-sky-400" />
                      ) : (
                        <div className="h-4 w-4 shrink-0" />
                      )}
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
