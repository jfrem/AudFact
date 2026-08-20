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
const PaginationFiltersSchema = z
  .union([
    z.record(z.string(), z.union([z.string(), z.number()])),
    z.array(UnknownRecordSchema),
  ])
  .nullish()
  .transform((filters) =>
    filters && !Array.isArray(filters) ? filters : null,
  );

export const PublicConfigSchema = z.object({
  auditBatchMaxLimit: z.number(),
  auditBatchTimeoutMs: z.number(),
});

export const HealthSchema = z.object({
  status: z.string(),
  timestamp: z.number().optional(),
  request_duration_ms: z.number().optional(),
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
      database_read: z
        .object({
          status: z.string(),
          message: z.string().optional(),
          latency_ms: z.number().optional(),
        })
        .passthrough()
        .optional(),
      redis: z
        .object({
          status: z.string(),
          message: z.string().optional(),
          latency_ms: z.number().optional(),
        })
        .passthrough()
        .optional(),
      disk: z.object({ status: z.string() }).passthrough(),
      memory: z.object({ status: z.string() }).passthrough(),
    })
    .passthrough(),
});

export const AsyncMetricsSchema = z.object({
  queueDepth: z.number().int().nonnegative(),
  streamDepths: z
    .object({
      inbox: z.number().int().nonnegative(),
      documents: z.number().int().nonnegative(),
      persistence: z.number().int().nonnegative(),
      results: z.number().int().nonnegative(),
      batchInbox: z.number().int().nonnegative(),
    })
    .optional(),
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
export const ClientDocumentSchema = z
  .object({
    NitSec: ScalarSchema,
    NitMedDocId: ScalarSchema,
    NitMedDocCodAlt: z.string().nullable().optional(),
    NitMedDocNom: z.string(),
  })
  .passthrough();
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
  codigoCampo: z.string().nullable().optional(),
});

export const AuditVisualCheckSchema = z.object({
  check: z.string(),
  description: z.string().nullable().optional(),
  severity: z.string().nullable().optional(),
  enabled: z.boolean().default(true),
  orden: z.number().default(0),
  codigoCampo: z.string().nullable().optional(),
});

export const FieldCatalogItemSchema = z.object({
  campoNombre: z.string(),
  codigoCampo: z.string(),
  tipoCampo: z.string(),
  tipoDato: z.string().nullable(),
  descripcion: z.string().nullable(),
  severidad: z.string(),
  esVisual: z.boolean(),
});
export type FieldCatalogItem = z.infer<typeof FieldCatalogItemSchema>;
export const FieldCatalogSchema = z.array(FieldCatalogItemSchema);

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

export const SaveAuditConfigResponseSchema = z
  .object({
    success: z.boolean().optional(),
  })
  .passthrough();

export const InvoiceSchema = UnknownRecordSchema;
export const PaginatedInvoicesSchema = z.object({
  items: z.array(InvoiceSchema),
  total: z.number(),
  page: z.number(),
  pageSize: z.number(),
  totalPages: z.number(),
  filters: PaginationFiltersSchema,
});

export const DispensationHeaderSchema = UnknownRecordSchema;
export const DispensationItemSchema = UnknownRecordSchema;
export const DispensationDetailSchema = z.object({
  header: DispensationHeaderSchema,
  items: z.array(DispensationItemSchema),
});

export const AttachmentSchema = z
  .object({
    dispensacion_id: ScalarSchema.optional(),
    dis_det_nro: z.string().optional(),
    cliente: ScalarSchema.optional(),
    id_adjunto_fisico: ScalarSchema.optional(),
    id_documento: ScalarSchema.optional(),
    nombre_documento: z.string().optional(),
    nombre_alternativo: z.string().optional(),
    almacenamiento_remoto: z.string().nullable().optional(),
    TipoAlmacenamiento: z.string().optional(),
  })
  .passthrough();

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
  "RECHAZADO",
]);
export const AuditSeveritySchema = z.string();

const FindingValueSchema = z
  .union([z.string(), z.number(), z.boolean()])
  .nullish()
  .transform((value) => (value == null ? null : String(value)));

export const AuditFindingSchema = z
  .object({
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
  })
  .passthrough();

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
  finish_reasons: z
    .record(z.string(), z.number().int().nonnegative())
    .optional()
    .default({}),
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
    processing_duration_ms: z.number().int().nonnegative().nullish(),
    queue_wait_ms: z.number().int().nonnegative().nullish(),
    total_elapsed_ms: z.number().int().nonnegative().nullish(),
  })
  .passthrough();

export const AuditSingleResponseSchema = z
  .object({
    audit_id: z.string().nullish(),
    status: z.union([
      AuditDocumentStatusSchema,
      z.literal("pending"),
      z.literal("completed"),
    ]),
    dis_det_nro: z.string().nullish(),
    dis_id: z.string().nullish(),
    findings: z.array(AuditFindingSchema).default([]),
    severity: AuditSeveritySchema.nullish(),
    message: z.string().nullish(),
    documents: z.array(UnknownRecordSchema).nullish(),
    metrics: z.record(z.string(), z.union([z.string(), z.number()])).nullish(),
    policy: z
      .object({ policyKey: z.string().nullish() })
      .passthrough()
      .nullable()
      .optional(),
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
  })
  .passthrough();

