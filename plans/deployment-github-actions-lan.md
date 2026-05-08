# Despliegue GitHub Actions en Servidor LAN

## Objetivo

Desplegar AudFact en un servidor sin IP publica usando GitHub Actions sin exponer SSH a Internet.

## Estrategia

El despliegue usa un runner self-hosted dentro de la LAN:

1. `CI - AudFact` valida PHP, Composer, estructura y PHPUnit.
2. `Publish Images - AudFact` construye imagenes inmutables y las publica en GHCR:
   - `ghcr.io/jfrem/audfact-php:<sha>`
   - `ghcr.io/jfrem/audfact-nginx:<sha>`
3. `Deploy Production - AudFact` corre en `audfact-prod-lan`, descarga esas imagenes y levanta `docker-compose.prod.yml`.

## Runner LAN

Configurar el runner con labels:

```text
self-hosted
audfact-prod-lan
```

Requisitos del host:

- Docker Engine y Docker Compose plugin.
- Salida HTTPS a `github.com` y `ghcr.io`.
- Acceso LAN a SQL Server.
- Usuario del runner con permisos para Docker.
- Directorio persistente de despliegue, por defecto: `$HOME/audfact-prod`.

Variable opcional de GitHub Environment:

| Variable | Uso |
|---|---|
| `AUDFACT_DEPLOY_DIR` | Sobrescribe el directorio persistente del servidor. Si no existe, usa `$HOME/audfact-prod`. |

## GitHub Environment

Crear environment `production` con aprobacion manual.

Secrets requeridos:

```text
APP_ENV=production
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
DB_ENCRYPT
DB_TRUST_SERVER_CERT
DB2_HOST
DB2_PORT
DB2_NAME
DB2_USER
DB2_PASS
DB2_ENCRYPT
DB2_TRUST_SERVER_CERT
GEMINI_API_KEY
ALLOWED_ORIGINS
MCP_WEBHOOK_SECRET
REDIS_PASSWORD
NEXT_PUBLIC_API_URL
```

Secrets condicionales:

```text
GOOGLE_DRIVE_CLIENT_EMAIL
GOOGLE_DRIVE_PRIVATE_KEY
LOG_LEVEL
AUDIT_NGINX_READ_TIMEOUT
AUDIT_FPM_TERMINATE_TIMEOUT
```

## Flujo de Deploy

```text
push main
  -> CI
  -> publish GHCR images
  -> deploy-production en runner LAN
  -> docker compose pull
  -> docker compose up -d
  -> curl http://localhost:8080/health
```

## Rollback

Ejecutar manualmente `Deploy Production - AudFact` desde GitHub Actions y pasar `image_tag` con el SHA anterior.

El workflow actualiza `.env` en el directorio persistente con:

```text
AUDFACT_IMAGE_TAG=<sha>
```

Luego ejecuta:

```bash
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

## Guardrails

- No usar GitHub-hosted runner para entrar a una IP LAN.
- No exponer SSH publico para compensar la falta de IP publica.
- No construir imagenes en el servidor de produccion.
- No imprimir `.env` ni secrets en logs.
- No dejar `responseIA/` dentro del contexto de build Docker.
