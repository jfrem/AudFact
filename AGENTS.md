# AudFact — Repository Guidelines

- Repo local: `c:\Users\USER\Desktop\AudFact`
- Idioma de toda interacción: **Español (Latinoamérica)**
- Runtime: PHP 8.2-FPM + Nginx 1.25 en Docker
- Base de datos: SQL Server (PDO `sqlsrv`)
- IA: Google Gemini API (multimodal)

---

## Estructura del Proyecto y Organización de Módulos

- **Código fuente**: `core/` (framework), `app/` (aplicación MVC)
- **Controladores**: `app/Controllers/` — Un controlador por recurso REST
- **Modelos**: `app/Models/` — Acceso a datos vía PDO, queries embebidas
- **Servicios**: `app/Services/` (audit pipeline), `app/worker/` (GeminiAuditService)
- **Rutas**: `app/Routes/web.php` — Definición centralizada de endpoints
- **Punto de entrada**: `public/index.php` — Bootstrap, CORS, rate limit, dispatch
- **MCP Integration**: `app/wrap/` — Webhook y herramientas para agentes IA
- **Docker**: `docker/` (Dockerfile, nginx.conf, xdebug.ini), `docker-compose.yml`
- **Tests**: `tests/` — Scripts CLI de integración (PHPUnit pendiente)
- **Logs**: `logs/` — Rotación automática por `Core\Logger`
- **Docs/Plans**: `plans/` — Documentos de planificación

### Skills disponibles

El proyecto tiene skills en `.agent/skills/`. Consultar `CATALOG.md` para el mapeo archivo → skill.

| Skill | Área | Cuándo usar |
|---|---|---|
| `audfact-project-overview` | Contexto | Arquitectura, flujos, dependencias |
| `audfact-api-rest` | REST API | Rutas, controladores, validación |
| `audfact-audit-gemini` | Auditoría IA | Pipeline Gemini, prompts, schemas |
| `audfact-sqlsrv-models` | SQL Server | Modelos, queries, BLOBs |
| `audfact-mcp-wrap` | MCP | Webhook, herramientas, ApiClient |
| `audfact-runtime-docker` | Docker/Ops | Contenedores, Nginx, conectividad |
| `audfact-security-guardrails` | Seguridad | Rate limit, CORS, sanitización |

**Antes de modificar un archivo**, consultar la skill correspondiente según la tabla en `CATALOG.md`.

---

- **Docs/Plans**: `plans/` — Documentos de planificación

---

## Mapa de Endpoints REST

> El router gestiona estas rutas en `app/Routes/web.php`. Los parámetros entre `{}` son dinámicos.

| Método | Endpoint | Controlador | Acción | Middleware | Descripción |
|---|---|---|---|---|---|
| `GET` | `/` | `Controller` | `index` | - | Bienvenida / Status API |
| `GET` | `/health` | `HealthController` | `status` | - | Health check (Docker/System) |
| `GET` | `/clients` | `ClientsController` | `index` | `auth` | Listar todos los clientes/EPS |
| `GET` | `/clients/{clientId}` | `ClientsController` | `show` | `auth` | Detalle de un cliente específico |
| `POST` | `/clients` | `ClientsController` | `lookup` | `auth` | Buscar cliente por filtros |
| `GET` | `/invoices` | `InvoicesController` | `index` | `auth` | Listar facturas (top 100) |
| `POST` | `/invoices` | `InvoicesController` | `search` | `auth` | Buscar facturas por fecha/nit |
| `GET` | `/dispensation/{DisDetNro}` | `DispensationController` | `show` | `auth` | Detalle técnico de una dispensa |
| `POST` | `/dispensation` | `DispensationController` | `lookup` | `auth` | Buscar dispensa por ID |
| `GET` | `/dispensation/{id}/attachments/{nit}` | `AttachmentsController` | `showByDispensation` | `auth` | Listar metadatos de adjuntos |
| `GET` | `/dispensation/{id}/attachments/download/{aid}` | `AttachmentsController` | `downloadByDispensation` | `auth` | Descargar BLOB de adjunto |
| `POST` | `/audit` | `AuditController` | `run` | `auth` | **Pipeline IA**: Ejecutar auditoría Gemini |

---

## Flujo de Datos / Request Lifecycle

El sistema sigue un pipeline secuencial para cada petición HTTP:

### 1. Entrada (Nginx & PHP-FPM)
- La petición llega al puerto `:8080`.
- Nginx la recibe y la pasa al contenedor `audfact-php` vía socket fastcgi.

### 2. Bootstrap (`public/index.php`)
- **Autoload**: Carga clases vía Composer.
- **Env**: Carga variables `.env`.
- **CORS**: Inyecta headers según `APP_ENV`.
- **ErrorHandler**: Registra el manejador global de excepciones.
- **Rate Limit**: Verifica IP en `Core\RateLimit`.
- **Middleware**: Registra manejadores (ej: `auth`).

### 3. Enrutamiento (`Core\Router`)
- El router matchea la URI contra `app/Routes/web.php`.
- Extrae parámetros dinámicos de la URL.
- Instancia el controlador correspondiente.

### 4. Controlador (`app/Controllers/*`)
- **Validación**: Usa `Core\Validator` para limpiar y validar `$_POST`/`$_GET`.
- **Negocio**: Llama a modelos o servicios (ej: `GeminiAuditService`).
- **Respuesta**: Llama a `Core\Response::success()` o `error()`.

### 5. Salida (`Core\Response`)
- Serializa datos a JSON.
- Establece el código HTTP (200, 400, 404, 500).
- Envía el payload y termina la ejecución (vía `exit`).

---

## Esquema de Base de Datos

El proyecto consume una base de datos SQL Server (`sqlsrv`). La mayoría son vistas o tablas de un sistema legacy (`Discolnet`).

### Mapeo de Modelos

| Modelo | Tabla / Vista | Propósito | PK / Identificador |
|---|---|---|---|
| `InvoicesModel` | `dbo.factura` | Cabeceras de facturas | `FacSec` |
| `ClientsModel` | `NIT` / `Clientes` | Gestión de EPS/Clientes | `NitSec` |
| `DispensationModel` | `vw_discolnet_dispensas` | Datos detallados de entrega | `DisDetNro` |
| `AttachmentsModel` | `AdjuntosDispensacion` | Archivos binarios (BLOB/URL) | `AdjDisId` |
| `AuditStatusModel` | `dbo.AudDispEst` | **Nueva**: Resultados de auditoría IA | `FacSec` |

### Relaciones Clave

- **Factura ↔ Dispensa**: Relación 1:N. Una factura (`FacSec`) agrupa múltiples dispensaciones (`DisId`).
- **Dispensa ↔ Adjuntos**: Relación 1:N. Una dispensa tiene múltiples documentos (fórmula, acta, etc.).
- **Factura ↔ Auditoría**: Relación 1:1. El estado de auditoría se persiste en `AudDispEst` usando el `FacSec` de la factura como llave secundaria/primaria de auditoría.

