# Arquitectura — AudFact

## Visión General

AudFact sigue una arquitectura **desacoplada**. Cuenta con un **Frontend SPA moderno** construido en **Next.js (React)** que consume un **Backend REST API en PHP (custom MVC)**. El backend cuenta con un balanceador **Nginx (`least_conn`)** que reparte el tráfico sobre **múltiples réplicas Docker de PHP-FPM (static pool)** y se comunica con SQL Server para datos, Gemini API para IA, y Google Drive para almacenamiento documental. La arquitectura soporta **Alta Disponibilidad (HA)** aislando recursos compartidos (como logs multi-nodo) para evitar race-conditions en concurrencia.

---

## Desglose de Componentes

### Core Framework (`core/`)

| Archivo | Responsabilidad |
|---|---|
| `Router.php` | Despacho de rutas, manejo de métodos HTTP |
| `Route.php` | Registro de rutas con middleware |
| `Database.php` | Fábrica de conexiones PDO nombradas; expone conexión cacheada para health/HTTP y apertura fresca para operaciones SQL |
| `SqlServerConnectionExecutor.php` | Ejecuta cada operación con PDO fresco, clasifica fase/modo y aplica retry acotado solo cuando el replay es seguro |
| `SqlServerOperationMode.php` | Modos `READ`, `IDEMPOTENT_WRITE` y `NON_REPLAYABLE_WRITE` |
| `SqlServerOperationException.php` | Excepción técnica sanitizada con conexión, fase, modo, intentos y SQLSTATE |
| `Middleware.php` | Pipeline de middleware |
| `Validator.php` | Validación de datos de entrada |
| `Response.php` | Respuestas JSON estandarizadas |
| `Logger.php` | Logging estructurado con rotación diaria |
| `Env.php` | Carga de `.env` por entorno |
| `RateLimit.php` | Rate limiting por IP (APCu con fallback a archivos) |

**Dependencias**: Ninguna externa (framework standalone).
**Interfaz**: Cada módulo es invocado desde `public/index.php` o los controladores.

---

### Controllers (`app/Controllers/`)

| Controlador | Responsabilidad | Modelo |
|---|---|---|
| `Controller.php` | Base — `validate()`, manejo de errores | — |
| `HealthController.php` | Health check (`GET /health`) | — |
| `ObservabilityController.php` | Métricas async del pipeline Redis (`GET /metrics/async`) | Redis |
| `ConfigController.php` | Configuración pública del frontend (`GET /config/public`) | — |
| `ClientsController.php` | CRUD clientes/EPS | `ClientsModel` |
| `AuditConfigController.php` | Configuración dinámica de auditoría por cliente | `AuditConfigModel` |
| `InvoicesController.php` | Búsqueda de facturas | `InvoicesModel` |
| `AttachmentsController.php` | Descarga/previsualización de documentos (BLOB/URL) con detección MIME por magic bytes | `AttachmentsModel` |
| `DispensationController.php` | Datos de dispensación | `DispensationModel` |
| `AuditController.php` | Orquestador de auditoría IA + resultados persistidos | Todos los modelos |
| `AuditDlqController.php` | Consulta y reproceso de eventos DLQ | Redis |

**Dependencias**: `core/Validator`, `core/Response`, `core/Logger`, Modelos.
**Interfaz**: REST JSON vía `app/Routes/web.php`.

---

### Models (`app/Models/`)

| Modelo | Tabla/Vista Principal | Operaciones |
|---|---|---|
| `Model.php` | Base de ejecución SQL | Callbacks `read()`, `idempotentWrite()` y `nonReplayableWrite()` sin retener PDO |
| `ClientsModel.php` | `NIT` + `Clientes` | `getClientById()`, `getAllClients()` |
| `InvoicesModel.php` | `Factura` + dispensación/kardex | `searchInvoices()`/`countInvoices()` exponen búsqueda interactiva paginada; `getInvoicesForAuditBatch()` usa keyset interno para batches y selecciona `DisId` como llave canónica |
| `AttachmentsModel.php` | `AdjuntosDispensacion` + `NitDocumentos` + `DispensacionDetalleServicio` | Metadata, stream HTTP y materialización BLOB para pipeline con `bytes === DATALENGTH` en la misma consulta |
| `DispensationModel.php` | `vw_discolnet_dispensas` | `getDispensationData()` expone `facsecF AS FacSec` |
| `AuditConfigModel.php` | `AudDisp` + `AudDispCampo` + `NitDocumentos` | `getConfig()`, `saveConfig()` |
| `AuditStatusModel.php` | `Discolnet.dbo.AudDispEst` | `searchAuditSummaries()`, `getAuditDetailByFacNro()`, `getTimingsByFacNro()` |
| `AuditResultPersistenceModel.php` | `Discolnet.dbo.AudDispEst` + `AdjuntosDispensacion` + `DispensacionDetalleServicio` | `persist()` transaccional y `updateFinalTimings()` sin read-before-write |

