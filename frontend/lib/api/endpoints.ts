import { buildSearchParams } from "@/lib/utils";

export const endpoints = {
  health: () => "/health",
  asyncMetrics: () => "/metrics/async",
  publicConfig: () => "/config/public",
  clients: () => "/clients",
  clientById: (clientId: string | number) => `/clients/${clientId}`,
  clientDocuments: (clientId: string | number) => `/clients/${clientId}/documents`,
  auditConfig: (clientId: string | number) => `/clients/${clientId}/audit-config`,
  invoices: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/invoices?${params.toString()}` : "/invoices";
  },
  dispensationById: (disDetNro: string) => `/dispensation/${disDetNro}`,
  dispensationAttachments: (invoiceId: string, nitSec: string | number) =>
    `/dispensation/${invoiceId}/attachments/${nitSec}`,
  attachmentDownload: (invoiceId: string, attachmentId: string | number) =>
    `/dispensation/${invoiceId}/attachments/download/${attachmentId}`,
  auditSingle: () => "/audit/single",
  auditAsync: () => "/audit/async",
  auditJob: (jobId: string) => `/audit/jobs/${jobId}`,
  auditResults: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/audit/results?${params.toString()}` : "/audit/results";
  },
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
