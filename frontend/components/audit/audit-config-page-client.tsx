"use client";

import * as React from "react";
import Link from "next/link";
import {
  Settings2,
  Users,
  ShieldCheck,
  Sliders,
  ChevronRight,
  DatabaseZap,
  FileX2,
  AlertTriangle,
  RefreshCw,
} from "lucide-react";
import { ClientSelector } from "@/components/audit/client-selector";
import { CreateConfigDialog } from "@/components/audit/create-config-dialog";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

/* ─── Types ────────────────────────────────────────────────────── */
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

/* ─── Page ─────────────────────────────────────────────────────── */
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

  const selected = clients.find((c) => c.NitSec === clientId) ?? null;
  const hasApiError = Boolean(clientsError || configError);

  return (
    <>
      <CreateConfigDialog
        open={!!createOpen}
        initialNitSec={typeof createOpen === "string" ? createOpen : undefined}
        onClose={() => setCreateOpen(false)}
      />

      <div className="space-y-6">
        {/* ── Hero Header ─────────────────────────────────────── */}
        <div className="rounded-3xl border border-white/[0.06] bg-slate-900/70 px-6 py-6 sm:px-7">
          <div className="relative flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            {/* Title block */}
            <div className="flex items-center gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-white/[0.08] bg-white/[0.03]">
                <Settings2 className="h-6 w-6 text-cyan-400" />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <span className="rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-cyan-400">
                    Configuración
                  </span>
                </div>
                <h1 className="mt-1 text-2xl font-bold tracking-tight text-white">
                  Auditoría por Cliente
                </h1>
                <p className="mt-1 text-sm text-slate-500">
                  Controla qué campos verifica la IA para cada EPS
                </p>
              </div>
            </div>

            {/* Stats pills */}
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:min-w-[420px]">
              <StatPill
                icon={Users}
                label="Clientes"
                value={clientsError ? "Error" : String(clients.length)}
                accent={clientsError ? "rose" : "cyan"}
              />
              <StatPill
                icon={ShieldCheck}
                label="API"
                value={hasApiError ? "Error" : "Conectada"}
                accent={hasApiError ? "rose" : "emerald"}
              />
              <StatPill icon={DatabaseZap} label="Motor" value="Gemini" accent="violet" />
            </div>
          </div>

          {/* Breadcrumb */}
          <div className="relative mt-4 flex items-center gap-1.5 text-[11px]">
            <Link href="/dashboard" className="text-slate-600 transition-colors hover:text-slate-400">
              Dashboard
            </Link>
            <ChevronRight className="h-3 w-3 text-slate-600" />
            <Link href="/clients" className="text-slate-600 transition-colors hover:text-slate-400">
              Clientes
            </Link>
            <ChevronRight className="h-3 w-3 text-slate-600" />
            <span className="text-slate-400">Config Auditoría</span>
          </div>
        </div>

        {/* ── Client Selector ──────────────────────────────────── */}
        <Field className="relative z-50">
          <FieldLabel className="flex items-center gap-2">
            <Users className="h-3.5 w-3.5" />
            EPS / Cliente
          </FieldLabel>
          <ClientSelector
            clients={clients}
            currentClientId={clientId}
            onCreateNew={() => setCreateOpen(true)}
          />
          {clientsError && (
            <DataWarning message={`No se pudo cargar el listado de clientes: ${clientsError}`} />
          )}
        </Field>

        {/* ── No client selected ───────────────────────────────── */}
        {!clientId && clientsError && (
          <ErrorPanel
            title="No se pudo cargar la configuración"
            description="El backend no respondió al cargar clientes. No se muestran datos de respaldo para evitar información falsa."
            detail={clientsError}
            retryPending={navigation.isPending}
            onRetry={() => navigation.refresh()}
          />
        )}

        {!clientId && !clientsError && (
          <EmptyPanel
            icon={Sliders}
            title="Selecciona un cliente para comenzar"
            description="Escoge una EPS del listado para ver y gestionar sus campos auditables por tipo de documento."
          />
        )}

        {clientId && configLoadState === "error" && (
          <ErrorPanel
            title="No se pudo cargar la configuración del cliente"
            description="La UI bloqueó la vista editable porque la configuración no fue confirmada por el backend."
            detail={configError ?? "Error de API no especificado."}
            retryPending={navigation.isPending}
            onRetry={() => navigation.refresh()}
          />
        )}

        {/* ── Client with no config ─────────────────────────────── */}
        {clientId && configLoadState === "not-found" && !hasConfig && (
          <NoConfigPanel
            clientId={clientId}
            clientName={selected?.NitCom}
            onCreateNew={() => setCreateOpen(clientId)}
          />
        )}

        {/* ── Editor ───────────────────────────────────────────── */}
        {clientId && configLoadState === "loaded" && hasConfig && editor}
      </div>
    </>
  );
}

