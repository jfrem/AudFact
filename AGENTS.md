# AudFact — Repository Guidelines

- Repo local: `c:\Users\USER\Desktop\AudFact`
- Idioma de toda interacción: **Español (Latinoamérica)**
- Runtime: PHP 8.2-FPM + Nginx 1.25 + frontend Next.js en Docker
- Base de datos: SQL Server (PDO `sqlsrv`)
- IA: Google Gemini API (multimodal)

---

## Estructura del Proyecto y Organización de Módulos

- **Código fuente**: `core/` (framework), `app/` (aplicación MVC)
- **Controladores**: `app/Controllers/` — Un controlador por recurso REST.
- **Modelos**: `app/Models/` — Acceso a datos vía PDO, queries embebidas.
- **Servicios**: `app/Services/` (audit pipeline event-driven y servicios de dominio).
- **Rutas**: `app/Routes/web.php` — Definición centralizada de endpoints.
- **Punto de entrada**: `public/index.php` — Bootstrap, CORS, rate limit, dispatch.
- **MCP Integration**: `app/wrap/` — Webhook y herramientas para agentes IA.
- **Docker**: `docker/` (Dockerfile, nginx.Dockerfile, frontend.Dockerfile, nginx.conf), `docker-compose.yml`
- **Tests**: `tests/` — Pruebas unitarias/integración (PHPUnit)
- **Logs**: `logs/` — Rotación automática por `Core\Logger` (Mount persistente en host)
- **Docs/Plans**: `plans/` — Documentación y planificación fuente de verdad para los agentes. La documentación para humanos se genera con Docusaurus y se sirve en `/docs/`.
- **Zero-Source**: El Host de producción solo contiene orquestación y secretos. El código vive dentro de las imágenes.

### Skills disponibles

El proyecto tiene skills en `.agent/skills/`. Consultar `CATALOG.md` para el mapeo archivo → skill.

| Skill                             | Área              | Cuándo usar                                                                                           |
| --------------------------------- | ----------------- | ----------------------------------------------------------------------------------------------------- |
| `audfact-project-overview`        | Contexto          | Arquitectura, flujos, dependencias                                                                    |
| `audfact-api-rest`                | REST API          | Rutas, controladores, validación                                                                      |
| `audfact-audit-gemini`            | Auditoría IA      | Pipeline Gemini, prompts, schemas                                                                     |
| `audfact-sqlsrv-models`           | SQL Server        | Modelos, queries, BLOBs                                                                               |
| `audfact-mcp-wrap`                | MCP               | Webhook, herramientas, ApiClient                                                                      |
| `audfact-runtime-docker`          | Docker/Ops        | Contenedores, Nginx, conectividad                                                                     |
| `audfact-production-ops`          | Producción LAN    | SSH a `admon@172.16.0.3`, diagnósticos, runner self-hosted, GitHub Secrets/Variables, deploy/rollback |
| `audfact-security-guardrails`     | Seguridad         | Rate limit, CORS, sanitización                                                                        |
| `audfact-docs-sync`               | Documentación     | Sincronización de `README.md`, `plans/*`, `CHANGELOG.md` y skills                                     |
| `audit-skill-router`              | Auditoría técnica | Enrutamiento de auditorías amplias/ambiguas a dominios especializados                                 |
| `architecture-assessment`         | Auditoría técnica | Evaluación de arquitectura, acoplamiento y escalabilidad                                              |
| `code-quality-assessment`         | Auditoría técnica | Evaluación de calidad de código, mantenibilidad y deuda técnica                                       |
| `security-assessment`             | Auditoría técnica | Auditoría de seguridad para readiness de release                                                      |
| `technical-governance-assessment` | Auditoría técnica | Evaluación de madurez de gobernanza técnica                                                           |
| `next-best-practices`             | Frontend Next.js  | Convenciones de App Router, Server Components, datos, errores y self-hosting                           |
| `next-cache-components`           | Frontend Next.js  | Caché y PPR únicamente durante una migración confirmada a Next.js 16+                                  |
| `next-upgrade`                    | Frontend Next.js  | Actualización incremental de Next.js con guías oficiales y codemods                                    |
| `impeccable`                      | UI/UX             | Diseño, auditoría y refinamiento de interfaces frontend                                                |
| `clean-rebuild-policy`            | Gobernanza técnica | Clean rebuild, eliminación de legacy y límites estrictos de MVP                                       |
| `write-sdd-spec`                  | Especificación    | Diseño técnico determinista, trazabilidad, migración y rollback antes de implementar                   |
| `phpunit-test-architect`          | Testing / TDD     | Contratos ejecutables y suites unitarias completas para PHP 8.2+ con PHPUnit 10+                       |

**Antes de modificar un archivo**, consultar la skill correspondiente según la tabla en `CATALOG.md`.
Después de modificar una skill o sus registros, ejecutar `node .agent/skills/_shared/scripts/validate-skills.mjs`.

---

## Mapa de Endpoints REST

> El router gestiona estas rutas en `app/Routes/web.php`. Los parámetros entre `{}` son dinámicos.

| Método | Endpoint                                                        | Controlador               | Acción                   | Descripción                                                                  |
| ------ | --------------------------------------------------------------- | ------------------------- | ------------------------ | ---------------------------------------------------------------------------- |
| `GET`  | `/`                                                             | `Controller`              | `index`                  | Bienvenida / Status API                                                      |
| `GET`  | `/health`                                                       | `HealthController`        | `status`                 | Health check (Docker/System)                                                 |
| `GET`  | `/metrics/async`                                                | `ObservabilityController` | `asyncMetrics`           | Métricas operativas del pipeline async                                       |
| `GET`  | `/config/public`                                                | `ConfigController`        | `publicConfig`           | Configuración pública del frontend                                           |
| `GET`  | `/clients`                                                      | `ClientsController`       | `index`                  | Listar todos los clientes/EPS                                                |
| `GET`  | `/clients/{clientId}`                                           | `ClientsController`       | `show`                   | Detalle de un cliente específico                                             |
| `GET`  | `/clients/{clientId}/documents`                                 | `ClientsController`       | `documents`              | Catálogo documental requerido por cliente                                    |
| `POST` | `/clients`                                                      | `ClientsController`       | `lookup`                 | Buscar cliente por filtros                                                   |
| `GET`  | `/clients/{clientId}/audit-config`                              | `AuditConfigController`   | `show`                   | Configuración dinámica de auditoría por cliente                              |
| `POST` | `/clients/{clientId}/audit-config`                              | `AuditConfigController`   | `save`                   | Guardar/reemplazar configuración dinámica de auditoría                       |
| `GET`  | `/audit/field-catalog`                                          | `AuditConfigController`   | `catalog`                | Catálogo de campos disponibles para configurar auditorías                    |
| `GET`  | `/invoices`                                                     | `InvoicesController`      | `index`                  | Buscar facturas pendientes con paginación `page`/`pageSize`                  |
| `POST` | `/invoices`                                                     | `InvoicesController`      | `search`                 | Buscar facturas por fecha/nit con contrato paginado                          |
| `GET`  | `/dispensation/{DisId}/{DisDetNro}`                             | `DispensationController`  | `show`                   | Detalle técnico de una dispensa                                              |
| `POST` | `/dispensation`                                                 | `DispensationController`  | `lookup`                 | Buscar dispensa por llave compuesta `DisId` + `DisDetNro`                    |
| `GET`  | `/dispensation/{disDetNro}/attachments/download/{attachmentId}` | `AttachmentsController`   | `downloadByDispensation` | Descargar/previsualizar adjunto                                              |
| `GET`  | `/dispensation/{disDetNro}/attachments/{nitSec}`                | `AttachmentsController`   | `showByDispensation`     | Listar metadatos de adjuntos                                                 |
| `GET`  | `/audit/results`                                                | `AuditController`         | `results`                | Resumen paginado de auditorías persistidas                                   |
| `GET`  | `/audit/results/{facNro}`                                       | `AuditController`         | `resultDetail`           | Detalle persistido de una auditoría por FacNro                               |
| `GET`  | `/audit/stats`                                                  | `AuditController`         | `stats`                  | Conteos agregados para dashboard                                             |
| `GET`  | `/audit/documents-history`                                      | `AuditController`         | `documentsHistory`       | Historial de documentos auditados por IA                                     |
| `POST` | `/audit/single`                                                 | `AuditController`         | `single`                 | **Pipeline IA**: Auditoría individual por `disDetNro` (con `disId` opcional) |
| `POST` | `/audit/async`                                                  | `AuditController`         | `async`                  | **Pipeline IA**: Auditoría en lote asíncrona (Redis Queue) → 202             |
| `GET`  | `/audit/jobs`                                                   | `AuditController`         | `jobsList`               | Listado y resumen de jobs batch recientes con enriquecimiento de clientes    |
| `GET`  | `/audit/jobs/{jobId}`                                           | `AuditController`         | `jobStatus`              | Estado y progreso de job asíncrono                                           |
| `GET`  | `/audit/status/{auditId}`                                       | `AuditController`         | `status`                 | Estado Redis de auditoría individual                                         |
| `GET`  | `/audit/dlq`                                                    | `AuditDlqController`      | `index`                  | Listado administrativo de eventos `dead_letter`                              |
| `POST` | `/audit/dlq/reprocess`                                          | `AuditDlqController`      | `reprocess`              | Reproceso administrativo de un evento DLQ                                    |
| `GET`  | `/audit/{facNro}/timings`                                       | `AuditController`         | `timings`                | Timings persistidos por factura                                              |
| `GET`  | `/audit/{auditId}/flow-stream`                                  | `AuditFlowController`     | `stream`                 | Telemetría SSE en vivo por auditoría                                         |

