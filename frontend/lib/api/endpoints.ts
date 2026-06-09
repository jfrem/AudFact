import { buildSearchParams } from "@/lib/utils";

export const endpoints = {
  health: () => "/health",
  asyncMetrics: () => "/metrics/async",
  publicConfig: () => "/config/public",
  clients: () => "/clients",
  clientById: (clientId: string | number) => `/clients/${clientId}`,
  clientDocuments: (clientId: string | number) => `/clients/${clientId}/documents`,
  auditConfig: (clientId: string | number) => `/clients/${clientId}/audit-config`,
  fieldCatalog: () => "/audit/field-catalog",
  invoices: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/invoices?${params.toString()}` : "/invoices";
  },
  dispensationById: (disDetNro: string) => `/dispensation/${disDetNro}`,
  dispensationAttachments: (disDetNro: string, nitSec: string | number) =>
    `/dispensation/${disDetNro}/attachments/${nitSec}`,
  attachmentDownload: (disDetNro: string, attachmentId: string | number) =>
    `/dispensation/${disDetNro}/attachments/download/${attachmentId}`,
  auditSingle: () => "/audit/single",
  auditAsync: () => "/audit/async",
  auditJob: (jobId: string) => `/audit/jobs/${jobId}`,
  auditStatus: (auditId: string) => `/audit/status/${auditId}`,
  auditResults: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/audit/results?${params.toString()}` : "/audit/results";
  },
  auditResultDetail: (facSec: string | number) => `/audit/results/${facSec}`,
  auditStats: () => "/audit/stats",
  auditDocumentsHistory: (
    query?: Record<string, string | number | null | undefined>,
  ) => {
    const params = buildSearchParams(query ?? {});
    return params.size
      ? `/audit/documents-history?${params.toString()}`
      : "/audit/documents-history";
  },
};
