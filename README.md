# AudFact — Sistema de Auditoría Documental Automatizada

Sistema de auditoría documental automatizada para el sector salud colombiano. Compara documentos escaneados (Actas de Entrega, Fórmulas Médicas, Autorizaciones) contra datos de dispensación en SQL Server, utilizando un **pipeline event-driven** sobre Redis Streams con **Google Gemini API** como motor de análisis multimodal (IA + OCR).

## Stack Tecnológico

| Capa           | Tecnología                                                                   |
| -------------- | ---------------------------------------------------------------------------- |
| Backend        | PHP 8.2-FPM — Framework MVC custom                                           |
| Base de datos  | SQL Server (PDO `sqlsrv`) — dual: escritura (`default`) + lectura (`db2`)    |
| IA             | Google Gemini API (multimodal, configurable vía `GEMINI_MODEL`)              |
| Pipeline       | Event-driven sobre Redis Streams (7 servicios de worker especializados)      |
| Almacenamiento | Google Drive (JWT) + BLOB en BD                                              |
| Web Server     | Nginx 1.25 → PHP-FPM                                                         |
| Contenedores   | Docker Compose único: build local en desarrollo, imágenes GHCR en producción |
| Frontend       | Next.js 15.5.15 (React 19) + Tailwind CSS + shadcn/ui                        |
| Dependencias   | Guzzle 7.x, firebase/php-jwt 7.x                                             |

## Estructura del Proyecto

```
AudFact/
├── frontend/              # Frontend SPA en Next.js (App Router)
├── app/
│   ├── Controllers/       # Controladores HTTP REST.
│   ├── Models/            # Modelos de acceso a datos SQL Server.
│   ├── Services/          # Google Drive + pipeline event-driven de auditoría IA.
│   │   └── Audit/         # Lógica central del dominio de auditoría.
│   │       └── Pipeline/  # Workers, policy engine, normalización, agregación.
│   ├── Routes/            # web.php — Definición centralizada de rutas.
│   └── wrap/              # Integración MCP.
├── bin/                   # audit-worker.php — launcher unificado de workers.
├── core/                  # Framework custom: Router, DB, Validator, Response, Logger, RedisClient.
├── public/                # Entry point (index.php API).
├── docker/                # Archivos de configuración Docker y Nginx.
├── logs/                  # Logs rotados por fecha.
├── plans/                 # Documentación técnica y especificaciones.
└── tests/                 # Pruebas unitarias e integración — PHPUnit.
```

## Inicio Rápido

### Prerrequisitos.

- Docker + Docker Compose.
- SQL Server con base de datos de dispensación.
- API Key de Google Gemini.
- Credenciales de servicio Google Drive (JSON).

### Instalación

```bash
# 1. Configurar entorno
cp .env.example .env
# Editar .env con credenciales

# 2. Instalar dependencias
composer install

# 3. Levantar backend con Docker
docker compose up -d

# 4. Levantar frontend en desarrollo
cd frontend
npm ci
npm run dev
```

### Variables de Entorno