### Transaccionalidad
- El sistema es mayoritariamente de **lectura**.
- La única tabla con escritura frecuente por parte de la API es `AudDispEst` (vía `AuditStatusModel::upsertAuditResult`).

---

## Comandos de Build, Test y Desarrollo

### Docker (entorno principal)

```bash
# Levantar entorno
docker compose up -d --build

# Verificar contenedores
docker compose ps

# Logs en tiempo real
docker compose logs -f php
docker compose logs -f nginx

# Acceder al contenedor PHP
docker exec -it audfact-php bash

# Instalar dependencias
docker exec -it audfact-php composer install

# Ejecutar tests CLI existentes
docker exec -it audfact-php php tests/cli_test_audit.php
docker exec -it audfact-php php tests/cli_test_single.php <FACSEC>
```

### API local

- Base URL: `http://localhost:8080`
- Health check: `GET /health`
- Webhook MCP: `POST /app/wrap/webhook.php`

### Dependencias

- Si falta `vendor/`, ejecutar `composer install` dentro del contenedor o rebuild Docker
- Dependencias de producción: `guzzlehttp/guzzle ^7.10`, `firebase/php-jwt ^7.0`
- **Nunca** agregar dependencias sin discutirlo primero

---

## Estilo de Código y Convenciones

### PHP

- **PSR-4 autoloading**: `App\` → `app/`, `Core\` → `core/`
- **Tipado estricto**: usar type hints en parámetros y retornos
- **Sin SQL en controladores**: toda query SQL va en modelos (`app/Models/`)
- **Sin `exit()` en clases**: usar excepciones. El único `exit` permitido está en `public/index.php`
- **Respuestas JSON**: siempre vía `Core\Response::success()` o `Core\Response::error()`
- **Validación**: siempre vía `Core\Validator` en el controlador, nunca validar manualmente
- **Logging**: usar `Core\Logger` (info/warning/error), nunca `echo` o `var_dump` en código de producción
- **Archivos ≤ 500 LOC**: si un archivo crece más, extraer helpers o servicios
- **Comentarios**: breves, solo para lógica no obvia o decisiones de diseño

### Nombrado

| Elemento | Convención | Ejemplo |
|---|---|---|
| Controlador | `PascalCase` + sufijo `Controller` | `InvoicesController` |
| Modelo | `PascalCase` + sufijo `Model` | `InvoicesModel` |
| Método | `camelCase` | `getInvoices()` |
| Tabla SQL | Nombre original de la BD (legacy) | `dbo.factura` |
| Ruta REST | `kebab-case`, plural | `/dispensation/{id}/attachments` |
| Variable | `camelCase` | `$facNitSec` |
| Constante | `UPPER_SNAKE_CASE` | `MAX_FILE_SIZE_BYTES` |

### Patterns obligatorios

- **Mass assignment protection**: todo modelo define `$fillable`
- **Prepared statements**: nunca concatenar valores en SQL
- **Inyección de dependencias**: pasar `GuzzleHttp\Client` como parámetro, no instanciar internamente
- **Error propagation**: lanzar excepciones, no llamar `Response::error()` desde servicios

---

## Seguridad y Configuración

### Variables de entorno

- Las variables de entorno se cargan desde `.env` vía `Core\Env::load()`
- **Nunca commitear** `.env` con credenciales reales
- **Archivo de referencia**: `.env.example` — mantenerlo actualizado con toda variable nueva
- Variables críticas: `GEMINI_API_KEY`, `DB_PASS`, `GOOGLE_DRIVE_PRIVATE_KEY`

### Guardrails de seguridad

- **Rate limiting**: `Core\RateLimit` — 100 req/min por IP (configurable)
- **CORS**: controlado en `public/index.php`, orígenes configurables vía `ALLOWED_ORIGINS`
- **Payload máximo**: `MAX_JSON_SIZE` (default 1 MB)
- **Sanitización de logs**: `Core\Logger` redacta campos sensibles automáticamente
- Nunca loguear valores de API keys, passwords, o datos de pacientes
- Nunca exponer stack traces o rutas internas en respuestas de error de producción
- Webhook MCP (`app/wrap/webhook.php`): debe tener autenticación por API key

### SQL Server

- Usar siempre `PDO::prepare()` con placeholders `?` o `:named`
- Cerrar cursores explícitamente después de fetch large result sets
- Queries contra vistas legacy: usar los nombres exactos de la BD (`vw_discolnet_dispensas`, etc.)
- Para BLOBs: usar `PDO::SQLSRV_ENCODING_BINARY` y stream resources

---

## Catálogo de Variables de Entorno

> Fuente de verdad: `.env.example`. Toda variable nueva **debe** agregarse aquí y en `.env.example`.

### Aplicación

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `APP_ENV` | `development` | ✅ | `Core\Env` — controla CORS, logs, mensajes de error |
| `WRAP_API_BASE` | `http://nginx` | ⚠️ Solo MCP | `app/wrap/core/ApiClient.php` — base URL interna |
| `WEBHOOK_URL` | `http://localhost:8080/app/wrap/webhook.php` | ⚠️ Solo MCP | URL pública del webhook MCP |
| `CAPABILITIES_URL` | `http://localhost:8080/app/wrap/capabilities.php` | ⚠️ Solo MCP | URL de capabilities MCP |

### Base de datos (SQL Server)

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `DB_TYPE` | `sqlsrv` | ✅ | `Core\Database` — driver PDO |
| `DB_HOST` | `localhost` | ✅ | `Core\Database` — host del servidor SQL |
| `DB_PORT` | `1433` | ✅ | `Core\Database` — puerto SQL Server |
| `DB_NAME` | `mi_base` | ✅ | `Core\Database` — nombre de la BD |
| `DB_USER` | `sa` | ✅ | `Core\Database` — usuario |
| `DB_PASS` | *(vacío)* | ✅ | `Core\Database` — contraseña |
| `DB_POOLING` | `1` | ❌ | `Core\Database` — connection pooling PDO |

### Google Drive

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `GOOGLE_DRIVE_CLIENT_EMAIL` | *(vacío)* | ⚠️ Solo adjuntos URL | Service account para acceso a archivos en Drive |
| `GOOGLE_DRIVE_PRIVATE_KEY` | *(vacío)* | ⚠️ Solo adjuntos URL | Clave privada del service account |

### Logging

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `LOG_LEVEL` | `info` | ❌ | `Core\Logger` — nivel mínimo (`error`, `warning`, `info`) |
| `LOG_RETENTION_DAYS` | `7` | ❌ | `Core\Logger` — días antes de borrar logs |
| `LOG_MAX_SIZE_MB` | `10` | ❌ | `Core\Logger` — tamaño máximo por archivo |

### Seguridad y red

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `REQUEST_TIMEOUT_MS` | `60000` | ❌ | Timeout general de request (60s) |
| `ALLOWED_ORIGINS` | *(vacío)* | ⚠️ En prod | `public/index.php` — orígenes CORS permitidos (comma-separated) |
| `MAX_JSON_SIZE` | `1048576` | ❌ | Tamaño máximo de payload JSON (1 MB) |

