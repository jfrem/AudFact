"use client";

import * as React from "react";
import { RotateCcw, Search } from "lucide-react";

import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";

import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { useRouter, useSearchParams } from "next/navigation";
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
  const [isSubmitting, setIsSubmitting] = React.useState(false);
  const hasActiveFilter = clientId.trim().length > 0;
  const searchParams = useSearchParams();

  const currentParams = searchParams.toString();

  React.useEffect(() => {
    setIsSubmitting(false);
  }, [currentParams]);

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const params = new URLSearchParams();

    if (clientId.trim()) {
      params.set("clientId", clientId.trim());
    }

    const newParamsString = params.toString();
    if (currentParams !== newParamsString) {
      setIsSubmitting(true);
      router.push(params.size ? `/clients?${newParamsString}` : "/clients");
    }
  };

  return (
    <div className="space-y-4">
      <form
        className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]"
        onSubmit={handleSubmit}
      >
        <Field>
          <FieldLabel htmlFor="clients-client-selector">
            Cliente / NitSec
          </FieldLabel>
          <ClientSelectorCombo
            id="clients-client-selector"
            clients={clients}
            value={clientId}
            onValueChange={setClientId}
            placeholder="Busca por nombre o NitSec"
          />
          <input type="hidden" name="clientId" value={clientId} readOnly />
        </Field>

        <div className="flex items-end gap-2 md:col-start-2">
          <Button
            type="submit"
            className="w-full md:w-auto"
            loading={isSubmitting}
            loadingLabel="Consultando"
          >
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
              loading={isSubmitting}
              loadingLabel="Limpiando"
              onClick={() => {
                setIsSubmitting(true);
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
    </div>
  );
}
