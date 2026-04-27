# Changelog AudFact

## [2026-04-27] — Reestructuración Deep: app/Services/Audit

### 🔵 Architecture / Refactor
- **AUDIT-013**: Reestructuración profunda del árbol `app/Services/Audit`:
  - **Rename `Events/` → `Pipeline/`**: El namespace genérico `Events` se renombró a `Pipeline` para reflejar con precisión su responsabilidad (pipeline event-driven de auditoría).
  - **Fusión `FieldStructure` → `AuditComparisonType`**: Los 6 métodos estáticos de detección de tipo por convención (fechas, cantidades, umbrales semánticos) se integraron directamente en el enum `AuditComparisonType`. −1 archivo.
  - **Fusión `GeminiGatewayFactory` → `GeminiConfig::fromEnv()` + `GeminiGateway::create()`**: La factory separada se absorbió como método estático en las clases que configuran e instancian el gateway. −1 archivo.
  - **`AuditFindingRules` → métodos estáticos**: Eliminadas 3 instanciaciones innecesarias (`new AuditFindingRules()`) en `DocumentPolicyEngine`, `RulesEvaluationWorker` y `AuditResultAggregator`.
  - **Resultado neto**: De 26 archivos dispersos a 22 archivos organizados en 2 subcarpetas (`Pipeline/`, `Debug/`).

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Reconstruido `plans/architecture.md` con la nueva estructura. Actualizado `plans/changelog.md`. Skills `audfact-audit-gemini` y `CATALOG.md` pendientes de actualización por el rename de namespace.
  - Archivos actualizados: `plans/architecture.md`, `plans/changelog.md`

## [2026-04-27] — Refactorización Arquitectónica: GeminiGateway

### 🟢 Calidad de Código / Refactor
- **AUDIT-012**: Rediseño completo de la capa de comunicación con IA (`GeminiGateway`).
  - **Extracción de responsabilidades (SRP)**: Separación de la configuración en un Value Object inmutable (`GeminiConfig`) y extracción de la resiliencia en un componente aislado y testeable (`GeminiCircuitBreaker`).
  - **Eliminación de código muerto**: Removidas funciones inutilizadas y simplificado el constructor de 12 a 4 parámetros.
  - **Desacoplamiento de contexto**: El contexto de trazabilidad (`X-Audit-Context-*`) se desacopló del array de `generationOverrides`, inyectándose explícitamente como un parámetro dedicado (`$debugContext`), eliminando el antipatrón de "bolsa mágica".

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada la documentación de arquitectura y el changelog. Validada la cobertura implícita del catálogo de skills.
  - Archivos actualizados: `plans/changelog.md`, `plans/architecture.md`

## [2026-04-27] — Auditoría Dinámica y Configuración Universal