### Gemini API (Auditoría IA)

| Variable | Default | Requerida | Módulo / Uso |
|---|---|---|---|
| `GEMINI_API_KEY` | *(vacío)* | ✅ | `GeminiAuditService` — API key de Google AI |
| `GEMINI_MODEL` | `gemini-flash-latest` | ❌ | Modelo de Gemini a usar |
| `GEMINI_TEMPERATURE` | `0.0` | ❌ | Temperatura (0 = determinístico) |
| `GEMINI_TIMEOUT` | `300` | ❌ | Timeout de la API en segundos |
| `GEMINI_TOP_P` | *(vacío)* | ❌ | Nucleus sampling (opcional) |
| `GEMINI_TOP_K` | *(vacío)* | ❌ | Top-K sampling (opcional) |
| `GEMINI_MAX_OUTPUT_TOKENS` | `2048` | ❌ | Límite de tokens en la respuesta |
| `GEMINI_RESPONSE_MIME` | `application/json` | ❌ | Tipo MIME de la respuesta |
| `GEMINI_MEDIA_RESOLUTION` | *(vacío)* | ❌ | Resolución de imágenes enviadas |
| `GEMINI_THINKING_BUDGET` | *(vacío)* | ❌ | Presupuesto de razonamiento (thinking mode) |

### Leyenda

- ✅ **Requerida**: la aplicación no funciona sin esta variable
- ⚠️ **Condicional**: requerida solo en ciertos contextos
- ❌ **Opcional**: tiene valor default funcional

---

## Docker y Operaciones Runtime

### Arquitectura de contenedores

```
┌─────────────┐     ┌───────────────┐     ┌──────────────┐
│   Nginx     │────▶│   PHP-FPM     │────▶│  SQL Server  │
│  :8080→:80  │     │   :9000       │     │  (externo)   │
└─────────────┘     └───────────────┘     └──────────────┘
```

### Troubleshooting Docker

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

### Precauciones

- **Xdebug**: actualmente habilitado siempre en Dockerfile — impacta rendimiento
- **Volúmenes**: `./logs` montado en `./logs:/var/www/html/logs` — es redundante con el mount de `./`
- No editar archivos dentro del contenedor directamente; usar el mount de volumen

---

## Testing

### Estado actual

- **No hay PHPUnit configurado** — solo scripts CLI en `tests/`
- Tests de integración existentes: `cli_test_audit.php`, `cli_test_single.php`
- Estos tests requieren conexión real a SQL Server y API key de Gemini

### Al agregar tests

- Framework objetivo: **PHPUnit 10+** con **Mockery**
- Tests unitarios: `tests/Unit/<namespace>/<Clase>Test.php`
- Tests de integración: `tests/Integration/<Clase>Test.php`
- Ejecutar tests antes de push cuando se toque lógica core
- **No mockear** la base de datos en tests de integración — usar datos reales o fixtures
- Cada test file debe ser autocontenido (setup/teardown propios)

---

## Control de Versiones (Git)

### Estado actual

> ⚠️ El repositorio **puede no estar inicializado**. Antes de cualquier operación Git, verificar con `git status`. Si no existe repo, inicializarlo siguiendo el procedimiento de esta sección.

### Inicialización del repositorio

Si el proyecto no tiene Git inicializado:

```bash
# 1. Inicializar
git init

# 2. Crear .gitignore ANTES del primer commit
# (ver contenido obligatorio abajo)

# 3. Primer commit
git add .
git commit -m "chore: inicializar repositorio AudFact"

# 4. Configurar remote (si aplica)
git remote add origin <url-del-repositorio>
git push -u origin main
```

### .gitignore obligatorio

El archivo `.gitignore` **debe existir** antes del primer commit. Contenido mínimo:

```gitignore
# Dependencias
/vendor/

# Variables de entorno (credenciales)
.env
.env.dev
.env.prod
.env.test

# Logs
/logs/*.log
/logs/*.txt

# Respuestas crudas de IA (debug)
/responseIA/

# IDE y editores
.idea/
.vscode/
*.swp
*.swo
*~

# Sistema operativo
Thumbs.db
.DS_Store

# Archivos temporales
*.tmp
*.bak

# Composer
composer.phar

# Docker (volúmenes locales)
docker/data/
```

**NUNCA deben entrar al repo**: `.env`, `logs/`, `vendor/`, `responseIA/`, credenciales, API keys.

### Estrategia de branching

Se usa **Git Flow simplificado** adaptado para el proyecto:

```
main ← rama de producción estable
  └── develop ← rama de integración
        ├── feature/<nombre> ← nuevas funcionalidades
        ├── fix/<nombre> ← correcciones de bugs
        ├── refactor/<nombre> ← refactorizaciones
        └── security/<nombre> ← parches de seguridad
```

| Branch | Propósito | Se crea desde | Se mergea a |
|---|---|---|---|
| `main` | Producción estable | — | — |
| `develop` | Integración y pruebas | `main` | `main` (via merge) |
| `feature/*` | Nueva funcionalidad | `develop` | `develop` |
| `fix/*` | Corrección de bug | `develop` | `develop` |
| `refactor/*` | Reorganización de código | `develop` | `develop` |
| `security/*` | Parche de seguridad urgente | `main` | `main` + `develop` |

### Nomenclatura de branches

```
feature/agregar-timeout-auditoria
fix/C01-eliminar-exit-response
refactor/rate-limit-apcu-driver
security/C05-autenticar-webhook-mcp
```

Reglas:
- Nombres en **español** o en **inglés técnico** (consistente dentro del proyecto)
- Usar **kebab-case** (minúsculas, guiones)
- Incluir **ID de hallazgo** si aplica: `fix/C01-descripcion`
- Máximo **50 caracteres** en el nombre de la rama

### Flujo de trabajo Git (paso a paso)

Este flujo se integra con el tablero Kanban de la sección "Planificación Pre-Implementación":

```
1. Plan aprobado (📌→🛠️)
   └── git checkout develop
   └── git pull origin develop
   └── git checkout -b feature/nombre-tarea

2. Implementación (🧑‍💻 In Dev)
   └── Hacer cambios
   └── git add <archivos-específicos>   ← NUNCA usar git add .
   └── git commit -m "tipo(ámbito): descripción"

3. Code Review (🔍)
   └── git push origin feature/nombre-tarea
   └── Crear Pull Request hacia develop
   └── Solicitar revisión

4. QA / Testing (🧪)
   └── Verificar en branch de feature
   └── Ejecutar tests

5. Merge (📦→✅)
   └── git checkout develop
   └── git merge --no-ff feature/nombre-tarea
   └── git push origin develop
   └── git branch -d feature/nombre-tarea
```

### Commits

#### Formato (Conventional Commits en español)

```
<tipo>(<ámbito>): <descripción breve>

feat(audit): agregar timeout de 120s al batch de auditoría
fix(models): cerrar cursor PDO después de fetch en AttachmentsModel
refactor(core): extraer rate limit a interfaz + driver APCu
docs(agents): crear AGENTS.md con guidelines del proyecto
chore(docker): condicionar instalación de Xdebug
security(wrap): C05 agregar autenticación API key al webhook
```

