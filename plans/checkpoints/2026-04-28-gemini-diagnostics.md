# Checkpoint: Gemini Diagnostics

Fecha: 2026-04-28
Estado Kanban: Ready -> In Dev

## Alcance aprobado

Instrumentar el pipeline de auditoria Gemini para separar metricas de extraccion documental y homologacion semantica sin cambiar la logica de decision.

## Golden Case

Comando:

```powershell
curl.exe "http://localhost:8080/audit/results?facNro=T38250701547&page=1&pageSize=1"
```

Baseline esperado:

- `EstadoDetallado=manual_review`
- `DocumentosProcesados=3`
- `coincidencias=34`
- `discrepancias=1`
- `no_concluyentes=1`
- `DocumentoFallido=AUTORIZACION`

## Cambios previos detectados

- `app/Services/Audit/Debug/ResponseIADiskStore.php` tiene cambios existentes no generados por esta tarea.
- `plans/data-flows.md` tiene cambios existentes no generados por esta tarea.

Estos cambios no se revertiran ni se pisaran.

## Archivos objetivo

- `app/Services/Audit/Debug/GeminiCallMetrics.php`
- `tests/Services/Audit/Debug/GeminiCallMetricsTest.php`
- `app/Services/Audit/GeminiGateway.php`
- `app/Services/Audit/SemanticMatchJudge.php`
- `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`
- `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`
- `app/Services/Audit/Pipeline/AuditAggregationWorker.php`
- `.env.example`
- `CHANGELOG.md`
