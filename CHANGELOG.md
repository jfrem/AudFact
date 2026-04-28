## [2026-04-28]

### feat
- **Auditoría Gemini**: Agrega métricas diagnósticas separadas para extracción documental y homologación semántica.
  - Archivos modificados: `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/SemanticMatchJudge.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: mejora la observabilidad de latencia y consumo de tokens sin alterar la decisión de auditoría.
