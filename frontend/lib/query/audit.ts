import { queryOptions } from "@tanstack/react-query";

import {
  getAttachmentPreview,
  getAttachments,
  getAuditJob,
  getDispensationDetail,
} from "@/lib/api/audfact";
import { appConfig } from "@/lib/api/config";
import { queryKeys } from "@/lib/query/query-keys";

export const dispensationQuery = (disId: string | undefined | null, disDetNro: string) =>
  queryOptions({
    queryKey: queryKeys.dispensation(disId || "lookup", disDetNro),
    queryFn: () => getDispensationDetail(disId, disDetNro),
    enabled: Boolean(disDetNro),
  });

export const attachmentsQuery = (disDetNro: string, nitSec?: string | number | null) =>
  queryOptions({
    queryKey: queryKeys.attachments(disDetNro, nitSec),
    queryFn: async () => {
      const data = await getAttachments(disDetNro, nitSec);
      return data ?? [];
    },
    enabled: Boolean(disDetNro),
  });

export const attachmentPreviewQuery = (
  disDetNro: string,
  attachmentId: string | number,
) =>
  queryOptions({
    queryKey: queryKeys.attachmentPreview(disDetNro, attachmentId),
    queryFn: () => getAttachmentPreview(disDetNro, attachmentId),
    enabled: Boolean(disDetNro) && Boolean(attachmentId),
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
