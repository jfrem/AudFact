import Link from "next/link";
import {
  ArrowRight,
  Building2,
  FileSearch,
  Settings2,
  Sparkles,
  Users2,
} from "lucide-react";

import { Suspense } from "react";
import { getClients } from "@/lib/api/audfact";
import type { ClientRecord } from "@/lib/schemas/domain";
import { PageHeader } from "@/components/layout/page-header";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { ClientsFilterForm } from "@/components/clients/clients-filter-form";
import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { formatNumber } from "@/lib/formatters";
import { Button } from "@/components/ui/button";

type ClientDirectoryItem = {
  id: string;
  name: string;
};

export default async function ClientsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const clientId = typeof params.clientId === "string" ? params.clientId.trim() : "";
  const sourceClients: ClientRecord[] = ((await getClients().catch(() => [])) ?? []);
  const normalizedClients = sourceClients.map(normalizeClient);
  const clients = normalizedClients.filter((client) => !clientId || client.id === clientId);
  const hasActiveFilters = Boolean(clientId);
  const exactMatchFound = Boolean(clientId && normalizedClients.some((client) => client.id === clientId));
  const summaryLabel = clientId
    ? exactMatchFound
      ? `Cliente ${clientId} encontrado`
      : `No existe coincidencia exacta para ${clientId}`
    : "Directorio completo disponible";
  const helperText = hasActiveFilters
    ? "Refina la consulta o limpia el filtro para volver al directorio completo."
    : "Busca por razón social o NitSec y entra directo a facturas o configuración.";

  const searchParamsKey = JSON.stringify({ clientId });

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Consulta"
        title="Clientes / EPS"
        description="Directorio operativo para encontrar una EPS rápido y ejecutar la siguiente acción sin ruido visual."
        actions={
          <div className="rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3">
            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">
              Vista actual
            </p>
            <p className="mt-1 text-sm font-medium text-white">
              {formatNumber(clients.length)} de {formatNumber(normalizedClients.length)} clientes
            </p>
          </div>
        }
      />

      <div className="stagger-children grid gap-3 md:grid-cols-2">
        <HighlightCard
          icon={Building2}
          label="Directorio"
          value={formatNumber(normalizedClients.length)}
          caption="Clientes disponibles para consulta"
        />
        <HighlightCard
          icon={Sparkles}
          label={hasActiveFilters ? "Filtro activo" : "Exploración"}
          value={formatNumber(clients.length)}
          caption={summaryLabel}
          accent="sky"
        />
      </div>

      <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4 md:px-5">
        <ClientsFilterForm
          clients={sourceClients}
          initialClientId={clientId}
        />
      </div>

      <Suspense
        key={searchParamsKey}
        fallback={
          <SectionCard
            title={hasActiveFilters ? "Resultado de la búsqueda" : "Directorio de clientes"}
            description={
              hasActiveFilters
                ? "Se muestra únicamente el cliente seleccionado por el filtro actual."
                : "Cada card resume identidad y acceso directo a las acciones disponibles."
            }
          >
            <BackendRequestSkeleton
              description="El directorio se está filtrando..."
              rows={3}
              title="Consultando clientes"
              variant="table"
            />
          </SectionCard>
        }
      >
        <ClientsGridFetcher
          clients={clients}
          clientId={clientId}
          hasActiveFilters={hasActiveFilters}
        />
      </Suspense>
    </div>
  );
}

