## [2026-03-17]

### feat
- **Ámbito**: Integración de mejoras proactivas P1, P2 y P3 en pipeline Gemini.
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`, `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/GeminiGateway.php`, `tests/Services/Audit/AuditBiasTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: Se agregan axiomas anti-alucinación (XML), Shield Prompt, regla Zero-Inference (campos pacientes en NULL), thinking budget dinámico basado en documentos y complejidad, hash del prompt y se reestructura `thinkingConfig` dentro de `generationConfig` en el proxy HTTP The API Gemini se invoca correctamente sin errores 400.

## [2026-03-11]

### fix
- **Ámbito**: Reintento resiliente del rebuild Docker en deploy sobre runner self-hosted.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: fallo transitorio de BuildKit por snapshot padre inexistente al exportar `audfact-php`
  - Impacto: el workflow de deploy ahora reintenta una vez el `docker compose up --build -d` tras limpiar el builder cache local, reduciendo fallos espurios del runner sin aplicar prunes globales destructivos.

### refactor
- **Ámbito**: Cobertura automatizada para rango de fechas en facturas y auditoría batch.
  - Archivos modificados: `tests/Controllers/AuditControllerTest.php`, `tests/Controllers/InvoicesControllerTest.php`, `tests/Models/InvoicesModelTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la suite PHPUnit ahora protege la validación de `dateTo`, el rechazo de rangos inválidos y la construcción SQL del modelo de facturas antes de hacer commit y push.

### fix
- **Ámbito**: Corrección del breadcrumb inválido en navegación del frontend.
  - Archivos modificados: `frontend/src/components/layout/navbar.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: navegación intermedia a `/audit` inexistente desde breadcrumbs
  - Impacto: la UI deja de generar enlaces y prefetch hacia la ruta inexistente `/audit`, eliminando el `404` de consola al abrir vistas hijas como `audit/batch`.

### feat
- **Ámbito**: Exposición completa de parámetros del lote de auditoría en la UI batch.
  - Archivos modificados: `frontend/src/app/audit/batch/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la pantalla de auditoría masiva ahora permite enviar `facNitSec`, `date`, `dateTo` y `limit` al endpoint `POST /audit`, validando además el rango de fechas y el límite configurado por backend.

### fix
- **Ámbito**: Compatibilidad SQL Server del endpoint de facturas.
  - Archivos modificados: `app/Models/InvoicesModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: `GET /invoices` fallaba con `SQLSTATE[07002]` por placeholders repetidos y `TOP` parametrizado en `pdo_sqlsrv`
  - Impacto: la búsqueda de facturas vuelve a responder desde la API sin error 500 para filtros válidos por NIT y fecha/rango.

### feat
- **Ámbito**: Soporte de rango de fechas para endpoints de auditoría en lote y búsqueda de facturas.
  - Archivos modificados: `app/Models/InvoicesModel.php`, `app/Controllers/AuditController.php`, `app/Controllers/InvoicesController.php`, `plans/api-endpoints.md`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los endpoints `GET /invoices` y `POST /audit` ahora aceptan un parámetro opcional `dateTo` para consultar e iniciar procesos de auditoría sobre rangos de tiempo definidos en vez de una fecha única.

### fix
- **Ámbito**: Desacople del rate limiter respecto al workspace del runner.
  - Archivos modificados: `core/RateLimit.php`, `docker/Dockerfile`, `docker/docker-entrypoint.sh`, `CHANGELOG.md`
  - Hallazgo resuelto: el fallback file-based dependía de `./logs` y fallaba cuando otro workflow limpiaba el workspace del self-hosted runner
  - Impacto: el rate limiter ahora usa un runtime dir dedicado (`/tmp/audfact-runtime/ratelimit`) dentro del contenedor y deja de romper el backend por la limpieza del workspace.

### fix
- **Ámbito**: Hardening del fallback file-based de rate limit para evitar warnings expuestos.
  - Archivos modificados: `core/RateLimit.php`, `CHANGELOG.md`
  - Hallazgo resuelto: `fopen(/var/www/html/.../logs/ratelimit.lock)` filtraba warnings HTML al cliente cuando el backend de archivos fallaba
  - Impacto: el rate limiter ahora maneja creación/apertura de archivos con control explícito de errores y sin exponer warnings crudos en respuestas HTTP.

### fix
- **Ámbito**: Corrección del loop de render en el header del dashboard frontend.
  - Archivos modificados: `frontend/src/app/dashboard/_components/dashboard-header.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: `React error #185` por snapshot inestable (`new Date()`) dentro de `useSyncExternalStore`
  - Impacto: el dashboard deja de entrar en render infinito al hidratar y conserva saludo/fecha cliente sin romper lint.

### fix
- **Ámbito**: Corrección del lint bloqueante del frontend y limpieza de warnings principales.
  - Archivos modificados: `frontend/src/app/dashboard/_components/dashboard-header.tsx`, `frontend/src/components/providers.tsx`, `frontend/src/app/dashboard/_components/recent-audits-table.tsx`, `frontend/src/app/dashboard/_components/status-distribution-chart.tsx`, `frontend/src/app/dashboard/page.tsx`, `frontend/src/app/settings/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: `npm run lint` fallaba por `setState` síncrono dentro de `useEffect` y dependencias/inutilizados inestables
  - Impacto: el repo `frontend` usa inicialización segura para cliente e hidrata sin los errores de hooks que bloqueaban GitHub Actions.

### fix
- **Ámbito**: Bind explícito del frontend a `0.0.0.0` en runtime Docker.
  - Archivos modificados: `frontend/Dockerfile`, `CHANGELOG.md`
  - Hallazgo resuelto: el frontend respondía desde el host pero rechazaba conexiones a `127.0.0.1:3000` dentro del contenedor, dejando el healthcheck en falso negativo
  - Impacto: la imagen frontend fuerza escucha en todas las interfaces del contenedor, permitiendo que el healthcheck interno valide correctamente el proceso Next.js.

### fix
- **Ámbito**: Corrección del healthcheck interno del contenedor frontend.
  - Archivos modificados: `docker-compose.frontend.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: falso negativo de salud con `wget http://localhost:3000/` pese a que Next.js respondía correctamente desde el host
  - Impacto: el healthcheck del frontend ahora valida contra `127.0.0.1`, reduciendo fallos por resolución interna de `localhost`.

