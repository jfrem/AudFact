---
name: audfact-runtime-docker
description: Operar y depurar el runtime Docker de AudFact. Usar cuando se cambien docker-compose.yml, docker-compose.prod.yml, docker/Dockerfile, docker/nginx.conf, variables .env o conectividad entre frontend, Nginx, PHP-FPM, Redis, SQL Server y APIs externas.
---

# AudFact Runtime Docker

## Objetivo
Asegurar que el entorno de ejecución local sea reproducible y diagnosticar fallas rápido.

> [!TIP]
> Consulta la guía de inicio rápido y configuración del entorno en [overview.md](file:///c:/Users/USER/Desktop/AudFact/plans/overview.md#guía-de-inicio-rápido).

## Archivos clave

| Archivo | Rol |
|---|---|
| `docker-compose.yml` | Runtime base con build local: `php` x5, `redis`, `nginx` y workers `batch`, `orchestrator`, `extraction`, `normalizer`, `policy`, `aggregator` |
| `docker-compose.prod.yml` | Producción LAN con imágenes GHCR y frontend Next.js publicado en `${AUDFACT_FRONTEND_HOST_PORT:-3100}` |
| `docker/Dockerfile` | PHP 8.2-FPM + ODBC SQL Server + Xdebug condicional + healthcheck interno |
| `docker/frontend.Dockerfile` | Next.js standalone productivo, publicado como `audfact-frontend` |
| `frontend/next.config.ts` | Config Next.js (debe tener `output: standalone`) |
| `docker/nginx.Dockerfile` | Nginx 1.25 Alpine con assets estáticos baked-in |
| `docker/nginx-ha.conf.template` / `docker/nginx.conf` | Reverse proxy hacia PHP-FPM con DNS Docker runtime |
| `public/index.php` | Bootstrap: env, CORS, rate limit, dispatch |
| `.env` | Variables de entorno locales/secretos; no commitear |
| `.env.example` | Template de variables, incluyendo perfiles Gemini, Redis y workers |

## Arquitectura de red

```
Cliente HTTP (Front LAN:3100) ─▶ Next.js (audfact-frontend:3000)
                                    │
                                    └────▶ API (nginx:8080) ────▶ PHP-FPM:9000
                                                                     │
                                                                     ▼
                                                                SQL Server
```

## Extensiones PHP instaladas
- `sqlsrv` — Driver SQL Server
- `pdo_sqlsrv` — PDO para SQL Server
- `xdebug` — Debug (**condicional**: `ENABLE_XDEBUG=1` en dev, `0` en prod/HA)
- `zip` — Manejo de archivos comprimidos
- `apcu` — Cache en memoria (rate limiting)

## Volúmenes Docker

### Desarrollo (Frontend)
El frontend Next.js en desarrollo suele usar `npm run dev` en el host o un mount completo si se desea Docker-Dev. Para producción local, se usa **Zero-Source** (código baked).

### Runtime base local
| Host | Container | Uso |
|---|---|---|
| `./logs` | `/var/www/html/logs` | Logs rotativos |
| `./responseIA` | `/var/www/html/responseIA` | Snapshots Gemini solo para diagnóstico local/desarrollo |
| *N/A* | Código baked en imagen | No hay mount de código fuente |

### Producción
`docker-compose.prod.yml` monta `./logs` y ejecuta código baked desde imágenes GHCR. El directorio `responseIA/` no se monta en producción.

## Variables .env obligatorias

| Variable | Ejemplo | Uso |
|---|---|---|
| `APP_ENV` | `development` | Entorno (development/production) |
| `DB_HOST` | `host.docker.internal` | Host SQL Server |
| `INTERNAL_API_URL` | `http://nginx` | URL interna usada por el proxy Next.js `/api/backend/*` en producción |
| `AUDFACT_API_PUBLIC_URL` | `http://localhost:8080` | URL pública del backend para generar URLs MCP/webhook en deploy |
| `AUDFACT_FRONTEND_HOST_PORT` | `3100` | Puerto LAN dedicado para el frontend productivo |
| `GEMINI_MODEL` | `gemini-3-flash-preview` | Modelo de auditoría IA |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS` | `4096` | Límite de salida para extracción documental |
| `GEMINI_EXTRACTION_THINKING_LEVEL` | `MINIMAL` | Nivel de razonamiento Gemini 3 para extracción documental |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS` | `2048` | Límite de salida para homologación semántica |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3` | Réplicas del worker que consume `audit_created` |
| `AUDIT_WORKER_BATCH_REPLICAS` | `2` | Réplicas del worker que consume `batch_requested` en `docker-compose.yml` |
| `AUDIT_WORKER_EXTRACTION_REPLICAS` | `8` | Réplicas del worker Gemini; subir con cuidado por cuotas 429/503 |
| `AUDIT_WORKER_POLICY_REPLICAS` | `2` | Réplicas del worker de reglas |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | Idle mínimo antes de reclamar eventos pending abandonados |
| `AUDIT_PENDING_RECLAIM_INTERVAL_MS` | `30000` | Intervalo de escaneo de pending por worker |

## Flujo de revisión
1. Verificar servicios en `docker-compose.yml` y `docker-compose.prod.yml`.
2. Verificar extensiones en `docker/Dockerfile`.
3. Validar `frontend/next.config.ts` (output: standalone).
4. Verificar variables obligatorias en `.env`.

## Reglas de implementación
1. Mantener volúmenes para `logs/`.
2. **No hardcodear secretos** — usar `.env`.
3. SQL Server es **externo** al entorno Docker.
4. No hornear URLs absolutas del backend en bundles `NEXT_PUBLIC_*`; el navegador y SSR deben usar `/api/backend/*`, y solo el route handler debe resolver `INTERNAL_API_URL` en runtime.
5. Escalar workers por variables `.env`, no editando números fijos en `docker-compose*.yml`.
6. No bajar `AUDIT_PENDING_RECLAIM_IDLE_MS` por debajo del peor caso de duración Gemini.
7. Nginx debe resolver `php:9000` en runtime vía `resolver 127.0.0.11`; no volver a upstream estático que cachee IPs de contenedores recreados.

## Anti-patterns ⚠️
1. **No agregar SQL Server a Docker Compose**.
2. **No intentar build de frontend sin `output: standalone`** en `next.config.ts`.
3. **No instalar extensiones PHP sin agregarlas al Dockerfile**.
4. **No usar `docker compose up` sin `--build`** tras cambios en código de imagen inmutable.
5. **No asumir hot reload del backend**: PHP y workers ejecutan código baked en imagen; tras cambios de código se requiere rebuild/recreate del servicio afectado.

## Comandos útiles
```bash
# Rebuild API Backend local
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"

# Inspeccionar réplicas reales de workers
wsl docker compose top worker-batch worker-orchestrator worker-extraction worker-policy

# Deploy producción desde imagenes GHCR (runner LAN)
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml pull
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml up -d --remove-orphans
```

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)
Después de implementar cualquier cambio en Docker o configuración de runtime, ejecutar `audfact-docs-sync`.

## Referencias
1. Ver `plans/docker-operations.md` para troubleshooting detallado.