---

## Flujo de Datos / Request Lifecycle

El sistema sigue un pipeline secuencial para cada petición HTTP:

### 1. Entrada (Nginx & PHP-FPM)

- La petición llega al puerto `:8080` (Host).
- Nginx (Imagen inmutable con assets empaquetados) recibe y procesa estáticos.
- Nginx pasa la ejecución dinámica al pool de contenedores `php` vía fastcgi.

### 2. Bootstrap (`public/index.php`)

- **Autoload**: Carga clases vía Composer.
- **Env**: Carga variables `.env`.
- **CORS**: Inyecta headers según `APP_ENV`.
- **ErrorHandler**: Registra el manejador global de excepciones (`HttpResponseException`).
- **Rate Limit**: Verifica IP en `Core\RateLimit` (Redis primario; APCu como primer fallback, archivo como último).
- **Middleware**: Registra manejadores (ej: `auth`).

### 3. Enrutamiento (`Core\Router`)

- El router matchea la URI contra `app/Routes/web.php`.
- Extrae parámetros dinámicos de la URL.
- Instancia el controlador correspondiente.

### 4. Controlador (`app/Controllers/*`)

- **Validación**: Usa `Core\Validator` para limpiar y validar `$_POST`/`$_GET`.
- **Negocio**: Llama a modelos o servicios de dominio.
- **Respuesta**: Llama a `Core\Response::success()` o `error()`.

### 5. Salida (`Core\Response`)

- Serializa datos a JSON.
- Establece el código HTTP (200, 400, 404, 500).
- Envía el payload (sin `exit()` — el flujo termina en `index.php`).

---

## Esquema de Base de Datos

El proyecto consume una base de datos SQL Server (`sqlsrv`). La mayoría son vistas o tablas de un sistema legacy (`Discolnet`).

### Mapeo de Modelos

| Modelo              | Tabla / Vista                                    | Propósito                                  | PK / Identificador                                       |
| ------------------- | ------------------------------------------------ | ------------------------------------------ | -------------------------------------------------------- |
| `InvoicesModel`     | `vw_discolnet_dispensas`                         | Facturas con datos de dispensación         | `DisId`                                                  |
| `ClientsModel`      | `NIT` / `Clientes`                               | Gestión de EPS/Clientes                    | `NitSec`                                                 |
| `DispensationModel` | `vw_discolnet_dispensas`                         | Datos detallados de entrega                | `DisDetNro`                                              |
| `AttachmentsModel`  | `AdjuntosDispensacion`                           | Archivos binarios (BLOB/URL)               | `AdjDisId`                                               |
| `AuditStatusModel`  | `dbo.AudDispEst` + `AdjuntosDispensacionDetalle` | Lectura de resultados de auditoría IA + observaciones | `FacNro` (PK operativa); `DisId` se almacena en `FacSec` |
| `AuditResultPersistenceModel` | `dbo.AudDispEst` + `AdjuntosDispensacion` + `DispensacionDetalleServicio` | Escritura transaccional del resumen, hallazgos documentales y trazabilidad | `FacNro` (PK operativa); `DisId` se almacena en `FacSec` |

### Relaciones Clave

- **Factura ↔ Dispensa**: Relación 1:N. Una factura (`FacSec`) agrupa múltiples dispensaciones (`DisId`).
- **Dispensa ↔ Adjuntos**: Relación 1:N. Una dispensa tiene múltiples documentos (fórmula, acta, etc.).
- **Dispensa ↔ Auditoría**: Relación 1:1 por resultado persistido. `AudDispEst.FacNro` es la PK operativa y contiene el `DisDetNro`; `FacSec` conserva el `DisId` como columna legacy.

### SQL Server

- Usar siempre `PDO::prepare()` con placeholders `?` o `:named`
- Cerrar cursores explícitamente después de fetch large result sets
- Queries contra vistas legacy: usar los nombres exactos de la BD (`vw_discolnet_dispensas`, etc.)
- Para BLOBs: usar `PDO::SQLSRV_ENCODING_BINARY` y stream resources
- Política de conexión en modelos: consultas (`SELECT`) por `db2` (`DB2_*`) y escrituras (`INSERT/UPDATE/DELETE/MERGE`) por `default` (`DB_*`)
  - **Excepción**: `AuditStatusModel` sobreescribe `$readConnectionName = 'default'` para garantizar consistencia de lectura post-escritura (lee el mismo resultado que acaba de persistir). Si `default` no está disponible, degrada a `db2` vía `readWithFallback()` local.

### Seguridad y Configuración

#### Variables de entorno y Secretos

- Las variables de entorno se cargan desde `.env` vía `Core\Env::load()`
- **Nunca commitear** `.env` con credenciales reales. El CI/CD lo genera dinámicamente desde Github Secrets.
- **Archivo de referencia**: `.env.example` — mantenerlo actualizado.
- **Inmutabilidad**: El código en producción NO puede modificarse desde el host (Zero-Source).
- Variables críticas: `GEMINI_API_KEY`, `DB_PASS`, `GOOGLE_DRIVE_PRIVATE_KEY`, `MCP_WEBHOOK_SECRET`
- **CI/CD SQL Server**: en GitHub Environment `production`, `DB_HOST` y `DB2_HOST` deben configurarse como GitHub Variables con host/IP limpio, sin instancia ni puerto embebido. `DB_PORT=1433`, `DB2_PORT=1433`.

> 🚨 **REGLA OBLIGATORIA E INFALIBLE: SINCRONIZACIÓN EN GITHUB REMOTO** 🚨
> 
> **Cada vez que un agente cree, modifique o renombre una variable de entorno o secreto en el código PHP/Next.js o en `.env.example`:**
> 1. **`.env.example`**: Agregar la clave inmediatamente. El pipeline CI (`Validate env contract completeness`) bloqueará el commit si alguna variable usada en código falta en `.env.example`.
> 2. **GitHub Remoto (`gh cli`)**: Es **estrictamente obligatorio** crear o actualizar la variable/secreto en el repositorio remoto de GitHub antes de hacer push a `main`:
>    - **Variables no sensibles**: `gh variable set <KEY> --body "<VAL>" --env production`
>    - **Secretos / Credenciales**: `gh secret set <KEY> --body "<SECRET>" --env production`
> 3. **`AGENTS.md`**: Documentar la variable en el *Catálogo de Variables de Entorno*.
> 
> **Queda prohibido dar por finalizada una tarea con variables nuevas sin haber ejecutado `gh variable set` / `gh secret set` en GitHub.**

#### Guardrails de seguridad

- **Rate limiting**: `Core\RateLimit` — Redis primario (distribuido), APCu como primer fallback (per-proceso), archivo como último fallback; límite general 100 req/min. Nginx **también** aplica rate limiting vía `docker/nginx-ha.conf.template` (10 req/s general, 2 req/s en `/audit`); `docker/nginx.conf` es solo para desarrollo local y no contiene rate limiting.
- **CORS**: controlado en `public/index.php`, orígenes configurables vía `ALLOWED_ORIGINS`.
- **Payload máximo**: `MAX_JSON_SIZE` (1 MB).
- **Timeouts**: `AUDIT_NGINX_READ_TIMEOUT` (Nginx) y `AUDIT_FPM_TERMINATE_TIMEOUT` (PHP) sincronizados (default 3600s).
- **Sanitización de logs**: `Core\Logger` redacta campos sensibles automáticamente. Controllers y Workers enmascaran `facNitSec` en logs (`***` + 3 últimos dígitos).
- **TLS saliente**: `GoogleDriveAuthService` valida certificados HTTPS por defecto.
- Nunca loguear valores de API keys, passwords, o datos de pacientes
- Nunca exponer stack traces o rutas internas en respuestas de error de producción
- Webhook MCP (`app/wrap/webhook.php`): Autenticación obligatoria mediante la cabecera `X-API-KEY` validada contra `MCP_WEBHOOK_SECRET`

---

## Catálogo de Variables de Entorno

> Fuente de verdad: `.env.example`. Toda variable nueva **debe** agregarse aquí y en `.env.example`.

### Aplicación

