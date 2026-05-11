"use client";

import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import type { ClientRecord } from "@/lib/schemas/domain";

interface AuditResultsFilterFormProps {
  allClients: ClientRecord[];
  initialFacNitSec?: string;
  initialFacNro?: string;
  initialDateFrom?: string;
  initialDateTo?: string;
  initialPageSize?: number;
}

export function AuditResultsFilterForm({
  allClients,
  initialFacNitSec = "",
  initialFacNro = "",
  initialDateFrom = "",
  initialDateTo = "",
  initialPageSize = 20,
}: AuditResultsFilterFormProps) {
  const router = useRouter();

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    params.set("page", "1");
    params.set("pageSize", String(initialPageSize));

    const facNitSec = formData.get("facNitSec");
    const facNro = formData.get("facNro");
    const dateFrom = formData.get("dateFrom");
    const dateTo = formData.get("dateTo");

    if (facNitSec) params.set("facNitSec", String(facNitSec));
    if (facNro) params.set("facNro", String(facNro));
    if (dateFrom) params.set("dateFrom", String(dateFrom));
    if (dateTo) params.set("dateTo", String(dateTo));

    router.push(`?${params.toString()}`);
  };

  return (
    <form
      className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(8rem,0.8fr)]"
      onSubmit={handleSubmit}
    >
      <div className="min-w-0 space-y-1.5">
        <label className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Cliente
        </label>
        <ClientSelectorCombo
          clients={allClients}
          value={initialFacNitSec}
          onValueChange={(value) => {
            const input = document.querySelector(
              'input[name="facNitSec"]'
            ) as HTMLInputElement;
            if (input) input.value = value;
          }}
          placeholder="Selecciona un cliente"
        />
        <input
          type="hidden"
          name="facNitSec"
          defaultValue={initialFacNitSec}
        />
      </div>

      <label className="min-w-0 space-y-1.5">
        <span className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Factura
        </span>
        <Input
          name="facNro"
          defaultValue={initialFacNro}
          className="min-w-0"
        />
      </label>

      <label className="min-w-0 space-y-1.5">
        <span className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Desde
        </span>
        <Input
          type="date"
          name="dateFrom"
          defaultValue={initialDateFrom}
          className="min-w-0"
        />
      </label>

      <label className="min-w-0 space-y-1.5">
        <span className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Hasta
        </span>
        <Input
          type="date"
          name="dateTo"
          defaultValue={initialDateTo}
          className="min-w-0"
        />
      </label>

      <div className="flex min-w-0 items-end">
        <Button type="submit" className="w-full">Buscar</Button>
      </div>
    </form>
  );
}
