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
| Frontend | HTML/JS (`AuditBatch.html`, `admin.html`) |
| Dependencias | Guzzle 7.x, firebase/php-jwt 7.x |

## Estructura del Proyecto

```
AudFact/
├── app/
│   ├── Controllers/       # 7 controladores REST
│   ├── Models/            # 5 modelos SQL Server
│   ├── Services/          # Google Drive + 6 servicios de auditoría IA
│   ├── worker/            # GeminiAuditService (orquestador IA)
│   ├── Routes/            # web.php (definición de rutas)
│   └── wrap/              # Integración MCP (4 tools)
├── core/                  # Framework: Router, DB, Validator, Response, Logger...
├── public/                # Entry point (index.php) + frontend HTML
├── docker/                # Dockerfile + nginx.conf
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

- Docker + Docker Compose
- SQL Server con base de datos de dispensación
- API Key de Google Gemini
- Credenciales de servicio Google Drive (JSON)

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
| `DB_TYPE` | Tipo de BD (`sqlsrv`) |
| `DB_HOST` | Host de SQL Server |
| `DB_PORT` | Puerto (default: `1433`) |
| `DB_DATABASE` | Nombre de la base de datos |
| `DB_USERNAME` | Usuario de BD |
| `DB_PASSWORD` | Contraseña de BD |
| `GEMINI_API_KEY` | API Key de Google Gemini |
| `GOOGLE_PROJECT_ID` | ID proyecto Google Cloud |
| `GOOGLE_CLIENT_EMAIL` | Email cuenta de servicio |
| `GOOGLE_PRIVATE_KEY` | Clave privada |
| `LOG_LEVEL` | Nivel de log (`debug`, `info`, `error`) |
| `CORS_ALLOWED_ORIGINS` | Orígenes permitidos |

## API

Base URL: `http://localhost:80`

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/` | Health check |
| `GET` | `/api/clients` | Listar clientes |
| `GET` | `/api/clients/{id}` | Obtener cliente |
| `GET` | `/api/invoices` | Buscar facturas |
| `GET` | `/api/dispensation/{id}` | Datos de dispensación |
| `GET` | `/api/attachments/{id}` | Listar adjuntos |
| `GET` | `/api/attachments/{id}/document/{docId}` | Descargar documento |
| `POST` | `/api/audit` | Auditoría individual |
| `POST` | `/api/audit/batch` | Auditoría en lote |
| `GET` | `/api/audit/status/{id}` | Estado de batch |
| `GET` | `/api/audit/results/{id}` | Resultados de auditoría |
| `POST` | `/wrap/webhook.php` | Endpoint MCP |

> Ver documentación detallada en [`plans/api-endpoints.md`](plans/api-endpoints.md)

## Docker

```bash
# Levantar servicios
docker-compose up -d

# Ver logs
docker-compose logs -f

# Reconstruir
docker-compose up -d --build
```

Servicios: `audfact-php` (PHP 8.2-FPM) + `audfact-nginx` (Nginx 1.25)

## Documentación

Documentación completa disponible en `plans/`:

- [Overview](plans/overview.md) — Visión general
- [Arquitectura](plans/architecture.md) — Componentes y diseño
- [Diagramas C4](plans/architecture-diagrams.md) — Diagramas de arquitectura
- [Flujos de Datos](plans/data-flows.md) — Diagramas de secuencia
- [API Endpoints](plans/api-endpoints.md) — Contratos de API
- [Database Schema](plans/database-schema.md) — Tablas y relaciones
- [Changelog](plans/changelog.md) — Historial de cambios

## Seguridad

- Rate limiting por IP (archivo)
- Validación de entrada vía `Validator`
- Prepared statements (PDO) — Sin SQL injection
- CORS configurable
- Whitelist de campos (`$fillable`) en modelos
- Logging estructurado con rotación diaria

## Licencia

Uso interno — Software propietario.
