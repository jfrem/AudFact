# Docker y Operaciones Runtime — AudFact

## Arquitectura de contenedores

```
┌────────────────┐
│ Next.js Front  │
│ :3100→:3000    │
└───────┬────────┘
        │ SSR/API interna
        ▼
┌─────────────┐     ┌───────────────┐     ┌──────────────┐
│   Nginx     │────▶│   PHP-FPM     │────▶│  SQL Server  │
│  :8080→:80  │     │   :9000       │     │  (externo)   │
└─────────────┘     └───────────────┘     └──────────────┘
```

## Troubleshooting Docker

```bash
# PHP no conecta a SQL Server
wsl docker compose exec php php -m | grep sqlsrv     # verificar extensiones
wsl docker compose exec php php -r "new PDO('sqlsrv:Server=...');"  # probar conexión

# Nginx 502 Bad Gateway
wsl docker compose logs nginx          # verificar upstream
wsl docker compose exec php ps aux     # verificar PHP-FPM activo

# Rebuild completo (si hay cambios en Dockerfile o código PHP)
# ⚠️ IMPORTANTE: usar wsl bash -c "..." para que TODOS los comandos se ejecuten dentro de WSL.
# El operador && dentro de PowerShell separa comandos: el primero corre en WSL y el segundo en PS.
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"

# Conflicto de nombre de contenedor (container name already in use)
# Si `docker compose down` no elimina correctamente un contenedor:
wsl bash -c "docker rm -f audfact-nginx 2>/dev/null; cd /mnt/c/Users/USER/Desktop/AudFact && docker compose up -d"
```

## Escalado de workers async

El pipeline usa Redis Streams con consumer groups y nombres de consumer únicos por host + PID. Esto evita que varias réplicas del mismo servicio colapsen en un único nombre lógico al inspeccionar Redis.

Variables de capacidad inicial:

| Variable | Default | Servicio |
|---|---:|---|
| `AUDIT_WORKER_BATCH_REPLICAS` | `2` | `worker-batch` |
| `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | `3` | `worker-orchestrator` |
| `AUDIT_WORKER_DOWNLOADER_REPLICAS` | `8` | `worker-downloader` |
| `AUDIT_WORKER_EXTRACTION_REPLICAS` | `8` | `worker-extraction` |
| `AUDIT_WORKER_POLICY_REPLICAS` | `2` | `worker-policy` |
| `AUDIT_PENDING_RECLAIM_IDLE_MS` | `600000` | recuperación de pending |
| `AUDIT_PENDING_RECLAIM_INTERVAL_MS` | `30000` | escaneo de pending |

## Capacidad Redis y TTL de auditorias

Redis se configura desde Compose mediante variables no sensibles:

| Variable | Default | Uso |
|---|---:|---|
| `REDIS_MAXMEMORY` | `4gb` | Limite interno de memoria Redis (`--maxmemory`). |
| `REDIS_MAXMEMORY_POLICY` | `volatile-lru` | Politica de eviccion para llaves con TTL. |
| `REDIS_CONTAINER_MEMORY` | `5G` | Limite de memoria del contenedor Redis. |
| `AUDIT_JOB_TTL` | `604800` | Retencion de `job:{jobId}:state` durante 7 dias. |
| `AUDIT_STATE_TTL` | `604800` | Retencion de `audit:{auditId}:state` durante 7 dias. |
| `AUDIT_RESERVATION_TTL` | `86400` | Retencion de reservas por `DisId` durante 24h. |

Validaciones operativas despues de deploy:

```bash
docker compose -f docker-compose.prod.yml exec redis redis-cli INFO memory
docker stats audfact-redis --no-stream
docker compose -f docker-compose.prod.yml exec redis redis-cli TTL audfact:job:<jobId>:state
docker compose -f docker-compose.prod.yml exec redis redis-cli TTL audfact:audit:<auditId>:state
```

## Higiene de `.env`

`.env.example` es el contrato de configuración y `.env` debe conservar el mismo
set de variables activas, con valores reales solo en `.env`. El template no debe
contener API keys, contraseñas, tokens ni bloques PEM de claves privadas.

Variables de runtime productivo que deben permanecer representadas en ambos
archivos: imágenes GHCR (`AUDFACT_*_IMAGE`, `AUDFACT_IMAGE_TAG`), publicación
frontend (`AUDFACT_FRONTEND_HOST_PORT`, `AUDFACT_FRONTEND_PUBLIC_URL`), pooling
SQL (`DB_POOLING`, `DB2_POOLING`), configuración pública Next.js
(`NEXT_PUBLIC_*`) y réplicas/recuperación de workers async.

Para producción, sincronizar esos valores hacia GitHub Environment `production`
con:

```bash
bash scripts/sync-github-production-env.sh --dry-run
bash scripts/sync-github-production-env.sh --apply
```

No copiar `.env` directamente al host como flujo normal: el workflow de deploy
regenera `/home/admon/audfact-prod/.env` desde GitHub Secrets/Variables. Para
`--apply`, el `.env` fuente debe ser productivo: `APP_ENV=production`, URLs
publicas sin `localhost`, e internos Docker como `INTERNAL_API_URL=http://nginx`.

