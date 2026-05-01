## [2026-05-01] — Limpieza Dead Code y Wrappers Redundantes (Pipeline)

### 🧹 Cleanup / Refactor
- **QUAL-001**: Eliminado `TYPE_EXTRACTION_FAILED` — constante declarada y ruteada sin productor ni consumer en todo el codebase.
  - Archivos modificados: `app/Services/Audit/Pipeline/AuditEvent.php`, `app/Services/Audit/Pipeline/AuditEventPublisher.php`
- **QUAL-002**: Limpieza de referencias fantasma a clases eliminadas en refactors AUDIT-013/014.
  - `FieldClassifier` eliminado de `AuditSeverity.php` (docblock) y SKILL.md (tabla de archivos)
  - `DocumentNormalizationWorker` → `DocumentNormalizer` en AGENTS.md y SKILL.md
  - `AuditResultAggregator` → `AuditAggregationWorker` en AGENTS.md y SKILL.md
  - `InternalAuditApiClient` → `AuditDataService` + `AttachmentDownloadService` en `plans/architecture.md` y SKILL.md
  - `extraction_failed` eliminado de tabla de streams en SKILL.md
  - `FieldClassifier` agregado al banner de obsolescencia de `audit-workflow.md`
- **Wrappers eliminados en `DocumentPolicyEngine`**:
  - `shouldSkipByCondition()` — wrapper de 1 línea que delegaba a `AuditFindingRules::shouldSkipByCondition()`. Docblock migrado al método fuente de verdad.
  - `normalizeVisualSeverity()` — duplicaba `AuditSeverity::fromInput()->value`. Reemplazado por llamada directa al enum.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `app/Services/Audit/Pipeline/AuditFindingRules.php`
- **Deuda documentada (fuera de scope)**: `normalizeDocumentType()` en PolicyEngine (semántica `iconv` vs `strtr` requiere tests), parámetro muerto `$documentType` en `evaluateField()`.

## [2026-04-30]
### feat
- **Auditoría Gemini**: Soporta `VigenciaEntrega` como visual estructurado y calculable para validar oportunidad de entrega.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/DocumentNormalizer.php`, `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/Pipeline/AuditFindingRules.php`
  - Hallazgo resuelto: ninguno
  - Impacto: permite que un visual configurado en cualquier documento aporte `valor`, `unidad` y `fecha_base`; PHP calcula si `FechaEntrega` está dentro de la vigencia sin depender del nombre del documento.

### fix
- **Frontend resultados de auditoría**: Corrige la alineación responsive de filtros, tabla y modal en `/audit/results`.
  - Archivos modificados: `frontend/app/globals.css`, `frontend/app/(dashboard)/layout.tsx`, `frontend/components/shared/section-card.tsx`, `frontend/components/audit/client-selector-combo.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/results/audit-results-table.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: elimina overflow horizontal móvil, reemplaza el scroll horizontal de la tabla por vista móvil en tarjetas, permite truncar clientes largos y renombra el conteo de `Hallazgos` a `Campos`/`Incidencias` según su significado real.

## [2026-04-29]

### fix
- **Auditoría Gemini**: Aísla perfiles Gemini por tarea y endurece la homologación semántica del Golden Case.
  - Archivos modificados: `app/Services/Audit/GeminiConfig.php`, `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/SemanticMatchJudge.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`
  - Hallazgo resuelto: regresión Golden Case `T38250701547` — falso `COINCIDE` en `NombreArticulo` de `AUTORIZACION`
  - Impacto: `mediaResolution` queda limitado a extracción multimodal, `semantic_match` opera text-only con evidencia estructurada conservadora y la cache semántica usa namespace versionado para no reutilizar falsos positivos previos.

### refactor
- **Auditoría Gemini**: Reemplaza la extracción monolítica por `extraction_contract` con parallel function calling (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`).
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`, `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: separa responsabilidades de extracción por documento, evita compatibilidad con el contrato legacy y conserva el shape interno consumido por normalización y policy.

- **Configuración Gemini**: Alinea el perfil de extracción del golden case con `config.md` (`gemini-3-flash-preview`, `4096` output tokens, `MINIMAL` thinking, `MEDIA_RESOLUTION_HIGH`) e invalida cache con `AUDIT_VERSION_EXTRACTOR=gemini-3.x-parallel-fc-v1`.
  - Archivos modificados: `.env.example`, `app/Services/Audit/GeminiConfig.php`
  - Hallazgo resuelto: ninguno
  - Impacto: asegura que la corrida E2E use el contrato nuevo, que la resolución multimedia configurada llegue al payload Gemini y que `responseMimeType` no se envíe junto con forced function calling.

### fix
- **Auditoría Gemini**: Rechaza extracciones truncadas o sin candidato válido antes de cachear o publicar `document_extracted`.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Debug/GeminiCallMetrics.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`
  - Hallazgo resuelto: desviación Google 2026 sobre validación de `finishReason`
  - Impacto: evita persistir auditorías incompletas cuando Gemini termina con `MAX_TOKENS` y agrega `finish_reason` a métricas diagnósticas.

- **Auditoría Gemini**: Agrega conteo `finish_reasons` a los resúmenes agregados de métricas Gemini.
  - Archivos modificados: `app/Services/Audit/Debug/GeminiCallMetrics.php`, `tests/Services/Audit/Debug/GeminiCallMetricsTest.php`
  - Hallazgo resuelto: ninguno
  - Impacto: permite auditar en resultados agregados cuántas llamadas terminaron en `STOP`, `MAX_TOKENS` u otra razón sin exponer payload documental.

## [2026-04-28]

### fix
- **Auditoría Gemini**: Corrige `MALFORMED_FUNCTION_CALL` + `400 Bad Request` en homologación semántica por configuración de thinking inválida en Gemini 3.1.
  - Archivos modificados: `app/Services/Audit/SemanticMatchJudge.php`, `.env.example`, `AGENTS.md`
  - Hallazgo resuelto: snapshot `T38250701547_success_20260428_221101870515_10eaf6bb.json` — `finishMessage: "Malformed function call: call:default_api:report"` (tokens agotados con thinking activo) + `400` al intentar `thinkingLevel=none` (valor inválido en Gemini 3.1)
  - Impacto: se omite `thinkingConfig` y se amplía `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS=2048` para absorber thinking interno por defecto del modelo.

### feat
- **Auditoría Gemini**: Agrega perfiles de generación por tarea para extracción documental y homologación semántica.
  - Archivos modificados: `app/Services/Audit/GeminiConfig.php`, `app/Services/Audit/SemanticMatchJudge.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `.env.example`, `AGENTS.md`
  - Hallazgo resuelto: ninguno
  - Impacto: permite reducir latencia y tokens con límites de salida y configuración de thinking específica por etapa.

### fix
- **Auditoría Gemini**: Corrige perfil semántico incompatible con Gemini 3 y evita persistir errores técnicos como detalle funcional.
  - Archivos modificados: `app/Services/Audit/GeminiConfig.php`, `app/Services/Audit/SemanticMatchJudge.php`, `.env.example`, `AGENTS.md`
  - Hallazgo resuelto: Golden Case runtime 2026-04-28
  - Impacto: elimina `thinkingBudget=0` inválido para Gemini 3 Pro y mantiene observaciones de auditoría limpias ante fallos del proveedor.

- **Auditoría Gemini**: Agrega métricas diagnósticas separadas para extracción documental y homologación semántica.
  - Archivos modificados: `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/SemanticMatchJudge.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: mejora la observabilidad de latencia y consumo de tokens sin alterar la decisión de auditoría.
