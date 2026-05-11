# AudFact — Sistema de Auditoría Documental Automatizada

Sistema de auditoría documental automatizada para el sector salud colombiano. Compara documentos escaneados (Actas de Entrega) contra datos de dispensación en SQL Server, utilizando **Google Gemini API** como motor de análisis multimodal (IA + OCR) con modelo configurable por entorno.

## Stack Tecnológico.

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2-FPM — Framework MVC custom |
| Base de datos | SQL Server (PDO `sqlsrv`) |
| IA | Google Gemini API (`GEMINI_MODEL`) |
| Almacenamiento | Google Drive (JWT) + BLOB en BD |
| Web Server | Nginx 1.25 → PHP-FPM |
| Contenedores | Docker Compose |
| Frontend | Next.js 16 (React 19) + Tailwind CSS + shadcn/ui |
| Dependencias | Guzzle 7.x, firebase/php-jwt 7.x |

## Estructura del Proyecto.

```
AudFact/
├── frontend/              # Frontend SPA en Next.js (App Router)
├── app/
│   ├── Controllers/       # 11 controladores HTTP (incluye base)
│   ├── Models/            # 7 modelos SQL Server (incluye base)
│   ├── Services/          # Google Drive + pipeline event-driven de auditoría IA
│   ├── Services/Audit/    # Pipeline/ (workers, policy, agregación y persistencia)
│   ├── Routes/            # web.php (definición de rutas)
│   └── wrap/              # Integración MCP (4 tools)
├── bin/                   # Workers CLI event-driven
├── core/                  # Framework: Router, DB, Validator, Response, Logger, RedisClient...
├── public/                # Entry point (index.php API)
├── docker/                # Dockerfile + nginx.conf + nginx-ha.conf.template + healthcheck
├── logs/                  # Logs rotados por fecha
├── plans/                 # Documentación del proyecto
│   ├── overview.md
│   ├── architecture.md
│   ├── architecture-diagrams.md
│   ├── data-flows.md
│   ├── api-endpoints.md
│   ├── database-schema.md
│   ├── changelog.md
│   └── features/
└── tests/                 # Tests
```

## Inicio Rápido

