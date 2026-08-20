---
name: audfact-audit-gemini
description: Trabajar en el pipeline de auditoría IA event-driven de AudFact sobre Redis Streams. Usar cuando se modifique app/Services/Audit/Pipeline/*, bin/audit-worker.php, contratos de eventos (audit_created, batch_created, batch_requested, document_registered, document_downloaded, document_extracted, document_rejected, document_normalized, rules_evaluated, audit_completed, audit_failed, batch_completed, batch_completed_with_errors, dead_letter), el contrato Gemini `extraction_contract` con parallel function calling o el manejo de DLQ.
---

# AudFact Audit Gemini (Event-Driven)

## Objetivo
Mantener confiable el pipeline event-driven de auditoría documental con Redis Streams, extracción Gemini por function calling, normalización y policy en PHP puro, y persistencia final en SQL Server.

## Archivos clave

### Servicios del pipeline event-driven

| Archivo | Rol |
|---|---|
| `app/Services/Audit/Pipeline/AuditEvent.php` | Value-object inmutable de evento (tipos, payload, UUID v4, timestamps ISO 8601) |
| `app/Services/Audit/Pipeline/AuditEventPublisher.php` | Publica a streams duales `.priority` y `.batch` (`audit.inbox.*`, `audit.documents.*`, `audit.results.*`, `audit.dlq`); enrutamiento automático de prioridad para auditorías interactivas 1:1; `rules_evaluated` debe pasar exclusivamente por `AuditPersistenceQueue` |
| `app/Services/Audit/Pipeline/AuditEventConsumer.php` | Base abstracta multi-stream: consume prioritariamente con `xReadGroupMulti` (`.priority` antes de `.batch`), ack, reintentos y DLQ; SQL agotado y descarga técnica son terminales en la misma entrega |
| `app/Services/Audit/Pipeline/AuditStateStore.php` | Claves Redis de estado (`audit:{id}:*`, `job:{id}:*`, contadores, FDV cache) |
| `app/Services/Audit/Pipeline/AuditDataService.php` | Facade concreta de acceso interno a FDV, audit-config y catálogo documental usado por workers (no consultas SQL directas) |
| `app/Services/Audit/Pipeline/AttachmentDownloadService.php` | Descarga Drive/BLOB, valida bytes esperados y clasifica fallos técnicos |
| `app/Services/Audit/Pipeline/AttachmentDownloadException.php` | Taxonomía tipada de fuente ausente/vacía, transferencia incompleta y fallo externo |
| `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php` | Consume `document_registered`, guarda el BLOB en Redis y publica solo `document_downloaded`; propaga fallos técnicos |
| `app/Services/Audit/Pipeline/DocumentRejectionReason.php` | Allowlist cerrada para rechazos comprobados de contenido |
| `app/Services/Audit/Pipeline/DocumentMappingRejectionReason.php` | Categoría y allowlist cerrada para rechazos de asociación lógica/física |
| `app/Services/Audit/Pipeline/DocumentAttachmentMatcher.php` | Matcher puro, determinista y global 1:1 por nombre, ID corroborado y alias único |
| `app/Services/Audit/Pipeline/DocumentAttachmentMatchResult.php` | DTO readonly que impide IDs lógicos o físicos duplicados en matches |
| `app/Services/Audit/Pipeline/DocumentIntegrityValidator.php` | Validación preventiva de integridad documental de adjuntos vacíos, corruptos o con MIME inconsistente antes de Gemini |
| `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | Consume `audit_created`, reconcilia todos los adjuntos físicos 1:1, publica matches y emite rechazos `DOCUMENT_MAPPING` controlados |
| `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php` | Construye function declarations Gemini dinámicas: `extract_fields`, `extract_items` y `detect_visual_checks` solo cuando aplican; `assess_document_quality` siempre |
| `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | Orquestador delgado que consume `document_downloaded`, delega estado a Redis, genera prompts y parsea respuestas. Produce rechazos `document_content`. |
| `app/Services/Audit/Pipeline/ExtractionCacheManager.php` | Administra estado transitorio y cache de extracción Gemini en Redis mediante `HSET`/`HGET`. |
| `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | Construye payloads modulares JSON Schema y prompts de contexto para inyección a Gemini. |
| `app/Services/Audit/Pipeline/GeminiResponseParser.php` | Implementa política de recuperación en 3 fases (Primary, Retry JSON-repair, Fallback Regex) para respuestas LLM truncadas. |
| `app/Services/Audit/Pipeline/ExtractionState.php` | Enum tipado para el estado de extracción de campos (`FOUND`, `FOUND_IN_LIST`, `NOT_FOUND`, `ILLEGIBLE`) |
| `app/Services/Audit/Pipeline/ExtractedEvidence.php` | DTO tipado para representar de forma determinista la evidencia extraída y normalizada |
| `app/Services/Audit/AuditBatchOrchestrator.php` | Servicio que encapsula la orquestación asíncrona de lotes (reserva de slots Redis y rollback transaccional) |
| `app/Services/Audit/Pipeline/BatchRequestedWorker.php` | Worker que consume `batch_requested` de `audit.batch.inbox`, realiza consultas pesadas en SQL Server y reserva idempotencia por `DisId` en Redis |
| `bin/schedule-daily-batches.php` | CLI Cron: encola auditorías batch diarias emitiendo `batch_requested` para todos los clientes configurados, validando campos activos e idempotencia por cliente. Límite configurable vía `AUDIT_BATCH_CRON_LIMIT` (default: 5000) o `--limit` CLI. |
| `app/Services/Audit/Pipeline/DocumentNormalizer.php` | Worker autocontenido: consume `document_extracted`, normaliza `fields` / `items` / `visual_checks` (fechas ISO, identidad documental, numéricos canónicos y evidencia visual estructurada) y publica `document_normalized` |
| `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | Motor determinista por documento: delega reglas complejas y orquesta COINCIDE / VALOR_DISTINTO / NO_ENCONTRADO / OMITIDO / NO_CONCLUYENTE |
| `app/Services/Audit/Pipeline/VisualCheckEvaluator.php` | Servicio delegado de `DocumentPolicyEngine` que evalúa evidencia visual y resuelve discrepancias de calidad documental. |
| `app/Services/Audit/DocumentDuplicationEvaluator.php` | Servicio funcional que evalúa colisiones SHA256 para prevenir fraude por duplicación documental. |
| `app/Services/Audit/Pipeline/FieldValueResolver.php` | Utilidad que extrae y normaliza el valor del documento (header vs items), incluyendo candidatos `valores` de evidencia v1, resolviendo dependencias de normalización cruzada. |
| `app/Services/Audit/Pipeline/ResolvedAuditValue.php` | DTO inmutable para comparar FDV y documento con el mismo contrato (`displayValue`, `values`, `normalizedValues`, `ambiguous`, `evidenceMeta`). |
| `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | Consume `document_normalized` y `document_rejected`, consolida hallazgos, métricas, `audit_result_data` y decisiones documentales, guarda el outcome y lo entrega a `AuditPersistenceQueue` cuando todos los documentos están evaluados |
| `app/Services/Audit/Pipeline/AuditPersistenceQueue.php` | Scheduler Redis/Lua idempotente: mantiene un evento activo por job y promueve el siguiente al cerrar el turno |
| `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | Consume `rules_evaluated`, persiste en SQL, cierra Redis, libera el turno y publica eventos terminales. No toma decisiones funcionales de auditoría. |
| `app/Models/AuditResultPersistenceModel.php` | Escritura SQL transaccional del resumen, hallazgos por adjunto y trazabilidad de la factura |
| `app/Services/Audit/Pipeline/AuditTimingSummarizer.php` | Agrega duraciones de las fases del pipeline y extrae los `phase_timings` para reporte. |
| `app/Services/Audit/Telemetry/TelemetryPublisher.php` | Publica telemetría live best-effort en `audit.telemetry` desde cada worker en su fase real (`orchestration`, `download`, `extraction`, `normalization`, `policy`, `aggregation`). |
| `app/Services/Audit/AuditFindingRules.php` | Utilidad compartida para normalizar valores, sumar métricas y resolver severidad |
| `app/Services/Audit/Pipeline/BatchJobStore.php` | Estado batch, reservas y métricas atómicas; las transiciones usan `job.status` y cubren `pending -> completed` directo |
| `app/Services/Audit/AuditComparisonType.php` | Enum `EXACT/SEMANTIC/BUSINESS/VISUAL` + `fromTipoCampo()` (mapea `E/S/B/V` desde BD) — métodos `isDateField/isQuantityField/isNumberField` son puentes `@deprecated` que delegan a `AuditFieldValueType` |
| `app/Services/Audit/AuditFieldValueType.php` | Enum strategy-based de `TipoDato` explícito en `audit-config`: `TEXT/DATE/QUANTITY/MONEY/IDENTITY_DOC_TYPE/IDENTITY_DOC_NUMBER/CODE/TRACE_TOKEN/PERSON_NAME/INSTITUTION_NAME/ARTICLE_NAME/NIT`. Métodos de comportamiento: `requiresSubsetComparison()` (CODE), `requiresTraceSetComparison()` (TRACE_TOKEN), `requiresTokenSortComparison()` (PERSON_NAME), `allowsMultiValueDocument()` (CODE, TRACE_TOKEN), `allowsSemanticGeminiFallback()` (ARTICLE_NAME y PERSON_NAME). `NIT` normaliza el número tributario colombiano eliminando el dígito de verificación (`-X`) y separadores de miles. Prohibido inferir tipos por nombre del campo. |
| `app/Services/Audit/GeminiConfig.php` | Value Object de configuración Gemini, incluyendo overrides por tarea (`GEMINI_EXTRACTION_*`, `GEMINI_SEMANTIC_*`) y opt-in explícito de `mediaResolution` |
| `app/Services/Audit/GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout, function calling, perfiles explícitos (`extraction`, `semantic_match`) y métricas `X-Audit-Metrics` |
| `app/Services/Audit/ArticleSemanticMatchJudge.php` | Fallback semántico conservador para homologación de artículos y nombres de persona; usa evidencia estructurada, cache versionada y no cachea fallos transitorios |
| `app/Services/Audit/GeminiCallMetrics.php` | Normaliza métricas Gemini por tarea: latencia, tokens de prompt/output/thinking/total y cache hits |
| `app/Services/Audit/ResponseIADiskStore.php` | Persiste snapshots de request/response Gemini en `AUDIT_RESPONSE_IA_DIR` solo cuando `APP_ENV=development` y `AUDIT_RESPONSE_IA_ENABLED=1` |

### Workers bootstrap (largas ejecuciones)

Tras la consolidación AUDIT-015 (2026-04-27), existe **un único launcher** `bin/audit-worker.php` que recibe el nombre de worker como primer argumento CLI y selecciona el consumer mediante un registry interno:

| Comando | Stream consumido | Consumer group |
|---|---|---|
| `php bin/audit-worker.php batch` | `audit.batch.inbox` | `batch-workers` |
| `php bin/audit-worker.php orchestrator` | `audit.inbox` | `orchestrator` |
| `php bin/audit-worker.php downloader` | `audit.documents` | `downloaders` |
| `php bin/audit-worker.php extraction` | `audit.documents` | `extractors` |
| `php bin/audit-worker.php normalizer` | `audit.documents` | `normalizers` |
| `php bin/audit-worker.php policy` | `audit.documents` | `policy` |
| `php bin/audit-worker.php persistence` | `audit.persistence:{queue}` | `persistence` |

El launcher carga `.env`, instancia el consumer correspondiente, registra SIGTERM/SIGINT para stop gracioso y llama `run()`; `pcntl_signal_dispatch` se procesa dentro del loop del consumer base. Los consumer names son únicos por rol + hostname + PID para que Redis refleje réplicas reales. `docker-compose.yml` levanta los 7 servicios con este mismo binario y argumento distinto.

### Controllers y endpoints

| Archivo | Endpoints |
|---|---|
| `app/Controllers/AuditController.php` | `POST /audit/single` (202), `POST /audit/async` (202), `GET /audit/jobs/{job_id}` |
| `app/Controllers/AuditDlqController.php` | `GET /audit/dlq`, `POST /audit/dlq/reprocess` (listar y republicar `dead_letter`) |

## Streams y eventos

| Stream | Productor | Eventos |
|---|---|---|
| `audit.batch.inbox` | `AuditController` | `batch_requested` |
| `audit.inbox` | `BatchRequestedWorker` / `AuditController` | `audit_created`, `batch_created` |
| `audit.documents` | Orchestrator (`registered`/mapping `rejected`), Downloader (`downloaded`), Extractor (`extracted`/content `rejected`), Normalizer (`normalized`) | `document_registered`, `document_downloaded`, `document_extracted`, `document_rejected`, `document_normalized` |
| `audit.persistence:{queue}` | `AuditPersistenceQueue` | `rules_evaluated` |
| `audit.results` | Persistence Worker | `audit_completed`, `audit_failed`, `batch_completed(_with_errors)` |
| `audit.telemetry` | Workers de auditoría | Eventos live `started`, `completed`, `failed`, `rejected` por fase real del DAG |
| `audit.dlq` | Cualquier worker | `dead_letter` (despliega payload original + etapa, attempts y last_error_*) |

## Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `GEMINI_API_KEY` | Credencial obligatoria para el extractor |
| `GEMINI_MODEL` | Modelo Gemini (por defecto `gemini-3.5-flash`) |
| `GEMINI_TIMEOUT`, `GEMINI_MAX_OUTPUT_TOKENS`, `GEMINI_TEMPERATURE`, `GEMINI_TOP_P`, `GEMINI_TOP_K`, `GEMINI_SEED`, `GEMINI_MEDIA_RESOLUTION`, `GEMINI_THINKING_BUDGET`, `GEMINI_THINKING_LEVEL` | Configuración base de generación Gemini |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS`, `GEMINI_EXTRACTION_THINKING_LEVEL`, `GEMINI_EXTRACTION_THINKING_BUDGET` | Perfil de generación para extracción documental |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS`, `GEMINI_SEMANTIC_THINKING_LEVEL`, `GEMINI_SEMANTIC_THINKING_BUDGET` | Perfil de generación para homologación semántica; en Gemini 3.1 dejar `THINKING_LEVEL` vacío si se desea omitir `thinkingConfig` |
| `AUDIT_STREAM_BLOCK_MS` | Bloqueo `XREADGROUP` |
| `AUDIT_EVENT_MAX_RETRIES` | Reintentos por evento antes de DLQ |
| `AUDIT_DLQ_STREAM` | Stream DLQ (default `audit.dlq`) |
| `AUDIT_CACHE_TTL`, `AUDIT_EXTRACTION_CACHE_TTL` | TTL cache extracción Gemini |
| `AUDIT_JOB_TTL` | TTL de estado de jobs batch async en Redis (default 604800) |
| `AUDIT_STATE_TTL` | TTL de estado transitorio de auditorias en Redis (default 604800) |
| `AUDIT_RESERVATION_TTL` | TTL de reservas por `DisId` en Redis (default 86400) |
| `AUDIT_WORKER_PERSISTENCE_REPLICAS` | Réplicas SQL globales (default 3); la cola limita a una activa por job |
| `AUDIT_PERSISTENCE_QUEUE_TTL` | TTL de turnos, pendientes y deduplicación de persistencia (default 604800) |
| `AUDIT_FDV_TTL` | TTL de la FDV completa en Redis |
| `AUDIT_INTERNAL_API_BASE` | Base URL que los workers usan para la API interna (FDV/catalogos/adjuntos) |
| `AUDIT_RESPONSE_IA_ENABLED`, `AUDIT_RESPONSE_IA_DIR` | Controlan snapshots Gemini locales; producción nunca persiste snapshots por hard-deny de `APP_ENV=production` |
| `AUDIT_VERSION_EXTRACTOR`, `AUDIT_VERSION_NORMALIZER`, `AUDIT_VERSION_RULES` | Versionado para trazabilidad en `AuditEvent` |

`GEMINI_MODEL` es el único selector de versión de modelo usado por el gateway. `GeminiConfig` conserva un fallback local si la variable falta, pero `GEMINI_EXTRACTION_*` y `GEMINI_SEMANTIC_*` son perfiles de generación del mismo modelo configurado; no implementan fallback ni redirección a otra versión Gemini.

## Flujo técnico

1. `POST /audit/single` valida `DisDetNro` → publica `audit_created` en `audit.inbox` → retorna 202 con `audit_id`.
2. `DocumentAuditOrchestrator` consume `audit_created`, resuelve FDV, `audit-config`, catálogo y todos los adjuntos físicos. Ejecuta una sola reconciliación global mediante `DocumentAttachmentMatcher`: nombre exacto normalizado, ID corroborado y alias único. Cada `attachment_id` se usa como máximo una vez. Los matches publican `document_registered` con trazabilidad lógica/física; missing, ambiguous, no content y reused se registran como rechazados y publican `document_rejected` con `rejection_category=DOCUMENT_MAPPING`, sin descarga ni Gemini. La regla `Autorizacion=R` reutiliza ese mismo resultado y solo transforma ausencia real en el hallazgo sintético `AUT` existente.
3. `AttachmentDownloadWorker` consume `document_registered`, descarga el adjunto y lo almacena temporalmente en Redis con key lógica `audit:blob:*` (`RedisClient` aplica `REDIS_PREFIX`). Para BLOB exige `bytes === DATALENGTH`. Publica `document_downloaded`; fuente ausente/vacía, transferencia parcial, SQL o Drive son fallos técnicos y se propagan, nunca publican `document_rejected`.
4. `DocumentExtractionWorker` consume `document_downloaded`, lee el BLOB desde Redis y evalúa su integridad estructural mediante `DocumentIntegrityValidator`. Es el único productor autorizado de rechazos de contenido: todo rechazo incluye `rejection_class=document_content`, origen exacto y razón de `DocumentRejectionReason`. Si es válido, calcula `document_hash`, arma prompt compacto, consulta cache; si no hay hit, invoca Gemini con function calling dinámico. Si Gemini lanza HTTP 400 por error de contenido confirmado, emite el rechazo tipado. Si es exitoso, publica `document_extracted`.
5. `DocumentNormalizer` normaliza `fields`/`items`/`visual_checks` (fechas ISO, identidad documental, numéricos canónicos, evidencia visual estructurada, null para vacío) y emite `document_normalized` con `normalization_log` sin PII cruda.
6. `RulesEvaluationWorker` evalúa `document_normalized` contra FDV usando `DocumentPolicyEngine`; FDV y documento se resuelven primero como `ResolvedAuditValue`. Valida dos contratos cerrados sin fallback: contenido solo desde `DocumentExtractionWorker` y mapping solo desde `DocumentAuditOrchestrator`. Mapping genera hallazgo `MAP`, severidad alta, `RECHAZADO` e `integrity`, preservando `logical_doc_id` y candidatos. Cualquier evento legacy o `DOWNLOAD_ERROR` falla técnicamente. Espera `docs_done + docs_rejected >= docs_total`, guarda el outcome y lo encola en `AuditPersistenceQueue`.
7. `AuditPersistenceQueue` publica un único turno activo por job en `audit.persistence:{queue}`; jobs diferentes pueden usar las tres réplicas en paralelo.
8. `AuditPersistenceWorker` aplica una barrera independiente contra `DOWNLOAD_ERROR` y contratos de rechazo inválidos, ejecuta la transacción dual idempotente sobre `AudDispEst` + `AdjuntosDispensacion` + `DispensacionDetalleServicio`, libera el turno y publica `audit_completed`.
9. SQL usa PDO fresco por operación y replay interno solo para lectura/escritura idempotente, con pausas de 1/5/30 segundos. Al agotar SQL o ante un fallo técnico tipado de descarga, `AuditEventConsumer` genera `dead_letter`, hace ACK y ejecuta el cierre terminal en la misma entrega; no espera `XAUTOCLAIM`.

## Reglas de implementación (estrictas)

1. **IA sólo extrae**: Gemini nunca toma decisiones de negocio finales; la comparación y aplicación de **severidades dinámicas** (CRITICO, ALTA, MEDIA, BAJA, INFO) viven en `DocumentPolicyEngine` según el `audit-config`.
2. **TipoCampo gobierna la comparación y TipoDato gobierna el valor** — fuente de verdad: columnas `TipoCampo` y `TipoDato` en `Discolnet.dbo.AudDispCampo`.
   - `TipoCampo=E` → `EXACT` (igualdad normalizada)
   - `TipoCampo=S` → `SEMANTIC` (umbral 0.82; Gemini solo si `TipoDato=article_name`)
   - `TipoCampo=B` → `BUSINESS` (sumatoria de items + comparación numérica; solo `TipoDato=quantity`)
   - `TipoCampo=V` → `VISUAL` (vive en `visualChecks[]`; no usa `TipoDato`)
   - `TipoDato` permitido: `text`, `date`, `quantity`, `money`, `identity_doc_type`, `identity_doc_number`, `code`, `trace_token`, `person_name`, `institution_name`, `article_name`, `nit`, `auth_number`.
   Prohibido inferir `TipoDato` por nombre del campo. `AuditFieldValueType::fromInput()` debe recibir metadata explícita del `audit-config`. La ubicación `extract_fields` vs `extract_items` la define `DocumentExtractionContractBuilder` con reglas explícitas de dominio para evitar mezclar cabecera y líneas.
3. **[AUDIT-016] Subset matching para `CODE`**: Campos con `AuditFieldValueType::CODE` usan `evaluateSubsetField()`. Si el FDV tiene un código (ej. `S202`) y el documento lista múltiples (ej. `S202, S273, F432`), se evalúa como `COINCIDE` con `tipo_auditoria=exact`. Si la evidencia llega como `valor=null`, `valores=[...]` y `FOUND_IN_LIST`, `FieldValueResolver` debe usar `valores` como evidencia encontrada, no emitir `NO_ENCONTRADO`. `tokenizeCodeField()` separa por coma, punto y coma o barra; normaliza a mayúsculas. El hallazgo incluye `valueType=code` y `valoresDocumento` (array de tokens).
4. **[TRACE_TOKEN] Set-based matching para Trazabilidad**: Campos como `Lote` usan `TRACE_TOKEN`. FDV y documento se resuelven como sets desde `ResolvedAuditValue`; si ambos sets son iguales, es `COINCIDE`. Si el documento trae solo una parte de un FDV múltiple, es `NO_CONCLUYENTE` (evidencia parcial). Si trae un lote no registrado en FDV, es `VALOR_DISTINTO`. El hallazgo incluye `valoresFuenteVerdad` y `valoresDocumento`.
5. **[AUDIT-016] Token-sort para `PERSON_NAME`**: Campos `PERSON_NAME` en modo semántico usan una heurística estructural de similitud por tokens (exigiendo que al menos 1 token de la parte más corta coincida exactamente). Si la heurística falla, hace fallback a `ArticleSemanticMatchJudge` para validar posibles alias o variaciones de escritura vía Gemini.
6. **[AUDIT-016] No data-loss en multi-item/lista divergente (CAT-1)**: Si `resolveDocumentValue()` encuentra múltiples items con valores distintos en un campo no sumable, o una evidencia escalar con varios candidatos `valores`, emite `NO_CONCLUYENTE` con `detalle: {ambiguous: true, valores: [...]}`. Un único candidato en `valores` se usa como escalar. Ya no se descarta silenciosamente el campo ni se convierte en `NO_ENCONTRADO` cuando existe evidencia.
7. **[AUDIT-016] Hallazgo canónico v1**: `buildDataFinding()` inyecta `valueType`; para `CODE` y `TRACE_TOKEN` agrega `valoresFuenteVerdad` y/o `valoresDocumento` cuando existen tokens/set evaluables. Las cantidades `TipoCampo=B` reportan `valorFuenteVerdad` y `valorDocumento` como sumatoria agregada de items. Si un hallazgo configurable falla y existe `codigoCampo`, el `detalle` se enriquece con el prefijo textual `-CODIGO- detalle`.
8. **Items solo cuando existen filas segmentadas**: no derivar `items` desde `fields` y viceversa. Si el extractor detecta segmentación parcial o incompleta, no debe fallar con excepción; en su lugar, emite el warning `ITEM_SEGMENTATION_INCOMPLETE` en el payload. Luego, `DocumentPolicyEngine` intercepta este warning y fuerza `NO_CONCLUYENTE` para todas las evaluaciones a nivel línea (`TipoCampo=B`) a fin de evitar validaciones sobre sumatorias parciales peligrosas.
9. **Prompt compacto de extracción**: Gemini no recibe valores esperados de FDV (`Campos de cabecera esperados`, `Campos de línea esperados`, diagnósticos, fechas, identidad, etc.). Solo recibe contexto estructural: documento objetivo, campos solicitados, separación identidad, ubicación `fields`/`items`, checks visuales y segmentación de filas cuando aplican. El schema puede usar descripciones configuradas del `audit-config` como `valor.description` y conserva las descripciones PHP solo como fallback; el system prompt personalizado se deduplica contra esas descripciones antes de calcular `prompt_context_hash`. En el schema Gemini, `valores` se declara solo para `CODE` y `TRACE_TOKEN`; `DocumentNormalizer` reconstruye `valores` desde `valor` para escalares. Las pistas de artículo para documentos prescriptivos se permiten solo cuando `NombreArticulo` está en `items`.
10. **Comparación determinista**: umbrales `persona 0.85`, `artículo 0.82`, `texto 0.90`; numéricos/IDs/fechas con igualdad normalizada.
11. **Cadena documental**: Fórmula → Autorización → Dispensa. El `audit-config` runtime no persiste `rol`; todo campo activo en `fields` se evalúa según `TipoCampo` y severidad.
12. **Entrega parcial** válida: `cantidad_entregada_total <= cantidad_autorizada` (o `cantidad_prescrita` si no hay autorización).
13. **Exclusiones documentales**: no documentar campos como "informativos" si el `audit-config` real no trae esa marca. Para omitir un campo de auditoría debe no estar activo en `fields`; `omitirSi` no está implementado en el runtime actual.
14. **Sin código legacy**: clean rebuild; no agregar shims ni compatibilidad con el pipeline monolítico anterior.
15. **XACK solo tras éxito**: acknowledge después de publicar el evento siguiente o persistir resultado final.
16. **Errores técnicos de Gemini no son detalle funcional**: loguear el error, devolver `NO_CONCLUYENTE` limpio y no cachear fallos transitorios del fallback semántico.
17. **Métricas Gemini por tarea**: preservar `gemini_extraction`, `gemini_semantic` y `gemini_total` en `phase_timings`, incluyendo respuestas malformadas cuando Gemini entregue `usageMetadata`.
18. **Perfiles Gemini aislados**: `mediaResolution` solo se permite en perfil `extraction`; `semantic_match` debe ser text-only, con cache semántica versionada y decisión PHP conservadora ante evidencia incompleta.
19. **Visuales calculables**: `VigenciaEntrega` no se cierra como booleano en `DocumentPolicyEngine`; Gemini extrae `valor`, `unidad` y `fecha_base`, `DocumentNormalizer` los canoniza y `RulesEvaluationWorker` calcula `FechaEntrega <= fecha_base + valor`. Si falta evidencia suficiente en un visual activo, el resultado agregado es `NO_CONCLUYENTE`.
20. **Identidad canónica E2E**: `DisId` de auditoría debe cumplir `vw_discolnet_dispensas.DisId == AudDispEst.FacSec` (columna legacy). `DisDetNro`/`Dispensa` se persiste como `AudDispEst.FacNro`, que es la PK operativa de resultados; `AuditResultPersistenceModel` hace el upsert serializable y `AuditStatusModel` consulta detalle y timings por `FacNro`. Ver `plans/audit-identity-contract.md`.
21. **Persistencia justa y atómica**: máximo un evento SQL activo por job; nunca separar el resumen, los hallazgos del adjunto y la trazabilidad en transacciones distintas.
22. **Sin bypass de scheduling**: está prohibido publicar `rules_evaluated` mediante `AuditEventPublisher`; usar siempre `AuditPersistenceQueue`.
23. **Fallo técnico no es rechazo documental**: PDO, SQL Server, Drive, fuente ausente/vacía durante descarga y transferencia parcial deben propagarse como excepciones técnicas. Está prohibido emitir `DOWNLOAD_ERROR`, `document_rejected` o un hallazgo funcional desde descarga. La ausencia o falta de contenido detectada durante la reconciliación de metadata sí pertenece a `DOCUMENT_MAPPING` y ocurre antes de encolar descarga.
24. **Productores segregados y defensa en profundidad**: `DocumentAuditOrchestrator` solo publica rechazos `DOCUMENT_MAPPING`; `DocumentExtractionWorker` solo publica rechazos `document_content`. `RulesEvaluationWorker` valida categoría/clase, origen y allowlist, y `AuditPersistenceWorker` repite la barrera antes de SQL.
25. **Reconciliación cerrada 1:1**: no seleccionar el primer candidato ni reutilizar un `attachment_id`. La única API del matcher es `matchAll()` y las únicas estrategias válidas son `exact_name`, `validated_id` y `unique_alias`.

## Omisiones de campos (runtime actual)

El runtime actual no lee ni persiste `omitirSi`. `AuditConfigModel::getConfig()` retorna `campoNombre`, `tipoCampo`, `tipoDato`, `orden`, `severity`, `codigoCampo` y, para visuales, `description`. `AuditConfigController::sanitizeFields()` exige `tipoDato` para campos no visuales, acepta `tipoDato = null` solo para `TipoCampo = V` y preserva `codigoCampo` cuando llega en el payload.

Implicación operativa:
- si un campo aparece activo en `fields`, `DocumentPolicyEngine` lo evalúa;
- si debe excluirse de auditoría, debe removerse del `audit-config`;
- `OMITIDO` puede aparecer solo por condiciones internas actuales del engine, por ejemplo ausencia simultánea de valor FDV y valor documental auditable, no por reglas condicionales configurables.

## Agregación de items en reglas `B`

Para `TipoCampo = B`, `DocumentPolicyEngine` **suma los items de la FDV** antes de comparar contra `valorDocumento`. Caso real `T38250701547` (NitSec 2426): la FDV tiene 2 items con `CantidadEntregada = 20` y `30`; el hallazgo persistido reporta `valorFuenteVerdad: "50"`, `valorDocumento: "50"`, `tipo_auditoria: "business"`, `resultado: COINCIDE`. Implicación: nunca documentar reglas `B` como "campo a campo" — son agregadas a nivel documento.

## Contrato real de hallazgo (v1 — AUDIT-016)

Forma canónica del objeto en `AudDispEst.Hallazgos[*]`:

```json
{
  "severidad":          "alta|media|baja",
  "campo":              "<nombre>",
  "documento":          "DISPENSA|AUTORIZACION|FORMULA MEDICA|...",
  "valorDocumento":     "<valor extraído por Gemini>",
  "valorFuenteVerdad":  "<valor de la FDV>",
  "resultado":          "COINCIDE|VALOR_DISTINTO|NO_ENCONTRADO|OMITIDO|NO_CONCLUYENTE|RECHAZADO",
  "detalle":            "<string|null>",
  "tipo_auditoria":     "exact|semantic|business|visual|integrity",
  "valueType":          "text|date|quantity|money|identity_doc_type|identity_doc_number|code|trace_token|person_name|institution_name|article_name",
  "valoresFuenteVerdad":["5D03364","5G00989"],    // opcional para CODE/TRACE_TOKEN
  "valoresDocumento":   ["5D03364","5G00989"]     // opcional para CODE/TRACE_TOKEN
}
```

> [!NOTE]
> `valueType`, `valoresFuenteVerdad` y `valoresDocumento` son campos v1 inyectados por `buildDataFinding()`. `valueType` debe salir del `TipoDato` explícito del `audit-config`, no del nombre del campo.
> Para resultados fallidos (`VALOR_DISTINTO`, `NO_ENCONTRADO`, `NO_CONCLUYENTE` y `RECHAZADO` cuando haya código disponible), el `detalle` inicia con el prefijo textual `-<codigoCampo>- ` tomado de `AudDispCampo.CodigoCampo`; no se agrega una propiedad pública separada.

El contrato runtime actual no incluye `rol` en hallazgos. Visual checks booleanos emiten un objeto similar, sin `valueType`; los visuales calculables como `VigenciaEntrega` emiten `tipo_auditoria: "visual"` desde la agregación de reglas.

## Nota Gemini 3.x — thinking tokens

En Gemini 3.x los thinking tokens pueden superar **4×** los output tokens (caso `T38250701547`: 5 594 thinking vs 1 177 output en extracción). Considerar al ajustar `GEMINI_*_MAX_OUTPUT_TOKENS` y `GEMINI_*_THINKING_BUDGET` para no truncar respuestas válidas.

## Anti-patterns ⚠️

1. **No** consultar vistas SQL directamente desde workers para FDV/adjuntos — usar `AuditDataService` y `AttachmentDownloadService`.
2. **No** incluir base64, binarios o credenciales en el payload de eventos (solo en claves de estado Redis).
3. **No** inyectar valores FDV esperados en el prompt de extracción; Gemini extrae evidencia visible y PHP compara.
4. **No** fabricar `items` desde `fields` en normalizador o policy.
5. **No** borrar mensajes de streams; dejar ack/retry/DLQ hacer su trabajo.
6. **No** mezclar dos responsabilidades en un worker: cada etapa publica exactamente un evento siguiente.

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
php bin/audit-worker.php downloader &
php bin/audit-worker.php extraction &
php bin/audit-worker.php normalizer &
php bin/audit-worker.php policy &
php bin/audit-worker.php persistence &
```

### Listar DLQ
```bash
curl http://localhost:8080/audit/dlq?limit=20
```

## Checklist rápido

1. `POST /audit/single` responde 202 con `audit_id`.
2. `POST /audit/async` responde 202 con `job_id`.
3. Workers procesan el flujo completo para `T38250701547` (cliente 2426).
4. Búsqueda de nombres legacy de cola y llamadas Redis list (`LPUSH`/`BRPOP`) sin coincidencias; tampoco debe existir orquestador monolítico anterior en `app/Services/Audit/`.
5. `audit.dlq` recibe `dead_letter` al agotar reintentos.
6. Persistencia final transaccional en `AudDispEst` + `AdjuntosDispensacion` + `DispensacionDetalleServicio`.
7. PHPUnit completo verde antes de merge; suite actual organizada en 47 archivos PHP bajo `tests/`.
8. `php vendor/bin/phpunit tests/Services/Audit/GoldenSetReplayTest.php --no-coverage` valida los fixtures golden.
9. Hallazgos de tipo `CODE`/`TRACE_TOKEN` incluyen `valueType` y arrays de valores FDV/documento cuando aplican.
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
