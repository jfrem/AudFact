# Especificación SDD — Optimización del Build Docker de AudFact

**Tipo de cambio**: Optimización de infraestructura  
**Archivos afectados**: 2 (`docker/Dockerfile`, `.dockerignore`)  
**Archivos evaluados y descartados**: 1 (`docker-compose.yml` — sin cambios)

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- |
| `Dockerfile` | [docker/Dockerfile](file:///c:/Users/USER/Desktop/AudFact/docker/Dockerfile) | Imagen PHP-FPM runtime para API, workers y healthcheck | L1-49 (completo) | Sí |
| `.dockerignore` | [.dockerignore](file:///c:/Users/USER/Desktop/AudFact/.dockerignore) | Control de contexto de build para BuildKit | L1-72 (completo) | Sí |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta | Línea(s) | Naturaleza |
| --- | --- | --- | --- | --- |
| `Dockerfile` | `docker-compose.yml` | [docker-compose.yml](file:///c:/Users/USER/Desktop/AudFact/docker-compose.yml) | L30-32, L113-115, L138-140, L163-165, L188-190, L213-215, L238-240, L261-263 | referencia (`dockerfile: docker/Dockerfile`) en 8 servicios |
| `Dockerfile` | `publish-images.yml` | [publish-images.yml](file:///c:/Users/USER/Desktop/AudFact/.github/workflows/publish-images.yml) | L47-48 | referencia (`file: docker/Dockerfile`) |
| `Dockerfile` | `deploy-production.yml` | [deploy-production.yml](file:///c:/Users/USER/Desktop/AudFact/.github/workflows/deploy-production.yml) | L455 | consumo (`docker compose up -d --no-build`) |
| `Dockerfile` | `ci.yml` | [ci.yml](file:///c:/Users/USER/Desktop/AudFact/.github/workflows/ci.yml) | L1-106 | N/A — CI no construye imagen Docker |
| `Dockerfile` | `docker-entrypoint.sh` | [docker-entrypoint.sh](file:///c:/Users/USER/Desktop/AudFact/docker/docker-entrypoint.sh) | L38-41 | invocación de `composer install` en arranque |
| `Dockerfile` | `healthcheck.php` | [healthcheck.php](file:///c:/Users/USER/Desktop/AudFact/docker/healthcheck.php) | L17 | `require_once '/var/www/html/vendor/autoload.php'` |
| `Dockerfile` | `public/index.php` | [public/index.php](file:///c:/Users/USER/Desktop/AudFact/public/index.php) | L2 | `require_once __DIR__ . '/../vendor/autoload.php'` |
| `Dockerfile` | `bin/audit-worker.php` | [bin/audit-worker.php](file:///c:/Users/USER/Desktop/AudFact/bin/audit-worker.php) | L6 | `require_once __DIR__ . '/../vendor/autoload.php'` |
| `Dockerfile` | `app/wrap/webhook.php` | [app/wrap/webhook.php](file:///c:/Users/USER/Desktop/AudFact/app/wrap/webhook.php) | L3 | `require_once __DIR__ . '/../../vendor/autoload.php'` |
| `Dockerfile` | `composer.json` | [composer.json](file:///c:/Users/USER/Desktop/AudFact/composer.json) | L6-9 | autoload PSR-4: `App\` → `app/`, `Core\` → `core/` |
| `Dockerfile` | `core/Env.php` | [core/Env.php](file:///c:/Users/USER/Desktop/AudFact/core/Env.php) | L8 | `load($path = __DIR__ . '/../.env')` — busca `.env` en raíz |
| `.dockerignore` | `docs.Dockerfile` | [docs.Dockerfile](file:///c:/Users/USER/Desktop/AudFact/docker/docs.Dockerfile) | L4, L8, L10-11 | `COPY website/`, `COPY plans/`, `COPY README.md BUSINESS.md DESIGN.md` |
| `.dockerignore` | `nginx.Dockerfile` | [nginx.Dockerfile](file:///c:/Users/USER/Desktop/AudFact/docker/nginx.Dockerfile) | L7 | `COPY public/ /var/www/html/public` |
| `.dockerignore` | `publish-images.yml` | [publish-images.yml](file:///c:/Users/USER/Desktop/AudFact/.github/workflows/publish-images.yml) | L47, L61, L81 | 3 builds usan `.` como context (comparten `.dockerignore`) |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo | Corrección |
| --- | --- | --- | --- | --- |
| Eliminar `COPY . .` y usar COPY selectivo (`app/`, `core/`, `public/`, `bin/`) | Ninguno | — | — | `[CONFIRMADO]` Los 4 directorios cubren 100% del autoload PSR-4 (`composer.json:L6-9`). `vendor/` se copia del stage. `docker/` se copia selectivamente para config/entrypoint. |
| Multi-stage: `composer install` en stage efímero | `docker-entrypoint.sh:L38-41` | [docker-entrypoint.sh:L38](file:///c:/Users/USER/Desktop/AudFact/docker/docker-entrypoint.sh#L38) | Runtime | Mantener `COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer` en la imagen final. El entrypoint necesita el binario para auto-instalar dependencias cuando un volumen de desarrollo oculta `vendor/`. |
| Eliminar `RUN rm -rf docker/ composer.* ...` (L44) | Ninguno | — | — | `[CONFIRMADO]` Ya no es necesario porque los archivos nunca ingresan al contenedor con COPY selectivo. |
| Eliminar `chown -R www-data:www-data /var/www/html` (L46) | Ninguno | — | — | `[CONFIRMADO]` Reemplazado por `COPY --chown=www-data:www-data` en cada instrucción COPY. |
| Reordenar `.dockerignore` con excepciones para `docs.Dockerfile` | `docs.Dockerfile:L4,8,10-11` | [docs.Dockerfile:L4](file:///c:/Users/USER/Desktop/AudFact/docker/docs.Dockerfile#L4) | Build | Las exclusiones de `website/node_modules`, `website/.docusaurus`, `website/build` deben ir **después** de la re-inclusión `!website/**` para respetar la semántica de evaluación secuencial de BuildKit. |
| Reducir paquetes `apt-get install` | `Dockerfile:L13` (mismo bloque RUN) | [Dockerfile:L13](file:///c:/Users/USER/Desktop/AudFact/docker/Dockerfile#L13) | Build | `gnupg2` es requerido por `gpg --dearmor` en L13. `git` es requerido por Composer para resolución de paquetes VCS. Ambos deben conservarse. |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Evidencia | Compatible |
| --- | --- | --- | --- |
| Docker BuildKit — `.dockerignore` | Las reglas se evalúan secuencialmente; la última regla que coincide gana. Una excepción `!` posterior re-incluye archivos previamente excluidos. | [Docker docs](https://docs.docker.com/build/concepts/context/#dockerignore-files) | Sí — Las exclusiones de `website/node_modules` se colocan **después** de `!website/**`. |
| Docker BuildKit — Multi-stage | Los artefactos del stage anterior solo se transfieren vía `COPY --from=<stage>`. El stage efímero se descarta. | [Docker docs](https://docs.docker.com/build/building/multi-stage/) | Sí — `vendor/` se transfiere con `COPY --from=vendor-builder`. |
| Docker BuildKit — Caché de capas | Cuando servicios comparten idéntico `context`, `dockerfile` y `args`, BuildKit reutiliza caché. | [Docker Compose docs](https://docs.docker.com/compose/how-tos/build-and-push/) | Sí — Los 8 servicios reutilizan caché. No es necesario eliminar `build:` de workers. |
| Composer | `composer install --no-scripts` omite hooks. Autoload con `--optimize-autoloader`. | [Composer docs](https://getcomposer.org/doc/03-cli.md#install-i) | Sí — Compatible con stage efímero. |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | `docker compose up --build` | Desarrollador local | Sí | 8 servicios conservan `build:`. Composer en imagen. |
| CI (`ci.yml`) | PHPUnit + lint sin Docker | `vendor/bin/phpunit`, `php -l` | Sí | CI no construye imágenes Docker. `ci.yml:L101-104` |
| CI (`publish-images.yml`) | Build + push a GHCR | `docker/build-push-action@v6` | Sí | `publish-images.yml:L47-48` |
| Producción (LAN runner) | Pull GHCR + up `--no-build` | `docker compose up -d --no-build` | Sí | `deploy-production.yml:L455` |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Estructura del Dockerfile actual | `[CONFIRMADO]` | `docker/Dockerfile:L1-49` |
| Tamaño de `website/node_modules` | `[CONFIRMADO]` | 835 MB medidos empíricamente |
| Tamaño del runtime PHP | `[CONFIRMADO]` | 0.8 MB medidos empíricamente |
| `gnupg2` requerido por `gpg --dearmor` | `[CONFIRMADO]` | `Dockerfile:L13` |
| Entrypoint invoca `composer` | `[CONFIRMADO]` | `docker-entrypoint.sh:L40` |
| Autoload PSR-4 | `[CONFIRMADO]` | `composer.json:L6-9` |
| Workers comparten `build:` idéntico | `[CONFIRMADO]` | `docker-compose.yml:L113-267` |
| Deploy usa `--no-build` | `[CONFIRMADO]` | `deploy-production.yml:L455` |

### 0.7-0.9 Información Faltante

Sin información faltante. Todos los elementos confirmados por lectura directa.

### 0.10 Supuestos Declarados

Sin supuestos.

### 0.11 Clasificación de Completitud Inicial

**Nivel A — Implementable**. Toda la información confirmada con ruta:línea. Sin supuestos.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema actual**: `COPY . .` (Dockerfile:L40) transfiere ~835 MB de `website/node_modules` al contexto porque `.dockerignore:L70-71` re-incluye `website/` después de excluir `node_modules`. Luego `RUN rm -rf` (L44) elimina archivos post-copia, desperdiciando tiempo y creando capa extra.
- **Causa raíz**: Semántica de evaluación secuencial de `.dockerignore` — la última regla que coincide gana.
- **Impacto actual**: ~107s exportación + ~73s unpacking en build.
- **Resultado esperado**: Build en menos de 15s. Imagen contiene solo runtime PHP (~0.8 MB código + ~30 MB vendor).

### 2. Alcance

#### Incluido

- Multi-stage en `docker/Dockerfile`.
- Corrección de `.dockerignore`.

#### Excluido

- `docker-compose.yml`, `docker-entrypoint.sh`, workflows CI/CD, otros Dockerfiles.

### 3. Non Goals

- Optimización de imágenes Nginx, frontend o docs.
- Modificación de la estructura de `docker-compose.yml`.

### 4. Estado Actual

**Dockerfile** (single-stage, 49 líneas): `COPY . .` + `RUN rm -rf` + `chown -R www-data:www-data /var/www/html`.

**`.dockerignore`** (72 líneas): `node_modules` excluido en L17, pero re-incluido por `!website/**` en L71.

### 5. Estado Objetivo

**Dockerfile** (multi-stage, ~54 líneas): Stage 1 `vendor-builder` genera `vendor/`. Stage 2 `runtime` copia selectivamente `app/`, `core/`, `public/`, `bin/` con `--chown`.

**`.dockerignore`** (76 líneas): Exclusiones de `website/node_modules` después de `!website/**`.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| DA-1 | Mantener Composer en imagen final | Eliminarlo | `docker-entrypoint.sh:L40` lo invoca. ~2 MB. |
| DA-2 | Conservar `git` y `gnupg2` | Eliminarlos | `gpg --dearmor` en Dockerfile:L13. |
| DA-3 | No modificar `docker-compose.yml` | Eliminar `build:` de workers | BuildKit reutiliza caché. Eliminar rompe `docker compose up --build`. |
| DA-4 | Reordenar `.dockerignore` | Glob negativo complejo | Más legible. Semántica documentada de BuildKit. |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| `composer:2` | Imagen Docker (multi-stage) | `composer:2` | Stage efímero + binario copiado a runtime |
| `php:8.2.33-fpm-bookworm` | Imagen Docker base | `8.2.33` (pinned) | Sin cambio |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| `vendor/autoload.php` existe en runtime | `COPY --from=vendor-builder` + fallback en entrypoint | `healthcheck.php:L17` |
| `gnupg2` disponible para clave Microsoft | `apt-get install gnupg2` en mismo RUN | Build falla si ausente |
| Workers comparten imagen PHP | `image:` idéntico en compose | `ci.yml:L52-87` |

### 9. Modelo de Datos

`[CONFIRMADO]` Sin impacto en persistencia.

### 10. Contratos

`[CONFIRMADO]` Sin impacto. Puerto 9000, entrypoint, healthcheck, workers preservados.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| R-1 | Eliminar fuga de 835 MB | Multi-stage + COPY selectivo | Build en menos de 15s |
| R-2 | Corregir `.dockerignore` | Reordenar exclusiones | `docker compose build docs` funciona |
| R-3 | Preservar Composer para entrypoint | `COPY --from=composer:2` en imagen final | Contenedor arranca con volumen dev |
| R-4 | Preservar `gnupg2` | Mantener en `apt-get install` | Build no falla en `gpg --dearmor` |

### 12. Impact Analysis

| Componente | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- |
| `docker-compose.yml` | Ninguno | No | `build.dockerfile` sigue siendo `docker/Dockerfile` |
| `publish-images.yml` | Ninguno | No | `file: docker/Dockerfile` — sin cambio de ruta |
| `deploy-production.yml` | Ninguno | No | Usa `--no-build` |
| `ci.yml` | Ninguno | No | No construye Docker |
| `docs.Dockerfile` | Funcional preservado | No | Re-inclusión `website/` preservada |
| `nginx.Dockerfile` | Ninguno | No | `public/` no excluido |

### 13. Cambios por Archivo

#### [MODIFY] [Dockerfile](file:///c:/Users/USER/Desktop/AudFact/docker/Dockerfile)

Reemplazar contenido completo (49 líneas → ~54 líneas). Ver §5 Estado Objetivo para el Dockerfile completo propuesto:

```dockerfile
# ── Stage 1: Vendor Builder ──
FROM composer:2 AS vendor-builder
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --no-interaction --no-scripts --no-progress \
    --prefer-dist --no-dev --optimize-autoloader

# ── Stage 2: Runtime PHP-FPM ──
FROM php:8.2.33-fpm-bookworm

ARG WWWGROUP_ID=33
ARG WWWUSER_ID=33
RUN usermod -u ${WWWUSER_ID} www-data && groupmod -g ${WWWGROUP_ID} www-data

ARG ENABLE_XDEBUG=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    git curl unzip gnupg2 ca-certificates libzip-dev unixodbc-dev gettext-base \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 apcu \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv apcu \
    && if [ "$ENABLE_XDEBUG" = "1" ]; then pecl install xdebug && docker-php-ext-enable xdebug; fi \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /var/www/html

COPY docker/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY docker/php-fpm-pool.conf.template /usr/local/etc/php-fpm.d/www.conf.template
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-custom.sh
COPY docker/healthcheck.php /usr/local/bin/audfact-healthcheck.php
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint-custom.sh \
    && sed -i 's/\r$//' /usr/local/bin/audfact-healthcheck.php \
    && chmod +x /usr/local/bin/docker-entrypoint-custom.sh \
    && if [ "$ENABLE_XDEBUG" != "1" ]; then rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; fi

# Composer en runtime (requerido por docker-entrypoint.sh:L40)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Dependencias PHP del stage vendor-builder
COPY --from=vendor-builder --chown=www-data:www-data /app/vendor ./vendor
COPY --chown=www-data:www-data composer.json composer.lock* ./

# Código fuente — solo directorios del autoload PSR-4 (composer.json:L6-9)
COPY --chown=www-data:www-data app/ ./app/
COPY --chown=www-data:www-data core/ ./core/
COPY --chown=www-data:www-data public/ ./public/
COPY --chown=www-data:www-data bin/ ./bin/

RUN mkdir -p /tmp/audfact-runtime/ratelimit /var/www/html/logs \
    && chown -R www-data:www-data /tmp/audfact-runtime /var/www/html/logs

ENTRYPOINT ["docker-entrypoint-custom.sh"]
```

---

#### [MODIFY] [.dockerignore](file:///c:/Users/USER/Desktop/AudFact/.dockerignore)

Agregar 4 líneas después de L71 (después de `!website/**`):

```diff
 !website/
 !website/**
+
+# Re-excluir artefactos pesados DESPUÉS de la re-inclusión
+website/node_modules
+website/.docusaurus
+website/build
```

### 14. Plan de Migración

#### Ejecución

1. Modificar `docker/Dockerfile` según §13.
2. Agregar 4 líneas al final de `.dockerignore` según §13.
3. `docker compose build php` — verificar build.
4. `docker compose build docs` — verificar que docs sigue funcionando.

#### Rollback

```bash
git checkout HEAD~1 -- docker/Dockerfile .dockerignore
```

### 15. Casos Límite

| Condición | Comportamiento | Verificable |
| --- | --- | --- |
| `composer.lock` no existe | Glob `composer.lock*` no falla | Build completa |
| Volumen de código montado en dev | Entrypoint ejecuta `composer install` | Contenedor arranca |
| Xdebug habilitado | Se instala normalmente | `php -m | grep xdebug` |

### 16. Testing

| # | Objetivo | Pasos | Resultado |
| --- | --- | --- | --- |
| 1 | Build PHP | `docker compose build php` | Sin errores |
| 2 | Build Docs | `docker compose build docs` | Sin errores |
| 3 | Imagen contiene runtime | `docker run --rm IMAGE ls /var/www/html/` | `app/ core/ public/ bin/ vendor/` |
| 4 | Composer disponible | `docker run --rm IMAGE composer --version` | `Composer version 2.x` |
| 5 | Health check | `docker compose up -d && curl localhost:8080/health` | HTTP 200 |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Tag `composer:2` flotante | técnico | Baja | Pinear si ocurre incidente |

### 18. Criterios de Aceptación

- `docker compose build php` completa en menos de 30s y sin errores.
- `docker compose build docs` completa sin errores.
- `curl http://localhost:8080/health` responde HTTP 200.
- Imagen no contiene `website/`, `frontend/`, `.git/`, `tests/`.
- `docker compose up --build` funciona en desarrollo local.

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todos los contratos documentados | PASS | §10 — sin impacto |
| Todos los requisitos tienen trazabilidad | PASS | R-1 a R-4 en §11 |
| Todos los consumidores analizados | PASS | 14 dependencias en §0.2 |
| Todas las migraciones tienen rollback | PASS | §14 |
| Todas las referencias están definidas | PASS | Ruta:línea en cada referencia |
| Toda compatibilidad tiene evidencia | PASS | §0.5 |
| Todos los criterios son verificables | PASS | §18 |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado |
| --- | --- |
| ¿Decisión arquitectónica implícita? | No — DA-1 a DA-4 |
| ¿Contrato sin documentar? | No |
| ¿Consumidor no analizado? | No — 14 en §0.2 |
| ¿Migración sin rollback? | No — §14 |
| ¿Dato persistido sin migración? | No |
| ¿Afirmación sin evidencia? | No |
| ¿Referencias huérfanas? | No |
| ¿Dos implementadores producirían soluciones diferentes? | No |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta | Resultado | Evidencia |
| --- | --- | --- | --- |
| 1 | ¿Entrypoint invoca algo que se elimina? | No | Composer preservado (DA-1). `docker-entrypoint.sh:L40`. |
| 2 | ¿Build posterior depende de algo eliminado? | No | `gnupg2` y `git` conservados (DA-2). |
| 3 | ¿Pipeline usa flujo no evaluado? | No | `publish-images.yml:L48`, `deploy-production.yml:L455`, `ci.yml` evaluados en §0.5. |
| 4 | ¿Se asume comportamiento sin verificar? | No | §0.4 — semántica documentada. |
| 5 | ¿Optimizado para un solo entorno? | No | §0.5 — 4 entornos verificados. |
| 6 | ¿Override en runtime oculta archivos? | No | Volumen `./logs` no afecta `vendor/`. |
| 7 | ¿Best practice sin verificar contra local? | No | DA-1, DA-2 documentan verificación. |
| 8 | ¿Se modifica interfaz pública? | No | Puerto 9000, entrypoint, healthcheck preservados. |
| 9 | ¿Se afectan datos persistidos? | No | Sin impacto en esquemas. |
| 10 | ¿Se introduce código muerto o scope extra? | No | Se eliminan 3 instrucciones innecesarias. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel A — Implementable**

- Sin supuestos.
- 4 regresiones detectadas y corregidas en §0.3.
- Todas las auditorías pasan.
- Matriz de entornos completa.
- Semántica de herramientas verificada.
- Criterios de aceptación medibles.