### 🔵 Features / Architecture
- **AUDIT-009**: Implementación de **Configuración de Auditoría Dinámica**. El sistema ahora permite definir metadatos por campo (Exacto, Semántico, Negocio) y severidades (ALTA, MEDIA, BAJA) persistidos en base de datos.
- **AUDIT-010**: Rediseño de la UI de configuración (`AuditConfigEditor`) para soportar la edición de nuevos tipos de campos y severidades dinámicas.
- **AUDIT-011**: Soporte para tipos de campo "S" (Semántico) y "B" (Negocio) en el pipeline de auditoría, permitiendo validaciones contextuales avanzadas vía Gemini.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada la documentación de endpoints y las skills de API y Auditoría Gemini para reflejar el nuevo modelo de datos dinámico.
  - Archivos actualizados: `plans/changelog.md`, `plans/api-endpoints.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-24] — Corrección Interfaz MCP (GetInvoices)

### 🔴 Critical Fixes
- **AUDIT-008**: Se solucionó un desajuste de parámetros en la tool `GetInvoices` (`app/wrap/core/tools/GetInvoices.php`). La interfaz MCP recibe el parámetro `date`, pero el cliente HTTP local no lo parseaba a `dateFrom` como lo espera `InvoicesController::index()`, resultando en validaciones HTTP 422 permanentes (bloqueando a los agentes IA de obtener facturas).

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Validada la skill `audfact-mcp-wrap`. No requiere cambios ya que el contrato externo MCP se mantuvo estricto, sólo cambió el mapeo interno.
  - Archivos actualizados: `plans/changelog.md`


## [2026-03-24] — Exclusión de RegimenPaciente en Fuente de Verdad (Auditoría IA)

### 🟢 Quality of Life / Business Logic
- **AUDIT-007**: Se modificó la consulta en `DispensationModel` para excluir el campo `RegimenPaciente` y forzar su valor a `NULL` para clientes específicos que no lo reportan consistentemente (NitSec `1045` Positiva, `80455` Suramericana, `2426` Colsanitas).
  - Esto activa la "Regla Absoluta de Régimen" del `AuditPromptBuilder` (fallback a `N/D`), eliminando falsos positivos en discrepancias donde el régimen de los documentos no coincide con la BD.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada la skill `audfact-audit-gemini` para documentar la regla explícita de exclusión para clientes particulares en conjunto con la regla de fallback del prompt.
  - Archivos actualizados: `plans/changelog.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-24] — Implementación de Regla de Entregas Parciales (Audit Prompt)

### 🟢 Quality of Life / Business Logic
- **AUDIT-006**: Implementada la regla de **entregas parciales** en `AuditPromptBuilder`. Gemini ahora permite que la cantidad en la Fuente de Verdad sea menor o igual a lo prescrito/autorizado sin reportar discrepancias. Solo se marca como `VALOR_DISTINTO` si el entregado excede el autorizado.
  - Modificado §03 para excluir cantidades de comparación exacta.
  - Agregada sub-regla en §05 con lógica de validación dirigida.
  - Actualizado §08 (Auto-auditoría) para forzar verificación de parciales.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada la documentación en `plans/features/audit-workflow.md` y la skill `audfact-audit-gemini` para reflejar la nueva capacidad de auditoría cuantitativa.
  - Archivos actualizados: `plans/changelog.md`, `plans/features/audit-workflow.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-20] — Robustecimiento de Transacciones, Parseo JSON y Resiliencia Redis (Pipeline Audit)

### 🔴 Critical Fixes
- **AUDIT-005 / C-01**: Inconsistencia transaccional en `AuditPersistenceService` → Ahora envuelve `upsertAuditResult` y actualización de adjuntos en una transacción PDO; si falla, revierte todo para mantener integridad y pospone la actualización en la caché de Redis (`lrem`).
- **AUDIT-005 / C-02**: Respuestas JSON de Gemini truncadas, malformadas o con llaves sin cerrar → Integrado `JsonRepairHelper` como fallback en `JsonResponseParser` para reparar comas sueltas, strings incompletos y corchetes desbalanceados antes de fallar.

### 🟠 High Priority Fixes
- **AUDIT-005 / H-01**: Pérdida silenciosa de scripts Lua (`NOSCRIPT`) por reinicios de servidor Redis en Workers → Agregado try/catch en `AuditQueueService::updateJob()` para atrapar el error `NOSCRIPT` y reintentar instantáneamente recargando y ejecutando el script en crudo con `EVAL`.

### Refactor (Testing)
- **TEST-001**: 100% de la suite de pruebas unitarias sincronizada con los cambios operacionales. El servicio de persistencia implementa ahora Mocks de PDO con Reflexión para verificar commits/rollbacks sin necesitar DB viva.
- **TEST-002**: Solución de colisiones de namespace (`FakeInvoicesModel`) entre Tests.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Actualizada skill `audfact-audit-gemini` (incorporando la sección Resiliencia vs Errores Formato y el uso del Helper).
  - Archivos actualizados: `plans/changelog.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

