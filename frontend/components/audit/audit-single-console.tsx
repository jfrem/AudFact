"use client";

import * as React from "react";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { LoaderCircle, Play } from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";

import { describeError, isRetryableError } from "@/lib/api/errors";

import { runAuditSingle } from "@/lib/api/audfact";
import type { AuditSingleResponse } from "@/lib/schemas/domain";
import { AuditSingleWorkspace } from "@/components/audit/audit-single-workspace";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const formSchema = z.object({
  disDetNro: z.string().min(1, "Ingresa un DisDetNro válido."),
});

type FormValues = z.infer<typeof formSchema>;

export function AuditSingleConsole() {
  const searchParams = useSearchParams();
  const prefill = searchParams.get("disDetNro") ?? "";

  const [latestDisDetNro, setLatestDisDetNro] = React.useState<string>("");
  const [latestResult, setLatestResult] = React.useState<AuditSingleResponse | null>(null);
  const [showConfirm, setShowConfirm] = React.useState(false);
  const [pendingValues, setPendingValues] = React.useState<FormValues | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { disDetNro: prefill },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const toastId = toast.loading("Ejecutando auditoría IA...", {
        description: `DisDetNro: ${values.disDetNro}`,
      });
      return runAuditSingle(values.disDetNro).finally(() => toast.dismiss(toastId));
    },
    onSuccess: (response, values) => {
      setLatestDisDetNro(values.disDetNro);
      setLatestResult(response.data);
      const duration = Number(response.data?.metrics?.totalTimeMs ?? 0);
      toast.success("Auditoría completada", {
        description: duration > 0 ? `Tiempo: ${(duration / 1000).toFixed(1)}s` : undefined,
      });
    },
    onError: (error, values) => {
      toast.error("Error en la auditoría", {
        description: describeError(error),
        duration: isRetryableError(error) ? 12_000 : 6_000,
        ...(isRetryableError(error)
          ? {
              action: {
                label: "Reintentar",
                onClick: () => mutation.mutate(values),
              },
            }
          : {}),
      });
    },
  });

  const handleSubmit = (values: FormValues) => {
    setPendingValues(values);
    setShowConfirm(true);
  };

  const handleConfirm = () => {
    setShowConfirm(false);
    if (pendingValues) {
      mutation.mutate(pendingValues);
    }
  };

  return (
    <>
      <ConfirmDialog
        open={showConfirm}
        variant="info"
        title="Ejecutar auditoría"
        description={`Se enviará la dispensación ${pendingValues?.disDetNro ?? ""} al pipeline de auditoría IA. Este proceso puede tomar entre 10 y 60 segundos.`}
        confirmLabel="Ejecutar"
        onConfirm={handleConfirm}
        onCancel={() => setShowConfirm(false)}
        loading={mutation.isPending}
      />

      <div className="space-y-5">
        <SectionCard
          title="Auditoría individual"
          description="Ejecuta una corrida puntual por `DisDetNro` y revisa el resultado, métricas y evidencia sin salir del flujo."
        >
          <form
            className="grid gap-4 md:grid-cols-[1fr_auto]"
            onSubmit={form.handleSubmit(handleSubmit)}
          >
            <div className="space-y-1.5">
              <label
                htmlFor="disDetNro"
                className="text-xs font-medium uppercase tracking-[0.12em] text-slate-400"
              >
                DisDetNro
              </label>
              <Input
                id="disDetNro"
                placeholder="Ej. X24260300080"
                {...form.register("disDetNro")}
              />
              {form.formState.errors.disDetNro && (
                <p className="text-xs text-rose-300" role="alert">
                  {form.formState.errors.disDetNro.message}
                </p>
              )}
            </div>

            <div className="flex items-end">
              <Button
                type="submit"
                disabled={mutation.isPending}
                aria-busy={mutation.isPending}
                className="w-full sm:w-auto min-w-32"
              >
                {mutation.isPending ? (
                  <>
                    <LoaderCircle className="h-4 w-4 animate-spin" />
                    Procesando...
                  </>
                ) : (
                  <>
                    <Play className="h-4 w-4" />
                    Ejecutar
                  </>
                )}
              </Button>
            </div>
          </form>
        </SectionCard>

        {latestResult ? (
          latestResult.status === "pending" ? (
            <SectionCard>
              <EmptyState
                title="Auditoría encolada"
                description={`La dispensación ${latestDisDetNro} ha sido enviada al pipeline asíncrono (ID: ${latestResult.audit_id}).`}
                action={
                  <Button asChild variant="outline">
                    <a href={`/audit/results?facNro=${encodeURIComponent(latestDisDetNro)}`}>
                      Ver Resultados
                    </a>
                  </Button>
                }
              />
            </SectionCard>
          ) : (
            <AuditSingleWorkspace
              disDetNro={latestDisDetNro}
              result={latestResult}
            />
          )
        ) : (
          <SectionCard>
            <EmptyState
              title="Sin auditoría ejecutada"
              description="Ingresa un DisDetNro y presiona Ejecutar para iniciar el análisis IA."
            />
          </SectionCard>
        )}
      </div>
    </>
  );
}
