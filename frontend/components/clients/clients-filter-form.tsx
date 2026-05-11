"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { RotateCcw, Search } from "lucide-react";

import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import { Button } from "@/components/ui/button";
import type { ClientRecord } from "@/lib/schemas/domain";

interface ClientsFilterFormProps {
  clients: ClientRecord[];
  initialClientId?: string;
}

export function ClientsFilterForm({
  clients,
  initialClientId = "",
}: ClientsFilterFormProps) {
  const router = useRouter();
  const [clientId, setClientId] = React.useState(initialClientId);
  const hasActiveFilter = clientId.trim().length > 0;

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const params = new URLSearchParams();

    const nextClientId = String(formData.get("clientId") ?? "").trim();

    if (nextClientId) params.set("clientId", nextClientId);

    router.push(params.size ? `/clients?${params.toString()}` : "/clients");
  };

  return (
    <form
      className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]"
      onSubmit={handleSubmit}
    >
      <label className="space-y-2">
        <span className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400">
          Cliente / NitSec
        </span>
        <ClientSelectorCombo
          clients={clients}
          value={clientId}
          onValueChange={setClientId}
          placeholder="Busca por nombre o NitSec"
        />
        <input type="hidden" name="clientId" value={clientId} readOnly />
      </label>

      <div className="flex items-end gap-2 md:col-start-2">
        <Button type="submit" className="w-full md:w-auto">
          <Search className="h-4 w-4" />
          Consultar
        </Button>
      </div>

      {hasActiveFilter ? (
        <div className="flex items-end gap-2 md:col-start-3">
          <Button
            type="button"
            variant="ghost"
            className="w-full md:w-auto"
            onClick={() => {
              setClientId("");
              router.push("/clients");
            }}
          >
            <RotateCcw className="h-4 w-4" />
            Limpiar
          </Button>
        </div>
      ) : null}
    </form>
  );
}
