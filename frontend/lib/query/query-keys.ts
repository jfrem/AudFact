export const queryKeys = {
  health: ["health"] as const,
  asyncMetrics: ["async-metrics"] as const,
  publicConfig: ["public-config"] as const,
  attachments: (disDetNro: string, nitSec: string | number) =>
    ["attachments", disDetNro, nitSec] as const,
  attachmentPreview: (disDetNro: string, attachmentId: string | number) =>
    ["attachment-preview", disDetNro, attachmentId] as const,
  dispensation: (disId: string, disDetNro: string) => ["dispensation", disId, disDetNro] as const,
  auditJob: (jobId: string) => ["audit-job", jobId] as const,
  auditResults: (scope: string) => ["audit-results", scope] as const,
};
