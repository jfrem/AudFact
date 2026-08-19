## [2026-08-19]

### feat
- **Alineación Oficial con Gemini 3.7 Flash y Resolución Multimodal**: Se actualizó el modelo por defecto en `GeminiConfig` a `gemini-3.7-flash` y se habilitó la inyección condicional de `mediaResolution` (`MEDIA_RESOLUTION_HIGH` / `MEDIA_RESOLUTION_MEDIUM`) exclusivamente en perfiles multimodales de extracción (`TASK_EXTRACTION`). Se reforzaron las descripciones JSON Schema en `AuditFieldValueType` para `AUTH_NUMBER` y `NIT` instruyendo lectura posicional estricta dígito a dígito y prevención de ambigüedad visual entre caracteres ($8 \leftrightarrow 6 \leftrightarrow 5 \leftrightarrow 0 \leftrightarrow 9$).
  - Archivos creados/modificados: `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/GeminiConfig.php`, `.env`, `.env.example`, `AGENTS.md`, `plans/gemini-alignment-sdd.md`, `tests/Services/Audit/AuditFieldValueTypeTest.php`, `tests/Services/Audit/GeminiConfigTest.php`.
  - Impacto: Máxima fidelidad en la transcripción de números de autorización y NITs en documentos de soporte, erradicando discrepancias OCR de un solo dígito sin generar sobrecargas ni errores 400 en llamadas semánticas.

### fix
- **Exclusión de Adjuntos Opcionales en Cálculo de Pendientes (`InvoicesModel`)**: Se corrigió el cálculo de `EstSop` en la tabla temporal `#Sopo` dentro de `InvoicesModel::buildOptimizedBatchSql` para evaluar exclusivamente los adjuntos con `AdjDisOpc = 'N'` (obligatorios).
  - Archivos modificados: `app/Models/InvoicesModel.php`.
  - Hallazgo resuelto: En clientes como `2624` con documentos opcionales configurados (ej. `TESTIGO A RUEGO` con `AdjDisOpc = 'S'`), los adjuntos no requeridos permanecían en estado `'P'` (Pendiente), provocando que `Min(case a.AdjDisEstSop ...)` siempre devolviera `0`. Esto causaba que el orquestador de lotes considerara falsamente que la dispensa seguía pendiente y reauditara repetidamente las mismas 100 facturas.
  - Impacto: Los lotes asíncronos ahora avanzan progresivamente hacia nuevas facturas pendientes en todos los clientes, independientemente de si poseen adjuntos opcionales.

## [2026-08-13]

### feat
- **Normalización de Abreviaciones de Meses en Fechas (`sept`, `set`, `mzo`, etc.)**: Se amplió el mapeo de nombres de meses en `AuditFindingRules::parseSpanishNarrativeDate` para soportar variantes como `sept`, `set`, `setiembre`, `mzo`, `agt`, `novb`, `dicb`. Permite normalizar fechas como `29-sept-2025` a `2025-09-29` de forma determinista y sin falsos positivos de auditoría.
  - Archivos modificados: `app/Services/Audit/AuditFindingRules.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.
  - Impacto: Fechas narrativas y con abreviaciones observadas en documentos médicos/fórmulas/actas coinciden exactamente con los registros ISO de base de datos.

### refactor
- **Desacoplamiento e Integridad Interna Data-Driven (Clean Rebuild)**: Se extrajo la evaluación de consistencia interna de base de datos (`NEntrega` vs `MipresNoEntrega`) fuera de `DocumentPolicyEngine` hacia `InternalIntegrityEvaluator`. Se formalizó el caso `AuditComparisonType::INTERNAL` para campos con `tipoCampo = 'I'`. `DocumentPolicyEngine` ahora filtra genéricamente sin conocer nombres de campo hardcodeados.
  - Archivos creados/modificados: `app/Services/Audit/Pipeline/InternalIntegrityEvaluator.php`, `app/Services/Audit/AuditComparisonType.php`, `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `tests/Services/Audit/Pipeline/InternalIntegrityEvaluatorTest.php`.
  - Impacto: Cero referencias a nombres de campos específicos en `DocumentPolicyEngine`; arquitectura extensible para validaciones de integridad interna sin modificar el motor de políticas.

### fix
- **Corrección de Mapeo de Columna MIPRES en `DispensationModel`**: Se corrigió el alias de columna de `mip.DatMipEntNoEntrega` a `mip.DatMipDirNoEnt AS MipresNoEntrega` en la consulta principal de `DispensationModel::getDispensationDetails`.
  - Archivos modificados: `app/Models/DispensationModel.php`, `plans/database-schema.md`.
  - Impacto: Permite evaluar correctamente el campo de consistencia interna `MipresNoEntrega` sin errores de columna inexistente en base de datos.
- **Precisión de Extracción IA en Documentos Soporte y Orientación (OCR Gemini)**: Se reforzó el prompt del sistema (`DEFAULT_SYSTEM_PROMPT`) y el prompt del usuario en `ExtractionPromptBuilder` con directrices explícitas para documentos rotados o invertidos (180°), lectura en sentido natural de izquierda a derecha, y transcripción posicional dígito por dígito de identificadores numéricos (cédulas, IDs, autorizaciones) distinguiendo minuciosamente caracteres ambiguos (`6 ↔ 8 ↔ 4 ↔ 0 ↔ 9`, `5 ↔ 8 ↔ 6`, `8 ↔ B ↔ 5 ↔ 3 ↔ 6 ↔ 0`). Se optimizaron las descripciones en el schema JSON en `DocumentExtractionContractBuilder` (`identity_doc_number`) y se creó la suite unitaria `ExtractionPromptBuilderTest`.
  - Archivos creados/modificados: `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php`, `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`, `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php`, `plans/gemini-extraction-accuracy-sdd.md`.
  - Hallazgo resuelto: Dispensa `D64260800214` reprobaba `FORMULA MEDICA` (orientada a 180°) por confusión OCR en `DocumentoPaciente` (`1115580646` vs `1115860646`) y `FechaFormula` (`2024-08-01` vs `2026-08-01`).
  - Impacto: Extracción 100% fiel al soporte físico con `gemini-3.6-flash`, eliminando falsos positivos en cédulas y fechas en todas las modalidades documentales sin alterar la política determinista de comparación.
- **Resolución de "Duplicate React Keys" en lista de adjuntos**: Se refactorizó el mapeo en `AttachmentList` para incluir explícitamente el índice del arreglo (`keyId = ${id}-${index}`) y así evitar conflictos de hidratación en React cuando múltiples documentos referencian el mismo `id_adjunto_fisico`.
  - Archivos modificados: `frontend/components/attachments/attachment-list.tsx`.
  - Hallazgo resuelto: Error en consola `Encountered two children with the same key` que causaba inestabilidad en la UI al seleccionar documentos.
  - Impacto: Navegación e iteración de adjuntos 100% estable.
- **Afinamiento de prompts para falsos positivos de la IA**: Se actualizaron las descripciones (prompts) en base de datos (`AudDispCampoCatalogo`) para `CodigoProducto` y `FirmaActaEntrega`, indicándole a Gemini que priorice explícitamente el "COD AUT" sobre el CUM, y que acepte firmas manuscritas informales (nombres, cédulas, huellas) como evidencias válidas de recepción.
  - Archivos modificados: Configuración productiva en base de datos (`Discolnet.dbo.AudDispCampoCatalogo`) vía API.
  - Hallazgo resuelto: El modelo reprobaba erróneamente actas de entrega correctas al extraer códigos secundarios u omitir firmas humanas desestructuradas.
  - Impacto: Los resultados de IA para estos documentos retornan `COINCIDE` con un razonamiento trazable y libre de falsos positivos en los chequeos visuales.