#### Tipos permitidos

| Tipo | Uso |
|---|---|
| `feat` | Nueva funcionalidad |
| `fix` | Corrección de bug |
| `refactor` | Cambio de código sin cambiar comportamiento |
| `docs` | Documentación |
| `chore` | Tareas de mantenimiento, dependencias |
| `test` | Agregar o modificar tests |
| `perf` | Mejora de rendimiento |
| `security` | Corrección de seguridad |

#### Reglas de commits

- **Atómicos**: un commit = un cambio lógico. No mezclar refactors con features
- **Específicos**: usar `git add <archivo>` en lugar de `git add .`
- **Verificados**: asegurarse de que el código funciona antes de commitear
- **Referenciados**: si resuelve un hallazgo → `fix(core): C01 eliminar exit() de Response`
- **Sin archivos prohibidos**: `.env`, `logs/`, `vendor/`, `responseIA/`, `composer.lock`

### Cuándo hacer commit

| Situación | ¿Commitear? | Ejemplo |
|---|---|---|
| Feature completa y probada | ✅ Sí | `feat(audit): agregar límite de archivos` |
| Fix de bug verificado | ✅ Sí | `fix(models): corregir query de attachments` |
| Antes de un refactor riesgoso | ✅ Sí (checkpoint) | `chore: checkpoint antes de refactor rate-limit` |
| Código a medio hacer | ❌ No | — |
| Solo cambios de formato | ⚠️ Separar | `chore(format): aplicar prettier a controllers` |
| Documentación | ✅ Sí | `docs(agents): agregar sección de Git` |

### Protecciones y reglas de seguridad

```
⛔ PROHIBIDO en Git:
├── Commitear .env o archivos con credenciales
├── Force push a main o develop
├── Commitear directamente a main (siempre via merge desde develop)
├── Eliminar branches remotas sin aprobación
├── Rebase de branches compartidas
└── Hacer git add . (agregar archivos uno por uno)

⚠️ REQUIERE APROBACIÓN del usuario:
├── Merge a main
├── Crear tags/releases
├── Resolver conflictos (mostrar ambos lados al usuario)
├── Crear/eliminar git stash
└── Cambiar de branch cuando hay cambios sin commitear
```

### Resolución de conflictos

Cuando ocurra un conflicto de merge:

1. **Nunca resolver automáticamente** — siempre informar al usuario
2. Mostrar ambos lados del conflicto con contexto
3. Proponer resolución con justificación
4. Esperar aprobación antes de aplicar
5. Después del merge, verificar que el código funciona

```bash
# Ver archivos en conflicto
git diff --name-only --diff-filter=U

# Después de resolver
git add <archivo-resuelto>
git commit -m "fix: resolver conflicto en <archivo> (merge develop)"
```

### Tags y releases

Para marcar versiones estables:

```bash
# Formato semántico: v<major>.<minor>.<patch>
git tag -a v1.0.0 -m "release: versión inicial estable"
git push origin v1.0.0
```

| Cambio | Bump |
|---|---|
| Breaking change / cambio de API | Major (v**2**.0.0) |
| Nueva funcionalidad compatible | Minor (v1.**1**.0) |
| Bug fix | Patch (v1.0.**1**) |

**Solo crear tags con aprobación del usuario.**

---

## Planificación Pre-Implementación

**OBLIGATORIO**: antes de escribir cualquier línea de código, el agente debe crear un plan de implementación y **esperar la aprobación explícita del usuario**. Ningún cambio de código se ejecuta sin aprobación previa.

### Flujo de trabajo (Kanban)

Cada tarea sigue este flujo. El agente gestiona los estados y reporta transiciones al usuario:

```
📌 Backlog → 🛠️ Ready → 🧑‍💻 In Dev → 🔍 Review → 🧪 QA → 📦 Deploy → ✅ Done
```

| Estado | Significado | Quién actúa |
|---|---|---|
| 📌 **Backlog** | Idea o requerimiento sin refinar | Usuario propone |
| 🛠️ **Ready** | Plan aprobado, criterios de aceptación definidos | Agente planifica → Usuario aprueba |
| 🧑‍💻 **In Development** | Código en proceso de escritura | Agente implementa |
| 🔍 **Code Review** | Implementación completa, pendiente de revisión | Agente presenta → Usuario revisa |
| 🧪 **QA / Testing** | Pruebas de verificación en curso | Agente verifica |
| 📦 **Ready for Deploy** | Verificado y listo para producción | Agente confirma |
| ✅ **Done** | Entregado, documentado, y cerrado | Ambos confirman |

### Template de plan (obligatorio)

Antes de pasar una tarea de **Backlog → Ready**, el agente debe presentar el siguiente plan al usuario:

```markdown
## 📋 Plan de Implementación: [Título]

### Contexto
[Qué problema resuelve y por qué es necesario]

### Alcance
- **Archivos a crear**: [lista]
- **Archivos a modificar**: [lista]
- **Archivos afectados indirectamente**: [lista]

### Criterios de Aceptación
1. [ ] [Criterio específico y verificable]
2. [ ] [Criterio específico y verificable]
3. [ ] [Criterio específico y verificable]

### Tareas Técnicas
1. [ ] [Tarea granular]
2. [ ] [Tarea granular]
3. [ ] [Tarea granular]

### Riesgos
- [Riesgo identificado] → [Mitigación]

### Estimación
- **Complejidad**: [Baja / Media / Alta]
- **Archivos afectados**: [N]

### Verificación
- [Cómo se validará que el cambio funciona]

### Hallazgos relacionados
- [IDs de auditoría si aplica, o "ninguno"]
```

### Reglas de transición

| De → A | Condición requerida |
|---|---|
| Backlog → Ready | Plan presentado **y aprobado** por el usuario |
| Ready → In Dev | Checkpoint creado, skill correspondiente leída |
| In Dev → Review | Código completo, sin errores de sintaxis |
| Review → QA | Usuario acepta o agente auto-avanza si la tarea es de complejidad Baja |
| QA → Deploy | Tests pasan, documentación post-implementación completada |
| Deploy → Done | Verificación final exitosa |

### Niveles de complejidad

| Nivel | Criterio | Aprobación requerida |
|---|---|---|
| **Baja** | 1-2 archivos, sin cambio de API, sin riesgo | Plan breve → puede auto-avanzar Review→QA |
| **Media** | 3-5 archivos, cambio interno, riesgo bajo | Plan completo → esperar aprobación |
| **Alta** | 6+ archivos, cambio de API/schema, riesgo medio-alto | Plan detallado + discusión → aprobación explícita |

### Integración con Trello

Si el proyecto tiene tablero Trello activo (ID: `68edb398ddef3c93dda9b92a`):
- Crear tarjeta en la lista correspondiente al estado actual
- Mover tarjeta al avanzar de estado
- Agregar checklist "Criterios de Aceptación" y "Tareas Técnicas" a la tarjeta
- Usar etiquetas según tipo: `Feature` (verde), `Core` (amarillo), `Tech Debt` (naranja), `Security` (rojo)

