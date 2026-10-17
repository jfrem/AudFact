---
name: audfact-runtime-docker
description: Operar y depurar el runtime Docker de AudFact. Usar cuando se cambien docker-compose.yml, docker/Dockerfile, docker/nginx.conf, variables .env o conectividad entre frontend, Nginx, PHP-FPM, Redis, SQL Server y APIs externas.
---

# AudFact Runtime Docker

## Objetivo
Asegurar que el entorno de ejecución local sea reproducible y diagnosticar fallas rápido.

## Protocolo v2 y corte

Execution=1 sigue por defecto. Perfil `execution-v2`: worker-semantic-vip/batch
y execution-maintenance; registry incluye semantic, mantenimiento tiene CLI
propia `bin/audit-execution-maintenance.php`. No activar perfil al instalar
código sin protocolo/cuotas/Redis verificados.
Workflow execution_phase steady/prepare/cutover/rollback, run_id y attestation
checks_passed=false por defecto. Prepare no recrea Redis saludable. Cutover
pausa/drena PHP-FPM (600 s) y todos los consumidores: indisponibilidad HTTP
breve. INFO/CONFIG reales deben probar AOF/fsync always/noeviction; marca
durable en volumen conserva esa política en rollback/recreación.
Slots de persistencia y réplicas existentes se preservan. Observe deja POST
cerrados y ejecuta lo importado; aceptación/canary preceden resume. Antes de
mutar producción aplicar audfact-production-ops y autorización de la sesión.
Nueve variables nuevas se sincronizan por `scripts/sync-execution-v2-github.ps1`;
nueve valores existentes se heredan del repo por límite Environment=100.
Cuotas UNCONFIGURED/0 bloquean v2. Ver SDD y deployment-and-ci para evidencia,
backup/restore productivo, retiro E01 y rollback.

