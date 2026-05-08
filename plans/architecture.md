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
| `Database.php` | Singleton PDO (sqlsrv/mysql) |
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
| `Model.php` | Base — CRUD genérico | `all()`, `find()`, `create()`, `update()`, `delete()` |
| `ClientsModel.php` | `NIT` + `Clientes` | `getClientById()`, `getAllClients()` |
| `InvoicesModel.php` | `vw_discolnet_dispensas` + `vw_discolnet_facturas` | `getInvoices()` (pendiente por KarUni=0) |
| `AttachmentsModel.php` | `AdjuntosDispensacion` + `NitDocumentos` + `DispensacionDetalleServicio` | `getAttachmentsByInvoiceId()`, `getAttachmentByIdForDispensation()`, `getAttachmentBlobStreamByIdForDispensation()` |
| `DispensationModel.php` | `vw_discolnet_dispensas` | `getDispensationData()` |
| `AuditConfigModel.php` | `AudDisp` + `AudDispCampo` + `NitDocumentos` | `getConfig()`, `saveConfig()` |
| `AuditStatusModel.php` | `Discolnet.dbo.AudDispEst` + `AdjuntosDispensacion` | `getByFacSec()`, `upsertAuditResult()`, `updateAuditResult()` |

**Dependencias**: `core/Database` (PDO sqlsrv).
**Interfaz**: Invocados por Controllers y Worker.

---

### Servicios de Auditoría IA (`app/Services/Audit/`)

| Componente | Responsabilidad |
|---|---|
| `AuditBatchOrchestrator.php` | Orquestación de encolamiento asíncrono (batch), slots y rollback transaccional |
| `AuditFindingRules.php` | Reglas compartidas para normalización, severidad, métricas y risk score |
| `AuditComparisonType.php` | Enum de tipos de comparación (exact/semantic/visual/business) + detección de tipo por convención |
| `AuditFieldValueType.php` | Enum de tipo de dato por campo para normalización y estrategias de comparación |
| `AuditFindingResult.php` | Enum de resultados canónicos (`COINCIDE`, `VALOR_DISTINTO`, `NO_ENCONTRADO`, `OMITIDO`, `NO_CONCLUYENTE`) |
| `AuditSeverity.php` | Enum de severidades normalizadas (alta/media/baja) |
| `DocumentQuality.php` | Enum de calidad documental normalizada |
| `GeminiConfig.php` | Value Object inmutable con parámetros de generación del modelo + factory `fromEnv()` |
| `GeminiCallMetrics.php` | Normalización de métricas no sensibles de llamadas Gemini (latencia, tokens, cache hits) |
| `GeminiGateway.php` | Cliente HTTP para Gemini API con retry, timeout, factory `create()` y manejo de errores |
| `SemanticMatchJudge.php` | Juez semántico con Gemini para campos de similitud textual |
| `ResponseIADiskStore.php` | Persistencia en disco de payloads de la IA para trazabilidad (solo `development`) |

**Dependencias**: Guzzle HTTP, `core/Logger`, `core/RedisClient`.
**Interfaz**: Invocados por los Workers del Pipeline o directamente desde Controladores.

---

### Pipeline Event-Driven (`app/Services/Audit/Pipeline/`)

| Worker / Componente | Responsabilidad |
|---|---|
| `AuditEvent.php` | Value-object inmutable de evento (tipos, payload, UUID v4, timestamps ISO 8601) |
| `AuditEventPublisher.php` | Publica a `audit.inbox`, `audit.documents`, `audit.results` y `audit.dlq` |
| `AuditEventConsumer.php` | Base abstracta: `XREADGROUP`, ack, reintentos y envío a DLQ automático |
| `AuditStateStore.php` | Claves Redis de estado de auditoría individual (`audit:{id}:*`, contadores) |
| `BatchJobStore.php` | Claves Redis de estado de jobs/batch (`job:{id}:*`, slots, progreso) |
| `AuditDataService.php` + `AttachmentDownloadService.php` | Acceso directo a FDV, adjuntos y catálogo sin HTTP loopback |
| `DocumentAuditOrchestrator.php` | Worker: consume `audit_created`, construye schema Gemini, publica N `document_registered` |
| `DocumentExtractionWorker.php` | Worker: consume `document_registered`, descarga adjunto, cache por hash, extrae con Gemini |
| `DocumentNormalizer.php` | Worker: consume `document_extracted`, normalización determinística PHP, publica `document_normalized` |
| `RulesEvaluationWorker.php` | Worker: consume `document_normalized`, evalúa policy, publica `rules_evaluated` |
| `DocumentPolicyEngine.php` | Motor determinista de evaluación por documento |
| `AuditAggregationWorker.php` | Worker: consume `rules_evaluated`, agrega resultados, persiste en SQL, publica `audit_completed` |

**Dependencias**: Todo el stack de IA, base de datos y Redis.
**Interfaz**: Invocados vía CLI (`php bin/audit-worker.php <worker_name>`).

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
| Desarrollo | `docker-compose.dev.yml` | Topología simple (1 PHP-FPM + 1 Nginx con `docker/nginx.conf`). |
| HA / Stress | `docker-compose.ha.yml` | Topología HA (5 réplicas PHP-FPM + Nginx con `docker/nginx-ha.conf.template`). |
| Base actual | `docker-compose.yml` | Mantiene la topología HA. Implementa **Lean Production 3.0**: Nginx es un bundle inmutable (incluye assets) y PHP se purga de artefactos tras el build. El host de producción es **Zero-Source** (solo orquestación y secretos). |

---

## Decisiones de Diseño

| Decisión | Justificación |
|---|---|
| Framework PHP custom | Control total sobre el pipeline, sin overhead de frameworks grandes |
| PDO sqlsrv | Acceso nativo a SQL Server con prepared statements |
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