### fix
- **Ámbito**: Preservación del contexto `frontend/` durante el purge Zero-Source del deploy backend.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: el runner eliminaba `frontend/` y luego `docker-compose.frontend.yml` fallaba por contexto de build inexistente
  - Impacto: el workspace remoto conserva `./frontend` para permitir reconstrucciones posteriores del contenedor frontend sin perder el modelo actual de despliegue.

### fix
- **Ámbito**: Alineación del deploy backend en CI con el checkout recursivo de submódulos.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ruido e inconsistencias del self-hosted runner al limpiar workspaces con `frontend` ya declarado en `.gitmodules`
  - Impacto: el job `deploy` de `CI — AudFact` ahora hace checkout consistente del árbol Git completo antes de recrear contenedores.

### fix
- **Ámbito**: Formalización del frontend como submódulo para CI/CD de GitHub Actions.
  - Archivos modificados: `.gitmodules`, `.github/workflows/deploy-frontend.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: `actions/setup-node` no podía resolver `frontend/package-lock.json` porque el checkout del repo principal no materializaba el árbol del frontend
  - Impacto: los jobs `validate-frontend` y `deploy-frontend` ahora inicializan el submódulo `frontend` antes de usar la caché npm o construir la imagen Docker.

### fix
- **Ámbito**: Corrección del estado persistido para guards de negocio y `human_review` en auditoría.
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: fallos de PHPUnit en persistencia de prevalidación y revisión humana
  - Impacto: `AudDispEst` vuelve a distinguir entre auditorías realmente procesadas por IA y salidas tempranas de negocio, manteniendo `EstAud`, `Severidad` y `RequiereRevisionHumana` consistentes con el contrato esperado.

### security
- **Ámbito**: Excepción operativa para despliegue con SQL Server sin TLS funcional.
  - Archivos modificados: `.github/workflows/ci.yml`, `.env.example`, `README.md`, `plans/deployment-and-ci.md`, `CHANGELOG.md`
  - Hallazgo resuelto: desbloqueo del backend en infraestructura donde `Encrypt=yes` falla aun con `TrustServerCertificate=yes`
  - Impacto: el pipeline de producción exige temporalmente `DB_ENCRYPT=no`, `DB_TRUST_SERVER_CERT=yes`, `DB2_ENCRYPT=no` y `DB2_TRUST_SERVER_CERT=yes` hasta corregir TLS en SQL Server.

### security
- **Ámbito**: Ajuste temporal del gate TLS para despliegue productivo sin certificado SQL Server válido.
  - Archivos modificados: `.github/workflows/ci.yml`, `.env.example`, `README.md`, `plans/deployment-and-ci.md`, `CHANGELOG.md`
  - Hallazgo resuelto: desbloqueo operativo del deploy con TLS activo y trust temporal del certificado del servidor
  - Impacto: el pipeline sigue exigiendo `Encrypt=yes` para SQL Server principal y de lectura, pero acepta `TrustServerCertificate=yes` hasta completar la instalación de certificados válidos en infraestructura.

### fix
- **Ámbito**: Disparo automático del deploy de frontend en cada push a `main`.
  - Archivos modificados: `.github/workflows/deploy-frontend.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: frontend sin despliegue cuando el push no modificaba rutas bajo `frontend/**`
  - Impacto: `Deploy Frontend — AudFact` puede ejecutarse en cualquier push a `main`, además de disparo manual.

### security
- **Ámbito**: Hardening del pipeline de despliegue con GitHub Actions runner.
  - Archivos modificados: `.github/workflows/ci.yml`, `.github/workflows/deploy-frontend.yml`, `docker/Dockerfile`, `docker-compose.yml`, `docker-compose.ha.yml`, `.env.example`, `README.md`, `plans/deployment-and-ci.md`, `CHANGELOG.md`
  - Hallazgo resuelto: `SEC-002`, `ARCH-001`, `GOV-001`, `GOV-002`, `QUAL-001` (parcial)
  - Impacto: el deploy de producción ahora exige TLS seguro para SQL Server principal y de lectura, el frontend pasa por `lint` y `build` antes de tocar el runner, y el healthcheck PHP usa una ruta persistente dentro de la imagen final.

## [2026-03-10]

### fix
- **Ámbito**: Resolución robusta de `documentoFallido` para rechazos puntuales en auditoría.
  - Archivos modificados: `app/Models/AuditStatusModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: rechazos omitidos por mismatch de nombre documental (exact match fallido)
  - Impacto: `updateAuditResult` ahora intenta match exacto, normalizado y por alias canónico, con trazabilidad de estrategia aplicada en logs.

### fix
- **Ámbito**: Persistencia documental para casos `human_review` (TUTELA).
  - Archivos modificados: `app/Services/Audit/AuditPreValidator.php`, `app/Services/Audit/AuditPersistenceService.php`, `tests/Services/Audit/AuditPreValidatorTest.php`, `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: auditorías `human_review` quedaban huérfanas en `/audit/documents-history`
  - Impacto: `human_review` ahora se registra con `_errorOrigin=business` y deja trazabilidad en `AdjuntosDispensacion` para aparecer en historial documental.

