"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
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
  const [facNitSec, setFacNitSec] = React.useState(initialFacNitSec);

  React.useEffect(() => {
    setFacNitSec(initialFacNitSec);
  }, [initialFacNitSec]);

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    const facNro = formData.get("facNro");

    if (facNitSec.trim()) params.set("facNitSec", facNitSec.trim());
    if (facNro) params.set("facNro", String(facNro));

    router.push(`?${params.toString()}`);
  };

  return (
    <form className="grid gap-3 md:grid-cols-[1fr_1fr_auto]" onSubmit={handleSubmit}>
      <Field>
        <FieldLabel htmlFor="client-selector">
          Cliente
        </FieldLabel>
        <ClientSelectorCombo
          id="client-selector"
          clients={allClients}
          value={facNitSec}
          onValueChange={setFacNitSec}
          placeholder="Selecciona un cliente"
        />
        <input
          type="hidden"
          name="facNitSec"
          value={facNitSec}
          readOnly
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="facNro-input">
          Factura (facNro)
        </FieldLabel>
        <Input
          id="facNro-input"
          name="facNro"
          defaultValue={initialFacNro}
        />
      </Field>

      <div className="flex items-end">
        <Button type="submit" className="w-full">Filtrar</Button>
      </div>
    </form>
  );
}