export const AuditJobSchema = z
  .object({
    job_id: z.string(),
    status: z.string(),
    total: z.number().int().nonnegative().optional().default(0),
    done: z.number().int().nonnegative().optional().default(0),
    failed: z.number().int().nonnegative().optional().default(0),
    pending: z.number().int().nonnegative().optional().default(0),
    avg_duration_ms: z.number().int().nonnegative().optional().default(0),
    accumulated_duration_ms: z
      .number()
      .int()
      .nonnegative()
      .optional()
      .default(0),
    throughput_per_sec: z.number().nonnegative().optional().default(0),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
    audits: z
      .array(
        z
          .object({
            audit_id: z.string(),
            status: z.string(),
            dis_det_nro: z.string().nullable().optional(),
            failed_stage: z.string().nullable().optional(),
          })
          .passthrough(),
      )
      .default([]),
  })
  .passthrough()
  .transform((val) => {
    const processed = (val.done || 0) + (val.failed || 0);
    const total = val.total || 0;
    const progress = total > 0 ? Math.round((processed / total) * 100) : 0;

    let status:
      | "queued"
      | "running"
      | "completed"
      | "completed_with_errors"
      | "failed" = "queued";
    if (val.status === "processing") status = "running";
    else if (val.status === "completed") status = "completed";
    else if (val.status === "completed_with_errors")
      status = "completed_with_errors";
    else if (val.status === "failed") status = "failed";

    let succeeded = val.done || 0;
    let skipped = 0;
    if (val.audits.length > 0) {
      succeeded = 0;
      for (const a of val.audits) {
        if (a.status === "completed") succeeded++;
        else if (a.status === "manual_review") skipped++;
      }
    }

    return {
      jobId: val.job_id,
      status,
      queueDepth: val.pending || 0,
      progress,
      processed,
      total,
      createdAt: val.created_at || null,
      startedAt: val.created_at || null,
      completedAt:
        status === "completed" ||
        status === "completed_with_errors" ||
        status === "failed"
          ? val.updated_at || null
          : null,
      result: {
        succeeded,
        failed: val.failed || 0,
        skipped,
      },
      error: null,
      statusUrl: `/audit/jobs/${val.job_id}`,
      performance: {
        avgDurationMs: val.avg_duration_ms || 0,
        accumulatedDurationMs: val.accumulated_duration_ms || 0,
        throughputPerSec: val.throughput_per_sec || 0,
      },
      audits: val.audits,
    };
  });

export const AuditJobSummarySchema = z
  .object({
    job_id: z.string(),
    fac_nit_sec: z.number().int().nonnegative().optional().default(0),
    client_name: z.string().optional().default("Sin cliente"),
    status: z.string(),
    total: z.number().int().nonnegative().optional().default(0),
    done: z.number().int().nonnegative().optional().default(0),
    failed: z.number().int().nonnegative().optional().default(0),
    pending: z.number().int().nonnegative().optional().default(0),
    progress_percent: z.number().int().nonnegative().optional().default(0),
    avg_duration_ms: z.number().int().nonnegative().optional().default(0),
    accumulated_duration_ms: z
      .number()
      .int()
      .nonnegative()
      .optional()
      .default(0),
    throughput_per_sec: z.number().nonnegative().optional().default(0),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
    date_from: z.string().nullable().optional(),
    date_to: z.string().nullable().optional(),
  })
  .passthrough();

export type AuditJobSummary = z.infer<typeof AuditJobSummarySchema>;
export const AuditJobsListSchema = z.array(AuditJobSummarySchema);

export const AuditResultRecordSchema = z
  .object({
    DisId: ScalarSchema,
    FacNro: z.string().nullish(),
    FacNitSec: ScalarSchema.nullish(),
    EstAud: z.number().int().nullish(),
    EstadoDetallado: z.string().nullish(),
    RequiereRevisionHumana: z.number().int().nullish(),
    Severidad: z.string().nullish(),
    DetalleError: z.string().nullish(),
    DocumentosProcesados: z.number().int().nonnegative().optional().default(0),
    DocumentoFallido: z.string().nullish(),
    DuracionProcesamientoMs: z
      .number()
      .int()
      .nonnegative()
      .optional()
      .default(0),
    metrics: z.record(z.string(), z.number()).optional().default({}),
    findingsCount: z.number().int().nonnegative().optional().default(0),
    failedFindingsCount: z.number().int().nonnegative().optional().default(0),
    inconclusiveFindingsCount: z
      .number()
      .int()
      .nonnegative()
      .optional()
      .default(0),
    auditExecuted: z.boolean().optional().default(false),
    FechaCreacion: z.string().nullish(),
    FechaActualizacion: z.string().nullish(),
  })
  .passthrough();