function ClientsGridFetcher({
  clients,
  clientId,
  hasActiveFilters,
}: {
  clients: ClientDirectoryItem[];
  clientId: string;
  hasActiveFilters: boolean;
}) {
  return (
    <SectionCard
      title={hasActiveFilters ? "Resultado de la búsqueda" : "Directorio de clientes"}
      description={
        hasActiveFilters
          ? "Se muestra únicamente el cliente seleccionado por el filtro actual."
          : "Cada card resume identidad y acceso directo a las acciones disponibles."
      }
      actions={
        <div className="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1.5 text-sm text-slate-300">
          {formatNumber(clients.length)} {clients.length === 1 ? "cliente" : "clientes"}
        </div>
      }
    >
      {clients.length === 0 ? (
        <EmptyState
          title="Sin clientes"
          description={
            clientId
              ? `No se encontró el cliente con ID ${clientId}.`
              : "No se encontraron clientes para los criterios actuales."
          }
          action={
            hasActiveFilters ? (
              <Button asChild variant="secondary">
                <Link href="/clients">Volver al directorio</Link>
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="stagger-children grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {clients.map((client) => (
            <article
              key={client.id}
              className="group flex h-full flex-col justify-between rounded-xl border border-white/10 bg-white/[0.02] p-3.5 transition-[border-color,background-color,transform] duration-200 hover:border-sky-400/20 hover:bg-white/[0.04]"
            >
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-2.5">
                  <div className="min-w-0 space-y-2.5">
                    <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] text-sky-300">
                      <Users2 className="h-4.5 w-4.5" />
                    </span>
                    <div className="min-w-0">
                      <p className="text-balance text-sm font-semibold text-white sm:text-base">
                        {client.name}
                      </p>
                      <p className="mt-1 text-xs text-slate-400">NitSec {client.id}</p>
                    </div>
                  </div>
                </div>

                {clientId === client.id ? (
                  <div className="flex flex-wrap gap-2 text-xs">
                    <span className="rounded-lg border border-white/10 bg-white/[0.05] px-3 py-1 font-medium text-white">
                      Coincidencia exacta
                    </span>
                  </div>
                ) : null}
              </div>

              <div className="mt-4 grid gap-2 sm:grid-cols-2">
                <Button asChild variant="secondary" className="w-full">
                  <Link href={`/invoices?facNitSec=${encodeURIComponent(client.id)}`}>
                    <FileSearch className="h-4 w-4" />
                    Ver facturas
                  </Link>
                </Button>
                <Button asChild variant="outline" className="w-full">
                  <Link href={`/clients/audit-config?clientId=${encodeURIComponent(client.id)}`}>
                    <Settings2 className="h-4 w-4" />
                    Config auditoría
                  </Link>
                </Button>
              </div>
            </article>
          ))}
        </div>
      )}

      {clients.length > 0 ? (
        <div className="mt-4 flex justify-end">
          <Link
            href="/clients/audit-config"
            className="inline-flex items-center gap-1.5 text-sm text-sky-400 transition hover:text-sky-300"
          >
            Ir a configuración global <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        </div>
      ) : null}
    </SectionCard>
  );
}

function HighlightCard({
  icon: Icon,
  label,
  value,
  caption,
  accent = "default",
}: {
  icon: typeof Building2;
  label: string;
  value: string;
  caption: string;
  accent?: "default" | "sky";
}) {
  const accentClass =
    accent === "sky"
      ? "text-sky-300 border-sky-500/20 bg-sky-500/10"
      : "text-slate-200 border-white/10 bg-white/[0.03]";

  return (
    <div className="panel rounded-xl px-4 py-4">
      <div className="flex items-start gap-3">
        <div
          className={`inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border ${accentClass}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
            {label}
          </p>
          <p className="mt-1 text-2xl font-semibold tracking-tight text-white">{value}</p>
          <p className="mt-1 text-sm leading-6 text-slate-400">{caption}</p>
        </div>
      </div>
    </div>
  );
}

function normalizeClient(client: ClientRecord): ClientDirectoryItem {
  const id = pickFirstValue(client, ["NitSec", "nitSec", "nit", "id"], "N/D");
  const name = pickFirstValue(
    client,
    ["NitCom", "Cliente", "Nombre", "RazonSocial", "razonSocial"],
    `Cliente ${id}`,
  );

  return {
    id,
    name,
  };
}

function pickFirstValue(
  source: ClientRecord,
  keys: string[],
  fallback: string,
): string {
  for (const key of keys) {
    const normalized = normalizeTextValue(source[key]);
    if (normalized) return normalized;
  }

  return fallback;
}

function normalizeTextValue(value: unknown): string {
  if (value === null || value === undefined) return "";
  const normalized = String(value).trim();
  return normalized;
}
