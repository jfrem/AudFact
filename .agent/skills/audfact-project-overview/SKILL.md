---
name: audfact-project-overview
description: >
  Visión general del proyecto AudFact. Usar cuando el usuario solicite "entender la arquitectura",
  "mapear dependencias", "obtener visión general", "analizar estructura", "identificar componentes",
  "revisar organización", "hacer diagrama de arquitectura" o "explicar cómo está organizado".
---

# AudFact — Project Overview

## ¿Qué es AudFact?
Sistema de auditoría documental automatizada que compara documentos escaneados (Actas de Entrega) contra datos de dispensación en SQL Server, usando **Google Gemini API** como motor de análisis multimodal.

> [!NOTE]
> Para una visión más profunda, consulta [overview.md](/plans/overview.md) y [architecture.md](/plans/architecture.md).

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2-FPM — Framework MVC custom |
| Base de datos | SQL Server (PDO `sqlsrv`) |
| IA | Google Gemini API (Guzzle HTTP, modelo configurable) |
| Almacenamiento | Google Drive (JWT) + BLOB en BD |
| Web Server | Nginx 1.25 (vía Docker) |
| Contenedores | Docker Compose (php + nginx) |

## Estructura

```
AudFact/
├── frontend/           # Aplicación Next.js (Dashboard + Gestión)
├── app/
│   ├── Controllers/     # 11 controladores HTTP (incluye base)
│   ├── Models/          # 7 modelos SQL Server (incluye base Model.php)
│   ├── Services/        # GoogleDrive + Audit/ (22 archivos: Pipeline/, Debug/, raíz)
│   ├── Routes/web.php   # 19 endpoints
│   └── wrap/            # Integración MCP (4 tools)
├── core/                # Framework: Router, Database, Validator, Response, Logger, RateLimit, Middleware, Env, Route, RedisClient
├── public/index.php     # Bootstrap: CORS, rate limit, exception handler, dispatch
├── docker/              # Dockerfile (PHP + Nginx), nginx.conf, xdebug.ini
├── docker-compose.yml   # php (HA: 5 réplicas) + nginx + redis + workers (orchestrator, extraction×5, normalizer, policy, aggregator)
├── bin/                 # bin/audit-worker.php (launcher único de workers, AUDIT-015)
├── tests/               # PHPUnit (Controllers, Models, Services)
├── responseIA/          # Snapshots de request/response Gemini (debug)
└── logs/                # Logs rotativos por hostname (HA-safe)
```

## Endpoints REST (22)

> Fuente canónica: `app/Routes/web.php`. Tabla detallada: skill `audfact-api-rest`.

| Método | URI | Controlador |
|---|---|---|
| GET | `/` | Controller::index |
| GET | `/health` | HealthController::status |
| GET | `/metrics/async` | ObservabilityController::asyncMetrics |
| GET | `/config/public` | ConfigController::publicConfig |
| GET | `/clients` | ClientsController::index |
| GET | `/clients/{clientId}` | ClientsController::show |
| GET | `/clients/{clientId}/documents` | ClientsController::documents |
| POST | `/clients` | ClientsController::lookup |
| GET | `/clients/{clientId}/audit-config` | AuditConfigController::show |
| POST | `/clients/{clientId}/audit-config` | AuditConfigController::save |
| GET | `/invoices` | InvoicesController::index |
| POST | `/invoices` | InvoicesController::search |
| GET | `/dispensation/{invoiceId}/attachments/{nitSec}` | AttachmentsController::showByDispensation |
| GET | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | AttachmentsController::downloadByDispensation |
| GET | `/dispensation/{DisDetNro}` | DispensationController::show |
| POST | `/dispensation` | DispensationController::lookup |
| GET | `/audit/results` | AuditController::results |
| GET | `/audit/documents-history` | AuditController::documentsHistory |
| POST | `/audit/single` | AuditController::single |
| POST | `/audit/async` | AuditController::async |
| GET | `/audit/jobs/{jobId}` | AuditController::jobStatus |
| GET | `/audit/{facNro}/timings` | AuditController::timings |
| GET | `/audit/dlq` | AuditDlqController::index |
| POST | `/audit/dlq/reprocess` | AuditDlqController::reprocess |

