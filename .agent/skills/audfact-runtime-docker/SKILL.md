---
name: audfact-runtime-docker
description: Operar y depurar el runtime local de AudFact con Docker. Usar cuando se cambien docker-compose.yml, docker/Dockerfile, docker/nginx.conf, variables .env o conectividad entre Nginx, PHP-FPM, SQL Server y APIs externas.
---

# AudFact Runtime Docker

## Objetivo
Asegurar que el entorno de ejecución local sea reproducible y diagnosticar fallas rápido.

> [!TIP]
> Consulta la guía de inicio rápido y configuración del entorno en [overview.md](file:///c:/Users/USER/Desktop/AudFact/plans/overview.md#guía-de-inicio-rápido).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `docker-compose.yml` | ~1.4 KB | HA: php (5 réplicas) + nginx (Xdebug off) |
| `docker-compose.dev.yml` | ~0.7 KB | Desarrollo: php + nginx (Xdebug on, source mounted) |
| `docker/Dockerfile` | ~1.5 KB | PHP 8.2-FPM + ODBC SQL Server + Xdebug condicional |
| `docker/nginx.Dockerfile` | ~0.4 KB | Nginx 1.25 Alpine con assets estáticos baked-in |
| `docker/nginx.conf` | ~0.7 KB | Reverse proxy → PHP-FPM |
| `docker/nginx-ha.conf.template` | ~1.5 KB | Template con upstream multi-worker + envsubst |
| `docker/xdebug.ini` | 197 B | Configuración Xdebug |
| `public/index.php` | 2 KB | Bootstrap: env, CORS, rate limit, dispatch |
| `.env` | 2.5 KB | Variables de entorno (secretos) |
| `.env.example` | 737 B | Template de variables |
| `.env.dev` | 114 B | Override para desarrollo |

## Arquitectura de red

```
Cliente HTTP
    │
    ▼ puerto 8080
┌──────────────────┐
│  audfact-nginx   │ Nginx 1.25 Alpine
│  (puerto 80)     │
└──────┬───────────┘
       │ FastCGI :9000
       ▼
┌──────────────────┐
│  audfact-php     │ PHP 8.2-FPM
│  (puerto 9000)   │
└──────┬───────────┘
       │ PDO sqlsrv
       ▼
┌──────────────────┐
│  SQL Server      │ ⚠️ EXTERNO (no en Docker)
│  (puerto 1433)   │
└──────────────────┘
```

## Extensiones PHP instaladas
- `sqlsrv` — Driver SQL Server
- `pdo_sqlsrv` — PDO para SQL Server
- `xdebug` — Debug (**condicional**: `ENABLE_XDEBUG=1` en dev, `0` en prod/HA)
- `zip` — Manejo de archivos comprimidos
- `apcu` — Cache en memoria (rate limiting)

## Volúmenes Docker

### Desarrollo (`docker-compose.dev.yml`)
| Host | Container | Uso |
|---|---|---|
| `./` | `/var/www/html` | Código fuente completo (hot-reload) |
| `./logs` | `/var/www/html/logs` | Logs rotativos |
| `./docker/nginx.conf` | `/etc/nginx/conf.d/default.conf` | Config Nginx (read-only) |

### Producción (`docker-compose.yml`)
| Host | Container | Uso |
|---|---|---|
| `./logs` | `/var/www/html/logs` | Logs rotativos (Zero-Source: único mount de datos) |
| *N/A* | Código baked en imagen | No hay mount de código fuente |

## Variables .env obligatorias

| Variable | Ejemplo | Uso |
|---|---|---|
| `APP_ENV` | `development` | Entorno (development/production) |
| `DB_HOST` | `host.docker.internal` | Host SQL Server |
| `DB_PORT` | `1433` | Puerto SQL Server |
| `DB_NAME` | `mi_base` | Nombre de BD |
| `DB_USER` | `sa` | Usuario BD |
| `DB_PASS` | `***` | Contraseña BD |
| `GEMINI_API_KEY` | `AIza...` | API key Gemini |
| `GEMINI_MODEL` | `gemini-2.0-flash` | Modelo IA |
| `ALLOWED_ORIGINS` | `http://localhost:3000` | CORS en producción |

## Flujo de revisión
1. Verificar servicios en `docker-compose.yml`.
2. Verificar extensiones y dependencias en `docker/Dockerfile`.
3. Verificar ruteo PHP/Nginx en `docker/nginx.conf`.
4. Verificar variables obligatorias en `.env` y `.env.example`.
5. Validar endpoints `GET /health` y rutas de `app/wrap`.

## Reglas de implementación
1. Mantener volúmenes para `logs/` y `tmp/`.
2. **No hardcodear secretos en Dockerfile** — usar `.env`.
3. Confirmar compatibilidad con `sqlsrv` y `pdo_sqlsrv`.
4. Mantener configuración simple para desarrollo local.
5. Documentar cambios operativos en `plans/` cuando aplique.
6. SQL Server es **externo** al entorno Docker.

## Anti-patterns ⚠️
1. **No agregar SQL Server a Docker Compose** — es un servicio externo gestionado aparte.
2. **No eliminar el volumen de logs** — necesario para diagnóstico en desarrollo.
3. **No cambiar el puerto base 8080** sin actualizar ApiClient y tests.
4. **No instalar extensiones PHP sin agregarlas al Dockerfile** — no persistirán entre rebuilds.
5. **No usar `docker compose up` sin `--build`** tras cambios en Dockerfile.

## Comandos útiles
```bash
# Levantar entorno (rebuild completo)
# ⚠️ Usar wsl bash -c "..." para ejecutar TODA la cadena dentro de WSL
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"

# Validar health
curl http://localhost:8080/health

# Logs del contenedor PHP
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose logs php --tail 100"

# Shell dentro del contenedor
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose exec php bash"

# Verificar extensiones PHP
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose exec php php -m | grep -i sql"

# Rebuild solo PHP sin cache
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose build --no-cache php"

# Conflicto de nombre (container name already in use)
wsl bash -c "docker rm -f audfact-nginx 2>/dev/null; cd /mnt/c/Users/USER/Desktop/AudFact && docker compose up -d"
```

## Checklist rápido
1. Contenedores levantan sin error.
2. `public/index.php` responde en :8080.
3. `/health` refleja estado BD.
4. `app/wrap/webhook.php` accesible.
5. Logs útiles para diagnóstico.
6. `.env` tiene todas las variables obligatorias.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio en los archivos gobernados por esta skill, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los archivos de Docker listados siguen existiendo? ¿Hay nuevos?
   - ¿Los volúmenes dev/prod siguen correctos?
   - ¿Las extensiones PHP instaladas están actualizadas?
   - ¿Los comandos útiles funcionan con la arquitectura actual?
   - ¿Las variables `.env` obligatorias están completas?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