### fix
- **Ámbito**: Corrección de filtros `facNro/facNitSec` en historial documental de auditoría.
  - Archivos modificados: `app/Models/AttachmentsModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: consultas filtradas de `/audit/documents-history` devolvían vacío pese a existir registros
  - Impacto: los filtros por factura y NIT vuelven a aplicar correctamente en `countAuditHistory` y `getAuditHistory`.

### fix
- **Ámbito**: Persistencia documental de errores de prevalidación por límite de páginas.
  - Archivos modificados: `app/Services/Audit/AuditPreValidator.php`, `tests/Services/Audit/AuditPreValidatorTest.php`, `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: error de negocio con auditoría en `AudDispEst` sin reflejo en `/audit/documents-history`
  - Impacto: cuando un adjunto excede el máximo de páginas permitido, el resultado incluye hallazgo por documento y se aplica rechazo puntual en `AdjuntosDispensacion`, quedando visible en historial documental.

### fix
- **Ámbito**: Unificación de `API_BASE` en frontend para evitar inconsistencias de endpoint.
  - Archivos modificados: `frontend/src/lib/api.ts`, `frontend/src/components/document-viewer.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: QUAL-001
  - Impacto: toda la UI consume una única base URL configurada por `NEXT_PUBLIC_API_URL` con fallback local consistente, eliminando hardcode de IP fija.

### fix
- **Ámbito**: Alineación de documentación de testing con el estado real del repositorio.
  - Archivos modificados: `plans/testing-strategy.md`, `CHANGELOG.md`
  - Hallazgo resuelto: GOV-002
  - Impacto: la estrategia de pruebas documenta PHPUnit activo, suite actual y comandos reales de ejecución en local/CI.

### perf
- **Ámbito**: Prefiltrado SQL de adjuntos requeridos para auditoría IA.
  - Archivos modificados: `app/Models/AttachmentsModel.php`, `app/Services/Audit/AuditPreValidator.php`, `tests/Services/Audit/AuditPreValidatorTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: sobrecarga por filtrar opcionalidad únicamente en capa de aplicación
  - Impacto: el pipeline de auditoría ahora consulta adjuntos con `AdjDisOpc='N'` antes de llegar a Gemini, reduciendo volumen procesado sin alterar el endpoint público de adjuntos.

### fix
- **Ámbito**: Normalización de severidad para errores de prevalidación.
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: `EstadoDetallado=error` con `Severidad=ninguna`
  - Impacto: cuando una auditoría termina en `error`, la severidad persistida ya no queda en `ninguna` (fallback a `alta`).

### fix
- **Ámbito**: Lectura consistente de resultados de auditoría tras persistencia.
  - Archivos modificados: `app/Models/AuditStatusModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: desfase entre datos recién guardados y `/audit/results` por uso de conexión de lectura `db2`
  - Impacto: `AuditStatusModel` ahora lee desde `default` (mismo origen de escritura), evitando valores atrasados en severidad/duración/documentos procesados.

### fix
- **Ámbito**: Persistencia de duración real en errores de prevalidación de auditoría.
  - Archivos modificados: `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/AuditPreValidator.php`, `tests/Services/Audit/AuditPreValidatorTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: `DuracionProcesamientoMs` en `0` para errores de negocio pre-Gemini
  - Impacto: los flujos de error temprano ahora incluyen `_meta.totalTimeMs` y documentos disponibles en `_meta.documentos`, permitiendo guardar duración y `DocumentosProcesados` coherentes en `AudDispEst`.

### fix
- **Ámbito**: Restauración de rechazo selectivo en auditoría por documentos faltantes.
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `app/Models/AuditStatusModel.php`, `app/Models/AttachmentsModel.php`, `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: rechazo global incorrecto de soportes en faltantes parciales
  - Impacto: cuando faltan adjuntos requeridos, solo se rechazan los documentos faltantes reportados; se elimina la reconciliación de lectura que forzaba estados `R` globales en historial.

## [2026-03-09]

### fix
- **Ámbito**: Consistencia entre `/audit/results` y persistencia de soportes en errores globales sin hallazgos por documento.
  - Archivos modificados: `app/Services/Audit/AuditPersistenceService.php`, `app/Models/AuditStatusModel.php`, `app/Models/AttachmentsModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: inconsistencia de estado C/R entre endpoints de auditoría
  - Impacto: cuando la auditoría falla por documentos faltantes sin `data.items`, los adjuntos se marcan como rechazados de forma global y se expone una reconciliación de lectura para historial documental.

### feat
- **Ámbito**: Ajuste de `.gitignore` para artefactos locales de frontend y temporales de trabajo.
  - Archivos modificados: `.gitignore`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se excluyen del control de versiones caches/builds locales (`frontend/.next`, `frontend/.turbo`, `tmp`, `.agents`, etc.) para reducir ruido en commits.

### feat
- **Ámbito**: Pipeline y runtime de despliegue para frontend Next.js en runner self-hosted.
  - Archivos modificados: `frontend/Dockerfile`, `frontend/.dockerignore`, `docker-compose.frontend.yml`, `.github/workflows/deploy-frontend.yml`, `.github/workflows/ci.yml`, `.env.example`, `README.md`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el frontend ahora cuenta con imagen Docker standalone y workflow de despliegue independiente con health check en `:3000`; backend preserva `docker-compose.frontend.yml` durante purge Zero-Source.