## Flujo principal — Auditoría IA (event-driven)

Pipeline event-driven sobre Redis Streams (post AUDIT-013/014/015). Cada etapa es un worker independiente que consume un evento y publica el siguiente:

```
1. POST /audit/single → AuditController::single
   └─ publica `audit_created` en stream `audit.inbox` (202 con audit_id)

2. DocumentAuditOrchestrator (group: orchestrator)
   ├─ resuelve FDV (DispensationModel) + audit-config (AuditConfigModel) + adjuntos
   ├─ construye function declaration `extract_document_data` desde audit-config
   └─ publica N × `document_registered` en `audit.documents`

3. DocumentExtractionWorker (group: extractors, ×5 réplicas)
   ├─ descarga adjunto (Drive URL o BLOB)
   ├─ document_hash = sha256(file) → cache Redis
   └─ Gemini function calling → publica `document_extracted`

4. DocumentNormalizer (group: normalizers)
   ├─ fechas ISO, upper sin tildes, numéricos canónicos, null para vacío
   └─ publica `document_normalized`

5. RulesEvaluationWorker (group: policy)
   ├─ DocumentPolicyEngine: COINCIDE/VALOR_DISTINTO/NO_ENCONTRADO/OMITIDO/NO_CONCLUYENTE
   ├─ SemanticMatchJudge como fallback de homologación de artículos
   └─ cuando docs_done == docs_total, publica `rules_evaluated` en `audit.results`

6. AuditAggregationWorker (group: aggregator)
   ├─ AuditResultData + documentDecisions
   ├─ AuditStatusModel.persistAuditResultWithAttachments()
   │    → MERGE Discolnet.dbo.AudDispEst + UPDATE AdjuntosDispensacionDetalle
   └─ publica `audit_completed` | `audit_failed` | `batch_completed[_with_errors]`

Reintentos por evento → DLQ (`audit.dlq`) tras AUDIT_EVENT_MAX_RETRIES (3).
```

## Skills disponibles

| Skill | Cuándo usarla |
|---|---|
| `audfact-api-rest` | Cambios en rutas, controladores, validación, respuestas HTTP |
| `audfact-audit-gemini` | Pipeline de auditoría IA, prompts, retry, JSON parsing |
| `audfact-sqlsrv-models` | Modelos, queries SQL, Database.php, BLOB |
| `audfact-mcp-wrap` | Integración MCP, tools, webhook, capabilities |
| `audfact-runtime-docker` | Docker, Nginx, PHP-FPM, .env, conectividad |
| `audfact-security-guardrails` | Rate limit, CORS, validación, logging, archivos |

## Patrones de diseño

- **Singleton**: `Database::getConnection()` — pool de conexiones PDO (default + db2).
- **Strategy**: `AuditDataServiceInterface`, `AttachmentDownloadServiceInterface`.
- **Template Method**: `AuditEventConsumer` (XREADGROUP + ack + retry + DLQ; subclases sólo implementan `handle()`).
- **Builder dinámico**: function declaration `extract_document_data` armado desde `audit-config` en `DocumentAuditOrchestrator`.
- **Chain of Responsibility**: Middleware pipeline en `Core\Router`.
- **Facade**: `Response::success()` / `Response::error()`.
- **Retry with Backoff**: `GeminiGateway` con `GeminiCircuitBreaker`.
- **Idempotencia con scripts Lua**: `AuditStateStore` (`STORE_RULES_EVALUATION_LUA`, `COMPLETE_AUDIT_LUA`, `REGISTER_DOCUMENT_LUA`).

## Instrucciones para el agente

1. **Antes de modificar**, identificar qué skill aplica.
2. **Consultar la documentación en `plans/`** para contexto arquitectónico.
3. **Seguir el flujo de trabajo** definido en la skill específica.
4. **Verificar consistencia** con los diagramas de arquitectura en `plans/architecture-diagrams.md`.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio significativo en el proyecto, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los conteos de controllers, models, services, endpoints siguen correctos?
   - ¿La estructura de directorios refleja la realidad?
   - ¿La tabla de endpoints está completa?
   - ¿El flujo principal de auditoría refleja el orquestador actual?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.
