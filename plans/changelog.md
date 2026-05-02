# Changelog AudFact

## [2026-05-02] — Clean Code Pipeline: Enums Centralizadores (AUDIT-020)

### 🔵 Architecture / Refactor
- **AUDIT-020**: Eliminación de constantes duplicadas y métodos redundantes en el pipeline de auditoría. Sin cambios en API pública, contratos de eventos ni respuestas REST.
  - **Nuevo enum** `DocumentQuality` (`legible/parcialmente_legible/ilegible`) reemplaza la constante privada `DOCUMENT_QUALITY_ENUM` que existía duplicada en `DocumentExtractionWorker`, `DocumentNormalizer` y `DocumentPolicyEngine`. Incluye `fromString()` (con validación), `tryFromString()`, `isLegible()` y `preventsConclusion()`.
  - **Nuevo enum** `AuditFindingResult` (`COINCIDE/VALOR_DISTINTO/NO_ENCONTRADO/OMITIDO/NO_CONCLUYENTE`) reemplaza las constantes privadas `RESULT_*` que existían duplicadas en `DocumentPolicyEngine` (5), `RulesEvaluationWorker` (3) y `AuditFindingRules` (3). Incluye `isFailure()`, `isDiscrepancy()`, `isInconclusive()`, `isSkipped()`.
  - **`AuditFindingRules`**: eliminadas constantes `RESULT_*` y listas `FAILURE_RESULTS`/`DISCREPANCY_RESULTS` → delegación a `AuditFindingResult`. Agregados helpers estáticos compartidos: `normalizeNullableString()` y `normalizeToken()`.
  - **`DocumentPolicyEngine`**: eliminadas 5 constantes `RESULT_*`, `DOCUMENT_QUALITY_ENUM`, `normalizeNullableString()` privado, `normalizeIdentityDocumentTypeToken()` privado (duplicado de `normalizeToken()` de `DocumentNormalizer`), y parámetro muerto `$documentType` de `evaluateField()`.
  - **`DocumentNormalizer`**: eliminadas `DOCUMENT_QUALITY_ENUM`, `normalizeNullableString()` privado, `normalizeToken()` privado → delegan a `AuditFindingRules`.
  - **`DocumentExtractionWorker`**: eliminada `DOCUMENT_QUALITY_ENUM` → `DocumentQuality::fromString()`.
  - **`RulesEvaluationWorker`**: eliminadas 3 constantes `RESULT_*` → `AuditFindingResult::*->value`.
  - **Resultado**: 15 definiciones duplicadas eliminadas (5 constantes × 3 clases). Todos los valores de string son idénticos al contrato anterior — backward compatibility total.
  - **Validación**: 88/88 tests, 330 assertions, 0 regresiones, sin modificación de tests.
  - **Archivos creados**: `app/Services/Audit/DocumentQuality.php`, `app/Services/Audit/AuditFindingResult.php`
  - **Archivos modificados**: `AuditFindingRules.php`, `DocumentPolicyEngine.php`, `DocumentNormalizer.php`, `DocumentExtractionWorker.php`, `RulesEvaluationWorker.php`

## [2026-05-02] — Formalización de Tipos de Valor Auditables (AuditFieldValueType)

### 🔵 Architecture / Refactor
- **AUDIT-019**: Separación formal de "tipo de comparación" (`AuditComparisonType`: E/S/B/V) y "tipo de dato" (`AuditFieldValueType`: text/date/quantity/money/identity_doc_type).
  - **Nuevo enum** `AuditFieldValueType` con factory `fromFieldName()` que consolida 4 heurísticas dispersas (`str_starts_with('Fecha')`, `str_starts_with('Cantidad')`, `str_starts_with('Vlr')`, `in_array(['TipoDocumentoPaciente', 'TipoDocumentoMedico'])`) en un único punto de decisión.
  - **Métodos auxiliares**: `isNumericForSchema()` (reemplaza `isNumberField()`), `isQuantitySummable()` (reemplaza `isQuantityField()` en resolución de valores).
  - **DocumentPolicyEngine**: `normalizeForComparison()` refactorizado de cascada if/else a `match` expression. Método privado `isIdentityDocumentTypeField()` eliminado. `resolveDocumentValue()`, `resolveSourceTruthValue()` y `evaluateBusinessField()` usan `AuditFieldValueType` directamente.
  - **DocumentExtractionContractBuilder**: `schemaTypeForField()` delega a `isNumericForSchema()`.
  - **AuditComparisonType**: `isDateField()`, `isQuantityField()`, `isNumberField()` marcados `@deprecated` como puentes que delegan a `AuditFieldValueType` — backward compatibility total para `DocumentNormalizer`.
  - **Resultado**: Refactoring puramente interno. API pública, contratos de eventos, respuestas REST y hallazgos persistidos no cambian.
  - **Validación**: 88/88 tests, 330 assertions, 0 regresiones, sin modificación de tests.
  - **Archivos creados**: `app/Services/Audit/AuditFieldValueType.php`
  - **Archivos modificados**: `AuditComparisonType.php`, `DocumentPolicyEngine.php`, `DocumentExtractionContractBuilder.php`