### feat
- **Ámbito**: Optimización de `.dockerignore` para reducir contexto de build y reforzar exclusión de secretos.
  - Archivos modificados: `.dockerignore`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se excluyen `.env` locales, artefactos de frontend/caches y carpetas de desarrollo no requeridas por imágenes Docker actuales, manteniendo `!.env.example`.

### feat
- **Ámbito**: Alineación de estados del dashboard con el contrato real de auditorías.
  - Archivos modificados: `frontend/src/lib/audit-state.ts`, `frontend/src/app/dashboard/page.tsx`, `frontend/src/app/dashboard/_components/kpi-cards.tsx`, `frontend/src/app/dashboard/_components/recent-audits-table.tsx`, `frontend/src/app/dashboard/_components/status-distribution-chart.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: KPIs, tabla y gráfica de distribución ahora usan un único mapeo canónico (`resolveAuditState`) para estados `Pendiente`, `Procesada OK`, `Con Hallazgos`, `Error`, `Revisión humana` y `Desconocido`.

### feat
- **Ámbito**: Homologación visual del visor modal en `audit/batch` para alinearlo con la experiencia de `audit/single`.
  - Archivos modificados: `frontend/src/app/audit/batch/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se removieron capas `z-index` que interferían con la percepción del modal y se estandarizó el cierre/limpieza de estado del visor.

### feat
- **Ámbito**: Acción directa "Ver" en tabla de soportes documentales de `audit/single`.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `frontend/src/components/document-viewer.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada soporte ahora puede abrirse desde su propia fila en modal `iframe` y se elimina la opción de descarga para simplificar la revisión visual.

### feat
- **Ámbito**: Detalles de factura en `audit/single` con modo colapsable (resumen + detalle completo).
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la vista muestra un resumen inicial de contexto y permite expandir/contraer bloques completos para reducir carga visual sin perder información.

### feat
- **Ámbito**: Ampliación de "Detalles de la Factura" con más campos del endpoint `/dispensation/{id}`.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `frontend/src/lib/types.ts`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: ahora se visualizan bloques adicionales (administrativo, paciente, médico/diagnóstico y fechas) usando datos reales de la respuesta del backend.

### feat
- **Ámbito**: Ajuste de proporciones del header operativo en `audit/single` a distribución 60/40.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la columna del buscador gana prioridad visual frente al panel de acción, mejorando balance y legibilidad en desktop.

### feat
- **Ámbito**: Distribución superior en dos columnas para buscador y acción principal en `audit/single`.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el buscador queda en una columna y el botón de auditoría con sus KPIs en otra, mejorando claridad operativa al inicio del flujo.

### feat
- **Ámbito**: Reorganización de layout en `audit/single` para mejorar jerarquía visual y escaneo.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `frontend/src/components/audit/dispensation-lines-table.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la pantalla ahora usa distribución contenido+panel de ejecución sticky, KPI compactos y hallazgos en columna única dentro del modal para una lectura más clara.

### fix
- **Ámbito**: Optimización de descarga de adjuntos BLOB para evitar doble lectura temprana del binario
  - Archivos modificados: `app/Models/AttachmentsModel.php`, `app/Controllers/AttachmentsController.php`
  - Hallazgo resuelto: ninguno
  - Impacto: el endpoint de descarga ahora consulta solo metadatos antes de abrir el stream del BLOB, reduciendo riesgo de timeout en adjuntos pesados

## [2026-03-08]

### Tipo (feat)
- **Ambito**: Optimizacion de UI en auditoria individual con exposicion completa de la respuesta de dispensacion.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `frontend/src/components/audit/dispensation-lines-table.tsx`, `frontend/src/components/audit/audit-result-summary.tsx`, `frontend/src/components/document-viewer.tsx`, `frontend/src/lib/types.ts`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la vista `audit/single` ahora muestra las lineas completas de `/dispensation/{id}`, mejora jerarquia de resumen de resultados y agrega feedback explicito cuando un hallazgo no puede mapearse a un adjunto.

### Tipo (feat)
- **Ambito**: Priorizacion de hallazgos en batch por severidad.
  - Archivos modificados: `frontend/src/app/audit/batch/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la tabla de hallazgos en auditoria masiva ahora ordena automaticamente por severidad (`alta`, `media`, `baja`) para acelerar la revision.

### Tipo (feat)
- **Ambito**: Visor de documentos extendido a resultados de auditoria masiva (batch).
  - Archivos modificados: `frontend/src/app/audit/batch/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los hallazgos del lote ahora incluyen accion `Ver` que resuelve el adjunto por factura+origen y abre el modal unificado tipo `iframe` con cache de resolucion.

### Tipo (feat)
- **Ambito**: Visor de documentos habilitado tambien en la vista de historial de auditorias.
  - Archivos modificados: `frontend/src/app/audit/history/page.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: ahora cada fila del historial permite abrir el soporte con boton `Ver` en modal grande tipo `iframe`, reutilizando el visor unificado.

### Tipo (feat)
- **Ambito**: Visor de documentos dentro del modal de resultados en auditoría individual.
  - Archivos modificados: `frontend/src/app/audit/single/page.tsx`, `frontend/src/components/document-viewer.tsx`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada hallazgo ahora permite abrir el soporte asociado con botón `Ver`, previsualizando PDF/imagen en un modal y ofreciendo descarga para MIME no renderizable.

## [2026-03-07]

### Tipo (feature)
- **Ambito**: Endpoint de historial de auditoría de documentos paginado y alineado.
  - Archivos modificados: `app/Routes/web.php`, `app/Controllers/AuditController.php`, `app/Models/AttachmentsModel.php`, `plans/api-endpoints.md`.
  - Descripción: Creación del endpoint `documents-history` y posterior alineación de parámetros (`facNro`, `pageSize`) y estructura JSON con el endpoint `results`.

### Tipo (fix)
- **Ambito**: Compatibilidad de tests de persistencia con PHPUnit 10.
  - Archivos modificados: `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se reemplazó `withConsecutive()` por callbacks con aserciones por invocación para evitar errores de método eliminado en PHPUnit 10 y destrabar la etapa de `lint` en CI.