### Archivos modificados
`app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditQueueService.php`, `app/Services/Audit/JsonResponseParser.php`, `app/Services/Audit/JsonRepairHelper.php` (nuevo), `tests/Services/Audit/*`, `tests/Controllers/InvoicesControllerTest.php`, `tests/Models/InvoicesModelTest.php`

## [2026-03-19] — Correcciones Persistencia e Idempotencia (Audit)
- **AUDIT-004 / C-01**: Corrupción de datos por truncado en Caché → `AuditPersistenceService` guarda `severity`, `_errorOrigin` y metadata completa.
- **AUDIT-004 / C-02**: Mapeo inválido de PK al re-persistir desde Caché → `AuditController::run` reconstruido para forzar `FacNro` genuino.
- **AUDIT-004 / Idempotencia**: Controlador usaba prefijo quemado (`audit:result:`) → sincronizado con `REDIS_PREFIX` de Env.

### 🟠 High Priority Fixes
- **AUDIT-004 / H-01**: DB Fallback sin validación estricta → `AuditStatusModel` devuelve int/false; el caching se aborta ante falla.

### 🟡 Medium / Low Priority
- **AUDIT-004 / M-02 / L-02**: Pre-validaciones abortaban sin array pre-formateado → inyección de `$items` de fallos documentales y MIPRES a `fail()`.

### Archivos modificados
`app/Services/Audit/AuditPersistenceService.php`, `app/Controllers/AuditController.php`, `app/Services/Audit/AuditPreValidator.php`, `app/Models/AuditStatusModel.php`

## [2026-03-18] — Correcciones Auditoría Independiente (19 hallazgos)

### 🔴 Critical Fixes
- **AUDIT-003 / C-01**: SQL Injection en `$limit` de `InvoicesModel` → cast `(int)` defensivo
- **AUDIT-003 / C-03**: `Response::success()`/`error()` lanzaban excepciones sin documentar → `#[NoReturn]` + `@return never`
- **AUDIT-003 / C-04**: Comparación de fechas con operadores string → `DateTime` objects (4 sitios en InvoicesController + AuditController)
- **AUDIT-003 / C-05**: Fecha asimétrica en subquery de `InvoicesModel` → condición simétrica con igualdad
- **AUDIT-003 / C-06**: `set_time_limit(120)` en `AuditOrchestrator` anulaba timeout del controller → eliminado

### 🟠 High Priority Fixes
- **AUDIT-003 / H-01**: Regla `optional` en `Validator` funcionaba por accidente → implementación explícita
- **AUDIT-003 / H-02**: Regla `min_length:` ignorada silenciosamente → implementada en `Validator`
- **AUDIT-003 / H-03**: Cache key en `AuditController::results()` no invalidable → prefijo `facNitSec`
- **AUDIT-003 / H-04**: `count($attempts)` como código de excepción (daba 2) → HTTP 500 con attempts en mensaje
- **AUDIT-003 / H-05**: Sin sanitización post-validación en `Controller` → `sanitizeData()` con `trim()` + `strip_tags()`

### 🟡 Medium Priority Fixes
- **AUDIT-003 / M-01**: `GROUP BY` 20+ columnas sin agregación en `DispensationModel` → `SELECT DISTINCT`
- **AUDIT-003 / M-03**: Rate limiting con `REMOTE_ADDR` (IP del proxy Docker) → `RateLimit::getClientIp()` proxy-aware
- **AUDIT-003 / M-04**: Uso dual de `DisDetNro` en `AuditController::single()` → documentado con comentario
- **AUDIT-003 / M-05**: PK hardcodeada `id` en `Model` base → `$primaryKey` configurable

### 🔵 Low Priority Fixes
- **AUDIT-003 / L-01**: Fuga de `facNitSec` en logs de `InvoicesModel` → enmascaramiento `***` + últimos 3 dígitos
- **AUDIT-003 / L-02**: SQL completo en logs de error de `Database` → `[REDACTED]`
- **AUDIT-003 / L-03**: Regex de `Router` no aceptaba puntos en parámetros → `[\w.\-]+`
- **AUDIT-003 / L-04**: `declare(strict_types=1)` añadido en `Database`, `Validator`, `RateLimit`

