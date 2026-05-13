## [2026-05-13]

### fix
- **Guardrail de hosts SQL en deploy**: Se normaliza `DB_HOST`/`DB2_HOST` a host/IP limpio al generar `.env` productivo y se agrega preflight PDO/sqlsrv antes de recrear el stack.
  - Archivos modificados: `.github/workflows/deploy-production.yml`, `.env.example`, `plans/deployment-github-actions-lan.md`, `plans/deployment-and-ci.md`
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
