import {
  AttachmentPreviewSchema,
  AsyncMetricsSchema,
  AttachmentsSchema,
  AuditConfigSchema,
  AuditResultDetailSchema,
  AuditJobSchema,
  AuditSingleResponseSchema,
  ClientDocumentsSchema,
  ClientsSchema,
  DispensationDetailSchema,
  HealthSchema,
  PaginatedInvoicesSchema,
  PaginatedAuditDocumentHistorySchema,
  PaginatedAuditResultsSchema,
  PublicConfigSchema,
  SaveAuditConfigResponseSchema,
  AuditStatsSchema,
  AuditLiveStatusSchema,
  FieldCatalogSchema,
} from "@/lib/schemas/domain";
import type { AuditResultDetail } from "@/lib/schemas/domain";
import { endpoints } from "@/lib/api/endpoints";
import {
  postJson,
  requestAttachmentPreview,
  requestJson,
} from "@/lib/api/client";
import { buildPublicApiUrl } from "@/lib/api/config";

export const INVOICE_SEARCH_DEFAULT_PAGE_SIZE = 20;
export const INVOICE_SEARCH_PAGE_SIZE_OPTIONS = [20, 50, 100] as const;

export function getHealth() {
  return requestJson(endpoints.health(), HealthSchema);
}

export function getAsyncMetrics() {
  return requestJson(endpoints.asyncMetrics(), AsyncMetricsSchema);
}

export function getPublicConfig() {
  return requestJson(endpoints.publicConfig(), PublicConfigSchema);
}

export function getClients() {
  return requestJson(endpoints.clients(), ClientsSchema);
}

export function getClientById(clientId: string | number) {
  return requestJson(endpoints.clientById(clientId), ClientsSchema.element);
}

export function getClientDocuments(clientId: string | number) {
  return requestJson(endpoints.clientDocuments(clientId), ClientDocumentsSchema);
}

export function getInvoices(query: {
  facNitSec: number | string;
  dateFrom: string;
  dateTo?: string;
  page?: number;
  pageSize?: number;
}) {
  return requestJson(endpoints.invoices(query), PaginatedInvoicesSchema);
}

export function getDispensationDetail(disId: string | undefined | null, disDetNro: string) {
  if (!disId?.trim()) {
    return postJson(endpoints.dispensationLookup(), { DisDetNro: disDetNro }, DispensationDetailSchema)
      .then((envelope) => envelope.data);
  }
  return requestJson(endpoints.dispensationById(disId, disDetNro), DispensationDetailSchema);
}

export function getAttachments(disDetNro: string, nitSec: string | number) {
  return requestJson(
    endpoints.dispensationAttachments(disDetNro, nitSec),
    AttachmentsSchema,
  );
}

export function getAttachmentPreview(disDetNro: string, attachmentId: string | number) {
  return requestAttachmentPreview(
    endpoints.attachmentDownload(disDetNro, attachmentId),
    AttachmentPreviewSchema,
  );
}

export function getAttachmentDownloadUrl(
  disDetNro: string,
  attachmentId: string | number,
) {
  return buildPublicApiUrl(endpoints.attachmentDownload(disDetNro, attachmentId));
}

export function runAuditSingle(disId: string | undefined | null, disDetNro: string) {
  return postJson(
    endpoints.auditSingle(),
    { disId: disId?.trim() || "", disDetNro },
    AuditSingleResponseSchema,
  );
}

export function enqueueAuditBatch(payload: {
  facNitSec: number;
  date: string;
  dateTo?: string;
  limit: number;
}) {
  return postJson(endpoints.auditAsync(), payload, AuditJobSchema);
}

export function getAuditJob(jobId: string) {
  return requestJson(endpoints.auditJob(jobId), AuditJobSchema);
}

export function getAuditLiveStatus(auditId: string) {
  return requestJson(endpoints.auditStatus(auditId), AuditLiveStatusSchema);
}

export function getAuditResults(query?: Record<string, string | number | undefined>) {
  return requestJson(endpoints.auditResults(query), PaginatedAuditResultsSchema);
}

export function getAuditResultDetail(disId: string | number): Promise<AuditResultDetail> {
  return requestJson(endpoints.auditResultDetail(disId), AuditResultDetailSchema) as Promise<AuditResultDetail>;
}

export function getAuditStats() {
  return requestJson(endpoints.auditStats(), AuditStatsSchema);
}

export function getAuditDocumentsHistory(
  query?: Record<string, string | number | undefined>,
) {
  return requestJson(
    endpoints.auditDocumentsHistory(query),
    PaginatedAuditDocumentHistorySchema,
  );
}

export function getAuditConfig(clientId: string | number) {
  return requestJson(endpoints.auditConfig(clientId), AuditConfigSchema);
}

export type AuditConfigPayload = {
  systemPrompt: string | null;
  fields: Array<{
    docId: number;
    campoNombre: string;
    enabled: boolean;
    description?: string | null;
    severity?: string | null;
    orden: number;
  }>;
};

export function getFieldCatalog() {
  return requestJson(endpoints.fieldCatalog(), FieldCatalogSchema);
}

export function saveAuditConfig(clientId: string | number, payload: AuditConfigPayload) {
  return postJson(
    endpoints.auditConfig(clientId),
    payload,
    SaveAuditConfigResponseSchema,
  );
}
