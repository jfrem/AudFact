"use client";

import * as React from "react";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Loader2, Play } from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";

import { describeError, isRetryableError } from "@/lib/api/errors";

import { runAuditSingle, getAuditLiveStatus } from "@/lib/api/audfact";
import { LiveAuditFlow } from "@/components/audit/live-audit-flow";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

const formSchema = z.object({
  disId: z.string().optional(),
  disDetNro: z.string().min(1, "Ingresa un número de factura válido."),
});

type FormValues = z.infer<typeof formSchema>;

const POLL_INTERVAL_MS = 3_000;

export function AuditSingleConsole() {
  const searchParams = useSearchParams();
  const prefill = searchParams.get("disId") ?? "";

  const [activeAuditId, setActiveAuditId] = React.useState<string | null>(null);

  const [pollingAuditId, setPollingAuditId] = React.useState<string | null>(
    null,
  );

  const liveStatus = useQuery({
    queryKey: ["audit-live-status", pollingAuditId],
    queryFn: () => getAuditLiveStatus(pollingAuditId!),
    enabled: pollingAuditId !== null,
    refetchInterval: (query) => {
      const data = query.state.data;
      if (data?.is_terminal) return false;
      return POLL_INTERVAL_MS;
    },
    retry: 2,
  });

  React.useEffect(() => {
    const data = liveStatus.data;
    if (!data?.is_terminal || !pollingAuditId) return;

    const isSuccess =
      data.status === "completed" || data.status === "manual_review";

    if (isSuccess) {
      toast.success("Auditoría completada", {
        description: `Pipeline finalizado exitosamente.`,
      });
    } else {
      toast.error("La auditoría falló en el pipeline", {
        description: data.error_message || `Estado terminal: ${data.status}`,
        duration: 12_000,
      });
    }

    setPollingAuditId(null);
  }, [liveStatus.data, pollingAuditId]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { disId: prefill, disDetNro: "" },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const toastId = toast.loading("Ejecutando auditoría IA...", {
        description: `DisId: ${values.disId} / Factura: ${values.disDetNro}`,
      });
      return runAuditSingle(values.disId, values.disDetNro).finally(() =>
        toast.dismiss(toastId),
      );
    },
    onSuccess: (response) => {
      const data = response.data;

      if (data.status === "pending" && data.audit_id) {
        setActiveAuditId(data.audit_id);
        setPollingAuditId(data.audit_id);
        toast.info(
          "Auditoría encolada, monitoreando progreso en tiempo real",
          {
            duration: 4_000,
          },
        );
      } else {
        setActiveAuditId(data.audit_id ?? null);
        const duration = Number(data?.metrics?.totalTimeMs ?? 0);
        toast.success("Auditoría completada", {
          description:
            duration > 0
              ? `Tiempo: ${(duration / 1000).toFixed(1)}s`
              : undefined,
        });
      }
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
    setActiveAuditId(null);
    setPollingAuditId(null);
    mutation.mutate(values);
  };

  const isPolling = pollingAuditId !== null;

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-white/10 bg-card p-4">
        <form
          className="grid gap-4 md:grid-cols-[1fr_1fr_auto]"
          onSubmit={form.handleSubmit(handleSubmit)}
          aria-label="Filtros de ejecución"
        >
          <Field>
            <FieldLabel htmlFor="disId">ID Dispensación (opcional)</FieldLabel>
            <Input
              id="disId"
              placeholder="Ej. 87723098"
              aria-invalid={!!form.formState.errors.disId}
              aria-describedby={
                form.formState.errors.disId
                  ? "audit-single-disId-error"
                  : undefined
              }
              {...form.register("disId")}
            />
            {form.formState.errors.disId && (
              <FieldDescription
                id="audit-single-disId-error"
                className="text-rose-300"
                role="alert"
              >
                {form.formState.errors.disId.message}
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor="disDetNro">Número de Factura</FieldLabel>
            <Input
              id="disDetNro"
              placeholder="Ej. T38250701547"
              aria-invalid={!!form.formState.errors.disDetNro}
              aria-describedby={
                form.formState.errors.disDetNro
                  ? "audit-single-disDetNro-error"
                  : undefined
              }
              {...form.register("disDetNro")}
            />
            {form.formState.errors.disDetNro && (
              <FieldDescription
                id="audit-single-disDetNro-error"
                className="text-rose-300"
                role="alert"
              >
                {form.formState.errors.disDetNro.message}
              </FieldDescription>
            )}
          </Field>

          <div className="flex items-end">
            <Button
              type="submit"
              disabled={mutation.isPending || isPolling}
              aria-busy={mutation.isPending || isPolling}
              className="w-full sm:w-auto min-w-32"
              loading={mutation.isPending}
              loadingLabel="Procesando..."
            >
              {isPolling ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  En curso...
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
      </div>

      <div className="flex flex-col gap-5 pt-4">
        <div>
          <h2 className="[font-family:var(--font-heading)] text-xl font-semibold tracking-tight text-white">
            Lienzo de Telemetría
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            Observación en vivo y trazabilidad técnica del pipeline de auditoría IA.
          </p>
        </div>
        <LiveAuditFlow auditId={activeAuditId ?? undefined} />
      </div>
    </div>
  );
}
