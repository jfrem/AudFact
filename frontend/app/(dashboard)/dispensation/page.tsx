"use client";

import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ScanSearch } from "lucide-react";
import { z } from "zod";

import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

const schema = z.object({
  disDetNro: z.string().min(1, "Ingresa un DisDetNro válido."),
});

type FormValues = z.infer<typeof schema>;

export default function DispensationSearchPage() {
  const router = useRouter();
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { disDetNro: "" },
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
          className="grid gap-4 md:grid-cols-[1fr_auto]"
          onSubmit={form.handleSubmit((values) => {
            router.push(`/dispensation/${encodeURIComponent(values.disDetNro)}`);
          })}
        >
          <Field>
            <FieldLabel htmlFor="disDetNro">
              DisDetNro
            </FieldLabel>
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
          <Button type="submit" className="h-11 self-end">
            <ScanSearch className="h-4 w-4" />
            Ver detalle
          </Button>
        </form>
      </div>
    </div>
  );
}
