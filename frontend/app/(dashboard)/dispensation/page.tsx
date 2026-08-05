"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ScanSearch } from "lucide-react";
import { z } from "zod";

import { PageHeader } from "@/components/layout/page-header";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

const schema = z.object({
  disId: z.string().optional(),
  disDetNro: z.string().min(1, "Ingresa un DisDetNro válido."),
});

type FormValues = z.infer<typeof schema>;

export default function DispensationSearchPage() {
  const navigation = usePendingNavigation();
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { disId: "", disDetNro: "" },
  });

  return (
    <div className="space-y-5">
      <PageHeader
        eyebrow="Consulta"
        title="Dispensación"
        description="Busca una dispensación por su identificador para ver el detalle técnico completo."
      />
      <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4 md:px-5">
        <form
          aria-busy={navigation.isPending}
          className="grid gap-4 md:grid-cols-[1fr_auto]"
          onSubmit={form.handleSubmit((values) => {
            const disIdParam = values.disId?.trim() || "";
            navigation.push(
              `/dispensation/${encodeURIComponent(disIdParam || "_")}/${encodeURIComponent(values.disDetNro)}`,
            );
          })}
        >
          <div className="grid gap-4 md:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="disId">DisId</FieldLabel>
              <Input
                id="disId"
                placeholder="Ej. DIS26-6..."
                aria-invalid={!!form.formState.errors.disId}
                aria-describedby={form.formState.errors.disId ? "disId-error" : undefined}
                {...form.register("disId")}
              />
              {form.formState.errors.disId ? (
                <FieldDescription id="disId-error" className="text-rose-300" role="alert">
                  {form.formState.errors.disId.message}
                </FieldDescription>
              ) : null}
            </Field>
            <Field>
              <FieldLabel htmlFor="disDetNro">DisDetNro</FieldLabel>
              <Input
                id="disDetNro"
                placeholder="Ej. X24260300080"
                aria-invalid={!!form.formState.errors.disDetNro}
                aria-describedby={form.formState.errors.disDetNro ? "disDetNro-error" : undefined}
                {...form.register("disDetNro")}
              />
              {form.formState.errors.disDetNro ? (
                <FieldDescription id="disDetNro-error" className="text-rose-300" role="alert">
                  {form.formState.errors.disDetNro.message}
                </FieldDescription>
              ) : null}
            </Field>
          </div>
          <Button
            type="submit"
            className="h-11 self-end"
            loading={navigation.isPending}
            loadingLabel="Consultando"
          >
            <ScanSearch className="h-4 w-4" />
            Ver detalle
          </Button>
        </form>
      </div>
      {navigation.isPending ? (
        <BackendRequestSkeleton
          description="El backend está cargando el detalle técnico y los adjuntos."
          title="Consultando dispensación"
          variant="detail"
        />
      ) : null}
    </div>
  );
}
