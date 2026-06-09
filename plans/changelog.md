# Changelog AudFact

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
  - Los hallazgos `COINCIDE` conservan el comportamiento actual y no reciben código.
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

## [2026-06-02] - Docs: Alineación del Modelo Gemini Real del Pipeline

### 📚 Documentation / IA Pipeline / Gemini
- **Verificación de uso real de modelos**:
  - Confirmado en `GeminiGateway` que todas las llamadas usan `GeminiConfig::model` en una única URL `models/{GEMINI_MODEL}:generateContent`.
  - Confirmado que `DocumentExtractionWorker` y `ArticleSemanticMatchJudge` usan perfiles de generación (`GEMINI_EXTRACTION_*`, `GEMINI_SEMANTIC_*`) sin cambiar de modelo.
  - Corregido el reporte ejecutivo para eliminar afirmaciones de fallback o redirección a `gemini-3.1-pro-preview`, que no existen en el runtime actual.

- **Documentación y skills**:
  - Sincronizados `README.md`, `plans/architecture-executive-report.md`, `CHANGELOG.md` y `audfact-audit-gemini`.

## [2026-06-02] - Fix: Worker Batch Productivo para Auditoría Async

### 🟢 Runtime / Producción LAN / Async Jobs
- **Corrección estructural de topología productiva**:
  - Agregado `worker-batch` a `docker-compose.prod.yml` usando la misma imagen PHP GHCR y el launcher canónico `php bin/audit-worker.php batch`.
  - El workflow `.github/workflows/deploy-production.yml` ahora escribe las réplicas de workers en `.env` y agrega `worker-batch` a los logs de diagnóstico de health check.
  - Producción y runtime base quedan alineados: ambos levantan los 6 servicios del pipeline (`batch`, `orchestrator`, `extraction`, `normalizer`, `policy`, `aggregator`).

- **Contexto operativo resuelto**:
  - Hallazgo PROD-BATCH-001: `/audit/async` publicaba `batch_requested` en `audit.batch.inbox`, pero producción no tenía consumer `batch-workers`, dejando jobs en `pending`, `sealed=false`, `total=0`.
  - Hallazgo PROD-GEMINI-001: se documenta que `GEMINI_API_KEY` expirada provoca errores `400 API key expired` en `worker-extraction`; debe renovarse en GitHub Environment `production` antes de validar extracción real.

- **Documentación y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/architecture.md`, `plans/docker-operations.md`, `plans/high-availability.md`, `plans/deployment-github-actions-lan.md` y skills operativas para reflejar la topología productiva corregida.

## [2026-06-02] - Fix: Alineación de Variables `.env`

### 🟢 Configuración / Seguridad / Runtime
- **Contrato único de configuración**:
  - `.env.example` queda alineado con `.env` en 92 variables activas, sin duplicados y sin valores con forma de secreto.
  - `.env` fue reestructurado desde `.env.example` preservando valores reales existentes y agregando defaults seguros para variables faltantes.
  - Se integraron variables de imágenes GHCR, publicación frontend, configuración pública Next.js, `DB2_POOLING`, réplicas async, TTL de idempotencia y recuperación de eventos `pending`.

- **Higiene de secretos**:
  - Eliminada una referencia comentada con forma de API key en `.env`.
  - Eliminado el bloque PEM de ejemplo en `GOOGLE_DRIVE_PRIVATE_KEY` de `.env.example`.

- **Documentación y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/docker-operations.md`, `plans/deployment-and-ci.md` y `audfact-runtime-docker` para reflejar el contrato actualizado.

## [2026-06-02] - Fix: Sincronización GitHub Environment Production

### 🟢 CI/CD / Seguridad / Producción LAN
- **Script estructural para Secrets/Variables**:
  - Creado `scripts/sync-github-production-env.sh` para sincronizar un `.env` productivo local hacia GitHub Environment `production` usando `gh secret set` y `gh variable set`.
  - El script valida que `.env` y `.env.example` tengan el mismo set de claves activas, detecta duplicados, no usa `source`, aborta con `bash -x`, y no imprime valores.
  - Se reemplaza el enfoque de copiar `.env` al host por una fuente persistente en GitHub; el runner regenera `/home/admon/audfact-prod/.env` en cada deploy.

- **Workflow productivo alineado**:
  - `.github/workflows/deploy-production.yml` ahora separa secretos reales (`DB_PASS`, `GEMINI_API_KEY`, `MCP_WEBHOOK_SECRET`, etc.) de variables no sensibles (`DB_HOST`, `DB_PORT`, `AUDFACT_*`, `NEXT_PUBLIC_*`, `AUDIT_*`).
  - El `.env` productivo generado conserva el contrato completo de 92 variables y elimina `GEMINI_RESPONSE_MIME`, que no pertenece a `.env.example`.
  - Se agregan variables faltantes al archivo generado: `DB2_POOLING`, `AUDIT_IDEMPOTENCY_KEY_TTL`, `AUDIT_PENDING_RECLAIM_*` y `NEXT_PUBLIC_*`.

- **Documentación y skills**:
  - Sincronizados `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/deployment-and-ci.md`, `plans/deployment-github-actions-lan.md`, `plans/docker-operations.md`, `CATALOG.md`, `catalog.json`, `audfact-runtime-docker` y `audfact-production-ops`.

