## [2026-04-29]

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