### Prerrequisitos

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
NEXT_PUBLIC_API_URL=http://localhost:8080 npm run dev
```

### Variables de Entorno

| Variable | Descripción |
|---|---|
| `APP_ENV` | Entorno (`development`, `production`) |
| `NEXT_PUBLIC_API_URL` | URL pública de la API consumida por el frontend Next.js |
| `INTERNAL_API_URL` | URL interna usada por Next.js para SSR/API server-side |
| `AUDFACT_FRONTEND_HOST_PORT` | Puerto LAN del host para publicar el frontend productivo (default: `3100`) |
| `DB_TYPE` | Tipo de BD (`sqlsrv`) |
| `DB_HOST` | Host de SQL Server |
| `DB_PORT` | Puerto (default: `1433`) |
| `DB_NAME` | Nombre de la base de datos |
| `DB_USER` | Usuario de BD |
| `DB_PASS` | Contraseña de BD |
| `DB_ENCRYPT` | Cifrado SQL Server principal (`no` temporal en este entorno) |
| `DB_TRUST_SERVER_CERT` | Trust del certificado SQL Server principal (`yes` temporal mientras no exista certificado válido) |
| `DB2_ENCRYPT` | Cifrado SQL Server de lectura (`no` temporal en este entorno) |
| `DB2_TRUST_SERVER_CERT` | Trust del certificado SQL Server de lectura (`yes` temporal mientras no exista certificado válido) |
| `GEMINI_API_KEY` | API Key de Google Gemini |
| `GOOGLE_DRIVE_CLIENT_EMAIL` | Email cuenta de servicio |
| `GOOGLE_DRIVE_PRIVATE_KEY` | Clave privada |
| `LOG_LEVEL` | Nivel de log (`error`, `warning`, `info`) |
| `ALLOWED_ORIGINS` | Origenes CORS permitidos (comma-separated) |
| `MCP_WEBHOOK_SECRET` | Secreto para `X-API-KEY` del webhook MCP |

### Minimo para Produccion

- `APP_ENV=production`
- Definir `ALLOWED_ORIGINS` con dominios explicitos (sin `*`).
- Definir `MCP_WEBHOOK_SECRET` robusto (aleatorio y largo).
- Definir `DB_PASS` y `GEMINI_API_KEY` reales por entorno.
- Definir `DB_ENCRYPT=no`, `DB_TRUST_SERVER_CERT=yes`, `DB2_ENCRYPT=no` y `DB2_TRUST_SERVER_CERT=yes` mientras la infraestructura SQL Server siga fallando con TLS.
- Migrar a `DB_ENCRYPT=yes`, `DB2_ENCRYPT=yes`, `DB_TRUST_SERVER_CERT=no` y `DB2_TRUST_SERVER_CERT=no` cuando el servidor tenga certificado verificable.
- Ajustar `LOG_LEVEL` (normalmente `warning` o `error` en produccion).
- Publicar el frontend en un puerto propio, por defecto `AUDFACT_FRONTEND_HOST_PORT=3100`, y permitir ese origen en CORS.
- Para despliegue por GitHub Actions, definir el environment `production`, el runner self-hosted `audfact-prod-lan` y los secrets requeridos por `.github/workflows/deploy-production.yml`.

## API

Base URL: `http://localhost:8080`

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/` | Estado base del API |
| `GET` | `/health` | Estado de salud del backend |
| `GET` | `/config/public` | Configuración pública del frontend |
| `GET` | `/clients` | Listar clientes |
| `GET` | `/clients/{clientId}` | Obtener cliente |
| `POST` | `/clients` | Buscar cliente por `clientId` |
| `GET` | `/invoices` | Buscar facturas |
| `POST` | `/invoices` | Buscar facturas por body JSON |
| `GET` | `/dispensation/{DisDetNro}` | Datos de dispensación |
| `GET` | `/dispensation/{invoiceId}/attachments/{nitSec}` | Listar adjuntos |
| `GET` | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | Descargar/previsualizar adjunto |
| `POST` | `/dispensation` | Buscar dispensación por body JSON |
| `POST` | `/audit/single` | Auditoría individual |
| `POST` | `/audit/async` | Auditoría en lote asíncrona |
| `GET` | `/audit/jobs/{jobId}` | Estado de auditoría asíncrona |
| `GET` | `/audit/dlq` | Listado de eventos fallidos definitivos |
| `POST` | `/audit/dlq/reprocess` | Reproceso administrativo de un evento DLQ |
| `GET` | `/audit/results` | Resultados persistidos de auditoría |
| `GET` | `/audit/documents-history` | Historial de documentos auditados (alineado) |
| `POST` | `/app/wrap/webhook.php` | Endpoint MCP |

> Ver documentación detallada en [`plans/api-endpoints.md`](plans/api-endpoints.md)

### Nota de Optimización (Pipeline IA)

El pipeline de auditoría (`POST /audit/single` y `POST /audit/async`) aplica prefiltrado SQL
de adjuntos requeridos (`AdjDisOpc='N'`) antes de preparar archivos para Gemini.

- Objetivo: reducir I/O y volumen de documentos procesados por la IA.
- Alcance: solo flujo interno de auditoría.
- Importante: el endpoint público `GET /dispensation/{invoiceId}/attachments/{nitSec}`
  conserva el listado completo de adjuntos para UX/operación.

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

### Produccion

```bash
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml pull
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