## [2026-06-01] - Docs: Sincronización documental con código actual

### 📚 Documentation / API / Pipeline / Skills
- **Alineación de endpoints y contratos REST**:
  - Sincronizadas las tablas de rutas con `app/Routes/web.php` (27 rutas), incluyendo `/metrics/async`, `/clients/{clientId}/documents`, audit-config, `/audit/stats`, `/audit/status/{auditId}`, `/audit/results/{facSec}` y `/audit/{facNro}/timings`.
  - Actualizado el contrato de `/invoices`: búsqueda interactiva paginada con `page` y `pageSize`; `limit` queda restringido al batch interno de auditoría.
  - Ajustada la documentación de `/audit/async` al comportamiento real de idempotencia: `X-Idempotency-Key` opcional, autogeneración UUID y `409` con `success=true` cuando la llave ya existe.

- **Alineación de arquitectura y pipeline IA**:
  - Reescrito `plans/features/audit-workflow.md` para eliminar referencias al pipeline monolítico obsoleto y documentar el flujo event-driven actual.
  - Reescritos `plans/architecture-diagrams.md` y `plans/high-availability.md` para reflejar la topología actual con Redis Streams, workers CLI y Compose vigente, eliminando referencias a `docker-compose.dev.yml`, `docker-compose.ha.yml` y clases legacy.
  - Incorporado `document_rejected` y `DocumentIntegrityValidator` en el flujo documental: rechazo pre-Gemini, hallazgo `RECHAZADO` y `tipo_auditoria=integrity`.
  - Actualizados conteos y runtime: Next.js 15.5.15, 27 rutas, 31 archivos PHP de test, workers base `batch=2`, `orchestrator=3`, `extraction=8`, `policy=2`; se documentó que `docker-compose.prod.yml` no define `worker-batch` en su estado actual.

- **Sincronización de skills y catálogo**:
  - Agregada `audfact-docs-sync` al catálogo humano y JSON de skills, aliases y bundles.
  - Actualizadas referencias MCP y SQL Server para remover ejemplos legacy con `limit` en búsqueda interactiva y aclarar que `GetInvoices` recibe `date` y lo traduce a `dateFrom`.
  - Sincronizadas skills `audfact-runtime-docker`, `audfact-project-overview`, `audfact-audit-gemini`, `audfact-mcp-wrap` y `audfact-docs-sync` con los contratos actuales.

## [2026-05-31] - Feature: Validación Preventiva de Integridad Documental y Rechazo Temprano (Clean Rebuild)

### 🔴 Resiliencia / IA Pipeline / Integrity / Clean Rebuild
- **Introducción de `DocumentIntegrityValidator`**:
  - Implementación del nuevo servicio de integridad preventiva `DocumentIntegrityValidator` en `app/Services/Audit/Pipeline/DocumentIntegrityValidator.php`.
  - Diseñado bajo la **Clean Rebuild Policy** para interceptar adjuntos antes de su envío a Gemini. Detecta de forma proactiva archivos vacíos (0 bytes) y firmas de archivos corruptos.
  - Genera una bifurcación de flujo limpia en `DocumentExtractionWorker`. Al detectar un adjunto corrupto/vacío, no se consume API de Gemini ni se emiten datos de extracción sintéticos. En su lugar, el estado documental se marca como `rejected` en `AuditStateStore`, se incrementa `docs_rejected` y se publica el evento explícito `document_rejected`.

- **Adaptación y Consolidación del Pipeline**:
  - **`AuditStateStore`**: Soportado el registro explícito e idempotente del estado documental `rejected`, evitando inyectar lógica de negocio o placeholders en componentes intermedios.
  - **`DocumentExtractionWorker`**: Integrado el validador de integridad justo después de descargar el adjunto. Si la validación falla, se evita el consumo de Gemini y se publica `document_rejected`.
  - **`RulesEvaluationWorker`**: Consume `document_rejected` como entrada de policy, genera un `policy_result` canónico con hallazgo de severidad `alta`, resultado `RECHAZADO` y `tipo_auditoria=integrity`, y usa `docs_done + docs_rejected` para el readiness de agregación.
  - **`AuditFindingResult`**: Registrado el resultado `"RECHAZADO"` en la lista de resultados de auditoría válidos para cumplir de forma determinista con el contrato runtime de auditoría.

- **Formalización de Documentación y Skills**:
  - **`plans/architecture.md`**: Actualizado para reflejar la introducción de `DocumentIntegrityValidator` y el flujo de bifurcación de eventos del pipeline.
  - **`audfact-audit-gemini`**: Sincronizada la skill del agente para incluir la validación preventiva de integridad, la bifurcación de flujo `REJECTED` y el nuevo validador documental en la tabla de servicios clave.

## [2026-05-30] - Docs: Expansión Arquitectónica Forense, Alineación E2E, Matiz de Métricas (ROI) y Auditoría de TTL en Redis

