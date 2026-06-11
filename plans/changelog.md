# Changelog AudFact

## [2026-06-11] - Feature: Payload Estructurado JSON en AdjDisObsRec

### Backend & Persistencia
- **Refactorización de Persistencia de Hallazgos**:
  - `AuditStatusModel.php`: Modificado `normalizeDocumentDecision` para transformar el array `payload` del rechazo en un string JSON (`json_encode($payload, JSON_UNESCAPED_UNICODE)`) en lugar de almacenar texto plano.
- **Flujo de Evaluacion (Domain)**:
  - `DocumentPolicyEngine.php`: El engine ahora inyecta dinámicamente `codigoCampo` en los resultados y agrupa los rechazos en la llave `payload` (`state`, `Dispensa`, `fechaAuditoria`, `hallazgos`).
  - `RulesEvaluationWorker.php`: Extrae tempranamente `$facNro` del store en Redis y lo propaga a toda la cadena de evaluación (`policyEngine->evaluate()`).
  - `VisualCheckEvaluator.php` / `DeliveryValidityEvaluator.php`: Propagan el `codigoCampo` configurado hacia el hallazgo resultante.
- **Sincronización Documental (DOCS-SYNC)**:
  - Tests ajustados y validados (`RulesEvaluationWorkerTest`) para confirmar aserciones sobre `payload.hallazgos[0].Descripcion` en lugar del campo legacy `observation`.
## [2026-06-11] - Fix: Consistencia de contrato DisId y limpieza documental

### Backend & Documentación
- **Limpieza del Modelo**:
  - `AttachmentsModel.php`: Actualizado `INNER JOIN vw_discolnet_dispensas` para cruzar explícitamente por `DisId` en lugar de la columna heredada `FacSec` en `countAuditHistory` y `getAuditHistory` (QUAL-001).
  - `DispensationModel.php`: Removidas constantes muertas (`WHERE_DIS_DET_NRO`, `WHERE_FAC_SEC`) y actualizados comentarios del contrato de identidad (QUAL-002).
- **Sincronización Documental (DOCS-SYNC)**:
  - `plans/api-endpoints.md`: Reflejado el cambio de payload JSON de `FacSec` a `DisId`.
  - `plans/architecture.md`: Actualizadas referencias arquitectónicas en descripciones de modelos y workers (QUAL-003).
  - `.agent/skills/audfact-sqlsrv-models/references/examples.md`: Reflejado el uso de `DisId` en los ejemplos de código SQL (QUAL-003).

### Bugfixes & Ajustes de Contrato
- **System Prompt E2E**:
  - `AuditConfigController.php`: El campo `systemPrompt` ahora es estrictamente requerido en el payload (puede ser `string` o `null`) para evitar borrados accidentales si el cliente lo omite.
  - `AuditConfigModel.php`: Removido el uso de `COALESCE` en el `MERGE` de `upsertHeader` y forzado el uso de `PDO::PARAM_NULL` para permitir el borrado explícito del prompt del sistema en la base de datos.
  - `audit-config-editor.tsx` (Frontend): Forzada la sincronización del estado local con el remoto vía `setSystemPrompt(config.systemPrompt ?? "")` en el `useEffect` para eliminar la ilusión de guardado.

## [2026-06-10] - Feature: Migración de identidad E2E de FacSec a DisId

### Backend & Frontend
- **Contrato de Identidad Actualizado**:
  - Refactorizado el sistema para usar DisId como llave principal en lugar de FacSec, preservando DisId para búsquedas canónicas e interacciones en UI.
  - Actualizados AuditController, AuditDataService y endpoints HTTP para recibir disId en lugar de FacSec.
  - Refactorizado rontend/lib/schemas/domain.ts, clientes API y componentes del Dashboard (tablas, modales, estado) para manejar la propiedad disId.
  - Actualizado DispensationModel para exponer DisId AS DisId en lugar de acsec AS FacSec.
- **Pruebas (PHPUnit)**:
  - Todas las pruebas actualizadas (AuditControllerTest, DispensationModelTest, DocumentAuditOrchestratorTest, RulesEvaluationWorkerTest, etc.) para verificar la estructura de datos que utiliza DisId de principio a fin.
- **Documentación Pendiente**: Se recomienda al equipo la actualización de la documentación técnica (AGENTS.md, plans/api-endpoints.md, plans/architecture.md, plans/features/audit-workflow.md, plans/audit-identity-contract.md) para sincronizar completamente estas áreas con el nuevo contrato de identidad de DisId.

## [2026-06-09] - Fix: Precision de extraccion documental y limpieza clean rebuild

### IA Pipeline / Audit Config / Clean Rebuild
- **Descripciones configurables en contrato Gemini**:
  - `DocumentExtractionContractBuilder` prioriza la descripcion configurada desde base de datos/catalogo para `valor.description` y conserva la descripcion local solo como fallback.
  - Esto permite que alias operativos del cliente, como `NumeroFactura` tambien visible como acta de entrega, lleguen al schema sin duplicar reglas hardcodeadas.
- **Prompt de extraccion mas compacto**:
  - `DocumentExtractionWorker` elimina del system prompt personalizado las frases ya cubiertas por las descripciones del contrato antes de calcular `prompt_context_hash`.
  - La deduplicacion reconstruye el prompt con frases conservadas, evitando reemplazos parciales sobre el texto original.
  - La regla de identidad se mantiene como bloque Markdown corto y solo exige separar datos visibles.
- **Schema compacto para reduccion de tokens**:
  - `DocumentExtractionContractBuilder` declara `valores` solo para campos multi-valor (`code` y `trace_token`), dejando que `DocumentNormalizer` reconstruya arrays para escalares desde `valor`.
  - `detect_visual_checks` incluye `valor`, `unidad` y `fecha_base` solo cuando el check activo es `VigenciaEntrega`; checks booleanos como `FirmaActaEntrega` usan schema reducido.
  - `DocumentExtractionWorker` emite la instruccion de vigencia solo cuando el payload contiene `VigenciaEntrega`.
- **Fix de regresion en evidencia tipo lista**:
  - `FieldValueResolver` ahora usa `valores` como evidencia auditable cuando `valor` llega nulo y el estado es `FOUND_IN_LIST`, evitando falsos `NO_ENCONTRADO` en `CodigoDiagnostico`.
  - `CODE` y `TRACE_TOKEN` preservan comparacion multi-valor; los escalares con un solo candidato en `valores` se usan como valor simple.
  - Escalares o cantidades con multiples candidatos en una misma evidencia quedan como `NO_CONCLUYENTE`, no se suman como lineas ni se descartan.
- **Tests y documentacion**:
  - `DocumentExtractionWorkerTest` valida que el system prompt conserve instrucciones no duplicadas y retire las cubiertas por el contrato.
  - `DocumentPolicyEngineTest` cubre `valor=null` + `valores=[...]` para `CODE`, escalares con candidato unico y listas ambiguas.
  - Sincronizada la skill `audfact-audit-gemini` con el contrato de descripciones configurables y deduplicacion de prompt.
  - Changelog normalizado para remover NULs y ruido de encoding del diff.

## [2026-06-08] - Docs: Alineacion de contrato `-CODIGO- detalle`

### Documentacion / Skills / Clean Rebuild
- Alineada la documentacion para declarar el contrato como prefijo textual `-CODIGO- detalle` en hallazgos configurables fallidos.
- Corregidas referencias ambiguas a "sufijo" en `plans/features/audit-workflow.md`, `audfact-audit-gemini` y `audfact-api-rest`.
- Se mantiene la decision MVP de no agregar una propiedad publica separada para `codigoCampo` en hallazgos.
- Limpieza tecnica en `AuditConfigModel::replaceFields()`: reemplazado `bindParam()` dentro del loop por `bindValue()` por iteracion, preservando tipos PDO explicitos y evitando referencias mutables.

## [2026-06-03] - Fix: Codigo de Campo en Justificaciones Fallidas y Alineacion de Tests

### IA Pipeline / Audit Config / Clean Rebuild
- **Hallazgos con codigo funcional (Prefijo)**:
  - Los hallazgos configurables fallidos (`VALOR_DISTINTO`, `NO_ENCONTRADO`, `NO_CONCLUYENTE` y fallos visuales/calculados con codigo disponible) anteponen el prefijo corto `-CODIGO- ` al `detalle`.
  - Los hallazgos `COINCIDE` conservan el comportamiento actual y no reciben cÃ³digo.
  - La idempotencia se garantiza al validar que el detalle no inicie con el formato de prefijo esperado mediante `str_starts_with`.

- **Propagacion de metadata**:
  - `codigoCampo` se conserva desde `audit-config` en campos de datos y visual checks.
  - `POST /clients/{clientId}/audit-config` preserva `codigoCampo` al reemplazar configuraciones, evitando perdida de trazabilidad.

- **Tests y documentacion**:
  - Agregadas pruebas enfocadas para datos, visual checks, orquestacion y `VigenciaEntrega`.
  - Corregidos los tests unitarios `DocumentPolicyEngineTest.php` y `RulesEvaluationWorkerTest.php` para buscar el formato de prefijo `-CODE- `.
  - Sincronizados `plans/api-endpoints.md`, `plans/features/audit-workflow.md`, `plans/database-schema.md`, `audfact-audit-gemini`, `audfact-api-rest` y `audfact-sqlsrv-models`.

## [2026-06-02] - Fix: Resolucion Multi-Item con Contrato de Valores

### IA Pipeline / Clean Rebuild / Audit Results
- **Contrato comun FDV/documento**:
  - Agregado `ResolvedAuditValue` para comparar fuente de verdad y evidencia documental con el mismo shape (`displayValue`, `values`, `normalizedValues`, `ambiguous`, `evidenceMeta`).
  - `FieldValueResolver` ahora resuelve cantidades agregadas, sets `TRACE_TOKEN` y ambiguedad tanto para FDV como para documento.

- **Correccion de reglas multi-item**:
  - `DocumentPolicyEngine` compara `Lote` como set completo y cantidades `TipoCampo=B` como sumatoria de items.
  - Caso real `D13260500540` queda cubierto: `Lote={5D03364,5G00989}`, `CantidadEntregada=7`, `CantidadPrescrita=30` deben evaluar `COINCIDE`.
  - Hallazgos `CODE`/`TRACE_TOKEN` pueden persistir `valoresFuenteVerdad` y `valoresDocumento`.

- **Resultados persistidos**:
  - `AuditStatusModel` deriva `auditExecuted` desde estados terminales con payload persistido, evitando mostrar como no ejecutadas auditorias en `manual_review` ya evaluadas.

- **Documentacion y skills**:
  - Sincronizados `plans/features/audit-workflow.md`, `audfact-audit-gemini` y `audfact-sqlsrv-models`.

## [2026-06-02] - Refactor: Clean Code sobre Contrato Gemini Dinamico v2

### IA Pipeline / Clean Rebuild / Maintainability
- **Builder de contrato**:
  - `DocumentExtractionContractBuilder` separa la seleccion de function declarations dinamicas y normaliza checks visuales activos antes de construir el schema.
  - Se mantiene intacto el contrato generado para payloads validos y el `contract_hash` sigue incluyendo declarations + required names.

- **Worker de extraccion**:
  - `DocumentExtractionWorker` centraliza la lectura de function calls opcionales/requeridos y usa un helper explicito para saber si una funcion esta declarada.
  - El prompt de checks visuales queda alineado al contrato efectivo, sin reintroducir valores FDV ni funciones omitidas.

- **Tests**:
  - Pruebas del extractor y orquestador compactan asserts repetidos sobre `required_function_names` / `allowedFunctionNames`.
  - Se conserva la cobertura de contrato dinamico, defaults canonicos y ausencia de `target_context_hash`.

## [2026-06-02] - Refactor: Contrato Gemini Dinamico Compacto v2

### IA Pipeline / Clean Rebuild / Cost Optimization
- **Function declarations dinamicas**:
  - `DocumentExtractionContractBuilder` ahora declara `extract_fields`, `extract_items` y `detect_visual_checks` solo cuando el documento tiene campos/checks activos para esas funciones.
  - `assess_document_quality` se mantiene siempre para trazabilidad de legibilidad.
  - `DocumentExtractionWorker` valida solo las funciones requeridas y rellena defaults canonicos (`fields={}`, `items=[]`, `visual_checks=[]`) para funciones omitidas.

- **Schema de evidencia mas compacto**:
  - Se conserva el shape v1 `{valor, valores, presente, estadoExtraccion}`.
  - Se eliminaron descripciones repetitivas del schema para campos genericos y se conservaron instrucciones criticas de identidad.
  - El cambio invalida cache por `contract_hash` / `prompt_context_hash`, evitando mezclar extracciones del contrato anterior.

- **Documentacion y skills**:
  - Sincronizados `plans/changelog.md`, `plans/domain-glossary.md`, `plans/features/audit-workflow.md`, `plans/architecture-executive-report.md` y `audfact-audit-gemini`.

## [2026-06-02] - Refactor: Prompt Compacto de Extraccion Gemini

### IA Pipeline / Clean Rebuild / Cost Optimization
- **Extraccion sin valores FDV en prompt**:
  - `DocumentExtractionWorker` ya no inyecta bloques de valores esperados de la fuente de verdad (`Campos de cabecera esperados` / `Campos de linea esperados`) en el prompt de Gemini.
  - Gemini queda limitado a extraer evidencia visible; la comparacion contra FDV sigue viviendo en PHP mediante normalizacion y `DocumentPolicyEngine`.
  - Las pistas de articulo para documentos prescriptivos se conservan solo cuando `NombreArticulo` se extrae realmente en `items`, evitando instrucciones contradictorias.