| Variable                      | Default                                           | Requerida         | Módulo / Uso                                                                                                         |
| ----------------------------- | ------------------------------------------------- | ----------------- | -------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                     | `development`                                     | ✅                | `Core\Env` — controla CORS, logs, mensajes de error                                                                  |
| `WRAP_API_BASE`               | `http://nginx`                                    | ⚠️ Solo MCP       | `app/wrap/core/ApiClient.php` — base URL interna                                                                     |
| `WWWUSER_ID`                  | `1000`                                            | ❌                | UID de usuario host para builds/contenedores de desarrollo                                                           |
| `WWWGROUP_ID`                 | `1000`                                            | ❌                | GID de grupo host para builds/contenedores de desarrollo                                                             |
| `AUDFACT_API_PUBLIC_URL`      | `http://localhost:8080`                           | ⚠️ Deploy/MCP     | URL pública del backend usada para generar `WEBHOOK_URL` y `CAPABILITIES_URL` en deploy; no se hornea en el frontend |
| `INTERNAL_API_URL`            | `http://127.0.0.1:8080`                           | ⚠️ Frontend Proxy | URL interna usada por el proxy Next.js `/api/backend/*`; en producción Docker se inyecta como `http://nginx`         |
| `AUDFACT_FRONTEND_PUBLIC_URL` | `http://localhost:3100`                           | ⚠️ Deploy/CORS    | Origen público del frontend usado por el deploy para completar `ALLOWED_ORIGINS`                                     |
| `WEBHOOK_URL`                 | `http://localhost:8080/app/wrap/webhook.php`      | ⚠️ Solo MCP       | URL pública del webhook MCP                                                                                          |
| `MCP_WEBHOOK_SECRET`          | _(vacío)_                                         | ⚠️ Solo MCP       | Secreto utilizado para validar la autenticación (cabecera `X-API-KEY`) del Webhook MCP                               |
| `CAPABILITIES_URL`            | `http://localhost:8080/app/wrap/capabilities.php` | ⚠️ Solo MCP       | URL de capabilities MCP                                                                                              |
| `AUDIT_STREAM_MAXLEN`         | `100000`                                          | ❌                | Límite aproximado de entradas (MAXLEN ~) por stream Redis de auditoría para evitar crecimiento infinito (OOM)        |

### Despliegue Docker/GHCR

| Variable                     | Default                          | Requerida     | Módulo / Uso                                                             |
| ---------------------------- | -------------------------------- | ------------- | ------------------------------------------------------------------------ |
| `AUDFACT_PHP_IMAGE`          | `ghcr.io/jfrem/audfact-php`      | ⚠️ Producción | `docker-compose.yml` — imagen PHP-FPM/workers publicada en GHCR          |
| `AUDFACT_NGINX_IMAGE`        | `ghcr.io/jfrem/audfact-nginx`    | ⚠️ Producción | `docker-compose.yml` — imagen Nginx publicada en GHCR                    |
| `AUDFACT_FRONTEND_IMAGE`     | `ghcr.io/jfrem/audfact-frontend` | ⚠️ Producción | `docker-compose.yml` — imagen frontend Next.js publicada en GHCR         |
| `AUDFACT_IMAGE_TAG`          | `latest`                         | ⚠️ Producción | `docker-compose.yml` — tag inmutable por SHA o rollback manual           |
| `AUDFACT_FRONTEND_HOST_PORT` | `3100`                           | ⚠️ Producción | `docker-compose.yml` — puerto LAN dedicado para el frontend AudFact      |

### Frontend público

| Variable                        | Default          | Requerida | Módulo / Uso                                                            |
| ------------------------------- | ---------------- | --------- | ----------------------------------------------------------------------- |
| `NEXT_PUBLIC_APP_NAME`          | `AudFact`        | ❌        | `frontend/lib/api/config.ts` / navegación — nombre visible del producto |
| `NEXT_PUBLIC_DEFAULT_THEME`     | `dark`           | ❌        | `frontend/lib/api/config.ts` — tema inicial                             |
| `NEXT_PUBLIC_POLLING_JOBS_MS`   | `5000`           | ❌        | `frontend/lib/api/config.ts` — intervalo de polling de jobs             |
| `NEXT_PUBLIC_POLLING_HEALTH_MS` | `30000`          | ❌        | `frontend/lib/api/config.ts` — intervalo de polling de health           |
| `NEXT_PUBLIC_LOCALE`            | `es-CO`          | ❌        | `frontend/lib/api/config.ts` / formatters — locale                      |
| `NEXT_PUBLIC_TIMEZONE`          | `America/Bogota` | ❌        | `frontend/lib/api/config.ts` / formatters — zona horaria                |

### Base de datos (SQL Server)

| Variable                | Default              | Requerida   | Módulo / Uso                                                                                                    |
| ----------------------- | -------------------- | ----------- | --------------------------------------------------------------------------------------------------------------- |
| `DB_TYPE`               | `sqlsrv`             | ✅          | `Core\Database` — driver PDO                                                                                    |
| `DB_HOST`               | `localhost`          | ✅          | `Core\Database` — host del servidor SQL; en producción usar host/IP limpio sin instancia                        |
| `DB_PORT`               | `1433`               | ✅          | `Core\Database` — puerto SQL Server                                                                             |
| `DB_NAME`               | `mi_base`            | ✅          | `Core\Database` — nombre de la BD                                                                               |
| `DB_USER`               | `sa`                 | ✅          | `Core\Database` — usuario                                                                                       |
| `DB_PASS`               | _(vacío)_            | ✅          | `Core\Database` — contraseña                                                                                    |
| `DB_POOLING`            | `1`                  | ❌          | `Core\Database` — connection pooling PDO                                                                        |
| `DB_ENCRYPT`            | `no`                 | ❌          | `Core\Database` — cifrado TLS de conexión `default`                                                             |
| `DB_TRUST_SERVER_CERT`  | `yes`                | ❌          | `Core\Database` — trust del certificado SQL Server de `default`                                                 |
| `DB2_HOST`              | `localhost`          | ⚠️ Multi-BD | `Core\Database` — host SQL Server de conexión `db2` (consulta); en producción usar host/IP limpio sin instancia |
| `DB2_PORT`              | `1433`               | ⚠️ Multi-BD | `Core\Database` — puerto SQL Server de conexión `db2`                                                           |
| `DB2_NAME`              | `mi_base_secundaria` | ⚠️ Multi-BD | `Core\Database` — nombre de BD de consulta                                                                      |
| `DB2_USER`              | `sa`                 | ⚠️ Multi-BD | `Core\Database` — usuario de BD de consulta                                                                     |
| `DB2_PASS`              | _(vacío)_            | ⚠️ Multi-BD | `Core\Database` — contraseña de BD de consulta                                                                  |
| `DB2_POOLING`           | `1`                  | ❌          | `Core\Database` — pooling PDO para conexión `db2`                                                               |
| `DB2_ENCRYPT`           | `no`                 | ❌          | `Core\Database` — cifrado TLS de conexión `db2`                                                                 |
| `DB2_TRUST_SERVER_CERT` | `yes`                | ❌          | `Core\Database` — trust del certificado SQL Server de `db2`                                                     |

### Google Drive

| Variable                    | Default   | Requerida            | Módulo / Uso                                                                                                                    |
| --------------------------- | --------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `GOOGLE_DRIVE_CLIENT_EMAIL` | _(vacío)_ | ⚠️ Solo adjuntos URL | Service account para acceso a archivos en Drive                                                                                 |
| `GOOGLE_DRIVE_PRIVATE_KEY`  | _(vacío)_ | ⚠️ Solo adjuntos URL | Clave privada del service account                                                                                               |
| `GOOGLE_DRIVE_TLS_VERIFY`   | `1`       | ❌                   | `GoogleDriveAuthService` — validación TLS de conexiones HTTPS a Google Drive (`0` solo para entornos de desarrollo controlados) |

### Logging

| Variable             | Default | Requerida | Módulo / Uso                                              |
| -------------------- | ------- | --------- | --------------------------------------------------------- |
| `LOG_LEVEL`          | `info`  | ❌        | `Core\Logger` — nivel mínimo (`error`, `warning`, `info`) |
| `LOG_RETENTION_DAYS` | `7`     | ❌        | `Core\Logger` — días antes de borrar logs                 |
| `LOG_MAX_SIZE_MB`    | `10`    | ❌        | `Core\Logger` — tamaño máximo por archivo                 |
| `AUDIT_RESPONSE_IA_ENABLED` | `1` | ❌ | `ResponseIADiskStore` — snapshots Gemini solo en `APP_ENV=development`; usar `0` en producción |
| `AUDIT_RESPONSE_IA_DIR` | `/var/www/html/logs/responseIA` | ❌ | `ResponseIADiskStore` — directorio configurable de snapshots locales |

### Seguridad y red

| Variable             | Default   | Requerida  | Módulo / Uso                                                    |
| -------------------- | --------- | ---------- | --------------------------------------------------------------- |
| `REQUEST_TIMEOUT_MS` | `60000`   | ❌         | Timeout general de request (60s)                                |
| `ALLOWED_ORIGINS`    | _(vacío)_ | ⚠️ En prod | `public/index.php` — orígenes CORS permitidos (comma-separated) |
| `MAX_JSON_SIZE`      | `1048576` | ❌         | Tamaño máximo de payload JSON (1 MB)                            |

### Gemini API (Auditoría IA)