### 📚 Documentation / Skills
- **DOCS-SYNC**: Skill `audfact-audit-gemini` actualizada con `AuditFieldValueType` en tabla de archivos clave, regla 2 y referencias.

## [2026-05-01] — Limpieza Dead Code y Wrappers Redundantes (Pipeline)

### 🧹 Cleanup / Refactor
- **QUAL-001**: Eliminado `TYPE_EXTRACTION_FAILED` — constante declarada y ruteada sin productor ni consumer en todo el codebase.
  - Archivos modificados: `AuditEvent.php`, `AuditEventPublisher.php`
- **QUAL-002**: Limpieza de referencias fantasma a clases eliminadas en refactors AUDIT-013/014.
  - `FieldClassifier` eliminado de `AuditSeverity.php` (docblock) y SKILL.md (tabla de archivos)
  - `DocumentNormalizationWorker` → `DocumentNormalizer` en AGENTS.md y SKILL.md
  - `AuditResultAggregator` → `AuditAggregationWorker` en AGENTS.md
  - `InternalAuditApiClient` → `AuditDataService` + `AttachmentDownloadService` en `plans/architecture.md` y SKILL.md
  - `extraction_failed` eliminado de tabla de streams en SKILL.md
  - `FieldClassifier` agregado al banner de obsolescencia de `audit-workflow.md`
- **Wrappers eliminados en `DocumentPolicyEngine`**:
  - `shouldSkipByCondition()` — wrapper de 1 línea que delegaba a `AuditFindingRules::shouldSkipByCondition()`. Docblock migrado al método fuente de verdad.
  - `normalizeVisualSeverity()` — duplicaba `AuditSeverity::fromInput()->value`. Reemplazado por llamada directa al enum.
  - Archivos modificados: `DocumentPolicyEngine.php`, `AuditFindingRules.php`
- **Deuda documentada (fuera de scope)**: `normalizeDocumentType()` en PolicyEngine (semántica `iconv` vs `strtr` requiere tests), parámetro muerto `$documentType` en `evaluateField()`.

## [2026-04-28] — Docs Sync: Pipeline event-driven & TipoCampo

### 📚 Documentation / Skills
- **DOCS-SYNC-002**: Sincronización tras detectar drift acumulado contra refactors AUDIT-013/014/015/016 y validación contra el caso golden `T38250701547` (NitSec 2426).
  - **Skill `audfact-audit-gemini`**: bootstrap unificado a `bin/audit-worker.php <rol>` (era lista de 5 binarios consolidados en AUDIT-015), eliminadas filas de archivos fusionados (`DocumentNormalizationWorker`, `AuditResultAggregator`, `ExtractionCache`, `SchemaBuilder` — AUDIT-014), corregido naming TipoCampo (el enum `AuditComparisonType::fromTipoCampo()` mapea `E` como default → `EXACT`; el código `D` no existe), eliminada regla "factor de empaque NitSec=2426 ≤ 5 unidades / `ACEPTADO_POR_EMPAQUE`" (no implementada en código), agregadas secciones para mecanismo `omitirSi` (`fdv_has`/`fdv_missing`/`doc_quality`), agregación de items en reglas `B` (sumatoria pre-comparación) y contrato real de hallazgo, nota técnica sobre thinking tokens en Gemini 3.x, removida referencia al `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` eliminado en AUDIT-016.
  - **Skill `audfact-project-overview`**: reemplazado el flujo monolítico (`AuditOrchestrator.auditInvoice` + `EmbeddingGateway` + `RuleEngine` + `AuditPersistenceService`) por el flujo event-driven actual (orchestrator → extraction → normalizer → policy → aggregator); conteos actualizados (8→11 controllers, 6→7 models, 11→22 archivos en `Services/Audit`); endpoints 17→22 con `audit-config`, DLQ y timings; patrones actualizados (Template Method, Lua scripts, Builder dinámico).
  - **`CATALOG.md`**: eliminada fila `app/Services/Audit/AuditOrchestrator.php` (no existe), agregada `Pipeline/DocumentAuditOrchestrator.php` y wildcard `Pipeline/*.php`; descripción y triggers de `audfact-audit-gemini` actualizados.
  - **`AGENTS.md`**: corregido namespace `Events/ → Pipeline/` en archivos críticos del pipeline; reemplazada referencia a `AuditPromptBuilder.php` (eliminado) por construcción dinámica del schema/prompts en `DocumentAuditOrchestrator` y `DocumentExtractionWorker`.
  - **TODO de negocio**: la regla "factor de empaque ≤ 5 unidades para NitSec=2426 con warning `ACEPTADO_POR_EMPAQUE`" se eliminó de la skill por no estar implementada (0 hits en código). Si el negocio aún la requiere, debe registrarse como nuevo ticket de implementación (puede vivir en `DocumentPolicyEngine` o como `omitirSi` en el `audit-config` de 2426).
  - **Drift residual fuera de alcance**: la carpeta `tests/Services/Audit/Events/` no fue renombrada a `Pipeline/` cuando el código de producción se renombró (AUDIT-013). Pendiente como tarea separada de testing.

