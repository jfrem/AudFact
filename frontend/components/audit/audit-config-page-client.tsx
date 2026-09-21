"use client";

import * as React from "react";
import {
  AlertTriangle,
  DatabaseZap,
  FileX2,
  RefreshCw,
  ShieldCheck,
  SlidersHorizontal,
  Users,
} from "lucide-react";

import { ClientSelector } from "@/components/audit/client-selector";
import { CreateConfigDialog } from "@/components/audit/create-config-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { SectionCard } from "@/components/shared/section-card";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

type Client = { NitSec: string; NitCom: string };
type ConfigLoadState = "idle" | "loaded" | "not-found" | "error";

interface Props {
  clients: Client[];
  clientId: string;
  clientsError?: string | null;
  configLoadState: ConfigLoadState;
  configError?: string | null;
  hasConfig: boolean;
  editor: React.ReactNode;
}

export function AuditConfigPageClient({
  clients,
  clientId,
  clientsError,
  configLoadState,
  configError,
  hasConfig,
  editor,
}: Props) {
  const navigation = usePendingNavigation();
  const [createOpen, setCreateOpen] = React.useState<string | boolean>(false);
  const selected = clients.find((client) => client.NitSec === clientId) ?? null;

  return (
    <>
      <CreateConfigDialog
        open={Boolean(createOpen)}
        initialNitSec={typeof createOpen === "string" ? createOpen : undefined}
        onClose={() => setCreateOpen(false)}
      />

      <div className="space-y-6">
        <PageHeader
          eyebrow="Reglas de auditoría"
          title="Configuración por cliente"
          description="Define el contrato documental y los campos que Gemini debe comprobar para cada EPS."
        />

        <div className="grid border-y border-border sm:grid-cols-3">
          <StatusDatum
            icon={Users}
            label="Clientes disponibles"
            value={clientsError ? "Sin conexión" : String(clients.length)}
            tone={clientsError ? "danger" : "neutral"}
          />
          <StatusDatum
            icon={ShieldCheck}
            label="Contrato API"
            value={clientsError || configError ? "Requiere atención" : "Verificado"}
            tone={clientsError || configError ? "danger" : "success"}
          />
          <StatusDatum
            icon={DatabaseZap}
            label="Motor de análisis"
            value="Gemini multimodal"
            tone="info"
          />
        </div>

        <SectionCard
          title="Cliente bajo análisis"
          description="Selecciona la EPS cuyo contrato de auditoría deseas consultar o editar."
        >
          <Field className="relative z-40 max-w-3xl">
            <FieldLabel>
              <Users className="h-3.5 w-3.5" aria-hidden="true" />
              EPS / cliente
            </FieldLabel>
            <ClientSelector
              clients={clients}
              currentClientId={clientId}
              onCreateNew={() => setCreateOpen(true)}
            />
            {clientsError ? (
              <Alert variant="warning" className="mt-2 px-3 py-2">
                <AlertTriangle />
                <AlertDescription className="text-xs leading-5">
                  No se pudo cargar el listado de clientes: {clientsError}
                </AlertDescription>
              </Alert>
            ) : null}
          </Field>
        </SectionCard>

        {!clientId && clientsError ? (
          <ErrorPanel
            title="No se pudo cargar la configuración"
            description="La interfaz no mostrará datos de respaldo porque el backend no confirmó el catálogo de clientes."
            detail={clientsError}
            retryPending={navigation.isPending}
            onRetry={() => navigation.refresh()}
          />
        ) : null}

        {!clientId && !clientsError ? (
          <EmptyState
            icon={<SlidersHorizontal className="h-5 w-5" />}
            title="Selecciona un cliente"
            description="La configuración aparecerá aquí cuando elijas una EPS del selector."
          />
        ) : null}

        {clientId && configLoadState === "error" ? (
          <ErrorPanel
            title="No se pudo confirmar el contrato del cliente"
            description="La edición permanece bloqueada hasta que el backend entregue una configuración válida."
            detail={configError ?? "Error de API no especificado."}
            retryPending={navigation.isPending}
            onRetry={() => navigation.refresh()}
          />
        ) : null}

        {clientId && configLoadState === "not-found" && !hasConfig ? (
          <Alert variant="warning" role="status" className="p-5 sm:p-6">
            <FileX2 className="mt-0.5 h-5 w-5" />
            <div className="space-y-3">
              <AlertTitle>
                {selected?.NitCom ?? clientId} no tiene configuración
              </AlertTitle>
              <AlertDescription>
                La inicialización consultará primero el catálogo documental real;
                no se precargarán campos auditables inventados.
              </AlertDescription>
              <Button
                type="button"
                variant="outline"
                className="border-warning/40 text-warning hover:bg-warning/10"
                onClick={() => setCreateOpen(clientId)}
              >
                Inicializar contrato
              </Button>
            </div>
          </Alert>
        ) : null}

        {clientId && configLoadState === "loaded" && hasConfig ? editor : null}
      </div>
    </>
  );
}

function StatusDatum({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: React.ElementType;
  label: string;
  value: string;
  tone: "neutral" | "success" | "danger" | "info";
}) {
  const toneClass = {
    neutral: "text-foreground",
    success: "text-success",
    danger: "text-destructive",
    info: "text-info",
  }[tone];

  return (
    <div className="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
      <Icon className={`h-4 w-4 shrink-0 ${toneClass}`} aria-hidden="true" />
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
          {label}
        </p>
        <p className={`truncate text-sm font-semibold ${toneClass}`}>{value}</p>
      </div>
    </div>
  );
}

function ErrorPanel({
  title,
  description,
  detail,
  onRetry,
  retryPending = false,
}: {
  title: string;
  description: string;
  detail: string;
  onRetry: () => void;
  retryPending?: boolean;
}) {
  return (
    <Alert variant="destructive" className="p-5 sm:p-6">
      <AlertTriangle className="mt-0.5 h-5 w-5" />
      <div className="space-y-3">
        <AlertTitle>{title}</AlertTitle>
        <AlertDescription>{description}</AlertDescription>
        <p className="border-l-2 border-destructive/60 pl-3 font-mono text-xs leading-5 text-destructive">
          {detail}
        </p>
        <Button
          type="button"
          variant="destructive"
          onClick={onRetry}
          loading={retryPending}
          loadingLabel="Reintentando"
        >
          <RefreshCw className="h-4 w-4" />
          Reintentar
        </Button>
      </div>
    </Alert>
  );
}
