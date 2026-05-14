"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { Field, FieldLabel } from "@/components/ui/field";
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
  const [facNitSec, setFacNitSec] = React.useState(initialFacNitSec);

  React.useEffect(() => {
    setFacNitSec(initialFacNitSec);
  }, [initialFacNitSec]);

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const params = new URLSearchParams();

    params.set("page", "1");
    params.set("pageSize", String(initialPageSize));

    const facNro = formData.get("facNro");
    const dateFrom = formData.get("dateFrom");
    const dateTo = formData.get("dateTo");

    if (facNitSec.trim()) params.set("facNitSec", facNitSec.trim());
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
      <Field>
        <FieldLabel htmlFor="audit-results-client-selector">
          Cliente
        </FieldLabel>
        <ClientSelectorCombo
          id="audit-results-client-selector"
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
        <FieldLabel htmlFor="audit-results-fac-nro">
          Factura
        </FieldLabel>
        <Input
          id="audit-results-fac-nro"
          name="facNro"
          defaultValue={initialFacNro}
          className="min-w-0"
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="audit-results-date-from">
          Desde
        </FieldLabel>
        <DatePickerInput
          id="audit-results-date-from"
          name="dateFrom"
          defaultValue={initialDateFrom}
          className="min-w-0"
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="audit-results-date-to">
          Hasta
        </FieldLabel>
        <DatePickerInput
          id="audit-results-date-to"
          name="dateTo"
          defaultValue={initialDateTo}
          className="min-w-0"
        />
      </Field>

      <div className="flex min-w-0 items-end">
        <Button type="submit" className="w-full">Buscar</Button>
      </div>
    </form>
  );
}