## [2026-04-28] — Docs Sync: Perfiles Gemini y Fallback Semántico

### 📚 Documentation / Skills
- **DOCS-SYNC**: Sincronización documental posterior a la corrección del pipeline Gemini.
  - **Skills actualizadas**: `audfact-audit-gemini` documenta `GeminiConfig`, `SemanticMatchJudge`, métricas Gemini por tarea, perfiles `GEMINI_EXTRACTION_*` / `GEMINI_SEMANTIC_*`, fallback limpio y no-cache de fallos transitorios.
  - **Runtime actualizado**: `audfact-runtime-docker` documenta que PHP/workers usan código baked en imagen y requieren rebuild/recreate tras cambios de backend.
  - **Documentación humana verificada**: `AGENTS.md` ya contiene el catálogo de variables Gemini por tarea; `CHANGELOG.md` ya registra el cambio user-facing.
  - **Validación base**: Golden Case `T38250701547` mantiene `manual_review`, 34 coincidencias, 1 discrepancia y 1 no concluyente; la respuesta ya no persiste errores técnicos de Gemini.

## [2026-04-28] — Optimización de Performance: Pro-Parallel (82s → 34s)

### ⚡ Performance / Infrastructure
- **AUDIT-018**: Optimización masiva de latencia en el pipeline de auditoría sin pérdida de calidad.
  - **Paralelismo**: Escalado de `worker-extraction` de 1 a **5 réplicas** en `docker-compose.yml`. Esto permite que los adjuntos de una factura (promedio 3) se procesen simultáneamente en lugar de secuencialmente.
  - **Configuración Pro-Optimized**: Uso de `gemini-3.1-pro-preview` con `GEMINI_MEDIA_RESOLUTION=MEDIA_RESOLUTION_LOW`. La reducción de resolución acelera el procesamiento de la API de Gemini sin degradar la precisión en campos críticos (CIE-10, firmas).
  - **Resultado**: Reducción del tiempo total de auditoría de **82 segundos a 34 segundos** (mejora del 58%) para una factura estándar de 3 documentos escaneados.
  - **Archivos modificados**: `docker-compose.yml`, `.env`, `.env.example`, `app/Services/Audit/GeminiConfig.php`.

## [2026-04-27] — Limpieza de artefactos muertos del repositorio

### 🧹 Cleanup
- **AUDIT-016**: Eliminación de documentación obsoleta, variables fantasma y archivos dead del repositorio.
  - **Archivos raíz eliminados**: `ASSESSMENT_AudFact_AuditPipeline_v1.0.md` (66KB), `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` (67KB), `REPRODUCIBILITY_FRAMEWORK.md` (7KB), `CHANGELOG.md` (duplicado de `plans/changelog.md`), `.env.dev` (sin consumidor).
  - **Directorio eliminado**: `tmp/` (3 JPGs de prueba manual), `app/Services/prompts/` (5 archivos de prompts legacy: v1-v4 + philosophy).
  - **Variables fantasma eliminadas**: `GEMINI_THINKING_LEVEL` (sin consumidor PHP), `GEMINI_EMBEDDING_MODEL` (nunca implementado), `SEMANTIC_THRESHOLD_DEFAULT` (hardcoded en `AuditComparisonType`), `AUDIT_FDV_TTL` (sin consumidor).
  - **Variables sincronizadas**: `AUDIT_VERSION_EXTRACTOR`, `AUDIT_VERSION_NORMALIZER`, `AUDIT_VERSION_RULES` agregadas a `.env` (faltaban, son consumidas por `AuditEvent.php`).
  - **Resultado neto**: −8 archivos raíz, −8 archivos en subdirectorios, −4 variables fantasma, +3 variables sincronizadas.