- **Cache alineado al prompt real**:
  - Se elimina `target_context` / `target_context_hash` del evento `document_registered`.
  - El cache de extraccion ahora usa `prompt_context_hash`, calculado desde el prompt de usuario y system prompt reales, junto con `document_hash`, `contract_hash` y version del extractor.

- **Contrato Gemini compactado**:
  - `DocumentExtractionContractBuilder` mantiene el shape v1 (`valor`, `valores`, `presente`, `estadoExtraccion`) pero reduce descripciones repetidas en schemas para bajar tokens de entrada.
  - Sin modo legacy ni feature flags, siguiendo `clean-rebuild-policy`.

- **Documentacion y skills**:
  - Sincronizados `plans/changelog.md` y `audfact-audit-gemini` con el nuevo contrato interno.

## [2026-06-02] - Docs: AlineaciÃ³n del Modelo Gemini Real del Pipeline

### ðŸ“š Documentation / IA Pipeline / Gemini
- **VerificaciÃ³n de uso real de modelos**:
  - Confirmado en `GeminiGateway` que todas las llamadas usan `GeminiConfig::model` en una Ãºnica URL `models/{GEMINI_MODEL}:generateContent`.
  - Confirmado que `DocumentExtractionWorker` y `ArticleSemanticMatchJudge` usan perfiles de generaciÃ³n (`GEMINI_EXTRACTION_*`, `GEMINI_SEMANTIC_*`) sin cambiar de modelo.
  - Corregido el reporte ejecutivo para eliminar afirmaciones de fallback o redirecciÃ³n a `gemini-3.1-pro-preview`, que no existen en el runtime actual.

- **DocumentaciÃ³n y skills**:
  - Sincronizados `README.md`, `plans/architecture-executive-report.md`, `CHANGELOG.md` y `audfact-audit-gemini`.

## [2026-06-02] - Fix: Worker Batch Productivo para AuditorÃ­a Async

### ðŸŸ¢ Runtime / ProducciÃ³n LAN / Async Jobs
- **CorrecciÃ³n estructural de topologÃ­a productiva**:
  - Agregado `worker-batch` a `docker-compose.prod.yml` usando la misma imagen PHP GHCR y el launcher canÃ³nico `php bin/audit-worker.php batch`.
  - El workflow `.github/workflows/deploy-production.yml` ahora escribe las rÃ©plicas de workers en `.env` y agrega `worker-batch` a los logs de diagnÃ³stico de health check.
  - ProducciÃ³n y runtime base quedan alineados: ambos levantan los 6 servicios del pipeline (`batch`, `orchestrator`, `extraction`, `normalizer`, `policy`, `aggregator`).

- **Contexto operativo resuelto**:
  - Hallazgo PROD-BATCH-001: `/audit/async` publicaba `batch_requested` en `audit.batch.inbox`, pero producciÃ³n no tenÃ­a consumer `batch-workers`, dejando jobs en `pending`, `sealed=false`, `total=0`.
  - Hallazgo PROD-GEMINI-001: se documenta que `GEMINI_API_KEY` expirada provoca errores `400 API key expired` en `worker-extraction`; debe renovarse en GitHub Environment `production` antes de validar extracciÃ³n real.

- **DocumentaciÃ³n y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/architecture.md`, `plans/docker-operations.md`, `plans/high-availability.md`, `plans/deployment-github-actions-lan.md` y skills operativas para reflejar la topologÃ­a productiva corregida.

## [2026-06-02] - Fix: AlineaciÃ³n de Variables `.env`

### ðŸŸ¢ ConfiguraciÃ³n / Seguridad / Runtime
- **Contrato Ãºnico de configuraciÃ³n**:
  - `.env.example` queda alineado con `.env` en 92 variables activas, sin duplicados y sin valores con forma de secreto.
  - `.env` fue reestructurado desde `.env.example` preservando valores reales existentes y agregando defaults seguros para variables faltantes.
  - Se integraron variables de imÃ¡genes GHCR, publicaciÃ³n frontend, configuraciÃ³n pÃºblica Next.js, `DB2_POOLING`, rÃ©plicas async, TTL de idempotencia y recuperaciÃ³n de eventos `pending`.

- **Higiene de secretos**:
  - Eliminada una referencia comentada con forma de API key en `.env`.
  - Eliminado el bloque PEM de ejemplo en `GOOGLE_DRIVE_PRIVATE_KEY` de `.env.example`.

- **DocumentaciÃ³n y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/docker-operations.md`, `plans/deployment-and-ci.md` y `audfact-runtime-docker` para reflejar el contrato actualizado.

## [2026-06-02] - Fix: SincronizaciÃ³n GitHub Environment Production

### ðŸŸ¢ CI/CD / Seguridad / ProducciÃ³n LAN
- **Script estructural para Secrets/Variables**:
  - Creado `scripts/sync-github-production-env.sh` para sincronizar un `.env` productivo local hacia GitHub Environment `production` usando `gh secret set` y `gh variable set`.
  - El script valida que `.env` y `.env.example` tengan el mismo set de claves activas, detecta duplicados, no usa `source`, aborta con `bash -x`, y no imprime valores.
  - Se reemplaza el enfoque de copiar `.env` al host por una fuente persistente en GitHub; el runner regenera `/home/admon/audfact-prod/.env` en cada deploy.

- **Workflow productivo alineado**:
  - `.github/workflows/deploy-production.yml` ahora separa secretos reales (`DB_PASS`, `GEMINI_API_KEY`, `MCP_WEBHOOK_SECRET`, etc.) de variables no sensibles (`DB_HOST`, `DB_PORT`, `AUDFACT_*`, `NEXT_PUBLIC_*`, `AUDIT_*`).
  - El `.env` productivo generado conserva el contrato completo de 92 variables y elimina `GEMINI_RESPONSE_MIME`, que no pertenece a `.env.example`.
  - Se agregan variables faltantes al archivo generado: `DB2_POOLING`, `AUDIT_IDEMPOTENCY_KEY_TTL`, `AUDIT_PENDING_RECLAIM_*` y `NEXT_PUBLIC_*`.