### Tipo (infra)
- **Ambito**: Trazabilidad del workspace en deploy self-hosted para diagnóstico de checkout.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el job de despliegue ahora imprime `GITHUB_WORKSPACE`, `pwd` y listado del workspace real para validar en logs dónde se realiza el checkout durante la ejecución.

### Tipo (security)
- **Ambito**: Endurecimiento del pipeline de despliegue en runner self-hosted.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el `.env` ahora se crea con permisos `600`, el deploy queda serializado con `concurrency` para evitar ejecuciones simultáneas, y el paso de purga incorpora guardas para reducir riesgo de borrados fuera del workspace.

## [2026-03-06]

### Tipo (infra)
- **Ambito**: Corrección del pipeline de despliegue para preservar directorios compartidos vitales.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: El paso `Zero-Source Host Purge` purgaba la carpeta `docker/` y `public/`, los cuales son montados como binarios o plantillas durante la recreación del contenedor provocando un bloqueo silencioso al fallar el entrypoint.
  - Impacto: Los contenedores volverán a levantar post-merge automáticamente y el `healthcheck` pasará de nuevo asegurando un CD funcional.

### Tipo (fix)
- **Ambito**: Inclusión de facturas auditadas con errores en la respuesta a pesar de tener un registro en `AudDispEst`.
  - Archivos modificados: `app/Models/InvoicesModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: Las facturas que ya tenían un intento de auditoría fallido no volvían a aparecer en el listado de "pendientes" porque el cruce `INNER JOIN` (implícito) omitía los registros. 
  - Impacto: Se cambió el cruce restrictivo por un `LEFT JOIN` a `AudDispEst` permitiendo procesar o re-procesar facturas que sufrieron errores previos (e.g. "Adjunto supera el máximo", fallos de Gemini).

## [2026-03-05]

### Tipo (refactor)
- **Ambito**: Blindaje de gobernanza de skills para agentes (skill-gate estricto + triggers + sync de compatibilidad).
  - Archivos modificados: `AGENTS.md`, `.agent/skills/CATALOG.md`, `CLAUDE.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se fuerza detección/carga de skill antes de análisis técnico, se añade checklist pre-ejecución y se reducen omisiones por ambigüedad con triggers por skill.

### Tipo (infra)
- **Ambito**: Endurecimiento de Despliegue en Producción (Lean Production 3.0).
  - Archivos modificados: `docker-compose.yml`, `.dockerignore`, `.github/workflows/ci.yml`, `docker/nginx.Dockerfile`, `docker/Dockerfile`
  - Hallazgo resuelto: Archivos de desarrollo, repositorios .git y herramientas de auditoría se estaban filtrando u hospedando innecesariamente en el servidor de Producción.
  - Impacto: El host es ahora **Zero-Source** (purgado post-deploy). Nginx es un **bundle inmutable** con assets integrados (sin bind mount). PHP purga artefactos de orquestación en build. Sincronización de timeouts Nginx/FPM para procesos IA largos (3600s).

### Tipo (fix)
- **Ambito**: Endurecimiento de sintaxis YAML en `ci.yml` para generación dinámica de `.env`.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: Error de sintaxis de heredoc Bash / YAML en Runner.
  - Impacto: El heredoc que genera el `.env` retira los bloqueos de parsing para asegurar inyección de variables dinámicas al tiempo que provee despliegue fluido a los Runner Nodes.

### Tipo (refactor)
- **Ambito**: Estandarización de variables de entorno para BD de consulta usando solo prefijo `DB2_*` (sin alias `SECONDARY_DB_*`).
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la conexión de lectura de modelos usa exclusivamente `DB2_*` con conexión nombrada `db2`; se elimina la dependencia de aliases `SECONDARY_DB_*`.

## [2026-03-04]

