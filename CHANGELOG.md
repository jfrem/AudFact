## [2026-05-20]

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
  - Impacto: Evita que un despliegue automatizado regenere hosts inválidos como `169.46.6.53SQL2022` y tumbe la conectividad SQL de producción.

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
