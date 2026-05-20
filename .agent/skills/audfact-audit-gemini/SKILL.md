---
name: audfact-audit-gemini
description: Trabajar en el pipeline de auditoría IA event-driven de AudFact sobre Redis Streams. Usar cuando se modifique app/Services/Audit/Pipeline/*, bin/audit-*-worker.php, contratos de eventos (audit_created, document_registered, document_extracted, document_normalized, rules_evaluated, audit_completed, dead_letter), el contrato Gemini `extraction_contract` con parallel function calling o el manejo de DLQ.
---

# AudFact Audit Gemini (Event-Driven)

## Objetivo
Mantener confiable el pipeline event-driven de auditoría documental con Redis Streams, extracción Gemini por function calling, normalización y policy en PHP puro, y persistencia final en SQL Server.

## Archivos clave

### Servicios del pipeline event-driven

| Archivo | Rol |
|---|---|
| `app/Services/Audit/Pipeline/AuditEvent.php` | Value-object inmutable de evento (tipos, payload, UUID v4, timestamps ISO 8601) |
| `app/Services/Audit/Pipeline/AuditEventPublisher.php` | Publica a `audit.inbox`, `audit.documents`, `audit.results` y `audit.dlq` |
| `app/Services/Audit/Pipeline/AuditEventConsumer.php` | Base abstracta: `XREADGROUP`, ack, reintentos y envío a DLQ automático |
| `app/Services/Audit/Pipeline/AuditStateStore.php` | Claves Redis de estado (`audit:{id}:*`, `job:{id}:*`, contadores, FDV cache) |
| `app/Services/Audit/Pipeline/AuditDataService.php` / `AuditDataServiceInterface.php` | Acceso interno a FDV, audit-config y catálogo documental usado por workers (no consultas SQL directas) |
| `app/Services/Audit/Pipeline/AttachmentDownloadService.php` / `AttachmentDownloadServiceInterface.php` | Descarga del adjunto (Drive/BLOB) por audit-id + document-id |
| `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | Consume `audit_created`, resuelve FDV/config/adjuntos, construye `extraction_contract` desde `audit-config` y publica N `document_registered` |
| `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | Construye las cuatro function declarations Gemini (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`) y agrupa campos dinámicos por responsabilidad |
| `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | Consume `document_registered`, descarga adjunto, calcula `document_hash`, gestiona cache Redis por hash, invoca Gemini con parallel function calling y publica `document_extracted` |
| `app/Services/Audit/Pipeline/ExtractionState.php` | Enum tipado para el estado de la extracción (COMPLETED, FAILED, ILLEGIBLE) |
| `app/Services/Audit/Pipeline/ExtractedEvidence.php` | DTO tipado para representar de forma determinista la evidencia extraída y normalizada |
| `app/Services/Audit/AuditBatchOrchestrator.php` | Servicio que encapsula la orquestación asíncrona de lotes (reserva de slots Redis y rollback transaccional) |
| `app/Services/Audit/Pipeline/DocumentNormalizer.php` | Worker autocontenido: consume `document_extracted`, normaliza `fields` / `items` / `visual_checks` (fechas ISO, identidad documental, numéricos canónicos y evidencia visual estructurada) y publica `document_normalized` |
| `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | Motor determinista por documento: delega reglas complejas y orquesta COINCIDE / VALOR_DISTINTO / NO_ENCONTRADO / OMITIDO / NO_CONCLUYENTE |
| `app/Services/Audit/Pipeline/VisualCheckEvaluator.php` | Servicio delegado de `DocumentPolicyEngine` que evalúa evidencia visual y resuelve discrepancias de calidad documental. |
| `app/Services/Audit/Pipeline/FieldValueResolver.php` | Utilidad que extrae y normaliza el valor del documento (header vs items) resolviendo dependencias de normalización cruzada. |
| `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | Consume `document_normalized` y publica `rules_evaluated` cuando `docs:done == docs:total` |
| `app/Services/Audit/Pipeline/AuditAggregationWorker.php` | Consume `rules_evaluated`, **agrega a `auditResultData` + decisiones documentales**, persiste en SQL y publica eventos finales. Delega recolección de métricas. |
| `app/Services/Audit/Pipeline/AuditTimingSummarizer.php` | Agrega duraciones de las fases del pipeline y extrae los `phase_timings` para reporte. |
| `app/Services/Audit/AuditFindingRules.php` | Utilidad compartida para normalizar valores, sumar métricas y resolver severidad |
| `app/Services/Audit/Pipeline/BatchJobStore.php` | Claves Redis `job:{id}` para batches async (claim slot, registrar audits, marcar completado) |
| `app/Services/Audit/AuditComparisonType.php` | Enum `EXACT/SEMANTIC/BUSINESS/VISUAL` + `fromTipoCampo()` (mapea `E/S/B/V` desde BD) — métodos `isDateField/isQuantityField/isNumberField` son puentes `@deprecated` que delegan a `AuditFieldValueType` |
| `app/Services/Audit/AuditFieldValueType.php` | Enum strategy-based de `TipoDato` explícito en `audit-config`: `TEXT/DATE/QUANTITY/MONEY/IDENTITY_DOC_TYPE/IDENTITY_DOC_NUMBER/CODE/TRACE_TOKEN/PERSON_NAME/INSTITUTION_NAME/ARTICLE_NAME`. Métodos de comportamiento: `requiresSubsetComparison()` (CODE), `requiresTraceSetComparison()` (TRACE_TOKEN), `requiresTokenSortComparison()` (PERSON_NAME), `allowsMultiValueDocument()` (CODE, TRACE_TOKEN), `allowsSemanticGeminiFallback()` (solo ARTICLE_NAME). Prohibido inferir tipos por nombre del campo. |
| `app/Services/Audit/GeminiConfig.php` | Value Object de configuración Gemini, incluyendo overrides por tarea (`GEMINI_EXTRACTION_*`, `GEMINI_SEMANTIC_*`) y opt-in explícito de `mediaResolution` |
| `app/Services/Audit/GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout, function calling, perfiles explícitos (`extraction`, `semantic_match`) y métricas `X-Audit-Metrics` |
| `app/Services/Audit/ArticleSemanticMatchJudge.php` | Fallback semántico conservador para homologación de artículos; usa evidencia estructurada, cache versionada y no cachea fallos transitorios |
| `app/Services/Audit/GeminiCallMetrics.php` | Normaliza métricas Gemini por tarea: latencia, tokens de prompt/output/thinking/total y cache hits |
| `app/Services/Audit/ResponseIADiskStore.php` | Persiste snapshots de request/response Gemini en `responseIA/` para diagnóstico local |

### Workers bootstrap (largas ejecuciones)

Tras la consolidación AUDIT-015 (2026-04-27), existe **un único launcher** `bin/audit-worker.php` que recibe el nombre de worker como primer argumento CLI y selecciona el consumer mediante un registry interno:

| Comando | Stream consumido | Consumer group |
|---|---|---|
| `php bin/audit-worker.php orchestrator` | `audit.inbox` | `orchestrator` |
| `php bin/audit-worker.php extraction` | `audit.documents` | `extractors` |
| `php bin/audit-worker.php normalizer` | `audit.documents` | `normalizers` |
| `php bin/audit-worker.php policy` | `audit.documents` | `policy` |
| `php bin/audit-worker.php aggregator` | `audit.results` | `aggregator` |

El launcher carga `.env`, instancia el consumer correspondiente, registra SIGTERM/SIGINT para stop gracioso y llama `run()`; `pcntl_signal_dispatch` se procesa dentro del loop del consumer base. `docker-compose.yml` levanta los 5 servicios con este mismo binario y argumento distinto (extraction tiene 5 réplicas).

### Controllers y endpoints

| Archivo | Endpoints |
|---|---|
| `app/Controllers/AuditController.php` | `POST /audit/single` (202), `POST /audit/async` (202), `GET /audit/jobs/{jobId}` |
| `app/Controllers/AuditDlqController.php` | `GET /audit/dlq`, `POST /audit/dlq/reprocess` (listar y republicar `dead_letter`) |

## Streams y eventos

| Stream | Productor | Eventos |
|---|---|---|
| `audit.inbox` | `AuditController` | `audit_created`, `batch_created` |
| `audit.documents` | Orchestrator / Extractor / Normalizer | `document_registered`, `document_extracted`, `document_normalized` |
| `audit.results` | Policy / Aggregator | `rules_evaluated`, `audit_completed`, `audit_failed`, `batch_completed(_with_errors)` |
| `audit.dlq` | Cualquier worker | `dead_letter` (despliega payload original + etapa, attempts y last_error_*) |

## Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `GEMINI_API_KEY` | Credencial obligatoria para el extractor |
| `GEMINI_MODEL` | Modelo Gemini (por defecto `gemini-3-flash-preview`) |
| `GEMINI_TIMEOUT`, `GEMINI_MAX_OUTPUT_TOKENS`, `GEMINI_TEMPERATURE`, `GEMINI_TOP_P`, `GEMINI_TOP_K`, `GEMINI_SEED`, `GEMINI_MEDIA_RESOLUTION`, `GEMINI_THINKING_BUDGET`, `GEMINI_THINKING_LEVEL` | Configuración base de generación Gemini |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS`, `GEMINI_EXTRACTION_THINKING_LEVEL`, `GEMINI_EXTRACTION_THINKING_BUDGET` | Perfil de generación para extracción documental |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS`, `GEMINI_SEMANTIC_THINKING_LEVEL`, `GEMINI_SEMANTIC_THINKING_BUDGET` | Perfil de generación para homologación semántica; en Gemini 3.1 dejar `THINKING_LEVEL` vacío si se desea omitir `thinkingConfig` |
| `AUDIT_STREAM_BLOCK_MS` | Bloqueo `XREADGROUP` |
| `AUDIT_EVENT_MAX_RETRIES` | Reintentos por evento antes de DLQ |
| `AUDIT_DLQ_STREAM` | Stream DLQ (default `audit.dlq`) |
| `AUDIT_CACHE_TTL`, `AUDIT_EXTRACTION_CACHE_TTL` | TTL cache extracción Gemini |
| `AUDIT_FDV_TTL` | TTL de la FDV completa en Redis |
| `AUDIT_INTERNAL_API_BASE` | Base URL que los workers usan para la API interna (FDV/catalogos/adjuntos) |
| `AUDIT_VERSION_EXTRACTOR`, `AUDIT_VERSION_NORMALIZER`, `AUDIT_VERSION_RULES` | Versionado para trazabilidad en `AuditEvent` |

## Flujo técnico

1. `POST /audit/single` valida `DisDetNro` → publica `audit_created` en `audit.inbox` → retorna 202 con `audit_id`.
2. `DocumentAuditOrchestrator` consume `audit_created`, resuelve FDV (`/dispensation/{DisDetNro}`), valida el contrato de identidad (`payload.fac_sec` = `FDV.header.FacSec` cuando venga del batch), obtiene `audit-config` por `NitSec`, catálogo documental y adjuntos; publica N `document_registered` en orden ascendente por `docId`.
3. `DocumentExtractionWorker` descarga el adjunto por URL interna, calcula `document_hash = sha256(base64_data)`, consulta cache; si hay hit publica `document_extracted` con `cache_hit=true`; si no, invoca Gemini con perfil `extraction` y parallel function calling (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`) y combina las respuestas en `extraction_result`.
4. `DocumentNormalizer` normaliza `fields`/`items`/`visual_checks` (fechas ISO, identidad documental, numéricos canónicos, evidencia visual estructurada, null para vacío) y emite `document_normalized` con `normalization_log` sin PII cruda.
5. `RulesEvaluationWorker` evalúa policy por documento contra FDV, usa `ArticleSemanticMatchJudge` solo como fallback text-only de homologación de artículos (`TipoDato=article_name`) con perfil `semantic_match`, espera `docs:done == docs:total`, aplica visuales calculables como `VigenciaEntrega` a nivel agregado y publica `rules_evaluated` con hallazgos, métricas y `document_decisions`.
6. `AuditAggregationWorker` agrega a `auditResultData`, persiste en `AudDispEst` + `AdjuntosDispensacion` y publica `audit_completed` (o `audit_failed` si persistencia falla).
7. Fallos recuperables se reintentan hasta `AUDIT_EVENT_MAX_RETRIES`; al agotar, `AuditEventConsumer` genera `dead_letter` automáticamente.

## Reglas de implementación (estrictas)

1. **IA sólo extrae**: Gemini nunca toma decisiones de negocio finales; la comparación y aplicación de **severidades dinámicas** (CRITICO, ALTA, MEDIA, BAJA, INFO) viven en `DocumentPolicyEngine` según el `audit-config`.
2. **TipoCampo gobierna la comparación y TipoDato gobierna el valor** — fuente de verdad: columnas `TipoCampo` y `TipoDato` en `Discolnet.dbo.AudDispCampo`.
   - `TipoCampo=E` → `EXACT` (igualdad normalizada)
   - `TipoCampo=S` → `SEMANTIC` (umbral 0.82; Gemini solo si `TipoDato=article_name`)
   - `TipoCampo=B` → `BUSINESS` (sumatoria de items + comparación numérica; solo `TipoDato=quantity`)
   - `TipoCampo=V` → `VISUAL` (vive en `visualChecks[]`; no usa `TipoDato`)
   - `TipoDato` permitido: `text`, `date`, `quantity`, `money`, `identity_doc_type`, `identity_doc_number`, `code`, `trace_token`, `person_name`, `institution_name`, `article_name`.
   Prohibido inferir `TipoDato` por nombre del campo. `AuditFieldValueType::fromInput()` debe recibir metadata explícita del `audit-config`. La ubicación `extract_fields` vs `extract_items` la define `DocumentExtractionContractBuilder` con reglas explícitas de dominio para evitar mezclar cabecera y líneas.
3. **[AUDIT-016] Subset matching para `CODE`**: Campos con `AuditFieldValueType::CODE` usan `evaluateSubsetField()`. Si el FDV tiene un código (ej. `S202`) y el documento lista múltiples (ej. `S202, S273, F432`), se evalúa como `COINCIDE` con `tipo_auditoria=exact`. `tokenizeCodeField()` separa por coma, punto y coma o barra; normaliza a mayúsculas. El hallazgo incluye `valueType=code` y `valoresDocumento` (array de tokens).
4. **[TRACE_TOKEN] Set-based matching para Trazabilidad**: Campos como `Lote` usan `TRACE_TOKEN`. Aplica lógica matemática de conjuntos $FDV \subseteq Doc$. Si la FDV pide "Lote A", y el documento trae "Lote A" y "Lote B", es `COINCIDE`. Si el documento trae solo una parte de un FDV múltiple, es `NO_CONCLUYENTE` (evidencia parcial). Si trae un lote no registrado, es `VALOR_DISTINTO`.
4. **[AUDIT-016] Token-sort para `PERSON_NAME`**: Campos `PERSON_NAME` en modo semántico intentan token-sort antes de invocar Gemini. `GARCIA ABSALON` vs `ABSALON GARCIA` → `COINCIDE` con `tipo_auditoria=exact` sin gasto de API.
5. **[AUDIT-016] No data-loss en multi-item divergente (CAT-1)**: Si `resolveDocumentValue()` encuentra múltiples items con valores distintos en un campo no sumable, emite `NO_CONCLUYENTE` con `detalle: {ambiguous: true, valores: [...]}`. Ya no se descarta silenciosamente el campo.
6. **[AUDIT-016] Hallazgo canónico v1**: `buildDataFinding()` inyecta `valueType` y `valoresDocumento` (tokens del set `CODE`). Siempre presente en hallazgos de tipo `CODE`. Adaptador `resolveFieldScalar()` normaliza payloads legacy (string) y v1 (`{valor, valores}`).
7. **Items solo cuando existen filas segmentadas**: no derivar `items` desde `fields` y viceversa.
8. **Comparación determinista**: umbrales `persona 0.85`, `artículo 0.82`, `texto 0.90`; numéricos/IDs/fechas con igualdad normalizada.
9. **Cadena documental**: Fórmula → Autorización → Dispensa. El `audit-config` runtime no persiste `rol`; todo campo activo en `fields` se evalúa según `TipoCampo` y severidad.
10. **Entrega parcial** válida: `cantidad_entregada_total <= cantidad_autorizada` (o `cantidad_prescrita` si no hay autorización).
11. **Exclusiones documentales**: no documentar campos como "informativos" si el `audit-config` real no trae esa marca. Para omitir un campo de auditoría debe no estar activo en `fields`; `omitirSi` no está implementado en el runtime actual.
12. **Sin código legacy**: clean rebuild; no agregar shims ni compatibilidad con el pipeline monolítico anterior.
13. **XACK solo tras éxito**: acknowledge después de publicar el evento siguiente o persistir resultado final.
14. **Errores técnicos de Gemini no son detalle funcional**: loguear el error, devolver `NO_CONCLUYENTE` limpio y no cachear fallos transitorios del fallback semántico.
15. **Métricas Gemini por tarea**: preservar `gemini_extraction`, `gemini_semantic` y `gemini_total` en `phase_timings`, incluyendo respuestas malformadas cuando Gemini entregue `usageMetadata`.
16. **Perfiles Gemini aislados**: `mediaResolution` solo se permite en perfil `extraction`; `semantic_match` debe ser text-only, con cache semántica versionada y decisión PHP conservadora ante evidencia incompleta.
17. **Visuales calculables**: `VigenciaEntrega` no se cierra como booleano en `DocumentPolicyEngine`; Gemini extrae `valor`, `unidad` y `fecha_base`, `DocumentNormalizer` los canoniza y `RulesEvaluationWorker` calcula `FechaEntrega <= fecha_base + valor`. Si falta evidencia suficiente en un visual activo, el resultado agregado es `NO_CONCLUYENTE`.
18. **Identidad canónica E2E**: `FacSec` de auditoría debe cumplir `Factura.FacSec == vw_discolnet_dispensas.facsecF == AudDispEst.FacSec`. `DisDetNro`/`Dispensa` es la llave operativa de adjuntos y se persiste como `AudDispEst.FacNro`. Ver `plans/audit-identity-contract.md`.

## Omisiones de campos (runtime actual)

El runtime actual no lee ni persiste `omitirSi`. `AuditConfigModel::getConfig()` retorna `campoNombre`, `tipoCampo`, `tipoDato`, `orden`, `severity` y, para visuales, `description`. `AuditConfigController::sanitizeFields()` exige `tipoDato` para campos no visuales y acepta `tipoDato = null` solo para `TipoCampo = V`.

Implicación operativa:
- si un campo aparece activo en `fields`, `DocumentPolicyEngine` lo evalúa;
- si debe excluirse de auditoría, debe removerse del `audit-config`;
- `OMITIDO` puede aparecer solo por condiciones internas actuales del engine, por ejemplo ausencia simultánea de valor FDV y valor documental auditable, no por reglas condicionales configurables.

## Agregación de items en reglas `B`

Para `TipoCampo = B`, `DocumentPolicyEngine` **suma los items de la FDV** antes de comparar contra `valorDocumento`. Caso real `T38250701547` (NitSec 2426): la FDV tiene 2 items con `CantidadEntregada = 20` y `30`; el hallazgo persistido reporta `valorFuenteVerdad: "50"`, `valorDocumento: "50"`, `tipo_auditoria: "business"`, `resultado: COINCIDE`. Implicación: nunca documentar reglas `B` como "campo a campo" — son agregadas a nivel documento.

## Contrato real de hallazgo (v1 — AUDIT-016)

Forma canónica del objeto en `AudDispEst.Hallazgos[*]` (todos los campos obligatorios post-AUDIT-016):

```json
{
  "severidad":          "alta|media|baja",
  "campo":              "<nombre>",
  "documento":          "DISPENSA|AUTORIZACION|FORMULA MEDICA|...",
  "valorDocumento":     "<valor extraído por Gemini>",
  "valorFuenteVerdad":  "<valor de la FDV>",
  "resultado":          "COINCIDE|VALOR_DISTINTO|NO_ENCONTRADO|OMITIDO|NO_CONCLUYENTE",
  "detalle":            "<string|null>",
  "tipo_auditoria":     "exact|semantic|business|visual",
  "valueType":          "text|date|quantity|money|identity_doc_type|identity_doc_number|code|trace_token|person_name|institution_name|article_name",
  "valoresDocumento":   ["S202","S273","F432"]   // solo para CODE; null en otros tipos
}
```

> [!NOTE]
> `valueType` y `valoresDocumento` son campos v1 inyectados por `buildDataFinding()`. `valueType` debe salir del `TipoDato` explícito del `audit-config`, no del nombre del campo.

El contrato runtime actual no incluye `rol` en hallazgos. Visual checks booleanos emiten un objeto similar, sin `valueType`; los visuales calculables como `VigenciaEntrega` emiten `tipo_auditoria: "visual"` desde la agregación de reglas.

## Nota Gemini 3.x — thinking tokens

En Gemini 3.x los thinking tokens pueden superar **4×** los output tokens (caso `T38250701547`: 5 594 thinking vs 1 177 output en extracción). Considerar al ajustar `GEMINI_*_MAX_OUTPUT_TOKENS` y `GEMINI_*_THINKING_BUDGET` para no truncar respuestas válidas.

## Anti-patterns ⚠️

1. **No** consultar vistas SQL directamente desde workers para FDV/adjuntos — usar `AuditDataService` y `AttachmentDownloadService`.
2. **No** incluir base64, binarios o credenciales en el payload de eventos (solo en claves de estado Redis).
3. **No** fabricar `items` desde `fields` en normalizador o policy.
4. **No** borrar mensajes de streams; dejar ack/retry/DLQ hacer su trabajo.
5. **No** mezclar dos responsabilidades en un worker: cada etapa publica exactamente un evento siguiente.

## Ejemplos

### Validar contrato `POST /audit/single`
```bash
curl -X POST http://localhost:8080/audit/single \
  -H "Content-Type: application/json" \
  -d '{"DisDetNro":"T38250701547"}'
# Respuesta esperada: 202 { "data": { "audit_id": "...", "status": "pending", ... } }
```

### Golden case (validación humana obligatoria)
```powershell
Invoke-RestMethod -Uri "http://localhost:8080/audit/single" `
  -Method POST -ContentType "application/json" `
  -Body '{"DisDetNro":"T38250701547"}' | ConvertTo-Json -Depth 20
```
Resultado esperado: `EstadoDetallado: "manual_review"`, 34 coincidencias, 1 discrepancia (NO_ENCONTRADO `CodigoDiagnostico` en FORMULA MEDICA), 1 NO_CONCLUYENTE (`NombreArticulo` por homologación de artículo).

### Levantar los workers localmente
```bash
php bin/audit-worker.php orchestrator &
php bin/audit-worker.php extraction &
php bin/audit-worker.php normalizer &
php bin/audit-worker.php policy &
php bin/audit-worker.php aggregator &
```

### Listar DLQ
```bash
curl http://localhost:8080/audit/dlq?limit=20
```

## Checklist rápido

1. `POST /audit/single` responde 202 con `audit_id`.
2. `POST /audit/async` responde 202 con `job_id`.
3. Workers procesan el flujo completo para `T38250701547` (cliente 2426).
4. `rg "AuditQueueService|AuditOrchestrator|lpush|brpop"` sin coincidencias.
5. `audit.dlq` recibe `dead_letter` al agotar reintentos.
6. Persistencia final en `AudDispEst` + `AdjuntosDispensacion`.
7. PHPUnit pasa — suite completa 233 tests, 693 assertions, 10 skipped.
8. `php vendor/bin/phpunit tests/Services/Audit/GoldenSetReplayTest.php --no-coverage` → 3 tests, 34 assertions (Golden Set D65260408592).
9. Hallazgos de tipo `CODE` incluyen `valueType` y `valoresDocumento` en el contrato v1.
10. Multi-item divergente emite `NO_CONCLUYENTE` con `ambiguous=true`, no silencio.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

Después de cualquier cambio en el pipeline event-driven:

1. Verificar que los archivos listados en "Archivos clave" existan.
2. Confirmar que streams y consumer groups siguen alineados con `AuditEventPublisher` y `AuditEventConsumer`.
3. Ejecutar `audfact-docs-sync` como segunda capa.


> [!CAUTION]
> Dejar la skill desactualizada genera drift que confunde a agentes futuros.

## Referencias

- Sprint checkpoints: `plans/checkpoints/`
- Skill asociada para modelos SQL: `audfact-sqlsrv-models`
- Mapeo TipoCampo → tipo de comparación: `app/Services/Audit/AuditComparisonType.php`
- TipoDato explícito y reglas de compatibilidad TipoCampo/TipoDato: `app/Services/Audit/AuditFieldValueType.php`
- Casos de validación E2E: `plans/changelog.md` (entradas DOCS-SYNC y AUDIT-014/015)
