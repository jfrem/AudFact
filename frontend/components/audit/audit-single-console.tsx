"use client";

import * as React from "react";
import { useSearchParams } from "next/navigation";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  FileText,
  Files,
  Loader2,
  Play,
  Sparkles,
  Zap,
} from "lucide-react";
import { z } from "zod";
import { toast } from "sonner";

import { describeError, isRetryableError } from "@/lib/api/errors";
import {
  runAuditSingle,
  getAuditLiveStatus,
} from "@/lib/api/audfact";
import { attachmentsQuery } from "@/lib/query/audit";
import type { Attachment } from "@/lib/schemas/domain";
import { LiveAuditFlow } from "@/components/audit/live-audit-flow";
import { SectionCard } from "@/components/shared/section-card";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const formSchema = z.object({
  disId: z.string().optional(),
  disDetNro: z.string().min(1, "Ingresa un número de factura válido."),
  attachmentId: z.string().optional(),
});

type FormValues = z.infer<typeof formSchema>;

const POLL_INTERVAL_MS = 3_000;

export function AuditSingleConsole() {
  const searchParams = useSearchParams();
  const prefillDisId = searchParams.get("disId") ?? "";
  const prefillDisDetNro = searchParams.get("disDetNro") ?? "";

  const [activeAuditId, setActiveAuditId] = React.useState<string | null>(null);
  const [pollingAuditId, setPollingAuditId] = React.useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      disId: prefillDisId,
      disDetNro: prefillDisDetNro,
      attachmentId: "ALL",
    },
  });

  const watchedDisDetNro = form.watch("disDetNro");
  const selectedAttachmentId = form.watch("attachmentId") || "ALL";

  // Debounce para evitar ráfagas de consultas al tipear la factura
  const [debouncedDisDetNro, setDebouncedDisDetNro] = React.useState(
    prefillDisDetNro.trim(),
  );

  React.useEffect(() => {
    const timer = setTimeout(() => {
      const clean = (watchedDisDetNro ?? "").trim();
      setDebouncedDisDetNro(clean);
    }, 350);
    return () => clearTimeout(timer);
  }, [watchedDisDetNro]);

  // Consulta centralizada de soportes vinculados a la dispensa
  const attachmentsQueryResult = useQuery({
    ...attachmentsQuery(debouncedDisDetNro),
    enabled: debouncedDisDetNro.length >= 3,
    staleTime: 60_000,
    retry: 1,
  });

  const rawAttachments = attachmentsQueryResult.data;
  const attachments = React.useMemo<Attachment[]>(
    () => rawAttachments ?? [],
    [rawAttachments],
  );
  const hasAttachments = attachments.length > 0;

  // Si cambia la factura y el soporte seleccionado ya no existe en la lista, reset a "ALL"
  React.useEffect(() => {
    if (selectedAttachmentId !== "ALL" && hasAttachments) {
      const exists = attachments.some(
        (att: Attachment) =>
          String(att.id_documento ?? att.id_adjunto_fisico) ===
          selectedAttachmentId,
      );
      if (!exists) {
        form.setValue("attachmentId", "ALL");
      }
    }
  }, [attachments, hasAttachments, selectedAttachmentId, form]);

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
        description: "Pipeline finalizado exitosamente.",
      });
    } else {
      toast.error("La auditoría falló en el pipeline", {
        description: data.error_message || `Estado terminal: ${data.status}`,
        duration: 12_000,
      });
    }

    setPollingAuditId(null);
  }, [liveStatus.data, pollingAuditId]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const isSelective =
        values.attachmentId && values.attachmentId !== "ALL";
      const targetAtt = isSelective
        ? attachments.find(
            (a: Attachment) =>
              String(a.id_documento ?? a.id_adjunto_fisico) ===
              values.attachmentId,
          )
        : null;

      const toastId = toast.loading(
        isSelective
          ? `Reauditando soporte: ${targetAtt?.nombre_documento || values.attachmentId}...`
          : "Ejecutando auditoría integral...",
        {
          description: `Factura: ${values.disDetNro} ${
            isSelective
              ? `(Soporte #${values.attachmentId})`
              : "(Todos los soportes)"
          }`,
        },
      );

      return runAuditSingle(
        values.disId,
        values.disDetNro,
        isSelective ? values.attachmentId : null,
      ).finally(() => toast.dismiss(toastId));
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
              ? `Tiempo de ejecución: ${(duration / 1000).toFixed(1)}s`
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

  // Atajo de teclado: Ctrl+Enter o Cmd+Enter para disparar la ejecución
  const handleKeyDown = (e: React.KeyboardEvent<HTMLFormElement>) => {
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      if (!mutation.isPending && !isPolling) {
        form.handleSubmit(handleSubmit)();
      }
    }
  };

  const isPolling = pollingAuditId !== null;
  const isSelectiveActive =
    selectedAttachmentId !== "ALL" && selectedAttachmentId !== "";
  const selectedDocInfo = attachments.find(
    (a: Attachment) =>
      String(a.id_documento ?? a.id_adjunto_fisico) === selectedAttachmentId,
  );

  return (
    <div className="space-y-6">
      <SectionCard
        title="Parámetros de ejecución"
        description="Identifica la dispensación y selecciona el soporte específico para reauditoría selectiva o todos para auditoría completa."
      >
        <form
          className="space-y-4"
          onSubmit={form.handleSubmit(handleSubmit)}
          onKeyDown={handleKeyDown}
          aria-label="Filtros de ejecución"
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field>
              <FieldLabel htmlFor="disId">ID Dispensación (opcional)</FieldLabel>
              <Input
                id="disId"
                placeholder="Ej. 87723098"
                className="font-mono text-sm tracking-wide placeholder:font-sans"
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
                  className="text-rose-400 text-xs"
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
                placeholder="Ej. D12260900335"
                className="font-mono text-sm uppercase tracking-wide placeholder:font-sans placeholder:normal-case"
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
                  className="text-rose-400 text-xs"
                  role="alert"
                >
                  {form.formState.errors.disDetNro.message}
                </FieldDescription>
              )}
            </Field>

            <Field>
              <div className="flex items-center justify-between">
                <FieldLabel htmlFor="attachmentId">
                  Soporte Objetivo
                </FieldLabel>
                {attachmentsQueryResult.isFetching && (
                  <span className="inline-flex items-center gap-1 font-mono text-[11px] text-muted-foreground animate-pulse">
                    <Loader2 className="h-3 w-3 animate-spin text-sky-400" />
                    Buscando soportes...
                  </span>
                )}
              </div>

              <Controller
                control={form.control}
                name="attachmentId"
                render={({ field }) => (
                  <Select
                    value={field.value || "ALL"}
                    onValueChange={(val) => field.onChange(val)}
                  >
                    <SelectTrigger
                      id="attachmentId"
                      className="w-full bg-[var(--surface-raised)] font-mono text-xs"
                    >
                      <SelectValue placeholder="Seleccionar soporte" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ALL">
                        <span className="flex items-center gap-2 font-medium">
                          <Files className="h-3.5 w-3.5 text-sky-400" />
                          Todos los soportes (Auditoría completa)
                        </span>
                      </SelectItem>

                      {attachments.map((att: Attachment) => {
                        const val = String(
                          att.id_documento ?? att.id_adjunto_fisico ?? "",
                        );
                        if (!val) return null;
                        const storageLabel = att.TipoAlmacenamiento || "DOC";

                        return (
                          <SelectItem key={val} value={val}>
                            <span className="flex items-center gap-2 font-mono">
                              <FileText className="h-3.5 w-3.5 text-sky-400" />
                              <span className="font-semibold text-foreground">
                                [{att.id_documento ?? "#"}] {att.nombre_documento || "DOCUMENTO"}
                              </span>
                              <span className="text-[10px] text-muted-foreground">
                                ({storageLabel})
                              </span>
                            </span>
                          </SelectItem>
                        );
                      })}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldDescription>
                {isSelectiveActive && selectedDocInfo ? (
                  <span className="flex items-center gap-1.5 text-sky-400 font-mono text-xs">
                    <Zap className="h-3 w-3 shrink-0" />
                    Reauditoría selectiva: solo [{selectedDocInfo.id_documento}] {selectedDocInfo.nombre_documento}
                  </span>
                ) : hasAttachments ? (
                  `${attachments.length} documento(s) indexado(s) en la dispensa.`
                ) : debouncedDisDetNro.length >= 3 && !attachmentsQueryResult.isFetching ? (
                  "No se hallaron soportes adjuntos para esta dispensa."
                ) : (
                  "Ingresa el número de factura para consultar sus soportes."
                )}
              </FieldDescription>
            </Field>
          </div>

          <div className="flex items-center justify-between pt-1">
            <span className="font-mono text-[11px] text-muted-foreground hidden sm:inline-block">
              Presiona <kbd className="rounded border border-border px-1 py-0.5 text-[10px] bg-muted">Ctrl+Enter</kbd> para ejecutar
            </span>

            <Button
              type="submit"
              disabled={mutation.isPending || isPolling}
              aria-busy={mutation.isPending || isPolling}
              className="min-w-48 ml-auto font-medium"
              loading={mutation.isPending}
              loadingLabel="Procesando pipeline..."
            >
              {isPolling ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Pipeline en curso...
                </>
              ) : isSelectiveActive ? (
                <>
                  <Sparkles className="h-4 w-4 text-amber-300" />
                  Reauditar Soporte ({selectedDocInfo?.nombre_documento || `#${selectedAttachmentId}`})
                </>
              ) : (
                <>
                  <Play className="h-4 w-4" />
                  Ejecutar Auditoría Completa
                </>
              )}
            </Button>
          </div>
        </form>
      </SectionCard>

      <section className="flex flex-col gap-4">
        <div>
          <h2 className="font-display text-lg font-semibold tracking-[-0.015em] text-foreground">
            Lienzo de Telemetría
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Observación en vivo y trazabilidad técnica del pipeline de auditoría IA.
          </p>
        </div>
        <LiveAuditFlow auditId={activeAuditId ?? undefined} />
      </section>
    </div>
  );
}