**Dependencias**: `core/SqlServerConnectionExecutor` sobre `core/Database` (PDO sqlsrv). Los modelos no conservan conexiones entre eventos.
**Interfaz**: Invocados por Controllers y Worker.

---

### Servicios de Auditoría IA (`app/Services/Audit/`)

| `ResponseIADiskStore.php` | Persistencia en disco de payloads de la IA para trazabilidad (solo `development`) |

**Dependencias**: Guzzle HTTP, `core/Logger`, `core/RedisClient`.
**Interfaz**: Invocados por los Workers del Pipeline o directamente desde Controladores.

---

### Pipeline Event-Driven (`app/Services/Audit/Pipeline/`)

| Worker / Componente | Responsabilidad |
|---|---|
| `AuditEvent.php` | Value-object inmutable de evento (tipos, payload, UUID v4, timestamps ISO 8601) |
| `AuditEventPublisher.php` | Publica a `audit.batch.inbox`, `audit.inbox`, `audit.documents`, `audit.results` y `audit.dlq`; rechaza `rules_evaluated` para impedir bypass del scheduler |
| `AuditEventConsumer.php` | Base abstracta: `XREADGROUP`, recuperación de `pending`, ack, reintentos, envío a DLQ, cierre terminal y telemetría; agotamiento SQL y fallos técnicos de descarga terminan con DLQ/ACK en la misma entrega |
| `AuditStateStore.php` | Claves Redis de estado de auditoría individual (`audit:{id}:*`, contadores, `event_timings`, `aggregation_timings`) |
| `BatchJobStore.php` | Claves Redis de jobs/reservas y transición atómica de métricas según el estado anterior del job, incluyendo terminal directo de lotes de una auditoría |
| `AuditDataService.php` + `AttachmentDownloadService.php` | Acceso directo a FDV, adjuntos y catálogo sin HTTP loopback; distingue fuente ausente/vacía, transferencia incompleta y fallo externo como errores técnicos |
| `BatchRequestedWorker.php` | Worker: consume `batch_requested` de `audit.batch.inbox`, realiza la consulta SQL pesada, efectúa reservas idempotentes en Redis por `DisId`, y publica eventos `audit_created` en `audit.inbox` |
| `DocumentAuditOrchestrator.php` | Consume `audit_created`, ejecuta la reconciliación global 1:1 y publica `document_registered` solo para matches; registra y publica `document_rejected` para fallos de mapping sin invocar Gemini |
| `DocumentAttachmentMatcher.php` | Matcher puro y determinista en tres pasadas: nombre exacto normalizado, ID corroborado y alias único |
| `DocumentAttachmentMatchResult.php` | DTO readonly que impide `logical_doc_id` o `attachment_id` duplicados en matches |
| `DocumentMappingRejectionReason.php` | Taxonomía cerrada de rechazos `DOCUMENT_MAPPING`: missing, ambiguous, no content y reused |
| `AttachmentDownloadWorker.php` | Worker: consume `document_registered`, descarga y almacena el adjunto en Redis; publica `document_downloaded` y propaga cualquier fallo técnico sin crear decisiones documentales |
| `DocumentRejectionReason.php` | Allowlist cerrada de razones de contenido válidas para `document_rejected` |
| `DocumentIntegrityValidator.php` | Gate de integridad estructural (magic bytes, tamaño y MIME) para validar documentos pre-Gemini |
| `DocumentExtractionWorker.php` | Productor exclusivo de `document_rejected` de categoría de contenido; consume bytes, valida integridad y extrae con Gemini |
| `DocumentNormalizer.php` | Worker: consume `document_extracted`, normalización determinística PHP, publica `document_normalized` |
| `RulesEvaluationWorker.php` | Consume `document_normalized` y valida por separado rechazos de contenido y mapping; estos últimos generan hallazgo `MAP`, severidad alta y resultado `RECHAZADO` |
| `AuditPersistenceQueue.php` | Único productor de `audit.persistence:{queue}`; scheduler Redis/Lua que deduplica y mantiene un turno activo por job |
| `DocumentPolicyEngine.php` | Orquestador de la evaluación de políticas de documento |
| `VisualCheckEvaluator.php` | Evaluación de discrepancias visuales vs legibles |
| `FieldValueResolver.php` | Resolución tipada del valor extraído según `AuditFieldValueType` |
| `AuditTimingSummarizer.php` | Cálculo de latencias de pipeline, telemetría por stream y tiempos de agregación |
| `AuditPersistenceWorker.php` | Worker: bloquea decisiones contaminadas (`DOWNLOAD_ERROR` o contrato de rechazo inválido), persiste SQL, libera el turno, recalcula timings y publica el terminal |

