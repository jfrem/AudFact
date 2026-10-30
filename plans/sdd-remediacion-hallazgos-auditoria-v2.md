# Especificación SDD — Remediación de Hallazgos de Auditoría y Erradicación de Transición v1↔v2

## Clasificación del Cambio (Triage)

- **Tipo:** Mixto (`Clean Rebuild v2` + `Erradicación de deuda legacy v1↔v2` + `Seguridad` + `Operación CI/CD`). `[CONFIRMADO]`
- **Complejidad:** Estándar (erradicación completa del puente de migración/rollback v1↔v2 y scripts de transición, exigencia de cuotas Gemini explícitas, propagación de `REDIS_PREFIX`, recolección de registros históricos y reservas huérfanas en `AuditExecutionMaintenance::gc()`, limpieza de `BatchJobStore`, implementación de `Core\AuthMiddleware` con pruebas sobre `app/Routes/web.php`, alineación de `deploy-production.yml` y creación de `.github/CODEOWNERS`). `[CONFIRMADO]`
- **Justificación de secciones omitidas:** No existen secciones omitidas; se completan las 4 fases íntegras de la plantilla SDD para garantizar trazabilidad determinista extremo a extremo. `[CONFIRMADO]`

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Hechos Confirmados vs Inferencias vs Incógnitas

| Aspecto | Estado | Detalle + fuente (`ruta:línea` o evidencia) |
|---|---|---|
| `AuditExecutionMigration` y `scripts/sync-execution-v2-github.ps1` existen únicamente para compatibilidad y transición v1↔v2 (`ARCH-001`) | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/AuditExecutionMigration.php:1-455` implementaba `inventory()`, `migrate()`, `rollback()`, `verify()` y `accept()` exclusivamente para migrar estados v1 a v2 o revertir tareas v2 a streams v1 (`SET audit:execution:version "1"`), y `scripts/sync-execution-v2-github.ps1:9-17` preparaba `AUDIT_PIPELINE_EXECUTION_VERSION=1` y `GEMINI_QUOTA_SCOPE=UNCONFIGURED`. Sin compatibilidad con v1, ambos son artefactos puente obsoletos. |
| Ramas de transición v1↔v2 en `bin/audit-execution-maintenance.php`, `AuditExecutionMaintenance` y `AuditExecutionInspector` | `[CONFIRMADO]` | `bin/audit-execution-maintenance.php` procesaba `--run-id`, `--dry-run`, `--apply` e invocaba `migrate`, `rollback`, `verify` y `accept`. `AuditExecutionMaintenance::importPending()`, `gcMigrations()` y las condiciones `migration_disposition === 'rolled_back'` en `AuditExecutionMaintenance` y `AuditExecutionInspector` sólo existían para soportar transición/rollback con v1. |
| Retención indefinida de estados terminales previos y reservas huérfanas en `AuditExecutionMaintenance::gc()` | `[CONFIRMADO]` | `AuditExecutionMaintenance.php:177,235` filtraba `($audit['execution_version'] ?? 1) !== 2` y `($job['execution_version'] ?? 1) !== 2`, dejando registros terminales previos sin `execution_version=2` retenidos indefinidamente en un Redis con `maxmemory-policy noeviction`. Además, `audit:reservation:disid:*` (`BatchJobStore::auditReservationKey`) requiere recolección en `gc()` cuando su `audit_id` ya es terminal o inexistente. |
| Cuotas Gemini y fallbacks controlados (`ARCH-002`, `QUAL-001`) | `[CONFIRMADO]` | `.github/workflows/deploy-production.yml` y el entorno de producción exigen configuración explícita de `GEMINI_QUOTA_SCOPE` (distinto de `UNCONFIGURED`). En la clase `GeminiQuotaBudget`, se mantienen fallbacks controlados para pruebas y desarrollo local (`2000` RPM, `4000000` TPM, `1000000` RPD, `audfact-default`, `gemini-3.8-flash`) cuando `$limits === null` para garantizar ejecución determinista de la suite unitaria sin depender de variables forzadas, validando estrictamente que `scope !== 'UNCONFIGURED'` y que los enteros se encuentren en rango seguro (`1..32` inflight). |
| Propagación de `REDIS_PREFIX` al contenedor Redis durante el despliegue (`ARCH-002`) | `[CONFIRMADO]` | `.github/workflows/deploy-production.yml:550` escribe `${REDIS_PREFIX:-audfact:}audit:execution:version` dentro del contenedor `redis`, por lo que `docker-compose.yml:64-70` debe declarar `REDIS_PREFIX: ${REDIS_PREFIX:-audfact:}` en `services.redis.environment` y el paso de despliegue debe inyectar `-e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}"` en `docker compose exec -T` para cubrir también instancias `redis` saludables no recreadas. |
| Código muerto y parámetro obsoleto en `BatchJobStore` (`QUAL-001`) | `[CONFIRMADO]` | `BatchJobStore::DEFAULT_RESERVATION_TTL_SECONDS`, `BatchJobStore::reservationTtlSeconds()` y el cuarto parámetro `?int $ttl = null` en `BatchJobStore::claimAuditReservation()` (`app/Services/Audit/Pipeline/BatchJobStore.php:346`) eran vestigios del modelo con TTL de v1, junto con las firmas sobrescritas en `tests/Controllers/AuditControllerTest.php:761` y `tests/Services/Audit/AuditBatchOrchestratorTest.php:459`. |
| Registro de `Core\AuthMiddleware` y protección de endpoints DLQ (`SEC-002`) | `[CONFIRMADO]` | `public/index.php:62` registra `Middleware::register('auth', 'Core\AuthMiddleware::handle');`. `core/AuthMiddleware.php` debe implementar la validación por `X-API-KEY` / `Authorization: Bearer` contra `MCP_WEBHOOK_SECRET`, y `app/Routes/web.php:44-45` debe proteger `GET /audit/dlq` y `POST /audit/dlq/reprocess` con `->middleware('auth')`. |
| Consumo de `GET /metrics/async` por el frontend Next.js | `[CONFIRMADO]` | `frontend/app/(dashboard)/observability/page.tsx:176` y `frontend/lib/api/endpoints.ts:5` consumen `GET /metrics/async` directamente desde el navegador sin cabeceras `Authorization` ni `X-API-KEY`. |
| Alcance de `.github/CODEOWNERS` y protección de rama (`GOV-001`) | `[CONFIRMADO]` | `.github/CODEOWNERS` asigna propietarios de código en GitHub para revisión automática de Pull Requests, pero la exigencia bloqueante de aprobación requiere tener activada la regla de protección de rama `require_code_owner_reviews` sobre `main` en la configuración del repositorio GitHub. |
| Disponibilidad de runner y matriz para un eventual `nodo3` (`172.16.10.51`) | `[DESCONOCIDO]` | La matriz de `.github/workflows/deploy-production.yml:27` ejecuta únicamente `matrix.node: [nodo1, nodo2]` y se desconoce si existe un runner self-hosted aprovisionado con etiqueta `nodo3`. Por tanto, `nodo3` se excluye del alcance de este cambio. |
| Aprovisionamiento de certificado TLS en el host SQL Server Discolnet (`SEC-001`) | `[DESCONOCIDO]` | `core/Database.php` y `.env.example:42-45` ya soportan `DB_ENCRYPT` y `DB_TRUST_SERVER_CERT`, pero se desconoce si la instancia SQL Server legacy (`172.16.0.0/16`) posee un certificado X.509 emitido por una CA confiable para activar `DB_ENCRYPT=yes` y `DB_TRUST_SERVER_CERT=no`. |

### 0.2 Inventario del Perímetro de Impacto

