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
| `docker-compose.yml` | ~1.4 KB | HA: php (5 réplicas) + extraction (5 réplicas) |
| `docker-compose.frontend.yml`| ~0.4 KB | Frontend: next.js (3000:3000) |
| `docker/Dockerfile` | ~1.5 KB | PHP 8.2-FPM + ODBC SQL Server + Xdebug condicional |
| `frontend/Dockerfile` | ~0.6 KB | Build multi-etapa Next.js (requiere standalone) |
| `frontend/next.config.ts` | < 1 KB | Config Next.js (debe tener `output: standalone`) |
| `docker/nginx.Dockerfile` | ~0.4 KB | Nginx 1.25 Alpine con assets estáticos baked-in |
| `docker/nginx.conf` | ~0.7 KB | Reverse proxy → PHP-FPM |
| `public/index.php` | 2 KB | Bootstrap: env, CORS, rate limit, dispatch |
| `.env` | 2.5 KB | Variables de entorno (secretos) |
| `.env.example` | ~3 KB | Template de variables, incluyendo perfiles Gemini por tarea |

## Arquitectura de red

```
Cliente HTTP (Front:3000) ────▶ Next.js (audfact-frontend)
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

### Producción (Backend)
| Host | Container | Uso |
|---|---|---|
| `./logs` | `/var/www/html/logs` | Logs rotativos (Zero-Source: único mount de datos) |
| *N/A* | Código baked en imagen | No hay mount de código fuente |

## Variables .env obligatorias

| Variable | Ejemplo | Uso |
|---|---|---|
| `APP_ENV` | `development` | Entorno (development/production) |
| `DB_HOST` | `host.docker.internal` | Host SQL Server |
| `NEXT_PUBLIC_API_URL` | `http://localhost:8080` | URL API para el browser |
| `GEMINI_MODEL` | `gemini-3-flash-preview` | Modelo de auditoría IA |
| `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS` | `4096` | Límite de salida para extracción documental |
| `GEMINI_EXTRACTION_THINKING_LEVEL` | `MINIMAL` | Nivel de razonamiento Gemini 3 para extracción documental |
| `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS` | `2048` | Límite de salida para homologación semántica |

## Flujo de revisión
1. Verificar servicios en `docker-compose.yml` y `docker-compose.frontend.yml`.
2. Verificar extensiones en `docker/Dockerfile`.
3. Validar `frontend/next.config.ts` (output: standalone).
4. Verificar variables obligatorias en `.env`.

## Reglas de implementación
1. Mantener volúmenes para `logs/`.
2. **No hardcodear secretos** — usar `.env`.
3. SQL Server es **externo** al entorno Docker.

## Anti-patterns ⚠️
1. **No agregar SQL Server a Docker Compose**.
2. **No intentar build de frontend sin `output: standalone`** en `next.config.ts`.
3. **No instalar extensiones PHP sin agregarlas al Dockerfile**.
4. **No usar `docker compose up` sin `--build`** tras cambios en código de imagen inmutable.
5. **No asumir hot reload del backend**: PHP y workers ejecutan código baked en imagen; tras cambios de código se requiere rebuild/recreate del servicio afectado.

## Comandos útiles
```bash
# Rebuild Frontend (Producción Local)
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose -f docker-compose.frontend.yml down && docker compose -f docker-compose.frontend.yml up -d --build"

# Rebuild API Backend
wsl bash -c "cd /mnt/c/Users/USER/Desktop/AudFact && docker compose down && docker compose up --build -d"
```

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)
Después de implementar cualquier cambio en Docker o configuración de runtime, ejecutar `audfact-docs-sync`.

## Referencias
1. Ver `plans/docker-operations.md` para troubleshooting detallado.
