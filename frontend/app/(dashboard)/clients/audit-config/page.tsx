import { getAuditConfig, getClients } from "@/lib/api/audfact";
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

  const [rawClients, config] = await Promise.all([
    getClients().catch(() => []),
    clientId
      ? getAuditConfig(clientId).catch(() => null)
      : Promise.resolve(null),
  ]);

  const clients = (rawClients ?? []).map((c) => ({
    NitSec: String(c.NitSec ?? ""),
    NitCom: String(c.NitCom ?? c.Cliente ?? c.Nombre ?? "Cliente"),
  }));

  return (
    <AuditConfigPageClient
      clients={clients}
      clientId={clientId}
      hasConfig={!!config}
      editor={
        config ? (
          <AuditConfigEditor config={config} clientId={clientId} />
        ) : null
      }
    />
  );
}