### 📚 Documentation / Architecture / Resiliencia / Redis TTL / ROI Refinement
- **Apéndice Técnico de Persistencia en Redis (TTL)**:
  - Se realizó una auditoría forense completa de los Time-To-Live (TTL) y políticas de expiración en la capa de datos en caliente de Redis.
  - Se documentaron formalmente todos los tiempos de expiración reales en el Apéndice Técnico (sección 7) de [architecture-executive-report.md](file:///c:/Users/USER/Desktop/AudFact/plans/architecture-executive-report.md), detallando: Caché de Extracción Documental (24h), Homologación Semántica (30d), Estado Transitorio de Auditorías (24h), Estado de Batch Jobs (24h), Caché de Hash de Dispensación (24h), Barrera de Idempotencia HTTP (5min), Caché de Consultas Públicas (60s) y Distributed Locks (10s).
  - Esta formalización erradica las "cajas negras" y provee total transparencia operativa para el dimensionamiento del consumo de memoria y optimización de rendimiento bajo alta concurrencia.

- **Refinamiento de Métricas de ROI e Impacto de Negocio**:
  - Se sustituyeron las afirmaciones absolutas y categóricas ("Cero Glosas", "Reducción del 98%", "Incremento de velocidad del 400%") por aserciones estadísticas y rigurosas basadas en mitigación activa de riesgos bajo validación continua y revisión manual de casos complejos.
  - Se incorporó la métrica operativa real del auditor de **3 minutos por dispensa (hora-hombre)** como línea de base manual de comparación de tiempos, proyectando una optimización potencial de hasta el 83% gracias al pipeline asíncrono distribuidor de workers.
  - Se proyectó un ahorro potencial de costos de tokens en APIs multimodales de hasta el 85% a través del SHA256 Extraction Cache, basado en la tasa de redundancia del portafolio del cliente.

- **Formalización de los 6 Pilares de Alta Eficiencia y Resiliencia**:
  - **Redis Streams & Idempotencia (Pilar 1)**: Documentación en profundidad de la topología event-driven utilizando Redis Streams, la adquisición de bloqueos concurrentes atómicos, la re-reclamación defensiva de eventos huérfanos vía `XAUTOCLAIM` (idle > 10 min) y la política fail-closed con envío a Dead Letter Queue (`audit.dlq`).
  - **Gemini Parallel Function Calling (Pilar 2)**: Detalle del flujo de invocación de herramientas, Structured Outputs y mitigación estricta de errores HTTP `400 Bad Request` mediante el método recursivo de sanitización de esquemas JSON `normalizeSchemaProperties()` en `GeminiGateway`.
  - **Patrones de Resiliencia Industrial (Pilar 3)**: Documentación de la máquina de estados distribuida en Redis (`cb:gemini:*`) para implementar el Circuit Breaker y estrategias de Backoff Exponencial con Jitter.
  - **Modelo Híbrido de Auditoría (Pilar 4)**: Justificación del desacoplamiento entre el motor cognitivo de IA (comprensión y traducción semántica de adjuntos) y el motor determinista local en PHP (`DocumentPolicyEngine` para validaciones de leyes y normativas colombianas sin alucinaciones).
  - **Lazy Downloading en Memoria (Pilar 5)**: Detalle del consumo de adjuntos binarios mediante streams de memoria en PHP a partir de Google Drive API, evitando la I/O a almacenamiento físico en disco.
  - **Telemetría y Métricas en Cola (Pilar 6)**: Explicación de los timings acumulados del pipeline asíncrono y los metadatos almacenados de ejecución.

- **Paridad y Sincronización Dual (Protocolo `audfact-docs-sync`)**:
  - Sincronización absoluta del reporte ejecutivo del repositorio (`plans/architecture-executive-report.md`) con el reporte del brain técnico del agente (`technical_architecture_report.md`).
  - Configuración minuciosa de enrutamientos de diagramas: rutas relativas para el repositorio local y rutas absolutas compatibles con el visor de la IA en el reporte del brain.
  - Actualización de las directrices y repositorios de conocimiento en las skills `audfact-audit-gemini` y `audfact-project-overview` para reflejar la topología exacta del código.
  - Registro formal del walkthrough en el artefacto final `walkthrough.md`.


## [2026-05-29] - Fix: Incompatibilidad de placeholders nombrados en CTEs con pdo_sqlsrv

### 🟢 Bugfix / SQL / Async Jobs Stability
- **Resolución de Error `SQLSTATE[07002]`**:
  - **Incompatibilidad del Driver SQLSRV**: Corregido el fallo crítico en `InvoicesModel::getInvoicesForAuditBatch` donde el parser de parámetros de `pdo_sqlsrv` fallaba al procesar placeholders nombrados dentro de una Expresión de Tabla Común (CTE `WITH candidates AS ...`), lanzando `SQLSTATE[07002]: COUNT field incorrect or syntax error`.
  - **Refactorización a Subquery Derivada**: Reemplazada la estructura de consulta con CTE por una subquery derivada estándar (`FROM (SELECT ...) candidates`). Esto preserva el rendimiento de ejecución de la paginación keyset y la lógica semántica pero asegura compatibilidad nativa absoluta con el driver PDO de SQL Server.
  - **Estabilización de Jobs en Redis**: Al eliminar las excepciones de base de datos durante la orquestación, se previene que `AuditBatchOrchestrator` ejecute el flujo destructivo de limpieza de estado de Redis (eliminando el `jobId`), resolviendo de forma definitiva los molestos errores `404` al consultar el progreso del job.
  - **Actualización de Tests**: Sincronizada la clase `InvoicesModelTest` para validar los assertions contra la nueva sintaxis de subquery derivada. La suite completa de PHPUnit se encuentra en estado verde con paso exitoso de todas las aserciones.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizados `plans/changelog.md`, la skill principal de base de datos `.agent/skills/audfact-sqlsrv-models/SKILL.md` para añadir el guardrail de CTEs, y actualizados los artefactos de progreso.

## [2026-05-29] - Refactor: Pipeline de Auditoría Async Real e Idempotencia Absoluta

### 🔵 Architecture / High Concurrency / Idempotency
- **Erradicación del Falso Asíncrono en `POST /audit/async`**:
  - **Desacoplamiento HTTP**: El endpoint en `AuditController::async()` ya no ejecuta la costosa consulta SQL de facturas ni la orquestación en el hilo del request web. Ahora valida parámetros, obtiene/calcula la llave de idempotencia, registra el Job en estado `pending` en `BatchJobStore` y publica el evento `batch_requested` al nuevo stream `audit.batch.inbox` en Redis, respondiendo `202 Accepted` de inmediato en menos de 100 ms.
  - **Idempotencia Absoluta por Job/Batch**: Implementación de políticas rigurosas en `BatchJobStore`. Si llega una petición idéntica con el mismo hash o `X-Idempotency-Key`, se reutiliza atómicamente el job existente. Si llega con el mismo hash pero con diferentes parámetros, se aborta con `409 Conflict`, evitando colisiones y duplicación de carga bajo alta concurrencia.
  - **Procesamiento en Segundo Plano**: El nuevo worker `BatchRequestedWorker` (lanzado con `php bin/audit-worker.php batch`) consume de `audit.batch.inbox`, ejecuta la consulta pesada a SQL Server para obtener las facturas correspondientes, adquiere la reserva de idempotencia por `FacSec` en Redis y publica secuencialmente los eventos `audit_created` a `audit.inbox`.
  - **Robusted de Tests**: Removida la palabra clave `final` de la clase de prueba auxiliar `StubBatchJobStore` en `tests/Controllers/AuditControllerTest.php` para posibilitar el mockeo robusto de PHPUnit y Mockery. Las aserciones fueron actualizadas para validar el contrato asíncrono real y los payloads/streams correctos.
  - **Resultados**: Cero regresiones y paso exitoso de todas las pruebas automatizadas (100% verde).

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizados `plans/api-endpoints.md`, `plans/data-flows.md`, `plans/architecture.md`, `CHANGELOG.md` y la skill principal `.agent/skills/audfact-audit-gemini/SKILL.md` para reflejar la topología de 6 servicios y el flujo asíncrono real.

## [2026-05-25] - Refactor: Observabilidad final y escalado controlado del pipeline async


### 🔵 Architecture / Performance / Observability
- **Telemetría por evento**: `AuditEventConsumer` registra por auditoría el stream, consumer, evento, espera en cola, tiempo de ejecución del handler, tiempo de ack y estado final del evento.
  - **Recuperación de pending**: el consumer reclama periódicamente mensajes Redis Streams abandonados con `AUDIT_PENDING_RECLAIM_IDLE_MS` alto para no duplicar llamadas largas a Gemini.
  - **Estado Redis**: `AuditStateStore` agrega `event_timings` y `aggregation_timings` como parte del estado de auditoría.
  - **Timings finales**: `AuditAggregationWorker` mide construcción de agregado, persistencia SQL y cierre Redis; después de `completeAudit()` recalcula los timings con `completed_at` real y actualiza `AudDispEst` por `FacSec`.
  - **Reporte**: `AuditTimingSummarizer` conserva las métricas existentes y agrega bloques `pipeline`, `event_telemetry` y `aggregation`.
  - **Nginx runtime**: la plantilla usa resolver Docker (`127.0.0.11`) y `fastcgi_pass` por variable para no conservar IPs PHP-FPM obsoletas después de recrear réplicas.
  - **Runtime**: `docker-compose.yml` y `docker-compose.prod.yml` parametrizan réplicas iniciales: orquestadores `3`, extractores `8`, policy `2`; `.env.example` documenta reclaim idle/interval.
  - **Verificación**: tests focales verdes para consumer, state store, summarizer y aggregator.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizados `CHANGELOG.md`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/architecture.md`, `plans/docker-operations.md`, `.agent/skills/CATALOG.md`, `.agent/skills/audfact-audit-gemini/SKILL.md` y `.agent/skills/audfact-runtime-docker/SKILL.md`.

## [2026-05-25] - Fix: Identidad canónica de FDV por FacSec

### 🟢 Bugfix / API / Pipeline
- **FacSec como selector canónico E2E**: `POST /audit/single` ahora recibe `FacSec`; `DocumentAuditOrchestrator` resuelve la FDV por `facsecF` y valida `DisDetNro` únicamente como llave operativa para adjuntos y `FacNro`.
  - **Modelos**: `DispensationModel` agrega consulta explícita por `facsecF = :FacSec`, reutilizando el SELECT FDV sin duplicación.
  - **Pipeline**: `AuditDataService` expone `getDispensationByFacSec()` y el orquestador exige `payload.fac_sec` en `audit_created`.
  - **Frontend**: la auditoría individual y el deep-link desde facturas envían `FacSec`.
  - **Verificación**: PHPUnit completo verde (276 tests, 815 assertions, 10 skipped), typecheck frontend verde, dos lotes concurrentes del cliente `2426` completaron 20/20 sin nuevos DLQ.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizados `README.md`, `AGENTS.md`, `plans/api-endpoints.md`, `plans/audit-identity-contract.md`, `plans/data-flows.md`, `CHANGELOG.md`, `.agent/skills/audfact-audit-gemini/SKILL.md` y `.agent/skills/audfact-sqlsrv-models/SKILL.md`.

## [2026-05-21] - Bugfix: Normalización de Fechas Numéricas con Espacios

### 🟢 Bugfix / Refactor
- **Normalización de Fechas Numéricas**: Mejoras en `AuditFindingRules::normalizeDateToIso` para admitir formatos numéricos con separador de espacios (ej. `'25 3 2026'`) y descartar horas o minutos sufijos (incluyendo formatos AM/PM de 12 horas).
  - **AuditFindingRules**: Se implementó una limpieza robusta mediante expresiones regulares para remover horas de 12 o 24 horas y normalizar los espacios como guiones.
  - **Tests Unitarios**: Adición de múltiples casos de prueba a la suite `AuditFindingRulesNormalizationTest` cubriendo ambigüedades numéricas con espacios, años de dos dígitos y horas con formatos narrativos.
  - **Verificación**: Ejecución exitosa de la suite completa de pruebas unitarias locales (267 tests pasados, 0 regresiones).

## [2026-05-20] - Refactor: Estandarización de Contratos de Fechas (AudFact)

### 🔵 Architecture / Bugfix
- **Refactorización de Contratos de Fechas**: Se eliminó la complejidad dinámica en el manejo de fechas en `InvoicesModel` para garantizar que el contrato `dateTo` sea obligatorio en toda la cadena de ejecución, previniendo errores de comparación `NULL` en SQL Server.
  - **Modelos (`InvoicesModel`)**: Se eliminó el código muerto relacionado con la lógica de fechas dinámica y se actualizó la firma de `getInvoices` para requerir `string $dateTo`.
  - **Controladores (`InvoicesController`, `AuditController`)**: Implementación de autocompletado para `dateTo` cuando el parámetro viene vacío, usando `dateFrom`.
  - **Servicios (`AuditBatchOrchestrator`, `BatchJobStore`)**: Actualización de firmas para eliminar nulabilidad de `$dateTo` y requerir tipos estrictos en todo momento.
  - **Tests**: Reescritura completa de los tests en `InvoicesModelTest`, `InvoicesControllerTest`, y `AuditControllerTest` para validar los nuevos contratos. Se corrigió una regresión que causaba que `testResultDetailReturnsPersistedAuditDetail` fallara al no encontrar el método `resultDetail` en el controlador (se agregó nuevamente el método faltante).

## [2026-05-20] - Hotfix: Conectividad Frontend-to-Backend en Producción y server-only

### 🟢 Infrastructure / Bugfix
- **INFRA-002**: Solución definitiva a la conectividad entre el frontend Next.js y el backend PHP-FPM/Nginx en producción.
  - **Evitación del Bucle Local de Red**: Configurado enrutamiento directo contenedor-a-contenedor a través de la red interna de Docker (`http://nginx`) para llamadas SSR y componentes de servidor (RSC/SSR) en el frontend.
  - **Resolución de Error de Compilación**: Se corrigió el error estático de compilación `You're importing a component that needs "server-only" which is not supported in the pages/ directory` en `frontend/lib/api/client.ts`. Se eliminó la importación estática de `@/lib/api/server-config` en favor de la lectura dinámica inline de `process.env.INTERNAL_API_URL`.
  - **Pipeline de Secrets robustecido**: Integrado pipeline automático de GitHub Actions para inyectar `INTERNAL_API_URL=http://nginx` en el workflow de despliegue productivo y sincronización de secretos de producción locales.
  - **Archivos Modificados**: `frontend/lib/api/client.ts`, `.github/workflows/deploy-production.yml`.
  - **Verificación**: Confirmación E2E en producción vía SSH en `172.16.0.3` con respuestas exitosas (200 OK) en `/api/backend/health` y `/api/backend/clients` a través del puerto `3100`.

## [2026-05-20] - Clean Rebuild: Service Oriented Pipeline Phase 5

### 🔵 Architecture / Refactor
- **AUDIT-026**: Culminación de la refactorización arquitectónica del pipeline de auditoría orientada a servicios (Fase 5).
  - **Extracción de Lógica**: Lógica legacy extraída de `AuditFindingRules` hacia los nuevos servicios `VisualCheckEvaluator` y `FieldValueResolver`.
  - **Métricas Independientes**: Creación de `AuditTimingSummarizer` para calcular latencias en el ciclo de agregación.
  - **Limpieza de Delegados**: Eliminados métodos estáticos obsoletos `isFailureResult` y `isDiscrepancyResult` en favor del enum tipado `AuditFindingResult::tryFrom()->isFailure()`.
  - **Encapsulación Final**: Simplificación de `AuditAggregationWorker` delegando la validación del schema, normalización, severidad y latencias.
  - **Persistencia Aislada**: Simplificación de `ResponseIADiskStore`.
  - **Validación E2E**: Ejecución exitosa de la suite completa (244 tests), asegurando 0 regresiones.
  - **Archivos Modificados**: `AuditFindingRules.php`, `RulesEvaluationWorker.php`, `AuditAggregationWorker.php`, `ResponseIADiskStore.php`.
  - **Archivos Creados**: `VisualCheckEvaluator.php`, `FieldValueResolver.php`, `AuditTimingSummarizer.php`.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada `plans/architecture.md` con los nuevos servicios del orquestador. Skills y tareas actualizadas.

## [2026-05-15] - Clean Rebuild: Hardening de Pipeline y Erradicación de Legacy

### 🧹 Cleanup / Refactor
- **PIPELINE-CLEAN-REBUILD**: Ejecución estricta de `clean-rebuild-policy` en el pipeline de auditoría.
  - **Eliminación de Código Muerto**: Se removió el campo obsoleto `confidence` del schema JSON de `ArticleSemanticMatchJudge` y la lógica híbrida dependiente (`isConservativeMatch()`). Se eliminó `responseMimeType` de `GeminiConfig` al estar desfasado con Gemini 3.1. Se erradicó el estado legacy `AMBIGUOUS` de `ExtractionState`.
  - **Estabilidad de Hallazgos**: Se eliminó la keyword 'confianza' de `AuditFindingRules::observationRequiresManualReview` para evitar falsos positivos de revisión manual.
  - **Estandarización Semántica**: Se renombró el array interno `_evidencia` a `extraction_meta` en `DocumentPolicyEngine`. Se documentó claramente la diferencia entre `error` y `failed` en `AuditStateStore`.
  - **Resolución de Error Técnico**: Se corrigió un fatal error en CLI PHP 8.1 causado por una constante en `JsonRedisStoreTrait`, migrándola a `protected static string`.
  - **Sincronización de Versiones**: Las versiones por defecto de los extractores en `DocumentExtractionWorker` y `AuditEvent` se sincronizaron con `v1` (eliminando cualquier dependencia futura a `v2-identity-split`).
  - **Validación E2E**: 236 tests ejecutados, todos pasando (10 skipped), confirmando 0 regresiones.
  - **Archivos Modificados**: `GeminiConfig.php`, `ExtractionState.php`, `ArticleSemanticMatchJudge.php`, `AuditFindingRules.php`, `DocumentPolicyEngine.php`, `DocumentExtractionContractBuilder.php`, `AuditStateStore.php`, `AuditEvent.php`, `AuditDataService.php`, `DocumentExtractionWorker.php`, `JsonRedisStoreTrait.php`, `BatchJobStore.php`, `GeminiConfigTest.php`, `DocumentAuditOrchestratorTest.php`.

### Docs Sync (Post-Implementación)
- **DOCS-SYNC**: Sincronizada la documentación de `AGENTS.md` y `.env` removiendo referencias a `GEMINI_RESPONSE_MIME`. Evaluada `audfact-audit-gemini`, que se mantiene vigente con el esquema limpio de la v1.

## [2026-05-13] - Eliminación de Artefactos IA en Producción

### 🔒 Security / Infrastructure
- **INFRA-001**: Eliminación de volúmenes `responseIA/` del compose productivo.
  - **Problema**: `docker-compose.prod.yml` montaba `./responseIA:/var/www/html/responseIA` en los servicios `php`, `worker-extraction` y `worker-policy`. Aunque el código PHP (`ResponseIADiskStore`, línea 46) ya impedía la escritura en `APP_ENV !== 'development'`, Docker creaba el directorio vacío en el host al levantar los contenedores.
  - **Corrección**: Eliminados 3 mounts de volumen del compose productivo. Solo `./logs:/var/www/html/logs` persiste como mount en producción.
  - **Documentación sincronizada**: `plans/docker-operations.md` (sección Precauciones), `README.md` (sección Producción) actualizados para reflejar que `responseIA/` es exclusivo de desarrollo.
  - **Archivos modificados**: `docker-compose.prod.yml`, `plans/docker-operations.md`, `README.md`
  - **Validación**: Verificado que `responseIA/` sigue presente en `docker-compose.yml` (desarrollo), `.dockerignore` (excluido del build context) y `.gitignore`.

## [2026-05-11] - Infraestructura & Documentación Visual

### Docs / Diagrams
- **ARCH-DIAGRAMS**: Actualización completa de `plans/architecture-diagrams.md` para reflejar la arquitectura **Event-Driven** actual (C4 Model Nivel 1, 2 y 3).
- **Architecture Walkthrough**: Creación de un walkthrough interactivo con diagramas PNG generados vía `mmdc` (Mermaid CLI) para facilitar la inducción técnica.
- **Secrets Sync**: Sincronización de secretos de producción desde el entorno local `.env` hacia GitHub Secrets para habilitar el despliegue automático.

## [2026-05-11] - Skill de Operaciones de Produccion

### Docs / Ops
- **PROD-OPS-SKILL**: Creada la skill `audfact-production-ops` para que agentes accedan por SSH al servidor LAN `admon@172.16.0.3`, ejecuten diagnosticos seguros y sigan runbooks de deploy/rollback con GitHub Actions self-hosted runner.
  - **Guardrails**: La skill prohibe persistir passwords o imprimir secrets y exige aprobacion explicita para acciones con impacto.
  - **Automatizacion**: Agregado `Invoke-AudFactProdSsh.ps1`, wrapper PowerShell con OpenSSH explicito y `SSH_ASKPASS` temporal.
  - **Catalogo**: Sincronizados `CATALOG.md`, `catalog.json`, `aliases.json`, `bundles.json`, `validation-baseline.json`, `AGENTS.md` y `CLAUDE.md`.

## [2026-05-08] — Optimización: Reducción de Payload de Extracción (Gemini v1)

### ⚡ Performance / Cleanup
- **AUDIT-023**: Eliminación de metadata redundante en el motor de extracción Gemini.
  - **Poda de Schema**: Se eliminaron los campos `confianza`, `evidencia` y `ubicacion` del JSON schema generado por `DocumentExtractionContractBuilder`. Esto reduce el tamaño de la respuesta y el consumo de tokens.
  - **Simplificación DTO**: Refactorizado `ExtractedEvidence` para remover los atributos `confidence`, `justification` y `location`. El DTO ahora transporta exclusivamente la información decisional y es retrocompatible ignorando claves legacy.
  - **Limpieza de Normalización**: Removida lógica obsoleta en `DocumentNormalizer` que procesaba los campos descartados.
  - **Actualización de Código Base**: Modificados los comentarios y docblocks en `DocumentPolicyEngine` para reflejar la nueva estructura simplificada.
  - **Validación**: `DocumentNormalizerTest` actualizado para validar la ausencia de los campos eliminados (191 tests exitosos).
  - **Archivos modificados**: `DocumentExtractionContractBuilder.php`, `ExtractedEvidence.php`, `DocumentNormalizer.php`, `DocumentPolicyEngine.php`, `tests/.../DocumentNormalizerTest.php`
## [2026-05-05] — Hardening de Normalización: Cierre de Brechas Anti-Glosa (NORM-001)

### 🔒 Hardening / Bugfix
- **NORM-001**: Cierre de 3 brechas de normalización que podían generar falsos `VALOR_DISTINTO` y consecuentes glosas injustificadas.

  **Componente 1 — Tabla completa de aliases `IDENTITY_DOC_TYPE`** (`AuditFindingRules`):
  - Cobertura ampliada de ~30% a ~100% de los tipos de documento RIPS/BDUA colombianos.
  - Se cubren ahora los 11 tipos oficiales: CC, TI, CE, RC, PA, PE/PEP, PPT, MS, AS, NUIP, SC.
  - Antes: solo CC y variantes de "Cédula de Ciudadanía". Ahora: "Tarjeta de Identidad" → TI, "Cédula de Extranjería" → CE, "Pasaporte" → PA, "PEP" → PE, etc.
  - Implementado como `private const IDENTITY_DOC_ALIASES` en vez de `match` — O(1) lookup, extensible sin tocar lógica.

  **Componente 2 — `stripAccents()` determinístico**:
  - Antes: dependía exclusivamente de `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE')` — frágil en contenedores Alpine sin locale configurada.
  - Ahora: `strtr()` con tabla explícita de 40+ caracteres como estrategia primaria. `iconv` como fallback solo para caracteres Unicode exóticos fuera de la tabla.
  - Elimina la posibilidad de que acentos persistan como diferencias en la comparación textual.

  **Componente 3 — Parser de fechas narrativas en español** (`normalizeDateToIso`):
  - Nuevo método privado `parseSpanishNarrativeDate()` como fallback tras parseo numérico.
  - Soporta: "4 de mayo de 2026", "Mayo 4, 2026", "4-mayo-2026", "4 may 2026", abreviaciones estándar.
  - `checkdate()` valida que la fecha sea real (ej: "30 de febrero" → `null`).

  **Tests añadidos** (64 unitarios NORM-001 + 3 integración):
  - `tests/Services/Audit/AuditFindingRulesNormalizationTest.php` — 64 tests con DataProviders.
  - `tests/Services/Audit/Events/DocumentPolicyEngineTest.php` — 3 tests end-to-end NORM-001.
  - Resultado: 94/94 ✅, cero regresiones en tests preexistentes.

  **Archivos modificados**: `AuditFindingRules.php`.
  **Archivos creados**: `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.


## [2026-05-04] — Depuración: Código Muerto y Drift Documental (ARCH-002)

### 🧹 Cleanup
- **ARCH-002**: Eliminación de código muerto y corrección de drift documental en `plans/architecture.md`.
  - **Archivo eliminado**: `ClientConfigurationService.php` — fachada hueca sin consumidores (0 imports en todo el proyecto). Era un pass-through 1:1 sobre `AuditConfigModel`, creado en AUDIT-022 pero nunca integrado en el pipeline ni en controllers.
  - **Drift corregido en `architecture.md`**: Eliminada referencia fantasma a `GeminiCircuitBreaker.php` (circuit breaker fue inlineado en `GeminiGateway` en AUDIT-013). Agregada referencia faltante a `GeminiCallMetrics.php`. Corregida ruta `Debug/ResponseIADiskStore.php` → `ResponseIADiskStore.php` (el subdirectorio `Debug/` nunca existió).
  - **Archivos eliminados**: `app/Services/Audit/ClientConfigurationService.php`
  - **Archivos modificados**: `plans/architecture.md`


## [2026-05-03] — Clean Controller: Delegación de Orquestación y Configuración (AUDIT-022)

### 🔵 Architecture / Refactor
- **AUDIT-022**: Refactorización de `AuditController` hacia el patrón *Thin Controller*, delegando la lógica de negocio a servicios especializados.
  - **`AuditBatchOrchestrator`**: Creado para encapsular el encolamiento asíncrono, la reserva de slots concurrentes en Redis (`BatchJobStore`), la inicialización del estado (`AuditStateStore`) y el rollback transaccional en caso de fallos de persistencia.
  - **`ClientConfigurationService`**: Creado para abstraer la consolidación dinámica de la configuración de auditoría (mezcla de campos hardcodeados y visuales de la DB) y su persistencia.
  - **Resultado**: El controlador `AuditController` redujo su tamaño y complejidad drásticamente (de 614 a 427 líneas). Las responsabilidades transaccionales ahora residen en clases testeables e independientes.
  - **Fix adicional**: Corregido bug en `InvoicesModelTest` donde las pruebas fallaban al depender de lógica condicional obsoleta (`$dateConditionD`).
  - **Validación**: 100% verde (174/174 tests, 568 assertions).
  - **Archivos creados**: `AuditBatchOrchestrator.php`, `ClientConfigurationService.php`.
  - **Archivos modificados**: `AuditController.php`, `AuditControllerTest.php`, `InvoicesModel.php`, `InvoicesModelTest.php`.

## [2026-05-03] — Clean Rebuild: Erradicación de Legacy en Pipeline (AUDIT-021)

### 🧹 Cleanup / Refactor
- **AUDIT-021**: Eliminación completa de compatibilidad retroactiva legacy en la capa de normalización y políticas, asumiendo un flujo estrictamente "shape v1" determinista.
  - **`DocumentNormalizer`**: Eliminado el comportamiento híbrido en `normalizeFieldWithLog` y borrado de la lógica y logs de `legacy_scalar_wrapped_v1`. Se simplificó `isEmptyRow` asumiendo arrays `['valor']`.
  - **`DocumentPolicyEngine`**: Eliminado el método `unwrapV1()` híbrido, haciendo acceso directo a los arrays v1 inyectados en la extracción.
  - **Resultado**: Código más limpio, directo y libre de capas de traducción ("por si acasos"), ciñéndose al MVP. Se eliminó la capacidad de procesar payloads antiguos, lo cual es correcto dado que el contrato actual de Gemini siempre devuelve v1.
  - **Validación**: Los tests `GoldenSetReplayTest` (así como unitarios de `DocumentNormalizer` y `DocumentPolicyEngine`) fueron adecuados y pasaron exitosamente.
  - **Archivos modificados**: `DocumentNormalizer.php`, `DocumentPolicyEngine.php`, `DocumentNormalizerTest.php`, `DocumentPolicyEngineTest.php`, `golden_D65260408592.json`.

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
# #   2 0 2 6 - 0 6 - 0 8  
 -   * * A u d F a c t   C o r e * * :   N o r m a l i z a c i � n   e s t r u c t u r a l   d e l   c a t � l o g o   A u d D i s p C a m p o .  
 -   * * F r o n t e n d * * :   R e f a c t o r i z a c i � n   p r o f u n d a   d e   A u d i t C o n f i g E d i t o r ,   r e e m p l a z a n d o   c o n s t a n t e s   y   s e l e c t s   d i n � m i c o s   o b s o l e t o s   p o r   v a l i d a c i o n e s   d e   s o l o   l e c t u r a   d e l e g a d a s   a l   b a c k e n d   ( C a t � l o g o   d e   c a m p o s ) .  
 -   * * B a c k e n d * * :   I m p l e m e n t a d o   e n d p o i n t   G E T   / a u d i t / f i e l d - c a t a l o g   y   o p t i m i z a d a   l a   l � g i c a   e n   A u d i t C o n f i g M o d e l   ( C l e a n   R e b u i l d ) .  
 -   * * D o c s * * :   A c t u a l i z a d o   p l a n s / a p i - e n d p o i n t s . m d   c o n   n u e v a   r u t a   d e   c a t � l o g o   y   r e d u c c i � n   d e l   p a y l o a d   e n   P O S T .  
 -   * * F r o n t e n d * * :   R e f o r z a d a   l a   f u n c i � n   ' D e s c u b r i r   c a m p o s '   ( A d d F i e l d F r o m D i s p e n s a D i a l o g )   p a r a   v a l i d a r   e n   t i e m p o   r e a l   c o n t r a   e l   C a t � l o g o   d e   C a m p o s ,   p r e v i n i e n d o   l a   c r e a c i � n   d e   c a m p o s   h u � r f a n o s   e   i n f i r i e n d o   a u t o m � t i c a m e n t e   s u s   t i p o s .  
 