### Cuándo NO se necesita plan formal

- Correcciones de typos o formato de código
- Actualización de documentación existente
- Cambios solicitados explícitamente con instrucciones detalladas por el usuario
- Responder preguntas o análisis sin cambio de código

---

## Notas Específicas para Agentes

### Comportamiento general

- **Idioma**: toda comunicación en Español (Latinoamérica)
- **Verificar en código**: responder con alta confianza; no adivinar comportamientos
- **Consultar skills**: antes de modificar un archivo, leer la skill correspondiente del catálogo
- **No editar `vendor/`**: las actualizaciones lo sobreescriben
- **No editar archivos dentro de contenedores Docker**: usar el mount de volumen local
- **Checkpoint obligatorio**: crear respaldo antes de cualquier modificación significativa

### Multi-agent safety

- **No crear/aplicar/eliminar `git stash`** sin solicitud explícita
- **No cambiar de branch** sin solicitud explícita
- **Scope**: al commitear, incluir solo los archivos propios del cambio
- Cuando haya archivos no reconocidos (de otro agente), ignorarlos y enfocarse en los propios
- Formato/lint automático: si los cambios son solo de formato, aplicar sin preguntar; si son semánticos, consultar

### Pipeline de auditoría IA

- **Archivos críticos**: `app/worker/GeminiAuditService.php` es el core del pipeline — cambios requieren review cuidadoso
- **Gemini API**: sujeto a rate limits (HTTP 429) y errores de disponibilidad (HTTP 503)
- **Prompts**: definidos en `app/Services/Audit/AuditPromptBuilder.php` — cualquier cambio afecta la calidad de las auditorías
- **Archivos base64**: alto consumo de RAM — respetar límites de tamaño
- **Respuestas JSON de Gemini**: pueden llegar truncadas o malformadas — siempre usar `JsonResponseParser` con `JsonRepairHelper`

### MCP Wrap

- **Webhook** (`app/wrap/webhook.php`): punto de entrada para agentes IA externos
- **Sin autenticación actualmente** — implementar antes de exponer a internet
- **Herramientas disponibles**: `GetClients`, `GetInvoices`, `GetAttachments`, `GetDispensation`
- `ApiClient.php` actualmente tiene `verify => false` — riesgo de SSL en producción

### Archivos que NO deben editarse

| Archivo | Razón |
|---|---|
| `vendor/*` | Gestionado por Composer |
| `.env` | Contiene credenciales reales |
| `logs/*` | Generados por la aplicación |
| `responseIA/*` | Respuestas crudas de Gemini para debug |
| `composer.lock` | Solo modificar indirectamente vía `composer update` |

### Archivos que SIEMPRE deben actualizarse en conjunto

| Si modificas... | También actualizar... |
|---|---|
| `app/Routes/web.php` | `README.md` (tabla de endpoints) |
| `.env` variables nuevas | `.env.example` |
| `app/Controllers/*` | Tests correspondientes (cuando existan) |
| Skills (`SKILL.md`) | `CATALOG.md` |
| Docker config | `README.md` (instrucciones de setup) |
| `AGENTS.md` | `CLAUDE.md` (mantener en sync) |
| Cualquier implementación | `CHANGELOG.md` (ver protocolo abajo) |

---

## Documentación Automática Post-Implementación

**OBLIGATORIO**: después de completar cada implementación (feature, fix, refactor, security), el agente debe ejecutar este protocolo de documentación **antes de considerar la tarea como terminada**.

### Paso 1 — CHANGELOG.md

Agregar entrada en `CHANGELOG.md` (crear si no existe) con el siguiente formato:

```markdown
## [YYYY-MM-DD]

### Tipo (feat/fix/refactor/security/perf)
- **Ámbito**: Descripción concisa del cambio
  - Archivos modificados: `archivo1.php`, `archivo2.php`
  - Hallazgo resuelto: C01 (si aplica)
  - Impacto: descripción del efecto en producción
```

Reglas del CHANGELOG:
- Solo cambios **user-facing** o que afecten el comportamiento del sistema
- No documentar: cambios de formato, typos en comentarios, reordenamiento de imports
- Ordenar por impacto: Changes primero, Fixes después
- Fecha en formato `YYYY-MM-DD`
- Mantener las entradas más recientes **arriba**

### Paso 2 — AGENTS.md (tabla de hallazgos)

Si el cambio resuelve un hallazgo de auditoría, actualizar la tabla de "Hallazgos de Auditoría Conocidos" cambiando el estado de `Pendiente` a `✅ Resuelto (YYYY-MM-DD)`.

### Paso 3 — Documentación contextual

Según el tipo de cambio, actualizar las fuentes correspondientes:

| Tipo de cambio | Documentación requerida |
|---|---|
| Nuevo endpoint REST | `README.md` tabla de endpoints + `app/Routes/web.php` comentarios |
| Nueva variable de entorno | `.env.example` + sección "Variables de entorno" de este archivo |
| Nuevo modelo/controlador | PHPDoc en la clase + skill correspondiente si aplica |
| Cambio en Docker | `README.md` sección setup + sección "Docker" de este archivo |
| Nuevo skill | `CATALOG.md` + tabla de skills de este archivo |
| Cambio en pipeline de auditoría | Sección "Pipeline de auditoría IA" de este archivo |
| Cambio de seguridad | Sección "Guardrails de seguridad" de este archivo |

### Paso 4 — Resumen de implementación

Al finalizar, generar un bloque de resumen con este formato (en el mensaje/reporte al usuario):

```
## Resumen de Implementación

**Cambio**: [descripción en una línea]
**Archivos modificados**: [lista]
**Tests**: [ejecutados/agregados/pendientes]
**Hallazgos resueltos**: [IDs o "ninguno"]
**Documentación actualizada**: [lista de docs tocados]
**Verificación**: [método usado para validar]
```

### Paso 5 — PHPDoc en código

Todo método público nuevo o modificado debe tener PHPDoc mínimo:

```php
/**
 * Descripción breve del método.
 *
 * @param  string  $invoiceId  ID de la factura
 * @param  int     $limit      Máximo de resultados (1-50)
 * @return array               Arreglo de resultados procesados
 * @throws \InvalidArgumentException  Si los parámetros son inválidos
 */
```

### Qué NO documentar

- Cambios internos sin impacto externo (refactors puros sin cambio de API)
- Correcciones de typos o formato
- Actualizaciones de dependencias menores (salvo breaking changes)
- Archivos auto-generados (`vendor/`, `logs/`, `composer.lock`)

---

## Hallazgos de Auditoría Conocidos

> Referencia rápida de problemas documentados. Ver `walkthrough.md` de auditoría para detalle completo.