- **DocumentaciÃ³n y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/deployment-and-ci.md`, `plans/deployment-github-actions-lan.md`, `plans/docker-operations.md`, `CATALOG.md`, `catalog.json`, `audfact-runtime-docker` y `audfact-production-ops`.

## [2026-06-01] - Docs: SincronizaciÃ³n documental con cÃ³digo actual

### ðŸ“š Documentation / API / Pipeline / Skills
- **AlineaciÃ³n de endpoints y contratos REST**:
  - Sincronizadas las tablas de rutas con `app/Routes/web.php` (27 rutas), incluyendo `/metrics/async`, `/clients/{clientId}/documents`, audit-config, `/audit/stats`, `/audit/status/{auditId}`, `/audit/results/{facSec}` y `/audit/{facNro}/timings`.
  - Actualizado el contrato de `/invoices`: bÃºsqueda interactiva paginada con `page` y `pageSize`; `limit` queda restringido al batch interno de auditorÃ­a.
  - Ajustada la documentaciÃ³n de `/audit/async` al comportamiento real de idempotencia: `X-Idempotency-Key` opcional, autogeneraciÃ³n UUID y `409` con `success=true` cuando la llave ya existe.

- **AlineaciÃ³n de arquitectura y pipeline IA**:
  - Reescrito `plans/features/audit-workflow.md` para eliminar referencias al pipeline monolÃ­tico obsoleto y documentar el flujo event-driven actual.
  - Reescritos `plans/architecture-diagrams.md` y `plans/high-availability.md` para reflejar la topologÃ­a actual con Redis Streams, workers CLI y Compose vigente, eliminando referencias a `docker-compose.dev.yml`, `docker-compose.ha.yml` y clases legacy.
  - Incorporado `document_rejected` y `DocumentIntegrityValidator` en el flujo documental: rechazo pre-Gemini, hallazgo `RECHAZADO` y `tipo_auditoria=integrity`.
  - Actualizados conteos y runtime: Next.js 15.5.15, 27 rutas, 31 archivos PHP de test, workers base `batch=2`, `orchestrator=3`, `extraction=8`, `policy=2`; se documentÃ³ que `docker-compose.prod.yml` no define `worker-batch` en su estado actual.

- **SincronizaciÃ³n de skills y catÃ¡logo**:
  - Agregada `audfact-docs-sync` al catÃ¡logo humano y JSON de skills, aliases y bundles.
  - Actualizadas referencias MCP y SQL Server para remover ejemplos legacy con `limit` en bÃºsqueda interactiva y aclarar que `GetInvoices` recibe `date` y lo traduce a `dateFrom`.
  - Sincronizadas skills `audfact-runtime-docker`, `audfact-project-overview`, `audfact-audit-gemini`, `audfact-mcp-wrap` y `audfact-docs-sync` con los contratos actuales.

## [2026-05-31] - Feature: ValidaciÃ³n Preventiva de Integridad Documental y Rechazo Temprano (Clean Rebuild)

### ðŸ”´ Resiliencia / IA Pipeline / Integrity / Clean Rebuild
- **IntroducciÃ³n de `DocumentIntegrityValidator`**:
  - ImplementaciÃ³n del nuevo servicio de integridad preventiva `DocumentIntegrityValidator` en `app/Services/Audit/Pipeline/DocumentIntegrityValidator.php`.
  - DiseÃ±ado bajo la **Clean Rebuild Policy** para interceptar adjuntos antes de su envÃ­o a Gemini. Detecta de forma proactiva archivos vacÃ­os (0 bytes) y firmas de archivos corruptos.
  - Genera una bifurcaciÃ³n de flujo limpia en `DocumentExtractionWorker`. Al detectar un adjunto corrupto/vacÃ­o, no se consume API de Gemini ni se emiten datos de extracciÃ³n sintÃ©ticos. En su lugar, el estado documental se marca como `rejected` en `AuditStateStore`, se incrementa `docs_rejected` y se publica el evento explÃ­cito `document_rejected`.

- **AdaptaciÃ³n y ConsolidaciÃ³n del Pipeline**:
  - **`AuditStateStore`**: Soportado el registro explÃ­cito e idempotente del estado documental `rejected`, evitando inyectar lÃ³gica de negocio o placeholders en componentes intermedios.
  - **`DocumentExtractionWorker`**: Integrado el validador de integridad justo despuÃ©s de descargar el adjunto. Si la validaciÃ³n falla, se evita el consumo de Gemini y se publica `document_rejected`.
  - **`RulesEvaluationWorker`**: Consume `document_rejected` como entrada de policy, genera un `policy_result` canÃ³nico con hallazgo de severidad `alta`, resultado `RECHAZADO` y `tipo_auditoria=integrity`, y usa `docs_done + docs_rejected` para el readiness de agregaciÃ³n.
  - **`AuditFindingResult`**: Registrado el resultado `"RECHAZADO"` en la lista de resultados de auditorÃ­a vÃ¡lidos para cumplir de forma determinista con el contrato runtime de auditorÃ­a.

- **FormalizaciÃ³n de DocumentaciÃ³n y Skills**:
  - **`plans/architecture.md`**: Actualizado para reflejar la introducciÃ³n de `DocumentIntegrityValidator` y el flujo de bifurcaciÃ³n de eventos del pipeline.
  - **`audfact-audit-gemini`**: Sincronizada la skill del agente para incluir la validaciÃ³n preventiva de integridad, la bifurcaciÃ³n de flujo `REJECTED` y el nuevo validador documental en la tabla de servicios clave.

## [2026-05-30] - Docs: ExpansiÃ³n ArquitectÃ³nica Forense, AlineaciÃ³n E2E, Matiz de MÃ©tricas (ROI) y AuditorÃ­a de TTL en Redis

### ðŸ“š Documentation / Architecture / Resiliencia / Redis TTL / ROI Refinement
- **ApÃ©ndice TÃ©cnico de Persistencia en Redis (TTL)**:
  - Se realizÃ³ una auditorÃ­a forense completa de los Time-To-Live (TTL) y polÃ­ticas de expiraciÃ³n en la capa de datos en caliente de Redis.
  - Se documentaron formalmente todos los tiempos de expiraciÃ³n reales en el ApÃ©ndice TÃ©cnico (secciÃ³n 7) de [architecture-executive-report.md](file:///c:/Users/USER/Desktop/AudFact/plans/architecture-executive-report.md), detallando: CachÃ© de ExtracciÃ³n Documental (24h), HomologaciÃ³n SemÃ¡ntica (30d), Estado Transitorio de AuditorÃ­as (24h), Estado de Batch Jobs (24h), CachÃ© de Hash de DispensaciÃ³n (24h), Barrera de Idempotencia HTTP (5min), CachÃ© de Consultas PÃºblicas (60s) y Distributed Locks (10s).
  - Esta formalizaciÃ³n erradica las "cajas negras" y provee total transparencia operativa para el dimensionamiento del consumo de memoria y optimizaciÃ³n de rendimiento bajo alta concurrencia.

- **Refinamiento de MÃ©tricas de ROI e Impacto de Negocio**:
  - Se sustituyeron las afirmaciones absolutas y categÃ³ricas ("Cero Glosas", "ReducciÃ³n del 98%", "Incremento de velocidad del 400%") por aserciones estadÃ­sticas y rigurosas basadas en mitigaciÃ³n activa de riesgos bajo validaciÃ³n continua y revisiÃ³n manual de casos complejos.
  - Se incorporÃ³ la mÃ©trica operativa real del auditor de **3 minutos por dispensa (hora-hombre)** como lÃ­nea de base manual de comparaciÃ³n de tiempos, proyectando una optimizaciÃ³n potencial de hasta el 83% gracias al pipeline asÃ­ncrono distribuidor de workers.
  - Se proyectÃ³ un ahorro potencial de costos de tokens en APIs multimodales de hasta el 85% a travÃ©s del SHA256 Extraction Cache, basado en la tasa de redundancia del portafolio del cliente.

- **FormalizaciÃ³n de los 6 Pilares de Alta Eficiencia y Resiliencia**:
  - **Redis Streams & Idempotencia (Pilar 1)**: DocumentaciÃ³n en profundidad de la topologÃ­a event-driven utilizando Redis Streams, la adquisiciÃ³n de bloqueos concurrentes atÃ³micos, la re-reclamaciÃ³n defensiva de eventos huÃ©rfanos vÃ­a `XAUTOCLAIM` (idle > 10 min) y la polÃ­tica fail-closed con envÃ­o a Dead Letter Queue (`audit.dlq`).
  - **Gemini Parallel Function Calling (Pilar 2)**: Detalle del flujo de invocaciÃ³n de herramientas, Structured Outputs y mitigaciÃ³n estricta de errores HTTP `400 Bad Request` mediante el mÃ©todo recursivo de sanitizaciÃ³n de esquemas JSON `normalizeSchemaProperties()` en `GeminiGateway`.
  - **Patrones de Resiliencia Industrial (Pilar 3)**: DocumentaciÃ³n de la mÃ¡quina de estados distribuida en Redis (`cb:gemini:*`) para implementar el Circuit Breaker y estrategias de Backoff Exponencial con Jitter.
  - **Modelo HÃ­brido de AuditorÃ­a (Pilar 4)**: JustificaciÃ³n del desacoplamiento entre el motor cognitivo de IA (comprensiÃ³n y traducciÃ³n semÃ¡ntica de adjuntos) y el motor determinista local en PHP (`DocumentPolicyEngine` para validaciones de leyes y normativas colombianas sin alucinaciones).
  - **Lazy Downloading en Memoria (Pilar 5)**: Detalle del consumo de adjuntos binarios mediante streams de memoria en PHP a partir de Google Drive API, evitando la I/O a almacenamiento fÃ­sico en disco.
  - **TelemetrÃ­a y MÃ©tricas en Cola (Pilar 6)**: ExplicaciÃ³n de los timings acumulados del pipeline asÃ­ncrono y los metadatos almacenados de ejecuciÃ³n.

- **Paridad y SincronizaciÃ³n Dual (Protocolo `audfact-docs-sync`)**:
  - SincronizaciÃ³n absoluta del reporte ejecutivo del repositorio (`plans/architecture-executive-report.md`) con el reporte del brain tÃ©cnico del agente (`technical_architecture_report.md`).
  - ConfiguraciÃ³n minuciosa de enrutamientos de diagramas: rutas relativas para el repositorio local y rutas absolutas compatibles con el visor de la IA en el reporte del brain.
  - ActualizaciÃ³n de las directrices y repositorios de conocimiento en las skills `audfact-audit-gemini` y `audfact-project-overview` para reflejar la topologÃ­a exacta del cÃ³digo.
  - Registro formal del walkthrough en el artefacto final `walkthrough.md`.


## [2026-05-29] - Fix: Incompatibilidad de placeholders nombrados en CTEs con pdo_sqlsrv

### ðŸŸ¢ Bugfix / SQL / Async Jobs Stability
- **ResoluciÃ³n de Error `SQLSTATE[07002]`**:
  - **Incompatibilidad del Driver SQLSRV**: Corregido el fallo crÃ­tico en `InvoicesModel::getInvoicesForAuditBatch` donde el parser de parÃ¡metros de `pdo_sqlsrv` fallaba al procesar placeholders nombrados dentro de una ExpresiÃ³n de Tabla ComÃºn (CTE `WITH candidates AS ...`), lanzando `SQLSTATE[07002]: COUNT field incorrect or syntax error`.
  - **RefactorizaciÃ³n a Subquery Derivada**: Reemplazada la estructura de consulta con CTE por una subquery derivada estÃ¡ndar (`FROM (SELECT ...) candidates`). Esto preserva el rendimiento de ejecuciÃ³n de la paginaciÃ³n keyset y la lÃ³gica semÃ¡ntica pero asegura compatibilidad nativa absoluta con el driver PDO de SQL Server.
  - **EstabilizaciÃ³n de Jobs en Redis**: Al eliminar las excepciones de base de datos durante la orquestaciÃ³n, se previene que `AuditBatchOrchestrator` ejecute el flujo destructivo de limpieza de estado de Redis (eliminando el `jobId`), resolviendo de forma definitiva los molestos errores `404` al consultar el progreso del job.
  - **ActualizaciÃ³n de Tests**: Sincronizada la clase `InvoicesModelTest` para validar los assertions contra la nueva sintaxis de subquery derivada. La suite completa de PHPUnit se encuentra en estado verde con paso exitoso de todas las aserciones.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizados `plans/changelog.md`, la skill principal de base de datos `.agent/skills/audfact-sqlsrv-models/SKILL.md` para aÃ±adir el guardrail de CTEs, y actualizados los artefactos de progreso.

## [2026-05-29] - Refactor: Pipeline de AuditorÃ­a Async Real e Idempotencia Absoluta

### ðŸ”µ Architecture / High Concurrency / Idempotency
- **ErradicaciÃ³n del Falso AsÃ­ncrono en `POST /audit/async`**:
  - **Desacoplamiento HTTP**: El endpoint en `AuditController::async()` ya no ejecuta la costosa consulta SQL de facturas ni la orquestaciÃ³n en el hilo del request web. Ahora valida parÃ¡metros, obtiene/calcula la llave de idempotencia, registra el Job en estado `pending` en `BatchJobStore` y publica el evento `batch_requested` al nuevo stream `audit.batch.inbox` en Redis, respondiendo `202 Accepted` de inmediato en menos de 100 ms.
  - **Idempotencia Absoluta por Job/Batch**: ImplementaciÃ³n de polÃ­ticas rigurosas en `BatchJobStore`. Si llega una peticiÃ³n idÃ©ntica con el mismo hash o `X-Idempotency-Key`, se reutiliza atÃ³micamente el job existente. Si llega con el mismo hash pero con diferentes parÃ¡metros, se aborta con `409 Conflict`, evitando colisiones y duplicaciÃ³n de carga bajo alta concurrencia.
  - **Procesamiento en Segundo Plano**: El nuevo worker `BatchRequestedWorker` (lanzado con `php bin/audit-worker.php batch`) consume de `audit.batch.inbox`, ejecuta la consulta pesada a SQL Server para obtener las facturas correspondientes, adquiere la reserva de idempotencia por `FacSec` en Redis y publica secuencialmente los eventos `audit_created` a `audit.inbox`.
  - **Robusted de Tests**: Removida la palabra clave `final` de la clase de prueba auxiliar `StubBatchJobStore` en `tests/Controllers/AuditControllerTest.php` para posibilitar el mockeo robusto de PHPUnit y Mockery. Las aserciones fueron actualizadas para validar el contrato asÃ­ncrono real y los payloads/streams correctos.
  - **Resultados**: Cero regresiones y paso exitoso de todas las pruebas automatizadas (100% verde).

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizados `plans/api-endpoints.md`, `plans/data-flows.md`, `plans/architecture.md`, `CHANGELOG.md` y la skill principal `.agent/skills/audfact-audit-gemini/SKILL.md` para reflejar la topologÃ­a de 6 servicios y el flujo asÃ­ncrono real.

## [2026-05-25] - Refactor: Observabilidad final y escalado controlado del pipeline async


### ðŸ”µ Architecture / Performance / Observability
- **TelemetrÃ­a por evento**: `AuditEventConsumer` registra por auditorÃ­a el stream, consumer, evento, espera en cola, tiempo de ejecuciÃ³n del handler, tiempo de ack y estado final del evento.
  - **RecuperaciÃ³n de pending**: el consumer reclama periÃ³dicamente mensajes Redis Streams abandonados con `AUDIT_PENDING_RECLAIM_IDLE_MS` alto para no duplicar llamadas largas a Gemini.
  - **Estado Redis**: `AuditStateStore` agrega `event_timings` y `aggregation_timings` como parte del estado de auditorÃ­a.
  - **Timings finales**: `AuditAggregationWorker` mide construcciÃ³n de agregado, persistencia SQL y cierre Redis; despuÃ©s de `completeAudit()` recalcula los timings con `completed_at` real y actualiza `AudDispEst` por `FacSec`.
  - **Reporte**: `AuditTimingSummarizer` conserva las mÃ©tricas existentes y agrega bloques `pipeline`, `event_telemetry` y `aggregation`.
  - **Nginx runtime**: la plantilla usa resolver Docker (`127.0.0.11`) y `fastcgi_pass` por variable para no conservar IPs PHP-FPM obsoletas despuÃ©s de recrear rÃ©plicas.
  - **Runtime**: `docker-compose.yml` y `docker-compose.prod.yml` parametrizan rÃ©plicas iniciales: orquestadores `3`, extractores `8`, policy `2`; `.env.example` documenta reclaim idle/interval.
  - **VerificaciÃ³n**: tests focales verdes para consumer, state store, summarizer y aggregator.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizados `CHANGELOG.md`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/architecture.md`, `plans/docker-operations.md`, `.agent/skills/CATALOG.md`, `.agent/skills/audfact-audit-gemini/SKILL.md` y `.agent/skills/audfact-runtime-docker/SKILL.md`.

## [2026-05-25] - Fix: Identidad canÃ³nica de FDV por FacSec

### ðŸŸ¢ Bugfix / API / Pipeline
- **FacSec como selector canÃ³nico E2E**: `POST /audit/single` ahora recibe `FacSec`; `DocumentAuditOrchestrator` resuelve la FDV por `facsecF` y valida `DisDetNro` Ãºnicamente como llave operativa para adjuntos y `FacNro`.
  - **Modelos**: `DispensationModel` agrega consulta explÃ­cita por `facsecF = :FacSec`, reutilizando el SELECT FDV sin duplicaciÃ³n.
  - **Pipeline**: `AuditDataService` expone `getDispensationByFacSec()` y el orquestador exige `payload.fac_sec` en `audit_created`.
  - **Frontend**: la auditorÃ­a individual y el deep-link desde facturas envÃ­an `FacSec`.
  - **VerificaciÃ³n**: PHPUnit completo verde (276 tests, 815 assertions, 10 skipped), typecheck frontend verde, dos lotes concurrentes del cliente `2426` completaron 20/20 sin nuevos DLQ.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizados `README.md`, `AGENTS.md`, `plans/api-endpoints.md`, `plans/audit-identity-contract.md`, `plans/data-flows.md`, `CHANGELOG.md`, `.agent/skills/audfact-audit-gemini/SKILL.md` y `.agent/skills/audfact-sqlsrv-models/SKILL.md`.

## [2026-05-21] - Bugfix: NormalizaciÃ³n de Fechas NumÃ©ricas con Espacios

### ðŸŸ¢ Bugfix / Refactor
- **NormalizaciÃ³n de Fechas NumÃ©ricas**: Mejoras en `AuditFindingRules::normalizeDateToIso` para admitir formatos numÃ©ricos con separador de espacios (ej. `'25 3 2026'`) y descartar horas o minutos sufijos (incluyendo formatos AM/PM de 12 horas).
  - **AuditFindingRules**: Se implementÃ³ una limpieza robusta mediante expresiones regulares para remover horas de 12 o 24 horas y normalizar los espacios como guiones.
  - **Tests Unitarios**: AdiciÃ³n de mÃºltiples casos de prueba a la suite `AuditFindingRulesNormalizationTest` cubriendo ambigÃ¼edades numÃ©ricas con espacios, aÃ±os de dos dÃ­gitos y horas con formatos narrativos.
  - **VerificaciÃ³n**: EjecuciÃ³n exitosa de la suite completa de pruebas unitarias locales (267 tests pasados, 0 regresiones).

## [2026-05-20] - Refactor: EstandarizaciÃ³n de Contratos de Fechas (AudFact)

### ðŸ”µ Architecture / Bugfix
- **RefactorizaciÃ³n de Contratos de Fechas**: Se eliminÃ³ la complejidad dinÃ¡mica en el manejo de fechas en `InvoicesModel` para garantizar que el contrato `dateTo` sea obligatorio en toda la cadena de ejecuciÃ³n, previniendo errores de comparaciÃ³n `NULL` en SQL Server.
  - **Modelos (`InvoicesModel`)**: Se eliminÃ³ el cÃ³digo muerto relacionado con la lÃ³gica de fechas dinÃ¡mica y se actualizÃ³ la firma de `getInvoices` para requerir `string $dateTo`.
  - **Controladores (`InvoicesController`, `AuditController`)**: ImplementaciÃ³n de autocompletado para `dateTo` cuando el parÃ¡metro viene vacÃ­o, usando `dateFrom`.
  - **Servicios (`AuditBatchOrchestrator`, `BatchJobStore`)**: ActualizaciÃ³n de firmas para eliminar nulabilidad de `$dateTo` y requerir tipos estrictos en todo momento.
  - **Tests**: Reescritura completa de los tests en `InvoicesModelTest`, `InvoicesControllerTest`, y `AuditControllerTest` para validar los nuevos contratos. Se corrigiÃ³ una regresiÃ³n que causaba que `testResultDetailReturnsPersistedAuditDetail` fallara al no encontrar el mÃ©todo `resultDetail` en el controlador (se agregÃ³ nuevamente el mÃ©todo faltante).

## [2026-05-20] - Hotfix: Conectividad Frontend-to-Backend en ProducciÃ³n y server-only

