import { queryOptions } from "@tanstack/react-query";

import {
  getAttachmentPreview,
  getAttachments,
  getAuditJob,
  getDispensationDetail,
} from "@/lib/api/audfact";
import { appConfig } from "@/lib/api/config";
import { queryKeys } from "@/lib/query/query-keys";

export const dispensationQuery = (disDetNro: string) =>
  queryOptions({
    queryKey: queryKeys.dispensation(disDetNro),
    queryFn: () => getDispensationDetail(disDetNro),
    enabled: Boolean(disDetNro),
  });

export const attachmentsQuery = (invoiceId: string, nitSec: string | number) =>
  queryOptions({
    queryKey: queryKeys.attachments(invoiceId, nitSec),
    queryFn: () => getAttachments(invoiceId, nitSec),
    enabled: Boolean(invoiceId) && Boolean(nitSec),
  });

export const attachmentPreviewQuery = (
  invoiceId: string,
  attachmentId: string | number,
) =>
  queryOptions({
    queryKey: queryKeys.attachmentPreview(invoiceId, attachmentId),
    queryFn: () => getAttachmentPreview(invoiceId, attachmentId),
    enabled: Boolean(invoiceId) && Boolean(attachmentId),
  });

export const auditJobQuery = (jobId: string) =>
  queryOptions({
    queryKey: queryKeys.auditJob(jobId),
    queryFn: () => getAuditJob(jobId),
    enabled: Boolean(jobId),
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === "completed" || status === "failed"
        ? false
        : appConfig.pollingJobsMs;
    },
  });
