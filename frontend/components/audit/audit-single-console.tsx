"use client";

import * as React from "react";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  AlertCircle,
  CheckCircle2,
  ExternalLink,
  Loader2,
  Play,
} from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";

import { describeError, isRetryableError } from "@/lib/api/errors";

import { runAuditSingle, getAuditLiveStatus } from "@/lib/api/audfact";
import type { AuditSingleResponse, AuditLiveStatus } from "@/lib/schemas/domain";
import { AuditSingleWorkspace } from "@/components/audit/audit-single-workspace";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";

const formSchema = z.object({
  facSec: z.string().min(1, "Ingresa un FacSec válido."),
});

type FormValues = z.infer<typeof formSchema>;

const POLL_INTERVAL_MS = 3_000;

export function AuditSingleConsole() {
  const searchParams = useSearchParams();
  const prefill = searchParams.get("facSec") ?? "";

  const [latestFacSec, setLatestFacSec] = React.useState<string>("");
  const [latestDisDetNro, setLatestDisDetNro] = React.useState<string>("");
  const [latestResult, setLatestResult] = React.useState<AuditSingleResponse | null>(null);
  const [showConfirm, setShowConfirm] = React.useState(false);
  const [pendingValues, setPendingValues] = React.useState<FormValues | null>(null);

  /* ── Polling state ─────────────────────────────────── */
  const [pollingAuditId, setPollingAuditId] = React.useState<string | null>(null);

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

  /* Cuando el polling detecta estado terminal ────────── */
  React.useEffect(() => {
    const data = liveStatus.data;
    if (!data?.is_terminal || !pollingAuditId) return;

    const isSuccess = data.status === "completed" || data.status === "manual_review";

    if (isSuccess) {
      toast.success("Auditoría completada", {
        description: `Pipeline finalizado — navegue a Resultados para ver el detalle.`,
      });
    } else {
      toast.error("La auditoría falló en el pipeline", {
        description: data.error_message || `Estado terminal: ${data.status}`,
        duration: 12_000,
      });
    }

    // Stop polling — don't clear the status data so the card stays visible
    setPollingAuditId(null);
  }, [liveStatus.data, pollingAuditId]);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { facSec: prefill },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const toastId = toast.loading("Ejecutando auditoría IA...", {
        description: `FacSec: ${values.facSec}`,
      });
      return runAuditSingle(values.facSec).finally(() => toast.dismiss(toastId));
    },
    onSuccess: (response, values) => {
      const data = response.data;
      setLatestFacSec(data.fac_sec ?? values.facSec);
      setLatestDisDetNro(data.dis_det_nro ?? "");

      if (data.status === "pending" && data.audit_id) {
        // Pipeline asíncrono: iniciar polling
        setLatestResult(data);
        setPollingAuditId(data.audit_id);
        toast.info("Auditoría encolada — monitoreando progreso en tiempo real", {
          duration: 4_000,
        });
      } else {
        // Resultado síncrono (legacy / future)
        setLatestResult(data);
        const duration = Number(data?.metrics?.totalTimeMs ?? 0);
        toast.success("Auditoría completada", {
          description: duration > 0 ? `Tiempo: ${(duration / 1000).toFixed(1)}s` : undefined,
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
    setPendingValues(values);
    setShowConfirm(true);
  };

  const handleConfirm = () => {
    setShowConfirm(false);
    if (pendingValues) {
      // Reset previous state
      setLatestResult(null);
      setPollingAuditId(null);
      mutation.mutate(pendingValues);
    }
  };

  /* ── Determinar qué vista renderizar ───────────────── */
  const isPolling = pollingAuditId !== null;
  const terminalStatus = liveStatus.data?.is_terminal ? liveStatus.data : null;

  return (
    <>
      <ConfirmDialog
        open={showConfirm}
        variant="info"
        title="Ejecutar auditoría"
        description={`Se enviará la factura ${pendingValues?.facSec ?? ""} al pipeline de auditoría IA. Este proceso puede tomar entre 10 y 60 segundos.`}
        confirmLabel="Ejecutar"
        onConfirm={handleConfirm}
        onCancel={() => setShowConfirm(false)}
        loading={mutation.isPending}
      />

      <div className="space-y-5">
        <SectionCard
          title="Auditoría individual"
          description="Ejecuta una corrida puntual por `FacSec` y revisa el resultado, métricas y evidencia sin salir del flujo."
        >
          <form
            className="grid gap-4 md:grid-cols-[1fr_auto]"
            onSubmit={form.handleSubmit(handleSubmit)}
          >
            <Field>
              <FieldLabel htmlFor="facSec">
                FacSec
              </FieldLabel>
              <Input
                id="facSec"
                placeholder="Ej. 87723098"
                aria-invalid={!!form.formState.errors.facSec}
                aria-describedby={form.formState.errors.facSec ? "audit-single-facSec-error" : undefined}
                {...form.register("facSec")}
              />
              {form.formState.errors.facSec && (
                <FieldDescription id="audit-single-facSec-error" className="text-rose-300" role="alert">
                  {form.formState.errors.facSec.message}
                </FieldDescription>
              )}
            </Field>

            <div className="flex items-end">
              <Button
                type="submit"
                disabled={mutation.isPending || isPolling}
                aria-busy={mutation.isPending || isPolling}
                className="w-full sm:w-auto min-w-32"
              >
                {mutation.isPending ? (
                  <>
                    <Spinner />
                    Procesando...
                  </>
                ) : isPolling ? (
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
        </SectionCard>

        {/* ── Estado: Polling en curso ── */}
        {(isPolling || terminalStatus) && latestResult?.status === "pending" ? (
          <AuditProgressCard
            liveStatus={liveStatus.data ?? null}
            isPolling={isPolling}
            facSec={latestFacSec}
            disDetNro={latestDisDetNro}
          />
        ) : latestResult && latestResult.status !== "pending" ? (
          /* ── Estado: Resultado completo (sync/legacy) ── */
          <AuditSingleWorkspace
            disDetNro={latestDisDetNro}
            result={latestResult}
          />
        ) : (
          /* ── Estado: Sin auditoría ejecutada ── */
          <SectionCard>
            <EmptyState
              title="Sin auditoría ejecutada"
              description="Ingresa un FacSec y presiona Ejecutar para iniciar el análisis IA."
            />
          </SectionCard>
        )}
      </div>
    </>
  );
}

/* ── Componente de progreso en tiempo real ──────────── */

function AuditProgressCard({
  liveStatus,
  isPolling,
  facSec,
  disDetNro,
}: {
  liveStatus: AuditLiveStatus | null;
  isPolling: boolean;
  facSec: string;
  disDetNro: string;
}) {
  const status = liveStatus?.status ?? "pending";
  const isTerminal = liveStatus?.is_terminal ?? false;
  const isSuccess = status === "completed" || status === "manual_review";
  const isFailed = status === "error" || status === "failed";

  const docsTotal = liveStatus?.docs_total ?? 0;
  const docsExtracted = liveStatus?.docs_extracted ?? 0;
  const docsEvaluated = liveStatus?.docs_evaluated ?? 0;
  const progressPct = docsTotal > 0
    ? Math.round((docsEvaluated / docsTotal) * 100)
    : 0;

  return (
    <SectionCard>
      <div className="space-y-5">
        {/* Header */}
        <div className="flex items-start gap-4">
          <div
            className={cn(
              "flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset",
              isSuccess && "bg-emerald-500/10 text-emerald-400 ring-emerald-500/20",
              isFailed && "bg-rose-500/10 text-rose-400 ring-rose-500/20",
              !isTerminal && "bg-sky-500/10 text-sky-400 ring-sky-500/20",
            )}
          >
            {isSuccess ? (
              <CheckCircle2 className="h-5 w-5" />
            ) : isFailed ? (
              <AlertCircle className="h-5 w-5" />
            ) : (
              <Loader2 className="h-5 w-5 animate-spin" />
            )}
          </div>
          <div className="min-w-0">
            <h3 className="text-base font-semibold text-white">
              {isSuccess
                ? "Auditoría completada"
                : isFailed
                  ? "Auditoría fallida"
                  : status === "processing"
                    ? "Pipeline en ejecución…"
                    : "Auditoría encolada…"}
            </h3>
            <p className="mt-0.5 text-sm text-slate-400">
              FacSec: <span className="font-mono text-slate-300">{facSec}</span>
              {liveStatus?.audit_id ? (
                <span className="ml-2 text-slate-500">
                  · ID: {liveStatus.audit_id.slice(0, 12)}…
                </span>
              ) : null}
            </p>
          </div>
        </div>

        {/* Progress bar */}
        {docsTotal > 0 && (
          <div className="space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-400">
              <span>
                Documentos procesados: {docsEvaluated}/{docsTotal}
                {docsExtracted > docsEvaluated && (
                  <span className="ml-1 text-slate-500">
                    ({docsExtracted} extraídos)
                  </span>
                )}
              </span>
              <span className="font-semibold tabular-nums text-white">
                {progressPct}%
              </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-white/5">
              <div
                className={cn(
                  "h-full rounded-full transition-all duration-500 ease-out",
                  isSuccess && "bg-emerald-500",
                  isFailed && "bg-rose-500",
                  !isTerminal && "bg-sky-500",
                )}
                style={{ width: `${Math.max(progressPct, isPolling ? 2 : 0)}%` }}
              />
            </div>
          </div>
        )}

        {/* Pulsing indicator while polling */}
        {isPolling && docsTotal === 0 && (
          <div className="flex items-center gap-3 rounded-lg border border-white/5 bg-white/[0.02] px-4 py-3">
            <span className="relative flex h-2.5 w-2.5">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400 opacity-75" />
              <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-sky-500" />
            </span>
            <p className="text-sm text-slate-400">
              Esperando a que el pipeline registre documentos…
            </p>
          </div>
        )}

        {/* Error message */}
        {isFailed && liveStatus?.error_message && (
          <div className="rounded-lg border border-rose-500/20 bg-rose-500/5 px-4 py-3">
            <p className="text-sm text-rose-300">{liveStatus.error_message}</p>
          </div>
        )}

        {/* CTA for completed */}
        {isTerminal && isSuccess && (
          <div className="flex items-center gap-3">
            <Button asChild variant="outline" className="gap-2">
              <a href={`/audit/results?facNro=${encodeURIComponent(disDetNro)}`}>
                Ver Resultados
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </Button>
            <p className="text-xs text-slate-500">
              El resultado completo ha sido persistido y está disponible en el historial.
            </p>
          </div>
        )}
      </div>
    </SectionCard>
  );
}