## [2026-04-27] — Consolidación de Bootstrap Scripts (`bin/`)

### 🔵 Architecture / Refactor
- **AUDIT-017**: Implementación de extracción selectiva para documentos prescriptivos (FORMULA MEDICA, RECETA, etc.). El `DocumentExtractionWorker` ahora inyecta en el prompt de Gemini la lista de artículos efectivamente dispensados (según la FDV), limitando la extracción a ítems relevantes y reduciendo el ruido/consumo de tokens en >90% (ej. 2 ítems extraídos en lugar de 21).
- **AUDIT-015**: Consolidación de los scripts ejecutables de los workers en un único launcher.
  - **Fusión `bin/audit-*-worker.php` → `bin/audit-worker.php`**: Se eliminaron 5 scripts de bootstrap casi idénticos y se reemplazaron por un único launcher que usa un registry de configuración.
  - El nuevo launcher recibe el nombre del worker como primer argumento CLI (ej: `php bin/audit-worker.php orchestrator`).
  - **Resultado neto**: −4 archivos (5→1). Centralización de carga de variables de entorno y manejo de señales POSIX.
  - **Archivos eliminados**: `bin/audit-orchestrator-worker.php`, `bin/audit-extraction-worker.php`, `bin/audit-normalizer-worker.php`, `bin/audit-policy-worker.php`, `bin/audit-aggregator-worker.php`
  - **Archivos añadidos**: `bin/audit-worker.php`
  - **Archivos modificados**: `docker-compose.yml` (actualización de los `command:` de cada servicio).
  - **Validación E2E**: `T38250701547` procesado correctamente con score idéntico (15) tras reconstrucción de contenedores.
## [2026-04-27] — Consolidación Pipeline: 17 → 13 archivos

### 🔵 Architecture / Refactor
- **AUDIT-014**: Consolidación del árbol `app/Services/Audit/Pipeline/` mediante fusión de clases con relación 1:1 exclusiva:
  - **F1: `DocumentNormalizationWorker` → `DocumentNormalizer`**: El thin wrapper (88 líneas) se eliminó. `DocumentNormalizer` ahora extiende `AuditEventConsumer` directamente, actuando como worker autocontenido.
  - **F2: `AuditResultAggregator` → `AuditAggregationWorker`**: Los métodos de agregación (normalización de hallazgos, resolución de status final, severidad) se absorbieron como métodos privados del worker. Único consumidor.
  - **F4: `ExtractionCache` → `DocumentExtractionWorker`**: Los métodos de cache Redis por `document_hash` se absorbieron como métodos privados del worker. Único consumidor.
  - **F5: `SchemaBuilder` → `DocumentAuditOrchestrator`**: La construcción del function declaration Gemini se absorbió en el orchestrator. `normalizeName()` se mantiene público estático.
  - **Descartada**: Fusión de `AuditFindingRules` — utilidad compartida por 3+ clases (PolicyEngine, RulesEvaluationWorker, AggregationWorker).
  - **Resultado neto**: −4 archivos (17→13), −24% de archivos, sin pérdida funcional.
  - **Archivos eliminados**: `DocumentNormalizationWorker.php`, `AuditResultAggregator.php`, `ExtractionCache.php`, `SchemaBuilder.php`
  - **Archivos modificados**: `DocumentNormalizer.php`, `AuditAggregationWorker.php`, `DocumentExtractionWorker.php`, `DocumentAuditOrchestrator.php`, `bin/audit-normalizer-worker.php`
  - **Validación E2E**: `T38250701547` → `risk_score:15`, `coincidencias:34`, `discrepancias:1` (idéntico a pre-refactorización)

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Actualizado `plans/architecture.md` con la estructura consolidada de 13 archivos. Actualizado `plans/changelog.md`.

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