## [2026-07-28]
### fix
- **Normalización numérica en campos de texto (0 == .00)**: `AuditFindingRules::normalizeForComparison` ahora normaliza automáticamente valores numéricos escalares (`0`, `.00`, `0.00`, `1,500.00`) cuando el campo se compara como texto (`default`/`TEXT`), eliminando falsos positivos de formato entre el valor documental (`'0'`) y el registro monetario en BD (`'.00'`).
  - Archivos modificados: `app/Services/Audit/AuditFindingRules.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.
  - Impacto: Campos monetarios o de saldos configurados como `text` comparan equivalencia numérica sin generar discrepancias de formato.
- **Tipo de dato `NIT` para normalización de dígito de verificación**: Nuevo `AuditFieldValueType::NIT` que elimina el sufijo `-X` (dígito de verificación) y separadores de miles en NITs colombianos, resolviendo falsos positivos donde Gemini extrae `828002423-5` pero la FDV contiene `828002423`.
  - Archivos modificados: `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/AuditFindingRules.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.
  - Acción requerida: Cambiar `tipoDato` del campo `DIS` (NITDiscolmets) de `text` a `nit` en la audit-config del cliente.
- **Tipo de dato `auth_number` para eliminación de prefijos en autorizaciones**: Nuevo `AuditFieldValueType::AUTH_NUMBER` (`auth_number`) que elimina prefijos separados por guion (ej. `0746-365230818` → `365230818`) sin afectar números puros (ej. `49547343` del cliente 2426).
  - Archivos modificados: `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/AuditFindingRules.php`, `app/Services/Audit/Pipeline/DocumentNormalizer.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`.
  - Acción requerida: Cambiar `tipoDato` del campo `AUT` (NumeroAutorizacion) de `text` a `auth_number` en el catálogo o audit-config del cliente.

## [2026-06-12]

