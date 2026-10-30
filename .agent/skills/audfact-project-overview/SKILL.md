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

## Ejecución durable v2

Desde 2026-10-16, prefetch limita descargas nuevas con histéresis 90/70 y poda
de reservas vencidas; reclaim y extracción siguen drenando. Un guard tipado
común clasifica respuestas Gemini y evita convertir bloqueo/truncamiento en
evidencia clínica. Protocolo único activo: ejecución durable v2 (v1 erradicada).

El runtime opera exclusivamente bajo ejecución durable v2 (`AuditExecutionConfig::VERSION = 2`),
con ocho roles de worker más mantenimiento independiente (`bin/audit-execution-maintenance.php`):
cuatro etapas con tareas durables/fences/receipts, Round-Robin por job (`cap=1`),
ondas semánticas agrupadas y presupuesto Gemini compartido (`GeminiQuotaBudget`) con
cuotas explícitas obligatorias verificadas en AI Studio. `RulesEvaluationWorker` (`policy`)
planifica y evalúa reglas en PHP puro sin HTTP; persistencia y slots mantienen contrato.
Modelo configurado/default actual: `gemini-3.8-flash`.

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
| Contenedores | Docker Compose (`php`, `nginx`, `redis`, workers y frontend en producción) |

## Estructura

```
AudFact/
├── frontend/           # Aplicación Next.js (Dashboard + Gestión)
├── app/
│   ├── Controllers/     # 13 controladores HTTP (incluye base)
│   ├── Models/          # 9 modelos SQL Server (incluye base Model.php)
│   ├── Services/        # 97 archivos PHP; 95 bajo Audit/ (incluye enums y value objects)
│   ├── Routes/web.php   # 36 rutas registradas
│   └── wrap/            # Integración MCP (4 tools)
├── core/                # Framework: Router, Database, Validator, Response, Logger, RateLimit, Middleware, Env, Route, RedisClient
├── public/index.php     # Bootstrap: CORS, rate limit, exception handler, dispatch
├── docker/              # Dockerfile (PHP + Nginx), nginx.conf, xdebug.ini
├── docker-compose.yml   # Defaults: php×5, batch×4, orchestrator×8, downloader×12, extraction VIP×2/batch×12, normalizer×4, policy×8, persistence×12
├── bin/                 # bin/audit-worker.php (launcher único de workers, AUDIT-015)
├── tests/               # PHPUnit (Controllers, Models, Services)
└── logs/                # Logs rotativos en desarrollo; Core\Logger usa stderr en producción; `logs/responseIA` solo dev
```

## Endpoints REST (36)

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
| GET | `/audit/field-catalog` | AuditConfigController::catalog |
| GET | `/invoices` | InvoicesController::index |
| POST | `/invoices` | InvoicesController::search |
| GET | `/dispensation/{disDetNro}/attachments/download/{attachmentId}` | AttachmentsController::downloadByDispensation |
| GET | `/dispensation/{disDetNro}/attachments/{nitSec}` | AttachmentsController::showByDispensation |
| GET | `/dispensation/{DisId}/{DisDetNro}` | DispensationController::show |
| POST | `/dispensation` | DispensationController::lookup |
| GET | `/audit/results` | AuditController::results |
| GET | `/audit/results/{facNro}` | AuditController::resultDetail |
| GET | `/audit/stats` | AuditController::stats |
| GET | `/audit/stats/monthly` | AuditController::monthlyPerformance |
| GET | `/audit/documents-history` | AuditController::documentsHistory |
| POST | `/audit/single` | AuditController::single |
| POST | `/audit/async` | AuditController::async |
| GET | `/audit/jobs` | AuditController::jobsList |
| GET | `/audit/jobs/{jobId}` | AuditController::jobStatus |
| GET | `/audit/status/{auditId}` | AuditController::status |
| GET | `/audit/{facNro}/timings` | AuditController::timings |
| GET | `/audit/dlq` | AuditDlqController::index |
| POST | `/audit/dlq/reprocess` | AuditDlqController::reprocess |
| GET | `/audit/{auditId}/flow-stream` | AuditFlowController::stream |
| GET | `/dispensation/{disDetNro}/attachments` | AttachmentsController::showByDispensation |
| GET | `/audit/costs` | AuditCostController::index |
| GET | `/audit/costs/clients` | AuditCostController::byClients |
| GET | `/audit/costs/trm` | AuditCostController::currentTrm |
| GET | `/audit/costs/{facNro}` | AuditCostController::byInvoice |

## Flujo principal — Auditoría IA (event-driven)

Pipeline event-driven sobre Redis Streams (post AUDIT-013/014/015). Cada etapa es un worker independiente que consume un evento y publica el siguiente:

```
1. POST /audit/single → AuditController::single
   └─ publica `audit_created` en stream `audit.inbox.priority` (202 con audit_id)

2. DocumentAuditOrchestrator (group: orchestrator)
   ├─ resuelve FDV, audit-config y adjuntos mediante AuditDataService sobre modelos PHP
   ├─ reconcilia globalmente documentos lógicos ↔ adjuntos físicos (nombre, ID corroborado, alias único)
   ├─ missing/ambiguous/no-content/reused → document_rejected category=DOCUMENT_MAPPING
   ├─ construye `extraction_contract` con response_schema desde audit-config
   └─ publica N × `document_registered` en `audit.documents.priority` o `audit.documents.batch`

3. AttachmentDownloadWorker (group: downloaders, default ×12 réplicas)
   ├─ descarga adjunto (Drive URL o BLOB)
   ├─ exige transferencia BLOB completa (`bytes === DATALENGTH`)
   ├─ admite BLOB temporal en presupuesto global Redis por bytes/entradas cuando cabe
   └─ publica `document_downloaded`; fallos técnicos se propagan a DLQ

4. DocumentExtractionWorker (group: extractors, default VIP×2 y batch×12)
   ├─ consume `document_downloaded`
   ├─ recupera caché faltante desde origen y verifica el mismo SHA256 de base64
   ├─ valida integridad estructural con `DocumentIntegrityValidator`
   ├─ delega estado transitorio y cache a `ExtractionCacheManager`
   ├─ productor exclusivo de `document_rejected` por contenido comprobado
   ├─ si es válido: construye prompts dinámicos con `ExtractionPromptBuilder`
   ├─ Gemini Structured Outputs (responseSchema y application/json)
   └─ parsea y rehidrata con `GeminiResponseParser` → publica `document_extracted`
      y libera el BLOB después de publicar extracción/rechazo

5. DocumentNormalizer (group: normalizers)
   ├─ fechas ISO, upper sin tildes, numéricos canónicos, null para vacío
   └─ publica `document_normalized`

6. RulesEvaluationWorker (group: policy)
   ├─ DocumentPolicyEngine: COINCIDE/VALOR_DISTINTO/NO_ENCONTRADO/OMITIDO/NO_CONCLUYENTE
   ├─ valida por separado mapping (orquestador) y contenido (extractor)
   ├─ mapping inválido genera hallazgo MAP sin pasar por Gemini
   ├─ SemanticMatchJudge como fallback de homologación semántica contextual (productos y personas)
   └─ cuando docs_done + docs_rejected >= docs_total y docs_evaluated >= docs_total, encola `rules_evaluated` mediante `AuditPersistenceQueue`

7. AuditPersistenceWorker (group: persistence, default Compose ×12 réplicas)
   ├─ AuditResultData + documentDecisions
   ├─ un turno por slot; default 2 slots fijados en Redis por job
   ├─ recupera metadata de slots desde scopes sin reparticionar pendientes
   ├─ bloquea `DOWNLOAD_ERROR` y contratos de rechazo inválidos
   ├─ AuditResultPersistenceModel.persist()
   │    → PDO fresco + transacción idempotente dual con retry 1/5/30 s
   └─ publica `audit_completed` | `audit_failed` | `batch_completed[_with_errors]`

Reintentos por evento → DLQ (`audit.dlq`) tras AUDIT_EVENT_MAX_RETRIES (3).
```

Las etapas desde orquestación hasta finalización usan `AuditEvent::followUp()` para
conservar `source`, `is_priority`, identidad y correlación del evento padre sin
mezclar el payload de negocio anterior. Cada llamada declara `documentId`
obligatoriamente: ID documental o null explícito para consolidación. La persistencia usa streams
`audit.persistence.priority` / `audit.persistence.batch`; los terminales usan
`audit.results.priority` / `audit.results.batch`. `audit_failed` se emite desde el
consumer base. El contrato JSON activo se mantiene y los eventos históricos sin
metadatos siguen en batch; consultar el procedimiento de recuperación en
`plans/features/audit-workflow.md`.

Cada consumer declara tipos de entrada y ACK de eventos ajenos sin reescribir
telemetría. Trabajo terminal se omite después de recuperar cierres DLQ; persistencia
conserva liberación de slots. Retención de trabajo respeta todos los grupos/PEL.
Reprocesar DLQ crea un intento independiente e idempotente con referencia a la
auditoría/job fallidos, sin reabrir historia. La ejecución durable v2 y la
homologación semántica agrupada constituyen el protocolo único y predeterminado.



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

- **Conexión cacheada**: `Database::getConnection()` — reservada para consumidores puntuales como health.
- **Retry por operación**: `SqlServerConnectionExecutor` abre PDO fresco y solo reproduce lecturas o escrituras idempotentes.
- **Inyección pragmática**: servicios y workers reciben colaboradores concretos por constructor con defaults de producción; el runtime actual no declara interfaces propias para `AuditDataService` ni `AttachmentDownloadService`.
- **Template Method**: `AuditEventConsumer` (XREADGROUP + ack + retry + DLQ; subclases sólo implementan `handle()`).
- **Builder dinámico**: `DocumentExtractionContractBuilder` arma `extract_fields`, `extract_items`, `detect_visual_checks` y `assess_document_quality` desde `audit-config`.
- **Chain of Responsibility**: Middleware pipeline en `Core\Router`.
- **Facade**: `Response::success()` / `Response::error()`.
- **Retry with Backoff**: `GeminiGateway` contiene el circuit breaker; no existe una clase separada `GeminiCircuitBreaker`.
- **Idempotencia con scripts Lua**: `AuditStateStore` (`STORE_RULES_EVALUATION_LUA`, `COMPLETE_AUDIT_LUA`, `REGISTER_DOCUMENT_LUA`).

## Instrucciones para el agente

Las réplicas anteriores son defaults de Compose; las variables de producción pueden sobrescribirlas. Comprobar siempre el runtime antes de diagnosticar capacidad.
La [SDD de homologación asíncrona y ejecución justa](../../../plans/sdd-homologacion-semantica-asincrona-ejecucion-justa.md) documenta la implementación local v2 y sus requisitos de activación. Comprobar `executionVersion` en `/metrics/async` y el marcador Redis antes de atribuir mejoras al scheduler justo. Implementación local, protocolo activo y despliegue productivo son evidencias distintas.

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