| Variable                              | Default                 | Requerida | Módulo / Uso                                                                                                   |
| ------------------------------------- | ----------------------- | --------- | -------------------------------------------------------------------------------------------------------------- |
| `GEMINI_API_KEY`                      | _(vacío)_               | ✅        | `DocumentExtractionWorker` / `GeminiGateway` — API key de Google AI                                            |
| `GEMINI_MODEL`                        | `gemini-3.7-flash`      | ❌        | Modelo de Gemini a usar                                                                                        |
| `GEMINI_TEMPERATURE`                  | `0.0`                   | ❌        | Temperatura (0 = determinístico)                                                                               |
| `GEMINI_TIMEOUT`                      | `300`                   | ❌        | Timeout de la API en segundos                                                                                  |
| `GEMINI_TOP_P`                        | `1.0`                   | ❌        | Nucleus sampling para determinismo                                                                             |
| `GEMINI_TOP_K`                        | `1`                     | ❌        | Top-K sampling para determinismo                                                                               |
| `GEMINI_MAX_OUTPUT_TOKENS`            | `8192`                  | ❌        | Límite de tokens en la respuesta                                                                               |
| `GEMINI_MEDIA_RESOLUTION`             | `MEDIA_RESOLUTION_MEDIUM` | ❌      | `GeminiConfig` — Resolución de imágenes. Enums Protobuf: `MEDIA_RESOLUTION_LOW`, `MEDIA_RESOLUTION_MEDIUM`, `MEDIA_RESOLUTION_HIGH`. |
| `GEMINI_THINKING_BUDGET`              | _(vacío)_               | ❌        | Presupuesto de razonamiento (thinking mode)                                                                    |
| `GEMINI_THINKING_LEVEL`               | _(vacío)_               | ❌        | Nivel de razonamiento general Gemini 3; vacío omite `thinkingConfig`                                           |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS` | `4096`                  | ❌        | Límite de salida para extracción documental                                                                    |
| `GEMINI_EXTRACTION_THINKING_BUDGET`   | _(vacío)_               | ❌        | Presupuesto de razonamiento para extracción documental                                                         |
| `GEMINI_EXTRACTION_THINKING_LEVEL`    | `MINIMAL`               | ❌        | Nivel de razonamiento Gemini 3 para extracción documental                                                      |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS`   | `2048`                  | ❌        | Límite de salida para homologación semántica (2048 para absorber thinking tokens por defecto de Gemini 3)      |
| `GEMINI_SEMANTIC_THINKING_LEVEL`      | _(vacío)_               | ❌        | Nivel de razonamiento Gemini 3 — dejar vacío (omite `thinkingConfig`); `none` no es valor válido en Gemini 3.1 |
| `GEMINI_SEMANTIC_THINKING_BUDGET`     | _(vacío)_               | ❌        | Presupuesto de razonamiento para modelos Gemini 2.5 en homologación semántica                                  |
| `GEMINI_SEED`                         | `42`                    | ❌        | Semilla para reproducibilidad (opcional, recomendada en prod)                                                  |

### Redis

| Variable                 | Default               | Requerida      | Módulo / Uso                                                                         |
| ------------------------ | --------------------- | -------------- | ------------------------------------------------------------------------------------ |
| `REDIS_HOST`             | `redis`               | ⚠️ Async/Cache | `Core\RedisClient` — host servidor Redis                                             |
| `REDIS_PORT`             | `6379`                | ⚠️ Async/Cache | `Core\RedisClient` — puerto Redis                                                    |
| `REDIS_PASSWORD`         | _(vacío)_             | ❌             | `Core\RedisClient` — contraseña Redis; coincide con `docker-compose.yml` por defecto |
| `REDIS_PREFIX`           | `audfact:`            | ❌             | `Core\RedisClient` — prefijo para keys (namespace)                                   |
| `REDIS_MAXMEMORY`        | `4gb`                 | ❌             | `docker-compose.yml` — límite interno de memoria Redis (`--maxmemory`)               |
| `REDIS_MAXMEMORY_POLICY` | `volatile-lru`        | ❌             | `docker-compose.yml` — política de evicción Redis para llaves con TTL                |
| `REDIS_CONTAINER_MEMORY` | `5G`                  | ❌             | `docker-compose.yml` — límite de memoria del contenedor Redis                        |
| `REDIS_MODE`             | `standalone`          | ❌             | `Core\RedisClient` — modo `standalone`, `sentinel` o `cluster`                       |
| `REDIS_SENTINELS`        | _(comentado)_         | ⚠️ Sentinel    | Lista `host:port` separada por comas para modo sentinel                              |
| `REDIS_SENTINEL_SERVICE` | _(comentado)_         | ⚠️ Sentinel    | Nombre del master Sentinel                                                           |
| `REDIS_CLUSTER_NODES`    | _(comentado)_         | ⚠️ Cluster     | Lista de nodos `host:port` para modo cluster                                         |
| `REDIS_PERSISTENT`       | `0`                   | ❌             | Habilita conexiones persistentes Redis en PHP-FPM                                    |

### Circuit Breaker Gemini

| Variable              | Default | Requerida | Módulo / Uso                                                         |
| --------------------- | ------- | --------- | -------------------------------------------------------------------- |
| `CB_GEMINI_THRESHOLD` | `3`     | ❌        | `GeminiCircuitBreaker` — fallos consecutivos antes de abrir circuito |
| `CB_GEMINI_COOLDOWN`  | `60`    | ❌        | `GeminiCircuitBreaker` — segundos antes de half-open                 |

### Auditoría Async

| Variable                             | Default                     | Requerida  | Módulo / Uso                                                                        |
| ------------------------------------ | --------------------------- | ---------- | ----------------------------------------------------------------------------------- |
| `AUDIT_BATCH_TIMEOUT`                | `3600`                      | ❌         | Timeout legacy/compat de batch; el flujo actual responde 202 y procesa en workers   |
| `AUDIT_BATCH_MAX_LIMIT`              | `100`                       | ❌         | `AuditController::async` — máximo de facturas por batch                             |
| `AUDIT_INTERNAL_API_BASE`            | `http://nginx`              | ⚠️ Workers | URL interna usada por workers cuando requieren API HTTP interna                     |
| `AUDIT_CACHE_TTL`                    | `604800`                    | ❌         | Idempotencia — TTL en segundos del cache Redis de resultados de auditoría           |
| `AUDIT_EXTRACTION_CACHE_TTL`         | `604800`                    | ❌         | `ExtractionCache` — TTL en segundos del cache documental por `document_hash`        |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3`                         | ❌         | `docker-compose.yml` — réplicas de orquestadores `audit_created`                    |
| `AUDIT_WORKER_BATCH_REPLICAS`        | `2`                         | ❌         | `docker-compose.yml` — réplicas del worker `batch_requested`                        |
| `AUDIT_WORKER_DOWNLOADER_REPLICAS`   | `8`                         | ❌         | `docker-compose.yml` — réplicas de descarga de adjuntos                             |
| `AUDIT_WORKER_EXTRACTION_REPLICAS`   | `8`                         | ❌         | `docker-compose.yml` — réplicas de extractores Gemini                               |
| `AUDIT_WORKER_POLICY_REPLICAS`       | `2`                         | ❌         | `docker-compose.yml` — réplicas de evaluación de reglas                             |
| `AUDIT_WORKER_PERSISTENCE_REPLICAS`  | `3`                         | ❌         | `docker-compose.yml` — réplicas globales de persistencia SQL                        |
| `AUDIT_IDEMPOTENCY_KEY_TTL`          | `300`                       | ❌         | `BatchJobStore` — TTL de barrera `X-Idempotency-Key`                                |
| `AUDIT_JOB_TTL`                      | `604800`                    | ❌         | `BatchJobStore` — TTL del estado de jobs batch async                                |
| `AUDIT_STATE_TTL`                    | `604800`                    | ❌         | `AuditStateStore` — TTL del estado transitorio de auditorías                        |
| `AUDIT_PERSISTENCE_QUEUE_TTL`        | `604800`                    | ❌         | `AuditPersistenceQueue` — TTL de turnos, pendientes y deduplicación por job          |
| `AUDIT_RESERVATION_TTL`              | `86400`                     | ❌         | `BatchJobStore` — TTL de reservas por `DisId`                                       |
| `AUDIT_PENDING_RECLAIM_IDLE_MS`      | `600000`                    | ❌         | `AuditEventConsumer` — idle mínimo antes de reclamar mensajes `pending` abandonados |
| `AUDIT_PENDING_RECLAIM_INTERVAL_MS`  | `30000`                     | ❌         | `AuditEventConsumer` — frecuencia de escaneo para recuperación de `pending`         |
| `AUDIT_EVENT_MAX_RETRIES`            | `3`                         | ❌         | `AuditEventConsumer` — reintentos antes de DLQ                                      |
| `AUDIT_STREAM_BLOCK_MS`              | `5000`                      | ❌         | `AuditEventConsumer` — bloqueo en `XREADGROUP` antes de re-poll                     |
| `AUDIT_DLQ_STREAM`                   | `audit.dlq`                 | ❌         | Nombre del stream DLQ                                                               |
| `AUDIT_VERSION_EXTRACTOR`            | `gemini-3.x-parallel-fc-v1` | ❌         | Versión de trazabilidad del extractor                                               |
| `AUDIT_VERSION_NORMALIZER`           | `1.0.0`                     | ❌         | Versión de trazabilidad del normalizador                                            |
| `AUDIT_VERSION_RULES`                | `1.0.0`                     | ❌         | Versión de trazabilidad de reglas                                                   |
| `AUDIT_NGINX_READ_TIMEOUT`           | `3600`                      | ❌         | Timeout de lectura Nginx para endpoints de auditoría                                |
| `AUDIT_FPM_TERMINATE_TIMEOUT`        | `3600`                      | ❌         | Timeout de terminación PHP-FPM para procesos de auditoría                           |

### Leyenda

- ✅ **Requerida**: la aplicación no funciona sin esta variable
- ⚠️ **Condicional**: requerida solo en ciertos contextos
- ❌ **Opcional**: tiene valor default funcional

---

## Docker y Operaciones Runtime

> 📖 **Ver documento dedicado:** [plans/docker-operations.md](file:///c:/Users/USER/Desktop/AudFact/plans/docker-operations.md)

- Nginx debe resolver `php:9000` con DNS Docker en runtime (`resolver 127.0.0.11`) para evitar upstreams FastCGI obsoletos después de recrear réplicas PHP-FPM.

---

## Testing

> 📖 **Ver documento dedicado:** [plans/testing-strategy.md](file:///c:/Users/USER/Desktop/AudFact/plans/testing-strategy.md)

---

## Control de Versiones (Git)

> 📖 **Ver documento dedicado:** [plans/git-workflow.md](file:///c:/Users/USER/Desktop/AudFact/plans/git-workflow.md)

---

## Planificación Pre-Implementación

**OBLIGATORIO**: antes de escribir cualquier línea de código, el agente debe crear un plan de implementación y **esperar la aprobación explícita del usuario**. Ningún cambio de código se ejecuta sin aprobación previa.

### Flujo de trabajo (Kanban)

Cada tarea sigue este flujo. El agente gestiona los estados y reporta transiciones al usuario:

```
📌 Backlog → 🛠️ Ready → 🧑‍💻 In Dev → 🔍 Review → 🧪 QA → 📦 Deploy → ✅ Done
```

| Estado                  | Significado                                      | Quién actúa                        |
| ----------------------- | ------------------------------------------------ | ---------------------------------- |
| 📌 **Backlog**          | Idea o requerimiento sin refinar                 | Usuario propone                    |
| 🛠️ **Ready**            | Plan aprobado, criterios de aceptación definidos | Agente planifica → Usuario aprueba |
| 🧑‍💻 **In Development**   | Código en proceso de escritura                   | Agente implementa                  |
| 🔍 **Code Review**      | Implementación completa, pendiente de revisión   | Agente presenta → Usuario revisa   |
| 🧪 **QA / Testing**     | Pruebas de verificación en curso                 | Agente verifica                    |
| 📦 **Ready for Deploy** | Verificado y listo para producción               | Agente confirma                    |
| ✅ **Done**             | Entregado, documentado, y cerrado                | Ambos confirman                    |

### Template de plan (obligatorio)

Antes de pasar una tarea de **Backlog → Ready**, el agente debe presentar el siguiente plan al usuario:

```markdown
## 📋 Plan de Implementación: [Título]

