# AudFact Skills Catalog

Colección de skills específicas para el proyecto `AudFact` — Sistema de auditoría documental IA.

## Skills

| Skill | Área | Archivos Gobernados | Descripción |
|---|---|---|---|
| `audfact-project-overview` | Contexto Global | `README.md`, `plans/*` | Visión general, arquitectura y flujos. |
| `audfact-api-rest` | Endpoints REST | `app/Routes/web.php`, `app/Controllers/*` | Endpoints en PHP MVC y validación. |
| `audfact-audit-gemini` | Auditoría IA | `app/Services/Audit/*` (raíz + Pipeline) | Pipeline event-driven con Gemini: `DocumentAuditOrchestrator`, `DocumentExtractionContractBuilder`, `DocumentIntegrityValidator`, reservas idempotentes por `DisId`, telemetría por evento y workers sobre Redis Streams. |
| `audfact-sqlsrv-models` | Datos SQL Server | `app/Models/*`, `core/Database.php` | Modelos PDO sqlsrv y streams BLOB. |
| `audfact-mcp-wrap` | Protocolo MCP | `app/wrap/*` | Integración MCP y herramientas internas. |
| `audfact-runtime-docker` | Ops / Runtime | `docker/*`, `docker-compose.yml`, `.github/workflows/*.yml`, `scripts/sync-github-production-env.sh` | Entorno Docker, CI/CD, Environment GitHub y conectividad DB. |
| `audfact-production-ops` | Producción LAN | `.agent/skills/audfact-production-ops/**`, `scripts/sync-github-production-env.sh`, servidor LAN | Acceso SSH no interactivo, diagnóstico de producción, runner self-hosted, GitHub Secrets/Variables y despliegues. |
| `audfact-security-guardrails` | Seguridad | `core/RateLimit.php`, `core/Logger.php` | Rate limit (100/min), CORS y logs. |
| `audfact-docs-sync` | Documentación | `README.md`, `plans/*`, `.agent/skills/*` | Sincronización obligatoria de documentación y skills después de cambios de código o drift documental. |
| `audit-skill-router` | Auditoría Técnica | Repositorio completo | Enrutador de auditorías amplias/ambiguas hacia dominios especializados con salida consolidada. |
| `architecture-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de arquitectura, acoplamiento, límites de módulos y escalabilidad. |
| `code-quality-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de mantenibilidad, complejidad, testabilidad y deuda técnica. |
| `security-assessment` | Auditoría Técnica | Repositorio completo | Auditoría de seguridad (auth/authz, secretos, vulnerabilidades, hardening). |
| `technical-governance-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de gobernanza técnica: ownership, code review, incidentes y roadmap. |
| `next-best-practices` | Frontend Next.js | `frontend/*` | Prácticas y convenciones recomendadas para directorios, dependencias y Server Components. |
| `next-cache-components` | Frontend Next.js | `frontend/*` | Guías de caché/PPR para migraciones Next.js 16+; no aplicar al runtime actual 15.5.15 salvo upgrade. |
| `next-upgrade` | Frontend Next.js | `frontend/*` | Herramientas y protocolos para actualizar a versiones nuevas de Next.js de manera segura. |
| `clean-rebuild-policy` | Gobernanza Técnica | Repositorio completo | Política para proyectos en fase temprana: reconstrucción limpia, sin legacy, enfocada en MVP. |
| `ui-ux-pro-max` | UI/UX Design | `frontend/*`, `public/assets/*` | Inteligencia de diseño para web/móvil: 50+ estilos, sistemas de color, tipografía y accesibilidad. |
| `impeccable` | UI/UX Design | `frontend/*` | The vocabulary you didn't know you needed. 23 commands y anti-patrones para un diseño frontend impecable. |

## Triggers Sugeridos por Skill

Usar estos triggers para reducir ambigüedad en el enrutamiento. Si el prompt coincide con varios, aplicar multi-skill en orden de impacto.

| Skill | Triggers sugeridos |
|---|---|
| `audfact-project-overview` | overview, visión general, arquitectura, cómo está organizado, mapear dependencias, estructura del proyecto |
| `audfact-api-rest` | endpoint, ruta, controller, request/response, validación HTTP, web.php, API REST |
| `audfact-audit-gemini` | auditoría IA, Gemini, prompt, schema JSON, extraction_contract, parallel function calling, retry/backoff, DocumentAuditOrchestrator, DocumentIntegrityValidator, document_rejected, workers, Pipeline event-driven, DLQ, idempotencia DisId, event_timings, phase_timings |
| `audfact-sqlsrv-models` | modelo, SQL Server, PDO sqlsrv, query, BLOB, stream, Database.php |
| `audfact-mcp-wrap` | MCP, webhook, capabilities, tools, ApiClient, JSON-RPC |
| `audfact-runtime-docker` | docker, compose, nginx, php-fpm, healthcheck, despliegue |
| `audfact-production-ops` | producción, servidor LAN, SSH, admon@172.16.0.3, runner self-hosted, deploy production, rollback, healthcheck remoto |
| `audfact-security-guardrails` | rate limit, CORS, sanitización, secretos, hardening, seguridad |
| `audfact-docs-sync` | documentación, docs sync, changelog, actualizar README, sincronizar skills, documentation drift |
| `audit-skill-router` | auditoría técnica integral, assessment, review global, scoring, 30/60/90 |
| `architecture-assessment` | acoplamiento, límites de módulos, escalabilidad, diseño de arquitectura |
| `code-quality-assessment` | deuda técnica, mantenibilidad, complejidad, testabilidad, code quality |
| `security-assessment` | vulnerabilidades, auth/authz, OWASP, exposición de secretos |
| `technical-governance-assessment` | ownership, gobernanza, estándares, code review process, roadmap técnico |
| `clean-rebuild-policy` | reconstrucción, clean rebuild, MVP, arquitectura desacoplada, eliminar legacy, desde cero |
| `ui-ux-pro-max` | UI/UX, diseño, accesibilidad, tipografía, paleta de colores, mockup, prototipado, landing page, dashboard |
| `impeccable` | impeccable, audit UI, diseño frontend, anti-patrones, polish UI, diseño |

## Bundles

| Bundle | Skills | Uso |
|---|---|---|
| `audfact-core` | `audfact-api-rest`, `audfact-sqlsrv-models` | Cambios en API + Datos |
| `audfact-ai-audit` | `audfact-audit-gemini`, `audfact-sqlsrv-models`, `audfact-security-guardrails` | Pipeline de auditoría completo |
| `audfact-integration` | `audfact-mcp-wrap`, `audfact-api-rest` | Integración con agentes IA |
| `audfact-ops` | `audfact-runtime-docker`, `audfact-production-ops`, `audfact-security-guardrails` | Infraestructura, producción LAN y hardening |
| `audfact-tech-assessment` | `audit-skill-router`, `architecture-assessment`, `code-quality-assessment`, `security-assessment`, `technical-governance-assessment` | Auditorías técnicas integrales con score global |
| `audfact-docs` | `audfact-docs-sync`, `audfact-project-overview` | Sincronización documental y validación de drift |
| `audfact-frontend` | `next-best-practices`, `ui-ux-pro-max`, `impeccable` | Cambios de frontend en Next.js 15.5.15 con revisión UI/UX |

## Mapeo Archivo → Skill

| Archivo | Skill Primaria |
|---|---|
| `app/Routes/web.php` | `audfact-api-rest` |
| `app/Controllers/*.php` | `audfact-api-rest` |
| `app/Models/*.php` | `audfact-sqlsrv-models` |
| `core/Database.php` | `audfact-sqlsrv-models` |
| `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | `audfact-audit-gemini` |
| `app/Services/Audit/Pipeline/*.php` | `audfact-audit-gemini` |
| `app/Services/Audit/*.php` | `audfact-audit-gemini` |
| `app/Services/GoogleDrive*.php` | `audfact-audit-gemini` |
| `app/wrap/**` | `audfact-mcp-wrap` |
| `docker-compose.yml`, `docker/*`, `.github/workflows/*.yml` | `audfact-runtime-docker` |
| `scripts/sync-github-production-env.sh` | `audfact-runtime-docker` + `audfact-production-ops` |
| `.agent/skills/audfact-production-ops/**` | `audfact-production-ops` |
| `.env*` | `audfact-runtime-docker` |
| `bin/*.php` (Workers) | `audfact-audit-gemini` + `audfact-runtime-docker` |
| `public/index.php` | `audfact-runtime-docker` + `audfact-security-guardrails` |
| `core/RateLimit.php`, `core/RedisClient.php` | `audfact-security-guardrails` |
| `core/Logger.php` | `audfact-security-guardrails` |
| `core/Validator.php` | `audfact-api-rest` + `audfact-security-guardrails` |
| `AGENTS.md`, `CLAUDE.md` | `audit-skill-router` |
| Todo código nuevo o modificado | `clean-rebuild-policy` |
| `frontend/**/*.tsx`, `frontend/**/*.css` | `ui-ux-pro-max`, `impeccable` |

---

> [!IMPORTANT]
> **Mantenimiento obligatorio**: Este catálogo DEBE ser actualizado por `audfact-docs-sync` si se crean, eliminan o renombran archivos gobernados. Un CATALOG.md desactualizado rompe la cadena de trazabilidad archivo→skill.
