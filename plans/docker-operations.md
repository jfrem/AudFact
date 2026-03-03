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
wsl docker exec -it audfact-php php -m | grep sqlsrv     # verificar extensiones
wsl docker exec -it audfact-php php -r "new PDO('sqlsrv:Server=...');"  # probar conexión

# Nginx 502 Bad Gateway
wsl docker compose logs nginx          # verificar upstream
wsl docker exec -it audfact-php ps aux # verificar PHP-FPM activo

# Rebuild completo (si hay cambios en Dockerfile o código PHP)
# ⚠️ IMPORTANTE: usar wsl bash -c "..." para que TODOS los comandos se ejecuten dentro de WSL.
# El operador && dentro de PowerShell separa comandos: el primero corre en WSL y el segundo en PS.
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"

# Conflicto de nombre de contenedor (container name already in use)
# Si `docker compose down` no elimina correctamente un contenedor:
wsl bash -c "docker rm -f audfact-nginx 2>/dev/null; cd /mnt/c/Users/USER/Desktop/AudFact && docker compose up -d"
```

## Precauciones

- **Xdebug**: actualmente habilitado siempre en Dockerfile — impacta rendimiento
- **Volúmenes**: `./logs` montado en `./logs:/var/www/html/logs` — es redundante con el mount de `./`
- No editar archivos dentro del contenedor directamente; usar el mount de volumen
- **PowerShell + WSL**: Siempre envolver cadenas de comandos Docker en `wsl bash -c "..."` para evitar que `&&` rompa la cadena entre shells
