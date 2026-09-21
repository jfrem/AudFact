import Link from "next/link";
import {
  AlertTriangle,
  ArrowRight,
  Building2,
  FileSearch,
  Settings2,
} from "lucide-react";

import { getClients } from "@/lib/api/audfact";
import { describeError } from "@/lib/api/errors";
import type { ClientRecord } from "@/lib/schemas/domain";
import { PageHeader } from "@/components/layout/page-header";
import { SectionCard } from "@/components/shared/section-card";
import { EmptyState } from "@/components/shared/empty-state";
import { ClientsFilterForm } from "@/components/clients/clients-filter-form";
import { formatNumber } from "@/lib/formatters";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
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

  let sourceClients: ClientRecord[] = [];
  let loadError: string | null = null;
  try {
    sourceClients = (await getClients()) ?? [];
  } catch (error) {
    loadError = describeError(error);
  }

  const normalizedClients = sourceClients.map(normalizeClient);
  const clients = normalizedClients.filter((client) => !clientId || client.id === clientId);
  const hasActiveFilter = Boolean(clientId);
  const exactMatchFound = Boolean(
    clientId && normalizedClients.some((client) => client.id === clientId),
  );

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Directorio operativo"
        title="Clientes y EPS"
        description="Localiza una entidad y continúa hacia sus facturas o reglas de auditoría."
        actions={
          <div className="text-right">
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
              Registros visibles
            </p>
            <p className="mt-1 font-mono text-lg font-semibold tabular-nums text-foreground">
              {formatNumber(clients.length)}
              <span className="text-sm font-normal text-muted-foreground"> / {formatNumber(normalizedClients.length)}</span>
            </p>
          </div>
        }
      />

      {loadError ? (
        <Alert variant="destructive">
          <AlertTriangle />
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      ) : null}

      <SectionCard
        title="Buscar entidad"
        description="La búsqueda usa la llave operativa NitSec."
      >
        <ClientsFilterForm clients={sourceClients} initialClientId={clientId} />
      </SectionCard>

      <SectionCard
        title={hasActiveFilter ? "Coincidencia" : "Directorio"}
        description={
          hasActiveFilter
            ? exactMatchFound
              ? `Resultado exacto para NitSec ${clientId}.`
              : `No existe una entidad con NitSec ${clientId}.`
            : "Entidades disponibles y sus accesos directos."
        }
        actions={
          clients.length > 0 ? (
            <Button asChild variant="ghost" size="sm">
              <Link href="/clients/audit-config">
                Configuración global
                <ArrowRight />
              </Link>
            </Button>
          ) : null
        }
      >
        {clients.length === 0 ? (
          <EmptyState
            title={loadError ? "Directorio no disponible" : "Sin coincidencias"}
            description={
              loadError
                ? "El backend no entregó el catálogo de clientes."
                : hasActiveFilter
                  ? `No se encontró el cliente ${clientId}.`
                  : "No hay clientes disponibles."
            }
            action={
              hasActiveFilter ? (
                <Button asChild variant="secondary">
                  <Link href="/clients">Limpiar búsqueda</Link>
                </Button>
              ) : null
            }
          />
        ) : (
          <div className="divide-y divide-border rounded-md border border-border">
            {clients.map((client) => (
              <article
                key={client.id}
                className="grid min-w-0 gap-4 px-4 py-4 transition-colors hover:bg-[var(--surface-hover)] md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
              >
                <div className="flex min-w-0 items-center gap-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted/45 text-primary" aria-hidden="true">
                    <Building2 className="h-4 w-4" />
                  </span>
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="truncate font-medium text-foreground">{client.name}</h3>
                      {clientId === client.id ? (
                        <Badge variant="info">Coincidencia exacta</Badge>
                      ) : null}
                    </div>
                    <p className="mt-1 font-mono text-xs tabular-nums text-muted-foreground">
                      NitSec {client.id}
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 md:justify-end">
                  <Button asChild variant="secondary" size="sm">
                    <Link href={`/invoices?facNitSec=${encodeURIComponent(client.id)}`}>
                      <FileSearch />
                      Facturas
                    </Link>
                  </Button>
                  <Button asChild variant="outline" size="sm">
                    <Link href={`/clients/audit-config?clientId=${encodeURIComponent(client.id)}`}>
                      <Settings2 />
                      Reglas
                    </Link>
                  </Button>
                </div>
              </article>
            ))}
          </div>
        )}
      </SectionCard>
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

  return { id, name };
}

function pickFirstValue(source: ClientRecord, keys: string[], fallback: string): string {
  for (const key of keys) {
    const normalized = normalizeTextValue(source[key]);
    if (normalized) return normalized;
  }

  return fallback;
}

function normalizeTextValue(value: unknown): string {
  if (value === null || value === undefined) return "";
  return String(value).trim();
}