### fix
- **Alineación limpia de resultados por `FacNro`**: `AuditBatchOrchestrator` consulta auditorías persistidas por `FacNro` sin restaurar compatibilidad legacy por `DisId`; se mantiene la reserva Redis por `DisId`.
  - Archivos modificados: `app/Services/Audit/AuditBatchOrchestrator.php`, `app/Models/migration/migration_AudDispEst_updated.sql`, `tests/Services/Audit/AuditBatchOrchestratorTest.php`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/Events/AuditAggregationWorkerTest.php`, `README.md`, `AGENTS.md`, `plans/*`, `.agent/skills/audfact-*`.
  - Hallazgos resueltos: QUAL-001, QUAL-002, QUAL-003.
  - Impacto: el flujo async ya no invoca el detalle legacy por `DisId`; el contrato activo queda alineado con `AudDispEst.FacNro` como PK productiva.

## [2026-06-09]

### ui
- **Refactorización UI/UX "Pro Max"**: Se actualizaron componentes visuales (`button`, `card`, `input`, `select`, `table`) para cumplir lineamientos Impeccable (feedback táctil `active:scale`, transiciones hover fluidas, *glassmorphism* vía `.panel`), manteniendo la política "Clean Rebuild" al limpiar código no utilizado.
  - Archivos modificados: `frontend/components/ui/button.tsx`, `frontend/components/ui/card.tsx`, `frontend/components/ui/input.tsx`, `frontend/components/ui/select.tsx`, `frontend/components/ui/table.tsx`.
  - Hallazgo resuelto: Feedback visual pobre en controles interactivos y redundancia en estilos de tarjetas.
  - Impacto: Tarjetas unificadas visualmente en toda la app sin repetición de CSS, tablas con seguimiento visual optimizado y foco (focus rings) suave y accesible.

### refactor
- **Erradicación del "Efecto Banner" (Clean Rebuild)**: Se sustituyeron los esqueletos de carga (`BackendRequestSkeleton`) anexados condicionalmente al final de las UIs por renderizados sustitutivos. En los formularios de filtrado, se confía en el `loading` del botón y en la transición nativa de Next.js, evitando mostrar tablas duplicadas fantasma.
  - Archivos modificados: `frontend/components/audit/audit-config-editor.tsx`, `frontend/components/audit/audit-config-page-client.tsx`, `frontend/components/clients/clients-filter-form.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/audit/documents-history-filter-form.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/app/(dashboard)/observability/page.tsx`, `frontend/components/shared/pending-pagination-controls.tsx`, `frontend/components/invoices/invoices-table.tsx`.
  - Hallazgo resuelto: Los esqueletos se acumulaban o mostraban contenido obsoleto debajo de los formularios.
  - Impacto: Cumplimiento de WYSIWYG, las interfaces de usuario reemplazan su contenido y el enrutador recarga las vistas con datos frescos.


### fix
- **Worker batch productivo para auditoría async**: `docker-compose.prod.yml` ahora levanta `worker-batch` con `php bin/audit-worker.php batch`, y el workflow de despliegue genera las variables de réplicas de workers e incluye `worker-batch` en diagnósticos.
  - Archivos modificados: `docker-compose.prod.yml`, `.github/workflows/deploy-production.yml`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/architecture.md`, `plans/docker-operations.md`, `plans/high-availability.md`, `plans/deployment-github-actions-lan.md`, `plans/changelog.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-production-ops/references/runbooks.md`
  - Hallazgo resuelto: PROD-BATCH-001.
  - Impacto: `/audit/async` ya no queda en `pending` por falta de consumidor de `audit.batch.inbox` en producción.
  - Nota operativa: renovar `GEMINI_API_KEY` en GitHub Environment `production` antes de validar extracciones; producción ya registró `400 API key expired` en `worker-extraction`.
- **Alineación de `.env` y `.env.example`**: `.env.example` queda como contrato limpio de 92 variables activas sin secretos ni private key de ejemplo, y `.env` fue reestructurado con el mismo set de claves preservando valores locales reales.
  - Archivos modificados: `.env`, `.env.example`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/docker-operations.md`, `plans/deployment-and-ci.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`
  - Hallazgo resuelto: ENV-DRIFT-001, ENV-HYGIENE-001, ENV-SECRET-TEMPLATE-001.
  - Impacto: configuración local, template y deploy quedan alineados para workers async, GHCR, frontend público, DB2 pooling y `NEXT_PUBLIC_*`.
- **Sincronización de Environment GitHub production**: nuevo script Bash para subir `.env` productivo a GitHub Secrets/Variables y workflow de deploy ajustado para generar `.env` completo desde ese Environment.
  - Archivos modificados: `scripts/sync-github-production-env.sh`, `.github/workflows/deploy-production.yml`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/deployment-and-ci.md`, `plans/deployment-github-actions-lan.md`, `plans/docker-operations.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `.agent/skills/audfact-runtime-docker/SKILL.md`, `.agent/skills/audfact-production-ops/SKILL.md`, `.agent/skills/audfact-production-ops/references/runbooks.md`
  - Hallazgo resuelto: PROD-SECRETS-001.
  - Impacto: producción recibe `.env` actualizado en cada deploy sin copiar secretos directamente al host.

### docs
- **Alineación del modelo Gemini real del pipeline**: se verificó que el gateway usa un único selector de modelo (`GEMINI_MODEL`) para extracción y homologación; los prefijos `GEMINI_EXTRACTION_*` y `GEMINI_SEMANTIC_*` solo ajustan parámetros de generación.
  - Archivos modificados: `README.md`, `plans/architecture-executive-report.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `CHANGELOG.md`, `plans/changelog.md`
  - Hallazgo resuelto: drift documental que describía un fallback o redirección inexistente a `gemini-3.1-pro-preview`.
  - Impacto: documentación y skill del pipeline ya no inducen a buscar múltiples versiones Gemini cuando el runtime usa un único modelo configurado por entorno.

## [2026-06-01]

### docs
- **Sincronización documental con el estado actual del código**: se alinearon endpoints, contratos `/invoices` y `/audit/async`, flujo `document_rejected`, conteos de rutas/tests/workers, versión real de Next.js y catálogo de skills.
  - Archivos modificados: `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/api-endpoints.md`, `plans/architecture.md`, `plans/architecture-diagrams.md`, `plans/data-flows.md`, `plans/database-schema.md`, `plans/docker-operations.md`, `plans/high-availability.md`, `plans/features/audit-workflow.md`, `plans/features/mcp-integration.md`, `plans/overview.md`, `plans/testing-strategy.md`, `plans/changelog.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `.agent/skills/aliases.json`, `.agent/skills/bundles.json`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-docs-sync/SKILL.md`, `.agent/skills/audfact-mcp-wrap/SKILL.md`, `.agent/skills/audfact-mcp-wrap/references/examples.md`, `.agent/skills/audfact-project-overview/SKILL.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`, `.agent/skills/audfact-sqlsrv-models/SKILL.md`, `.agent/skills/audfact-sqlsrv-models/references/examples.md`, `.agent/skills/audfact-sqlsrv-models/references/test-cases.md`
  - Hallazgo resuelto: drift documental posterior a paginación de facturas, rechazo pre-Gemini, endpoint de estado Redis y runtime frontend.
  - Impacto: agentes y humanos consultan documentación coherente con el código vigente.

### refactor
- **Paginación real en búsqueda de facturas**: `/invoices` deja de exponer `limit` y usa contrato paginado uniforme con `items`, `total`, `page`, `pageSize`, `totalPages` y `filters`; la UI de facturas muestra selector de tamaño de página, paginador y skeleton durante cada búsqueda o cambio de página.
  - Archivos modificados: `app/Controllers/InvoicesController.php`, `app/Models/InvoicesModel.php`, `app/wrap/core/tools/GetInvoices.php`, `app/wrap/capabilities.php`, `frontend/app/(dashboard)/invoices/page.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/invoices/invoices-table.tsx`, `frontend/lib/api/audfact.ts`, `frontend/lib/schemas/domain.ts`, `tests/Controllers/InvoicesControllerTest.php`, `tests/Models/InvoicesModelTest.php`, `plans/api-endpoints.md`, `.agent/skills/audfact-mcp-wrap/SKILL.md`, `.agent/skills/audfact-sqlsrv-models/SKILL.md`
  - Hallazgo resuelto: `/invoices` solo limitaba resultados con `SELECT TOP` y no permitía navegar páginas reales.
  - Impacto: los usuarios pueden recorrer facturas por páginas estables y los consumidores internos quedan alineados al contrato estándar de listas.

### fix
- **Render SSR estable en shell del dashboard**: evita que componentes con `asChild` entreguen contenido ambiguo a Radix `Slot`, manteniendo un único elemento hijo en botones renderizados como child y en el cierre del menú mobile.
  - Archivos modificados: `frontend/components/ui/button.tsx`, `frontend/components/layout/app-sidebar.tsx`
  - Hallazgo resuelto: Next.js caía a client rendering por `React.Children.only expected to receive a single React element child`.
  - Impacto: el dashboard conserva render server estable y el menú mobile mantiene el mismo comportamiento de apertura/cierre.

## [2026-05-31]

### feat
- **Skeletons contextuales para peticiones backend del frontend**: las acciones de búsqueda, navegación, paginación, auditoría, configuración, observabilidad y carga de adjuntos muestran feedback inmediato con skeletons accesibles mientras el backend responde.
  - Archivos modificados: `frontend/components/ui/button.tsx`, `frontend/components/shared/backend-request-skeleton.tsx`, `frontend/lib/hooks/use-pending-navigation.ts`, `frontend/components/shared/pending-pagination-controls.tsx`, `frontend/components/shared/pagination.tsx`, `frontend/components/clients/clients-filter-form.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/invoices/invoices-table.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/audit/documents-history-filter-form.tsx`, `frontend/components/audit/audit-single-console.tsx`, `frontend/components/audit/audit-batch-console.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/components/audit/add-field-from-dispensa-dialog.tsx`, `frontend/components/audit/audit-config-editor.tsx`, `frontend/components/audit/audit-config-page-client.tsx`, `frontend/components/audit/client-selector.tsx`, `frontend/components/jobs/job-tracker.tsx`, `frontend/components/jobs/job-detail-client.tsx`, `frontend/components/attachments/attachment-viewer-panel.tsx`, `frontend/app/(dashboard)/dispensation/page.tsx`, `frontend/app/(dashboard)/observability/page.tsx`
  - Hallazgo resuelto: ausencia de feedback visual consistente en botones y flujos que esperan respuesta del backend.
  - Impacto: el usuario ve que su solicitud sigue en proceso, los botones conservan estado ocupado accesible y se reduce la percepción de bloqueo en consultas lentas.

### fix
- **Rechazo temprano robusto de documentos no procesables**: el pipeline de auditoría separa la validación de integridad pre-Gemini (`DocumentIntegrityValidator`) de la evaluación funcional del rechazo. El extractor publica `document_rejected` sin invocar Gemini ni cachear extracción, y `RulesEvaluationWorker` genera el hallazgo canónico `RECHAZADO` con `tipo_auditoria=integrity`, permitiendo que `rules_evaluated` se publique sin bloquear el readiness de la auditoría.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentIntegrityValidator.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/Pipeline/AuditStateStore.php`, `app/Services/Audit/Pipeline/AuditEvent.php`, `app/Services/Audit/Pipeline/AuditEventPublisher.php`, `app/Services/Audit/AuditFindingResult.php`, `tests/Services/Audit/Events/DocumentIntegrityValidatorTest.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`, `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php`, `tests/Services/Audit/Events/AuditStateStoreTest.php`, `tests/Services/Audit/Events/AuditEventPublisherTest.php`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/CATALOG.md`, `plans/architecture.md`, `plans/changelog.md`
  - Hallazgo resuelto: bloqueo potencial del pipeline por documentos rechazados sin evento de policy.
  - Impacto: los adjuntos vacíos, corruptos o con MIME inconsistente quedan auditados como hallazgos de integridad sin consumir tokens de Gemini ni dejar auditorías pendientes.
- **Validación robusta de query params en `GET /invoices`**: el endpoint valida los valores crudos de `facNitSec`, `dateFrom`, `dateTo` y `limit` antes de castear, evitando que entradas inválidas como `limit=abc` o `facNitSec=abc` se conviertan silenciosamente a enteros.
  - Archivos modificados: `app/Controllers/InvoicesController.php`, `tests/Controllers/InvoicesControllerTest.php`
  - Hallazgo resuelto: validación débil por casteo previo de query params.
  - Impacto: el endpoint rechaza solicitudes malformadas con `422` antes de consultar SQL Server.
- **Alineación robusta del frontend de facturas con `/invoices`**: la búsqueda de facturas valida cliente, fecha inicial, rango y límite antes de navegar, muestra errores del backend en vez de convertirlos en listas vacías y usa `limit=100` como default compartido con el backend.
  - Archivos modificados: `frontend/app/(dashboard)/invoices/page.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/invoices/invoices-table.tsx`, `frontend/components/audit/client-selector-combo.tsx`, `frontend/lib/api/audfact.ts`, `frontend/lib/api/errors.ts`
  - Hallazgo resuelto: frontend permitía búsquedas incompletas y ocultaba errores `422` como ausencia de resultados.
  - Impacto: el usuario recibe validación local clara y los errores reales de API quedan visibles sin ejecutar consultas inválidas.

## [2026-05-29]

### refactor
- **Pipeline de Auditoría Async Real e Idempotencia Absoluta**: Desacoplamiento HTTP express real del endpoint `POST /audit/async` que ahora solo valida, registra el job y encola `batch_requested` en menos de 100ms. El procesamiento en segundo plano lo asume `BatchRequestedWorker` (`php bin/audit-worker.php batch`), consumiendo de `audit.batch.inbox`, consultando SQL Server, reservando idempotencia por `FacSec` en Redis y publicando `audit_created` en `audit.inbox`. Implementación de validaciones atómicas robustas para reutilización y prevención de colisiones en `BatchJobStore`, y eliminación de `final` en `StubBatchJobStore` para posibilitar mocks en tests.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/Services/Audit/Pipeline/BatchJobStore.php`, `app/Services/Audit/Pipeline/BatchRequestedWorker.php`, `tests/Controllers/AuditControllerTest.php`, `plans/architecture.md`, `plans/data-flows.md`, `plans/changelog.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`
  - Hallazgo resuelto: falso asíncrono y bloqueo síncrono del backend web por procesamiento largo de consultas en el hilo HTTP.
  - Impacto: respuesta inmediata de encolado (<100ms) con concurrencia robusta de batches paralelos e idempotencia a nivel de `FacSec`.

## [2026-05-25]

### refactor
- **Observabilidad real y escalado controlado del pipeline async**: registra telemetría por evento (`queue_wait`, `handle`, `ack`) en Redis, recupera periódicamente mensajes pendientes abandonados, recalcula timings finales después de `completed_at`, evita upstreams PHP-FPM obsoletos en Nginx y parametriza réplicas de workers sin hardcodear capacidad.
  - Archivos modificados: `app/Services/Audit/Pipeline/AuditEventConsumer.php`, `app/Services/Audit/Pipeline/AuditStateStore.php`, `app/Services/Audit/Pipeline/AuditTimingSummarizer.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`, `app/Models/AuditStatusModel.php`, `docker/nginx-ha.conf.template`, `docker/nginx.conf`, `docker-compose.yml`, `docker-compose.prod.yml`, `.env.example`, `tests/Services/Audit/Events/AuditEventConsumerTest.php`, `tests/Services/Audit/Events/AuditStateStoreTest.php`, `tests/Services/Audit/Events/AuditAggregationWorkerTest.php`, `tests/Services/Audit/Pipeline/AuditTimingSummarizerTest.php`
  - Hallazgo resuelto: latencia alta no atribuible por falta de métricas de cola/worker/persistencia.
  - Impacto: permite distinguir backlog Redis, procesamiento Gemini, persistencia SQL y cierre Redis sin romper idempotencia por `FacSec`.

### fix
- **Identidad canónica de FDV por FacSec**: `/audit/single` recibe `FacSec`, el pipeline resuelve la fuente de verdad por `facsecF` y valida `DisDetNro` solo como llave operativa de adjuntos.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/Models/DispensationModel.php`, `app/Services/Audit/AuditBatchOrchestrator.php`, `app/Services/Audit/Pipeline/AuditDataService.php`, `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `frontend/lib/api/audfact.ts`, `frontend/lib/schemas/domain.ts`, `frontend/components/audit/audit-single-console.tsx`, `frontend/components/invoices/invoices-table.tsx`, `tests/Controllers/AuditControllerTest.php`, `tests/Models/DispensationModelTest.php`, `tests/Services/Audit/Events/DocumentAuditOrchestratorTest.php`
  - Hallazgo resuelto: selección ambigua de FDV cuando un mismo `DisDetNro` apunta a más de un `FacSec`.
  - Impacto: evita `AUDIT_IDENTITY_MISMATCH` por consultar la FDV equivocada y mantiene idempotencia/trazabilidad por factura.

## [2026-05-24]

### refactor
- **Idempotencia global y concurrencia real en auditoría async**: reemplaza el bloqueo por lote/documento por reservas Redis con owner token por `FacSec`, aplica la misma idempotencia a `/audit/single`, sella jobs antes de publicar eventos y cierra auditorías que caen a DLQ.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/Services/Audit/AuditBatchOrchestrator.php`, `app/Services/Audit/Pipeline/BatchJobStore.php`, `app/Services/Audit/Pipeline/AuditStateStore.php`, `app/Services/Audit/Pipeline/AuditEventConsumer.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`, `app/Models/InvoicesModel.php`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/Events/AuditAggregationWorkerTest.php`, `tests/Models/InvoicesModelTest.php`
  - Hallazgo resuelto: bloqueo por cliente/rango, locks huérfanos ante DLQ e idempotencia incompleta entre auditoría individual y batch.
  - Impacto: múltiples batches del mismo cliente pueden avanzar en paralelo sobre `FacSec` distintos sin duplicar auditorías activas ni dejar jobs pendientes por fallos terminales.

## [2026-05-23]

### refactor
- **Métricas limpias de latencia async**: separa espera en cola, procesamiento activo y tiempo total; hace idempotente `started_at`, centraliza el cálculo de duración y evita persistir `JobId` sin columna documentada en `AudDispEst`.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/Services/Audit/Pipeline/AuditStateStore.php`, `app/Services/Audit/Pipeline/AuditTimingSummarizer.php`, `app/Services/Audit/Pipeline/BatchJobStore.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`, `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Models/AuditStatusModel.php`, `frontend/lib/schemas/domain.ts`, `frontend/components/audit/audit-timings-panel.tsx`, `frontend/components/jobs/job-detail-client.tsx`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/Events/AuditAggregationWorkerTest.php`, `tests/Services/Audit/Pipeline/AuditTimingSummarizerTest.php`, `plans/api-endpoints.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los jobs async reportan rendimiento sin duplicar métricas por reintentos y la persistencia SQL conserva el contrato real de la tabla.

## [2026-05-20]

### refactor
- **Contrato canónico de resultados de auditoría**: `/audit/results` queda como summary paginado y se agrega `GET /audit/results/{facSec}` para detalle; el frontend consume hallazgos/timings sólo desde el detalle y deja de depender de estructuras legacy.
  - Archivos modificados: `app/Routes/web.php`, `app/Controllers/AuditController.php`, `app/Models/AuditStatusModel.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`, `app/Services/Audit/AuditFindingRules.php`, `frontend/lib/api/endpoints.ts`, `frontend/lib/api/audfact.ts`, `frontend/lib/schemas/domain.ts`, `frontend/components/results/audit-results-table.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/dashboard/priority-audit-table.tsx`, `frontend/components/audit/audit-timings-panel.tsx`, `frontend/components/audit/result-items-table.tsx`, `frontend/app/(dashboard)/audit/results/[facSec]/page.tsx`, `tests/Controllers/AuditControllerTest.php`, `tests/Services/Audit/Events/RulesEvaluationWorkerTest.php`, `tests/Services/Audit/Events/AuditAggregationWorkerTest.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`, `README.md`, `plans/api-endpoints.md`, `plans/architecture.md`, `plans/audit-findings.md`, `BUSINESS.md`, `AGENTS.md`, `CLAUDE.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`
  - Hallazgo resuelto: REF-01, REF-02, REF-03, REF-04, REF-05
  - Impacto: reduce payload público, elimina indirection legacy en frontend y deja al agregador como persistidor terminal sin lógica funcional de decisión.

### fix
- **Dashboard operativo dinámico en producción**: el layout del grupo dashboard fuerza render en runtime para evitar artefactos RSC prerenderizados durante build sin `INTERNAL_API_URL`.
  - Archivos modificados: `frontend/app/(dashboard)/layout.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: `/dashboard` y las vistas operativas vuelven a leer backend con variables de entorno runtime del contenedor frontend.

## [2026-05-19]

### fix
- **Contrato canónico de identidad de auditoría**: se formaliza `Factura.FacSec == vw_discolnet_dispensas.facsecF == AudDispEst.FacSec` y `DisDetNro == Dispensa == AudDispEst.FacNro`; el orquestador ahora rechaza eventos batch cuya identidad no coincida con la FDV.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `app/Services/Audit/Pipeline/AttachmentDownloadService.php`, `app/Services/Audit/Pipeline/AuditDataService.php`, `app/Models/DispensationModel.php`, `app/Models/AttachmentsModel.php`, `app/Controllers/AttachmentsController.php`, `app/Routes/web.php`, `app/wrap/core/tools/GetDispensation.php`, `app/wrap/core/tools/GetAttachments.php`, `app/wrap/capabilities.php`, `frontend/lib/api/endpoints.ts`, `frontend/lib/api/audfact.ts`, `frontend/lib/query/audit.ts`, `frontend/lib/query/query-keys.ts`, `frontend/lib/schemas/domain.ts`, `frontend/components/attachments/attachment-viewer-panel.tsx`, `frontend/components/results/attachment-result-detail-client.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/audit/audit-single-workspace.tsx`, `frontend/app/(dashboard)/dispensation/[disDetNro]/page.tsx`, `frontend/app/(dashboard)/audit/results/[facSec]/page.tsx`, `tests/Models/AttachmentsModelTest.php`, `tests/Models/DispensationModelTest.php`, `tests/Services/Audit/Events/DocumentAuditOrchestratorTest.php`, `tests/wrap/core/tools/GetDispensationTest.php`, `tests/wrap/core/tools/GetAttachmentsTest.php`, `README.md`, `BUSINESS.md`, `plans/audit-identity-contract.md`, `plans/api-endpoints.md`, `plans/architecture.md`, `plans/database-schema.md`, `plans/data-flows.md`, `plans/domain-glossary.md`, `.agent/skills/audfact-api-rest/SKILL.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-mcp-wrap/SKILL.md`, `.agent/skills/audfact-project-overview/SKILL.md`, `.agent/skills/audfact-sqlsrv-models/SKILL.md`
  - Hallazgo resuelto: ambigüedad E2E entre `facsec`, `facsecF`, `Factura.FacSec` e identificadores de adjuntos.
  - Impacto: los jobs nuevos fallan explícitamente ante identidad inconsistente en vez de sobrescribir auditorías por una llave legacy.

- **Frontend usa proxy runtime para API**: el navegador deja de consumir una URL absoluta horneada en el bundle y llama `/api/backend/*`, mientras Next.js reenvía al backend con `INTERNAL_API_URL` en runtime.
  - Archivos modificados: `frontend/lib/api/config.ts`, `frontend/lib/api/client.ts`, `frontend/lib/api/server-config.ts`, `frontend/app/api/backend/[[...path]]/route.ts`, `docker/frontend.Dockerfile`, `.github/workflows/frontend-ci.yml`, `.github/workflows/publish-images.yml`, `.github/workflows/deploy-production.yml`, `.env.example`, `README.md`, `AGENTS.md`, `CLAUDE.md`, `plans/deployment-github-actions-lan.md`, `plans/deployment-and-ci.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`
  - Hallazgo resuelto: bundle productivo del frontend contenía `http://localhost:8080` y enviaba acciones del navegador al backend local del operador.
  - Impacto: el mismo frontend puede desplegarse por entorno sin reconstruir URLs públicas y CI/CD bloquea bundles con URLs locales embebidas.

### fix
- **Batch async persiste una fila por factura auditada**: `InvoicesModel::getInvoices()` usa `Factura.FacSec` como llave de auditoría y alinea el filtro contra `AudDispEst`, evitando tanto descartes por alias como sobrescrituras cuando varias dispensas comparten `DisId`.
  - Archivos modificados: `app/Models/InvoicesModel.php`, `tests/Models/InvoicesModelTest.php`
  - Hallazgo resuelto: inconsistencia de contrato `facsec` vs `FacSec` y colisión `DisId` vs `Factura.FacSec` detectadas en batch `facNitSec=2426`.
  - Impacto: los jobs async registran y persisten auditorías con una llave única por factura seleccionada.

## [2026-05-15]

### feat
- **Configuración auditable por TipoDato**: `audit-config` exige, persiste y expone el tipo de dato de cada campo no visual, y el pipeline usa esa metadata para construir schemas Gemini, normalizar, comparar y decidir fallback semántico sin reglas hardcodeadas por nombre ni cliente.
  - Archivos modificados: `app/Controllers/AuditConfigController.php`, `app/Models/AuditConfigModel.php`, `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/AuditFindingRules.php`, `app/Services/Audit/ArticleSemanticMatchJudge.php`, `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`, `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/DocumentNormalizer.php`, `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `app/Services/Audit/Pipeline/RulesEvaluationWorker.php`, `app/Services/Audit/ResponseIADiskStore.php`, `frontend/components/audit/audit-config-editor.tsx`, `frontend/lib/api/audfact.ts`, `frontend/lib/schemas/domain.ts`
  - Hallazgo resuelto: reglas por nombre de campo que no escalaban a 22+ clientes y llamadas Gemini innecesarias en campos semánticos no-artículo.
  - Impacto: la configuración del cliente gobierna el comportamiento; Gemini semántico queda limitado a homologación de artículos (`article_name`) y los snapshots `responseIA` ya no guardan base64 inline.

### refactor
- **Limpieza de helpers legacy sin consumidores**: se eliminan métodos públicos no usados en enums de auditoría y el test de `AuditFieldValueType` itera directamente sobre los cases nativos.
  - Archivos modificados: `app/Services/Audit/AuditFindingResult.php`, `app/Services/Audit/DocumentQuality.php`, `app/Services/Audit/AuditFieldValueType.php`, `tests/Services/Audit/AuditFieldValueTypeTest.php`
  - Hallazgo resuelto: DL-001, DL-002, DL-003, DL-004, DL-005, DL-006 y DL-007 del reporte `audit_pipeline_legacy_code_report.md.resolved`.
  - Impacto: reduce superficie pública interna sin cambiar valores de enum, contratos persistidos ni comportamiento del pipeline.
- **Métricas Gemini preservadas en cache hits**: extracción documental y homologación semántica ahora emiten métricas locales `cache_hit=true` cuando resuelven desde Redis, sin inventar tokens ni contar cache como llamada remota.
  - Archivos modificados: `app/Services/Audit/GeminiCallMetrics.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/ArticleSemanticMatchJudge.php`, `app/Services/Audit/Pipeline/AuditAggregationWorker.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`, `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php`
  - Hallazgo resuelto: pérdida de visibilidad en `phase_timings.gemini_*` cuando una auditoría reutiliza cache de extracción o semántica.
  - Impacto: `/audit/results` conserva conteos y `cache_hits` por perfil sin alterar decisiones de auditoría.
- **Clean Code previo a backfill de TipoDato**: centraliza compatibilidad `TipoCampo`/`TipoDato` en `AuditFieldValueType`, simplifica sanitización del `audit-config` y alinea el editor para derivar opciones desde una sola matriz de tipos.
  - Archivos modificados: `app/Controllers/AuditConfigController.php`, `app/Models/AuditConfigModel.php`, `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `frontend/components/audit/audit-config-editor.tsx`, `tests/Services/Audit/AuditFieldValueTypeTest.php`, `.agent/skills/audfact-audit-gemini/SKILL.md`
  - Hallazgo resuelto: deuda de mantenibilidad previa al poblamiento de `TipoDato`.
  - Impacto: reduce duplicación de reglas sin poblar datos ni cambiar la decisión de auditoría esperada.
- **Pruebas y documentación alineadas al contrato explícito**: tests del pipeline, Golden Set, docs de negocio y skills reflejan `TipoDato`, `ArticleSemanticMatchJudge` y el contrato actual del editor de configuración.
  - Archivos modificados: `tests/Services/Audit/AuditFieldValueTypeTest.php`, `tests/Services/Audit/AuditFindingRulesNormalizationTest.php`, `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php`, `tests/Services/Audit/Events/DocumentAuditOrchestratorTest.php`, `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php`, `tests/Services/Audit/Events/DocumentNormalizerTest.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`, `tests/Services/Audit/Fixtures/golden_D65260408592.json`, `BUSINESS.md`, `plans/architecture.md`, `plans/testing-strategy.md`, `.agent/skills/audfact-audit-gemini/SKILL.md`, `.agent/skills/audfact-project-overview/SKILL.md`
  - Hallazgo resuelto: drift documental sobre inferencia por nombre de campo y juez semántico genérico.
  - Impacto: futuros cambios de clientes deben configurar metadata en UI/API en vez de agregar excepciones en código.

### docs
- **Golden case post-refactor actualizado**: `BUSINESS.md` refleja el resultado real de `X24260100121` con 37 campos auditados, `CodigoDiagnostico` activo en `DISPENSA` y métricas Gemini preservadas.
  - Archivos modificados: `BUSINESS.md`
  - Hallazgo resuelto: drift entre el golden case documentado y el `audit-config` vigente del cliente `2426`.
  - Impacto: el diagnóstico esperado queda alineado con `/audit/results` y evita interpretar como regresión un hallazgo configurado.

## [2026-05-14]

### feat
- **Comparación determinística de trazabilidad (TRACE_TOKEN)**: El motor de políticas IA preserva matrices estructuradas extraídas por Gemini para campos multi-ítem como `Lote`, resolviendo falsos positivos ("valores distintos") causados por la concatenación de la lógica anterior (Golden Case `X24260100121`).
  - Archivos modificados: `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`, `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`
  - Hallazgo resuelto: Evidencia parcial reportada falsamente como VALOR_DISTINTO.
  - Impacto: Introduce lógica formal de conjuntos (Set Theory) logrando determinismo 100% (COINCIDE para $F=D$, NO_CONCLUYENTE para $D \subset F$, VALOR_DISTINTO para divergencia) sobre taxonomía central.
- **Normalización determinística de identidad documental**: La extracción IA y el normalizador separan números de documento y nombres cuando Gemini devuelve líneas mixtas como `94229637-NOMBRE PACIENTE`.
  - Archivos modificados: `app/Services/Audit/Pipeline/DocumentExtractionContractBuilder.php`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`, `app/Services/Audit/Pipeline/DocumentNormalizer.php`, `app/Services/Audit/AuditFieldValueType.php`, `app/Services/Audit/AuditFindingRules.php`
  - Hallazgo resuelto: ninguno
  - Impacto: Reduce falsos `VALOR_DISTINTO` en `DocumentoPaciente`/`DocumentoMedico` sin relajar la comparación exacta contra FDV ni registrar datos personales en logs; la taxonomía de identidad queda centralizada en `AuditFieldValueType`.
- **Descubridor de campos con controles shadcn/ui**: El modal para agregar campos desde una factura real usa `Button`, `Tabs`, `Alert` y `Checkbox` en lugar de controles visuales artesanales.
  - Archivos modificados: `frontend/components/audit/add-field-from-dispensa-dialog.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: El flujo conserva la validación y selección de campos, pero mejora semántica accesible, estados disabled y consistencia visual con el resto de la configuración de auditoría.
- **Sheet shadcn/ui y optimización de formularios Next.js**: Se agrega `Sheet`, se migran overlays manuales a primitivas Radix/shadcn y se eliminan accesos DOM imperativos en filtros.
  - Archivos modificados: `frontend/components/ui/sheet.tsx`, `frontend/components/ui/dialog.tsx`, `frontend/components/layout/app-sidebar.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/components/audit/add-field-from-dispensa-dialog.tsx`, `frontend/components/clients/clients-filter-form.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/audit/documents-history-filter-form.tsx`, `frontend/components/jobs/job-tracker.tsx`, `frontend/app/(dashboard)/dispensation/page.tsx`, `frontend/components/audit/audit-single-console.tsx`, `frontend/components/audit/audit-config-page-client.tsx`, `frontend/components/audit/audit-config-editor.tsx`, `frontend/app/(dashboard)/observability/page.tsx`, `design-system/MASTER.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Drawer móvil, modales, filtros y formularios quedan alineados con shadcn/ui, con foco/ESC/overlay consistentes, sin cambiar contratos HTTP ni nombres de campos.
- **Tooltip shadcn/ui y consolidación de feedback**: Se agrega `Tooltip` sobre Radix, se centraliza el provider global y se completan migraciones de `Spinner`, `Alert` e `Item` en superficies operativas.
  - Archivos modificados: `frontend/package.json`, `frontend/package-lock.json`, `frontend/components/ui/tooltip.tsx`, `frontend/providers/app-providers.tsx`, `frontend/components/layout/app-sidebar.tsx`, `frontend/components/audit/audit-config-editor.tsx`, `frontend/components/audit/audit-batch-console.tsx`, `frontend/components/audit/audit-single-console.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/results/audit-results-table.tsx`, `frontend/components/attachments/attachment-list.tsx`, `frontend/components/jobs/job-detail-client.tsx`, `frontend/app/(dashboard)/observability/page.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Las acciones icon-only tienen ayuda contextual, los loaders inline usan una primitiva única y los errores operativos usan alertas accesibles sin cambiar lógica, navegación, requests ni payloads.
- **Item y Spinner shadcn/ui para listas y cargas inline**: Se agrega `Item`, `ItemContent`, `ItemMedia`, `ItemTitle` y `Spinner`, migrando la lista documental del dashboard y loaders inline principales.
  - Archivos modificados: `frontend/components/ui/item.tsx`, `frontend/components/ui/spinner.tsx`, `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/components/audit/add-field-from-dispensa-dialog.tsx`, `frontend/components/shared/confirm-dialog.tsx`, `frontend/components/jobs/job-detail-client.tsx`, `frontend/components/attachments/attachment-viewer-panel.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Las listas compactas y estados de carga usan primitivas reutilizables sin cambiar lógica, navegación, requests ni payloads.
- **Alert shadcn/ui para avisos operativos**: Se agrega `Alert`, `AlertTitle` y `AlertDescription` y se migran avisos manuales de configuración de cliente y dashboard.
  - Archivos modificados: `frontend/components/ui/alert.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/components/audit/audit-config-page-client.tsx`, `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/components/dashboard/async-queue-summary.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los estados informativos y de error usan una primitiva accesible y consistente sin cambiar navegación, requests ni payloads.
- **Field shadcn/ui para formularios**: Se agrega `Field`, `FieldLabel` y `FieldDescription` y se migran filtros principales a wrappers de formulario consistentes.
  - Archivos modificados: `frontend/components/ui/field.tsx`, `frontend/components/audit/audit-batch-console.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/audit/documents-history-filter-form.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los formularios usan estructura accesible y consistente sin cambiar nombres de campos, `FormData`, `register` ni payloads HTTP.

## [2026-05-13]

### feat
- **Checkbox shadcn/ui para verificaciones visuales**: Se agrega `Checkbox` sobre Radix y se reemplaza el checkbox manual de selección múltiple por `Checkbox + Label`.
  - Archivos modificados: `frontend/package.json`, `frontend/package-lock.json`, `frontend/components/ui/checkbox.tsx`, `frontend/components/audit/audit-config-editor.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: La selección de verificaciones visuales usa una primitiva accesible sin cambiar la lógica de `selectedFields` ni el payload final.
- **Switch shadcn/ui para configuración de auditoría**: Se agrega `Switch` sobre Radix y se reemplaza el interruptor manual de campos auditables por `Label + Switch`.
  - Archivos modificados: `frontend/package.json`, `frontend/package-lock.json`, `frontend/components/ui/switch.tsx`, `frontend/components/audit/audit-config-editor.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los toggles de campos y verificaciones visuales usan una primitiva accesible sin cambiar el payload `enabled` enviado al backend.
- **Calendar shadcn/ui para filtros de fecha**: Se agrega `Calendar` sobre `react-day-picker` y un `DatePickerInput` reutilizable para reemplazar los inputs nativos de fecha en auditoria y facturas.
  - Archivos modificados: `frontend/package.json`, `frontend/package-lock.json`, `frontend/components/ui/calendar.tsx`, `frontend/components/ui/date-picker-input.tsx`, `frontend/components/audit/audit-batch-console.tsx`, `frontend/components/results/audit-results-filter-form.tsx`, `frontend/components/invoices/invoices-filter-form.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los formularios empiezan a usar el patron shadcn/ui `Popover + Calendar` sin cambiar el contrato HTTP `YYYY-MM-DD` enviado al backend.

### fix
- **Modales y confirm con superficie opaca**: Los dialogos Radix/shadcn y el `ConfirmDialog` dejan de usar superficies translucidas que permitian ver contenido del fondo.
  - Archivos modificados: `frontend/components/ui/dialog.tsx`, `frontend/components/shared/confirm-dialog.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los modales tienen mejor aislamiento visual, scrim mas fuerte y lectura estable sobre pantallas densas.
- **Calendario con superficie opaca**: El popover del selector de fecha deja de mostrar el contenido de fondo a traves del calendario.
  - Archivos modificados: `frontend/components/ui/date-picker-input.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: El calendario conserva legibilidad y reduce ruido visual sobre dashboards y formularios densos.
- **Bandeja prioritaria con navegación estable**: El botón `Revisar` de `/dashboard` abre el listado de resultados filtrado por factura en vez del detalle inconsistente.
  - Archivos modificados: `frontend/components/dashboard/priority-audit-table.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: La revisión desde el dashboard usa `/audit/results?page=1&pageSize=20&facNro=...`, reutilizando la UI estable del historial.
- **Dashboard operativo de triage**: `/dashboard` se reorganiza alrededor de revisión manual, discrepancias, fallas y cola asíncrona, eliminando accesos rápidos redundantes.
  - Archivos modificados: `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/components/dashboard/priority-audit-table.tsx`, `frontend/components/dashboard/async-queue-summary.tsx`, `frontend/components/dashboard/dashboard-health-strip.tsx`, `frontend/components/dashboard/dashboard-results-table.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: El dashboard prioriza casos accionables, muestra estado compacto del sistema y expone métricas async reales sin datos mock.
- **Fechas estables en hidratación Next.js**: El formateo de fechas evita diferencias de `Intl.DateTimeFormat` entre Node y navegador.
  - Archivos modificados: `frontend/lib/formatters/index.ts`
  - Hallazgo resuelto: ninguno
  - Impacto: Previene errores de hidratación en tablas del dashboard por diferencias como `p. m.` vs `p.m.`.
- **Distribución de estados del dashboard**: El card de `/dashboard` ahora incluye todos los estados reales de `/audit/stats`, incluyendo `MANUAL_REVIEW`.
  - Archivos modificados: `frontend/app/(dashboard)/dashboard/page.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: El gráfico ya no queda vacío cuando las auditorías están en revisión manual u otro estado válido distinto de conciliado/discrepancia/fallido.
- **Dashboard sin estados falsos por API caída**: `/dashboard` deja de reemplazar fallas del backend por métricas en cero, estados vacíos o degradación inferida.
  - Archivos modificados: `frontend/app/(dashboard)/dashboard/page.tsx`, `frontend/lib/schemas/domain.ts`
  - Hallazgo resuelto: ninguno
  - Impacto: Si una fuente del dashboard falla, la sección muestra un error explícito con reintento en vez de datos aparentes; las respuestas paginadas reales con `filters: []` ya no se rechazan como error de contrato.
- **Configuración de auditoría sin datos falsos**: La UI de configuración de cliente deja de convertir fallas del backend en estados vacíos y ya no precarga campos genéricos al inicializar una configuración.
  - Archivos modificados: `frontend/app/(dashboard)/clients/audit-config/page.tsx`, `frontend/components/audit/audit-config-page-client.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/components/audit/audit-config-editor.tsx`, `frontend/lib/api/audfact.ts`, `frontend/lib/api/endpoints.ts`, `frontend/lib/schemas/domain.ts`
  - Hallazgo resuelto: ninguno
  - Impacto: Si la API no responde, el frontend muestra un error explícito; al crear configuraciones solo valida documentos reales y no siembra campos mock.
- **Guardrail de hosts SQL en deploy**: Se normaliza `DB_HOST`/`DB2_HOST` a host/IP limpio al generar `.env` productivo y se agrega preflight PDO/sqlsrv antes de recrear el stack.
  - Archivos modificados: `.github/workflows/deploy-production.yml`, `.env.example`, `AGENTS.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `.agent/skills/audfact-production-ops/SKILL.md`, `.agent/skills/audfact-production-ops/references/runbooks.md`, `plans/deployment-github-actions-lan.md`, `plans/deployment-and-ci.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Evita que un despliegue automatizado regenere hosts inválidos como `<IP>SQL2022` y tumbe la conectividad SQL de producción.

### security
- **Eliminación de `responseIA/` en producción**: Se removieron los volúmenes `./responseIA:/var/www/html/responseIA` de los servicios `php`, `worker-extraction` y `worker-policy` en `docker-compose.prod.yml`. El código (`ResponseIADiskStore`) ya contenía un guardrail (`APP_ENV !== 'development'`), pero el mount de Docker forzaba la creación del directorio vacío en el host de producción.
  - Archivos modificados: `docker-compose.prod.yml`, `plans/docker-operations.md`, `README.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Elimina la persistencia innecesaria de directorios de debug IA en producción, alineando infraestructura con la política Zero-Source.

## [2026-05-11]

### feat
- **Frontend Docker productivo**: Se agrega imagen standalone de Next.js, publicacion GHCR y servicio `frontend` en `docker-compose.prod.yml`.
  - Archivos modificados: `docker/frontend.Dockerfile`, `frontend/.dockerignore`, `frontend/app/api/health/route.ts`, `.github/workflows/publish-images.yml`, `.github/workflows/deploy-production.yml`, `docker-compose.prod.yml`, `.env.example`, `README.md`, `plans/docker-operations.md`, `plans/deployment-github-actions-lan.md`, `AGENTS.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Produccion LAN puede servir el frontend desde `audfact-frontend:<sha>` en un puerto dedicado configurable (`AUDFACT_FRONTEND_HOST_PORT`, default `3100`) sin depender de Node ni codigo fuente en el host.

### fix
- **Deploy produccion LAN**: Se corrige la reproducibilidad del pipeline CI/GHCR al remover el gitlink huerfano de `.agent/tmp-impeccable` y definir el default de extraccion Gemini cuando CI no carga `.env`.
  - Archivos modificados: `.gitignore`, `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`
  - Hallazgo resuelto: ninguno
  - Impacto: GitHub Actions puede ejecutar checkout, PHPUnit, publicacion de imagenes y deploy desde el estado local esperado.
- **Frontend source-of-truth local**: Se elimina la dependencia del submodulo remoto del frontend y se versiona el codigo fuente local necesario para CI/build.
  - Archivos modificados: `.gitmodules`, `.gitignore`, `.github/workflows/frontend-ci.yml`, `.github/workflows/publish-images.yml`, `frontend/*`
  - Hallazgo resuelto: ninguno
  - Impacto: GitHub Actions valida el mismo frontend que existe en el repo local, sin depender de un submodulo desalineado.
- **Deploy Redis sin auth**: Se alinea la validacion de secrets del workflow productivo con `REDIS_PASSWORD` opcional.
  - Archivos modificados: `.github/workflows/deploy-production.yml`, `docker-compose.prod.yml`
  - Hallazgo resuelto: ninguno
  - Impacto: Produccion puede desplegar contra Redis sin autenticacion, como esta documentado en `.env.example` y AGENTS.

### docs
- **Skill de operaciones de produccion**: Se agrega `audfact-production-ops` para acceso SSH no interactivo al servidor LAN, diagnosticos de Docker/runner y guias de deploy/rollback.
  - Archivos modificados: `.agent/skills/audfact-production-ops/SKILL.md`, `.agent/skills/audfact-production-ops/scripts/Invoke-AudFactProdSsh.ps1`, `.agent/skills/audfact-production-ops/references/runbooks.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `.agent/skills/aliases.json`, `.agent/skills/bundles.json`, `.agent/skills/validation-baseline.json`, `AGENTS.md`, `CLAUDE.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Futuras sesiones pueden operar produccion LAN con pasos reproducibles, sin persistir secretos y con guardrails para acciones de impacto.

## [2026-05-06]

### feat
- **Despliegue LAN con GitHub Actions**: Se separa CI, publicacion de imagenes GHCR y deploy productivo en runner self-hosted LAN con `docker-compose.prod.yml`.
  - Archivos modificados: `.github/workflows/ci.yml`, `.github/workflows/publish-images.yml`, `.github/workflows/deploy-production.yml`, `.github/workflows/frontend-ci.yml`, `docker-compose.prod.yml`, `.env.example`, `AGENTS.md`, `CLAUDE.md`, `.agent/skills/CATALOG.md`, `.agent/skills/audfact-runtime-docker/SKILL.md`, `README.md`, `plans/docker-operations.md`, `plans/deployment-github-actions-lan.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Produccion puede desplegar sin IP publica ni SSH expuesto, usando imagenes versionadas por SHA y healthcheck `/health`.
- **Configuración de auditoría UI**: Se agregan checkboxes de verificaciones visuales por documento para persistir `FirmaActaEntrega`, `VigenciaEntrega` y `FirmaPrescriptor` como campos `TipoCampo = V`.
  - Archivos modificados: `frontend/components/audit/audit-config-editor.tsx`, `frontend/components/audit/create-config-dialog.tsx`, `frontend/lib/schemas/domain.ts`
  - Hallazgo resuelto: ninguno
  - Impacto: El administrador puede configurar visuales por documento desde el navegador sin cambios backend.

### security
- **Contexto Docker**: Se excluye `responseIA/` del build context para evitar publicar respuestas crudas de Gemini dentro de imagenes.
  - Archivos modificados: `.dockerignore`
  - Hallazgo resuelto: ninguno
  - Impacto: Reduce riesgo de filtracion de datos sensibles en GHCR.

### docs
- **Alineación de auditoría IA**: Se sincroniza documentación con el contrato runtime de `audit-config`, eliminando referencias vigentes a roles `INFORMATIVO`/`AUTORITATIVO`, `omitirSi`, endpoint `POST /audit` y conteos REST obsoletos.
  - Archivos modificados: `BUSINESS.md`, `README.md`, `plans/api-endpoints.md`, `plans/architecture.md`, `plans/architecture-diagrams.md`, `plans/overview.md`, `plans/features/audit-workflow.md`, `.agent/skills/*`, `tests/Services/Audit/Fixtures/golden_D65260408592.json`, `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`
  - Hallazgo resuelto: ninguno
  - Impacto: La documentación ya refleja que todo campo activo en `fields` se evalúa según `TipoCampo` y que `NombreArticulo` tipo `S` puede disparar homologación semántica.

## [2026-05-05]

### feat
- **Frontend auditoría**: Se agregan timings persistidos del pipeline en el detalle de resultados de auditoría.
  - Archivos modificados: `frontend/lib/schemas/domain.ts`, `frontend/components/audit/audit-timings-panel.tsx`, `frontend/components/audit/audit-single-workspace.tsx`, `frontend/components/audit/status-badge.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/results/audit-results-table.tsx`, `frontend/app/(dashboard)/audit/results/[facSec]/page.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los auditores pueden revisar duración total, fase dominante, cache, desglose por fase y consumo Gemini sin llamadas adicionales al backend.