`docker-compose.yml` conserva la topología local con build desde el repo. `docker-compose.prod.yml` usa imagenes publicadas en GHCR y no construye en el servidor.
El frontend productivo usa la imagen `audfact-frontend` y publica el contenedor Next.js interno `:3000` en el puerto LAN `${AUDFACT_FRONTEND_HOST_PORT:-3100}`. En el servidor actual queda disponible como `http://172.16.0.3:3100`.
El build de `php` usa `ENABLE_XDEBUG=0` por defecto para evitar Xdebug en runtime productivo.
En `APP_ENV=production`, el logger escribe en `stderr` (logs del contenedor). El compose productivo monta `./logs:/var/www/html/logs` y `./responseIA:/var/www/html/responseIA`; el código fuente vive dentro de la imagen (Zero-Source).
El contenedor PHP usa un healthcheck empaquetado en `/usr/local/bin/audfact-healthcheck.php`, evitando depender de rutas eliminadas durante el build final.

Nota operativa: si `nginx` falla con `unexpected end of file`, validar que `docker/nginx-ha.conf.template` tenga saltos de linea reales (LF) y no secuencias literales `\r\n`.

### Produccion con GitHub Actions en LAN

El despliegue productivo esta separado en tres workflows:

- `.github/workflows/ci.yml`: valida PHP, Composer, estructura, secretos hardcodeados y PHPUnit.
- `.github/workflows/publish-images.yml`: construye `audfact-php`, `audfact-nginx` y `audfact-frontend`, y publica tags `latest` y `${GITHUB_SHA}` en GHCR.
- `.github/workflows/deploy-production.yml`: corre en el runner self-hosted `audfact-prod-lan`, genera `.env`, hace `docker compose pull`, levanta `docker-compose.prod.yml` y valida `/health` + `/clients`.

El servidor no necesita IP publica ni SSH expuesto. El runner debe vivir dentro de la LAN y tener salida HTTPS a GitHub/GHCR.

Rollback manual:

```bash
# Desde GitHub Actions > Deploy Production - AudFact > Run workflow
# image_tag = SHA previamente desplegado
```

### Frontend

El frontend Next.js vive versionado dentro de `frontend/`. En CI se valida con `.github/workflows/frontend-ci.yml` y en produccion se publica como imagen GHCR `ghcr.io/jfrem/audfact-frontend:<sha>`.

- Runtime interno del contenedor: `3000`.
- Puerto LAN por defecto del proyecto: `3100`.
- URL productiva actual: `http://172.16.0.3:3100`.
- Healthcheck interno del frontend: `/api/health`.
- `INTERNAL_API_URL=http://nginx` se inyecta en compose para que SSR consuma la API dentro de la red Docker.
- `NEXT_PUBLIC_API_URL` queda baked en el build para llamadas desde el navegador.

### Seguridad pendiente

La autenticación/autorización de endpoints críticos queda diferida a un sprint posterior. Este release endurece el pipeline de despliegue y el transporte a SQL Server, pero no cambia todavía la exposición funcional de `/clients*`, `/invoices*`, `/dispensation*` y `/audit*`.
Además, la validación estricta de certificados SQL Server queda temporalmente diferida: el despliegue en este entorno opera sin cifrado SQL Server y con `TrustServerCertificate=yes` hasta que infraestructura remedie TLS correctamente.

## Documentación

Documentación completa disponible en `plans/`:

- [Overview](plans/overview.md) — Visión general.
- [Arquitectura](plans/architecture.md) — Componentes y diseño.
- [Diagramas C4](plans/architecture-diagrams.md) — Diagramas de arquitectura.
- [Flujos de Datos](plans/data-flows.md) — Diagramas de secuencia.
- [API Endpoints](plans/api-endpoints.md) — Contratos de API.
- [Database Schema](plans/database-schema.md) — Tablas y relaciones.
- [Deployment GitHub Actions LAN](plans/deployment-github-actions-lan.md) — Despliegue con runner self-hosted en red privada.
- [Changelog](plans/changelog.md) — Historial de cambios.

## Seguridad

- Rate limiting por IP (APCu con fallback a archivo).
- Validación de entrada vía `Validator`.
- Prepared statements (PDO) — Sin SQL injection.
- CORS configurable.
- Whitelist de campos (`$fillable`) en modelos.
- Logging estructurado con rotación diaria.

## Licencia

Uso interno — Software propietario.
