### Tipo (refactor)
- **Ambito**: Enrutamiento por tipo de sentencia en capa de modelos: lecturas en `secondary` (`DB2_*` fallback) y escrituras en `default`.
  - Archivos modificados: `app/Models/Model.php`, `app/Models/AuditStatusModel.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: todas las consultas de modelos pasan a BD secundaria y las operaciones de escritura (`INSERT/UPDATE/DELETE/MERGE`) se ejecutan en BD principal sin duplicar lógica de conexión.

### Tipo (refactor)
- **Ambito**: Enrutamiento centralizado de modelos a conexiones nombradas con caché PDO aislado por fingerprint de configuración.
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `Database` evita mezclar conexiones por colisión de caché al cachear por configuración efectiva y mantiene fallback legado `DB2_*` para `secondary`.

### Tipo (refactor)
- **Ambito**: Soporte multi-BD por modelo con conexión nombrada `secondary` y compatibilidad de prefijos legados.
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada modelo puede seleccionar su conexión (`default` o `secondary`) mediante propiedad interna; `Database` elimina colisiones de caché y acepta configuración `SECONDARY_DB_*` con fallback a `DB2_*`.

### Tipo (fix)
- **Ambito**: Hardening de logging en runtime para evitar warnings por permisos en `logs/` durante despliegues remotos.
  - Archivos modificados: `core/Logger.php`, `docker/docker-entrypoint.sh`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando el path primario de logs no es escribible, el logger usa fallback en `/tmp/audfact-logs` y el endpoint HTTP evita exponer warnings; el entrypoint reporta en arranque si `logs/` no es escribible por `www-data`.

### Tipo (fix)
- **Ambito**: Logging robusto de producción orientado a contenedor para eliminar dependencia de permisos en `./logs`.
  - Archivos modificados: `core/Logger.php`, `docker-compose.yml`, `docker-compose.ha.yml`, `docker/docker-entrypoint.sh`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: en `APP_ENV=production` los logs se emiten por `stderr`, por lo que `/health` y endpoints no exponen warnings de `file_put_contents`; en dev se mantiene logging a archivos con rotación.

### Tipo (fix)
- **Ambito**: Alineación de perfiles Docker Compose para UID/GID parametrizable en build de PHP.
  - Archivos modificados: `docker-compose.ha.yml`, `docker-compose.dev.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los perfiles `ha` y `dev` ahora usan los mismos build args `WWWUSER_ID/WWWGROUP_ID` que el compose base, evitando diferencias de permisos/ownership entre entornos.

## [2026-03-03]

### Tipo (fix)
- **Ambito**: Hardening del deploy para estabilidad de runtime en self-hosted runner.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el deploy ahora asegura permisos de `logs/` para rate limiter/logger, configura `safe.directory` para Composer en contenedor, valida existencia de `vendor/autoload.php` y usa `GET /health` como check funcional.

### Tipo (security)
- **Ambito**: Endurecimiento del deploy en runner self-hosted para generar `.env` desde GitHub Secrets.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada deploy ahora crea `.env` con secretos requeridos antes de `docker compose up`, evitando arranques con variables demo o ausencia total del archivo.

### Tipo (fix)
- **Ambito**: Endurecimiento del deploy CI/CD para garantizar dependencias PHP en runtime con bind mount activo.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el job `deploy` ahora ejecuta `composer install` dentro del contenedor `php` despues de `docker compose up --build -d`, evitando el error fatal por `vendor/autoload.php` inexistente en servidor.

### Tipo (feat)
- **Ambito**: Creacion de nuevo CLI en PHP (Symfony Console) en directorio `cli/` con modo interactivo, presets y soporte multi-base de datos.
  - Archivos modificados: `cli/composer.json`, `cli/bin/php-init`, `cli/src/Application.php`, `cli/src/Command/NewProjectCommand.php`, `cli/src/Command/MakeControllerCommand.php`, `cli/src/Command/MakeModelCommand.php`, `cli/src/Command/MakeMiddlewareCommand.php`, `cli/src/Command/MakeCrudCommand.php`, `cli/src/Command/ListRoutesCommand.php`, `cli/src/Command/DbMigrateCommand.php`, `cli/src/Command/DbFreshCommand.php`, `cli/src/Command/InitDockerCommand.php`, `cli/src/Support/ProjectScaffolder.php`, `cli/src/Support/ScaffoldTemplates.php`, `cli/src/Support/SafeWriter.php`, `cli/src/Support/NameSanitizer.php`, `cli/src/Support/EnvReader.php`, `cli/src/Support/ProjectContext.php`, `cli/README.md`, `plans/TODO/checkpoints/cli-migration-20260302-215142.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se habilita una base de migracion desde el CLI Node.js hacia PHP con comandos `new`, `make:*`, `list:routes`, `db:migrate`, `db:fresh` e `init:docker`, generando scaffolding agnostico de dominio.

### Tipo (feat)
- **Ambito**: Expansion del flujo interactivo de `new` con configuracion avanzada y post-acciones.
  - Archivos modificados: `cli/src/Command/NewProjectCommand.php`, `cli/src/Support/ProjectScaffolder.php`, `cli/src/Support/ScaffoldTemplates.php`, `cli/src/Support/NameSanitizer.php`, `cli/src/Command/MakeCrudCommand.php`, `cli/README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el comando `new` ahora soporta `APP_ENV`, `ALLOWED_ORIGINS`, expiraciones JWT, generacion opcional de tests, y ejecucion opcional de `composer install` / `db:migrate`; ademas `make:crud` corrige generacion integral de modelo+controlador+rutas.

### Tipo (feat)
- **Ambito**: Preparacion de `cli/` como repositorio independiente listo para GitHub.
  - Archivos modificados: `cli/.gitignore`, `cli/LICENSE`, `cli/CHANGELOG.md`, `cli/README.md`, `cli/composer.json`, `cli/.github/workflows/ci.yml`, `cli/.github/workflows/release-drafter.yml`, `cli/.github/release-drafter.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el modulo CLI queda con metadatos de publicacion, CI automatizado, y release draft para ciclo de entrega independiente.

### Tipo (refactor)
- **Ambito**: Mejora del template de validacion para nuevos scaffolds generados por el CLI.
  - Archivos modificados: `cli/src/Support/ScaffoldTemplates.php`, `cli/CHANGELOG.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `core/Validator.php` generado ahora soporta reglas mas completas (`nullable`, `string`, `email`, `numeric`, `integer`, `boolean`, `alpha`, `date`, `in`, `min/max`, `min_length/max_length`, `min_value/max_value`) con mensajes consistentes.

## [2026-03-02]