### Descartado
- **C-02 (Autenticación API)**: Postergado a sprint futuro por decisión del usuario

### Archivos modificados (13)
`app/Models/InvoicesModel.php`, `app/Models/DispensationModel.php`, `app/Models/Model.php`, `core/Database.php`, `core/Validator.php`, `app/Controllers/Controller.php`, `app/Controllers/InvoicesController.php`, `app/Controllers/AuditController.php`, `app/Services/Audit/AuditOrchestrator.php`, `core/Response.php`, `core/Router.php`, `core/RateLimit.php`, `public/index.php`

## [2026-03-18] — Fix Inyección Exhaustiva de Medicamentos (Auditoría IA)

### Fix (Prompt)
- **Iteración Multi-Medicamento**: `AuditPromptBuilder` itera sobre todos los ítems de `$dispensationData` generando nodos `<medication item="N">` XML individuales, asegurando que la IA valide todos los medicamentos de una dispensación multi-línea.
- **Entregas Parciales (v3.2)**: El sistema permite que la Fuente de Verdad registre cantidades menores o iguales a las prescritas/autorizadas, clasificándolas como `COINCIDE` para evitar falsos positivos en dispensaciones fragmentadas.
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`
  - Prompt v3.2: 4 capas con axiomas deterministas, motor de 6 dimensiones, protocolo de reconfirmación anti-alucinación, e **iteración multi-medicamento**. Incluye regla de **entregas parciales** (FdV ≤ Doc OK).

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Actualizada skill `audfact-audit-gemini` (v3.0→v3.1 con iteración multi-medicamento). Corregido drift significativo acumulado en `plans/features/audit-workflow.md`: tabla de archivos obsoleta (`GeminiAuditService` → `AuditOrchestrator`), endpoints faltantes (async, jobStatus, results, documents-history), parámetro `FacNro`→`DisDetNro`, versión de prompt (v6.0→v3.1), sección multi-línea→multi-medicamento con XML iterado, y notas técnicas sobre filtrado de adjuntos.
  - Archivos actualizados: `.agent/skills/audfact-audit-gemini/SKILL.md`, `plans/features/audit-workflow.md`

### Refactor (Post-Audit Quality)
- **AUDIT-002**: Correcciones robustas post-auditoría independiente (6 hallazgos):
  - **H-01**: §08.7 restaurado con guard rail concreto (`{$totalLineas}` ítems + verificación individual)
  - **M-01**: Supuesto de metadatos comunes (`$ref = $dispensationData[0]`) documentado
  - **M-02**: `FirmaActaEntrega` hardcodeada como 'Obligatorio' documentada como decisión de negocio
  - **M-03**: Nodos `<medication>` envueltos en tag contenedor `<medications total="N">`
  - **L-01**: Helper `isMultiItem()` extraído (DRY, 4 instancias reemplazadas)
  - **L-02**: DocBlock actualizado `@version 2.1` → `@version 3.1`
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`, `app/Models/DispensationModel.php`

## [2026-03-18] — Correcciones CI/CD Pipeline (14 hallazgos)

### 🔴 Critical Fixes
- **CICD-001**: Deploy separado build de restart — build failure ya no causa downtime
  - `docker compose build` (containers siguen corriendo) → `docker compose up -d --force-recreate`
  - Archivos: `.github/workflows/ci.yml`
- **CICD-002**: Composer installer reemplazado por `COPY --from=composer:2` (supply chain safe)
  - Archivos: `docker/Dockerfile`
- **CICD-003**: Agregado `permissions: contents: read` a ambos workflows (least privilege)
  - Archivos: `ci.yml`, `deploy-frontend.yml`