### Contexto

[Qué problema resuelve y por qué es necesario]

### Alcance

- **Archivos a crear**: [lista]
- **Archivos a modificar**: [lista]
- **Archivos afectados indirectamente**: [lista]

### Criterios de Aceptación

1. [ ] [Criterio específico y verificable]
2. [ ] [Criterio específico y verificable]
3. [ ] [Criterio específico y verificable]

### Tareas Técnicas

1. [ ] [Tarea granular]
2. [ ] [Tarea granular]
3. [ ] [Tarea granular]

### Riesgos

- [Riesgo identificado] → [Mitigación]

### Estimación

- **Complejidad**: [Baja / Media / Alta]
- **Archivos afectados**: [N]

### Verificación

- [Cómo se validará que el cambio funciona]

### Archivos de documentación

- [lista de archivos de documentación a crear o actualizar]

### Hallazgos relacionados

- [IDs de auditoría si aplica, o "ninguno"]
```

### Reglas de transición

| De → A          | Condición requerida                                                    |
| --------------- | ---------------------------------------------------------------------- |
| Backlog → Ready | Plan presentado **y aprobado** por el usuario                          |
| Ready → In Dev  | Checkpoint creado, skill correspondiente leída                         |
| In Dev → Review | Código completo, sin errores de sintaxis                               |
| Review → QA     | Usuario acepta o agente auto-avanza si la tarea es de complejidad Baja |
| QA → Deploy     | Tests pasan, documentación post-implementación completada              |
| Deploy → Done   | Verificación final exitosa                                             |

### Niveles de complejidad

| Nivel     | Criterio                                             | Aprobación requerida                              |
| --------- | ---------------------------------------------------- | ------------------------------------------------- |
| **Baja**  | 1-2 archivos, sin cambio de API, sin riesgo          | Plan breve → puede auto-avanzar Review→QA         |
| **Media** | 3-5 archivos, cambio interno, riesgo bajo            | Plan completo → esperar aprobación                |
| **Alta**  | 6+ archivos, cambio de API/schema, riesgo medio-alto | Plan detallado + discusión → aprobación explícita |

### Integración con Trello

Si el proyecto tiene tablero Trello activo (ID: `68edb398ddef3c93dda9b92a`):

- Crear tarjeta en la lista correspondiente al estado actual
- Mover tarjeta al avanzar de estado
- Agregar checklist "Criterios de Aceptación" y "Tareas Técnicas" a la tarjeta
- Usar etiquetas según tipo: `Feature` (verde), `Core` (amarillo), `Tech Debt` (naranja), `Security` (rojo)

### Cuándo NO se necesita plan formal

- Correcciones de typos o formato de código
- Actualización de documentación existente
- Cambios solicitados explícitamente con instrucciones detalladas por el usuario
- Responder preguntas o análisis sin cambio de código

---

## Notas Específicas para Agentes

### Comportamiento general

- **Idioma**: toda comunicación en Español (Latinoamérica)
- **Contexto de negocio obligatorio**: ver sección **Business Domain Gate** más abajo. Leer [`BUSINESS.md`](./BUSINESS.md) es prerrequisito antes de tocar código de dominio.
- **Documentación primero**: OBLIGATORIO revisar planes (`plans/api-endpoints.md`, `plans/architecture.md`, etc.) ANTES de intentar adivinar URLs, comandos o la estructura del ruteo.
- **Verificar en código**: responder con alta confianza; no adivinar comportamientos
- **Skill-first**: antes de analizar, responder o modificar, detectar y cargar la skill aplicable desde `.agent/skills/CATALOG.md`
- **No editar `vendor/`**: las actualizaciones lo sobreescriben
- **No editar archivos dentro de contenedores Docker**: usar el mount de volumen local
- **Checkpoint obligatorio**: crear respaldo antes de cualquier modificación significativa

### Skill Gate Estricto (Global)

Esta regla aplica a TODA tarea técnica (análisis, overview, implementación, refactor, review).

1. Detectar intención del usuario y mapearla a una skill del catálogo.
2. Cargar y leer `SKILL.md` correspondiente ANTES de abrir archivos de código o responder contenido técnico.
3. Declarar explícitamente en la primera respuesta operativa: `Skill aplicada: <nombre>`.
4. Si aplican múltiples skills, declarar orden de uso y alcance de cada una.
5. Si la skill no existe, no puede leerse o falla: detener flujo normal, reportar bloqueo y continuar con fallback manual marcado como `provisional sin skill`.

Checklist operativo obligatorio al inicio de cada tarea:

- `Skill detectada`
- `SKILL.md leído`
- `Archivos objetivo identificados`

Excepciones (no requieren skill-gate formal):

- Correcciones de typo/formato sin impacto funcional
- Edición menor de documentación existente
- Conversación casual sin análisis técnico

### Business Domain Gate (Global)

> **OBLIGATORIO** — Esta regla tiene la misma jerarquía que el Skill Gate.
> Su omisión es un defecto de proceso, incluso si el cambio es "solo refactor" o "solo clean code".

Antes de **crear, modificar, refactorizar o eliminar** código en cualquiera de los archivos o directorios listados abajo, el agente **DEBE** leer [`BUSINESS.md`](./BUSINESS.md) y confirmar que comprende las reglas de dominio que gobiernan la lógica que va a tocar.

#### Archivos y directorios que disparan el Business Gate

| Ruta / Patrón | Motivo |
|---|---|
| `app/Services/Audit/**` | Pipeline IA, reglas de negocio, normalización, policy engine |
| `app/Models/DispensationModel.php` | Fuente de Verdad (FDV), campos de dispensación |
| `app/Models/AuditStatusModel.php` | Lectura de resultados y timings de auditoría |
| `app/Models/AuditResultPersistenceModel.php` | Escritura transaccional del resultado global y detalle documental |
| `app/Models/AuditConfigModel.php` | Configuración dinámica de auditoría por cliente |
| `app/Models/AttachmentsModel.php` | Adjuntos documentales del expediente |
| `app/Controllers/AuditController.php` | Endpoints de auditoría (single, async, results) |
| `app/Controllers/AuditConfigController.php` | Configuración de campos auditables por EPS |
| `app/Controllers/AuditDlqController.php` | Dead Letter Queue de auditoría |
| `bin/audit-*.php` | Workers del pipeline event-driven |
| Cualquier archivo que contenga lógica de comparación de campos (`TipoCampo`, `TipoDato`, `normalizeForComparison`, `evaluateBusinessField`) | Reglas de evaluación de la auditoría |

#### Checklist obligatorio (Business Gate)

Antes de escribir la primera línea de código en archivos del gate, el agente debe verificar:

- [ ] `BUSINESS.md leído` — Leer el documento completo (no solo una sección).
- [ ] `Reglas de dominio identificadas` — Declarar qué reglas de negocio (sección 9 de BUSINESS.md) aplican al cambio.
- [ ] `Impacto en decisiones de auditoría evaluado` — Confirmar que el cambio no altera la semántica de `COINCIDE`, `VALOR_DISTINTO`, `NO_ENCONTRADO`, `NO_CONCLUYENTE` u `OMITIDO` de forma no intencionada.

#### Qué sucede si el agente omite el Business Gate

Si se detecta que un agente modificó archivos del gate sin haber leído `BUSINESS.md`:

1. El cambio debe ser revisado manualmente por el usuario antes de mergearse.
2. El agente debe documentar la omisión en el commit o PR como deuda de proceso.
3. En sesiones futuras, el agente debe priorizar la lectura del Business Gate al inicio de la conversación si anticipa trabajo en el dominio.

#### Excepciones (no requieren Business Gate formal)

- Cambios exclusivamente de formato/whitespace sin impacto semántico.
- Actualización de comentarios o docstrings que no alteran lógica.
- Cambios en archivos de test que no modifican la lógica bajo prueba.

### Regla Obligatoria de Auditoría (Skill Gate)

Cuando el usuario solicite auditar/revisar/evaluar/assessment de un repositorio o proyecto, el agente debe:

1. Cargar y aplicar obligatoriamente la skill `.agent/skills/audit-skill-router/SKILL.md`.
2. Ejecutar el enrutamiento por dominio y el contrato de orquestación consolidado, salvo que el usuario limite explícitamente el alcance.
3. Entregar siempre: alcance/supuestos, hallazgos por severidad con evidencia, tabla de scoring por dominio (0-5) con ponderado, score global + clasificación (A/B/C/D), nota de regla de techo (si aplica), y plan 30/60/90 con responsables.
4. Si la skill no existe, no puede leerse o falla: detener el flujo normal, reportar bloqueo explícito y continuar solo con fallback manual marcado como "auditoría provisional sin framework", incluyendo evidencia faltante.

Esta regla tiene prioridad sobre estilo libre en tareas de auditoría.

### Multi-agent safety

- **No crear/aplicar/eliminar `git stash`** sin solicitud explícita
- **No cambiar de branch** sin solicitud explícita
- **Scope**: al commitear, incluir solo los archivos propios del cambio
- Cuando haya archivos no reconocidos (de otro agente), ignorarlos y enfocarse en los propios
- Formato/lint automático: si los cambios son solo de formato, aplicar sin preguntar; si son semánticos, consultar

### Pipeline de auditoría IA

> ⚠️ **Business Gate requerido**: Cualquier cambio en esta sección exige haber completado el checklist del **Business Domain Gate** antes de escribir código. Ver sección anterior.

- **Archivos críticos**: `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` y `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` gobiernan la decisión final del pipeline y requieren review cuidadoso
- **Pipeline event-driven actual**: `audit_created -> document_registered -> document_downloaded -> document_extracted -> document_normalized -> rules_evaluated -> audit_completed`
- **Workers event-driven clave**: `DocumentAuditOrchestrator`, `AttachmentDownloadWorker`, `DocumentExtractionWorker`, `DocumentNormalizer`, `RulesEvaluationWorker`, `AuditPersistenceWorker`
- **Outcome final**: `RulesEvaluationWorker` transforma los resultados de policy + estado Redis a `audit_result_data` y `document_decisions` compatibles con `AuditResultPersistenceModel::persist()`
- **Persistencia final**: `AuditPersistenceQueue` mantiene un turno activo por job y `AuditPersistenceWorker` valida el outcome, persiste en SQL, cierra Redis y publica eventos terminales; no toma decisiones funcionales de auditoría
- **Idempotencia global**: `POST /audit/single` y `POST /audit/async` reservan `DisId` en Redis con owner token antes de publicar `audit_created`; la FDV se resuelve por `DisId` y `DisDetNro` queda como llave operativa de adjuntos/`FacNro`
- **Estado Redis por auditoría**: `AuditStateStore` conserva `docs_total`, `docs_extracted`, `docs_done` (documentos normalizados listos para policy), `docs_rejected` (documentos no procesables rechazados antes de Gemini) y `docs_evaluated`
- **Observabilidad Redis por auditoría**: `AuditEventConsumer` persiste `event_timings` con espera en cola, duración del handler, duración del ack, stream, consumer y tipo de evento; `AuditPersistenceWorker` conserva `aggregation_timings` como contrato de telemetría consumido por el frontend
- **Recuperación de pending Redis Streams**: `AuditEventConsumer` reclama periódicamente mensajes `pending` con idle alto (`AUDIT_PENDING_RECLAIM_IDLE_MS`) para recuperar workers caídos sin duplicar extracciones Gemini largas
- **Timings finales persistidos**: el worker de persistencia recalcula `phase_timings` después de `completed_at` y actualiza `AudDispEst` por `FacNro` sin releer ni reescribir adjuntos
- **Escalado inicial de workers**: defaults de `docker-compose.yml` `batch=2`, `orchestrator=3`, `downloader=8`, `extraction=8`, `policy=2`, `persistence=3`; ajustar por `.env` según backlog real, cuota Gemini y presión sobre SQL Server
- **Cierre de auditoría**: `AuditPersistenceWorker` marca `completed`, `manual_review` o `error`; `AuditEventConsumer` marca `failed` solo al agotar reintentos y libera el siguiente turno antes del ACK terminal
- **Persistencia final**: `audit_completed` solo se publica después de persistencia exitosa en `AudDispEst` y `AdjuntosDispensacion`; el batch publica `batch_completed` o `batch_completed_with_errors` cuando el job llega a estado terminal
- **Fallo final de persistencia**: un error SQL se reintenta sin terminalizar anticipadamente; al agotar intentos, `AuditEventConsumer` marca `failed`, publica `audit_failed`, actualiza el batch y `AuditPersistenceWorker` libera el siguiente turno del job
- **Gemini API**: sujeto a rate limits (HTTP 429) y errores de disponibilidad (HTTP 503)
- **Rechazo preventivo pre-Gemini**: `DocumentIntegrityValidator` puede publicar `document_rejected` para adjuntos vacíos, corruptos, con MIME inconsistente o no soportados. `RulesEvaluationWorker` convierte ese evento en hallazgo canónico `RECHAZADO` con `tipo_auditoria=integrity` y no consume Gemini.
- **Contrato Gemini y prompts**: `DocumentAuditOrchestrator` publica `extraction_contract` parametrizado por `audit-config`; `DocumentExtractionContractBuilder` construye las cuatro funciones paralelas (`extract_fields`, `extract_items`, `detect_visual_checks`, `assess_document_quality`); `DocumentExtractionWorker` genera el user prompt por documento y combina las llamadas en `extraction_result`. Cualquier cambio afecta la calidad de las auditorías.
- **Visuales calculables**: `TipoCampo = V` puede ser booleano (`presente`/`ausente`) o aportar evidencia estructurada opcional (`valor`, `unidad`, `fecha_base`). `VigenciaEntrega` se calcula en `RulesEvaluationWorker` cuando todos los documentos están evaluados; Gemini solo extrae evidencia y PHP decide.
- **Archivos base64**: alto consumo de RAM — respetar límites de tamaño
- **Respuestas JSON de Gemini**: pueden llegar truncadas o malformadas — siempre usar `JsonResponseParser` con `JsonRepairHelper`

### MCP Wrap

- **Webhook** (`app/wrap/webhook.php`): punto de entrada para agentes IA externos
- **Autenticación**: Validación obligatoria de cabecera `X-API-KEY` contra `MCP_WEBHOOK_SECRET`
- **Herramientas disponibles**: `GetClients`, `GetInvoices`, `GetAttachments`, `GetDispensation`
- `ApiClient.php` usa Guzzle HTTP con configuración TLS del proyecto

### Archivos que NO deben editarse

| Archivo         | Razón                                               |
| --------------- | --------------------------------------------------- |
| `vendor/*`      | Gestionado por Composer                             |
| `.env`          | Contiene credenciales reales                        |
| `logs/*`        | Generados por la aplicación                         |
| `logs/responseIA/*`  | Respuestas crudas de Gemini para debug local        |
| `composer.lock` | Solo modificar indirectamente vía `composer update` |

### Archivos que SIEMPRE deben actualizarse en conjunto

| Si modificas...             | También actualizar...                   |
| --------------------------- | --------------------------------------- |
| `app/Routes/web.php`        | `README.md` (tabla de endpoints)        |
| `.env` variables nuevas     | `.env.example`                          |
| `app/Controllers/*`         | Tests correspondientes (cuando existan) |
| Skills (`SKILL.md`)         | `CATALOG.md`                            |
| Docker config               | `README.md` (instrucciones de setup)    |
| `AGENTS.md`                 | `CLAUDE.md` (mantener en sync)          |
| Reglas de negocio / dominio | `BUSINESS.md`                           |
| Cualquier implementación    | `CHANGELOG.md` (ver protocolo abajo)    |

---

## Documentación Automática Post-Implementación

**OBLIGATORIO**: después de completar cada implementación (feature, fix, refactor, security), el agente debe ejecutar este protocolo de documentación **antes de considerar la tarea como terminada**.

### Paso 1 — CHANGELOG.md

Agregar entrada en `CHANGELOG.md` (crear si no existe) con el siguiente formato:

```markdown
## [YYYY-MM-DD]

### Tipo (feat/fix/refactor/security/perf)

- **Ámbito**: Descripción concisa del cambio
  - Archivos modificados: `archivo1.php`, `archivo2.php`
  - Hallazgo resuelto: C01 (si aplica)
  - Impacto: descripción del efecto en producción
```

Reglas del CHANGELOG:

- Solo cambios **user-facing** o que afecten el comportamiento del sistema
- No documentar: cambios de formato, typos en comentarios, reordenamiento de imports
- Ordenar por impacto: Changes primero, Fixes después
- Fecha en formato `YYYY-MM-DD`
- Mantener las entradas más recientes **arriba**

### Paso 2 — AGENTS.md (tabla de hallazgos)

Si el cambio resuelve un hallazgo de auditoría, actualizar la tabla de "Hallazgos de Auditoría Conocidos" cambiando el estado de `Pendiente` a `✅ Resuelto (YYYY-MM-DD)`.

### Paso 3 — Documentación contextual

Según el tipo de cambio, actualizar las fuentes correspondientes:

| Tipo de cambio                  | Documentación requerida                                           |
| ------------------------------- | ----------------------------------------------------------------- |
| Nuevo endpoint REST             | `README.md` tabla de endpoints + `app/Routes/web.php` comentarios |
| Nueva variable de entorno       | `.env.example` + sección "Variables de entorno" de este archivo   |
| Nuevo modelo/controlador        | PHPDoc en la clase + skill correspondiente si aplica              |
| Cambio en Docker                | `README.md` sección setup + sección "Docker" de este archivo      |
| Nuevo skill                     | `CATALOG.md` + tabla de skills de este archivo                    |
| Cambio en pipeline de auditoría | Sección "Pipeline de auditoría IA" de este archivo                |
| Cambio de seguridad             | Sección "Guardrails de seguridad" de este archivo                 |

### Paso 4 — Resumen de implementación

Al finalizar, generar un bloque de resumen con este formato (en el mensaje/reporte al usuario):

```
## Resumen de Implementación

**Cambio**: [descripción en una línea]
**Archivos modificados**: [lista]
**Tests**: [ejecutados/agregados/pendientes]
**Hallazgos resueltos**: [IDs o "ninguno"]
**Documentación actualizada**: [lista de docs tocados]
**Verificación**: [método usado para validar]
```

### Paso 5 — PHPDoc en código

Todo método público nuevo o modificado debe tener PHPDoc mínimo:

```php
/**
 * Descripción breve del método.
 *
 * @param  string  $invoiceId  ID de la factura
 * @param  int     $limit      Máximo de resultados (1-50)
 * @return array               Arreglo de resultados procesados
 * @throws \InvalidArgumentException  Si los parámetros son inválidos
 */
