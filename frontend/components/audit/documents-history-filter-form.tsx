"use client";

import * as React from "react";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";
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
  const navigation = usePendingNavigation();
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

    navigation.push(`?${params.toString()}`);
  };

  return (
    <div className="space-y-4">
      <form
        aria-busy={navigation.isPending}
        className="grid gap-3 md:grid-cols-[1fr_1fr_auto]"
        onSubmit={handleSubmit}
      >
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
          <Button
            type="submit"
            className="w-full"
            loading={navigation.isPending}
            loadingLabel="Filtrando"
          >
            Filtrar
          </Button>
        </div>
      </form>

      {navigation.isPending ? (
        <BackendRequestSkeleton
          description="El backend está cargando el historial documental."
          rows={6}
          title="Consultando documentos"
          variant="table"
        />
      ) : null}
    </div>
  );
}