> [!TIP]
> Consulta la guía de inicio rápido y configuración del entorno en [overview.md](file:///c:/Users/USER/Desktop/AudFact/plans/overview.md#guía-de-inicio-rápido).

## Archivos clave

| Archivo | Rol |
|---|---|
| `docker-compose.yml` | Fuente única universal: build local cuando se trabaja en desarrollo, imágenes GHCR en producción, perfil `frontend` y workers `batch`, `orchestrator`, `downloader`, `extraction`, `normalizer`, `policy`, `persistence` |
| `docker/Dockerfile` | PHP 8.2.33-fpm-bookworm + ODBC SQL Server + APCu + Xdebug condicional + healthcheck interno |
| `docker/frontend.Dockerfile` | Next.js standalone productivo, publicado como `audfact-frontend` |
| `frontend/next.config.ts` | Config Next.js (debe tener `output: standalone`) |
| `docker/nginx.Dockerfile` | Nginx 1.25 Alpine con assets estáticos baked-in |
| `docker/nginx-ha.conf.template` / `docker/nginx.conf` | Reverse proxy hacia PHP-FPM con DNS Docker runtime |
| `public/index.php` | Bootstrap: env, CORS, rate limit, dispatch |
| `.env` | Variables de entorno locales/secretos; no commitear |
| `.env.example` | Template de variables, incluyendo perfiles Gemini, Redis y workers |
| `scripts/sync-github-production-env.sh` | Sincroniza `.env` productivo hacia GitHub Environment `production` sin imprimir valores |

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
| `./logs` | `/var/www/html/logs` | Logs rotativos en desarrollo; Core\Logger escribe stderr en producción |
| `./logs/responseIA` | `/var/www/html/logs/responseIA` | Subdirectorio de snapshots Gemini cuando `APP_ENV=development` y `AUDIT_RESPONSE_IA_ENABLED=1` |
| *N/A* | Código baked en imagen | No hay mount de código fuente |

### Producción
`docker-compose.yml` monta `./logs` y ejecuta código baked desde imágenes GHCR. El directorio `responseIA/` no se monta como volumen dedicado; en producción `ResponseIADiskStore` tiene hard-deny por `APP_ENV=production`. La topología incluye `worker-batch`, `worker-orchestrator`, `worker-downloader`, `worker-extraction-vip`, `worker-extraction-batch`, `worker-normalizer`, `worker-policy` y `worker-persistence`. Las réplicas dependen del entorno; los defaults de Compose no prueban las réplicas desplegadas.

## Variables .env obligatorias

| Variable | Ejemplo | Uso |
|---|---|---|
| `APP_ENV` | `development` | Entorno (development/production) |
| `DB_HOST` | `host.docker.internal` | Host SQL Server |
| `INTERNAL_API_URL` | `http://nginx` | URL interna usada por el proxy Next.js `/api/backend/*` en producción |
| `AUDFACT_API_PUBLIC_URL` | `http://localhost:8080` | URL pública del backend para generar URLs MCP/webhook en deploy |
| `AUDFACT_FRONTEND_PUBLIC_URL` | `http://localhost:3100` | Origen público del frontend para CORS/deploy |
| `AUDFACT_PHP_IMAGE` | `ghcr.io/jfrem/audfact-php` | Imagen PHP-FPM/workers usada por producción |
| `AUDFACT_NGINX_IMAGE` | `ghcr.io/jfrem/audfact-nginx` | Imagen Nginx usada por producción |
| `AUDFACT_FRONTEND_IMAGE` | `ghcr.io/jfrem/audfact-frontend` | Imagen Next.js usada por producción |
| `AUDFACT_IMAGE_TAG` | `latest` | Tag GHCR usado por deploy/rollback |
| `AUDFACT_FRONTEND_HOST_PORT` | `3100` | Puerto LAN dedicado para el frontend productivo |
| `NEXT_PUBLIC_APP_NAME` | `AudFact` | Nombre público del producto en frontend |
| `NEXT_PUBLIC_DEFAULT_THEME` | `dark` | Tema público inicial del frontend |
| `NEXT_PUBLIC_POLLING_JOBS_MS` | `5000` | Polling público de jobs |
| `NEXT_PUBLIC_POLLING_HEALTH_MS` | `30000` | Polling público de health |
| `NEXT_PUBLIC_LOCALE` | `es-CO` | Locale público |
| `NEXT_PUBLIC_TIMEZONE` | `America/Bogota` | Zona horaria pública |
| `DB_POOLING` | `1` | Pooling PDO para conexión default |
| `DB2_POOLING` | `1` | Pooling PDO para conexión db2 |
| `REDIS_MAXMEMORY` | `4gb` | Limite interno de memoria Redis (`--maxmemory`) |
| `REDIS_MAXMEMORY_POLICY` | `volatile-lru` | Politica de eviccion Redis para llaves con TTL |
| `REDIS_CONTAINER_MEMORY` | `5G` | Limite de memoria del contenedor Redis |
| `GEMINI_MODEL` | `gemini-3.7-flash` | Default actual de `.env.example`; modelo configurable |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS` | `4096` | Límite de salida para extracción documental |
| `GEMINI_EXTRACTION_THINKING_LEVEL` | Vacío | Opcional low/medium/high para Gemini 3.7; MINIMAL no está soportado |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS` | `2048` | Límite de salida para homologación semántica |
| `AUDIT_RESPONSE_IA_ENABLED` | `1` dev / `0` prod | Habilita snapshots Gemini solo en `APP_ENV=development` |
| `AUDIT_RESPONSE_IA_DIR` | `/var/www/html/logs/responseIA` | Directorio configurable de snapshots locales |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3` | Réplicas del worker que consume `audit_created` |
| `AUDIT_WORKER_BATCH_REPLICAS` | `2` | Réplicas del worker que consume `batch_requested` |
| `AUDIT_WORKER_DOWNLOADER_REPLICAS` | `12` | Default Compose; consume `document_registered` |
| `AUDIT_WORKER_EXTRACTION_VIP_REPLICAS` | `2` | Default Compose del carril prioritario Gemini |
| `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS` | `12` | Default Compose del carril batch; verificar cuotas antes de escalar |
| `AUDIT_WORKER_EXTRACTION_REPLICAS` | `8` | Clave legacy presente en plantilla/deploy; no escala los servicios actuales VIP/batch de Compose |
| `AUDIT_WORKER_NORMALIZER_REPLICAS` | `4` | Default Compose; consume `document_extracted` y publica `document_normalized` |
| `AUDIT_WORKER_POLICY_REPLICAS` | `8` | Default Compose del worker de reglas |
| `AUDIT_WORKER_PERSISTENCE_REPLICAS` | `6` | Réplicas SQL globales; un turno activo por slot |
| `AUDIT_PERSISTENCE_JOB_SLOTS` | `2` | Slots 1..16 fijados en Redis por job; cambiar configuración afecta jobs nuevos |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | Idle mínimo antes de reclamar eventos pending abandonados |
| `AUDIT_PENDING_RECLAIM_INTERVAL_MS` | `30000` | Intervalo de escaneo de pending por worker |
| `AUDIT_JOB_TTL` | `604800` | Retencion de estado de jobs batch async en Redis |
| `AUDIT_STATE_TTL` | `604800` | Retencion de estado transitorio de auditorias en Redis |
| `AUDIT_RESERVATION_TTL` | `86400` | Retencion de reservas por `DisId` |
| `AUDIT_PERSISTENCE_QUEUE_TTL` | `604800` | Retencion de turnos, pendientes y deduplicacion de persistencia |
| `AUDIT_DOCUMENT_CACHE_MAX_BYTES` | `268435456` | Presupuesto global de bytes JSON BLOB; metadata/overhead Redis se miden por separado |
| `AUDIT_DOCUMENT_CACHE_MAX_ENTRIES` | `1024` | Presupuesto global de entradas compartido entre réplicas |

## Flujo de revisión

Para caché documental acotada, el workflow recrea/verifica primero los extractores
VIP y batch; luego habilita stack y downloaders nuevos. La imagen destino debe
incluir `AuditDocumentInput`: el workflow rechaza downgrade a imágenes anteriores
sin recuperación. Un rollback anterior requiere cortar admisión/productores,
drenar extracción/policy, verificar lag/PEL y restaurar por SHA bajo aprobación.
Conservar Redis, DLQ, jobs y contadores; no borrar claves para acelerar la migración.
Los límites son globales; `AUDIT_STREAM_MAXLEN` es meta para trabajo y no poda DLQ.
Ver `plans/ajustes-alto-volumen-2026-10-08.md`.

Para slots de persistencia, desplegar policy/persistence/API coordinadamente después de drenar colas. Reducir réplicas conserva los scopes fijados. Rollback a imágenes sin slots requiere drenar primero; restart no incorpora cambios de entorno. Workflow production inyecta y genera la variable nueva. Ver `plans/sdd-optimizacion-cuello-botella-persistencia.md`.
Metadata perdida se recupera desde scopes, no desde la configuración nueva.
Un job particionado anterior sin N permite drenar auditorías conocidas y
rechaza nuevas; no borrar claves para forzar N. Métricas reconcilian legacy
al primer uso o después de perder el índice y fallan con 503 si no completan.
1. Verificar servicios en `docker-compose.yml`.
2. Verificar extensiones en `docker/Dockerfile`.
3. Validar `frontend/next.config.ts` (output: standalone).
4. Verificar variables obligatorias en `.env`.

## Reglas de implementación
1. Mantener volúmenes para `logs/`.
2. **No hardcodear secretos** — usar `.env`.
3. SQL Server es **externo** al entorno Docker.
4. No hornear URLs absolutas del backend en bundles `NEXT_PUBLIC_*`; el navegador y SSR deben usar `/api/backend/*`, y solo el route handler debe resolver `INTERNAL_API_URL` en runtime.
5. Escalar workers por variables `.env`, no editando números fijos en `docker-compose.yml`.
6. No bajar `AUDIT_PENDING_RECLAIM_IDLE_MS` por debajo del peor caso de duración Gemini.
7. Nginx debe resolver `php:9000` en runtime vía `resolver 127.0.0.11`; no volver a upstream estático que cachee IPs de contenedores recreados.
8. Redis debe mantener `REDIS_MAXMEMORY`, `REDIS_MAXMEMORY_POLICY` y `REDIS_CONTAINER_MEMORY` parametrizados en Compose; no volver a limites hardcodeados de 256mb/300M.

## Anti-patterns ⚠️
1. **No agregar SQL Server a Docker Compose**.
2. **No intentar build de frontend sin `output: standalone`** en `next.config.ts`.
3. **No instalar extensiones PHP sin agregarlas al Dockerfile**.
4. **En desarrollo, reconstruir la imagen antes de recrear servicios tras cambiar código** (`up --build`); en producción, usar `pull` de un SHA publicado y `up --no-build`, conforme a Zero-Source.
5. **No asumir hot reload del backend**: PHP y workers ejecutan código baked en imagen; tras cambios de código se requiere rebuild/recreate del servicio afectado.

## Comandos útiles
```bash
# Rebuild API Backend local
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"

# Inspeccionar réplicas reales de workers
wsl docker compose top worker-batch worker-orchestrator worker-downloader worker-extraction-vip worker-extraction-batch worker-normalizer worker-policy worker-persistence

# Deploy producción desde imagenes GHCR (runner LAN)
AUDFACT_IMAGE_TAG=<sha> docker compose pull
AUDFACT_IMAGE_TAG=<sha> docker compose --profile frontend up -d --no-build --remove-orphans

# Sincronizar GitHub Environment production desde un .env productivo
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)
Después de implementar cualquier cambio en Docker o configuración de runtime, ejecutar `audfact-docs-sync`.

## Referencias
1. Ver `plans/docker-operations.md` para troubleshooting detallado.
2. `plans/sdd-homologacion-semantica-asincrona-ejecucion-justa.md` define un cambio propuesto; worker semantic, presupuesto y AOF descritos allí todavía no son contratos del runtime vigente.
