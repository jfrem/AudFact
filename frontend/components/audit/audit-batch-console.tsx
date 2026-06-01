"use client";

import * as React from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQuery, useMutation } from "@tanstack/react-query";
import { ExternalLink, TimerReset } from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";

import { describeError, isRetryableError } from "@/lib/api/errors";

import { getClients, enqueueAuditBatch } from "@/lib/api/audfact";
import type { ClientRecord } from "@/lib/schemas/domain";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { SectionCard } from "@/components/shared/section-card";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";

const batchSchema = z.object({
  clientNitSec: z.string().min(1, "El cliente es obligatorio."),
  date: z.string().min(1, "La fecha inicial es obligatoria."),
  dateTo: z.string().optional(),
  limit: z.coerce.number().min(1).max(100),
});

type BatchValues = z.infer<typeof batchSchema>;

export function AuditBatchConsole({
  defaultLimit,
  timeoutMs,
}: {
  defaultLimit: number;
  timeoutMs: number;
}) {
  const [lastAsyncJob, setLastAsyncJob] = React.useState<{
    jobId: string;
    statusUrl: string;
  } | null>(null);
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [pendingValues, setPendingValues] = React.useState<{ facNitSec: number; date: string; dateTo?: string; limit: number } | null>(null);

  const { data: clients = [] } = useQuery({
    queryKey: ["batch-console-clients"],
    queryFn: () => getClients(),
  });

  const form = useForm<BatchValues>({
    resolver: zodResolver(batchSchema),
    defaultValues: {
      clientNitSec: "",
      date: "",
      dateTo: "",
      limit: defaultLimit,
    },
  });

  const asyncMutation = useMutation({
    mutationFn: (values: { facNitSec: number; date: string; dateTo?: string; limit: number }) => {
      const tid = toast.loading("Encolando batch de auditoría...");
      return enqueueAuditBatch(values).finally(() => toast.dismiss(tid));
    },
    onSuccess: (response) => {
      setLastAsyncJob({
        jobId: response.data.jobId,
        statusUrl: response.data.statusUrl,
      });
      toast.success("Job encolado", {
        description: `ID: ${response.data.jobId.slice(0, 12)}...`,
      });
    },
    onError: (error) => {
      toast.error("Error al encolar", {
        description: describeError(error),
        duration: isRetryableError(error) ? 12_000 : 6_000,
        ...(isRetryableError(error)
          ? {
              action: {
                label: "Reintentar",
                onClick: () => {
                  if (pendingValues) asyncMutation.mutate(pendingValues);
                },
              },
            }
          : {}),
      });
    },
  });

  const isBusy = asyncMutation.isPending;

  const requestConfirm = () => {
    form.handleSubmit((formValues) => {
      setPendingValues({
        facNitSec: Number(formValues.clientNitSec),
        date: formValues.date,
        dateTo: formValues.dateTo || undefined,
        limit: formValues.limit,
      });
      setConfirmOpen(true);
    })();
  };

  const handleConfirm = () => {
    if (!pendingValues) return;
    setConfirmOpen(false);
    asyncMutation.mutate(pendingValues);
  };

  return (
    <>
      <ConfirmDialog
        open={confirmOpen}
        variant="info"
        title="Encolar batch de auditoría"
        description={`Se encolará un batch de hasta ${pendingValues?.limit ?? 0} facturas para procesamiento asíncrono. El sistema devolverá un jobId para seguimiento.`}
        confirmLabel="Encolar"
        onConfirm={handleConfirm}
        onCancel={() => setConfirmOpen(false)}
        loading={isBusy}
      />

      <div className="grid gap-5 lg:grid-cols-[1fr_1fr]">
        <SectionCard
          title="Auditoría en lote"
          description="Define la ventana operativa y encola el job batch oficial del sistema."
        >
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); requestConfirm(); }}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="batch-client-selector">
                  Cliente
                </FieldLabel>
                <ClientSelectorCombo
                  id="batch-client-selector"
                  clients={clients}
                  value={form.watch("clientNitSec")}
                  onValueChange={(value) => form.setValue("clientNitSec", value)}
                  placeholder="Selecciona un cliente"
                />
                {form.formState.errors.clientNitSec && (
                  <FieldDescription className="text-rose-300" role="alert">
                    {form.formState.errors.clientNitSec.message}
                  </FieldDescription>
                )}
              </Field>
              <Field>
                <FieldLabel htmlFor="batch-limit">Límite</FieldLabel>
                <Input id="batch-limit" {...form.register("limit")} />
                {form.formState.errors.limit?.message ? (
                  <FieldDescription className="text-rose-300" role="alert">
                    {form.formState.errors.limit.message}
                  </FieldDescription>
                ) : null}
              </Field>
              <Field>
                <FieldLabel htmlFor="batch-date-from">Fecha desde</FieldLabel>
                <DatePickerInput
                  id="batch-date-from"
                  value={form.watch("date")}
                  onValueChange={(value) =>
                    form.setValue("date", value, { shouldDirty: true, shouldValidate: true })
                  }
                />
                {form.formState.errors.date?.message ? (
                  <FieldDescription className="text-rose-300" role="alert">
                    {form.formState.errors.date.message}
                  </FieldDescription>
                ) : null}
              </Field>
              <Field>
                <FieldLabel htmlFor="batch-date-to">Fecha hasta</FieldLabel>
                <DatePickerInput
                  id="batch-date-to"
                  value={form.watch("dateTo")}
                  onValueChange={(value) =>
                    form.setValue("dateTo", value, { shouldDirty: true, shouldValidate: true })
                  }
                />
                {form.formState.errors.dateTo?.message ? (
                  <FieldDescription className="text-rose-300" role="alert">
                    {form.formState.errors.dateTo.message}
                  </FieldDescription>
                ) : null}
              </Field>
            </div>

            <div className="flex flex-wrap gap-3">
              <Button
                type="submit"
                disabled={isBusy}
                aria-busy={isBusy}
                loading={isBusy}
                loadingLabel="Encolando"
              >
                <TimerReset className="h-4 w-4" />
                Encolar batch
              </Button>
            </div>
          </form>
        </SectionCard>

        <div className="space-y-5">
          <SectionCard title="Límites operativos">
            <div className="grid gap-2 sm:grid-cols-2">
              <div className="surface-subtle rounded-lg p-4">
                <p className="text-[11px] uppercase tracking-[0.12em] text-slate-500">Máximo por batch</p>
                <p className="mt-2 text-2xl font-semibold text-white">{defaultLimit}</p>
              </div>
              <div className="surface-subtle rounded-lg p-4">
                <p className="text-[11px] uppercase tracking-[0.12em] text-slate-500">Timeout</p>
                <p className="mt-2 text-2xl font-semibold text-white">
                  {(timeoutMs / 1000 / 60).toFixed(0)} min
                </p>
              </div>
            </div>
          </SectionCard>

          <SectionCard title="Última ejecución">
            {isBusy ? (
              <BackendRequestSkeleton
                description="El backend está creando el job asíncrono."
                title="Encolando batch"
                variant="compact"
              />
            ) : (
              <div className="space-y-2">
                <div className="surface-subtle rounded-lg p-4">
                  <p className="text-[11px] uppercase tracking-[0.12em] text-slate-500">Job batch</p>
                  {lastAsyncJob ? (
                    <Link
                      href={lastAsyncJob.statusUrl}
                      className="mt-2 inline-flex items-center gap-2 text-sm text-sky-300 transition hover:text-sky-200"
                    >
                      Abrir seguimiento
                      <ExternalLink className="h-3.5 w-3.5" />
                    </Link>
                  ) : (
                    <p className="mt-2 text-sm text-white">Sin job encolado en esta sesión.</p>
                  )}
                </div>
              </div>
            )}
          </SectionCard>
        </div>
      </div>
    </>
  );
}