| Variable                                                               | Descripción                                                                                    |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `APP_ENV`                                                              | Entorno (`development`, `production`)                                                          |
| `AUDFACT_API_PUBLIC_URL`                                               | URL pública del backend usada para URLs MCP/webhook generadas en deploy                        |
| `INTERNAL_API_URL`                                                     | URL interna usada por el proxy Next.js `/api/backend`; en producción Docker usa `http://nginx` |
| `AUDFACT_FRONTEND_PUBLIC_URL`                                          | Origen público del frontend usado por deploy/CORS                                              |
| `AUDFACT_PHP_IMAGE` / `AUDFACT_NGINX_IMAGE` / `AUDFACT_FRONTEND_IMAGE` | Imágenes GHCR usadas por `docker-compose.yml` en produccion                                    |
| `AUDFACT_IMAGE_TAG`                                                    | Tag inmutable de imágenes GHCR para deploy/rollback                                            |
| `AUDFACT_FRONTEND_HOST_PORT`                                           | Puerto LAN del host para publicar el frontend productivo (default: `3100`)                     |
| `NEXT_PUBLIC_APP_NAME` / `NEXT_PUBLIC_DEFAULT_THEME`                   | Configuración pública básica del frontend                                                      |
| `NEXT_PUBLIC_POLLING_JOBS_MS` / `NEXT_PUBLIC_POLLING_HEALTH_MS`        | Intervalos de polling del frontend                                                             |
| `NEXT_PUBLIC_LOCALE` / `NEXT_PUBLIC_TIMEZONE`                          | Locale y zona horaria del frontend                                                             |
| `DB_TYPE`                                                              | Tipo de BD (`sqlsrv`)                                                                          |
| `DB_HOST` / `DB2_HOST`                                                 | Host de SQL Server (escritura / lectura)                                                       |
| `DB_PORT` / `DB2_PORT`                                                 | Puerto (default: `1433`)                                                                       |
| `DB_NAME` / `DB2_NAME`                                                 | Nombre de la base de datos                                                                     |
| `DB_USER` / `DB2_USER`                                                 | Usuario de BD                                                                                  |
| `DB_PASS` / `DB2_PASS`                                                 | Contraseña de BD                                                                               |
| `DB_POOLING` / `DB2_POOLING`                                           | Connection pooling PDO por conexión                                                            |
| `DB_ENCRYPT` / `DB2_ENCRYPT`                                           | Cifrado SQL Server (`no` temporal en este entorno)                                             |
| `DB_TRUST_SERVER_CERT` / `DB2_TRUST_SERVER_CERT`                       | Trust del certificado SQL Server (`yes` temporal)                                              |
| `GEMINI_API_KEY`                                                       | API Key de Google Gemini                                                                       |
| `GEMINI_MODEL`                                                         | Modelo de Gemini a usar (default: `gemini-3.5-flash`)                                          |
| `AUDIT_RESPONSE_IA_ENABLED` / `AUDIT_RESPONSE_IA_DIR`                  | Snapshots Gemini locales; solo persisten en `APP_ENV=development`                              |
| `CB_GEMINI_THRESHOLD` / `CB_GEMINI_COOLDOWN`                           | Umbral y cooldown del circuit breaker Gemini                                                   |
| `REDIS_HOST` / `REDIS_PORT`                                            | Host y puerto de Redis para pipeline async                                                     |
| `REDIS_PASSWORD` / `REDIS_MODE`                                        | Autenticación y modo Redis (`standalone`, `sentinel`, `cluster`)                               |
| `AUDIT_WORKER_BATCH_REPLICAS`                                          | Réplicas del worker de batches (default: `2`)                                                  |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS`                                   | Réplicas de orquestadores async (default: `3`)                                                 |
| `AUDIT_WORKER_DOWNLOADER_REPLICAS`                                     | Réplicas de descargadores de adjuntos (default: `8`)                                           |
| `AUDIT_WORKER_EXTRACTION_REPLICAS`                                     | Réplicas de extractores Gemini (default: `8`)                                                  |
| `AUDIT_WORKER_POLICY_REPLICAS`                                         | Réplicas de evaluación de reglas (default: `2`)                                                |
| `AUDIT_IDEMPOTENCY_KEY_TTL`                                            | TTL en segundos de la barrera `X-Idempotency-Key` (default: `300`)                             |
| `AUDIT_PENDING_RECLAIM_IDLE_MS`                                        | Idle mínimo antes de reclamar eventos pending abandonados (default: `600000`)                  |
| `AUDIT_PENDING_RECLAIM_INTERVAL_MS`                                    | Intervalo de escaneo de pending por worker (default: `30000`)                                  |
| `GOOGLE_DRIVE_CLIENT_EMAIL`                                            | Email cuenta de servicio                                                                       |
| `GOOGLE_DRIVE_PRIVATE_KEY`                                             | Clave privada                                                                                  |
| `LOG_LEVEL`                                                            | Nivel de log (`error`, `warning`, `info`)                                                      |
| `ALLOWED_ORIGINS`                                                      | Origenes CORS permitidos (comma-separated)                                                     |
| `MCP_WEBHOOK_SECRET`                                                   | Secreto para `X-API-KEY` del webhook MCP                                                       |

> Catálogo completo de variables en [`AGENTS.md`](AGENTS.md) y `.env.example`.

### Mínimo para Producción

- `APP_ENV=production`
- Definir `ALLOWED_ORIGINS` con dominios explícitos (sin `*`).
- Definir `MCP_WEBHOOK_SECRET` robusto (aleatorio y largo).
- Definir `DB_PASS`, `DB2_PASS` y `GEMINI_API_KEY` reales por entorno; si la API key de Gemini expira, los extractores fallan y las auditorías terminan en DLQ.
- Definir `DB_ENCRYPT=no`, `DB_TRUST_SERVER_CERT=yes`, `DB2_ENCRYPT=no` y `DB2_TRUST_SERVER_CERT=yes` mientras la infraestructura SQL Server siga fallando con TLS.
- Migrar a `DB_ENCRYPT=yes`, `DB2_ENCRYPT=yes`, `DB_TRUST_SERVER_CERT=no` y `DB2_TRUST_SERVER_CERT=no` cuando el servidor tenga certificado verificable.
- Ajustar `LOG_LEVEL` (normalmente `warning` o `error` en producción).
- Publicar el frontend en un puerto propio, por defecto `AUDFACT_FRONTEND_HOST_PORT=3100`, y permitir ese origen en CORS.
- Para despliegue por GitHub Actions, definir el environment `production`, el runner self-hosted `audfact-prod-lan` y los GitHub Secrets/Variables requeridos por `.github/workflows/deploy-production.yml`.

## API

Base URL: `http://localhost:8080`