### 🟠 High Priority Fixes
- **CICD-004**: `timeout-minutes` agregado a 4 jobs (15min lint, 30min deploy)
- **CICD-005**: Eliminado `echo` de `NEXT_PUBLIC_API_URL` en logs del workflow
- **CICD-006**: `.env` en contenedor cambiado de `chmod 644` a `chmod 640`
  - Archivos: `docker/docker-entrypoint.sh`
- **CICD-007**: Redis `--requirepass` agregado con default `audfact_dev_default`
  - Archivos: `docker-compose.yml`, `ci.yml` (.env generation)

### 🟡 Medium Priority Fixes
- **CICD-008**: TODO comment para pin de `shivammathur/setup-php` a SHA
- **CICD-010**: Secret scan cambiado de `::warning::` a `exit 1` (blocking)

### 🔵 Low Priority
- **CICD-013**: Warning comment en `docker-compose.ha.yml` sobre source mount
- **CICD-014**: Zero-source purge agregado a `deploy-frontend.yml`

### No aplica
- **CICD-011**: Limitación intencional de Next.js (API URL baked at build)
- **CICD-012**: Falso positivo — YAML `|` strip indentation correctamente

## [2026-03-18] — Correcciones Auditoría Independiente (5 hallazgos)

### Breaking Change
- **ARCH-001**: `POST /audit/single` — Parámetro renombrado de `FacNro` a `DisDetNro` para reflejar semántica real
  - Archivos modificados: `app/Controllers/AuditController.php`, `AGENTS.md`

### Fix
- **QUAL-001**: Test `AuditPersistenceServiceTest` usaba campo `hallazgo` (inexistente en schema Gemini) en vez de `detalle`
  - Archivos modificados: `tests/Services/Audit/AuditPersistenceServiceTest.php`
- **SEC-004**: `Logger::write()` sanitizaba contexto ANTES de serializar excepciones, dejando `trace` sin redactar
  - Archivos modificados: `core/Logger.php`