### ðŸŸ¢ Infrastructure / Bugfix
- **INFRA-002**: SoluciÃ³n definitiva a la conectividad entre el frontend Next.js y el backend PHP-FPM/Nginx en producciÃ³n.
  - **EvitaciÃ³n del Bucle Local de Red**: Configurado enrutamiento directo contenedor-a-contenedor a travÃ©s de la red interna de Docker (`http://nginx`) para llamadas SSR y componentes de servidor (RSC/SSR) en el frontend.
  - **ResoluciÃ³n de Error de CompilaciÃ³n**: Se corrigiÃ³ el error estÃ¡tico de compilaciÃ³n `You're importing a component that needs "server-only" which is not supported in the pages/ directory` en `frontend/lib/api/client.ts`. Se eliminÃ³ la importaciÃ³n estÃ¡tica de `@/lib/api/server-config` en favor de la lectura dinÃ¡mica inline de `process.env.INTERNAL_API_URL`.
  - **Pipeline de Secrets robustecido**: Integrado pipeline automÃ¡tico de GitHub Actions para inyectar `INTERNAL_API_URL=http://nginx` en el workflow de despliegue productivo y sincronizaciÃ³n de secretos de producciÃ³n locales.
  - **Archivos Modificados**: `frontend/lib/api/client.ts`, `.github/workflows/deploy-production.yml`.
  - **VerificaciÃ³n**: ConfirmaciÃ³n E2E en producciÃ³n vÃ­a SSH en `172.16.0.3` con respuestas exitosas (200 OK) en `/api/backend/health` y `/api/backend/clients` a travÃ©s del puerto `3100`.

## [2026-05-20] - Clean Rebuild: Service Oriented Pipeline Phase 5

### ðŸ”µ Architecture / Refactor
- **AUDIT-026**: CulminaciÃ³n de la refactorizaciÃ³n arquitectÃ³nica del pipeline de auditorÃ­a orientada a servicios (Fase 5).
  - **ExtracciÃ³n de LÃ³gica**: LÃ³gica legacy extraÃ­da de `AuditFindingRules` hacia los nuevos servicios `VisualCheckEvaluator` y `FieldValueResolver`.
  - **MÃ©tricas Independientes**: CreaciÃ³n de `AuditTimingSummarizer` para calcular latencias en el ciclo de agregaciÃ³n.
  - **Limpieza de Delegados**: Eliminados mÃ©todos estÃ¡ticos obsoletos `isFailureResult` y `isDiscrepancyResult` en favor del enum tipado `AuditFindingResult::tryFrom()->isFailure()`.
  - **EncapsulaciÃ³n Final**: SimplificaciÃ³n de `AuditAggregationWorker` delegando la validaciÃ³n del schema, normalizaciÃ³n, severidad y latencias.
  - **Persistencia Aislada**: SimplificaciÃ³n de `ResponseIADiskStore`.
  - **ValidaciÃ³n E2E**: EjecuciÃ³n exitosa de la suite completa (244 tests), asegurando 0 regresiones.
  - **Archivos Modificados**: `AuditFindingRules.php`, `RulesEvaluationWorker.php`, `AuditAggregationWorker.php`, `ResponseIADiskStore.php`.
  - **Archivos Creados**: `VisualCheckEvaluator.php`, `FieldValueResolver.php`, `AuditTimingSummarizer.php`.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada `plans/architecture.md` con los nuevos servicios del orquestador. Skills y tareas actualizadas.

## [2026-05-15] - Clean Rebuild: Hardening de Pipeline y ErradicaciÃ³n de Legacy

### ðŸ§¹ Cleanup / Refactor
- **PIPELINE-CLEAN-REBUILD**: EjecuciÃ³n estricta de `clean-rebuild-policy` en el pipeline de auditorÃ­a.
  - **EliminaciÃ³n de CÃ³digo Muerto**: Se removiÃ³ el campo obsoleto `confidence` del schema JSON de `ArticleSemanticMatchJudge` y la lÃ³gica hÃ­brida dependiente (`isConservativeMatch()`). Se eliminÃ³ `responseMimeType` de `GeminiConfig` al estar desfasado con Gemini 3.1. Se erradicÃ³ el estado legacy `AMBIGUOUS` de `ExtractionState`.
  - **Estabilidad de Hallazgos**: Se eliminÃ³ la keyword 'confianza' de `AuditFindingRules::observationRequiresManualReview` para evitar falsos positivos de revisiÃ³n manual.
  - **EstandarizaciÃ³n SemÃ¡ntica**: Se renombrÃ³ el array interno `_evidencia` a `extraction_meta` en `DocumentPolicyEngine`. Se documentÃ³ claramente la diferencia entre `error` y `failed` en `AuditStateStore`.
  - **ResoluciÃ³n de Error TÃ©cnico**: Se corrigiÃ³ un fatal error en CLI PHP 8.1 causado por una constante en `JsonRedisStoreTrait`, migrÃ¡ndola a `protected static string`.
  - **SincronizaciÃ³n de Versiones**: Las versiones por defecto de los extractores en `DocumentExtractionWorker` y `AuditEvent` se sincronizaron con `v1` (eliminando cualquier dependencia futura a `v2-identity-split`).
  - **ValidaciÃ³n E2E**: 236 tests ejecutados, todos pasando (10 skipped), confirmando 0 regresiones.
  - **Archivos Modificados**: `GeminiConfig.php`, `ExtractionState.php`, `ArticleSemanticMatchJudge.php`, `AuditFindingRules.php`, `DocumentPolicyEngine.php`, `DocumentExtractionContractBuilder.php`, `AuditStateStore.php`, `AuditEvent.php`, `AuditDataService.php`, `DocumentExtractionWorker.php`, `JsonRedisStoreTrait.php`, `BatchJobStore.php`, `GeminiConfigTest.php`, `DocumentAuditOrchestratorTest.php`.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada la documentaciÃ³n de `AGENTS.md` y `.env` removiendo referencias a `GEMINI_RESPONSE_MIME`. Evaluada `audfact-audit-gemini`, que se mantiene vigente con el esquema limpio de la v1.

## [2026-05-13] - EliminaciÃ³n de Artefactos IA en ProducciÃ³n

### ðŸ”’ Security / Infrastructure
- **INFRA-001**: EliminaciÃ³n de volÃºmenes `responseIA/` del compose productivo.
  - **Problema**: `docker-compose.prod.yml` montaba `./responseIA:/var/www/html/responseIA` en los servicios `php`, `worker-extraction` y `worker-policy`. Aunque el cÃ³digo PHP (`ResponseIADiskStore`, lÃ­nea 46) ya impedÃ­a la escritura en `APP_ENV !== 'development'`, Docker creaba el directorio vacÃ­o en el host al levantar los contenedores.
  - **CorrecciÃ³n**: Eliminados 3 mounts de volumen del compose productivo. Solo `./logs:/var/www/html/logs` persiste como mount en producciÃ³n.
  - **DocumentaciÃ³n sincronizada**: `plans/docker-operations.md` (secciÃ³n Precauciones), `README.md` (secciÃ³n ProducciÃ³n) actualizados para reflejar que `responseIA/` es exclusivo de desarrollo.
  - **Archivos modificados**: `docker-compose.prod.yml`, `plans/docker-operations.md`, `README.md`
  - **ValidaciÃ³n**: Verificado que `responseIA/` sigue presente en `docker-compose.yml` (desarrollo), `.dockerignore` (excluido del build context) y `.gitignore`.

## [2026-05-11] - Infraestructura & DocumentaciÃ³n Visual

### Docs / Diagrams
- **ARCH-DIAGRAMS**: ActualizaciÃ³n completa de `plans/architecture-diagrams.md` para reflejar la arquitectura **Event-Driven** actual (C4 Model Nivel 1, 2 y 3).
- **Architecture Walkthrough**: CreaciÃ³n de un walkthrough interactivo con diagramas PNG generados vÃ­a `mmdc` (Mermaid CLI) para facilitar la inducciÃ³n tÃ©cnica.
- **Secrets Sync**: SincronizaciÃ³n de secretos de producciÃ³n desde el entorno local `.env` hacia GitHub Secrets para habilitar el despliegue automÃ¡tico.

## [2026-05-11] - Skill de Operaciones de Produccion

### Docs / Ops
- **PROD-OPS-SKILL**: Creada la skill `audfact-production-ops` para que agentes accedan por SSH al servidor LAN `admon@172.16.0.3`, ejecuten diagnosticos seguros y sigan runbooks de deploy/rollback con GitHub Actions self-hosted runner.
  - **Guardrails**: La skill prohibe persistir passwords o imprimir secrets y exige aprobacion explicita para acciones con impacto.
  - **Automatizacion**: Agregado `Invoke-AudFactProdSsh.ps1`, wrapper PowerShell con OpenSSH explicito y `SSH_ASKPASS` temporal.
  - **Catalogo**: Sincronizados `CATALOG.md`, `catalog.json`, `aliases.json`, `bundles.json`, `validation-baseline.json`, `AGENTS.md` y `CLAUDE.md`.

## [2026-05-08] â€” OptimizaciÃ³n: ReducciÃ³n de Payload de ExtracciÃ³n (Gemini v1)

### âš¡ Performance / Cleanup
- **AUDIT-023**: EliminaciÃ³n de metadata redundante en el motor de extracciÃ³n Gemini.
  - **Poda de Schema**: Se eliminaron los campos `confianza`, `evidencia` y `ubicacion` del JSON schema generado por `DocumentExtractionContractBuilder`. Esto reduce el tamaÃ±o de la respuesta y el consumo de tokens.
  - **SimplificaciÃ³n DTO**: Refactorizado `ExtractedEvidence` para remover los atributos `confidence`, `justification` y `location`. El DTO ahora transporta exclusivamente la informaciÃ³n decisional y es retrocompatible ignorando claves legacy.
  - **Limpieza de NormalizaciÃ³n**: Removida lÃ³gica obsoleta en `DocumentNormalizer` que procesaba los campos descartados.
  - **ActualizaciÃ³n de CÃ³digo Base**: Modificados los comentarios y docblocks en `DocumentPolicyEngine` para reflejar la nueva estructura simplificada.
  - **ValidaciÃ³n**: `DocumentNormalizerTest` actualizado para validar la ausencia de los campos eliminados (191 tests exitosos).
  - **Archivos modificados**: `DocumentExtractionContractBuilder.php`, `ExtractedEvidence.php`, `DocumentNormalizer.php`, `DocumentPolicyEngine.php`, `tests/.../DocumentNormalizerTest.php`
## [2026-05-05] â€” Hardening de NormalizaciÃ³n: Cierre de Brechas Anti-Glosa (NORM-001)