export const AuditDocumentDecisionSchema = z
  .object({
    documentName: z.string(),
    approved: z.boolean(),
    observation: z.string().nullable().optional(),
    doc_id: z.string().nullable().optional(),
    attachment_id: z.string().nullable().optional(),
    rejection_class: z.string().nullable().optional(),
    rejection_category: z.string().nullable().optional(),
    rejection_reason: z.string().nullable().optional(),
    candidate_attachment_ids: z.array(z.string()).optional(),
  })
  .passthrough();

export const AuditResultDetailSchema = AuditResultRecordSchema.extend({
  findings: z.array(AuditFindingSchema).optional().default([]),
  fieldDecisions: z.array(AuditFindingSchema).optional().default([]),
  documentDecisions: z
    .array(AuditDocumentDecisionSchema)
    .optional()
    .default([]),
  timings: AuditPhaseTimingsSchema.nullish(),
});

export const PaginatedAuditResultsSchema = z.object({
  items: z.array(AuditResultRecordSchema),
  total: z.number(),
  page: z.number(),
  pageSize: z.number(),
  totalPages: z.number(),
  filters: PaginationFiltersSchema,
});

export const AuditLiveStatusSchema = z.object({
  audit_id: z.string(),
  status: z.string(),
  dis_det_nro: z.string().default(""),
  dis_id: z.string().default(""),
  docs_total: z.number().int().nonnegative().default(0),
  docs_done: z.number().int().nonnegative().default(0),
  docs_extracted: z.number().int().nonnegative().default(0),
  docs_evaluated: z.number().int().nonnegative().default(0),
  is_terminal: z.boolean(),
  error_message: z.string().nullable().optional(),
  created_at: z.string().default(""),
  updated_at: z.string().default(""),
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

export const AuditMonthlyPerformanceItemSchema = z.object({
  mes: z.number().int().min(1).max(12),
  fac_nit_sec: z.number().int().nonnegative(),
  tercero: z.string(),
  aud_conf: z.number().int().nonnegative(),
  aud_rech: z.number().int().nonnegative(),
  total: z.number().int().nonnegative(),
  rate_conf: z.number().min(0).max(100),
  aud_conf_doc: z.number().int().nonnegative(),
  aud_rech_doc: z.number().int().nonnegative(),
  total_doc: z.number().int().nonnegative(),
});

export const AuditMonthlyPerformanceSummarySchema = z.object({
  total_facturas: z.number().int().nonnegative(),
  total_conformes: z.number().int().nonnegative(),
  total_rechazadas: z.number().int().nonnegative(),
  global_rate_conf: z.number().min(0).max(100),
  total_documentos: z.number().int().nonnegative(),
  total_doc_conformes: z.number().int().nonnegative().optional().default(0),
  total_doc_rechazados: z.number().int().nonnegative().optional().default(0),
});

export const AuditMonthlyPerformanceDataSchema = z.object({
  year: z.number().int(),
  summary: AuditMonthlyPerformanceSummarySchema,
  items: z.array(AuditMonthlyPerformanceItemSchema),
});

export type PublicConfig = z.infer<typeof PublicConfigSchema>;
export type HealthStatus = z.infer<typeof HealthSchema>;
export type AsyncMetrics = z.infer<typeof AsyncMetricsSchema>;
export type ClientRecord = z.infer<typeof ClientSchema>;
export type ClientDocument = z.infer<typeof ClientDocumentSchema>;
export type InvoiceRecord = z.infer<typeof InvoiceSchema>;
export type PaginatedInvoices = z.infer<typeof PaginatedInvoicesSchema>;
export type DispensationHeader = z.infer<typeof DispensationHeaderSchema>;
export type DispensationItem = z.infer<typeof DispensationItemSchema>;
export type DispensationDetail = z.infer<typeof DispensationDetailSchema>;
export type AttachmentRecord = z.infer<typeof AttachmentSchema>;
export type AttachmentPreview = z.infer<typeof AttachmentPreviewSchema>;
export type AuditFinding = z.infer<typeof AuditFindingSchema>;
export type AuditTimingSummary = z.infer<typeof AuditTimingSummarySchema>;
export type AuditGeminiTimingSummary = z.infer<
  typeof AuditGeminiTimingSummarySchema
>;
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
export type SaveAuditConfigResponse = z.infer<
  typeof SaveAuditConfigResponseSchema
>;
export type AuditLiveStatus = z.infer<typeof AuditLiveStatusSchema>;
export type AuditStats = z.infer<typeof AuditStatsSchema>;
export type AuditMonthlyPerformanceItem = z.infer<
  typeof AuditMonthlyPerformanceItemSchema
>;
export type AuditMonthlyPerformanceSummary = z.infer<
  typeof AuditMonthlyPerformanceSummarySchema
>;
export type AuditMonthlyPerformanceData = z.infer<
  typeof AuditMonthlyPerformanceDataSchema
>;
