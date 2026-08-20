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
  dispensationById: (disId: string, disDetNro: string) => `/dispensation/${disId}/${disDetNro}`,
  dispensationLookup: () => "/dispensation",
  dispensationAttachments: (disDetNro: string, nitSec: string | number) =>
    `/dispensation/${disDetNro}/attachments/${nitSec}`,
  attachmentDownload: (disDetNro: string, attachmentId: string | number) =>
    `/dispensation/${disDetNro}/attachments/download/${attachmentId}`,
  auditSingle: () => "/audit/single",
  auditAsync: () => "/audit/async",
  auditJobs: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/audit/jobs?${params.toString()}` : "/audit/jobs";
  },
  auditJob: (jobId: string) => `/audit/jobs/${jobId}`,
  auditStatus: (auditId: string) => `/audit/status/${auditId}`,
  auditResults: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/audit/results?${params.toString()}` : "/audit/results";
  },
  auditResultDetail: (facNro: string | number) => `/audit/results/${facNro}`,
  auditStats: () => "/audit/stats",
  auditStatsMonthly: (query?: Record<string, string | number | null | undefined>) => {
    const params = buildSearchParams(query ?? {});
    return params.size ? `/audit/stats/monthly?${params.toString()}` : "/audit/stats/monthly";
  },
  auditDocumentsHistory: (
    query?: Record<string, string | number | null | undefined>,
  ) => {
    const params = buildSearchParams(query ?? {});
    return params.size
      ? `/audit/documents-history?${params.toString()}`
      : "/audit/documents-history";
  },
};