| Método | Ruta                                                            | Descripción                                                  |
| ------ | --------------------------------------------------------------- | ------------------------------------------------------------ |
| `GET`  | `/`                                                             | Estado base del API                                          |
| `GET`  | `/health`                                                       | Estado de salud del backend                                  |
| `GET`  | `/metrics/async`                                                | Métricas de pipeline asíncrono                               |
| `GET`  | `/config/public`                                                | Configuración pública del frontend                           |
| `GET`  | `/clients`                                                      | Listar clientes                                              |
| `GET`  | `/clients/{clientId}`                                           | Obtener cliente                                              |
| `GET`  | `/clients/{clientId}/documents`                                 | Documentos requeridos por cliente                            |
| `POST` | `/clients`                                                      | Buscar cliente por `clientId`                                |
| `GET`  | `/clients/{clientId}/audit-config`                              | Configuración de auditoría por cliente                       |
| `POST` | `/clients/{clientId}/audit-config`                              | Guardar configuración de auditoría                           |
| `GET`  | `/invoices`                                                     | Buscar facturas pendientes con paginación `page`/`pageSize`  |
| `POST` | `/invoices`                                                     | Buscar facturas por body JSON con el mismo contrato paginado |
| `GET`  | `/dispensation/{DisId}/{DisDetNro}`                             | Datos de dispensación                                        |
| `POST` | `/dispensation`                                                 | Buscar dispensación por body JSON                            |
| `GET`  | `/dispensation/{DisDetNro}/attachments/{nitSec}`                | Listar adjuntos                                              |
| `GET`  | `/dispensation/{DisDetNro}/attachments/download/{attachmentId}` | Descargar/previsualizar adjunto                              |
| `POST` | `/audit/single`                                                 | Auditoría individual por `disDetNro` (con `disId` opcional)  |
| `POST` | `/audit/async`                                                  | Auditoría en lote asíncrona (→ 202)                          |
| `GET`  | `/audit/jobs/{jobId}`                                           | Estado de auditoría asíncrona                                |
| `GET`  | `/audit/status/{auditId}`                                       | Estado Redis de una auditoría individual encolada            |
| `GET`  | `/audit/results`                                                | Resumen paginado de auditorías persistidas                   |
| `GET`  | `/audit/results/{facNro}`                                       | Detalle persistido por FacNro                                |
| `GET`  | `/audit/stats`                                                  | Conteos agregados para dashboard                             |
| `GET`  | `/audit/documents-history`                                      | Historial de documentos auditados                            |
| `GET`  | `/audit/{facNro}/timings`                                       | Timings detallados por factura                               |
| `GET`  | `/audit/dlq`                                                    | Listado de eventos fallidos definitivos                      |
| `POST` | `/audit/dlq/reprocess`                                          | Reproceso administrativo de un evento DLQ                    |
| `POST` | `/app/wrap/webhook.php`                                         | Endpoint MCP                                                 |