- **QUAL-002**: `saveToDatabase()` silenciaba errores de persistencia (void). Ahora retorna `bool`, Orchestrator loguea fallos
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditOrchestrator.php`
- **DOC-001**: README.md decía "Rate limiting por IP (archivo)" en vez de "(APCu con fallback a archivo)"
  - Archivos modificados: `README.md`

### Diferido
- SEC-001, SEC-002, SEC-003: Diferidos a sprint futuro por decisión del usuario
- GOV-001: Cobertura de tests — registrado como TODO

## [2026-03-17] — Auditoría Independiente Fase 3 (Correcciones)

### Fix (Async Queue — 3 Críticos + 4 Altos/Medios)
- **C01**: `POST /audit/async` retornaba HTTP 200 en vez de 202. `Response::success()` ahora recibe `code=202`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **C02**: Redis `allkeys-lru` podía evictar metadata de jobs activos. Cambiado a `volatile-lru`
  - Archivos modificados: `docker-compose.yml`
- **C03**: `read_write_timeout=2s` < `brpop timeout=5s` causaba crash del worker en cada iteración
  - Archivos modificados: `core/RedisClient.php`
- **A01**: Worker no verificaba idempotencia antes de re-auditar facturas. Agregado `getIdempotentResult()`
  - Archivos modificados: `bin/audit-worker.php`
- **A02**: Shutdown parcial marcaba job como COMPLETED. Agregado estado `STATUS_INTERRUPTED`
  - Archivos modificados: `bin/audit-worker.php`, `app/Services/Audit/AuditQueueService.php`
- **M03**: Eliminados `return` muertos después de `Response::error()`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **M04**: `buildOrchestrator()` se reconstruía por cada job. Ahora usa lazy-init reutilizable
  - Archivos modificados: `bin/audit-worker.php`
- **A03**: `buildOrchestrator()` duplicada entre controller y worker. Creada `AuditOrchestratorFactory`
  - Archivos creados: `app/Services/Audit/AuditOrchestratorFactory.php`
  - Archivos modificados: `app/Controllers/AuditController.php`, `bin/audit-worker.php`
- **M01**: `updateJob()` no era atómico (GET+SET). Ahora usa script Lua Redis con fallback
  - Archivos modificados: `app/Services/Audit/AuditQueueService.php`, `core/RedisClient.php`
- **M02**: Índice SQL referenciaba tabla inexistente `AdjuntosDispensacionDetalle`. Corregido a `AdjuntosDispensacion`
  - Archivos modificados: `database/migrations/optimize_audit_indexes.sql`
- **B01**: Validación `jobId` hardcodeada a 32 chars. Ahora regex flexible `[a-f0-9]{32,64}`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **B02**: Log de `$data` en `async()` exponía `facNitSec`. Sanitizado a `***` + 3 últimos dígitos
  - Archivos modificados: `app/Controllers/AuditController.php`
- **C-NEW-02**: El worker también logueaba `params` exponiendo `facNitSec` en cleartext. Sanitizado con enmascaramiento.
  - Archivos modificados: `bin/audit-worker.php`

### Fix (Auditoría v2 — 2 Medios + 2 Bajos)
- **M-NEW-01**: `run()` y `single()` logueaban `json_encode($data)` y `facNitSec` en cleartext. Sanitizado con enmascaramiento `***`+3 últimos dígitos, alineado con `async()`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **M-NEW-02**: `queueDepth()` retornaba `0` por error Redis (indistinguible de "cola vacía"). Ahora retorna `null` si Redis no disponible
  - Archivos modificados: `app/Services/Audit/AuditQueueService.php`
- **B-NEW-01**: `AuditOrchestratorFactory` no validaba formato de `GEMINI_MODEL`. Agregada validación que verifica `gemini` + segmentos con guión
  - Archivos modificados: `app/Services/Audit/AuditOrchestratorFactory.php`
- **B-NEW-02**: Worker `$auditor` no se reseteaba tras `Throwable` irrecuperable. Agregado `$auditor = null` en catch para forzar re-creación limpia
  - Archivos modificados: `bin/audit-worker.php`

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Actualizado `AGENTS.md` con 3 endpoints faltantes (`/audit/async`, `/audit/jobs/{jobId}`, `/audit/documents-history`), secciones Redis y Auditoría Async en catálogo de env vars, variable `GEMINI_SEED`, y nota expandida de sanitización de logs
  - Archivos modificados: `AGENTS.md`
  - Verificado: `CATALOG.md`, `architecture.md`, `api-endpoints.md`, `README.md`, skills `audfact-audit-gemini` y `audfact-security-guardrails` — ya al día

## [2026-03-17]

### Feature (Escalabilidad Async)
- **Ámbito**: Sistema asíncrono de colas para auditoría IA (Fase 3)
  - Archivos modificados: `core/RedisClient.php`, `app/Services/Audit/AuditQueueService.php`, `bin/audit-worker.php`, `app/Controllers/AuditController.php`, `app/Routes/web.php`, `database/migrations/optimize_audit_indexes.sql`
  - Detalles: Se implementaron colas utilizando listas de Redis (`lpush`, `brpop`, `llen`). El nuevo modelo permite encolar la auditoría desde un backend y procesar hasta de forma concurrente desde el Worker CLI de PHP evitando el time-out HTTP al orquestar con Gemini.
  - Hito: Sincronización de skills P3 (Colas y Rate Limiting)


### Feature (Pipeline IA)
- **Ámbito**: Implementación de Schema Dinámico para Gemini
  - Archivos modificados: `AuditResponseSchema.php`, `GeminiGateway.php`, `AuditOrchestrator.php`, `AuditPromptBuilder.php`
  - Detalles: El pipeline de auditoría ahora extrae dinámicamente los nombres de los documentos (ej. `DISPENSA`, `FORMULA MEDICA`) directamente de la base de datos `AdjuntosDispensacion` y los inyecta en el JSON Schema de Gemini. Esto fuerza a la IA a responder con nomenclatura 100% idéntica a la BD, eliminando los fallos de conciliación en el modelo `AuditStatusModel` por el uso de nomenclatura SNAKE_CASE impuesta previamente.
  - Hito: Sincronización de skills P2.5 (Schema Dinámico).

## [2026-03-10]

### Rediseño Visual Premium (Dashboard)
- **UI/UX Holística**: Se implementó un rediseño visual completo basado en referentes de alta gama (Falcon, Label, Corona).
- **Tema Deep Navy**: Paleta de colores profesional (`oklch 0.11`) para reducir fatiga visual y mejorar contraste.
- **Micro-interacciones**: Se agregaron efectos de "glow border", elevación de tarjetas en hover y animaciones de entrada (`scale-in`, `shimmer`).
- **Nuevos Componentes**: KPI Cards rediseñadas con gradientes duales, Dashboard Header con badges de status, y Charts con tooltips de alta fidelidad.
- **Tipografía**: Implementación de Inter (Display) y Outfit para una estética moderna.

### Optimizaciones Docker & Infra
- **Fix Standalone Build**: Se habilitó `output: 'standalone'` en `next.config.ts` para permitir la creación correcta de imágenes Docker optimizadas.
- **Workflow de Rebuild**: Documentado el proceso de reconstrucción para el frontend desacoplado.

### Fixes & Bug Fixes
- **KPI Alertas (Dashboard)**: Se corrigió la lógica de `EstAud` en backend para que marque registros procesados con errores o advertencias. Se robusteció el mapeo de estados en frontend.
- **React Hydration Mismatch (#418)**: Se eliminó el error diferiendo la renderización de fechas (`new Date()`) en `DashboardHeader` hasta la etapa del cliente mediante `useEffect`.
- **Navegación 404 (/settings)**: Se agregó la página "Configuración (En Construcción)" para resolver rutas inexistentes de los menús laterales y superior.

## [2026-03-07]

### Migración Frontend a Next.js
- **Migración a SPA**: Se migró la interfaz originalmente servida como HTML renderizados estáticamente desde PHP a una **Arquitectura Desacoplada** con Next.js (App Router).
- **Stack Frontend**: React 19, TypeScript, Tailwind CSS v4, shadcn/ui, eCharts, Lucide Icons, Zustand y React Query (TanStack).
- **Consumo de APIs**: Se creó un cliente `api.ts` estándar y seguro para interactuar con la API PHP existente, unificando los tipos e interfaces.


### Optimización de Estándares (Skills)
- **Alineación de Endpoints**: Se formalizó el "Patrón de Endpoint Estándar" en la skill `audfact-api-rest`. Ahora todos los controladores deben usar `validateQuery` para capturar filtros y devolver respuestas con metadatos de paginación y el objeto `filters` (echo).
- **Consumo de Datos en Modelos**: Se formalizó el "Patrón de Consumo de Datos y Filtrado" en la skill `audfact-sqlsrv-models`. Los modelos ahora deben aceptar un array `$filters` inyectado desde el controlador para construir cláusulas `WHERE` dinámicas de manera consistente.
- **Workflow de Generación**: Se creó el archivo `.agent/workflows/generate-endpoint.md` para guiar a los agentes en la creación de nuevos endpoints siguiendo estos estándares.
- **Impacto**: Reducción de la deuda técnica y garantía de una API predecible y uniforme para el frontend.

## 2026-03-09
- Fix: Implementado deep-linking en tablas de auditoría (Dashboard) inyectando estado inicial vía `useSearchParams` hacia las páginas `audit/history` y `audit/single`. Se eliminó la dependencia exclusiva de hooks de efecto para hidratar variables del URL.

## 2026-03-08
- Fix: Corregido el mapeo de parámetros (FacSec a NumeroFactura) en la Auditoría 1:1.
- Fix: Resuelto el renderizado vacío del modal de resultados de Auditoría 1:1 en la UI gestionando correctamente la envoltura data.data del backend y el estado de error de la IA.
