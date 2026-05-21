import { z } from "zod";

export const ApiEnvelopeSchema = <T extends z.ZodTypeAny>(dataSchema: T) =>
  z.object({
    success: z.boolean(),
    message: z.string(),
    data: dataSchema,
    errors: z.array(z.string()).optional(),
  });

const UnknownRecordSchema = z.record(z.string(), z.unknown());
const ScalarSchema = z.union([z.string(), z.number()]);

export const PublicConfigSchema = z.object({
  auditBatchMaxLimit: z.number(),
  auditBatchTimeoutMs: z.number(),
});

export const HealthSchema = z.object({
  status: z.string(),
  timestamp: z.number().optional(),
  uptime_seconds: z.number().optional(),
  environment: z.string().optional(),
  php_version: z.string().optional(),
  services: z
    .object({
      database: z
        .object({
          status: z.string(),
          message: z.string().optional(),
          latency_ms: z.number().optional(),
        })
        .passthrough(),
      disk: z.object({ status: z.string() }).passthrough(),
      memory: z.object({ status: z.string() }).passthrough(),
    })
    .passthrough(),
});

export const AsyncMetricsSchema = z.object({
  queueDepth: z.number().int().nonnegative(),
  deadLetterDepth: z.number().int().nonnegative(),
  jobs: z.object({
    queued: z.number().int().nonnegative(),
    running: z.number().int().nonnegative(),
    completed: z.number().int().nonnegative(),
    failed: z.number().int().nonnegative(),
  }),
  retries: z.number().int().nonnegative(),
  terminalFailures: z.number().int().nonnegative(),
});

export const ClientSchema = UnknownRecordSchema;
export const ClientsSchema = z.array(ClientSchema);
export const ClientDocumentSchema = z.object({
  NitSec: ScalarSchema,
  NitMedDocId: ScalarSchema,
  NitMedDocCodAlt: z.string().nullable().optional(),
  NitMedDocNom: z.string(),
}).passthrough();
export const ClientDocumentsSchema = z.array(ClientDocumentSchema);

export const AuditConfigFieldSchema = z.object({
  campoNombre: z.string(),
  tipoCampo: z.string(), // 'E' (exacto), 'S' (semántico), 'V' (visual), 'B' (negocio)
  tipoDato: z.string(),
  enabled: z.boolean().default(true),
  description: z.string().nullable().optional(),
  severity: z.string().nullable().optional(),
  descripcionOverride: z.string().nullable().optional(),
  severityOverride: z.string().nullable().optional(),
  orden: z.number().default(0),
});

export const AuditVisualCheckSchema = z.object({
  check: z.string(),
  description: z.string().nullable().optional(),
  severity: z.string().nullable().optional(),
  enabled: z.boolean().default(true),
  orden: z.number().default(0),
});

export const AuditConfigDocumentSchema = z.object({
  docId: z.number(),
  fields: z.array(AuditConfigFieldSchema),
  visualChecks: z.array(AuditVisualCheckSchema).default([]),
});

export const AuditConfigSchema = z.object({
  nitSec: z.string(),
  activo: z.boolean(),
  systemPrompt: z.string().nullable(),
  documents: z.record(z.string(), AuditConfigDocumentSchema),
});

export const SaveAuditConfigResponseSchema = z.object({
  success: z.boolean().optional(),
}).passthrough();

export const InvoiceSchema = UnknownRecordSchema;
export const InvoicesSchema = z.array(InvoiceSchema);

export const DispensationHeaderSchema = UnknownRecordSchema;
export const DispensationItemSchema = UnknownRecordSchema;
export const DispensationDetailSchema = z.object({
  header: DispensationHeaderSchema,
  items: z.array(DispensationItemSchema),
});

export const AttachmentSchema = z.object({
  dispensacion_id: ScalarSchema.optional(),
  dis_det_nro: z.string().optional(),
  cliente: ScalarSchema.optional(),
  id_documento: ScalarSchema.optional(),
  nombre_documento: z.string().optional(),
  nombre_alternativo: z.string().optional(),
  almacenamiento_remoto: z.string().nullable().optional(),
  TipoAlmacenamiento: z.string().optional(),
}).passthrough();

export const AttachmentsSchema = z.array(AttachmentSchema);

export const AttachmentPreviewSchema = z.object({
  mime: z.string(),
  data: z.string(),
});

export const AuditDocumentStatusSchema = z.enum(["CONCILIADO", "DISCREPANCIA"]);
export const AuditFindingResultSchema = z.enum([
  "COINCIDE",
  "VALOR_DISTINTO",
  "NO_ENCONTRADO",
  "OMITIDO",
  "NO_CONCLUYENTE",
]);
export const AuditSeveritySchema = z.string();

const FindingValueSchema = z
  .union([z.string(), z.number(), z.boolean()])
  .nullish()
  .transform((value) => (value == null ? null : String(value)));

