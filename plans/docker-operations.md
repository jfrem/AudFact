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

El flujo automatizado vive en:

- `.github/workflows/publish-images.yml`
- `.github/workflows/deploy-production.yml`
- `plans/deployment-github-actions-lan.md`

## Precauciones

- **Xdebug**: Condicional por `ENABLE_XDEBUG` en el build de `docker/Dockerfile`. Produccion publica imagenes con `ENABLE_XDEBUG=0`.
- **Frontend**: El contenedor Next.js escucha en `3000`, pero el host lo publica en `${AUDFACT_FRONTEND_HOST_PORT:-3100}` para evitar colisiones con otros proyectos LAN.
- **Volúmenes**: En producción se montan `./logs:/var/www/html/logs` y `./responseIA:/var/www/html/responseIA`; el código vive dentro de la imagen, no en mounts del host.
- No editar archivos dentro del contenedor directamente; usar el mount de volumen para logs y el rebuild para código
- **PowerShell + WSL**: Siempre envolver cadenas de comandos Docker en `wsl bash -c "..."` para evitar que `&&` rompa la cadena entre shells
- **Producción Zero-Source**: El directorio persistente del deploy contiene `.env`, `docker-compose.prod.yml`, `logs/`, `responseIA/` y el volumen Docker de Redis. El checkout de GitHub Actions no se usa como runtime.
