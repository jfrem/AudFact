import { getAuditConfig, getClientDocuments, getClients, getFieldCatalog } from "@/lib/api/audfact";
import { ApiError, describeError } from "@/lib/api/errors";
import type { AuditConfig, ClientDocument, ClientRecord, FieldCatalogItem } from "@/lib/schemas/domain";
import { AuditConfigEditor } from "@/components/audit/audit-config-editor";
import { AuditConfigPageClient } from "@/components/audit/audit-config-page-client";

export const metadata = {
  title: "AudFact | Config Auditoría",
  description: "Configura los campos auditables por cliente y tipo de documento",
};

export default async function AuditConfigPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const clientId =
    typeof params.clientId === "string" ? params.clientId.trim() : "";

  let rawClients: ClientRecord[] = [];
  let catalog: FieldCatalogItem[] = [];
  let clientsError: string | null = null;
  let config: AuditConfig | null = null;
  let configLoadState: "idle" | "loaded" | "not-found" | "error" = clientId
    ? "error"
    : "idle";
  let configError: string | null = null;

  try {
    const [fetchedClients, fetchedCatalog] = await Promise.all([
      getClients(),
      getFieldCatalog()
    ]);
    rawClients = fetchedClients ?? [];
    catalog = fetchedCatalog ?? [];
  } catch (error) {
    clientsError = describeError(error);
  }

  if (clientId) {
    try {
      const loadedConfig = await getAuditConfig(clientId);
      if (!loadedConfig) {
        throw new Error("El backend no retornó configuración para el cliente.");
      }

      config = loadedConfig;
      configLoadState = "loaded";
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        configLoadState = "not-found";
      } else {
        configLoadState = "error";
        configError = describeError(error);
      }
    }

    if (config && Object.keys(config.documents).length === 0) {
      try {
        const documents = (await getClientDocuments(clientId)) ?? [];
        if (documents.length === 0) {
          throw new Error("El cliente no tiene catálogo documental real para mostrar.");
        }

        config = {
          ...config,
          documents: buildDocumentScaffold(documents),
        };
      } catch (error) {
        config = null;
        configLoadState = "error";
        configError = describeError(error);
      }
    }
  }

  const clients = (rawClients ?? []).map((c) => ({
    NitSec: String(c.NitSec ?? ""),
    NitCom: String(c.NitCom ?? c.Cliente ?? c.Nombre ?? "Cliente"),
  }));

  return (
    <AuditConfigPageClient
      clients={clients}
      clientId={clientId}
      clientsError={clientsError}
      configLoadState={configLoadState}
      configError={configError}
      hasConfig={!!config}
      editor={
        config ? (
          <AuditConfigEditor config={config} clientId={clientId} catalog={catalog} />
        ) : null
      }
    />
  );
}

function buildDocumentScaffold(documents: ClientDocument[]): AuditConfig["documents"] {
  return documents.reduce<AuditConfig["documents"]>((acc, document) => {
    const docName = document.NitMedDocNom.trim();
    if (!docName) return acc;

    acc[docName] = {
      docId: Number(document.NitMedDocId),
      fields: [],
      visualChecks: [],
    };
    return acc;
  }, {});
}