| ID | Severidad | Descripción | Estado |
|---|---|---|---|
| C01 | 🔴 Crítico | `exit()` en `Response.php` y `Controller.php` | Pendiente |
| C02 | 🔴 Crítico | Rate limiting basado en archivo (no escala) | Pendiente |
| C03 | 🔴 Crítico | Auditoría secuencial sin timeout ni límite | Pendiente |
| C04 | 🔴 Crítico | BLOBs base64 sin límite de memoria | Pendiente |
| C05 | 🔴 Crítico | Webhook MCP sin autenticación | Pendiente |

Al implementar fixes, referenciar estos IDs en el commit message y actualizar esta tabla (ver protocolo de documentación arriba).

---

## Manejo de Errores y Excepciones

### Estado actual (⚠️ en proceso de corrección — ver C01)

`Core\Response::json()` **todavía usa `exit()`** después de enviar la respuesta. Esto es un problema conocido (hallazgo C01) que impide testing unitario y rompe el flujo de ejecución.

### Estado objetivo (después de implementar C01)

```
             Capa                          Manejo
┌─────────── public/index.php ──────────── Único exit() del sistema
│  ┌──────── Controlador ─────────────── Valida entrada, atrapa excepciones de servicio
│  │  ┌───── Servicio/Modelo ──────────── Lanza excepciones
│  │  │  ┌── Core\Response ────────────── Envía JSON, NO hace exit()
```

### Reglas de manejo de errores

| Capa | Qué hacer | Qué NO hacer |
|---|---|---|
| **Modelo** | Lanzar excepción si la query falla o datos inválidos | Llamar `Response::error()` |
| **Servicio** | Lanzar excepción con mensaje descriptivo y código HTTP | Hacer `echo` o `exit()` |
| **Controlador** | Capturar excepciones → `Response::error($e->getMessage(), $code)` | Dejar pasar excepciones sin manejar |
| **index.php** | Exception handler global como red de seguridad | Mostrar stack traces en producción |

### Exception handler global (actual)

```php
// public/index.php
set_exception_handler(function ($e) {
    Logger::error('Unhandled exception: ' . $e->getMessage(), ['exception' => $e]);

    if (Env::get('APP_ENV') === 'production') {
        Response::error('Internal server error', 500);  // Sin detalles
    } else {
        Response::error($e->getMessage(), 500);          // Con detalles en dev
    }
});
```

### Formato de respuesta de error

```json
{
    "success": false,
    "message": "Descripción del error para el cliente",
    "errors": ["detalle 1", "detalle 2"]   // Opcional, solo si aplica
}
```

### Qué NO incluir en respuestas de error

- ❌ Stack traces
- ❌ Rutas internas del servidor (`/var/www/html/...`)
- ❌ Nombres de tablas o columnas SQL
- ❌ Valores de variables de entorno
- ❌ Mensajes de PDO/driver completos

---

## Estándares de Logging

### Implementación actual: `Core\Logger`

El logger escribe archivos JSON rotados en `logs/app-YYYY-MM-DD.log`.

### Niveles de log

| Nivel | Cuándo usar | Ejemplo |
|---|---|---|
| `Logger::error()` | Fallos que impiden completar la operación | Error de conexión SQL, excepción no manejada, API Gemini 500 |
| `Logger::warning()` | Situaciones anómalas que no bloquean la operación | Rate limit cercano, archivo adjunto omitido por tamaño, respuesta JSON truncada |
| `Logger::info()` | Operaciones completadas exitosamente, trazabilidad | Query ejecutada, auditoría completada, webhook recibido |

### Configuración por variable de entorno

| Variable | Default | Descripción |
|---|---|---|
| `LOG_LEVEL` | `info` | Nivel mínimo: `error`, `warning`, o `info` |
| `LOG_RETENTION_DAYS` | `7` | Días antes de eliminar logs antiguos |
| `LOG_MAX_SIZE_MB` | `10` | Tamaño máximo por archivo (se vacía al exceder) |

### Sanitización automática

`Logger::sanitizeContext()` redacta automáticamente campos con estas claves:

```
password, token, secret, api_key, credit_card, ssn, authorization
```

Se reemplazan por `[REDACTED]` en el contexto del log.

### Qué loguear vs. qué NO loguear

| ✅ Loguear | ❌ NO loguear |
|---|---|
| ID de factura/dispensa procesada | Datos de pacientes (nombre, documento) |
| Conteo de resultados (`count($result)`) | Contenido completo de respuestas SQL |
| Errores de API con código HTTP | API keys o tokens completos |
| Tiempo de ejecución de operaciones | Datos base64 de archivos adjuntos |
| IP del cliente (para rate limiting) | Contraseñas o secrets |

### Formato de entrada de log

```json
{
    "timestamp": "2026-02-22T09:50:00-05:00",
    "level": "ERROR",
    "message": "Descripción del evento",
    "context": {
        "exception": {
            "class": "RuntimeException",
            "message": "Rate limit exceeded",
            "file": "/var/www/html/core/RateLimit.php:45",
            "trace": "..."
        }
    }
}
```

```

---

## Performance y Optimización

El rendimiento es crítico en AudFact debido a la latencia de la API de Gemini y el procesamiento de grandes volúmenes de datos SQL.

### 1. Base de Datos (SQL Server)
- **WITH (NOLOCK)**: Usar siempre en queries de lectura para evitar bloqueos en el sistema transaccional de producción.
- **Paginación**: Nunca traer más de 1000 registros de una vez.
- **Batch Processing**: Usar `getAttachmentBlobsByInvoiceId()` para traer todos los archivos de una factura en un solo viaje TCP.

### 2. Caché y Almacenamiento
- **APCu**: Se recomienda para caché de nivel de sistema (ej: tokens JWT, rate limiting hits) para evitar latencia de archivos.
- **Memoization**: Los controladores deben cachear resultados de configuraciones estáticas durante el ciclo de vida de la petición.

### 3. Gemini API (Audit Pipeline)
- **Timeouts**: Configurar `GEMINI_TIMEOUT` (default 300s) para evitar procesos zombies.
- **Multimodal**: No enviar archivos que excedan el límite de memoria configurado en `PHP_INI`.
- **Reintentos**: Implementar reintentos con **Exponential Backoff** ante errores 429 (Rate Limit) o 503 (Servicio no disponible).

---

## Monitoreo y Alertas

### 1. Health Check (`/health`)
El endpoint `/health` devuelve el estado de salud del sistema:
- **Database**: Verifica ping exitoso a SQL Server.
- **Logs**: Verifica permisos de escritura en `logs/`.
- **Environment**: Verifica presencia de `.env`.

### 2. Alertas Críticas
El equipo debe ser notificado si:
- El log contiene `ERROR: Unhandled exception`.
- El tiempo de una auditoría excede los 240 segundos.
- La tasa de errores 4xx/5xx supera el 5% en un periodo de 5 minutos.
- El disco de `/logs` supera el 85% de capacidad.

### 3. Perfilamiento (Profiling)
- **Xdebug**: Habilitar solo en desarrollo para trazar cuellos de botella.
- **Logger Ms**: Registrar siempre el campo `DuracionProcesamientoMs` en la tabla `AudDispEst` para medir el SLA de la IA.

---

## Manejo de Dependencias (Composer)

### Estado actual

```json
{
    "require": {
        "ext-pdo": "*",
        "guzzlehttp/guzzle": "^7.10",
        "firebase/php-jwt": "^7.0"
    }
}
```

No hay dependencias de desarrollo (`require-dev`) configuradas. PHPUnit será la primera (ver hallazgo en Testing).

### Reglas para agregar dependencias

**Siempre discutir con el usuario antes de agregar una nueva dependencia.**

Checklist de evaluación:

| Criterio | Pregunta a responder |
|---|---|
| **Necesidad** | ¿Se puede resolver sin una dependencia externa? |
| **Mantenimiento** | ¿El paquete tiene mantenimiento activo? (última release < 6 meses) |
| **Licencia** | ¿Es compatible con el proyecto? (MIT, Apache 2.0 preferidas) |
| **Tamaño** | ¿Cuántas sub-dependencias trae? |
| **Seguridad** | ¿Tiene vulnerabilidades conocidas? (`composer audit`) |
| **Alternativas** | ¿Existen opciones más ligeras o nativas de PHP? |

### Comandos seguros

```bash
# Instalar dependencias (NUNCA usar fuera del contenedor Docker)
docker exec -it audfact-php composer install

