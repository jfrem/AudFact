"use client";

import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import type { ClientRecord } from "@/lib/schemas/domain";

interface DocumentsHistoryFilterFormProps {
  allClients: ClientRecord[];
  initialFacNitSec?: string;
  initialFacNro?: string;
}

export function DocumentsHistoryFilterForm({
  allClients,
  initialFacNitSec = "",
  initialFacNro = "",
}: DocumentsHistoryFilterFormProps) {
  const router = useRouter();

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    const facNitSec = formData.get("facNitSec");
    const facNro = formData.get("facNro");

    if (facNitSec) params.set("facNitSec", String(facNitSec));
    if (facNro) params.set("facNro", String(facNro));

    router.push(`?${params.toString()}`);
  };

  return (
    <form className="grid gap-3 md:grid-cols-[1fr_1fr_auto]" onSubmit={handleSubmit}>
      <div className="space-y-1.5">
        <label htmlFor="client-selector" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Cliente
        </label>
        <ClientSelectorCombo
          id="client-selector"
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
        <label htmlFor="facNro-input" className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Factura (facNro)
        </label>
        <Input
          id="facNro-input"
          name="facNro"
          defaultValue={initialFacNro}
        />
      </div>

      <div className="flex items-end">
        <Button type="submit" className="w-full">Filtrar</Button>
      </div>
    </form>
  );
}
