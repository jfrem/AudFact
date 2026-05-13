"use client";

import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { Input } from "@/components/ui/input";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import type { ClientRecord } from "@/lib/schemas/domain";

interface InvoicesFilterFormProps {
  allClients: ClientRecord[];
  initialFacNitSec?: string;
  initialDateFrom?: string;
  initialDateTo?: string;
  initialLimit?: number;
}

export function InvoicesFilterForm({
  allClients,
  initialFacNitSec = "",
  initialDateFrom = "",
  initialDateTo = "",
  initialLimit = 20,
}: InvoicesFilterFormProps) {
  const router = useRouter();

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    const facNitSec = formData.get("facNitSec");
    const dateFrom = formData.get("dateFrom");
    const dateTo = formData.get("dateTo");
    const limit = formData.get("limit");

    if (facNitSec) params.set("facNitSec", String(facNitSec));
    if (dateFrom) params.set("dateFrom", String(dateFrom));
    if (dateTo) params.set("dateTo", String(dateTo));
    if (limit && limit !== "20") params.set("limit", String(limit));

    router.push(`?${params.toString()}`);
  };

  return (
    <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" onSubmit={handleSubmit}>
      <div className="space-y-1.5">
        <label htmlFor="invoices-client-selector" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Cliente
        </label>
        <ClientSelectorCombo
          id="invoices-client-selector"
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

      <div className="space-y-1.5">
        <label htmlFor="dateFrom-input" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Desde
        </label>
        <DatePickerInput
          id="dateFrom-input"
          name="dateFrom"
          defaultValue={initialDateFrom}
        />
      </div>

      <div className="space-y-1.5">
        <label htmlFor="dateTo-input" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Hasta
        </label>
        <DatePickerInput
          id="dateTo-input"
          name="dateTo"
          defaultValue={initialDateTo}
        />
      </div>

      <div className="space-y-1.5">
        <label htmlFor="limit-input" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Límite
        </label>
        <Input
          id="limit-input"
          name="limit"
          defaultValue={String(initialLimit)}
        />
      </div>

      <div className="flex items-end">
        <Button type="submit" className="w-full">Buscar</Button>
      </div>
    </form>
  );
}
