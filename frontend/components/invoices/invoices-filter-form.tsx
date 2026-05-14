"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { Field, FieldLabel } from "@/components/ui/field";
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
  const [facNitSec, setFacNitSec] = React.useState(initialFacNitSec);

  React.useEffect(() => {
    setFacNitSec(initialFacNitSec);
  }, [initialFacNitSec]);

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    const dateFrom = formData.get("dateFrom");
    const dateTo = formData.get("dateTo");
    const limit = formData.get("limit");

    if (facNitSec.trim()) params.set("facNitSec", facNitSec.trim());
    if (dateFrom) params.set("dateFrom", String(dateFrom));
    if (dateTo) params.set("dateTo", String(dateTo));
    if (limit && limit !== "20") params.set("limit", String(limit));

    router.push(`?${params.toString()}`);
  };

  return (
    <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" onSubmit={handleSubmit}>
      <Field>
        <FieldLabel htmlFor="invoices-client-selector">
          Cliente
        </FieldLabel>
        <ClientSelectorCombo
          id="invoices-client-selector"
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
        <FieldLabel htmlFor="dateFrom-input">
          Desde
        </FieldLabel>
        <DatePickerInput
          id="dateFrom-input"
          name="dateFrom"
          defaultValue={initialDateFrom}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="dateTo-input">
          Hasta
        </FieldLabel>
        <DatePickerInput
          id="dateTo-input"
          name="dateTo"
          defaultValue={initialDateTo}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="limit-input">
          Límite
        </FieldLabel>
        <Input
          id="limit-input"
          name="limit"
          defaultValue={String(initialLimit)}
        />
      </Field>

      <div className="flex items-end">
        <Button type="submit" className="w-full">Buscar</Button>
      </div>
    </form>
  );
}
