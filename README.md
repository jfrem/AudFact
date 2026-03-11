# AudFact — Sistema de Auditoría Documental Automatizada

Sistema de auditoría documental automatizada para el sector salud colombiano. Compara documentos escaneados (Actas de Entrega) contra datos de dispensación en SQL Server, utilizando **Google Gemini Flash** como motor de análisis multimodal (IA + OCR).

## Stack Tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2-FPM — Framework MVC custom |
| Base de datos | SQL Server (PDO `sqlsrv`) |
| IA | Google Gemini Flash API |
| Almacenamiento | Google Drive (JWT) + BLOB en BD |
| Web Server | Nginx 1.25 → PHP-FPM |
| Contenedores | Docker Compose |
| Frontend | Next.js 16 (React 19) + Tailwind CSS + shadcn/ui |
| Dependencias | Guzzle 7.x, firebase/php-jwt 7.x |

## Estructura del Proyecto

```
AudFact/
├── frontend/              # Frontend SPA en Next.js (App Router)
├── app/
│   ├── Controllers/       # 8 controladores REST
│   ├── Models/            # 6 modelos SQL Server
│   ├── Services/          # Google Drive + 10 servicios de auditoría IA
│   ├── Services/Audit/    # AuditOrchestrator (orquestador IA)
│   ├── Routes/            # web.php (definición de rutas)
│   └── wrap/              # Integración MCP (4 tools)
├── core/                  # Framework: Router, DB, Validator, Response, Logger...
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
- SQL Server con base de datos de dispensación
- API Key de Google Gemini.
- Credenciales de servicio Google Drive (JSON).

### Instalación

```bash
# 1. Configurar entorno
cp .env.example .env
# Editar .env con credenciales

# 2. Instalar dependencias
composer install

# 3. Levantar con Docker
docker-compose up -d
```

### Variables de Entorno

| Variable | Descripción |
|---|---|
| `APP_ENV` | Entorno (`development`, `production`) |
| `NEXT_PUBLIC_API_URL` | URL pública de la API consumida por el frontend Next.js |
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

## API

Base URL: `http://localhost:8080`

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/` | Health check |
| `GET` | `/health` | Estado de salud del backend |
| `GET` | `/clients` | Listar clientes |
| `GET` | `/clients/{clientId}` | Obtener cliente |
| `GET` | `/invoices` | Buscar facturas |
| `POST` | `/invoices` | Buscar facturas por body JSON |
| `GET` | `/dispensation/{DisDetNro}` | Datos de dispensación |
| `GET` | `/dispensation/{invoiceId}/attachments/{nitSec}` | Listar adjuntos |
| `GET` | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | Descargar/previsualizar adjunto |
| `POST` | `/audit` | Auditoría en lote |
| `POST` | `/audit/single` | Auditoría individual |
| `GET` | `/audit/results` | Resultados persistidos de auditoría |
| `GET` | `/audit/documents-history` | Historial de documentos auditados (alineado) |
| `POST` | `/app/wrap/webhook.php` | Endpoint MCP |

> Ver documentación detallada en [`plans/api-endpoints.md`](plans/api-endpoints.md)

### Nota de Optimización (Pipeline IA)

El pipeline de auditoría (`POST /audit` y `POST /audit/single`) aplica prefiltrado SQL
de adjuntos requeridos (`AdjDisOpc='N'`) antes de preparar archivos para Gemini.

- Objetivo: reducir I/O y volumen de documentos procesados por la IA.
- Alcance: solo flujo interno de auditoría.
- Importante: el endpoint público `GET /dispensation/{invoiceId}/attachments/{nitSec}`
  conserva el listado completo de adjuntos para UX/operación.

## Docker

### Desarrollo local (recomendado)

```bash
docker compose -f docker-compose.dev.yml up -d --build

# Ver estado y logs (dev)
docker compose -f docker-compose.dev.yml ps
docker compose -f docker-compose.dev.yml logs -f

# Detener entorno dev
docker compose -f docker-compose.dev.yml down
```

### Modo HA / stress testing

```bash
# Levantar stack HA
docker compose -f docker-compose.ha.yml up -d --build

# Ver estado y logs (HA)
docker compose -f docker-compose.ha.yml ps
docker compose -f docker-compose.ha.yml logs -f

# Detener entorno HA
wsl docker compose -f docker-compose.ha.yml down
```

Servicios (dev): `php` + `nginx` (1 replica por servicio).
Servicios (ha): `php` (5 replicas declaradas) + `nginx` con balanceo via template.

Estado actual de configuración: `docker-compose.yml` conserva la intención de topología HA en su definición, pero el workflow de producción ejecuta `docker compose up --build -d` sobre un runner self-hosted. Eso valida y levanta el stack, pero no debe comunicarse como orquestación HA garantizada por sí sola.
El build de `php` usa `ENABLE_XDEBUG` por entorno: en `docker-compose.dev.yml` está en `1` (debug activo) y en `docker-compose.yml` / `docker-compose.ha.yml` está en `0` (debug deshabilitado).
En `APP_ENV=production`, el logger escribe en `stderr` (logs del contenedor). El compose de producción (`docker-compose.yml`) monta únicamente `./logs:/var/www/html/logs` para persistir logs en el host; el código fuente vive dentro de la imagen (Zero-Source).
El contenedor PHP usa un healthcheck empaquetado en `/usr/local/bin/audfact-healthcheck.php`, evitando depender de rutas eliminadas durante el build final.

Nota operativa: si `nginx` falla con `unexpected end of file`, validar que `docker/nginx-ha.conf.template` tenga saltos de linea reales (LF) y no secuencias literales `\r\n`.

### Frontend (Deploy Runner)

El frontend se despliega con un workflow independiente en runner self-hosted:

- Workflow: `.github/workflows/deploy-frontend.yml`
- Compose: `docker-compose.frontend.yml`
- Imagen: `frontend/Dockerfile` (Next.js standalone)
- Puerto: `3000`
- Gate previo: `lint` + `build` en `ubuntu-latest` antes del deploy al runner self-hosted

### Seguridad pendiente

La autenticación/autorización de endpoints críticos queda diferida a un sprint posterior. Este release endurece el pipeline de despliegue y el transporte a SQL Server, pero no cambia todavía la exposición funcional de `/clients*`, `/invoices*`, `/dispensation*` y `/audit*`.
Además, la validación estricta de certificados SQL Server queda temporalmente diferida: el despliegue en este entorno opera sin cifrado SQL Server y con `TrustServerCertificate=yes` hasta que infraestructura remedie TLS correctamente.

Comando manual equivalente:

```bash
NEXT_PUBLIC_API_URL=http://localhost:8080 docker compose -f docker-compose.frontend.yml up --build -d
```

## Documentación

Documentación completa disponible en `plans/`:

- [Overview](plans/overview.md) — Visión general.
- [Arquitectura](plans/architecture.md) — Componentes y diseño.
- [Diagramas C4](plans/architecture-diagrams.md) — Diagramas de arquitectura.
- [Flujos de Datos](plans/data-flows.md) — Diagramas de secuencia.
- [API Endpoints](plans/api-endpoints.md) — Contratos de API.
- [Database Schema](plans/database-schema.md) — Tablas y relaciones.
- [Changelog](plans/changelog.md) — Historial de cambios.

## Seguridad

- Rate limiting por IP (archivo).
- Validación de entrada vía `Validator`.
- Prepared statements (PDO) — Sin SQL injection.
- CORS configurable.
- Whitelist de campos (`$fillable`) en modelos.
- Logging estructurado con rotación diaria.

## Licencia

Uso interno — Software propietario.
















