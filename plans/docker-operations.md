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

# Rebuild completo (si hay cambios en Dockerfile)
wsl docker compose down && wsl docker compose up -d --build --force-recreate
```

## Precauciones

- **Xdebug**: actualmente habilitado siempre en Dockerfile — impacta rendimiento
- **Volúmenes**: `./logs` montado en `./logs:/var/www/html/logs` — es redundante con el mount de `./`
- No editar archivos dentro del contenedor directamente; usar el mount de volumen