```

### Qué NO documentar

- Cambios internos sin impacto externo (refactors puros sin cambio de API)
- Correcciones de typos o formato
- Actualizaciones de dependencias menores (salvo breaking changes)
- Archivos auto-generados (`vendor/`, `logs/`, `composer.lock`)

---

## Hallazgos de Auditoría Conocidos

> 📖 **Ver documento dedicado:** [plans/audit-findings.md](file:///c:/Users/USER/Desktop/AudFact/plans/audit-findings.md)

---

## Manejo de Errores y Excepciones

### Estado actual (✅ C01 resuelto)

`Core\Response::json()` ya no usa `exit()`. Se utiliza `HttpResponseException` para control de flujo, capturada por el handler global en `public/index.php`.

### Arquitectura de excepciones

```
             Capa                          Manejo
┌─────────── public/index.php ──────────── Único exit() del sistema
│  ┌──────── Controlador ───────────────── Valida entrada, atrapa excepciones de servicio
│  │  ┌───── Servicio/Modelo ───────────── Lanza excepciones
│  │  │  ┌── Core\Response ─────────────── Envía JSON, NO hace exit()
```

### Reglas de manejo de errores

| Capa            | Qué hacer                                                         | Qué NO hacer                        |
| --------------- | ----------------------------------------------------------------- | ----------------------------------- |
| **Modelo**      | Lanzar excepción si la query falla o datos inválidos              | Llamar `Response::error()`          |
| **Servicio**    | Lanzar excepción con mensaje descriptivo y código HTTP            | Hacer `echo` o `exit()`             |
| **Controlador** | Capturar excepciones → `Response::error($e->getMessage(), $code)` | Dejar pasar excepciones sin manejar |
| **index.php**   | Exception handler global como red de seguridad                    | Mostrar stack traces en producción  |

### Exception handler global (actual)

```php
// public/index.php
set_exception_handler(function ($e) {
    Logger::error('Unhandled exception: ' . $e->getMessage(), ['exception' => $e]);

    if (Env::get('APP_ENV') === 'production') {
        Response::error('Internal server error', 500);  // Sin detalles
    } else {
        Response::error($e->getMessage(), 500);          // Con detalles en dev
    }
});
```

### Formato de respuesta de error

```json
{
  "success": false,
  "message": "Descripción del error para el cliente",
  "errors": ["detalle 1", "detalle 2"] // Opcional, solo si aplica
}
```

### Qué NO incluir en respuestas de error

- ❌ Stack traces
- ❌ Rutas internas del servidor (`/var/www/html/...`)
- ❌ Nombres de tablas o columnas SQL
- ❌ Valores de variables de entorno
- ❌ Mensajes de PDO/driver completos

---

## Estándares de Logging

### Implementación actual: `Core\Logger`

El logger escribe archivos JSON rotados en `logs/app-{HOSTNAME}-YYYY-MM-DD.log`. La integración del sufijo `{HOSTNAME}` es un blindaje vital de **Alta Disponibilidad** para permitir que múltiples procesos Docker FPM concurrentes logueen en tiempo real sin pisarse por race condition de escrituras exclusivas en un único archivo compartido del mount host.

### Niveles de log

| Nivel               | Cuándo usar                                        | Ejemplo                                                                         |
| ------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------- |
| `Logger::error()`   | Fallos que impiden completar la operación          | Error de conexión SQL, excepción no manejada, API Gemini 500                    |
| `Logger::warning()` | Situaciones anómalas que no bloquean la operación  | Rate limit cercano, archivo adjunto omitido por tamaño, respuesta JSON truncada |
| `Logger::info()`    | Operaciones completadas exitosamente, trazabilidad | Query ejecutada, auditoría completada, webhook recibido                         |

### Configuración por variable de entorno

| Variable             | Default | Descripción                                     |
| -------------------- | ------- | ----------------------------------------------- |
| `LOG_LEVEL`          | `info`  | Nivel mínimo: `error`, `warning`, o `info`      |
| `LOG_RETENTION_DAYS` | `7`     | Días antes de eliminar logs antiguos            |
| `LOG_MAX_SIZE_MB`    | `10`    | Tamaño máximo por archivo (se vacía al exceder) |

### Sanitización automática

`Logger::sanitizeContext()` redacta automáticamente campos con estas claves:

```
password, token, secret, api_key, credit_card, ssn, authorization
```

Se reemplazan por `[REDACTED]` en el contexto del log.

### Qué loguear vs. qué NO loguear

| ✅ Loguear                              | ❌ NO loguear                          |
| --------------------------------------- | -------------------------------------- |
| ID de factura/dispensa procesada        | Datos de pacientes (nombre, documento) |
| Conteo de resultados (`count($result)`) | Contenido completo de respuestas SQL   |
| Errores de API con código HTTP          | API keys o tokens completos            |
| Tiempo de ejecución de operaciones      | Datos base64 de archivos adjuntos      |
| IP del cliente (para rate limiting)     | Contraseñas o secrets                  |

### Formato de entrada de log

```json
{
  "timestamp": "2026-02-22T09:50:00-05:00",
  "level": "ERROR",
  "message": "Descripción del evento",
  "context": {
    "exception": {
      "class": "RuntimeException",
      "message": "Rate limit exceeded",
      "file": "/var/www/html/core/RateLimit.php:45",
      "trace": "..."
    }
  }
}
```

````

---

## Performance y Optimización

El rendimiento es crítico en AudFact debido a la latencia de la API de Gemini y el procesamiento de grandes volúmenes de datos SQL.

### 1. Base de Datos (SQL Server)
- **WITH (NOLOCK)**: Usar siempre en queries de lectura para evitar bloqueos en el sistema transaccional de producción.
- **Paginación**: Nunca traer más de 1000 registros de una vez.
- **Stream Processing**: Usar `getAttachmentBlobStreamByIdForDispensation()` con lectura directa a memoria sin archivo temporal.

### 2. Caché y Almacenamiento
- **APCu**: Se recomienda para caché de nivel de sistema (ej: tokens JWT, rate limiting hits) para evitar latencia de archivos.
- **Memoization**: Los controladores deben cachear resultados de configuraciones estáticas durante el ciclo de vida de la petición.

### 3. Gemini API (Audit Pipeline)
- **Timeouts**: Configurar `GEMINI_TIMEOUT` (default 300s) para evitar procesos zombies.
- **Multimodal**: No enviar archivos que excedan el límite de memoria configurado en `PHP_INI`.
- **Reintentos**: Implementar reintentos con **Exponential Backoff** ante errores 429 (Rate Limit) o 503 (Servicio no disponible).

---

## Monitoreo y Alertas

### 1. Health Check (`/health`)
El endpoint `/health` devuelve el estado de salud del sistema:
- **Database**: Verifica ping exitoso a SQL Server.
- **Logs**: Verifica permisos de escritura en `logs/`.
- **Environment**: Verifica presencia de `.env`.

### 2. Alertas Críticas
El equipo debe ser notificado si:
- El log contiene `ERROR: Unhandled exception`.
- El tiempo de una auditoría excede los 240 segundos.
- La tasa de errores 4xx/5xx supera el 5% en un periodo de 5 minutos.
- El disco de `/logs` supera el 85% de capacidad.

### 3. Perfilamiento (Profiling)
- **Xdebug**: Habilitar solo en desarrollo para trazar cuellos de botella.
- **Logger Ms**: Registrar siempre el campo `DuracionProcesamientoMs` en la tabla `AudDispEst` para medir el SLA de la IA.

---

## Manejo de Dependencias (Composer)

### Estado actual

```json
{
    "require": {
        "ext-pdo": "*",
        "guzzlehttp/guzzle": "^7.10",
        "firebase/php-jwt": "^7.0"
    }
}
````