# Agregar dependencia de producción
docker exec -it audfact-php composer require vendor/package

# Agregar dependencia de desarrollo
docker exec -it audfact-php composer require --dev vendor/package

# Verificar vulnerabilidades
docker exec -it audfact-php composer audit

# Actualizar un paquete específico
docker exec -it audfact-php composer update vendor/package
```

### Archivos de Composer

| Archivo | ¿Commitear? | Notas |
|---|---|---|
| `composer.json` | ✅ Sí | Fuente de verdad de dependencias |
| `composer.lock` | ✅ Sí | Garantiza versiones reproducibles |
| `vendor/` | ❌ No | Se regenera con `composer install` |

> **Nota**: actualmente `composer.lock` está en la lista de "nunca commitear" del `.gitignore`. Esto debe revisarse cuando se inicialice Git — la práctica recomendada es **sí comitearlo** para tener builds reproducibles.

---

## Procedimiento de Rollback y Recuperación

### Contexto de deploy

- **Deploy actual**: manual por copia de archivos al servidor
- **Docker**: solo para desarrollo local (producción pendiente; se evaluará Docker en prod si se implementa Redis como caché)
- **Producción**: no existe aún, pero todo el desarrollo debe quedar **production-ready**

### Procedimiento de rollback (deploy manual)

```
1. ANTES de deployar
   └── Crear copia de respaldo del directorio actual en servidor
       └── cp -r /ruta/proyecto /ruta/proyecto.backup.YYYY-MM-DD

2. Deployar
   └── Copiar archivos nuevos
   └── Ejecutar composer install (si cambiaron dependencias)
   └── Verificar health check: curl http://servidor/health

3. Si algo falla
   └── Restaurar backup inmediatamente:
       └── rm -rf /ruta/proyecto
       └── mv /ruta/proyecto.backup.YYYY-MM-DD /ruta/proyecto
   └── Verificar health check nuevamente
   └── Documentar qué falló y por qué
```

### Rollback con Git (cuando el repo esté inicializado)

```bash
# Ver commits recientes
git log --oneline -10

# Revertir último commit (crea nuevo commit de reversión)
git revert HEAD --no-edit

# Revertir a un commit específico (PELIGRO: descarta commits)
# REQUIERE aprobación del usuario
git reset --hard <commit-hash>
```

### Rollback en Docker (entorno local)

```bash
# Si un cambio de config rompe el contenedor
docker compose down
git checkout -- docker-compose.yml docker/
docker compose up -d --build

# Rebuild completo desde cero
docker compose down -v
docker compose up -d --build --force-recreate
```

### Checklist pre-deploy

- [ ] Código funciona en entorno local Docker
- [ ] Health check (`/health`) responde correctamente
- [ ] Variables de entorno de producción configuradas (`.env`)
- [ ] No hay `exit()` sueltos que rompan el flujo (cuando C01 esté resuelto)
- [ ] Logs configurados en nivel apropiado (`LOG_LEVEL=warning` o `error` en prod)
- [ ] `APP_ENV=production` (desactiva mensajes de error detallados y CORS abierto)
- [ ] `composer install --no-dev` (sin dependencias de desarrollo)
- [ ] Backup del estado actual en servidor

---

## Configuración de CI/CD (Pipeline Propuesto)

Aunque el despliegue es manual, se define un flujo de **Integración Continua** para asegurar la calidad antes de cada release.

### Pipeline de Verificación (Pre-Push/PR)

| Etapa | Comando / Herramienta | Objetivo |
|---|---|---|
| **Lint** | `php -l` o `composer lint` | Detectar errores de sintaxis |
| **Estilos** | `php-cs-fixer` | Asegurar cumplimiento de PSR-12 |
| **Security Audit** | `composer audit` | Detectar vulnerabilidades en dependencias |
| **Unit Tests** | `phpunit` | Validar lógica core (cuando se implementen) |
| **Integración** | `tests/cli_test_audit.php` | Validar comunicación con Gemini/SQL |

### Flujo de Release
1. **Develop**: Los agentes integran features en la rama `develop`.
2. **QA Auto**: Al mergear a `develop`, el agente debe ejecutar la suite de tests CLI.
3. **Staging**: Un entorno Docker idéntico a producción para validación final.
4. **Main**: Merge a `main` → Usuario aprueba el tag de versión (ej: `v1.0.1`).
5. **Deploy**: Copia manual de archivos de `main` al servidor de producción.

---

## Glosario de Dominio

> Términos del negocio usados en el código y la base de datos. Referencia para que cualquier agente entienda el contexto del proyecto.

### Entidades principales

| Término | Significado | Tabla/Vista en BD | Campo clave |
|---|---|---|---|
| **Factura** | Documento de cobro emitido por la farmacia a la EPS | `dbo.factura` | `FacSec`, `FacNro` |
| **Dispensa / Dispensación** | Acto de entregar medicamentos a un paciente bajo una fórmula médica. Una factura puede tener múltiples dispensaciones | `vw_discolnet_dispensas` | `Dispensa` (= `DisDetNro`) |
| **Cliente / EPS** | Entidad Promotora de Salud que contrata los servicios. Es el "cliente" del sistema | `Clientes`, `NIT` | `NitSec`, `NitCom` |
| **Paciente** | Persona que recibe los medicamentos dispensados | (dentro de la dispensa) | `Paciente_doc`, `Paciente_doct` |
| **Attachment / Adjunto** | Documento digitalizado asociado a una dispensa (fórmula médica, autorización, acta de entrega) | Modelo `AttachmentsModel` | `attachmentId` |
| **Auditoría IA** | Proceso automatizado donde Google Gemini analiza una factura y sus documentos adjuntos para detectar inconsistencias, fraude o errores administrativos | `AudDispEst` | `EstAud` |

### Identificadores

| Campo | Significado | Ejemplo |
|---|---|---|
| `FacSec` | ID secuencial interno de la factura | `12345` |
| `FacNro` | Número de factura visible | `FAC-2026-001` |
| `FacNitSec` | ID del cliente/EPS asociado a la factura | `67` |
| `DisDetNro` | Número del detalle de dispensación (= `Dispensa`) | `DIS-2026-0001` |
| `NitSec` | ID secuencial del NIT en el sistema | `89` |
| `NitCom` | Número de NIT comercial de la EPS | `900123456` |
| `DisId` | ID de la dispensación vinculada a la factura | `54321` |

### Términos médicos y regulatorios

| Término | Significado |
|---|---|
| **NIT** | Número de Identificación Tributaria (Colombia) |
| **IPS** | Institución Prestadora de Salud |
| **CUM** | Código Único de Medicamento (registro INVIMA Colombia) |
| **CIE** | Clasificación Internacional de Enfermedades (código diagnóstico) |
| **Mipres** | Sistema de prescripción electrónica del Ministerio de Salud de Colombia |
| **Copago** | Valor que paga el paciente directamente |
| **Autorización** | Número aprobado por la EPS para la dispensación |
| **Acta de Entrega** | Documento firmado por el paciente al recibir medicamentos (obligatorio) |
| **Fórmula Médica** | Prescripción del médico que autoriza la entrega de medicamentos |
| **Lote** | Identificador del lote de fabricación del medicamento |

### Pipeline de auditoría

| Término | Significado |
|---|---|
| **Auditoría batch** | Proceso que analiza múltiples facturas en una sola solicitud |
| **GeminiAuditService** | Servicio PHP que orquesta la comunicación con Google Gemini API |
| **AuditPromptBuilder** | Clase que construye los prompts y schemas para la API de Gemini |
| **JsonResponseParser** | Parsea las respuestas JSON de Gemini (pueden venir malformadas) |
| **JsonRepairHelper** | Intenta reparar JSON truncado o malformado de Gemini |
| **EstAud** | Campo en `AudDispEst` que almacena el estado de la auditoría |

---

## Decisiones de Arquitectura (ADR)

### Qué es un ADR

Un **Architecture Decision Record** documenta una decisión técnica significativa con su contexto y consecuencias. Sirve para que cualquier agente o desarrollador futuro entienda el **por qué** detrás de una decisión, no solo el **qué**.

### Cuándo crear un ADR

- Cambio de tecnología o framework (ej: agregar Redis, migrar a PHPUnit)
- Decisión de diseño que afecta múltiples componentes (ej: refactorizar rate limiting)
- Trade-offs importantes (ej: base de datos de archivos vs. APCu para rate limiting)
- Rechazo de una alternativa (documentar por qué NO se eligió)

### Template de ADR

Almacenar en `plans/adr/` con el formato `ADR-NNN-titulo.md`:

```markdown
# ADR-NNN: [Título de la Decisión]