export const AuditFindingSchema = z.object({
  campo: z.string(),
  resultado: AuditFindingResultSchema,
  severidad: z.string(),
  detalle: z.unknown().nullish(),
  valorDocumento: FindingValueSchema,
  valorFuenteVerdad: FindingValueSchema,
  documento: z.string().nullish(),
  tipo_auditoria: z.string().nullish(),
  valueType: z.string().nullish(),
  valoresDocumento: z.array(z.string()).nullish(),
}).passthrough();

export const AuditTimingSummarySchema = z
  .object({
    count: z.number().int().nonnegative().optional().default(0),
    avg_ms: z.number().nonnegative().nullish(),
    min_ms: z.number().nonnegative().nullish(),
    max_ms: z.number().nonnegative().nullish(),
    p95_ms: z.number().nonnegative().nullish(),
  })
  .passthrough();

export const AuditGeminiTimingSummarySchema = AuditTimingSummarySchema.extend({
  cache_hits: z.number().int().nonnegative().optional().default(0),
  prompt_tokens: z.number().int().nonnegative().nullish(),
  output_tokens: z.number().int().nonnegative().nullish(),
  thoughts_tokens: z.number().int().nonnegative().nullish(),
  total_tokens: z.number().int().nonnegative().nullish(),
  finish_reasons: z.record(z.string(), z.number().int().nonnegative()).optional().default({}),
}).passthrough();

export const AuditPhaseTimingsSchema = z
  .object({
    docs_total: z.number().int().nonnegative().optional().default(0),
    cache_hit_rate: z.number().nonnegative().nullish(),
    download: AuditTimingSummarySchema.nullish(),
    gemini: AuditTimingSummarySchema.nullish(),
    gemini_extraction: AuditGeminiTimingSummarySchema.nullish(),
    gemini_semantic: AuditGeminiTimingSummarySchema.nullish(),
    gemini_total: AuditGeminiTimingSummarySchema.nullish(),
    semantic_calls: z.number().int().nonnegative().nullish(),
    semantic_cache_hits: z.number().int().nonnegative().nullish(),
    extraction: AuditTimingSummarySchema.nullish(),
    normalization: AuditTimingSummarySchema.nullish(),
    policy: AuditTimingSummarySchema.nullish(),
  })
  .passthrough();

export const AuditSingleResponseSchema = z.object({
  audit_id: z.string().nullish(),
  status: z.union([AuditDocumentStatusSchema, z.literal("pending")]),
  dis_det_nro: z.string().nullish(),
  findings: z.array(AuditFindingSchema).default([]),
  severity: AuditSeveritySchema.nullish(),
  message: z.string().nullish(),
  documents: z.array(UnknownRecordSchema).nullish(),
  metrics: z.record(z.string(), z.union([z.string(), z.number()])).nullish(),
  policy: z.object({ policyKey: z.string().nullish() }).passthrough().nullable().optional(),
  _meta: z
    .object({
      totalTimeMs: z.number().nullish(),
      attempts: z.number().nullish(),
      acceptedAttempt: z.number().nullish(),
      promptHash: z.string().nullish(),
      acceptedPromptHash: z.string().nullish(),
      phases: z.record(z.string(), z.number()).nullish(),
    })
    .passthrough()
    .nullish(),
}).passthrough();

export const AuditJobSchema = z.object({
  job_id: z.string(),
  status: z.string(),
  total: z.number().int().nonnegative().optional().default(0),
  done: z.number().int().nonnegative().optional().default(0),
  failed: z.number().int().nonnegative().optional().default(0),
  pending: z.number().int().nonnegative().optional().default(0),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
}).passthrough().transform((val) => {
  const processed = (val.done || 0) + (val.failed || 0);
  const total = val.total || 0;
  const progress = total > 0 ? Math.round((processed / total) * 100) : 0;
  
  let status: "queued" | "running" | "completed" | "failed" = "queued";
  if (val.status === "processing") status = "running";
  else if (val.status === "completed" || val.status === "completed_with_errors") status = "completed";
  else if (val.status === "failed") status = "failed";

  return {
    jobId: val.job_id,
    status,
    queueDepth: val.pending || 0,
    progress,
    processed,
    total,
    createdAt: val.created_at || null,
    startedAt: val.created_at || null,
    completedAt: (status === "completed" || status === "failed") ? (val.updated_at || null) : null,
    result: {
      succeeded: val.done || 0,
      failed: val.failed || 0,
      skipped: 0,
    },
    error: null,
    statusUrl: `/audit/jobs/${val.job_id}`,
  };
});

