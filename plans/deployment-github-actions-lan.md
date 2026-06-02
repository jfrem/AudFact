# Despliegue GitHub Actions en Servidor LAN

## Objetivo

Desplegar AudFact en un servidor sin IP publica usando GitHub Actions sin exponer SSH a Internet.

## Estrategia

El despliegue usa un runner self-hosted dentro de la LAN:

1. `CI - AudFact` valida PHP, Composer, estructura y PHPUnit.
2. `Publish Images - AudFact` construye imagenes inmutables y las publica en GHCR:
   - `ghcr.io/jfrem/audfact-php:<sha>`
   - `ghcr.io/jfrem/audfact-nginx:<sha>`
   - `ghcr.io/jfrem/audfact-frontend:<sha>`
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
| `AUDFACT_FRONTEND_HOST_PORT` | Puerto LAN dedicado para publicar el frontend AudFact. Default: `3100`. |
| `AUDFACT_FRONTEND_PUBLIC_URL` | Origen publico del frontend para CORS. Default: `http://172.16.0.3:${AUDFACT_FRONTEND_HOST_PORT}`. |
| `AUDFACT_API_PUBLIC_URL` | URL publica del backend para generar `WEBHOOK_URL` y `CAPABILITIES_URL`. Default: `http://172.16.0.3:8080`. |

## GitHub Environment

Crear environment `production` con aprobacion manual.

Para SQL Server en produccion, configurar hosts sin instancia ni puerto embebido. El puerto se define por separado en `DB_PORT` y `DB2_PORT`.

```text
DB_HOST=169.46.6.53
DB_PORT=1433
DB2_HOST=169.46.6.55
DB2_PORT=1433
```

Secrets requeridos:

```text
DB_USER
DB_PASS
DB2_USER
DB2_PASS
GEMINI_API_KEY
MCP_WEBHOOK_SECRET
```

Secrets condicionales:

```text
GOOGLE_DRIVE_PRIVATE_KEY
REDIS_PASSWORD
```

Variables requeridas:

```text
DB_HOST
DB_PORT
DB_NAME
DB2_HOST
DB2_PORT
DB2_NAME
```

El Environment `production` puede poblarse desde un `.env` productivo con:

```bash
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

El script escribe GitHub Secrets/Variables; no copia `.env` al servidor. El
workflow regenera `/home/admon/audfact-prod/.env` en cada despliegue.

## Flujo de Deploy

```text
push main
  -> CI
  -> publish GHCR images
  -> deploy-production en runner LAN
  -> generar .env con hosts SQL normalizados
  -> docker compose pull
  -> preflight SQL con la imagen PHP publicada
  -> docker compose up -d
  -> curl http://localhost:8080/health
  -> curl http://localhost:${AUDFACT_FRONTEND_HOST_PORT:-3100}/api/health
  -> curl http://localhost:${AUDFACT_FRONTEND_HOST_PORT:-3100}/api/backend/health
  -> curl http://localhost:${AUDFACT_FRONTEND_HOST_PORT:-3100}/clients
  -> verificar worker-batch activo para procesar audit.batch.inbox
```

## Rollback

Ejecutar manualmente `Deploy Production - AudFact` desde GitHub Actions y pasar `image_tag` con el SHA anterior.

El workflow actualiza `.env` en el directorio persistente con:

```text
AUDFACT_IMAGE_TAG=<sha>
AUDFACT_FRONTEND_HOST_PORT=<puerto>
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
- No configurar `DB_HOST`/`DB2_HOST` como `host\instancia` ni `host,puerto` en produccion; usar host/IP limpio y puerto separado.
- El deploy debe fallar antes de recrear contenedores si `DB_HOST` o `DB2_HOST` no conectan por PDO/sqlsrv.
- `docker-compose.prod.yml` debe levantar `worker-batch`; sin ese servicio, `/audit/async` publica `batch_requested` pero el job queda `pending` sin auditorías.
- Renovar `GEMINI_API_KEY` en el GitHub Environment `production` antes de validar extracciones; una key expirada provoca errores `400 API key expired` en `worker-extraction`.

## Incidente CI/CD 2026-05-13: hosts SQL malformados

### Sintomas observados

- `GET /health` en produccion respondia `status=unhealthy`.
- `GET /dispensation/T38250701547` respondia `500` despues de aproximadamente 30 segundos.
- Los contenedores `php` estaban `unhealthy`.
- Workers `orchestrator`, `extraction` y `aggregator` reiniciaban por `SQLSTATE[HYT00] Login timeout expired`.
- Frontend, Nginx, Redis y runner self-hosted estaban activos.

### Causa raiz

El workflow regeneraba `/home/admon/audfact-prod/.env` desde GitHub Secrets. En ese momento los hosts SQL vivian como secrets de produccion y contenian instancia; el heredoc terminaba generando valores invalidos:

```text
DB_HOST=169.46.6.53SQL2022
DB2_HOST=169.46.6.55SQL2022_REPLICA
```

Desde el contenedor PHP, esos hosts no resolvian/conectaban. Las IP limpias si conectaban por TCP y PDO:

```text
169.46.6.53:1433 OK
169.46.6.55:1433 OK
```

### Resolucion aplicada

- GitHub Variables del Environment `production` actualizadas:
  - `DB_HOST=169.46.6.53`
  - `DB2_HOST=169.46.6.55`
- `.github/workflows/deploy-production.yml` ahora:
  - normaliza `host\instancia` a host base;
  - rechaza hosts malformados como `169.46.6.53SQL2022`;
  - escribe `.env` con `printf`;
  - ejecuta preflight PDO/sqlsrv con la imagen PHP publicada antes de `docker compose up`.
- Verificacion: workflow `Deploy Production - AudFact` run `25812026509` paso `Create production environment file`, `Preflight SQL connectivity`, `Start production stack` y `Health check`.

### Regla para agentes

Si vuelve a fallar `Create production environment file` con `DB_HOST appears malformed`, no tocar contenedores por SSH primero. Corregir las GitHub Variables de `production` y relanzar el workflow.
