export const queryKeys = {
  health: ["health"] as const,
  asyncMetrics: ["async-metrics"] as const,
  publicConfig: ["public-config"] as const,
  attachments: (invoiceId: string, nitSec: string | number) =>
    ["attachments", invoiceId, nitSec] as const,
  attachmentPreview: (invoiceId: string, attachmentId: string | number) =>
    ["attachment-preview", invoiceId, attachmentId] as const,
  dispensation: (disDetNro: string) => ["dispensation", disDetNro] as const,
  auditJob: (jobId: string) => ["audit-job", jobId] as const,
  auditResults: (scope: string) => ["audit-results", scope] as const,
};
