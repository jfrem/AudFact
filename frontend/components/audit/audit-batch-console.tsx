"use client";

import * as React from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AlertTriangle, ExternalLink, TimerReset } from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";
import { v4 as uuidv4 } from "uuid";

import { enqueueAuditBatch, getClients } from "@/lib/api/audfact";
import { describeError, isRetryableError } from "@/lib/api/errors";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { SectionCard } from "@/components/shared/section-card";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { ClientSelectorCombo } from "@/components/audit/client-selector-combo";
import { DatePickerInput } from "@/components/ui/date-picker-input";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";

const batchSchema = z.object({
  clientNitSec: z.string().min(1, "El cliente es obligatorio."),
  date: z.string().min(1, "La fecha inicial es obligatoria."),
  dateTo: z.string().optional(),
  limit: z.coerce.number().min(1).max(100),
});

type BatchValues = z.infer<typeof batchSchema>;

type BatchPayload = {
  facNitSec: number;
  date: string;
  dateTo?: string;
  limit: number;
};

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
  const [pendingValues, setPendingValues] = React.useState<BatchPayload | null>(null);
  const idempotencyKeyRef = React.useRef("");

  const {
    data: clients = [],
    isError: clientsError,
  } = useQuery({
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
    mutationFn: (values: BatchPayload) => {
      const toastId = toast.loading("Encolando auditoría batch");
      return enqueueAuditBatch(values, idempotencyKeyRef.current).finally(() => toast.dismiss(toastId));
    },
    onSuccess: (response) => {
      setLastAsyncJob({
        jobId: response.data.jobId,
        statusUrl: response.data.statusUrl,
      });
      toast.success("Job encolado", {
        description: `ID: ${response.data.jobId.slice(0, 12)}…`,
      });
    },
    onError: (error) => {
      toast.error("No fue posible encolar", {
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

  const requestConfirmation = () => {
    void form.handleSubmit((values) => {
      idempotencyKeyRef.current = uuidv4();
      setPendingValues({
        facNitSec: Number(values.clientNitSec),
        date: values.date,
        dateTo: values.dateTo || undefined,
        limit: values.limit,
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
        title="Encolar auditoría batch"
        description={`Se procesarán hasta ${pendingValues?.limit ?? 0} facturas. Recibirás un job ID para seguir el lote.`}
        confirmLabel="Encolar"
        onConfirm={handleConfirm}
        onCancel={() => setConfirmOpen(false)}
        loading={asyncMutation.isPending}
      />

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]">
        <SectionCard
          title="Parámetros del lote"
          description="Define cliente, ventana operativa y volumen máximo."
        >
          {clientsError ? (
            <Alert variant="warning" className="mb-4">
              <AlertTriangle />
              <AlertDescription>
                No fue posible cargar el catálogo de clientes. Actualiza la página para reintentar.
              </AlertDescription>
            </Alert>
          ) : null}

          <form
            className="space-y-5"
            onSubmit={(event) => {
              event.preventDefault();
              requestConfirmation();
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="batch-client-selector">Cliente</FieldLabel>
                <ClientSelectorCombo
                  id="batch-client-selector"
                  clients={clients}
                  value={form.watch("clientNitSec")}
                  onValueChange={(value) =>
                    form.setValue("clientNitSec", value, {
                      shouldDirty: true,
                      shouldValidate: true,
                    })
                  }
                  placeholder="Selecciona un cliente"
                  invalid={Boolean(form.formState.errors.clientNitSec)}
                  ariaDescribedBy={
                    form.formState.errors.clientNitSec ? "batch-client-error" : undefined
                  }
                />
                {form.formState.errors.clientNitSec ? (
                  <FieldDescription id="batch-client-error" className="text-rose-300" role="alert">
                    {form.formState.errors.clientNitSec.message}
                  </FieldDescription>
                ) : null}
              </Field>

              <Field>
                <FieldLabel htmlFor="batch-limit">Límite</FieldLabel>
                <Input
                  id="batch-limit"
                  type="number"
                  min={1}
                  max={100}
                  aria-invalid={Boolean(form.formState.errors.limit)}
                  {...form.register("limit")}
                />
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
                    form.setValue("date", value, {
                      shouldDirty: true,
                      shouldValidate: true,
                    })
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
                    form.setValue("dateTo", value, {
                      shouldDirty: true,
                      shouldValidate: true,
                    })
                  }
                />
              </Field>
            </div>

            <Button
              type="submit"
              loading={asyncMutation.isPending}
              loadingLabel="Encolando"
              disabled={asyncMutation.isPending}
            >
              <TimerReset />
              Encolar batch
            </Button>
          </form>
        </SectionCard>

        <SectionCard
          title="Contrato operativo"
          description="Límites efectivos y última ejecución de esta sesión."
        >
          <dl className="divide-y divide-border rounded-md border border-border">
            <MetricRow label="Máximo por batch" value={String(defaultLimit)} />
            <MetricRow label="Timeout" value={`${(timeoutMs / 60_000).toFixed(0)} min`} />
          </dl>

          <div className="mt-5 border-t border-border pt-5">
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
              Último job
            </p>
            {asyncMutation.isPending ? (
              <BackendRequestSkeleton
                className="mt-3"
                description="El backend está creando el job asíncrono."
                title="Encolando lote"
                variant="compact"
              />
            ) : lastAsyncJob ? (
              <div className="mt-3">
                <p className="truncate font-mono text-xs text-muted-foreground" title={lastAsyncJob.jobId}>
                  {lastAsyncJob.jobId}
                </p>
                <Button asChild variant="outline" size="sm" className="mt-3">
                  <Link href={lastAsyncJob.statusUrl}>
                    Abrir seguimiento
                    <ExternalLink />
                  </Link>
                </Button>
              </div>
            ) : (
              <p className="mt-2 text-sm leading-6 text-muted-foreground">
                Aún no se ha encolado un job en esta sesión.
              </p>
            )}
          </div>
        </SectionCard>
      </div>
    </>
  );
}

function MetricRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-4 px-3.5 py-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-mono text-sm font-semibold tabular-nums text-foreground">{value}</dd>
    </div>
  );
}