> Ver documentación detallada en [`plans/api-endpoints.md`](plans/api-endpoints.md)

### Nota de Optimización (Pipeline IA)

El pipeline de auditoría (`POST /audit/single` y `POST /audit/async`) aplica prefiltrado SQL
de adjuntos requeridos (`AdjDisOpc='N'`) antes de preparar archivos para Gemini.

- Objetivo: reducir I/O y volumen de documentos procesados por la IA.
- Alcance: solo flujo interno de auditoría.
- Importante: el endpoint público `GET /dispensation/{DisDetNro}/attachments/{nitSec}`
  conserva el listado completo de adjuntos para UX/operación.

### Contrato de Identidad de Auditoría

La llave canónica de auditoría es `DisId`:

```text
vw_discolnet_dispensas.DisId == AudDispEst.FacSec (columna legacy)
```

La llave operativa de dispensación/documentos es `DisDetNro`:

```text
DisDetNro == vw_discolnet_dispensas.Dispensa == AudDispEst.FacNro
```

`POST /audit/single` y `POST /audit/async` seleccionan la FDV por `DisId`. Los adjuntos se resuelven por `DisDetNro`; la persistencia guarda `DisId` en la columna legacy `FacSec` y usa `FacNro` (`DisDetNro`) como llave primaria operativa de `AudDispEst`. Ver [`plans/audit-identity-contract.md`](plans/audit-identity-contract.md).

## Pipeline de Auditoría IA

El sistema utiliza un pipeline event-driven sobre Redis Streams con 7 servicios de worker especializados:

```
BatchRequestedWorker → DocumentAuditOrchestrator → AttachmentDownloadWorker → DocumentExtractionWorker → DocumentNormalizer → RulesEvaluationWorker → AuditAggregationWorker
      (batch)              (orchestrator)            (downloader)              (extraction)              (normalizer)         (policy)               (aggregator)
```

Cada worker consume eventos del stream correspondiente, procesa su etapa y publica el resultado al siguiente. El flujo incluye:

- **Extracción**: Descarga de adjuntos, validación estructural con `DocumentIntegrityValidator` y análisis multimodal con Gemini (parallel function calling).
- **Normalización**: Estandarización de valores extraídos (fechas, cantidades, tipos de documento).
- **Políticas**: Comparación campo a campo contra la Fuente de Verdad (FDV), conversión de `document_rejected` en hallazgo canónico `RECHAZADO`, consolidación de outcome final y cálculo de risk score.
- **Agregación**: Validación del outcome, persistencia en SQL Server y publicación de eventos terminales.

Características:

- Cache de extracción por `document_hash` (idempotencia).
- Modelo Gemini base configurable (`GEMINI_MODEL`).
- Soporte nativo para **Thinking Mode** (razonamiento profundo latente) configurable independientemente por tipo de tarea (`GEMINI_EXTRACTION_THINKING_LEVEL`, `GEMINI_SEMANTIC_THINKING_LEVEL`). La clase `GeminiConfig` centraliza esta orquestación y garantiza compatibilidad entre versiones del modelo.
- Fallback semántico vía `ArticleSemanticMatchJudge` para homologación de artículos.
- Dead Letter Queue (DLQ) para eventos irrecuperables con reproceso administrativo.
- Observabilidad por auditoría con telemetría de cola, ejecución, ack, agregación y persistencia final.
- Recuperación periódica de eventos `pending` abandonados en Redis Streams sin robar procesos Gemini en curso.
- Escalado por variables para `worker-batch`, `worker-orchestrator`, `worker-downloader`, `worker-extraction` y `worker-policy` sin perder idempotencia por `DisId`.

## Docker

### Desarrollo local

```bash
docker compose up -d --build

# Ver estado y logs
docker compose ps
docker compose logs -f

# Detener entorno
docker compose down
```

Nginx resuelve `php:9000` con DNS Docker en runtime para evitar `502` por IPs PHP-FPM obsoletas después de un rebuild.

### Producción

```bash
AUDFACT_IMAGE_TAG=<sha> docker compose pull
AUDFACT_IMAGE_TAG=<sha> docker compose --profile frontend up -d --no-build --remove-orphans
```

`docker-compose.yml` es la fuente unica universal: en desarrollo puede construir desde el repo; en produccion usa imagenes publicadas en GHCR, no construye en el servidor y levanta la misma topologia funcional de workers (`batch`, `orchestrator`, `downloader`, `extraction`, `normalizer`, `policy`, `aggregator`) para que `/audit/async` avance.
El frontend productivo usa la imagen `audfact-frontend` y publica el contenedor Next.js interno `:3000` en el puerto LAN `${AUDFACT_FRONTEND_HOST_PORT:-3100}`. En el servidor actual queda disponible como `http://172.16.0.3:3100`.
El build de `php` usa `ENABLE_XDEBUG=0` por defecto para evitar Xdebug en runtime productivo.
En `APP_ENV=production`, el logger escribe en `stderr` (logs del contenedor). El compose monta `./logs:/var/www/html/logs`; el código fuente vive dentro de la imagen (Zero-Source). `responseIA` no tiene volumen dedicado: los snapshots solo se escriben en desarrollo, bajo `AUDIT_RESPONSE_IA_DIR`.
El contenedor PHP usa un healthcheck empaquetado en `/usr/local/bin/audfact-healthcheck.php`, evitando depender de rutas eliminadas durante el build final.

Nota operativa: si `nginx` falla con `unexpected end of file`, validar que `docker/nginx-ha.conf.template` tenga saltos de línea reales (LF) y no secuencias literales `\r\n`.

### Producción con GitHub Actions en LAN

El despliegue productivo está separado en cuatro workflows:

- `.github/workflows/ci.yml`: valida PHP, Composer, estructura, secretos hardcodeados y PHPUnit.
- `.github/workflows/frontend-ci.yml`: valida build del frontend Next.js y bloquea bundles con URLs locales de backend embebidas.
- `.github/workflows/publish-images.yml`: construye `audfact-php`, `audfact-nginx` y `audfact-frontend`, y publica tags `latest` y `${GITHUB_SHA}` en GHCR.
- `.github/workflows/deploy-production.yml`: corre en el runner self-hosted `audfact-prod-lan`, genera `.env`, hace `docker compose pull`, levanta `docker-compose.yml` con `--profile frontend` y valida `/health`, `/api/backend/health` + `/clients`.

El servidor no necesita IP pública ni SSH expuesto. El runner debe vivir dentro de la LAN y tener salida HTTPS a GitHub/GHCR.

Para sincronizar la configuración local hacia el Environment `production` de
GitHub, usar el script seguro:

```bash
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

El script actualiza GitHub Secrets/Variables, no copia `.env` al servidor. El
workflow de deploy regenera `/home/admon/audfact-prod/.env` desde GitHub en cada
despliegue. Para `--apply`, el `.env` fuente debe ser productivo: `APP_ENV=production`,
URLs públicas sin `localhost`, e internos Docker como `INTERNAL_API_URL=http://nginx`.

Rollback manual:

```bash
# Desde GitHub Actions > Deploy Production - AudFact > Run workflow
# image_tag = SHA previamente desplegado
```

### Frontend

El frontend Next.js vive versionado dentro de `frontend/`. En CI se valida con `.github/workflows/frontend-ci.yml` y en producción se publica como imagen GHCR `ghcr.io/jfrem/audfact-frontend:<sha>`.

- Runtime interno del contenedor: `3000`.
- Puerto LAN por defecto del proyecto: `3100`.
- URL productiva actual: `http://172.16.0.3:3100`.
- Healthcheck interno del frontend: `/api/health`.
- `INTERNAL_API_URL=http://nginx` se inyecta en compose para que el proxy `/api/backend/*` consuma la API dentro de la red Docker.
- El navegador y SSR llaman rutas relativas `/api/backend/*`; Next.js reenvía al backend en runtime.

### Seguridad pendiente

La autenticación/autorización de endpoints críticos queda diferida a un sprint posterior. Este release endurece el pipeline de despliegue y el transporte a SQL Server, pero no cambia todavía la exposición funcional de `/clients*`, `/invoices*`, `/dispensation*` y `/audit*`.
Además, la validación estricta de certificados SQL Server queda temporalmente diferida: el despliegue en este entorno opera sin cifrado SQL Server y con `TrustServerCertificate=yes` hasta que infraestructura remedie TLS correctamente.

## Testing

```bash
# Ejecutar suite completa
vendor/bin/phpunit

# Ejecutar test específico
vendor/bin/phpunit --filter="testEvaluateBuildsFindingsAndDocumentDecision"
```

- Tests unitarios y de integración cubriendo: controladores, modelos, normalización, pipeline de auditoría, configuración Gemini y golden-set replay.
- Las aserciones que requieren base de datos o Redis se saltan (skipped) de forma segura si la infraestructura no está disponible en el entorno local.

## Documentación

Documentación completa disponible en `plans/`:

- [Overview](plans/overview.md) — Visión general.
- [Arquitectura](plans/architecture.md) — Componentes y diseño.
- [Diagramas C4](plans/architecture-diagrams.md) — Diagramas de arquitectura.
- [Flujos de Datos](plans/data-flows.md) — Diagramas de secuencia.
- [API Endpoints](plans/api-endpoints.md) — Contratos de API.
- [Database Schema](plans/database-schema.md) — Tablas y relaciones.
- [Docker Operations](plans/docker-operations.md) — Operaciones Docker.
- [Deployment & CI](plans/deployment-and-ci.md) — Pipeline CI/CD.
- [Deployment GitHub Actions LAN](plans/deployment-github-actions-lan.md) — Despliegue con runner self-hosted.
- [High Availability](plans/high-availability.md) — Configuración HA.
- [Git Workflow](plans/git-workflow.md) — Flujo de trabajo Git.
- [Testing Strategy](plans/testing-strategy.md) — Estrategia de pruebas.
- [Domain Glossary](plans/domain-glossary.md) — Glosario de dominio.
- [Architecture Decisions](plans/architecture-decisions.md) — Decisiones de arquitectura.
- [Changelog](plans/changelog.md) — Historial de cambios.

## Seguridad

- Rate limiting por IP (APCu con fallback a archivo).
- Validación de entrada vía `Validator`.
- Prepared statements (PDO) — Sin SQL injection.
- CORS configurable vía `ALLOWED_ORIGINS`.
- Whitelist de campos (`$fillable`) en modelos.
- Logging estructurado con rotación diaria y redacción automática de campos sensibles.
- Webhook MCP autenticado vía `X-API-KEY`.

## Licencia

Uso interno — Software propietario.