Dependencias de desarrollo:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^10.0"
  }
}
```

PHPUnit 10 está configurado y activo. Los tests se ejecutan automáticamente en el pipeline CI.

### Reglas para agregar dependencias

**Siempre discutir con el usuario antes de agregar una nueva dependencia.**

Checklist de evaluación:

| Criterio          | Pregunta a responder                                               |
| ----------------- | ------------------------------------------------------------------ |
| **Necesidad**     | ¿Se puede resolver sin una dependencia externa?                    |
| **Mantenimiento** | ¿El paquete tiene mantenimiento activo? (última release < 6 meses) |
| **Licencia**      | ¿Es compatible con el proyecto? (MIT, Apache 2.0 preferidas)       |
| **Tamaño**        | ¿Cuántas sub-dependencias trae?                                    |
| **Seguridad**     | ¿Tiene vulnerabilidades conocidas? (`composer audit`)              |
| **Alternativas**  | ¿Existen opciones más ligeras o nativas de PHP?                    |

### Comandos seguros

```bash
# Instalar dependencias (NUNCA usar fuera del contenedor Docker)
docker exec -it audfact-php composer install

# Agregar dependencia de producción
docker exec -it audfact-php composer require vendor/package

# Agregar dependencia de desarrollo
docker exec -it audfact-php composer require --dev vendor/package