export const AuditResultRecordSchema = z.object({
  FacSec: ScalarSchema,
  FacNro: z.string().nullish(),
  FacNitSec: ScalarSchema.nullish(),
  EstAud: z.number().int().nullish(),
  EstadoDetallado: z.string().nullish(),
  RequiereRevisionHumana: z.number().int().nullish(),
  Severidad: z.string().nullish(),
  DetalleError: z.string().nullish(),
  DocumentosProcesados: z.number().int().nonnegative().optional().default(0),
  DocumentoFallido: z.string().nullish(),
  DuracionProcesamientoMs: z.number().int().nonnegative().optional().default(0),
  metrics: z.record(z.string(), z.number()).optional().default({}),
  findingsCount: z.number().int().nonnegative().optional().default(0),
  failedFindingsCount: z.number().int().nonnegative().optional().default(0),
  inconclusiveFindingsCount: z.number().int().nonnegative().optional().default(0),
  auditExecuted: z.boolean().optional().default(false),
  FechaCreacion: z.string().nullish(),
  FechaActualizacion: z.string().nullish(),
}).passthrough();

export const AuditDocumentDecisionSchema = z.object({
  documentName: z.string(),
  approved: z.boolean(),
  observation: z.string().nullable().optional(),
}).passthrough();

export const AuditResultDetailSchema = AuditResultRecordSchema.extend({
  findings: z.array(AuditFindingSchema).optional().default([]),
  fieldDecisions: z.array(AuditFindingSchema).optional().default([]),
  documentDecisions: z.array(AuditDocumentDecisionSchema).optional().default([]),
  timings: AuditPhaseTimingsSchema.nullish(),
});

const PaginationFiltersSchema = z
  .union([
    z.record(z.string(), z.union([z.string(), z.number()])),
    z.array(UnknownRecordSchema),
  ])
  .nullish()
  .transform((filters) => (filters && !Array.isArray(filters) ? filters : null));

export const PaginatedAuditResultsSchema = z.object({
  items: z.array(AuditResultRecordSchema),
  total: z.number(),
  page: z.number(),
  pageSize: z.number(),
  totalPages: z.number(),
  filters: PaginationFiltersSchema,
});

export const AuditStatsSchema = z.object({
  total: z.number(),
  byState: z.record(z.string(), z.number()),
  documentsAudited: z.number(),
  lastAuditAt: z.string().nullable().optional(),
});

export const AuditDocumentHistoryItemSchema = UnknownRecordSchema;
export const PaginatedAuditDocumentHistorySchema = z.object({
  items: z.array(AuditDocumentHistoryItemSchema),
  total: z.number(),
  page: z.number(),
  pageSize: z.number(),
  totalPages: z.number(),
  filters: PaginationFiltersSchema,
});

export type PublicConfig = z.infer<typeof PublicConfigSchema>;
export type HealthStatus = z.infer<typeof HealthSchema>;
export type AsyncMetrics = z.infer<typeof AsyncMetricsSchema>;
export type ClientRecord = z.infer<typeof ClientSchema>;
export type ClientDocument = z.infer<typeof ClientDocumentSchema>;
export type InvoiceRecord = z.infer<typeof InvoiceSchema>;
export type DispensationHeader = z.infer<typeof DispensationHeaderSchema>;
export type DispensationItem = z.infer<typeof DispensationItemSchema>;
export type DispensationDetail = z.infer<typeof DispensationDetailSchema>;
export type AttachmentRecord = z.infer<typeof AttachmentSchema>;
export type AttachmentPreview = z.infer<typeof AttachmentPreviewSchema>;
export type AuditFinding = z.infer<typeof AuditFindingSchema>;
export type AuditTimingSummary = z.infer<typeof AuditTimingSummarySchema>;
export type AuditGeminiTimingSummary = z.infer<typeof AuditGeminiTimingSummarySchema>;
export type AuditPhaseTimings = z.infer<typeof AuditPhaseTimingsSchema>;
export type AuditSingleResponse = z.infer<typeof AuditSingleResponseSchema>;
export type AuditJob = z.infer<typeof AuditJobSchema>;
export type AuditResultRecord = z.infer<typeof AuditResultRecordSchema>;
export type AuditResultDetail = z.infer<typeof AuditResultDetailSchema>;
export type AuditDocumentDecision = z.infer<typeof AuditDocumentDecisionSchema>;
export type PaginatedAuditResults = z.infer<typeof PaginatedAuditResultsSchema>;
export type PaginatedAuditDocumentHistory = z.infer<
  typeof PaginatedAuditDocumentHistorySchema
>;
export type AuditConfigField = z.infer<typeof AuditConfigFieldSchema>;
export type AuditConfigDocument = z.infer<typeof AuditConfigDocumentSchema>;
export type AuditConfig = z.infer<typeof AuditConfigSchema>;
export type SaveAuditConfigResponse = z.infer<typeof SaveAuditConfigResponseSchema>;
export type AuditStats = z.infer<typeof AuditStatsSchema>;