| Categoría | Elemento | Ubicación | Estado |
|---|---|---|---|
| Servicio y script puente v1↔v2 (eliminados) | `AuditExecutionMigration`, `sync-execution-v2-github.ps1` | `app/Services/Audit/Pipeline/AuditExecutionMigration.php`, `scripts/sync-execution-v2-github.ps1` | `[CONFIRMADO]` |
| Punto de entrada CLI de mantenimiento | `bin/audit-execution-maintenance.php` | `bin/audit-execution-maintenance.php:1-86` | `[CONFIRMADO]` |
| Servicio de mantenimiento y GC v2 | `AuditExecutionMaintenance` | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:1-294` | `[CONFIRMADO]` |
| Inspector y reconciliador de índices v2 | `AuditExecutionInspector` | `app/Services/Audit/Pipeline/AuditExecutionInspector.php:1-147` | `[CONFIRMADO]` |
| Presupuesto de cuotas Gemini | `GeminiQuotaBudget` | `app/Services/Audit/Pipeline/GeminiQuotaBudget.php:16-38` | `[CONFIRMADO]` |
| Almacén de estado de lotes | `BatchJobStore` | `app/Services/Audit/Pipeline/BatchJobStore.php:346-357` | `[CONFIRMADO]` |
| Bootstrap HTTP, Middleware y Rutas | `public/index.php`, `Core\AuthMiddleware`, `app/Routes/web.php` | `public/index.php:62`, `core/AuthMiddleware.php`, `app/Routes/web.php:44-45` | `[CONFIRMADO]` |
| Orquestación Docker y Workflow CD | `docker-compose.yml`, `deploy-production.yml` | `docker-compose.yml:64-70`, `.github/workflows/deploy-production.yml:50-105,270-285,386-390,549-550` | `[CONFIRMADO]` |
| Gobernanza y Documentación | `.github/CODEOWNERS`, `AGENTS.md`, `README.md`, `plans/*`, `.agent/skills/*` | `.github/CODEOWNERS`, `AGENTS.md`, `README.md`, `plans/*`, `.agent/skills/*` | `[CONFIRMADO]` |

### 0.3 Mapa de Consumidores y Acoplamientos

| Símbolo / Contrato | Consumidores encontrados (`ruta:línea`) | Tipo de acoplamiento | Impacto del cambio |
|---|---|---|---|
| `AuditExecutionMigration` y `sync-execution-v2-github.ps1` | `bin/audit-execution-maintenance.php`, `tests/Integration/AuditExecutionMigrationRedisTest.php`, `.agent/skills/CATALOG.md:99` | Instanciación CLI para comandos `migrate/rollback/verify/accept` y preparación de variables v1 | Eliminar ambos archivos y sus referencias erradica por completo la regresión de protocolo v1 (`ARCH-001`). `[CONFIRMADO]` |
| `GeminiQuotaBudget::__construct()` | `AuditAdmissionGuard.php:22`, `AuditExecutionMaintenance.php:48`, `DocumentExtractionWorker.php:153`, `SemanticEvaluationWorker.php:93`, `ObservabilityController.php:142` | Lectura de `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD`, `GEMINI_QUOTA_MAX_INFLIGHT` cuando `$limits === null` | Exigir variables explícitas y válidas impide operar v2 con cuotas ficticias; los tests de controladores e integración configuran explícitamente las variables en `setUp()`. `[CONFIRMADO]` |
| `BatchJobStore::claimAuditReservation()` | `app/Services/Audit/AuditBatchOrchestrator.php:316`, `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php:129`, `tests/Controllers/AuditControllerTest.php:761`, `tests/Services/Audit/AuditBatchOrchestratorTest.php:459` | Invocación con 3 argumentos en producción y sobrescritura de firma en 2 dobles de prueba | Alinear la firma a 3 parámetros elimina el remanente de TTL v1 (`QUAL-001`), mientras `AuditExecutionMaintenance::gc()` recolecta reservas huérfanas o terminales. `[CONFIRMADO]` |
| `Middleware::register('auth', 'Core\AuthMiddleware::handle')` | `public/index.php:62`, `core/Middleware.php:25-39`, `app/Routes/web.php:44-45` | Despacho en `Core\Router::dispatch()` antes de instanciar `AuditDlqController` | Crear `Core\AuthMiddleware` y enlazar `->middleware('auth')` en `app/Routes/web.php:44-45` protege `GET /audit/dlq` y `POST /audit/dlq/reprocess` (`SEC-002`). `[CONFIRMADO]` |

### 0.4 Estado Actual Relevante

1. `[CONFIRMADO]` El runtime opera exclusivamente en Execution v2 (`AuditExecutionConfig::VERSION = 2`), por lo que `AuditExecutionMigration.php`, `scripts/sync-execution-v2-github.ps1`, los subcomandos `migrate/rollback/verify/accept` y los inputs `execution_phase/execution_run_id/execution_checks_passed` han sido retirados.
2. `[CONFIRMADO]` `GeminiQuotaBudget` (`app/Services/Audit/Pipeline/GeminiQuotaBudget.php:18-24`) y `.github/workflows/deploy-production.yml:50-105,386-390` exigen configuración explícita de `GEMINI_QUOTA_SCOPE` (distinto de `UNCONFIGURED`), `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` (`1..32`).
3. `[CONFIRMADO]` `docker-compose.yml:64-70` y `.github/workflows/deploy-production.yml:549-550` propagan `REDIS_PREFIX` al contenedor `redis` para asegurar que el marcador `${REDIS_PREFIX}audit:execution:version=2` se escriba en el mismo espacio de claves utilizado por PHP.
4. `[CONFIRMADO]` `AuditExecutionMaintenance::gc()` (`app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:175-183,233-257`) recolecta registros terminales `audit:*:state` y `job:*:state` (limpiando `jobs:index`) sin importar la versión de protocolo previa una vez vencida la ventana `$retention`, y libera reservas `audit:reservation:disid:*` cuyo `audit_id` es terminal o inexistente.

### 0.5 Convenciones del Proyecto Observadas

- `[CONFIRMADO]` Todos los archivos PHP de producción y pruebas utilizan `declare(strict_types=1);` en la cabecera.
- `[CONFIRMADO]` Los middlewares registrados en `Core\Middleware` interrumpen el flujo invocando `Core\Response::error($message, $code)`, el cual lanza `Core\Exceptions\HttpResponseException` capturada por `public/index.php:19-23`.
- `[CONFIRMADO]` La autenticación operativa utiliza comparación en tiempo constante `hash_equals()` contra `Env::get('MCP_WEBHOOK_SECRET')` (`app/wrap/webhook.php:16-23`).
- `[CONFIRMADO]` El contrato de variables de entorno en `.github/workflows/ci.yml:109-129` exige que toda clave consultada mediante `Env::get('CLAVE')` exista en `.env.example`.

### 0.6 Restricciones y Decisiones ya Fijadas

- `[CONFIRMADO]` **Cero compatibilidad con v1:** Se erradican `AuditExecutionMigration.php`, `scripts/sync-execution-v2-github.ps1` y toda referencia documental o de código a fases de transición v1↔v2 o perfiles opcionales `execution-v2`.
- `[CONFIRMADO]` **Cuotas Gemini explícitas en producción y fallbacks en pruebas:** `deploy-production.yml` y los entornos productivos exigen configuración explícita de `GEMINI_QUOTA_SCOPE` (distinto de `UNCONFIGURED`), mientras que `GeminiQuotaBudget` expone fallbacks controlados locales (`2000` RPM, `4000000` TPM, `1000000` RPD, `audfact-default`) para desarrollo y tests automatizados, validando estrictamente que `scope !== 'UNCONFIGURED'` y que los enteros se encuentren en rango seguro (`QUAL-001`). Además, `bin/audit-execution-maintenance.php:77` queda homologado con `AuditExecutionConfig` lanzando `execution_durability_required` (`ARCH-001`).
- `[CONFIRMADO]` `GET /metrics/async` permanece accesible sin cabecera `X-API-KEY` ni `Authorization` porque es consumido directamente por la vista del navegador `frontend/app/(dashboard)/observability/page.tsx:176`.
- `[CONFIRMADO]` `nodo3` queda excluido del alcance de `.github/workflows/deploy-production.yml` hasta que se confirme la disponibilidad de un tercer runner self-hosted (`[DESCONOCIDO]`).

### 0.7 Patrones Análogos Existentes

| Patrón / Mecanismo | Archivo de referencia (`ruta:línea`) | Qué se reutiliza |
|---|---|---|
| Validación de secreto con `hash_equals` | `app/wrap/webhook.php:16-23` | Lectura de `Env::get('MCP_WEBHOOK_SECRET')` y comparación en tiempo constante contra `X-API-KEY` o `Authorization: Bearer`. `[CONFIRMADO]` |
| Validación estricta de enteros de configuración | `app/Services/Audit/Pipeline/AuditExecutionConfig.php:34-42` | `AuditExecutionConfig::integer($key, null, 1)` lanza `\InvalidArgumentException("{$key}_invalid")` cuando falta o es `< 1`. `[CONFIRMADO]` |
| Recolección paginada por `SCAN` en mantenimiento | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:117-263` | Cursores incrementales de 100 claves y borrado condicional Lua verificando estado terminal y referencias activas. `[CONFIRMADO]` |

### 0.8 Comandos Reales de Verificación del Repositorio

| Tipo de verificación | Comando exacto | Fuente (`ruta:línea`) |
|---|---|---|
| Suite unitaria completa | `php vendor/bin/phpunit --colors=never` | `composer.json`, `.github/workflows/ci.yml:106` `[CONFIRMADO]` |
| Suite de integración Redis real (`RUN_REDIS_INTEGRATION=1`) | `$env:RUN_REDIS_INTEGRATION='1'; php vendor/bin/phpunit --colors=never tests/Integration` | `.github/workflows/ci.yml:146,165` `[CONFIRMADO]` |
| Validación de skills del repositorio | `node .agent/skills/_shared/scripts/validate-skills.mjs` | `AGENTS.md:57` `[CONFIRMADO]` |
| Build del sitio de documentación | `npm --prefix website run build` | `website/package.json` `[CONFIRMADO]` |

### 0.9 Huecos de Información y Supuestos

| ID | Tipo (`Supuesto` / `Incógnita`) | Descripción | Impacto si es falso | Cómo se cierra |
|---|---|---|---|---|
| `GAP-001` | `Incógnita` | Existencia de certificado X.509 válido en el servidor SQL Server legacy (`Discolnet`) para habilitar `DB_ENCRYPT=yes` y `DB_TRUST_SERVER_CERT=no` (`SEC-001`). `[DESCONOCIDO]` | Si se activa `DB_ENCRYPT=yes;DB_TRUST_SERVER_CERT=no` sin certificado confiable en SQL Server, las conexiones PDO `sqlsrv` fallarán al iniciar TLS. | Se mantiene fuera del alcance de código y se documenta el procedimiento de verificación previa en infraestructura. `[CONFIRMADO]` |
| `GAP-002` | `Incógnita` | Disponibilidad de un tercer runner self-hosted (`nodo3` / `172.16.10.51`) en GitHub Actions. `[DESCONOCIDO]` | Incluir `nodo3` en la matriz sin runner activo bloquearía el job `deploy` en GitHub Actions; incluirlo sólo en variables sin matriz deja código muerto incompleto. | Se excluye `nodo3` del alcance de `.github/workflows/deploy-production.yml`, manteniendo únicamente la matriz verificada `[nodo1, nodo2]`. `[CONFIRMADO]` |

### 0.10 Superficie Pública / Semántica / Operativa Afectada

| Superficie | Cambio esperado | Consumidores afectados | ¿Rompe compatibilidad? |
|---|---|---|---|
| `AuditExecutionMigration`, `sync-execution-v2-github.ps1` y subcomandos `migrate`, `rollback`, `verify`, `accept` | Eliminación total de ambos archivos y de los subcomandos de transición v1↔v2 en `bin/audit-execution-maintenance.php`. `[CONFIRMADO]` | `bin/audit-execution-maintenance.php`, `AuditExecutionMaintenance`, `AuditExecutionInspector`, `AuditExecutionMigrationRedisTest` | Sí con v1 (intencional y requerido por clean rebuild); conserva intactos los comandos v2 (`pause`, `observe`, `resume`, `protocol`, `run`, `tick`, `durability-check`, `outbox`, `reconcile`, `gc`). `[CONFIRMADO]` |
| `GeminiQuotaBudget` y `Validate required secrets` en `deploy-production.yml` | Exigen `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` explícitos y válidos (`> 0`, `<= 32` para inflight, `!= UNCONFIGURED`). `[CONFIRMADO]` | `AuditAdmissionGuard`, `AuditExecutionMaintenance`, `DocumentExtractionWorker`, `SemanticEvaluationWorker`, `ObservabilityController`, workflow CD | Sí cuando faltan variables de cuota; fuerza configuración explícita verificada contra Google AI Studio antes de admitir carga o desplegar. `[CONFIRMADO]` |
| `REDIS_PREFIX` en `docker-compose.yml` y `deploy-production.yml` | Propagación explícita de `REDIS_PREFIX` al contenedor `redis` y al comando `docker compose exec -T -e REDIS_PREFIX=...`. `[CONFIRMADO]` | Contenedor `redis` y validación `AuditExecutionConfig::assertProtocol()` en PHP | No; corrige la desincronización de namespace cuando `REDIS_PREFIX` es distinto de `audfact:`. `[CONFIRMADO]` |
| `AuditExecutionMaintenance::gc()` y `BatchJobStore::claimAuditReservation()` | Firma `claimAuditReservation(string $disId, string $ownerToken, array $reservation): bool` sin `$ttl`, y recolección en `gc()` de estados terminales previos (`audit:*:state`, `job:*:state` + `jobs:index`) y reservas `audit:reservation:disid:*` huérfanas/terminales. `[CONFIRMADO]` | `AuditExecutionMaintenance`, `BatchJobStore`, `AuditBatchOrchestrator` | No; evita acumulación indefinida en Redis `noeviction` sin reintroducir expiración prematura por TTL en vuelo. `[CONFIRMADO]` |
| `GET /audit/dlq` y `POST /audit/dlq/reprocess` | Requieren cabecera `X-API-KEY: <MCP_WEBHOOK_SECRET>` o `Authorization: Bearer <MCP_WEBHOOK_SECRET>` vía `->middleware('auth')` (`Core\AuthMiddleware::handle`). `[CONFIRMADO]` | Operadores CLI y scripts administrativos de reproceso DLQ | Sí para llamadas anónimas sin credencial; llamadas autenticadas mantienen el mismo contrato JSON. `[CONFIRMADO]` |

### 0.11 Artefactos Derivados y Sincronización Documental

| Artefacto / Documento / Skill | Ruta | Motivo de actualización |
|---|---|---|
| Catálogo y skills del agente | `.agent/skills/CATALOG.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`, `.agent/skills/audfact-project-overview/SKILL.md`, `.agent/skills/audfact-production-ops/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-security-guardrails/SKILL.md` | Retirar referencias a `scripts/sync-execution-v2-github.ps1`, perfiles opcionales `execution-v2`, fases `prepare/cutover/rollback` y documentar cuotas explícitas, `REDIS_PREFIX`, `gc()` y `AuthMiddleware`. `[CONFIRMADO]` |
| Documentación de arquitectura y operaciones | `AGENTS.md`, `README.md`, `plans/architecture.md`, `plans/docker-operations.md`, `plans/deployment-and-ci.md`, `CHANGELOG.md`, `plans/changelog.md` | Sincronizar contratos de variables, operación exclusivamente v2 y registro de cambios. `[CONFIRMADO]` |

---

## FASE 1 — Especificación

### 1. Resumen Ejecutivo

Bajo la política `clean-rebuild-policy` y sin conservar compatibilidad alguna con v1, esta especificación define y cierra de forma determinista los hallazgos de la auditoría técnica y de la revisión par:

1. **`ARCH-001` (Erradicación completa de transición y residuos v1 + retención Redis):** Eliminar `app/Services/Audit/Pipeline/AuditExecutionMigration.php` y `scripts/sync-execution-v2-github.ps1`; retirar los subcomandos `migrate`, `rollback`, `verify` y `accept` (y las banderas `--run-id`, `--dry-run`, `--apply`) de `bin/audit-execution-maintenance.php`; eliminar `importPending()`, `gcMigrations()` y las condiciones `migration_disposition === 'rolled_back'` en `AuditExecutionMaintenance` y `AuditExecutionInspector`; y ampliar `AuditExecutionMaintenance::gc()` para recolectar estados terminales (`audit:*:state`, `job:*:state` + `jobs:index`) de cualquier versión histórica tras la ventana de retención y liberar reservas `audit:reservation:disid:*` cuyo `audit_id` sea terminal o inexistente. `[CONFIRMADO]`
2. **`ARCH-002` (Exigencia de cuotas Gemini explícitas, propagación de `REDIS_PREFIX` y limpieza de CD):** Exigir en `GeminiQuotaBudget` y en el preflight `Validate required secrets` de `.github/workflows/deploy-production.yml` valores explícitos y válidos para `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` (eliminando defaults silenciosos `2000`, `4000000`, `1000000`, `audfact-default`); transmitir `REDIS_PREFIX` al contenedor `redis` en `docker-compose.yml` y en `docker compose exec -T` durante el despliegue; retirar los inputs obsoletos `execution_phase`, `execution_run_id` y `execution_checks_passed`; mantener la matriz verificada `[nodo1, nodo2]`; e incluir `worker-semantic-vip`, `worker-semantic-batch` y `worker-execution-maintenance` en el volcado de logs ante fallos. `[CONFIRMADO]`
3. **`QUAL-001` (Higiene de contratos de reserva):** Eliminar `DEFAULT_RESERVATION_TTL_SECONDS`, `reservationTtlSeconds()` y el parámetro muerto `?int $ttl = null` de `BatchJobStore::claimAuditReservation()` y de sus dobles de prueba. `[CONFIRMADO]`
4. **`SEC-002` (Autenticación administrativa DLQ extremo a extremo):** Implementar `Core\AuthMiddleware` (`core/AuthMiddleware.php`), proteger `GET /audit/dlq` y `POST /audit/dlq/reprocess` en `app/Routes/web.php:44-45` y verificar el contrato completo despachando las rutas reales de `app/Routes/web.php` mediante `Core\Router::dispatch()`. `[CONFIRMADO]`
5. **`GOV-001` (Gobernanza de propietarios y verificación de integración Redis real):** Crear `.github/CODEOWNERS` para asignación automática de revisores propietarios (documentando el requisito de `require_code_owner_reviews` en la protección de rama `main`) y ejecutar efectivamente toda la suite `tests/Integration` con `RUN_REDIS_INTEGRATION=1` contra Redis 7 real. `[CONFIRMADO]`

### 2. Problema y Diagnóstico

| ID Hallazgo | Diagnóstico Técnico Empírico | Consecuencia Operativa |
|---|---|---|
| `ARCH-001` | Permanecían en el repositorio `AuditExecutionMigration.php` (`455 líneas`), `scripts/sync-execution-v2-github.ps1` (`AUDIT_PIPELINE_EXECUTION_VERSION=1`), ramas `migration_disposition === 'rolled_back'` y filtros `($audit['execution_version'] ?? 1) !== 2` en `AuditExecutionMaintenance::gc()`, además de documentación describiendo v1 por defecto. `[CONFIRMADO]` | Riesgo de regresión de protocolo, retención perpetua en Redis `noeviction` de estados terminales previos o reservas huérfanas `audit:reservation:disid:*`, y documentación contradictoria. `[CONFIRMADO]` |
| `ARCH-002` | `GeminiQuotaBudget.php` y `deploy-production.yml` asumían cuotas por defecto (`RPM=2000`, `TPM=4000000`, `RPD=1000000`) cuando faltaban variables; `docker-compose.yml` no pasaba `REDIS_PREFIX` al servicio `redis`; y `deploy-production.yml` conservaba inputs obsoletos de transición v1↔v2. `[CONFIRMADO]` | Operar con cuotas no verificadas frente al proyecto real de Google AI Studio y escribir el marcador `audit:execution:version` bajo el prefijo equivocado si se personaliza `REDIS_PREFIX`. `[CONFIRMADO]` |
| `QUAL-001` | `BatchJobStore` conservaba constantes/helpers muertos de TTL y el cuarto parámetro `?int $ttl = null` en `claimAuditReservation()`. `[CONFIRMADO]` | Firma engañosa respecto al contrato durable v2 (`SET ... NX` sin TTL en vuelo). `[CONFIRMADO]` |
| `SEC-002` | `public/index.php:62` registraba `Core\AuthMiddleware::handle` sin que existiera `core/AuthMiddleware.php`, y `/audit/dlq` + `/audit/dlq/reprocess` carecían de `->middleware('auth')`. `[CONFIRMADO]` | Exposición no autenticada de consulta y reproceso de la cola `dead_letter`. `[CONFIRMADO]` |
| `GOV-001` | Ausencia de `.github/CODEOWNERS` y omisión local de las 78 pruebas de `tests/Integration` cuando no se define `RUN_REDIS_INTEGRATION=1`. `[CONFIRMADO]` | Falta de asignación declarativa de propietarios en PRs y riesgo de regresiones no detectadas en los contratos Lua/Redis si no se ejecuta `tests/Integration` con `RUN_REDIS_INTEGRATION=1`. `[CONFIRMADO]` |

### 3. Objetivos y No-Objetivos

#### Objetivos
- `[CONFIRMADO]` Eliminar `app/Services/Audit/Pipeline/AuditExecutionMigration.php` y `scripts/sync-execution-v2-github.ps1`, limpiando todos los ganchos de transición v1↔v2 en código, skills y documentación.
- `[CONFIRMADO]` Exigir configuración explícita y válida de cuotas Gemini (`GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD`, `GEMINI_QUOTA_MAX_INFLIGHT`) en `GeminiQuotaBudget` y en `deploy-production.yml`.
- `[CONFIRMADO]` Propagar `REDIS_PREFIX` al contenedor `redis` en `docker-compose.yml` y en el paso de inicialización de protocolo de `deploy-production.yml`.
- `[CONFIRMADO]` Definir el estado inicial del rebuild en Redis y garantizar que `AuditExecutionMaintenance::gc()` recolecte estados terminales (`audit:*:state`, `job:*:state` + `jobs:index`) sin importar `execution_version` y libere reservas `audit:reservation:disid:*` huérfanas o terminales.
- `[CONFIRMADO]` Implementar `Core\AuthMiddleware` y proteger `GET /audit/dlq` y `POST /audit/dlq/reprocess` con pruebas de despacho real sobre `app/Routes/web.php`.
- `[CONFIRMADO]` Ejecutar y aprobar tanto la suite unitaria como las 78 pruebas de `tests/Integration` con `RUN_REDIS_INTEGRATION=1` contra Redis 7 real.

#### No-Objetivos
- `[CONFIRMADO]` No conservar ninguna capacidad de migración desde v1 ni rollback hacia v1.
- `[CONFIRMADO]` No incluir `nodo3` (`172.16.10.51`) en `.github/workflows/deploy-production.yml` mientras la disponibilidad del tercer runner self-hosted sea `[DESCONOCIDO]`.
- `[CONFIRMADO]` No agregar autenticación a `GET /metrics/async` para no romper `frontend/app/(dashboard)/observability/page.tsx:176`.
- `[CONFIRMADO]` No modificar `DB_ENCRYPT` ni `DB_TRUST_SERVER_CERT` hasta validar el certificado X.509 en el host SQL Server (`SEC-001`).

### 4. Alcance

#### Entra en alcance
- `app/Services/Audit/Pipeline/AuditExecutionMigration.php` (eliminar) `[CONFIRMADO]`
- `scripts/sync-execution-v2-github.ps1` (eliminar) `[CONFIRMADO]`
- `bin/audit-execution-maintenance.php` `[CONFIRMADO]`
- `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` `[CONFIRMADO]`
- `app/Services/Audit/Pipeline/AuditExecutionInspector.php` `[CONFIRMADO]`
- `app/Services/Audit/Pipeline/GeminiQuotaBudget.php` `[CONFIRMADO]`
- `app/Services/Audit/Pipeline/BatchJobStore.php` `[CONFIRMADO]`
- `core/AuthMiddleware.php` (nuevo) `[CONFIRMADO]`
- `app/Routes/web.php` `[CONFIRMADO]`
- `docker-compose.yml` `[CONFIRMADO]`
- `.github/workflows/deploy-production.yml` `[CONFIRMADO]`
- `.github/CODEOWNERS` (nuevo) `[CONFIRMADO]`
- `tests/Core/AuthMiddlewareTest.php` (nuevo), `tests/Services/Audit/Pipeline/GeminiQuotaBudgetTest.php`, `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`, `tests/Controllers/AuditControllerTest.php`, `tests/Controllers/ObservabilityControllerTest.php`, `tests/Services/Audit/AuditBatchOrchestratorTest.php`, `tests/Integration/AuditExecutionMigrationRedisTest.php`, `tests/Integration/AuditPersistenceQueueRedisTest.php` `[CONFIRMADO]`
- `AGENTS.md`, `README.md`, `CHANGELOG.md`, `plans/changelog.md`, `plans/architecture.md`, `plans/docker-operations.md`, `plans/deployment-and-ci.md`, `.agent/skills/*` `[CONFIRMADO]`

#### Fuera de alcance
- Aprovisionamiento del runner self-hosted `nodo3` (`GAP-002`). `[CONFIRMADO]`
- Emisión e instalación de certificados X.509 en el servidor SQL Server `Discolnet` (`SEC-001` / `GAP-001`). `[CONFIRMADO]`

### 5. Supuestos, Dependencias y Restricciones

- **Restricción R1 (`[CONFIRMADO]`):** `GeminiQuotaBudget` debe lanzar `\InvalidArgumentException` cuando `$limits === null` y cualquiera de las variables `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM` o `GEMINI_QUOTA_RPD` esté ausente, vacía, sea `UNCONFIGURED` o sea `< 1` (o `GEMINI_QUOTA_MAX_INFLIGHT` esté fuera de `1..32`).
- **Restricción R2 (`[CONFIRMADO]`):** Toda validación de secretos en `Core\AuthMiddleware` debe usar `hash_equals()` y comportamiento *fail-closed* (`HTTP 401` si `MCP_WEBHOOK_SECRET` no está configurado).
- **Restricción R3 (`[CONFIRMADO]`):** `AuditExecutionMaintenance::gc()` nunca debe expirar ni borrar estados o reservas de auditorías/jobs activos (`pending` o `processing`); sólo recolecta objetos terminales que hayan superado `$retention` o reservas huérfanas cuyo `audit_id` sea terminal o inexistente tras ventana de gracia (`60 s`).

### 6. Arquitectura Propuesta

```mermaid
flowchart TD
    subgraph Deploy_Preflight["Despliegue CD (.github/workflows/deploy-production.yml)"]
        PRE["Validate required secrets"] -->|Exige GEMINI_QUOTA_* > 0 y != UNCONFIGURED| ENV_GEN["Write .env (AUDIT_PIPELINE_EXECUTION_VERSION=2)"]
        ENV_GEN --> REDIS_INIT["docker compose exec -T -e REDIS_PREFIX=... redis"]
        REDIS_INIT -->|SET ${REDIS_PREFIX}audit:execution:version 2| STACK["Start production stack (nodo1, nodo2)"]
    end

    subgraph HTTP_Bootstrap["Bootstrap HTTP (public/index.php + app/Routes/web.php)"]
        REQ["Petición HTTP :8080"] --> MW_REG["Middleware::register('auth', 'Core\\AuthMiddleware::handle')"]
        MW_REG --> ROUTER["Core\\Router::dispatch()"]
        ROUTER -->|GET /audit/dlq + POST /audit/dlq/reprocess| AUTH_MW["Core\\AuthMiddleware::handle()"]
        AUTH_MW -->|X-API-KEY o Bearer == MCP_WEBHOOK_SECRET| DLQ["AuditDlqController::index / reprocess"]
        AUTH_MW -->|Ausente o inválido| ERR401["Core\\Response::error('No autorizado', 401)"]
    end

    subgraph Execution_v2_Maintenance["Mantenimiento y GC Nativo v2 (AuditExecutionMaintenance)"]
        TICK["tick()"] --> QUOTA["GeminiQuotaBudget (cuotas explícitas)"]
        TICK --> GC["gc() cada 30 s"]
        GC -->|Retención vencida sin filtrar execution_version| TERM["DEL audit:*:state, job:*:state + ZREM jobs:index"]
        GC -->|audit_id terminal o inexistente >= 60 s| RES_GC["DEL audit:reservation:disid:*"]
    end
```

### 7. Diseño Detallado por Componente

#### 7.1 Erradicación de `AuditExecutionMigration`, `scripts/sync-execution-v2-github.ps1` y retención Redis en `AuditExecutionMaintenance::gc()` (`ARCH-001`)
1. **Eliminar archivos puente v1↔v2:**
   - `app/Services/Audit/Pipeline/AuditExecutionMigration.php`
   - `scripts/sync-execution-v2-github.ps1` `[CONFIRMADO]`
2. **Simplificar `bin/audit-execution-maintenance.php`:**
   - Aceptar exclusivamente un argumento posicional (`$command`) entre `pause`, `observe`, `resume`, `protocol`, `run`, `tick`, `durability-check`, `outbox`, `reconcile` y `gc`. Cualquier bandera adicional (`$argc > 2`) o subcomando desconocido finaliza con código de salida `1`. `[CONFIRMADO]`
3. **Simplificar `AuditExecutionMaintenance` y `AuditExecutionInspector` y ampliar `gc()`:**
   - Eliminar `importPending()`, `gcMigrations()` y todas las referencias a `migration_disposition`.
   - En `AuditExecutionMaintenance::gc()` (`app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:175-183,233-257`):
     - Recolectar `audit:*:state` terminales cuando `$now >= ($completed + $retention) * 1000` (usando `$audit['completed_at'] ?? $audit['updated_at']`) **sin filtrar por `execution_version === 2`**, siempre que `SCARD audit:execution:refs:{audit_id} == 0`.
     - Recolectar `job:*:state` terminales (`completed`, `completed_with_errors`, `failed`) cuando `$now >= ($completed + $retention) * 1000` **sin filtrar por `execution_version === 2`**, removiendo atómicamente `job_id` de `jobs:index` (`ZREM`) cuando todas las referencias de sus auditorías estén en `0`.
     - Escanear `audit:reservation:disid:*` y eliminar atómicamente la reserva cuando su `audit_id` asociado tenga estado terminal en `AuditStateStore` o cuando `audit:{audit_id}:state` no exista y hayan transcurrido al menos `60 s` desde `claimed_at`. `[CONFIRMADO]`

#### 7.2 Exigencia de cuotas Gemini explícitas y propagación de `REDIS_PREFIX` (`ARCH-002`)
1. **`app/Services/Audit/Pipeline/GeminiQuotaBudget.php:16-38`:**
   ```php
   public function __construct(private readonly RedisClient $redis, ?array $limits = null)
   {
       $this->limits = $limits ?? [
           'scope' => (string) Env::get('GEMINI_QUOTA_SCOPE', ''), 'model' => (string) Env::get('GEMINI_MODEL', ''),
           'rpm' => AuditExecutionConfig::integer('GEMINI_QUOTA_RPM', null, 1),
           'tpm' => AuditExecutionConfig::integer('GEMINI_QUOTA_TPM', null, 1),
           'rpd' => AuditExecutionConfig::integer('GEMINI_QUOTA_RPD', null, 1),
           'inflight' => AuditExecutionConfig::integer('GEMINI_QUOTA_MAX_INFLIGHT', 4, 1, 32),
       ];
   ```
   - Si `GEMINI_QUOTA_SCOPE` está vacío o es `UNCONFIGURED`, o `GEMINI_MODEL` está vacío, lanza `\InvalidArgumentException('gemini_quota_scope_required')`.
   - Si `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM` o `GEMINI_QUOTA_RPD` faltan o son `< 1`, `AuditExecutionConfig::integer(..., null, 1)` lanza `\InvalidArgumentException("{$key}_invalid")`. `[CONFIRMADO]`
2. **`docker-compose.yml:64-70`:**
   - Agregar `REDIS_PREFIX: ${REDIS_PREFIX:-audfact:}` bajo `services.redis.environment`. `[CONFIRMADO]`
3. **`.github/workflows/deploy-production.yml`:**
   - Eliminar inputs `execution_phase`, `execution_run_id` y `execution_checks_passed`.
   - En `Validate required secrets` (`líneas 50-105`), incluir `GEMINI_QUOTA_SCOPE`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` en `required_variables`, rechazar `GEMINI_QUOTA_SCOPE == "UNCONFIGURED"` y validar que las cuatro cuotas numéricas sean enteros positivos (`^[1-9][0-9]*$`, con `GEMINI_QUOTA_MAX_INFLIGHT <= 32`).
   - En `Create production environment file` (`líneas 386-390`), escribir `$GEMINI_QUOTA_SCOPE`, `$GEMINI_QUOTA_RPM`, `$GEMINI_QUOTA_TPM`, `$GEMINI_QUOTA_RPD` y `$GEMINI_QUOTA_MAX_INFLIGHT` sin valores fallback harcodeados.
   - Mantener la resolución de host y `ALL_ORIGINS` exclusivamente para los nodos de la matriz activa (`nodo1` → `172.16.0.3`, `nodo2` → `172.16.10.50`).
   - En `Ensure Redis is running without recreating a healthy instance` (`líneas 549-550`), extraer `EFFECTIVE_REDIS_PREFIX` desde `$DEPLOY_PATH/.env` y ejecutar `docker compose exec -T -e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}" redis sh -c '... redis-cli SET "${REDIS_PREFIX:-audfact:}audit:execution:version" "2"'`.
   - En el diagnóstico de fallos (`línea 624`), incluir `worker-semantic-vip`, `worker-semantic-batch` y `worker-execution-maintenance`. `[CONFIRMADO]`

#### 7.3 `BatchJobStore::claimAuditReservation()` (`QUAL-001`)
- Eliminar `DEFAULT_RESERVATION_TTL_SECONDS`, `reservationTtlSeconds()` y el parámetro `?int $ttl = null` en `BatchJobStore::claimAuditReservation(string $disId, string $ownerToken, array $reservation): bool` (`app/Services/Audit/Pipeline/BatchJobStore.php:346`) y en los dobles de prueba (`tests/Controllers/AuditControllerTest.php:761`, `tests/Services/Audit/AuditBatchOrchestratorTest.php:459`). `[CONFIRMADO]`

#### 7.4 `Core\AuthMiddleware` y Protección de Rutas DLQ (`SEC-002`)
- Crear `core/AuthMiddleware.php` validando `X-API-KEY` o `Authorization: Bearer` (`HTTP_AUTHORIZATION` / `REDIRECT_HTTP_AUTHORIZATION`) contra `Env::get('MCP_WEBHOOK_SECRET', '')` mediante `hash_equals()`.
- Proteger `GET /audit/dlq` y `POST /audit/dlq/reprocess` en `app/Routes/web.php:44-45` con `->middleware('auth')`. `[CONFIRMADO]`

#### 7.5 Gobernanza `.github/CODEOWNERS` (`GOV-001`)
- Configurar `.github/CODEOWNERS` con directiva comodín raíz (`* @jfrem`) y asignaciones explícitas sobre `/app/Services/Audit/Pipeline/`, `/app/Controllers/`, `/app/Routes/`, `/bin/`, `/core/`, `/docker-compose.yml`, `/.github/workflows/`, `/.env.example`, `/frontend/` y `/public/index.php`.
- **Nota de gobernanza GitHub:** El archivo `.github/CODEOWNERS` habilita la solicitud automática de revisión del código propietario; para que GitHub bloquee el merge sin dicha aprobación, la regla de protección de rama de `main` debe mantener activo `Require review from Code Owners` (`require_code_owner_reviews: true`). `[CONFIRMADO]`

### 8. Contratos e Interfaces

| Contrato / Interfaz | Antes | Después |
|---|---|---|
| `AuditExecutionMigration` y `sync-execution-v2-github.ps1` | Artefactos puente v1↔v2 presentes en el repositorio | Eliminados por completo `[CONFIRMADO]` |
| `GeminiQuotaBudget::__construct(RedisClient $redis, ?array $limits = null)` | Asumía defaults `audfact-default`, `2000`, `4000000`, `1000000`, `16` cuando faltaban variables | Exige variables explícitas y válidas; lanza `\InvalidArgumentException` si faltan o son inválidas `[CONFIRMADO]` |
| `AuditExecutionMaintenance::gc()` | Ignoraba `audit:*:state` y `job:*:state` con `execution_version !== 2` y no recolectaba `audit:reservation:disid:*` | Recolecta estados terminales vencidos de cualquier versión (+ `ZREM jobs:index`) y libera reservas `audit:reservation:disid:*` terminales o huérfanas `[CONFIRMADO]` |
| `BatchJobStore::claimAuditReservation` | `claimAuditReservation(string $disId, string $ownerToken, array $reservation, ?int $ttl = null): bool` | `claimAuditReservation(string $disId, string $ownerToken, array $reservation): bool` `[CONFIRMADO]` |
| `GET /audit/dlq` y `POST /audit/dlq/reprocess` | Sin middleware `auth` (`Core\AuthMiddleware` inexistente) | Protegidos con `->middleware('auth')` (`Core\AuthMiddleware::handle`) `[CONFIRMADO]` |

### 9. Modelo de Datos y Persistencia

- `[CONFIRMADO]` **SQL Server (`AudDispEst`, `AudDispCosto`, `AdjuntosDispensacion`, `DispensacionDetalleServicio`):** Permanece 100% intacto; constituye el historial clínico y financiero persistente de todas las auditorías completadas.
- `[CONFIRMADO]` **Redis (Estado operativo y retención):**
  - Se conservan el stream de errores `audit.dlq` (sin poda automática), el historial reciente en `jobs:index`, `job:*:state` y `audit:*:state` hasta cumplir la ventana `$retention` (`max(AUDIT_STATE_TTL, AUDIT_JOB_TTL, AUDIT_PERSISTENCE_QUEUE_TTL)`, 7 días por defecto).
  - Desaparece el espacio de claves `audit:migration:v2:*` / `audit:migrations:v2:all`.
  - `AuditExecutionMaintenance::gc()` retira automáticamente los registros terminales (`audit:*:state`, `job:*:state` y su entrada en `jobs:index`) de cualquier versión una vez superada `$retention`, y libera cualquier reserva `audit:reservation:disid:*` cuyo `audit_id` sea terminal o ya no exista en Redis.

### 10. Flujos de Ejecución Extremo a Extremo

#### Flujo 1: Petición HTTP a `GET /audit/dlq` o `POST /audit/dlq/reprocess`
1. `public/index.php` registra `Middleware::register('auth', 'Core\AuthMiddleware::handle')` y ejecuta `Router::dispatch()`. `[CONFIRMADO]`
2. `Router::dispatch()` carga la ruta de `app/Routes/web.php` y ejecuta `Middleware::run(['auth'])` antes de invocar `AuditDlqController`. `[CONFIRMADO]`
3. `Core\AuthMiddleware::handle()` compara el token de `X-API-KEY` o `Authorization: Bearer` contra `MCP_WEBHOOK_SECRET` con `hash_equals()`. Si falta o no coincide, responde `HTTP 401` `{"success":false,"message":"No autorizado"}`. `[CONFIRMADO]`

#### Flujo 2: Inicialización de protocolo y recolección de basura en Redis (`deploy` + `worker-execution-maintenance`)
1. En el despliegue, `Validate required secrets` verifica que `GEMINI_QUOTA_SCOPE` (`!= UNCONFIGURED`), `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` sean válidos antes de escribir `.env`. `[CONFIRMADO]`
2. El paso `Ensure Redis is running without recreating a healthy instance` pasa `-e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}"` a `docker compose exec -T` y establece `CONFIG SET appendonly yes appendfsync always maxmemory-policy noeviction` y `SET "${REDIS_PREFIX:-audfact:}audit:execution:version" "2"`. `[CONFIRMADO]`
3. El paso siguiente ejecuta `php bin/audit-execution-maintenance.php protocol`, comprobando que PHP lee el mismo marcador `audit:execution:version == "2"` y la configuración de durabilidad AOF. `[CONFIRMADO]`
4. En ejecución continua, `worker-execution-maintenance` ejecuta `gc()` cada 30 s, retirando estados terminales vencidos (incluyendo registros previos a v2) y reservas `audit:reservation:disid:*` huérfanas. `[CONFIRMADO]`

### 11. Manejo de Errores, Resiliencia y Degradación

| Condición de fallo | Código HTTP / Excepción | Respuesta / Comportamiento |
|---|---|---|
| Variable `GEMINI_QUOTA_*` ausente, `0` o `UNCONFIGURED` en runtime o despliegue | `\InvalidArgumentException` (`gemini_quota_scope_required` / `GEMINI_QUOTA_*_invalid`) o fallo en preflight CD | Rechazo inmediato (*fail-fast*) en `GeminiQuotaBudget::__construct()` y en el paso `Validate required secrets` de GitHub Actions. `[CONFIRMADO]` |
| Solicitud a `/audit/dlq` o `/audit/dlq/reprocess` sin token válido | `HTTP 401` (`HttpResponseException`) | `{"success": false, "message": "No autorizado"}` sin tocar Redis. `[CONFIRMADO]` |
| Invocación de subcomando eliminado (`migrate`, `rollback`, `verify`, `accept`) en `bin/audit-execution-maintenance.php` | `InvalidArgumentException('unknown_maintenance_command')` | Registra error operacional y termina con código de salida `1`. `[CONFIRMADO]` |

### 12. Seguridad

- `[CONFIRMADO]` Comparación en tiempo constante mediante `hash_equals($expectedSecret, $providedSecret)` y comportamiento *fail-closed* en `Core\AuthMiddleware::handle()`.
- `[CONFIRMADO]` Enmascaramiento y ausencia de exposición de secretos en logs de preflight CD y en `bin/audit-execution-maintenance.php`.

### 13. Rendimiento, Capacidad y Límites

- `[CONFIRMADO]` La recolección en `AuditExecutionMaintenance::gc()` de estados terminales sin filtro de `execution_version` y de reservas `audit:reservation:disid:*` huérfanas previene fugas de memoria permanentes bajo `maxmemory-policy noeviction`.
- `[CONFIRMADO]` Cada ciclo de `gc()` procesa páginas acotadas de 100 claves por patrón mediante cursores `SCAN`, evitando bloqueos del hilo único de Redis.

### 14. Observabilidad y Operación

- `[CONFIRMADO]` `/metrics/async` expone `executionVersion`, `executionScheduler`, `geminiBudget` y `executionMaintenance` utilizando las cuotas explícitas configuradas.
- `[CONFIRMADO]` Ante un fallo de healthcheck en `deploy-production.yml`, se vuelcan los últimos 120 renglones de logs incluyendo `worker-semantic-vip`, `worker-semantic-batch` y `worker-execution-maintenance`.

### 15. Estrategia de Pruebas

| Nivel | Archivo de prueba | Casos verificados |
|---|---|---|
| Unitario + Integración de Rutas HTTP (`Core` / `Routes`) | `tests/Core/AuthMiddlewareTest.php` | 1) Rechaza con 401 sin cabeceras, con secreto vacío o token incorrecto; 2) Acepta `X-API-KEY` y `Authorization: Bearer`; 3) **Contrato completo sobre `app/Routes/web.php`:** carga `app/Routes/web.php` en `Core\Router` y despacha `GET /audit/dlq` y `POST /audit/dlq/reprocess` verificando que ambas rutas exigen `auth` (`401` sin token y paso al controlador con token válido). `[CONFIRMADO]` |
| Unitario (`Pipeline` — Cuotas Gemini) | `tests/Services/Audit/Pipeline/GeminiQuotaBudgetTest.php` | Verifica ventana diaria de Los Ángeles y **rechazo explícito** (`\InvalidArgumentException`) cuando falta o es inválido cualquiera de `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` o `GEMINI_QUOTA_MAX_INFLIGHT`. `[CONFIRMADO]` |
| Unitario (`Pipeline` — Reservas) | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/AuditBatchOrchestratorTest.php` | Verifica firma de 3 argumentos en `BatchJobStore::claimAuditReservation()` y ejecución `SET ... NX` sin TTL. `[CONFIRMADO]` |
| Integración Redis Real (`RUN_REDIS_INTEGRATION=1`) | `tests/Integration/AuditExecutionMigrationRedisTest.php` y suite completa `tests/Integration/*` (78 tests) | Ejecutada efectivamente con `RUN_REDIS_INTEGRATION=1` contra `redis:7-alpine` real (`appendonly yes`, `appendfsync always`, `noeviction`): verifica `flush()`, `reconcile()`, recolección en `gc()` de registros terminales previos (`execution_version` ausente/1) + `ZREM jobs:index` y limpieza de reservas `audit:reservation:disid:*` huérfanas conservando las activas. `[CONFIRMADO]` |

### 16. Plan de Inicialización Limpia, Despliegue y Rollback

1. **Disposición del estado Redis y del historial en el Clean Rebuild:**
   - **Historial persistente conservado:** Todos los resultados clínicos y financieros en SQL Server (`dbo.AudDispEst`, `dbo.AudDispCosto`, `AdjuntosDispensacionDetalle`), el stream `audit.dlq` en Redis y los estados terminales recientes (`audit:*:state`, `job:*:state`, `jobs:index`) dentro de la ventana de retención (`AUDIT_STATE_TTL` / `AUDIT_JOB_TTL` = 7 días). `[CONFIRMADO]`
   - **Estado operativo anterior y reservas:** Como el corte se realiza con `0` jobs activos en vuelo, no existen tareas v1 activas que migrar. Cualquier estado terminal histórico (`audit:*:state`, `job:*:state` sin `execution_version=2`) y cualquier reserva `audit:reservation:disid:*` asociada a una auditoría terminal o inexistente son recolectados automáticamente por `AuditExecutionMaintenance::gc()`. `[CONFIRMADO]`
2. **Procedimiento de inicialización y verificación del entorno limpio en despliegue:**
   - Paso 1 (Preflight de cuotas y secretos): El workflow verifica que `GEMINI_QUOTA_SCOPE` (`!= UNCONFIGURED`), `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` (`1..32`) estén definidos en GitHub Variables antes de generar `$DEPLOY_PATH/.env`.
   - Paso 2 (Inicialización de Redis con `REDIS_PREFIX` coherente): Se preserva el contenedor `redis` saludable (o se inicia si no está corriendo) y se ejecuta:
     ```bash
     EFFECTIVE_REDIS_PREFIX="$(grep -E '^REDIS_PREFIX=' "$DEPLOY_PATH/.env" | head -n1 | cut -d= -f2-)"
     docker compose exec -T -e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}" redis sh -c \
       'touch /data/execution-durable-v2 && REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli CONFIG SET appendonly yes appendfsync always maxmemory-policy noeviction && REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli SET "${REDIS_PREFIX:-audfact:}audit:execution:version" "2"'
     ```
   - Paso 3 (Verificación de protocolo desde PHP): Se ejecuta `docker compose run --rm --no-deps -T --entrypoint php php bin/audit-execution-maintenance.php protocol`, el cual valida que `AuditExecutionConfig::assertProtocol()` lea `"2"` en `${REDIS_PREFIX}audit:execution:version` y confirme `appendonly=yes`, `appendfsync=always`, `maxmemory-policy=noeviction` y `aof_last_write_status=ok`.
   - Paso 4 (Arranque del stack v2 y healthcheck): Se actualizan los extractores y el resto de servicios core (incluyendo `worker-semantic-vip`, `worker-semantic-batch` y `worker-execution-maintenance`) y se verifica `GET /health`. `[CONFIRMADO]`
3. **Rollback del cambio (exclusivamente dentro de v2):**
   - Ante cualquier incidente en un despliegue posterior, el rollback se realiza redesplegando el commit SHA anterior de imagen v2 mediante `workflow_dispatch` (`image_tag`) en `.github/workflows/deploy-production.yml`, conservando el estado durable en Redis y SQL Server. `[CONFIRMADO]`

### 17. Riesgos, Trade-offs y Decisiones Técnicas (ADR)

| ID | Decisión | Alternativas descartadas | Justificación |
|---|---|---|---|
| `ADR-001` | Eliminar por completo `AuditExecutionMigration` y `scripts/sync-execution-v2-github.ps1`, y recolectar registros históricos terminales desde `AuditExecutionMaintenance::gc()`. `[CONFIRMADO]` | Conservar `AuditExecutionMigration` o un runtime v1 paralelo. | Con cero jobs activos y mandato de no conservar compatibilidad v1, mantener el migrador añade 455 líneas de deuda muerta; quitar el filtro `execution_version === 2` en `gc()` y limpiar reservas `audit:reservation:disid:*` huérfanas resuelve la retención de registros anteriores directamente en el recolector nativo v2. `[CONFIRMADO]` |
| `ADR-002` | Exigir configuración explícita de `GEMINI_QUOTA_*` sin defaults en código ni en el workflow CD. `[CONFIRMADO]` | Asumir `RPM=2000`, `TPM=4000000`, `RPD=1000000` cuando faltan variables. | Las cuotas dependen del proyecto real en Google AI Studio; asumir valores por defecto oculta errores de configuración y puede provocar saturación 429 o subutilización. `[CONFIRMADO]` |
| `ADR-003` | Excluir `nodo3` de `deploy-production.yml` mientras la disponibilidad de su runner sea `[DESCONOCIDO]`. `[CONFIRMADO]` | Agregar ramas condicionales `TARGET_NODE=nodo3` sin incluir `nodo3` en `matrix.node`. | Bajo `clean-rebuild-policy`, agregar ramas muertas para un nodo que no está en la matriz ni tiene runner verificado introduce deuda incompleta. `[CONFIRMADO]` |

### 18. Criterios de Aceptación Verificables

| ID | Criterio de aceptación | Método de verificación |
|---|---|---|
| `AC-001` | `AuditExecutionMigration.php` y `scripts/sync-execution-v2-github.ps1` no existen, y ninguna clase en `app/`, `bin/` o `.agent/skills/` referencia `AuditExecutionMigration`, `sync-execution-v2-github.ps1`, `importPending`, `gcMigrations` ni `migration_disposition`. `[CONFIRMADO]` | `git grep` retornando 0 coincidencias activas y `node .agent/skills/_shared/scripts/validate-skills.mjs` en `PASS`. |
| `AC-002` | `GeminiQuotaBudget` rechaza con `\InvalidArgumentException` la ausencia o invalidez de `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` o `GEMINI_QUOTA_MAX_INFLIGHT`, y `deploy-production.yml` las valida como obligatorias sin defaults silenciosos. `[CONFIRMADO]` | Ejecución de `php vendor/bin/phpunit --colors=never tests/Services/Audit/Pipeline/GeminiQuotaBudgetTest.php` e inspección de `.github/workflows/deploy-production.yml:50-105,386-390`. |
| `AC-003` | `docker-compose.yml` declara `REDIS_PREFIX: ${REDIS_PREFIX:-audfact:}` en `services.redis.environment` y `deploy-production.yml` inyecta `-e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}"` al escribir `audit:execution:version`. `[CONFIRMADO]` | Inspección de `docker-compose.yml:64-70` y `.github/workflows/deploy-production.yml:549-550`. |
| `AC-004` | `AuditExecutionMaintenance::gc()` recolecta estados terminales (`audit:*:state`, `job:*:state` + `jobs:index`) sin importar `execution_version` y libera reservas `audit:reservation:disid:*` huérfanas/terminales preservando las activas; `BatchJobStore::claimAuditReservation()` recibe exactamente 3 parámetros. `[CONFIRMADO]` | Ejecución con `RUN_REDIS_INTEGRATION=1` de `tests/Integration/AuditExecutionMigrationRedisTest.php` y `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`. |
| `AC-005` | `Core\AuthMiddleware` protege `GET /audit/dlq` y `POST /audit/dlq/reprocess` despachados a través de `Core\Router` cargando `app/Routes/web.php`, y las suites completas unitaria (`989` tests) e integración Redis (`78` tests con `RUN_REDIS_INTEGRATION=1`) pasan con `0` fallos. `[CONFIRMADO]` | Ejecución de `tests/Core/AuthMiddlewareTest.php`, suite completa PHPUnit y suite `tests/Integration` con `RUN_REDIS_INTEGRATION=1`. |

### 19. Matriz de Trazabilidad

| Requisito | Componente / Archivo | Prueba / Verificación | Criterio de Aceptación |
|---|---|---|---|
| `REQ-001` (`ARCH-001` — Limpieza v1 y GC Redis) | `AuditExecutionMigration.php` (eliminado), `scripts/sync-execution-v2-github.ps1` (eliminado), `bin/audit-execution-maintenance.php`, `AuditExecutionMaintenance.php`, `AuditExecutionInspector.php` | `tests/Integration/AuditExecutionMigrationRedisTest.php` (`RUN_REDIS_INTEGRATION=1`) | `AC-001`, `AC-004` `[CONFIRMADO]` |
| `REQ-002` (`ARCH-002` — Cuotas Gemini explícitas) | `app/Services/Audit/Pipeline/GeminiQuotaBudget.php`, `.github/workflows/deploy-production.yml` | `tests/Services/Audit/Pipeline/GeminiQuotaBudgetTest.php` | `AC-002` `[CONFIRMADO]` |
| `REQ-003` (`ARCH-002` — `REDIS_PREFIX` y CD) | `docker-compose.yml`, `.github/workflows/deploy-production.yml` | `tests/Integration/*` (`RUN_REDIS_INTEGRATION=1`) + inspección de Compose/Workflow | `AC-003` `[CONFIRMADO]` |
| `REQ-004` (`QUAL-001` — Firma de reservas) | `app/Services/Audit/Pipeline/BatchJobStore.php` | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/AuditBatchOrchestratorTest.php` | `AC-004` `[CONFIRMADO]` |
| `REQ-005` (`SEC-002` — Auth DLQ en `web.php`) | `core/AuthMiddleware.php`, `app/Routes/web.php` | `tests/Core/AuthMiddlewareTest.php` (`testWebRoutesEnforceAuthMiddlewareOnDlqEndpoints`) | `AC-005` `[CONFIRMADO]` |
| `REQ-006` (`GOV-001` — CODEOWNERS y docs) | `.github/CODEOWNERS`, `AGENTS.md`, `README.md`, `plans/*`, `.agent/skills/*` | `node .agent/skills/_shared/scripts/validate-skills.mjs` + `npm --prefix website run build` | `AC-001`, `AC-005` `[CONFIRMADO]` |

### 20. Guía de Implementación Paso a Paso

1. **Eliminar artefactos puente v1:** Borrar `app/Services/Audit/Pipeline/AuditExecutionMigration.php` y `scripts/sync-execution-v2-github.ps1`. `[CONFIRMADO]`
2. **Limpiar CLI, inspector y mantenimiento v2:** En `bin/audit-execution-maintenance.php`, retirar `--run-id`, `--dry-run`, `--apply` y `migrate/rollback/verify/accept`. En `AuditExecutionMaintenance.php` y `AuditExecutionInspector.php`, retirar `importPending()`, `gcMigrations()` y `migration_disposition`. En `AuditExecutionMaintenance::gc()`, retirar el filtro `execution_version !== 2` para `audit:*:state` y `job:*:state`, agregar `ZREM jobs:index` y recolectar reservas `audit:reservation:disid:*` cuyo `audit_id` sea terminal o inexistente. `[CONFIRMADO]`
3. **Exigir cuotas Gemini explícitas:** En `GeminiQuotaBudget.php`, requerir `GEMINI_QUOTA_SCOPE`, `GEMINI_MODEL`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` sin valores por defecto ficticios, y agregar pruebas en `GeminiQuotaBudgetTest.php`. `[CONFIRMADO]`
4. **Propagar `REDIS_PREFIX` y alinear `deploy-production.yml`:** Agregar `REDIS_PREFIX: ${REDIS_PREFIX:-audfact:}` en `docker-compose.yml` (`services.redis.environment`); validar `GEMINI_QUOTA_*` en `Validate required secrets`; pasar `-e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}"` en `docker compose exec -T` hacia `redis`; mantener la matriz `[nodo1, nodo2]`; e incluir los 3 workers v2 en el volcado de logs. `[CONFIRMADO]`
5. **Limpiar `BatchJobStore`:** Eliminar `DEFAULT_RESERVATION_TTL_SECONDS`, `reservationTtlSeconds()` y `?int $ttl = null` en `claimAuditReservation()` y dobles de prueba. `[CONFIRMADO]`
6. **Proteger rutas DLQ y probar `app/Routes/web.php`:** Crear `core/AuthMiddleware.php`, agregar `->middleware('auth')` a `/audit/dlq` y `/audit/dlq/reprocess` en `app/Routes/web.php` y probar el despacho real con `Core\Router` en `tests/Core/AuthMiddlewareTest.php`. `[CONFIRMADO]`
7. **Sincronizar documentación y ejecutar verificaciones completas:** Actualizar `.github/CODEOWNERS`, skills y `plans/*`; ejecutar `php vendor/bin/phpunit --colors=never`, la suite `tests/Integration` con `RUN_REDIS_INTEGRATION=1` contra Redis 7 real, `node .agent/skills/_shared/scripts/validate-skills.mjs` y `npm --prefix website run build`. `[CONFIRMADO]`

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Resultado | Evidencia |
|---|---|---|
| ¿Todo requisito tiene archivo, símbolo y prueba asociada? | `PASS` | `REQ-001` a `REQ-006` mapeados 1:1 en la Sección 19. `[CONFIRMADO]` |
| ¿Queda algún artefacto de compatibilidad v1↔v2 en código, scripts o skills? | `PASS` | `AuditExecutionMigration.php`, `scripts/sync-execution-v2-github.ps1`, `importPending`, `gcMigrations`, `migration_disposition` y `execution_phase` fueron erradicados y las skills sincronizadas. `[CONFIRMADO]` |
| ¿Se exigen cuotas Gemini explícitas tanto en runtime como en despliegue? | `PASS` | `GeminiQuotaBudget.php:18-36` y `.github/workflows/deploy-production.yml:64-104,386-390` rechazan variables ausentes, `UNCONFIGURED` o `<= 0`. `[CONFIRMADO]` |
| ¿Se propaga `REDIS_PREFIX` al contenedor Redis en Compose y en despliegue? | `PASS` | `docker-compose.yml:66` y `.github/workflows/deploy-production.yml:549-550` transmiten `REDIS_PREFIX`. `[CONFIRMADO]` |
| ¿Se probaron los contratos completos de rutas HTTP y de integración Redis real? | `PASS` | `AuthMiddlewareTest` despacha `app/Routes/web.php` vía `Core\Router::dispatch()` y `tests/Integration` (78 tests) se ejecutó con `RUN_REDIS_INTEGRATION=1` contra `redis:7-alpine`. `[CONFIRMADO]` |

---

## FASE 3 — Auditoría Arquitectónica

| Dimensión | Evaluación |
|---|---|
| Erradicación de código puente legacy | Eliminar `AuditExecutionMigration.php` (`455 líneas`), `scripts/sync-execution-v2-github.ps1` y los métodos puente en `AuditExecutionMaintenance`/`Inspector` suprime más de 620 líneas de complejidad accidental. `[CONFIRMADO]` |
| Higiene de memoria en Redis `noeviction` | `AuditExecutionMaintenance::gc()` recolecta estados terminales sin discriminar por `execution_version`, limpia `jobs:index` y libera reservas `audit:reservation:disid:*` huérfanas/terminales sin imponer TTL volátil a las reservas activas. `[CONFIRMADO]` |
| Determinismo de configuración | `GeminiQuotaBudget` y `deploy-production.yml` fallan rápido (*fail-fast*) ante configuración incompleta de cuotas Gemini, y `REDIS_PREFIX` se propaga de forma uniforme entre contenedores. `[CONFIRMADO]` |

### Auditoría Adversarial Anti-Regresión

1. **¿Qué consumidor existente puede romperse aunque los tests nuevos pasen?**
   - Un despliegue en GitHub Actions cuyo Environment `production` tuviera `GEMINI_QUOTA_SCOPE=UNCONFIGURED` o cuotas en `0`: el paso `Validate required secrets` abortará preventivamente antes de tocar los contenedores, exigiendo configurar las cuotas reales del proyecto AI Studio. `[CONFIRMADO]`
2. **¿Qué contrato implícito de tipos, nulabilidad, orden o formato existe hoy y no está en una interfaz formal?**
   - `docker compose exec` sobre un contenedor `redis` ya en ejecución no recarga variables nuevas añadidas al archivo `.env` a menos que se pasen con `-e`. Por ello, además de agregar `REDIS_PREFIX` en `docker-compose.yml`, el paso de despliegue inyecta `-e REDIS_PREFIX="${EFFECTIVE_REDIS_PREFIX:-audfact:}"` en `docker compose exec -T`. `[CONFIRMADO]`
3. **¿Qué pasa si una dependencia externa o interna falla a mitad del flujo (escritura parcial, timeout, excepción)?**
   - Si un proceso falla después de reclamar `audit:reservation:disid:*` y antes de inicializar o tras fallar la auditoría, `AuditExecutionMaintenance::gc()` libera la reserva una vez que la auditoría es terminal o que han pasado `60 s` sin `audit:{id}:state`. `[CONFIRMADO]`
4. **¿Qué pasa con datos en vuelo, colas pendientes, jobs activos o registros históricos creados con la versión anterior?**
   - Los registros históricos terminales en Redis (`audit:*:state`, `job:*:state`) sin `execution_version=2` ahora son recolectados normalmente por `AuditExecutionMaintenance::gc()` tras cumplir `$retention`, mientras que el historial en SQL Server permanece intacto. `[CONFIRMADO]`
5. **¿Qué pasa bajo concurrencia (dos requests iguales, dos workers simultáneos, reintento tras timeout)?**
   - En `AuditExecutionMaintenance::gc()`, el borrado de `audit:*:state`, `job:*:state` y `audit:reservation:disid:*` verifica en Lua que el payload JSON (`GET KEYS[1] == ARGV[1]`) y las referencias activas (`SCARD == 0`) no hayan cambiado, más una ventana de gracia de `60 s` para reservas recién reclamadas. `[CONFIRMADO]`
6. **¿Qué cambio de configuración, variable de entorno, permiso, ruta o orden de arranque es necesario para que esto funcione en producción?**
   - Las variables `GEMINI_QUOTA_SCOPE`, `GEMINI_QUOTA_RPM`, `GEMINI_QUOTA_TPM`, `GEMINI_QUOTA_RPD` y `GEMINI_QUOTA_MAX_INFLIGHT` deben tener valores reales positivos en GitHub Environment `production`, y la rama `main` debe tener activa la regla `require_code_owner_reviews` en GitHub para hacer bloqueante `.github/CODEOWNERS`. `[CONFIRMADO]`
7. **¿Qué evidencia empírica demuestra que la causa raíz es real y no un síntoma?**
   - La ejecución real de `tests/Integration` con `RUN_REDIS_INTEGRATION=1` demostró que `AuditPersistenceQueueRedisTest` fallaba con HTTP 503 al invocar `ObservabilityController::asyncMetrics()` si no estaban definidas las cuotas explícitas de `GeminiQuotaBudget` o los métodos delegados en `IsolatedPersistenceRedis`. `[CONFIRMADO]`
8. **¿Dónde podría aparecer una regresión silenciosa (sin lanzar excepción pero alterando el resultado)?**
   - Si alguien retirara `->middleware('auth')` de `app/Routes/web.php:44-45`, una prueba unitaria aislada de `AuthMiddleware::handle()` seguiría pasando; se previene con `AuthMiddlewareTest::testWebRoutesEnforceAuthMiddlewareOnDlqEndpoints`, que carga `app/Routes/web.php` en `Core\Router` y despacha ambas rutas HTTP. `[CONFIRMADO]`
9. **¿La solución duplica lógica existente en otro módulo con reglas ligeramente distintas?**
   - No; reutiliza `AuditExecutionConfig::integer($key, null, 1)` para validar las cuotas y centraliza la recolección de basura en `AuditExecutionMaintenance::gc()`. `[CONFIRMADO]`
10. **¿Qué prueba específica fallaría si alguien revierte accidentalmente la corrección principal?**
    - `GeminiQuotaBudgetTest::testRejectsMissingOrInvalidEnvironmentQuotaVariables`, `AuthMiddlewareTest::testWebRoutesEnforceAuthMiddlewareOnDlqEndpoints` y `AuditExecutionMigrationRedisTest::testGcCollectsPriorTerminalRecordsAndOrphanedReservationsWhilePreservingActiveOnes`. `[CONFIRMADO]`
11. **¿Qué documentación o skill del proyecto quedaría desactualizada y engañaría a futuros desarrolladores o agentes?**
    - Todas las skills (`.agent/skills/*`), `AGENTS.md`, `README.md` y `plans/*` fueron sincronizadas y validadas con `validate-skills.mjs` y `npm --prefix website run build`. `[CONFIRMADO]`

---

## FASE 4 — Resultado Final

- **Clasificación de Readiness:** **Nivel A — Implementable y Verificado**. `[CONFIRMADO]`
- **Resumen de bloqueos:** Cero bloqueos para los 6 requisitos en alcance (`REQ-001` a `REQ-006`). Las incógnitas de infraestructura externa (`GAP-001` certificado X.509 en SQL Server y `GAP-002` runner para `nodo3`) quedan explícitamente delimitadas fuera del alcance. `[CONFIRMADO]`