### Tipo (feat)
- **Ambito**: Instalacion del framework de auditoria tecnica por dominios en `.agent/skills`.
  - Archivos modificados: `.agent/skills/_shared/*`, `.agent/skills/audit-skill-router/*`, `.agent/skills/architecture-assessment/*`, `.agent/skills/code-quality-assessment/*`, `.agent/skills/security-assessment/*`, `.agent/skills/technical-governance-assessment/*`, `.agent/skills/README.md`, `.agent/skills/SMOKE-TESTS.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `AGENTS.md`, `CLAUDE.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el repositorio ahora tiene routing de auditorias y evaluaciones especializadas con scoring determinista, evidencia obligatoria y salida estructurada.

## [2026-03-02]

### Tipo (refactor)
- **Ambito**: Endurecimiento de gobernanza para auditorías técnicas con skill gate obligatorio.
  - Archivos modificados: `AGENTS.md`, `CLAUDE.md`, `.agent/skills/project-audit-framework/SKILL.md`, `.agent/skills/CATALOG.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: toda solicitud de auditoría/review/evaluación queda forzada a usar `project-audit-framework` con fases 0-6, scoring ponderado, clasificación global y plan 30/60/90.

### Tipo (refactor)
- **Ambito**: Estandarización de metadata OpenAI para la skill de auditoría.
  - Archivos modificados: `.agent/skills/project-audit-framework/agents/openai.yaml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la skill `project-audit-framework` queda alineada con el formato `agents/openai.yaml` usado por el resto de skills del repositorio.

## [2026-02-28]

### Tipo (refactor)
- **Ambito**: Rotacion real de logs por tamaño con backups numerados.
  - Archivos modificados: `core/Logger.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando un archivo alcanza `LOG_MAX_SIZE_MB`, ahora se rota a `app-...log.1` (hasta `.5`) en lugar de truncarse, preservando trazabilidad del mismo dia.

### Tipo (security)
- **Ambito**: Xdebug condicional por entorno de build Docker.
  - Archivos modificados: `docker/Dockerfile`, `docker-compose.yml`, `docker-compose.dev.yml`, `docker-compose.ha.yml`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: imágenes `HA/prod` construyen con `ENABLE_XDEBUG=0` (sin Xdebug en runtime), mientras `dev` mantiene `ENABLE_XDEBUG=1` para depuración local.

### Tipo (fix)
- **Ambito**: Corrección de manejo de excepciones para bloqueo CORS en bootstrap.
  - Archivos modificados: `public/index.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: solicitudes con `Origin` no permitido ahora responden JSON `403` consistente (incluyendo `OPTIONS`), evitando salida HTML/fatal con código `200`.

### Tipo (security)
- **Ambito**: Hardening de bootstrap HTTP para configuracion productiva.
  - Archivos modificados: `public/index.php`, `.env.example`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: CORS en produccion valida allowlist estricta (`ALLOWED_ORIGINS`) y rechaza origenes no permitidos con `403`, se agregan headers base de seguridad y se elimina bypass silencioso ante fallos del rate limiter.

### Tipo (fix)
- **Ambito**: Ajuste de cadena SQL Server para evitar fallo TLS con certificado autofirmado en ODBC 18.
  - Archivos modificados: `core/Database.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la conexión PDO `sqlsrv` usa `Encrypt=no;TrustServerCertificate=yes`, eliminando el error `certificate verify failed:self-signed certificate` en runtime Docker local.

### Tipo (fix)
- **Ambito**: Reconstruccion Docker compatible con PHP 8.2 para drivers SQL Server.
  - Archivos modificados: `docker/Dockerfile`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `docker compose up --build -d` vuelve a completar el build de `audfact-php` fijando `sqlsrv/pdo_sqlsrv` a `5.11.1` por URL directa de PECL, evitando resolver releases incompatibles con PHP 8.2.

## [2026-02-27]

### Tipo (refactor)
- **Ambito**: Unificacion de validacion de query/body en controladores para consultas de auditoria.
  - Archivos modificados: `app/Controllers/Controller.php`, `app/Controllers/AuditController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GET /audit/results` deja validaciones manuales y usa `Validator` central mediante `validateQuery()`, con reglas consistentes, paginacion validada y saneo uniforme de query params.

### Tipo (fix)
- **Ambito**: Alineación de documentación de endpoints con el router real (sin prefijo `/api`).
  - Archivos modificados: `README.md`, `plans/data-flows.md`, `plans/features/mcp-integration.md`, `plans/overview.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se corrigen tablas y flujos operativos para usar rutas reales (`/clients`, `/invoices`, `/audit`, `/audit/single`, etc.) y base URL local `http://localhost:8080`.

### Tipo (refactor)
- **Ambito**: Expansión de tipado estricto en capas core/controllers/models (fase segura).
  - Archivos modificados: `core/Router.php`, `core/Route.php`, `core/Middleware.php`, `core/Response.php`, `app/Controllers/Controller.php`, `app/Controllers/ClientsController.php`, `app/Controllers/InvoicesController.php`, `app/Controllers/DispensationController.php`, `app/Controllers/HealthController.php`, `app/Controllers/ConfigController.php`, `app/Models/Model.php`, `app/Models/ClientsModel.php`, `app/Models/InvoicesModel.php`, `app/Models/DispensationModel.php`, `app/Models/AttachmentsModel.php`, `app/Models/AuditStatusModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: mayor seguridad de tipos en tiempo de ejecución y mejor mantenibilidad sin cambio funcional de endpoints.

### Tipo (fix)
- **Ambito**: Endurecimiento del enrutador para no aceptar parámetros vacíos en segmentos requeridos.
  - Archivos modificados: `core/Router.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: rutas con parámetros dinámicos obligatorios ahora requieren al menos un carácter (`+`), evitando matcheos inválidos y validaciones tardías.