**Fecha**: YYYY-MM-DD
**Estado**: Propuesto | Aceptado | Rechazado | Obsoleto
**Hallazgo relacionado**: [ID si aplica, ej: C02]

## Contexto
[Qué problema o necesidad motivó esta decisión]

## Decisión
[Qué se decidió hacer]

## Alternativas consideradas

### Alternativa A: [nombre]
- Pros: ...
- Contras: ...

### Alternativa B: [nombre]
- Pros: ...
- Contras: ...

## Consecuencias
- [Impacto positivo]
- [Impacto negativo o trade-off]
- [Acciones de seguimiento]
```

### ADRs existentes (implícitos, por documentar)

| Decisión | Contexto | Estado |
|---|---|---|
| PHP MVC custom en lugar de Laravel/Symfony | Proyecto legacy con requerimientos específicos de SQL Server | Aceptado (implícito) |
| SQL Server como BD (no MySQL/PostgreSQL) | Integración con sistema existente Discolnet | Aceptado (implícito) |
| Google Gemini API para auditoría IA | Capacidad multimodal necesaria para analizar documentos escaneados | Aceptado (implícito) |
| Docker solo para desarrollo local | Infraestructura de producción actual no soporta contenedores | Aceptado |

---

## Límites de Responsabilidad del Agente

### Acciones autónomas (sin necesidad de aprobación)

| Acción | Condición |
|---|---|
| Leer archivos del proyecto | Siempre |
| Ejecutar `git status`, `git log`, `git diff` | Siempre |
| Ejecutar tests existentes | Siempre |
| Verificar `docker compose ps` / logs | Siempre |
| Aplicar formato de código (Prettier/lint) | Solo si los cambios son exclusivamente de formato |
| Corregir typos en documentación | Si no cambia el significado |
| Agregar PHPDoc a métodos existentes | Si no cambia la firma del método |
| Avanzar tarjeta de Review→QA | Solo si complejidad es Baja |

### Acciones que REQUIEREN aprobación

| Acción | Por qué |
|---|---|
| Crear/modificar código PHP | Todo cambio de código requiere plan aprobado |
| Agregar dependencias Composer | Puede afectar estabilidad y tamaño del proyecto |
| Modificar `docker-compose.yml` o `Dockerfile` | Afecta el entorno de todos |
| Crear/eliminar branches Git | Impacta el flujo de trabajo |
| Merge a `develop` o `main` | Punto de no retorno |
| Crear tags/releases | Marca versions oficiales |
| Resolver conflictos de merge | El usuario debe ver ambos lados |
| Modificar variables de entorno | Pueden romper conectividad |
| Cambios en prompts de Gemini | Afectan la calidad de las auditorías |
| Modificar schemas de la base de datos | Impacto en datos existentes |
| Cambiar configuración de seguridad (CORS, rate limit) | Impacto en producción |

### Acciones PROHIBIDAS (nunca, bajo ninguna circunstancia)

| Acción | Razón |
|---|---|
| Ejecutar queries DELETE/DROP/TRUNCATE en BD | Pérdida de datos irreversible |
| Publicar secrets o API keys en código/logs | Vulnerabilidad de seguridad |
| Hacer `git push --force` | Destruye historial |
| Instalar paquetes del sistema (`apt-get`, etc.) | Fuera del scope del agente |
| Modificar archivos en `vendor/` | Gestionado por Composer |
| Acceder a servidores externos no documentados | Sin autorización |
| Desactivar rate limiting o CORS en producción | Riesgo de seguridad |

---

## Compatibilidad de Archivos de Instrucciones

Este archivo (`AGENTS.md`) es la fuente canónica de guidelines. Los siguientes archivos deben mantenerse en sincronía:

| Archivo | Plataforma | Notas |
|---|---|---|
| `AGENTS.md` | Genérico / Antigravity | Fuente canónica ✅ |
| `CLAUDE.md` | Claude Code | Debe ser copia o symlink de `AGENTS.md` |
| `GEMINI.md` | Gemini CLI | Puede apuntar al catálogo de skills |
| `.cursorrules` | Cursor | Versión compacta para Cursor IDE |