# Verificar vulnerabilidades
docker exec -it audfact-php composer audit

# Actualizar un paquete específico
docker exec -it audfact-php composer update vendor/package
```

### Archivos de Composer

| Archivo         | ¿Commitear? | Notas                              |
| --------------- | ----------- | ---------------------------------- |
| `composer.json` | ✅ Sí       | Fuente de verdad de dependencias   |
| `composer.lock` | ✅ Sí       | Garantiza versiones reproducibles  |
| `vendor/`       | ❌ No       | Se regenera con `composer install` |

> **Nota**: `composer.lock` se commitea normalmente para garantizar builds reproducibles.

---

## Despliegue, CI/CD y Rollback

> 📖 **Ver documento dedicado:** [plans/deployment-and-ci.md](file:///c:/Users/USER/Desktop/AudFact/plans/deployment-and-ci.md)

### Contexto operativo CI/CD vigente

- Producción LAN despliega por GitHub Actions con runner `self-hosted` label `audfact-prod-lan`, imágenes GHCR por SHA y `docker-compose.yml` con perfil `frontend`.
- El workflow `.github/workflows/deploy-production.yml` regenera `/home/admon/audfact-prod/.env` en cada deploy desde GitHub Secrets/Variables.
- Incidente resuelto el 2026-05-13: `DB_HOST`/`DB2_HOST` llegaron con instancia embebida (ej: `<IP>SQL2022`), provocando `Login timeout expired`, `php` unhealthy y workers reiniciando.
- Corrección permanente: GitHub Variables `production` quedaron con host/IP limpio (sin instancia ni puerto).
- Guardrail actual: el workflow normaliza `host\instancia` a host base, rechaza hosts malformados, ejecuta preflight PDO/sqlsrv antes de recrear contenedores y falla temprano si SQL no conecta.
- Última verificación conocida: workflow `Deploy Production - AudFact` run `25812026509` completó exitosamente con `Preflight SQL connectivity`, `Start production stack` y `Health check`.

---

## Glosario de Dominio

> 📖 **Ver documento dedicado:** [plans/domain-glossary.md](file:///c:/Users/USER/Desktop/AudFact/plans/domain-glossary.md)

---

## Decisiones de Arquitectura (ADR)

> 📖 **Ver documento dedicado:** [plans/architecture-decisions.md](file:///c:/Users/USER/Desktop/AudFact/plans/architecture-decisions.md)

---

## Límites de Responsabilidad del Agente

### Acciones autónomas (sin necesidad de aprobación)

| Acción                                       | Condición                                         |
| -------------------------------------------- | ------------------------------------------------- |
| Leer archivos del proyecto                   | Siempre                                           |
| Ejecutar `git status`, `git log`, `git diff` | Siempre                                           |
| Ejecutar tests existentes                    | Siempre                                           |
| Verificar `docker compose ps` / logs         | Siempre                                           |
| Aplicar formato de código (Prettier/lint)    | Solo si los cambios son exclusivamente de formato |
| Corregir typos en documentación              | Si no cambia el significado                       |
| Agregar PHPDoc a métodos existentes          | Si no cambia la firma del método                  |
| Avanzar tarjeta de Review→QA                 | Solo si complejidad es Baja                       |

### Acciones que REQUIEREN aprobación

| Acción                                                | Por qué                                         |
| ----------------------------------------------------- | ----------------------------------------------- |
| Crear/modificar código PHP                            | Todo cambio de código requiere plan aprobado    |
| Agregar dependencias Composer                         | Puede afectar estabilidad y tamaño del proyecto |
| Modificar `docker-compose.yml` o `Dockerfile`         | Afecta el entorno de todos                      |
| Crear/eliminar branches Git                           | Impacta el flujo de trabajo                     |
| Merge a `develop` o `main`                            | Punto de no retorno                             |
| Crear tags/releases                                   | Marca versions oficiales                        |
| Resolver conflictos de merge                          | El usuario debe ver ambos lados                 |
| Modificar variables de entorno                        | Pueden romper conectividad                      |
| Cambios en prompts de Gemini                          | Afectan la calidad de las auditorías            |
| Modificar schemas de la base de datos                 | Impacto en datos existentes                     |
| Cambiar configuración de seguridad (CORS, rate limit) | Impacto en producción                           |

### Acciones PROHIBIDAS (nunca, bajo ninguna circunstancia)

| Acción                                          | Razón                         |
| ----------------------------------------------- | ----------------------------- |
| Ejecutar queries DELETE/DROP/TRUNCATE en BD     | Pérdida de datos irreversible |
| Publicar secrets o API keys en código/logs      | Vulnerabilidad de seguridad   |
| Hacer `git push --force`                        | Destruye historial            |
| Instalar paquetes del sistema (`apt-get`, etc.) | Fuera del scope del agente    |
| Modificar archivos en `vendor/`                 | Gestionado por Composer       |
| Acceder a servidores externos no documentados   | Sin autorización              |
| Desactivar rate limiting o CORS en producción   | Riesgo de seguridad           |

---

## Compatibilidad de Archivos de Instrucciones

Este archivo (`AGENTS.md`) es la fuente canónica de guidelines. Los siguientes archivos deben mantenerse en sincronía:

| Archivo        | Plataforma             | Notas                                   |
| -------------- | ---------------------- | --------------------------------------- |
| `AGENTS.md`    | Genérico / Antigravity | Fuente canónica ✅                      |
| `CLAUDE.md`    | Claude Code            | Debe ser copia o symlink de `AGENTS.md` |
| `GEMINI.md`    | Gemini CLI             | Puede apuntar al catálogo de skills     |
| `.cursorrules` | Cursor                 | Versión compacta para Cursor IDE        |

---

## Tooling y Comandos Útiles

### GitHub CLI (`gh`)
- **Lectura de issues largos:** Al usar `gh issue view`, la terminal y el paginador pueden truncar la salida. Para extraer el cuerpo completo de manera confiable (especialmente para SDD y specs largas), usa SIEMPRE:
  ```bash
  gh issue view <id> --json body --jq '.body'
  ```