### ðŸ”’ Hardening / Bugfix
- **NORM-001**: Cierre de 3 brechas de normalizaciÃ³n que podÃ­an generar falsos `VALOR_DISTINTO` y consecuentes glosas injustificadas.

  **Componente 1 â€” Tabla completa de aliases `IDENTITY_DOC_TYPE`** (`AuditFindingRules`):
  - Cobertura ampliada de ~30% a ~100% de los tipos de documento RIPS/BDUA colombianos.
  - Se cubren ahora los 11 tipos oficiales: CC, TI, CE, RC, PA, PE/PEP, PPT, MS, AS, NUIP, SC.
  - Antes: solo CC y variantes de "CÃ©dula de CiudadanÃ­a". Ahora: "Tarjeta de Identidad" â†’ TI, "CÃ©dula de ExtranjerÃ­a" â†’ CE, "Pasaporte" â†’ PA, "PEP" â†’ PE, etc.
  - Implementado como `private const IDENTITY_DOC_ALIASES` en vez de `match` â€” O(1) lookup, extensible sin tocar lÃ³gica.

  **Componente 2 â€” `stripAccents()` determinÃ­stico**:
  - Antes: dependÃ­a exclusivamente de `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE')` â€” frÃ¡gil en contenedores Alpine sin locale configurada.
  - Ahora: `strtr()` con tabla explÃ­cita de 40+ caracteres como estrategia primaria. `iconv` como fallback solo para caracteres Unicode exÃ³ticos fuera de la tabla.
  - Elimina la posibilidad de que acentos persistan como diferencias en la comparaciÃ³n textual.

  **Componente 3 â€” Parser de fechas narrativas en espaÃ±ol** (`normalizeDateToIso`):
  - Nuevo mÃ©todo privado `parseSpanishNarrativeDate()` como fallback tras parseo numÃ©rico.
  - Soporta: "4 de mayo de 2026", "Mayo 4, 2026", "4-mayo-2026", "4 may 2026", abreviaciones estÃ¡ndar.
  - `checkdate()` valida que la fecha sea real (ej: "30 de febrero" â†’ `null`).

  **Tests aÃ±adidos** (64 unitarios NORM-001 + 3 integraciÃ³n):
  - `tests/Services/Audit/AuditFindingRulesNormalizationTest.php` â€” 64 tests con DataProviders.
  - `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` â€” 3 tests end-to-end NORM-001.
  - Resultado: 94/94 âœ…, cero regresiones en tests preexistentes.

  **Archivos modificados**: `AuditFindingRules.php`.
  **Archivos creados**: `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.


## [2026-05-04] â€” DepuraciÃ³n: CÃ³digo Muerto y Drift Documental (ARCH-002)

### ðŸ§¹ Cleanup
- **ARCH-002**: EliminaciÃ³n de cÃ³digo muerto y correcciÃ³n de drift documental en `plans/architecture.md`.
  - **Archivo eliminado**: `ClientConfigurationService.php` â€” fachada hueca sin consumidores (0 imports en todo el proyecto). Era un pass-through 1:1 sobre `AuditConfigModel`, creado en AUDIT-022 pero nunca integrado en el pipeline ni en controllers.
  - **Drift corregido en `architecture.md`**: Eliminada referencia fantasma a `GeminiCircuitBreaker.php` (circuit breaker fue inlineado en `GeminiGateway` en AUDIT-013). Agregada referencia faltante a `GeminiCallMetrics.php`. Corregida ruta `Debug/ResponseIADiskStore.php` â†’ `ResponseIADiskStore.php` (el subdirectorio `Debug/` nunca existiÃ³).
  - **Archivos eliminados**: `app/Services/Audit/ClientConfigurationService.php`
  - **Archivos modificados**: `plans/architecture.md`


## [2026-05-03] â€” Clean Controller: DelegaciÃ³n de OrquestaciÃ³n y ConfiguraciÃ³n (AUDIT-022)

### ðŸ”µ Architecture / Refactor
- **AUDIT-022**: RefactorizaciÃ³n de `AuditController` hacia el patrÃ³n *Thin Controller*, delegando la lÃ³gica de negocio a servicios especializados.
  - **`AuditBatchOrchestrator`**: Creado para encapsular el encolamiento asÃ­ncrono, la reserva de slots concurrentes en Redis (`BatchJobStore`), la inicializaciÃ³n del estado (`AuditStateStore`) y el rollback transaccional en caso de fallos de persistencia.
  - **`ClientConfigurationService`**: Creado para abstraer la consolidaciÃ³n dinÃ¡mica de la configuraciÃ³n de auditorÃ­a (mezcla de campos hardcodeados y visuales de la DB) y su persistencia.
  - **Resultado**: El controlador `AuditController` redujo su tamaÃ±o y complejidad drÃ¡sticamente (de 614 a 427 lÃ­neas). Las responsabilidades transaccionales ahora residen en clases testeables e independientes.
  - **Fix adicional**: Corregido bug en `InvoicesModelTest` donde las pruebas fallaban al depender de lÃ³gica condicional obsoleta (`$dateConditionD`).
  - **ValidaciÃ³n**: 100% verde (174/174 tests, 568 assertions).
  - **Archivos creados**: `AuditBatchOrchestrator.php`, `ClientConfigurationService.php`.
  - **Archivos modificados**: `AuditController.php`, `AuditControllerTest.php`, `InvoicesModel.php`, `InvoicesModelTest.php`.

## [2026-05-03] â€” Clean Rebuild: ErradicaciÃ³n de Legacy en Pipeline (AUDIT-021)

### ðŸ§¹ Cleanup / Refactor
- **AUDIT-021**: EliminaciÃ³n completa de compatibilidad retroactiva legacy en la capa de normalizaciÃ³n y polÃ­ticas, asumiendo un flujo estrictamente "shape v1" determinista.
  - **`DocumentNormalizer`**: Eliminado el comportamiento hÃ­brido en `normalizeFieldWithLog` y borrado de la lÃ³gica y logs de `legacy_scalar_wrapped_v1`. Se simplificÃ³ `isEmptyRow` asumiendo arrays `['valor']`.
  - **`DocumentPolicyEngine`**: Eliminado el mÃ©todo `unwrapV1()` hÃ­brido, haciendo acceso directo a los arrays v1 inyectados en la extracciÃ³n.
  - **Resultado**: CÃ³digo mÃ¡s limpio, directo y libre de capas de traducciÃ³n ("por si acasos"), ciÃ±Ã©ndose al MVP. Se eliminÃ³ la capacidad de procesar payloads antiguos, lo cual es correcto dado que el contrato actual de Gemini siempre devuelve v1.
  - **ValidaciÃ³n**: Los tests `GoldenSetReplayTest` (asÃ­ como unitarios de `DocumentNormalizer` y `DocumentPolicyEngine`) fueron adecuados y pasaron exitosamente.
  - **Archivos modificados**: `DocumentNormalizer.php`, `DocumentPolicyEngine.php`, `DocumentNormalizerTest.php`, `DocumentPolicyEngineTest.php`, `golden_D65260408592.json`.

## [2026-05-02] â€” Clean Code Pipeline: Enums Centralizadores (AUDIT-020)

### ðŸ”µ Architecture / Refactor
- **AUDIT-020**: EliminaciÃ³n de constantes duplicadas y mÃ©todos redundantes en el pipeline de auditorÃ­a. Sin cambios en API pÃºblica, contratos de eventos ni respuestas REST.
  - **Nuevo enum** `DocumentQuality` (`legible/parcialmente_legible/ilegible`) reemplaza la constante privada `DOCUMENT_QUALITY_ENUM` que existÃ­a duplicada en `DocumentExtractionWorker`, `DocumentNormalizer` y `DocumentPolicyEngine`. Incluye `fromString()` (con validaciÃ³n), `tryFromString()`, `isLegible()` y `preventsConclusion()`.
  - **Nuevo enum** `AuditFindingResult` (`COINCIDE/VALOR_DISTINTO/NO_ENCONTRADO/OMITIDO/NO_CONCLUYENTE`) reemplaza las constantes privadas `RESULT_*` que existÃ­an duplicadas en `DocumentPolicyEngine` (5), `RulesEvaluationWorker` (3) y `AuditFindingRules` (3). Incluye `isFailure()`, `isDiscrepancy()`, `isInconclusive()`, `isSkipped()`.
  - **`AuditFindingRules`**: eliminadas constantes `RESULT_*` y listas `FAILURE_RESULTS`/`DISCREPANCY_RESULTS` â†’ delegaciÃ³n a `AuditFindingResult`. Agregados helpers estÃ¡ticos compartidos: `normalizeNullableString()` y `normalizeToken()`.
  - **`DocumentPolicyEngine`**: eliminadas 5 constantes `RESULT_*`, `DOCUMENT_QUALITY_ENUM`, `normalizeNullableString()` privado, `normalizeIdentityDocumentTypeToken()` privado (duplicado de `normalizeToken()` de `DocumentNormalizer`), y parÃ¡metro muerto `$documentType` de `evaluateField()`.
  - **`DocumentNormalizer`**: eliminadas `DOCUMENT_QUALITY_ENUM`, `normalizeNullableString()` privado, `normalizeToken()` privado â†’ delegan a `AuditFindingRules`.
  - **`DocumentExtractionWorker`**: eliminada `DOCUMENT_QUALITY_ENUM` â†’ `DocumentQuality::fromString()`.
  - **`RulesEvaluationWorker`**: eliminadas 3 constantes `RESULT_*` â†’ `AuditFindingResult::*->value`.
  - **Resultado**: 15 definiciones duplicadas eliminadas (5 constantes Ã— 3 clases). Todos los valores de string son idÃ©nticos al contrato anterior â€” backward compatibility total.
  - **ValidaciÃ³n**: 88/88 tests, 330 assertions, 0 regresiones, sin modificaciÃ³n de tests.
  - **Archivos creados**: `app/Services/Audit/DocumentQuality.php`, `app/Services/Audit/AuditFindingResult.php`
  - **Archivos modificados**: `AuditFindingRules.php`, `DocumentPolicyEngine.php`, `DocumentNormalizer.php`, `DocumentExtractionWorker.php`, `RulesEvaluationWorker.php`

## [2026-05-02] â€” FormalizaciÃ³n de Tipos de Valor Auditables (AuditFieldValueType)

### ðŸ”µ Architecture / Refactor
- **AUDIT-019**: SeparaciÃ³n formal de "tipo de comparaciÃ³n" (`AuditComparisonType`: E/S/B/V) y "tipo de dato" (`AuditFieldValueType`: text/date/quantity/money/identity_doc_type).
  - **Nuevo enum** `AuditFieldValueType` con factory `fromFieldName()` que consolida 4 heurÃ­sticas dispersas (`str_starts_with('Fecha')`, `str_starts_with('Cantidad')`, `str_starts_with('Vlr')`, `in_array(['TipoDocumentoPaciente', 'TipoDocumentoMedico'])`) en un Ãºnico punto de decisiÃ³n.
  - **MÃ©todos auxiliares**: `isNumericForSchema()` (reemplaza `isNumberField()`), `isQuantitySummable()` (reemplaza `isQuantityField()` en resoluciÃ³n de valores).
  - **DocumentPolicyEngine**: `normalizeForComparison()` refactorizado de cascada if/else a `match` expression. MÃ©todo privado `isIdentityDocumentTypeField()` eliminado. `resolveDocumentValue()`, `resolveSourceTruthValue()` y `evaluateBusinessField()` usan `AuditFieldValueType` directamente.
  - **DocumentExtractionContractBuilder**: `schemaTypeForField()` delega a `isNumericForSchema()`.
  - **AuditComparisonType**: `isDateField()`, `isQuantityField()`, `isNumberField()` marcados `@deprecated` como puentes que delegan a `AuditFieldValueType` â€” backward compatibility total para `DocumentNormalizer`.
  - **Resultado**: Refactoring puramente interno. API pÃºblica, contratos de eventos, respuestas REST y hallazgos persistidos no cambian.
  - **ValidaciÃ³n**: 88/88 tests, 330 assertions, 0 regresiones, sin modificaciÃ³n de tests.
  - **Archivos creados**: `app/Services/Audit/AuditFieldValueType.php`
  - **Archivos modificados**: `AuditComparisonType.php`, `DocumentPolicyEngine.php`, `DocumentExtractionContractBuilder.php`

### ðŸ“š Documentation / Skills
- **DOCS-SYNC**: Skill `audfact-audit-gemini` actualizada con `AuditFieldValueType` en tabla de archivos clave, regla 2 y referencias.

## [2026-05-01] â€” Limpieza Dead Code y Wrappers Redundantes (Pipeline)

### ðŸ§¹ Cleanup / Refactor
- **QUAL-001**: Eliminado `TYPE_EXTRACTION_FAILED` â€” constante declarada y ruteada sin productor ni consumer en todo el codebase.
  - Archivos modificados: `AuditEvent.php`, `AuditEventPublisher.php`
- **QUAL-002**: Limpieza de referencias fantasma a clases eliminadas en refactors AUDIT-013/014.
  - `FieldClassifier` eliminado de `AuditSeverity.php` (docblock) y SKILL.md (tabla de archivos)
  - `DocumentNormalizationWorker` â†’ `DocumentNormalizer` en AGENTS.md y SKILL.md
  - `AuditResultAggregator` â†’ `AuditAggregationWorker` en AGENTS.md
  - `InternalAuditApiClient` â†’ `AuditDataService` + `AttachmentDownloadService` en `plans/architecture.md` y SKILL.md
  - `extraction_failed` eliminado de tabla de streams en SKILL.md
  - `FieldClassifier` agregado al banner de obsolescencia de `audit-workflow.md`
- **Wrappers eliminados en `DocumentPolicyEngine`**:
  - `shouldSkipByCondition()` â€” wrapper de 1 lÃ­nea que delegaba a `AuditFindingRules::shouldSkipByCondition()`. Docblock migrado al mÃ©todo fuente de verdad.
  - `normalizeVisualSeverity()` â€” duplicaba `AuditSeverity::fromInput()->value`. Reemplazado por llamada directa al enum.
  - Archivos modificados: `DocumentPolicyEngine.php`, `AuditFindingRules.php`
- **Deuda documentada (fuera de scope)**: `normalizeDocumentType()` en PolicyEngine (semÃ¡ntica `iconv` vs `strtr` requiere tests), parÃ¡metro muerto `$documentType` en `evaluateField()`.

## [2026-04-28] â€” Docs Sync: Pipeline event-driven & TipoCampo

### ðŸ“š Documentation / Skills
- **DOCS-SYNC-002**: SincronizaciÃ³n tras detectar drift acumulado contra refactors AUDIT-013/014/015/016 y validaciÃ³n contra el caso golden `T38250701547` (NitSec 2426).
  - **Skill `audfact-audit-gemini`**: bootstrap unificado a `bin/audit-worker.php <rol>` (era lista de 5 binarios consolidados en AUDIT-015), eliminadas filas de archivos fusionados (`DocumentNormalizationWorker`, `AuditResultAggregator`, `ExtractionCache`, `SchemaBuilder` â€” AUDIT-014), corregido naming TipoCampo (el enum `AuditComparisonType::fromTipoCampo()` mapea `E` como default â†’ `EXACT`; el cÃ³digo `D` no existe), eliminada regla "factor de empaque NitSec=2426 â‰¤ 5 unidades / `ACEPTADO_POR_EMPAQUE`" (no implementada en cÃ³digo), agregadas secciones para mecanismo `omitirSi` (`fdv_has`/`fdv_missing`/`doc_quality`), agregaciÃ³n de items en reglas `B` (sumatoria pre-comparaciÃ³n) y contrato real de hallazgo, nota tÃ©cnica sobre thinking tokens en Gemini 3.x, removida referencia al `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` eliminado en AUDIT-016.
  - **Skill `audfact-project-overview`**: reemplazado el flujo monolÃ­tico (`AuditOrchestrator.auditInvoice` + `EmbeddingGateway` + `RuleEngine` + `AuditPersistenceService`) por el flujo event-driven actual (orchestrator â†’ extraction â†’ normalizer â†’ policy â†’ aggregator); conteos actualizados (8â†’11 controllers, 6â†’7 models, 11â†’22 archivos en `Services/Audit`); endpoints 17â†’22 con `audit-config`, DLQ y timings; patrones actualizados (Template Method, Lua scripts, Builder dinÃ¡mico).
  - **`CATALOG.md`**: eliminada fila `app/Services/Audit/AuditOrchestrator.php` (no existe), agregada `Pipeline/DocumentAuditOrchestrator.php` y wildcard `Pipeline/*.php`; descripciÃ³n y triggers de `audfact-audit-gemini` actualizados.
  - **`AGENTS.md`**: corregido namespace `Events/ â†’ Pipeline/` en archivos crÃ­ticos del pipeline; reemplazada referencia a `AuditPromptBuilder.php` (eliminado) por construcciÃ³n dinÃ¡mica del schema/prompts en `DocumentAuditOrchestrator` y `DocumentExtractionWorker`.
  - **TODO de negocio**: la regla "factor de empaque â‰¤ 5 unidades para NitSec=2426 con warning `ACEPTADO_POR_EMPAQUE`" se eliminÃ³ de la skill por no estar implementada (0 hits en cÃ³digo). Si el negocio aÃºn la requiere, debe registrarse como nuevo ticket de implementaciÃ³n (puede vivir en `DocumentPolicyEngine` o como `omitirSi` en el `audit-config` de 2426).
  - **Drift residual fuera de alcance**: la carpeta `tests/Services/Audit/Events/` no fue renombrada a `Pipeline/` cuando el cÃ³digo de producciÃ³n se renombrÃ³ (AUDIT-013). Pendiente como tarea separada de testing.

## [2026-04-28] â€” Docs Sync: Perfiles Gemini y Fallback SemÃ¡ntico

### ðŸ“š Documentation / Skills
- **DOCS-SYNC**: SincronizaciÃ³n documental posterior a la correcciÃ³n del pipeline Gemini.
  - **Skills actualizadas**: `audfact-audit-gemini` documenta `GeminiConfig`, `SemanticMatchJudge`, mÃ©tricas Gemini por tarea, perfiles `GEMINI_EXTRACTION_*` / `GEMINI_SEMANTIC_*`, fallback limpio y no-cache de fallos transitorios.
  - **Runtime actualizado**: `audfact-runtime-docker` documenta que PHP/workers usan cÃ³digo baked en imagen y requieren rebuild/recreate tras cambios de backend.
  - **DocumentaciÃ³n humana verificada**: `AGENTS.md` ya contiene el catÃ¡logo de variables Gemini por tarea; `CHANGELOG.md` ya registra el cambio user-facing.
  - **ValidaciÃ³n base**: Golden Case `T38250701547` mantiene `manual_review`, 34 coincidencias, 1 discrepancia y 1 no concluyente; la respuesta ya no persiste errores tÃ©cnicos de Gemini.

## [2026-04-28] â€” OptimizaciÃ³n de Performance: Pro-Parallel (82s â†’ 34s)

### âš¡ Performance / Infrastructure
- **AUDIT-018**: OptimizaciÃ³n masiva de latencia en el pipeline de auditorÃ­a sin pÃ©rdida de calidad.
  - **Paralelismo**: Escalado de `worker-extraction` de 1 a **5 rÃ©plicas** en `docker-compose.yml`. Esto permite que los adjuntos de una factura (promedio 3) se procesen simultÃ¡neamente en lugar de secuencialmente.
  - **ConfiguraciÃ³n Pro-Optimized**: Uso de `gemini-3.1-pro-preview` con `GEMINI_MEDIA_RESOLUTION=MEDIA_RESOLUTION_LOW`. La reducciÃ³n de resoluciÃ³n acelera el procesamiento de la API de Gemini sin degradar la precisiÃ³n en campos crÃ­ticos (CIE-10, firmas).
  - **Resultado**: ReducciÃ³n del tiempo total de auditorÃ­a de **82 segundos a 34 segundos** (mejora del 58%) para una factura estÃ¡ndar de 3 documentos escaneados.
  - **Archivos modificados**: `docker-compose.yml`, `.env`, `.env.example`, `app/Services/Audit/GeminiConfig.php`.

## [2026-04-27] â€” Limpieza de artefactos muertos del repositorio

### ðŸ§¹ Cleanup
- **AUDIT-016**: EliminaciÃ³n de documentaciÃ³n obsoleta, variables fantasma y archivos dead del repositorio.
  - **Archivos raÃ­z eliminados**: `ASSESSMENT_AudFact_AuditPipeline_v1.0.md` (66KB), `PLANNING_AudFact_AuditPipelineCleanRebuild_v1.0.md` (67KB), `REPRODUCIBILITY_FRAMEWORK.md` (7KB), `CHANGELOG.md` (duplicado de `plans/changelog.md`), `.env.dev` (sin consumidor).
  - **Directorio eliminado**: `tmp/` (3 JPGs de prueba manual), `app/Services/prompts/` (5 archivos de prompts legacy: v1-v4 + philosophy).
  - **Variables fantasma eliminadas**: `GEMINI_THINKING_LEVEL` (sin consumidor PHP), `GEMINI_EMBEDDING_MODEL` (nunca implementado), `SEMANTIC_THRESHOLD_DEFAULT` (hardcoded en `AuditComparisonType`), `AUDIT_FDV_TTL` (sin consumidor).
  - **Variables sincronizadas**: `AUDIT_VERSION_EXTRACTOR`, `AUDIT_VERSION_NORMALIZER`, `AUDIT_VERSION_RULES` agregadas a `.env` (faltaban, son consumidas por `AuditEvent.php`).
  - **Resultado neto**: âˆ’8 archivos raÃ­z, âˆ’8 archivos en subdirectorios, âˆ’4 variables fantasma, +3 variables sincronizadas.

## [2026-04-27] â€” ConsolidaciÃ³n de Bootstrap Scripts (`bin/`)

### ðŸ”µ Architecture / Refactor
- **AUDIT-017**: ImplementaciÃ³n de extracciÃ³n selectiva para documentos prescriptivos (FORMULA MEDICA, RECETA, etc.). El `DocumentExtractionWorker` ahora inyecta en el prompt de Gemini la lista de artÃ­culos efectivamente dispensados (segÃºn la FDV), limitando la extracciÃ³n a Ã­tems relevantes y reduciendo el ruido/consumo de tokens en >90% (ej. 2 Ã­tems extraÃ­dos en lugar de 21).
- **AUDIT-015**: ConsolidaciÃ³n de los scripts ejecutables de los workers en un Ãºnico launcher.
  - **FusiÃ³n `bin/audit-*-worker.php` â†’ `bin/audit-worker.php`**: Se eliminaron 5 scripts de bootstrap casi idÃ©nticos y se reemplazaron por un Ãºnico launcher que usa un registry de configuraciÃ³n.
  - El nuevo launcher recibe el nombre del worker como primer argumento CLI (ej: `php bin/audit-worker.php orchestrator`).
  - **Resultado neto**: âˆ’4 archivos (5â†’1). CentralizaciÃ³n de carga de variables de entorno y manejo de seÃ±ales POSIX.
  - **Archivos eliminados**: `bin/audit-orchestrator-worker.php`, `bin/audit-extraction-worker.php`, `bin/audit-normalizer-worker.php`, `bin/audit-policy-worker.php`, `bin/audit-aggregator-worker.php`
  - **Archivos aÃ±adidos**: `bin/audit-worker.php`
  - **Archivos modificados**: `docker-compose.yml` (actualizaciÃ³n de los `command:` de cada servicio).
  - **ValidaciÃ³n E2E**: `T38250701547` procesado correctamente con score idÃ©ntico (15) tras reconstrucciÃ³n de contenedores.
## [2026-04-27] â€” ConsolidaciÃ³n Pipeline: 17 â†’ 13 archivos

### ðŸ”µ Architecture / Refactor
- **AUDIT-014**: ConsolidaciÃ³n del Ã¡rbol `app/Services/Audit/Pipeline/` mediante fusiÃ³n de clases con relaciÃ³n 1:1 exclusiva:
  - **F1: `DocumentNormalizationWorker` â†’ `DocumentNormalizer`**: El thin wrapper (88 lÃ­neas) se eliminÃ³. `DocumentNormalizer` ahora extiende `AuditEventConsumer` directamente, actuando como worker autocontenido.
  - **F2: `AuditResultAggregator` â†’ `AuditAggregationWorker`**: Los mÃ©todos de agregaciÃ³n (normalizaciÃ³n de hallazgos, resoluciÃ³n de status final, severidad) se absorbieron como mÃ©todos privados del worker. Ãšnico consumidor.
  - **F4: `ExtractionCache` â†’ `DocumentExtractionWorker`**: Los mÃ©todos de cache Redis por `document_hash` se absorbieron como mÃ©todos privados del worker. Ãšnico consumidor.
  - **F5: `SchemaBuilder` â†’ `DocumentAuditOrchestrator`**: La construcciÃ³n del function declaration Gemini se absorbiÃ³ en el orchestrator. `normalizeName()` se mantiene pÃºblico estÃ¡tico.
  - **Descartada**: FusiÃ³n de `AuditFindingRules` â€” utilidad compartida por 3+ clases (PolicyEngine, RulesEvaluationWorker, AggregationWorker).
  - **Resultado neto**: âˆ’4 archivos (17â†’13), âˆ’24% de archivos, sin pÃ©rdida funcional.
  - **Archivos eliminados**: `DocumentNormalizationWorker.php`, `AuditResultAggregator.php`, `ExtractionCache.php`, `SchemaBuilder.php`
  - **Archivos modificados**: `DocumentNormalizer.php`, `AuditAggregationWorker.php`, `DocumentExtractionWorker.php`, `DocumentAuditOrchestrator.php`, `bin/audit-normalizer-worker.php`
  - **ValidaciÃ³n E2E**: `T38250701547` â†’ `risk_score:15`, `coincidencias:34`, `discrepancias:1` (idÃ©ntico a pre-refactorizaciÃ³n)

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Actualizado `plans/architecture.md` con la estructura consolidada de 13 archivos. Actualizado `plans/changelog.md`.

## [2026-04-27] â€” ReestructuraciÃ³n Deep: app/Services/Audit

### ðŸ”µ Architecture / Refactor
- **AUDIT-013**: ReestructuraciÃ³n profunda del Ã¡rbol `app/Services/Audit`:
  - **Rename `Events/` â†’ `Pipeline/`**: El namespace genÃ©rico `Events` se renombrÃ³ a `Pipeline` para reflejar con precisiÃ³n su responsabilidad (pipeline event-driven de auditorÃ­a).
  - **FusiÃ³n `FieldStructure` â†’ `AuditComparisonType`**: Los 6 mÃ©todos estÃ¡ticos de detecciÃ³n de tipo por convenciÃ³n (fechas, cantidades, umbrales semÃ¡nticos) se integraron directamente en el enum `AuditComparisonType`. âˆ’1 archivo.
  - **FusiÃ³n `GeminiGatewayFactory` â†’ `GeminiConfig::fromEnv()` + `GeminiGateway::create()`**: La factory separada se absorbiÃ³ como mÃ©todo estÃ¡tico en las clases que configuran e instancian el gateway. âˆ’1 archivo.
  - **`AuditFindingRules` â†’ mÃ©todos estÃ¡ticos**: Eliminadas 3 instanciaciones innecesarias (`new AuditFindingRules()`) en `DocumentPolicyEngine`, `RulesEvaluationWorker` y `AuditResultAggregator`.
  - **Resultado neto**: De 26 archivos dispersos a 22 archivos organizados en 2 subcarpetas (`Pipeline/`, `Debug/`).

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Reconstruido `plans/architecture.md` con la nueva estructura. Actualizado `plans/changelog.md`. Skills `audfact-audit-gemini` y `CATALOG.md` pendientes de actualizaciÃ³n por el rename de namespace.
  - Archivos actualizados: `plans/architecture.md`, `plans/changelog.md`

## [2026-04-27] â€” RefactorizaciÃ³n ArquitectÃ³nica: GeminiGateway

### ðŸŸ¢ Calidad de CÃ³digo / Refactor
- **AUDIT-012**: RediseÃ±o completo de la capa de comunicaciÃ³n con IA (`GeminiGateway`).
  - **ExtracciÃ³n de responsabilidades (SRP)**: SeparaciÃ³n de la configuraciÃ³n en un Value Object inmutable (`GeminiConfig`) y extracciÃ³n de la resiliencia en un componente aislado y testeable (`GeminiCircuitBreaker`).
  - **EliminaciÃ³n de cÃ³digo muerto**: Removidas funciones inutilizadas y simplificado el constructor de 12 a 4 parÃ¡metros.
  - **Desacoplamiento de contexto**: El contexto de trazabilidad (`X-Audit-Context-*`) se desacoplÃ³ del array de `generationOverrides`, inyectÃ¡ndose explÃ­citamente como un parÃ¡metro dedicado (`$debugContext`), eliminando el antipatrÃ³n de "bolsa mÃ¡gica".

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada la documentaciÃ³n de arquitectura y el changelog. Validada la cobertura implÃ­cita del catÃ¡logo de skills.
  - Archivos actualizados: `plans/changelog.md`, `plans/architecture.md`

## [2026-04-27] â€” AuditorÃ­a DinÃ¡mica y ConfiguraciÃ³n Universal

### ðŸ”µ Features / Architecture
- **AUDIT-009**: ImplementaciÃ³n de **ConfiguraciÃ³n de AuditorÃ­a DinÃ¡mica**. El sistema ahora permite definir metadatos por campo (Exacto, SemÃ¡ntico, Negocio) y severidades (ALTA, MEDIA, BAJA) persistidos en base de datos.
- **AUDIT-010**: RediseÃ±o de la UI de configuraciÃ³n (`AuditConfigEditor`) para soportar la ediciÃ³n de nuevos tipos de campos y severidades dinÃ¡micas.
- **AUDIT-011**: Soporte para tipos de campo "S" (SemÃ¡ntico) y "B" (Negocio) en el pipeline de auditorÃ­a, permitiendo validaciones contextuales avanzadas vÃ­a Gemini.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada la documentaciÃ³n de endpoints y las skills de API y AuditorÃ­a Gemini para reflejar el nuevo modelo de datos dinÃ¡mico.
  - Archivos actualizados: `plans/changelog.md`, `plans/api-endpoints.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-24] â€” CorrecciÃ³n Interfaz MCP (GetInvoices)

### ðŸ”´ Critical Fixes
- **AUDIT-008**: Se solucionÃ³ un desajuste de parÃ¡metros en la tool `GetInvoices` (`app/wrap/core/tools/GetInvoices.php`). La interfaz MCP recibe el parÃ¡metro `date`, pero el cliente HTTP local no lo parseaba a `dateFrom` como lo espera `InvoicesController::index()`, resultando en validaciones HTTP 422 permanentes (bloqueando a los agentes IA de obtener facturas).

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Validada la skill `audfact-mcp-wrap`. No requiere cambios ya que el contrato externo MCP se mantuvo estricto, sÃ³lo cambiÃ³ el mapeo interno.
  - Archivos actualizados: `plans/changelog.md`


## [2026-03-24] â€” ExclusiÃ³n de RegimenPaciente en Fuente de Verdad (AuditorÃ­a IA)

### ðŸŸ¢ Quality of Life / Business Logic
- **AUDIT-007**: Se modificÃ³ la consulta en `DispensationModel` para excluir el campo `RegimenPaciente` y forzar su valor a `NULL` para clientes especÃ­ficos que no lo reportan consistentemente (NitSec `1045` Positiva, `80455` Suramericana, `2426` Colsanitas).
  - Esto activa la "Regla Absoluta de RÃ©gimen" del `AuditPromptBuilder` (fallback a `N/D`), eliminando falsos positivos en discrepancias donde el rÃ©gimen de los documentos no coincide con la BD.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada la skill `audfact-audit-gemini` para documentar la regla explÃ­cita de exclusiÃ³n para clientes particulares en conjunto con la regla de fallback del prompt.
  - Archivos actualizados: `plans/changelog.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-24] â€” ImplementaciÃ³n de Regla de Entregas Parciales (Audit Prompt)

### ðŸŸ¢ Quality of Life / Business Logic
- **AUDIT-006**: Implementada la regla de **entregas parciales** en `AuditPromptBuilder`. Gemini ahora permite que la cantidad en la Fuente de Verdad sea menor o igual a lo prescrito/autorizado sin reportar discrepancias. Solo se marca como `VALOR_DISTINTO` si el entregado excede el autorizado.
  - Modificado Â§03 para excluir cantidades de comparaciÃ³n exacta.
  - Agregada sub-regla en Â§05 con lÃ³gica de validaciÃ³n dirigida.
  - Actualizado Â§08 (Auto-auditorÃ­a) para forzar verificaciÃ³n de parciales.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Sincronizada la documentaciÃ³n en `plans/features/audit-workflow.md` y la skill `audfact-audit-gemini` para reflejar la nueva capacidad de auditorÃ­a cuantitativa.
  - Archivos actualizados: `plans/changelog.md`, `plans/features/audit-workflow.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

## [2026-03-20] â€” Robustecimiento de Transacciones, Parseo JSON y Resiliencia Redis (Pipeline Audit)

### ðŸ”´ Critical Fixes
- **AUDIT-005 / C-01**: Inconsistencia transaccional en `AuditPersistenceService` â†’ Ahora envuelve `upsertAuditResult` y actualizaciÃ³n de adjuntos en una transacciÃ³n PDO; si falla, revierte todo para mantener integridad y pospone la actualizaciÃ³n en la cachÃ© de Redis (`lrem`).
- **AUDIT-005 / C-02**: Respuestas JSON de Gemini truncadas, malformadas o con llaves sin cerrar â†’ Integrado `JsonRepairHelper` como fallback en `JsonResponseParser` para reparar comas sueltas, strings incompletos y corchetes desbalanceados antes de fallar.

### ðŸŸ  High Priority Fixes
- **AUDIT-005 / H-01**: PÃ©rdida silenciosa de scripts Lua (`NOSCRIPT`) por reinicios de servidor Redis en Workers â†’ Agregado try/catch en `AuditQueueService::updateJob()` para atrapar el error `NOSCRIPT` y reintentar instantÃ¡neamente recargando y ejecutando el script en crudo con `EVAL`.

### Refactor (Testing)
- **TEST-001**: 100% de la suite de pruebas unitarias sincronizada con los cambios operacionales. El servicio de persistencia implementa ahora Mocks de PDO con ReflexiÃ³n para verificar commits/rollbacks sin necesitar DB viva.
- **TEST-002**: SoluciÃ³n de colisiones de namespace (`FakeInvoicesModel`) entre Tests.

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Actualizada skill `audfact-audit-gemini` (incorporando la secciÃ³n Resiliencia vs Errores Formato y el uso del Helper).
  - Archivos actualizados: `plans/changelog.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`

### Archivos modificados
`app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditQueueService.php`, `app/Services/Audit/JsonResponseParser.php`, `app/Services/Audit/JsonRepairHelper.php` (nuevo), `tests/Services/Audit/*`, `tests/Controllers/InvoicesControllerTest.php`, `tests/Models/InvoicesModelTest.php`

## [2026-03-19] â€” Correcciones Persistencia e Idempotencia (Audit)
- **AUDIT-004 / C-01**: CorrupciÃ³n de datos por truncado en CachÃ© â†’ `AuditPersistenceService` guarda `severity`, `_errorOrigin` y metadata completa.
- **AUDIT-004 / C-02**: Mapeo invÃ¡lido de PK al re-persistir desde CachÃ© â†’ `AuditController::run` reconstruido para forzar `FacNro` genuino.
- **AUDIT-004 / Idempotencia**: Controlador usaba prefijo quemado (`audit:result:`) â†’ sincronizado con `REDIS_PREFIX` de Env.

### ðŸŸ  High Priority Fixes
- **AUDIT-004 / H-01**: DB Fallback sin validaciÃ³n estricta â†’ `AuditStatusModel` devuelve int/false; el caching se aborta ante falla.

### ðŸŸ¡ Medium / Low Priority
- **AUDIT-004 / M-02 / L-02**: Pre-validaciones abortaban sin array pre-formateado â†’ inyecciÃ³n de `$items` de fallos documentales y MIPRES a `fail()`.

### Archivos modificados
`app/Services/Audit/AuditPersistenceService.php`, `app/Controllers/AuditController.php`, `app/Services/Audit/AuditPreValidator.php`, `app/Models/AuditStatusModel.php`

## [2026-03-18] â€” Correcciones AuditorÃ­a Independiente (19 hallazgos)

### ðŸ”´ Critical Fixes
- **AUDIT-003 / C-01**: SQL Injection en `$limit` de `InvoicesModel` â†’ cast `(int)` defensivo
- **AUDIT-003 / C-03**: `Response::success()`/`error()` lanzaban excepciones sin documentar â†’ `#[NoReturn]` + `@return never`
- **AUDIT-003 / C-04**: ComparaciÃ³n de fechas con operadores string â†’ `DateTime` objects (4 sitios en InvoicesController + AuditController)
- **AUDIT-003 / C-05**: Fecha asimÃ©trica en subquery de `InvoicesModel` â†’ condiciÃ³n simÃ©trica con igualdad
- **AUDIT-003 / C-06**: `set_time_limit(120)` en `AuditOrchestrator` anulaba timeout del controller â†’ eliminado

### ðŸŸ  High Priority Fixes
- **AUDIT-003 / H-01**: Regla `optional` en `Validator` funcionaba por accidente â†’ implementaciÃ³n explÃ­cita
- **AUDIT-003 / H-02**: Regla `min_length:` ignorada silenciosamente â†’ implementada en `Validator`
- **AUDIT-003 / H-03**: Cache key en `AuditController::results()` no invalidable â†’ prefijo `facNitSec`
- **AUDIT-003 / H-04**: `count($attempts)` como cÃ³digo de excepciÃ³n (daba 2) â†’ HTTP 500 con attempts en mensaje
- **AUDIT-003 / H-05**: Sin sanitizaciÃ³n post-validaciÃ³n en `Controller` â†’ `sanitizeData()` con `trim()` + `strip_tags()`

### ðŸŸ¡ Medium Priority Fixes
- **AUDIT-003 / M-01**: `GROUP BY` 20+ columnas sin agregaciÃ³n en `DispensationModel` â†’ `SELECT DISTINCT`
- **AUDIT-003 / M-03**: Rate limiting con `REMOTE_ADDR` (IP del proxy Docker) â†’ `RateLimit::getClientIp()` proxy-aware
- **AUDIT-003 / M-04**: Uso dual de `DisDetNro` en `AuditController::single()` â†’ documentado con comentario
- **AUDIT-003 / M-05**: PK hardcodeada `id` en `Model` base â†’ `$primaryKey` configurable

### ðŸ”µ Low Priority Fixes
- **AUDIT-003 / L-01**: Fuga de `facNitSec` en logs de `InvoicesModel` â†’ enmascaramiento `***` + Ãºltimos 3 dÃ­gitos
- **AUDIT-003 / L-02**: SQL completo en logs de error de `Database` â†’ `[REDACTED]`
- **AUDIT-003 / L-03**: Regex de `Router` no aceptaba puntos en parÃ¡metros â†’ `[\w.\-]+`
- **AUDIT-003 / L-04**: `declare(strict_types=1)` aÃ±adido en `Database`, `Validator`, `RateLimit`

### Descartado
- **C-02 (AutenticaciÃ³n API)**: Postergado a sprint futuro por decisiÃ³n del usuario

### Archivos modificados (13)
`app/Models/InvoicesModel.php`, `app/Models/DispensationModel.php`, `app/Models/Model.php`, `core/Database.php`, `core/Validator.php`, `app/Controllers/Controller.php`, `app/Controllers/InvoicesController.php`, `app/Controllers/AuditController.php`, `app/Services/Audit/AuditOrchestrator.php`, `core/Response.php`, `core/Router.php`, `core/RateLimit.php`, `public/index.php`

## [2026-03-18] â€” Fix InyecciÃ³n Exhaustiva de Medicamentos (AuditorÃ­a IA)

### Fix (Prompt)
- **IteraciÃ³n Multi-Medicamento**: `AuditPromptBuilder` itera sobre todos los Ã­tems de `$dispensationData` generando nodos `<medication item="N">` XML individuales, asegurando que la IA valide todos los medicamentos de una dispensaciÃ³n multi-lÃ­nea.
- **Entregas Parciales (v3.2)**: El sistema permite que la Fuente de Verdad registre cantidades menores o iguales a las prescritas/autorizadas, clasificÃ¡ndolas como `COINCIDE` para evitar falsos positivos en dispensaciones fragmentadas.
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`
  - Prompt v3.2: 4 capas con axiomas deterministas, motor de 6 dimensiones, protocolo de reconfirmaciÃ³n anti-alucinaciÃ³n, e **iteraciÃ³n multi-medicamento**. Incluye regla de **entregas parciales** (FdV â‰¤ Doc OK).

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Actualizada skill `audfact-audit-gemini` (v3.0â†’v3.1 con iteraciÃ³n multi-medicamento). Corregido drift significativo acumulado en `plans/features/audit-workflow.md`: tabla de archivos obsoleta (`GeminiAuditService` â†’ `AuditOrchestrator`), endpoints faltantes (async, jobStatus, results, documents-history), parÃ¡metro `FacNro`â†’`DisDetNro`, versiÃ³n de prompt (v6.0â†’v3.1), secciÃ³n multi-lÃ­neaâ†’multi-medicamento con XML iterado, y notas tÃ©cnicas sobre filtrado de adjuntos.
  - Archivos actualizados: `.agent/skills/audfact-audit-gemini/SKILL.md`, `plans/features/audit-workflow.md`

### Refactor (Post-Audit Quality)
- **AUDIT-002**: Correcciones robustas post-auditorÃ­a independiente (6 hallazgos):
  - **H-01**: Â§08.7 restaurado con guard rail concreto (`{$totalLineas}` Ã­tems + verificaciÃ³n individual)
  - **M-01**: Supuesto de metadatos comunes (`$ref = $dispensationData[0]`) documentado
  - **M-02**: `FirmaActaEntrega` hardcodeada como 'Obligatorio' documentada como decisiÃ³n de negocio
  - **M-03**: Nodos `<medication>` envueltos en tag contenedor `<medications total="N">`
  - **L-01**: Helper `isMultiItem()` extraÃ­do (DRY, 4 instancias reemplazadas)
  - **L-02**: DocBlock actualizado `@version 2.1` â†’ `@version 3.1`
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`, `app/Models/DispensationModel.php`

## [2026-03-18] â€” Correcciones CI/CD Pipeline (14 hallazgos)

### ðŸ”´ Critical Fixes
- **CICD-001**: Deploy separado build de restart â€” build failure ya no causa downtime
  - `docker compose build` (containers siguen corriendo) â†’ `docker compose up -d --force-recreate`
  - Archivos: `.github/workflows/ci.yml`
- **CICD-002**: Composer installer reemplazado por `COPY --from=composer:2` (supply chain safe)
  - Archivos: `docker/Dockerfile`
- **CICD-003**: Agregado `permissions: contents: read` a ambos workflows (least privilege)
  - Archivos: `ci.yml`, `deploy-frontend.yml`

### ðŸŸ  High Priority Fixes
- **CICD-004**: `timeout-minutes` agregado a 4 jobs (15min lint, 30min deploy)
- **CICD-005**: Eliminado `echo` de `NEXT_PUBLIC_API_URL` en logs del workflow
- **CICD-006**: `.env` en contenedor cambiado de `chmod 644` a `chmod 640`
  - Archivos: `docker/docker-entrypoint.sh`
- **CICD-007**: Redis `--requirepass` agregado con default `audfact_dev_default`
  - Archivos: `docker-compose.yml`, `ci.yml` (.env generation)

### ðŸŸ¡ Medium Priority Fixes
- **CICD-008**: TODO comment para pin de `shivammathur/setup-php` a SHA
- **CICD-010**: Secret scan cambiado de `::warning::` a `exit 1` (blocking)

### ðŸ”µ Low Priority
- **CICD-013**: Warning comment en `docker-compose.ha.yml` sobre source mount
- **CICD-014**: Zero-source purge agregado a `deploy-frontend.yml`

### No aplica
- **CICD-011**: LimitaciÃ³n intencional de Next.js (API URL baked at build)
- **CICD-012**: Falso positivo â€” YAML `|` strip indentation correctamente

## [2026-03-18] â€” Correcciones AuditorÃ­a Independiente (5 hallazgos)

### Breaking Change
- **ARCH-001**: `POST /audit/single` â€” ParÃ¡metro renombrado de `FacNro` a `DisDetNro` para reflejar semÃ¡ntica real
  - Archivos modificados: `app/Controllers/AuditController.php`, `AGENTS.md`

### Fix
- **QUAL-001**: Test `AuditPersistenceServiceTest` usaba campo `hallazgo` (inexistente en schema Gemini) en vez de `detalle`
  - Archivos modificados: `tests/Services/Audit/AuditPersistenceServiceTest.php`
- **SEC-004**: `Logger::write()` sanitizaba contexto ANTES de serializar excepciones, dejando `trace` sin redactar
  - Archivos modificados: `core/Logger.php`
- **QUAL-002**: `saveToDatabase()` silenciaba errores de persistencia (void). Ahora retorna `bool`, Orchestrator loguea fallos
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditOrchestrator.php`
- **DOC-001**: README.md decÃ­a "Rate limiting por IP (archivo)" en vez de "(APCu con fallback a archivo)"
  - Archivos modificados: `README.md`

### Diferido
- SEC-001, SEC-002, SEC-003: Diferidos a sprint futuro por decisiÃ³n del usuario
- GOV-001: Cobertura de tests â€” registrado como TODO

## [2026-03-17] â€” AuditorÃ­a Independiente Fase 3 (Correcciones)

### Fix (Async Queue â€” 3 CrÃ­ticos + 4 Altos/Medios)
- **C01**: `POST /audit/async` retornaba HTTP 200 en vez de 202. `Response::success()` ahora recibe `code=202`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **C02**: Redis `allkeys-lru` podÃ­a evictar metadata de jobs activos. Cambiado a `volatile-lru`
  - Archivos modificados: `docker-compose.yml`
- **C03**: `read_write_timeout=2s` < `brpop timeout=5s` causaba crash del worker en cada iteraciÃ³n
  - Archivos modificados: `core/RedisClient.php`
- **A01**: Worker no verificaba idempotencia antes de re-auditar facturas. Agregado `getIdempotentResult()`
  - Archivos modificados: `bin/audit-worker.php`
- **A02**: Shutdown parcial marcaba job como COMPLETED. Agregado estado `STATUS_INTERRUPTED`
  - Archivos modificados: `bin/audit-worker.php`, `app/Services/Audit/AuditQueueService.php`
- **M03**: Eliminados `return` muertos despuÃ©s de `Response::error()`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **M04**: `buildOrchestrator()` se reconstruÃ­a por cada job. Ahora usa lazy-init reutilizable
  - Archivos modificados: `bin/audit-worker.php`
- **A03**: `buildOrchestrator()` duplicada entre controller y worker. Creada `AuditOrchestratorFactory`
  - Archivos creados: `app/Services/Audit/AuditOrchestratorFactory.php`
  - Archivos modificados: `app/Controllers/AuditController.php`, `bin/audit-worker.php`
- **M01**: `updateJob()` no era atÃ³mico (GET+SET). Ahora usa script Lua Redis con fallback
  - Archivos modificados: `app/Services/Audit/AuditQueueService.php`, `core/RedisClient.php`
- **M02**: Ãndice SQL referenciaba tabla inexistente `AdjuntosDispensacionDetalle`. Corregido a `AdjuntosDispensacion`
  - Archivos modificados: `database/migrations/optimize_audit_indexes.sql`
- **B01**: ValidaciÃ³n `jobId` hardcodeada a 32 chars. Ahora regex flexible `[a-f0-9]{32,64}`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **B02**: Log de `$data` en `async()` exponÃ­a `facNitSec`. Sanitizado a `***` + 3 Ãºltimos dÃ­gitos
  - Archivos modificados: `app/Controllers/AuditController.php`
- **C-NEW-02**: El worker tambiÃ©n logueaba `params` exponiendo `facNitSec` en cleartext. Sanitizado con enmascaramiento.
  - Archivos modificados: `bin/audit-worker.php`

### Fix (AuditorÃ­a v2 â€” 2 Medios + 2 Bajos)
- **M-NEW-01**: `run()` y `single()` logueaban `json_encode($data)` y `facNitSec` en cleartext. Sanitizado con enmascaramiento `***`+3 Ãºltimos dÃ­gitos, alineado con `async()`
  - Archivos modificados: `app/Controllers/AuditController.php`
- **M-NEW-02**: `queueDepth()` retornaba `0` por error Redis (indistinguible de "cola vacÃ­a"). Ahora retorna `null` si Redis no disponible
  - Archivos modificados: `app/Services/Audit/AuditQueueService.php`
- **B-NEW-01**: `AuditOrchestratorFactory` no validaba formato de `GEMINI_MODEL`. Agregada validaciÃ³n que verifica `gemini` + segmentos con guiÃ³n
  - Archivos modificados: `app/Services/Audit/AuditOrchestratorFactory.php`
- **B-NEW-02**: Worker `$auditor` no se reseteaba tras `Throwable` irrecuperable. Agregado `$auditor = null` en catch para forzar re-creaciÃ³n limpia
  - Archivos modificados: `bin/audit-worker.php`

### Docs Sync (Post-ImplementaciÃ³n)
- **DOCS-SYNC**: Actualizado `AGENTS.md` con 3 endpoints faltantes (`/audit/async`, `/audit/jobs/{jobId}`, `/audit/documents-history`), secciones Redis y AuditorÃ­a Async en catÃ¡logo de env vars, variable `GEMINI_SEED`, y nota expandida de sanitizaciÃ³n de logs
  - Archivos modificados: `AGENTS.md`
  - Verificado: `CATALOG.md`, `architecture.md`, `api-endpoints.md`, `README.md`, skills `audfact-audit-gemini` y `audfact-security-guardrails` â€” ya al dÃ­a

## [2026-03-17]

### Feature (Escalabilidad Async)
- **Ãmbito**: Sistema asÃ­ncrono de colas para auditorÃ­a IA (Fase 3)
  - Archivos modificados: `core/RedisClient.php`, `app/Services/Audit/AuditQueueService.php`, `bin/audit-worker.php`, `app/Controllers/AuditController.php`, `app/Routes/web.php`, `database/migrations/optimize_audit_indexes.sql`
  - Detalles: Se implementaron colas utilizando listas de Redis (`lpush`, `brpop`, `llen`). El nuevo modelo permite encolar la auditorÃ­a desde un backend y procesar hasta de forma concurrente desde el Worker CLI de PHP evitando el time-out HTTP al orquestar con Gemini.
  - Hito: SincronizaciÃ³n de skills P3 (Colas y Rate Limiting)


### Feature (Pipeline IA)
- **Ãmbito**: ImplementaciÃ³n de Schema DinÃ¡mico para Gemini
  - Archivos modificados: `AuditResponseSchema.php`, `GeminiGateway.php`, `AuditOrchestrator.php`, `AuditPromptBuilder.php`
  - Detalles: El pipeline de auditorÃ­a ahora extrae dinÃ¡micamente los nombres de los documentos (ej. `DISPENSA`, `FORMULA MEDICA`) directamente de la base de datos `AdjuntosDispensacion` y los inyecta en el JSON Schema de Gemini. Esto fuerza a la IA a responder con nomenclatura 100% idÃ©ntica a la BD, eliminando los fallos de conciliaciÃ³n en el modelo `AuditStatusModel` por el uso de nomenclatura SNAKE_CASE impuesta previamente.
  - Hito: SincronizaciÃ³n de skills P2.5 (Schema DinÃ¡mico).

## [2026-03-10]

### RediseÃ±o Visual Premium (Dashboard)
- **UI/UX HolÃ­stica**: Se implementÃ³ un rediseÃ±o visual completo basado en referentes de alta gama (Falcon, Label, Corona).
- **Tema Deep Navy**: Paleta de colores profesional (`oklch 0.11`) para reducir fatiga visual y mejorar contraste.
- **Micro-interacciones**: Se agregaron efectos de "glow border", elevaciÃ³n de tarjetas en hover y animaciones de entrada (`scale-in`, `shimmer`).
- **Nuevos Componentes**: KPI Cards rediseÃ±adas con gradientes duales, Dashboard Header con badges de status, y Charts con tooltips de alta fidelidad.
- **TipografÃ­a**: ImplementaciÃ³n de Inter (Display) y Outfit para una estÃ©tica moderna.

### Optimizaciones Docker & Infra
- **Fix Standalone Build**: Se habilitÃ³ `output: 'standalone'` en `next.config.ts` para permitir la creaciÃ³n correcta de imÃ¡genes Docker optimizadas.
- **Workflow de Rebuild**: Documentado el proceso de reconstrucciÃ³n para el frontend desacoplado.

### Fixes & Bug Fixes
- **KPI Alertas (Dashboard)**: Se corrigiÃ³ la lÃ³gica de `EstAud` en backend para que marque registros procesados con errores o advertencias. Se robusteciÃ³ el mapeo de estados en frontend.
- **React Hydration Mismatch (#418)**: Se eliminÃ³ el error diferiendo la renderizaciÃ³n de fechas (`new Date()`) en `DashboardHeader` hasta la etapa del cliente mediante `useEffect`.
- **NavegaciÃ³n 404 (/settings)**: Se agregÃ³ la pÃ¡gina "ConfiguraciÃ³n (En ConstrucciÃ³n)" para resolver rutas inexistentes de los menÃºs laterales y superior.

## [2026-03-07]

### MigraciÃ³n Frontend a Next.js
- **MigraciÃ³n a SPA**: Se migrÃ³ la interfaz originalmente servida como HTML renderizados estÃ¡ticamente desde PHP a una **Arquitectura Desacoplada** con Next.js (App Router).
- **Stack Frontend**: React 19, TypeScript, Tailwind CSS v4, shadcn/ui, eCharts, Lucide Icons, Zustand y React Query (TanStack).
- **Consumo de APIs**: Se creÃ³ un cliente `api.ts` estÃ¡ndar y seguro para interactuar con la API PHP existente, unificando los tipos e interfaces.


### OptimizaciÃ³n de EstÃ¡ndares (Skills)
- **AlineaciÃ³n de Endpoints**: Se formalizÃ³ el "PatrÃ³n de Endpoint EstÃ¡ndar" en la skill `audfact-api-rest`. Ahora todos los controladores deben usar `validateQuery` para capturar filtros y devolver respuestas con metadatos de paginaciÃ³n y el objeto `filters` (echo).
- **Consumo de Datos en Modelos**: Se formalizÃ³ el "PatrÃ³n de Consumo de Datos y Filtrado" en la skill `audfact-sqlsrv-models`. Los modelos ahora deben aceptar un array `$filters` inyectado desde el controlador para construir clÃ¡usulas `WHERE` dinÃ¡micas de manera consistente.
- **Workflow de GeneraciÃ³n**: Se creÃ³ el archivo `.agent/workflows/generate-endpoint.md` para guiar a los agentes en la creaciÃ³n de nuevos endpoints siguiendo estos estÃ¡ndares.
- **Impacto**: ReducciÃ³n de la deuda tÃ©cnica y garantÃ­a de una API predecible y uniforme para el frontend.

## 2026-03-09
- Fix: Implementado deep-linking en tablas de auditorÃ­a (Dashboard) inyectando estado inicial vÃ­a `useSearchParams` hacia las pÃ¡ginas `audit/history` y `audit/single`. Se eliminÃ³ la dependencia exclusiva de hooks de efecto para hidratar variables del URL.

## 2026-03-08
- Fix: Corregido el mapeo de parÃ¡metros (FacSec a NumeroFactura) en la AuditorÃ­a 1:1.
- Fix: Resuelto el renderizado vacÃ­o del modal de resultados de AuditorÃ­a 1:1 en la UI gestionando correctamente la envoltura data.data del backend y el estado de error de la IA.
## 2026-06-08

- **AudFact Core**: Normalización estructural del catálogo AudDispCampo.

- **Frontend**: Refactorización profunda de AuditConfigEditor, reemplazando constantes y selects dinámicos obsoletos por validaciones de solo lectura delegadas al backend (Catálogo de campos).

- **Backend**: Implementado endpoint GET /audit/field-catalog y optimizada la lógica en AuditConfigModel (Clean Rebuild).

- **Docs**: Actualizado plans/api-endpoints.md con nueva ruta de catálogo y reducción del payload en POST.

- **Frontend**: Reforzada la función 'Descubrir campos' (AddFieldFromDispensaDialog) para validar en tiempo real contra el Catálogo de Campos, previniendo la creación de campos huérfanos e infiriendo automáticamente sus tipos.


## 2026-06-10

- Agregado filtro "tb3.FacEst='A'" en InvoicesModel::invoiceCandidatesSql y getInvoicesForAuditBatch para evitar encolar múltiples auditorías de una misma dispensa por tener versiones anuladas activas, resolviendo registros duplicados en el pipeline y la base de datos AudDispEst. (Skill actualizada: audfact-sqlsrv-models/SKILL.md no requirió cambios, pero fue revisada).
