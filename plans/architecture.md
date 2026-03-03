# Arquitectura — AudFact

## Visión General

AudFact sigue una arquitectura **MVC monolítica escalable horizontalmente** con un framework PHP custom. La aplicación cuenta con un balanceador **Nginx (`least_conn`)** que reparte el tráfico sobre **múltiples réplicas Docker de PHP-FPM (static pool)** y se comunica con SQL Server para datos, Gemini API para IA, y Google Drive para almacenamiento documental. La arquitectura soporta **Alta Disponibilidad (HA)** aislando recursos compartidos (como logs multi-nodo) para evitar race-conditions en concurrencia.

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
| `RateLimit.php` | Rate limiting por IP (archivo) |

**Dependencias**: Ninguna externa (framework standalone).
**Interfaz**: Cada módulo es invocado desde `public/index.php` o los controladores.

---

### Controllers (`app/Controllers/`)

| Controlador | Responsabilidad | Modelo |
|---|---|---|
| `Controller.php` | Base — `validate()`, manejo de errores | — |
| `HealthController.php` | Health check (`GET /health`) | — |
| `ClientsController.php` | CRUD clientes/EPS | `ClientsModel` |
| `InvoicesController.php` | Búsqueda de facturas | `InvoicesModel` |
| `AttachmentsController.php` | Descarga/previsualización de documentos (BLOB/URL) con detección MIME por magic bytes | `AttachmentsModel` |
| `DispensationController.php` | Datos de dispensación | `DispensationModel` |
| `AuditController.php` | Orquestador de auditoría IA | Todos los modelos |

**Dependencias**: `core/Validator`, `core/Response`, `core/Logger`, Modelos.
**Interfaz**: REST JSON vía `app/Routes/web.php`.

---

### Models (`app/Models/`)

| Modelo | Tabla/Vista Principal | Operaciones |
|---|---|---|
| `Model.php` | Base — CRUD genérico | `all()`, `find()`, `create()`, `update()`, `delete()` |
| `ClientsModel.php` | `NIT` + `Clientes` | `getClientById()`, `getAllClients()` |
| `InvoicesModel.php` | `factura` + `AudDispEst` | `getInvoices()` |
| `AttachmentsModel.php` | `AdjuntosDispensacion` + `NitDocumentos` + `DispensacionDetalleServicio` | `getAttachmentsByInvoiceId()`, `getAttachmentByIdForDispensation()`, `getAttachmentBlobStreamByIdForDispensation()` |
| `DispensationModel.php` | `vw_discolnet_dispensas` | `getDispensationData()` |

**Dependencias**: `core/Database` (PDO sqlsrv).
**Interfaz**: Invocados por Controllers y Worker.

---

### Servicios de Auditoría IA (`app/Services/Audit/`)

| Servicio | Responsabilidad |
|---|---|
| `AuditFileManager.php` | Resolución de archivos: BLOB → memoria (optimizado, sin disco), URL → download vía Drive |
| `AuditPromptBuilder.php` | Ingeniería de prompts: Philosophy + System Instruction v3.0 (4 capas con axiomas deterministas) |
| `AuditResponseSchema.php` | Definición del JSON schema esperado de Gemini |
| `AuditResultValidator.php` | Validación de la respuesta contra el schema |
| `JsonRepairHelper.php` | Reparación de JSON truncado/malformado |
| `JsonResponseParser.php` | Parseo robusto de respuestas Gemini |

**Dependencias**: Guzzle HTTP, `core/Logger`.
**Interfaz**: Invocados por `AuditOrchestrator`.

---

### Orchestrator (`app/Services/Audit/AuditOrchestrator.php`)

**Responsabilidad**: Orquesta el pipeline completo de auditoría IA.

**Flujo**:
1. Recibe factura + datos de dispensación
2. Resuelve archivos adjuntos (BLOB/URL)
3. Construye prompt con datos + archivos
4. Envía a Gemini Flash API
5. Parsea y valida respuesta JSON
6. Retorna resultado estructurado

**Dependencias**: Todos los servicios de `Audit/`, Guzzle HTTP, `core/Logger`.

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
| Base actual | `docker-compose.yml` | Mantiene la topología HA como configuración principal del repositorio. |

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
| Hostname Logging | Evita que X réplicas corrompan JSON log entries escribiendo al unísono sobre el mount compartido `app-YYYY-MM-DD.log`. |

## Integraciones Externas

| Servicio | Protocolo | Autenticación |
|---|---|---|
| Google Gemini API | HTTPS REST | API Key (env `GEMINI_API_KEY`) |
| Google Drive | HTTPS REST | JWT Service Account (env `GOOGLE_*`) |
| SQL Server | TCP/TDS | User/Password (env `DB_*`) |