**Dependencias**: Todo el stack de IA, base de datos y Redis.
**Interfaz**: Invocados vía CLI (`php bin/audit-worker.php <worker_name>`). En `docker-compose.yml`, el launcher `bin/audit-worker.php` levanta servicios independientes parametrizados por `.env`: `batch=2`, `orchestrator=3`, `downloader=8`, `extraction=8`, `normalizer=1`, `policy=2`, `persistence=3`. La recuperación de mensajes `pending` se controla con `AUDIT_PENDING_RECLAIM_IDLE_MS` y `AUDIT_PENDING_RECLAIM_INTERVAL_MS`.


---

### MCP Integration (`app/wrap/`)

| Archivo | Responsabilidad |
|---|---|
| `webhook.php` | Entry point MCP (JSON-RPC 2.0) |
| `capabilities.php` | Declaración de tools disponibles |
| `core/MCPServer.php` | Servidor MCP (routing de tools) |
| `core/ApiClient.php` | Cliente HTTP interno (llama a la API REST) |
| `core/tools/*.php` | 4 tools: GetClients, GetInvoices, GetDispensation, GetAttachments |

**Dependencias**: API REST interna (vía `ApiClient`).
**Interfaz**: JSON-RPC 2.0 vía `POST /wrap/webhook.php`.

---

## Modos de Ejecución Docker

| Modo | Compose | Descripción |
|---|---|---|
| Local | `docker-compose.yml` | Build desde repo: `php` x5, `redis`, `nginx` y workers `batch`, `orchestrator`, `downloader`, `extraction`, `normalizer`, `policy`, `persistence`. |
| Producción LAN | `docker-compose.yml` + `--profile frontend` | Imágenes GHCR para frontend, PHP, Nginx y los 7 workers (`batch`, `orchestrator`, `downloader`, `extraction`, `normalizer`, `policy`, `persistence`). |

---

## Decisiones de Diseño

| Decisión | Justificación |
|---|---|
| Framework PHP custom | Control total sobre el pipeline, sin overhead de frameworks grandes |
| PDO sqlsrv | Acceso nativo a SQL Server con prepared statements |
| Un turno SQL por job | Evita head-of-line blocking entre jobs sin eliminar la doble persistencia exigida por dominio |
| `aggregation` como nombre de telemetría | Compatibilidad temporal limitada al contrato del DAG. Responsable: Pipeline/Frontend. Retiro: migrar schema y store UI a `persistence`. Validación: `ObservabilityControllerTest` + typecheck frontend. El runtime y las clases no conservan el agregador |
| Gemini Flash (no Pro) | Balance costo/velocidad para análisis multimodal masivo |
| Dual storage (BLOB + Drive URL) | Compatibilidad con documentos legacy (BLOB) y nuevos (Drive) |
| MCP como capa separada | Reutiliza la API REST existente sin duplicar lógica |
| Docker multi-container | Separación Nginx/PHP-FPM para escalabilidad independiente de las fases de request/processing |
| Load Balancing (Nginx least_conn) | El tráfico a Gemini es variable en tiempo (5s a 25s). `least_conn` asegura que Nginx no envíe N peticiones pesadas a la misma réplica estática. |
| PHP-FPM (Static Pool) | Evita overhead the spawn processes (Dynamic/Ondemand) bajo peaks de carga concurrente. Asigna inmediatamente memoria a procesos `www-data` para latencias consistentes. |
| Nginx Bundle (Assets) | Elimina el bind mount de la carpeta `public/`. Los assets se inyectan en el build de Nginx, garantizando que el servidor web sea una unidad inmutable y atómica. |
| Zero-Source Host | El Runner de CI/CD elimina todo rastro de código fuente, `.git` y docs del host tras el despliegue exitoso (`Host Purge`), dejando solo `.env`, `docker-compose.yml` y `logs/`. |
| PHP Artifact Purge | El Dockerfile de PHP elimina `composer.json`, `docker/` y archivos de orquestación tras el build para reducir la superficie de ataque dentro del contenedor. |

## Integraciones Externas

| Servicio | Protocolo | Autenticación |
|---|---|---|
| Google Gemini API | HTTPS REST | API Key (env `GEMINI_API_KEY`) |
| Google Drive | HTTPS REST | JWT Service Account (env `GOOGLE_*`) |
| SQL Server | TCP/TDS | User/Password (env `DB_*`) |
