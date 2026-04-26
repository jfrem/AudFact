---
name: audfact-audit-gemini
description: Trabajar en el pipeline de auditoría IA event-driven de AudFact sobre Redis Streams. Usar cuando se modifique app/Services/Audit/Events/*, bin/audit-*-worker.php, contratos de eventos (audit_created, document_registered, document_extracted, document_normalized, rules_evaluated, audit_completed, dead_letter), el schema Gemini `extract_document_data` o el manejo de DLQ.
---

# AudFact Audit Gemini (Event-Driven)

## Objetivo
Mantener confiable el pipeline event-driven de auditoría documental con Redis Streams, extracción Gemini por function calling, normalización y policy en PHP puro, y persistencia final en SQL Server.

> [!TIP]
> Plan normativo obligatorio: [PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md](file:///c:/Users/USER/Desktop/AudFact/PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md).

## Archivos clave

### Servicios del pipeline event-driven

| Archivo | Rol |
|---|---|
| `app/Services/Audit/Events/AuditEvent.php` | Value-object inmutable de evento (tipos, payload, UUID v4, timestamps ISO 8601) |
| `app/Services/Audit/Events/AuditEventPublisher.php` | Publica a `audit.inbox`, `audit.documents`, `audit.results` y `audit.dlq` |
| `app/Services/Audit/Events/AuditEventConsumer.php` | Base abstracta: `XREADGROUP`, ack, reintentos y envío a DLQ automático |
| `app/Services/Audit/Events/AuditStateStore.php` | Claves Redis de estado (`audit:{id}:*`, `job:{id}:*`, contadores, FDV cache) |
| `app/Services/Audit/Events/InternalAuditApiClient.php` | Cliente HTTP interno usado por workers (FDV, catálogo, adjuntos, descarga JSON) |
| `app/Services/Audit/Events/SchemaBuilder.php` | Construye el function declaration `extract_document_data` desde `audit-config` (TipoCampo D/V) |
| `app/Services/Audit/Events/DocumentAuditOrchestrator.php` | Consume `audit_created`, resuelve FDV/config/adjuntos y publica N `document_registered` |
| `app/Services/Audit/Events/DocumentExtractionWorker.php` | Consume `document_registered`, descarga adjunto, calcula `document_hash`, consulta cache y publica `document_extracted` |
| `app/Services/Audit/Events/ExtractionCache.php` | Cache Redis por `document_hash` para reutilizar extracciones Gemini |
| `app/Services/Audit/Events/DocumentNormalizer.php` | Normalización determinística PHP de `fields`/`items`/`visual_checks` (fechas ISO, upper sin tildes, numéricos) |
| `app/Services/Audit/Events/DocumentNormalizationWorker.php` | Consume `document_extracted` y publica `document_normalized` |
| `app/Services/Audit/Events/DocumentPolicyEngine.php` | Motor determinista por documento: COINCIDE / VALOR_DISTINTO / NO_ENCONTRADO / OMITIDO / NO_CONCLUYENTE |
| `app/Services/Audit/Events/RulesEvaluationWorker.php` | Consume `document_normalized` y publica `rules_evaluated` cuando `docs:done == docs:total` |
| `app/Services/Audit/Events/AuditResultAggregator.php` | Construye el contrato final `auditResultData` + decisiones documentales para persistir |
| `app/Services/Audit/Events/AuditAggregationWorker.php` | Consume `rules_evaluated`, persiste en SQL y publica `audit_completed` / `audit_failed` / `batch_completed(_with_errors)` |
| `app/Services/Audit/GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout y function calling |
| `app/Services/Audit/FieldClassifier.php` | Clasifica campos por tipo (documental/visual) y severidad |

### Workers bootstrap (largas ejecuciones)

| Binario | Stream consumido | Consumer group |
|---|---|---|
| `bin/audit-orchestrator-worker.php` | `audit.inbox` | `orchestrator` |
| `bin/audit-extraction-worker.php` | `audit.documents` | `extractors` |
| `bin/audit-normalizer-worker.php` | `audit.documents` | `normalizers` |
| `bin/audit-policy-worker.php` | `audit.documents` | `policy` |
| `bin/audit-aggregator-worker.php` | `audit.results` | `aggregator` |

Cada worker: carga `.env`, instancia el consumer correspondiente, registra SIGTERM/SIGINT para stop gracioso, llama `run()`; `pcntl_signal_dispatch` se procesa dentro del loop del consumer base.

### Controllers y endpoints

| Archivo | Endpoints |
|---|---|
| `app/Controllers/AuditController.php` | `POST /audit/single` (202), `POST /audit/async` (202), `GET /audit/jobs/{jobId}` |
| `app/Controllers/AuditDlqController.php` | `GET /audit/dlq`, `POST /audit/dlq/reprocess` (listar y republicar `dead_letter`) |

## Streams y eventos

| Stream | Productor | Eventos |
|---|---|---|
| `audit.inbox` | `AuditController` | `audit_created`, `batch_created` |
| `audit.documents` | Orchestrator / Extractor / Normalizer | `document_registered`, `document_extracted`, `document_normalized`, `extraction_failed` |
| `audit.results` | Policy / Aggregator | `rules_evaluated`, `audit_completed`, `audit_failed`, `batch_completed(_with_errors)` |
| `audit.dlq` | Cualquier worker | `dead_letter` (despliega payload original + etapa, attempts y last_error_*) |

## Variables de entorno relevantes

| Variable | Uso |
|---|---|
| `GEMINI_API_KEY` | Credencial obligatoria para el extractor |
| `GEMINI_MODEL` | Modelo Gemini (por defecto `gemini-3.1-pro-preview`) |
| `GEMINI_TIMEOUT`, `GEMINI_MAX_OUTPUT_TOKENS`, `GEMINI_TEMPERATURE`, `GEMINI_TOP_P`, `GEMINI_TOP_K`, `GEMINI_SEED` | Determinismo de extracción |
| `AUDIT_STREAM_BLOCK_MS` | Bloqueo `XREADGROUP` |
| `AUDIT_EVENT_MAX_RETRIES` | Reintentos por evento antes de DLQ |
| `AUDIT_DLQ_STREAM` | Stream DLQ (default `audit.dlq`) |
| `AUDIT_CACHE_TTL`, `AUDIT_EXTRACTION_CACHE_TTL` | TTL cache extracción Gemini |
| `AUDIT_FDV_TTL` | TTL de la FDV completa en Redis |
| `AUDIT_INTERNAL_API_BASE` | Base URL que los workers usan para la API interna (FDV/catalogos/adjuntos) |
| `AUDIT_VERSION_EXTRACTOR`, `AUDIT_VERSION_NORMALIZER`, `AUDIT_VERSION_RULES` | Versionado para trazabilidad en `AuditEvent` |

## Flujo técnico

1. `POST /audit/single` valida `DisDetNro` → publica `audit_created` en `audit.inbox` → retorna 202 con `audit_id`.
2. `DocumentAuditOrchestrator` consume `audit_created`, resuelve FDV (`/dispensation/{DisDetNro}`), `audit-config` por `NitSec`, catálogo documental y adjuntos; publica N `document_registered` en orden ascendente por `docId`.
3. `DocumentExtractionWorker` descarga el adjunto por URL interna, calcula `document_hash = sha256(base64_data)`, consulta cache; si hay hit publica `document_extracted` con `cache_hit=true`; si no, invoca Gemini con function calling `extract_document_data`.
4. `DocumentNormalizationWorker` normaliza `fields`/`items`/`visual_checks` (fechas ISO, mayúsculas sin tildes, numéricos canónicos, null para vacío) y emite `document_normalized` con `normalization_log`.
5. `RulesEvaluationWorker` evalúa policy por documento contra FDV, espera `docs:done == docs:total` y publica `rules_evaluated` con hallazgos, métricas y `document_decisions`.
6. `AuditAggregationWorker` agrega a `auditResultData`, persiste en `AudDispEst` + `AdjuntosDispensacion` y publica `audit_completed` (o `audit_failed` si persistencia falla).
7. Fallos recuperables se reintentan hasta `AUDIT_EVENT_MAX_RETRIES`; al agotar, `AuditEventConsumer` genera `dead_letter` automáticamente.

## Reglas de implementación (estrictas)

1. **IA sólo extrae**: Gemini nunca toma decisiones de negocio; comparación y severidad viven en `DocumentPolicyEngine`.
2. **TipoCampo gobierna el schema**: `D` → `fields`, `V` → `visual_checks`. Prohibido inferir por nombre.
3. **Items solo cuando existen filas segmentadas**: no derivar `items` desde `fields` y viceversa.
4. **Comparación determinista**: umbrales `persona 0.85`, `artículo 0.82`, `texto 0.90`; numéricos/IDs/fechas con igualdad normalizada.
5. **Cadena documental**: Fórmula → Autorización → Dispensa, con autoridad por campo (ver plan §23.3).
6. **Entrega parcial** válida: `cantidad_entregada_total <= cantidad_autorizada` (o `cantidad_prescrita` si no hay autorización).
7. **Factor de empaque**: sólo `NitSec = 2426` admite exceso `<= 5` unidades con warning `ACEPTADO_POR_EMPAQUE`.
8. **NumeroAutorizacion** no se audita contra `FORMULA MEDICA`.
9. **Sin código legacy**: clean rebuild; no agregar shims ni compatibilidad con el pipeline monolítico anterior.
10. **XACK solo tras éxito**: acknowledge después de publicar el evento siguiente o persistir resultado final.

## Anti-patterns ⚠️

1. **No** consultar vistas SQL directamente desde workers para FDV/adjuntos — usar `InternalAuditApiClient`.
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
Resultado esperado documentado en `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` §23.9.

### Levantar los workers localmente
```bash
php bin/audit-orchestrator-worker.php &
php bin/audit-extraction-worker.php &
php bin/audit-normalizer-worker.php &
php bin/audit-policy-worker.php &
php bin/audit-aggregator-worker.php &
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
7. PHPUnit pasa (cobertura mínima: Eventos 80%, SchemaBuilder 90%, Normalizer/Policy/Aggregator 80%, Controllers 75%).

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

Después de cualquier cambio en el pipeline event-driven:

1. Verificar que los archivos listados en "Archivos clave" existan.
2. Confirmar que streams y consumer groups siguen alineados con `AuditEventPublisher` y `AuditEventConsumer`.
3. Ejecutar `audfact-docs-sync` como segunda capa.

> [!CAUTION]
> Dejar la skill desactualizada genera drift que confunde a agentes futuros.

## Referencias

- Plan normativo: `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md`
- Sprint checkpoints: `plans/checkpoints/`
- Skill asociada para modelos SQL: `audfact-sqlsrv-models`
