import {
  AttachmentPreviewSchema,
  AsyncMetricsSchema,
  AttachmentsSchema,
  AuditConfigSchema,
  AuditJobSchema,
  AuditSingleResponseSchema,
  ClientDocumentsSchema,
  ClientsSchema,
  DispensationDetailSchema,
  HealthSchema,
  InvoicesSchema,
  PaginatedAuditDocumentHistorySchema,
  PaginatedAuditResultsSchema,
  PublicConfigSchema,
  SaveAuditConfigResponseSchema,
  AuditStatsSchema,
} from "@/lib/schemas/domain";
import { endpoints } from "@/lib/api/endpoints";
import {
  postJson,
  requestAttachmentPreview,
  requestJson,
} from "@/lib/api/client";
import { buildPublicApiUrl } from "@/lib/api/config";

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
  limit?: number;
}) {
  return requestJson(endpoints.invoices(query), InvoicesSchema);
}

export function getDispensationDetail(disDetNro: string) {
  return requestJson(endpoints.dispensationById(disDetNro), DispensationDetailSchema);
}

export function getAttachments(invoiceId: string, nitSec: string | number) {
  return requestJson(
    endpoints.dispensationAttachments(invoiceId, nitSec),
    AttachmentsSchema,
  );
}

export function getAttachmentPreview(invoiceId: string, attachmentId: string | number) {
  return requestAttachmentPreview(
    endpoints.attachmentDownload(invoiceId, attachmentId),
    AttachmentPreviewSchema,
  );
}

export function getAttachmentDownloadUrl(
  invoiceId: string,
  attachmentId: string | number,
) {
  return buildPublicApiUrl(endpoints.attachmentDownload(invoiceId, attachmentId));
}

export function runAuditSingle(disDetNro: string) {
  return postJson(
    endpoints.auditSingle(),
    { DisDetNro: disDetNro },
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

export function getAuditResults(query?: Record<string, string | number | undefined>) {
  return requestJson(endpoints.auditResults(query), PaginatedAuditResultsSchema);
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
  systemPrompt?: string | null;
  fields: Array<{
    docId: number;
    campoNombre: string;
    tipoCampo: string;
    tipoDato?: string | null;
    enabled: boolean;
    description?: string | null;
    severity?: string | null;
    orden: number;
  }>;
};

export function saveAuditConfig(clientId: string | number, payload: AuditConfigPayload) {
  return postJson(
    endpoints.auditConfig(clientId),
    payload,
    SaveAuditConfigResponseSchema,
  );
}