Comandos de diagnóstico:

```bash
wsl docker compose top worker-batch worker-orchestrator worker-downloader worker-extraction worker-policy
wsl docker compose exec redis redis-cli XINFO GROUPS audfact:audit.batch.inbox
wsl docker compose exec redis redis-cli XINFO GROUPS audfact:audit.inbox
wsl docker compose exec redis redis-cli XINFO GROUPS audfact:audit.documents
```

Estrategia: subir primero `worker-downloader` cuando la espera aparezca en `document_registered` y Drive/SQL no esté saturado. Subir `worker-extraction` cuando la espera aparezca en `document_downloaded` y Gemini no esté devolviendo 429/503. Subir `worker-orchestrator` solo si `audit.inbox` acumula espera. Subir `worker-policy` cuando la espera aparezca en `document_normalized`, no por latencia Gemini.

Si `XINFO GROUPS` muestra `pending > 0` con `lag=0`, hay eventos entregados a un consumer que no hizo `XACK`. Los workers reclaman esos eventos periódicamente cuando superan `AUDIT_PENDING_RECLAIM_IDLE_MS`; no bajar este valor por debajo del peor caso de duración Gemini, porque puede duplicar procesamiento legítimo en curso.

Nginx resuelve `php:9000` mediante DNS Docker (`127.0.0.11`) en runtime. Esto evita que conserve IPs FastCGI obsoletas cuando `docker compose up -d --build` recrea las réplicas PHP-FPM sin recrear Nginx.

## Despliegue Produccion LAN

Produccion usa imagenes publicadas en GHCR y `docker-compose.prod.yml`; no construye en el servidor.

```bash
docker login ghcr.io
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml pull
AUDFACT_IMAGE_TAG=<sha> docker compose -f docker-compose.prod.yml up -d --remove-orphans
curl -sf http://localhost:8080/health
curl -sf http://localhost:${AUDFACT_FRONTEND_HOST_PORT:-3100}/api/health
curl -sf http://localhost:${AUDFACT_FRONTEND_HOST_PORT:-3100}/clients
```

Despues de desplegar cambios en `/audit/async`, verificar que `worker-batch` y
`worker-downloader` existan y que Redis haya creado los consumer groups:

```bash
docker compose -f docker-compose.prod.yml ps worker-batch
docker compose -f docker-compose.prod.yml ps worker-downloader
docker compose -f docker-compose.prod.yml exec redis redis-cli XINFO GROUPS audfact:audit.batch.inbox
docker compose -f docker-compose.prod.yml exec redis redis-cli XINFO GROUPS audfact:audit.documents
```

El flujo automatizado vive en:

- `.github/workflows/publish-images.yml`
- `.github/workflows/deploy-production.yml`
- `plans/deployment-github-actions-lan.md`

## Precauciones

- **Xdebug**: Condicional por `ENABLE_XDEBUG` en el build de `docker/Dockerfile`. Produccion publica imagenes con `ENABLE_XDEBUG=0`.
- **Frontend**: El contenedor Next.js escucha en `3000`, pero el host lo publica en `${AUDFACT_FRONTEND_HOST_PORT:-3100}` para evitar colisiones con otros proyectos LAN.
- **Volúmenes**: En producción se monta `./logs:/var/www/html/logs`; el código vive dentro de la imagen, no en mounts del host. El directorio `responseIA/` solo se monta en desarrollo (`docker-compose.yml`).
- **Gemini**: `GEMINI_API_KEY` debe estar vigente en GitHub Environment `production`; una key expirada provoca `400 API key expired` en `worker-extraction` y envía eventos a DLQ.
- No editar archivos dentro del contenedor directamente; usar el mount de volumen para logs y el rebuild para código
- **PowerShell + WSL**: Siempre envolver cadenas de comandos Docker en `wsl bash -c "..."` para evitar que `&&` rompa la cadena entre shells
- **Producción Zero-Source**: El directorio persistente del deploy contiene `.env`, `docker-compose.prod.yml`, `logs/` y el volumen Docker de Redis. El checkout de GitHub Actions no se usa como runtime.
