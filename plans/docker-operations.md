# Docker y Operaciones Runtime — AudFact

## Arquitectura de contenedores

```
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

## Precauciones

- **Xdebug**: Condicional por `ENABLE_XDEBUG` en `docker-compose*.yml`. Habilitado (`1`) en `docker-compose.dev.yml`, deshabilitado (`0`) en los demás.
- **Volúmenes**: En producción solo se monta `./logs:/var/www/html/logs` (el código vive dentro de la imagen, no en mounts del host)
- No editar archivos dentro del contenedor directamente; usar el mount de volumen para logs y el rebuild para código
- **PowerShell + WSL**: Siempre envolver cadenas de comandos Docker en `wsl bash -c "..."` para evitar que `&&` rompa la cadena entre shells
- **Producción Zero-Source**: El host solo contiene `.env`, `docker-compose.yml`, `logs/` y `.git` después del deploy