/* ─── Sub-components ────────────────────────────────────────────── */

function StatPill({
  icon: Icon,
  label,
  value,
  accent = "cyan",
}: {
  icon: React.ElementType;
  label: string;
  value: string;
  accent?: "cyan" | "emerald" | "violet" | "rose";
}) {
  const colors = {
    cyan: "border-white/[0.08] bg-white/[0.03] text-cyan-400",
    emerald: "border-white/[0.08] bg-white/[0.03] text-emerald-400",
    violet: "border-white/[0.08] bg-white/[0.03] text-violet-400",
    rose: "border-rose-500/20 bg-rose-500/[0.06] text-rose-300",
  };
  return (
    <div
      className={`flex items-center gap-2 rounded-lg border px-3.5 py-2 ${colors[accent]}`}
    >
      <Icon className="h-3.5 w-3.5" />
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">
          {label}
        </p>
        <p className="text-sm font-bold leading-tight">{value}</p>
      </div>
    </div>
  );
}

function DataWarning({ message }: { message: string }) {
  return (
    <Alert variant="warning" className="px-3 py-2">
      <AlertTriangle />
      <AlertDescription className="text-xs leading-5">{message}</AlertDescription>
    </Alert>
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
    <Alert variant="destructive" className="block rounded-3xl px-0 py-0">
      <div className="relative flex flex-col items-center gap-5 px-8 py-16 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-lg border border-rose-500/20 bg-rose-500/10">
          <AlertTriangle className="h-8 w-8 text-rose-300" />
        </div>
        <div className="max-w-xl">
          <AlertTitle className="text-lg">{title}</AlertTitle>
          <AlertDescription className="col-auto mt-2 text-sm leading-relaxed text-slate-400">
            {description}
          </AlertDescription>
          <AlertDescription className="col-auto mt-3 rounded-lg border border-white/[0.06] bg-black/20 px-3 py-2 text-xs leading-5 text-rose-100/80">
            {detail}
          </AlertDescription>
        </div>
        <Button
          type="button"
          onClick={onRetry}
          loading={retryPending}
          loadingLabel="Reintentando"
          className="cursor-pointer rounded-lg bg-rose-500 px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 active:scale-[0.98]"
        >
          <RefreshCw className="h-4 w-4" />
          Reintentar
        </Button>
        {retryPending ? (
          <BackendRequestSkeleton
            className="w-full max-w-xl text-left"
            description="El backend está intentando cargar la configuración nuevamente."
            title="Reintentando carga"
            variant="compact"
          />
        ) : null}
      </div>
    </Alert>
  );
}

function EmptyPanel({
  icon: Icon,
  title,
  description,
}: {
  icon: React.ElementType;
  title: string;
  description: string;
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 rounded-3xl border border-white/[0.06] bg-white/[0.02] py-20 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-lg border border-white/[0.06] bg-white/[0.04]">
        <Icon className="h-8 w-8 text-slate-600" />
      </div>
      <div className="max-w-xs">
        <p className="text-base font-semibold text-slate-300">{title}</p>
        <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{description}</p>
      </div>
    </div>
  );
}

function NoConfigPanel({
  clientId,
  clientName,
  onCreateNew,
}: {
  clientId: string;
  clientName?: string;
  onCreateNew: () => void;
}) {
  return (
    <Alert variant="warning" role="status" className="block rounded-3xl px-0 py-0">
      <div className="relative flex flex-col items-center gap-5 px-8 py-16 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-lg border border-amber-500/20 bg-amber-500/10">
          <FileX2 className="h-8 w-8 text-amber-400" />
        </div>
        <div className="max-w-sm">
          <AlertTitle className="text-lg">
            {clientName ?? clientId} no tiene configuración
          </AlertTitle>
          <AlertDescription className="col-auto mt-2 text-sm leading-relaxed text-slate-500">
            Este cliente no tiene una configuración guardada. La inicialización
            valida primero el catálogo documental real y no precarga campos
            auditables inventados.
          </AlertDescription>
        </div>
        <button
          type="button"
          onClick={onCreateNew}
          className="cursor-pointer rounded-lg bg-amber-500 px-6 py-3 text-sm font-semibold text-white transition hover:brightness-110 active:scale-[0.98]"
        >
          Inicializar configuración para {clientName ?? clientId}
        </button>
      </div>
    </Alert>
  );
}