### Tipo (security)
- **Ambito**: Hardening de TLS para integración con Google Drive.
  - Archivos modificados: `app/Services/GoogleDriveAuthService.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la verificación TLS en conexiones HTTPS de Google Drive queda habilitada por defecto y controlada por `GOOGLE_DRIVE_TLS_VERIFY` (solo desactivable en desarrollo controlado).

### Tipo (fix)
- **Ambito**: Health check funcional con estado global real en lugar de valor fijo.
  - Archivos modificados: `app/Controllers/HealthController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GET /health` ahora reporta `healthy` solo cuando la BD responde y retorna detalle estructurado por servicio (`status`, `message`, `latency_ms`), evitando falsos positivos.

### Tipo (security)
- **Ambito**: Sanitización de logs del pipeline Gemini para evitar exposición de contenido sensible en trazas operativas.
  - Archivos modificados: `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/AuditResultValidator.php`, `core/Logger.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se eliminan logs de contenido crudo/parseado de Gemini y se reemplazan por métricas técnicas seguras (longitud de respuesta, tipo de respuesta, cantidad de items, intentos).

### Tipo (refactor)
- **Ambito**: Retiro de fachada `GeminiAuditService` y consumo directo de `AuditOrchestrator` desde el controlador de auditoría.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se elimina capa legacy de compatibilidad y se mantiene el comportamiento funcional de `/audit` y `/audit/single` delegando directamente en la nueva arquitectura de servicios.

### Tipo (refactor)
- **Ambito**: Desacople del pipeline de auditoría Gemini para reducir acoplamiento y facilitar escalabilidad.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditTelemetryService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GeminiAuditService` queda como fachada de compatibilidad y la lógica se distribuye por responsabilidades (orquestación, gateway HTTP, persistencia y telemetría) sin cambiar el contrato de `POST /audit` y `POST /audit/single`.

### Tipo (refactor)
- **Ambito**: Estandarizacion de configuracion de entorno y cliente HTTP interno para MCP.
  - Archivos modificados: `app/Controllers/Controller.php`, `app/Services/GoogleDriveAuthService.php`, `app/wrap/core/ApiClient.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se elimina uso directo de `getenv()` en modulos clave y `ApiClient` migra de cURL a Guzzle manteniendo contrato de respuesta.

## [2026-02-26]

### Tipo (fix)
- **Ambito**: Correccion del retorno en `getSystemInstruction` para evitar `TypeError` por retorno `null`.
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la auditoria individual ya no falla al construir la instruccion de sistema y puede continuar al llamado de Gemini.

### Tipo (fix)
- **Ambito**: Bloqueo de adjuntos con mas de 2 paginas antes de invocar Gemini y persistencia de estado en BD.
  - Archivos modificados: `app/Services/Audit/AuditFileManager.php`, `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando un adjunto excede 2 paginas se aborta la auditoria y queda persistido `Adjunto supera el maximo de páginas permitidas` en `AudDispEst`.

### Tipo (fix)
- **Ambito**: Correccion de severidad por defecto en respuestas de error para evitar persistencia con `Severidad=ninguna`.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: inconsistencias de negocio (como MIPRES incompleto) quedan persistidas con severidad alta en BD.

### Tipo (fix)
- **Ambito**: Abort early para dispensaciones `MIPRES` con campos obligatorios vacios antes de invocar Gemini.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se evita auditoria IA invalida y se persiste resultado de error en `AudDispEst` y `responseIA`.

### Tipo (fix)
- **Ambito**: Alineacion del schema de auditoria para incluir bloques `metrics` y `config_used` en salida de Gemini y validacion interna.
  - Archivos modificados: `app/Services/Audit/AuditResponseSchema.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la API ahora exige y expone metrica estructurada y configuracion efectiva de riesgo en la respuesta de auditoria.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Correccion de previsualizacion de adjuntos para evitar respuesta JSON mezclada con stream binario.
  - Archivos modificados: `app/Controllers/AttachmentsController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los botones "Previsualizar" en auditoria individual y por lote vuelven a abrir el documento sin error.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Restauracion de `envsubst` en imagen PHP para evitar reinicios por entrypoint.
  - Archivos modificados: `docker/Dockerfile`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `docker-entrypoint.sh` vuelve a generar `www.conf` y el contenedor `php` deja de reiniciar con codigo 127.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Limpieza de archivos legacy y temporales no referenciados tras separacion dev/ha.
  - Archivos modificados: `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: menor ruido en repositorio sin afectar runtime.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Separacion explicita del modo HA en archivo dedicado.
  - Archivos modificados: `docker-compose.ha.yml`, `README.md`
  - Hallazgo resuelto: ninguno
  - Impacto: operacion mas clara con perfiles separados para desarrollo y stress/HA.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Separacion de entorno de desarrollo con `docker-compose.dev.yml` para ejecucion estable (1 php + 1 nginx).
  - Archivos modificados: `docker-compose.dev.yml`, `README.md`
  - Hallazgo resuelto: ninguno
  - Impacto: flujo local mas predecible y healthcheck PHP simplificado para evitar falsos `unhealthy`.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Correccion de plantilla Nginx HA para evitar fallo de arranque por sintaxis invalida.
  - Archivos modificados: `docker/nginx-ha.conf.template`
  - Hallazgo resuelto: ninguno
  - Impacto: `audfact-nginx` inicia correctamente y vuelve a publicar `:8080`.

