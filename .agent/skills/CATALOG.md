# AudFact Skills Catalog

Colección de skills específicas para el proyecto `AudFact` — Sistema de auditoría documental IA.

## Skills

| Skill | Área | Archivos Gobernados | Descripción |
|---|---|---|---|
| `audfact-project-overview` | Contexto Global | `README.md`, `plans/*` | Visión general, arquitectura y flujos. |
| `audfact-api-rest` | Endpoints REST | `app/Routes/web.php`, `app/Controllers/*` | Endpoints en PHP MVC y validación. |
| `audfact-audit-gemini` | Auditoría IA | `app/worker/*`, `app/Services/Audit/*` | Pipeline con Gemini y servicios. |
| `audfact-sqlsrv-models` | Datos SQL Server | `app/Models/*`, `core/Database.php` | Modelos PDO sqlsrv y streams BLOB. |
| `audfact-mcp-wrap` | Protocolo MCP | `app/wrap/*` | Integración MCP y herramientas internas. |
| `audfact-runtime-docker` | Ops / Runtime | `docker/*`, `docker-compose.yml` | Entorno Docker y conectividad DB. |
| `audfact-security-guardrails` | Seguridad | `core/RateLimit.php`, `core/Logger.php` | Rate limit (100/min), CORS y logs. |
| `audit-skill-router` | Auditoría Técnica | Repositorio completo | Enrutador de auditorías amplias/ambiguas hacia dominios especializados con salida consolidada. |
| `architecture-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de arquitectura, acoplamiento, límites de módulos y escalabilidad. |
| `code-quality-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de mantenibilidad, complejidad, testabilidad y deuda técnica. |
| `security-assessment` | Auditoría Técnica | Repositorio completo | Auditoría de seguridad (auth/authz, secretos, vulnerabilidades, hardening). |
| `technical-governance-assessment` | Auditoría Técnica | Repositorio completo | Evaluación de gobernanza técnica: ownership, code review, incidentes y roadmap. |

## Bundles

| Bundle | Skills | Uso |
|---|---|---|
| `audfact-core` | `audfact-api-rest`, `audfact-sqlsrv-models` | Cambios en API + Datos |
| `audfact-ai-audit` | `audfact-audit-gemini`, `audfact-sqlsrv-models`, `audfact-security-guardrails` | Pipeline de auditoría completo |
| `audfact-integration` | `audfact-mcp-wrap`, `audfact-api-rest` | Integración con agentes IA |
| `audfact-ops` | `audfact-runtime-docker`, `audfact-security-guardrails` | Infraestructura y hardening |
| `audfact-tech-assessment` | `audit-skill-router`, `architecture-assessment`, `code-quality-assessment`, `security-assessment`, `technical-governance-assessment` | Auditorías técnicas integrales con score global |

## Mapeo Archivo → Skill

| Archivo | Skill Primaria |
|---|---|
| `app/Routes/web.php` | `audfact-api-rest` |
| `app/Controllers/*.php` | `audfact-api-rest` |
| `app/Models/*.php` | `audfact-sqlsrv-models` |
| `core/Database.php` | `audfact-sqlsrv-models` |
| `app/worker/GeminiAuditService.php` | `audfact-audit-gemini` |
| `app/Services/Audit/*.php` | `audfact-audit-gemini` |
| `app/Services/GoogleDrive*.php` | `audfact-audit-gemini` |
| `app/wrap/**` | `audfact-mcp-wrap` |
| `docker-compose.yml`, `docker/*` | `audfact-runtime-docker` |
| `.env*` | `audfact-runtime-docker` |
| `public/index.php` | `audfact-runtime-docker` + `audfact-security-guardrails` |
| `core/RateLimit.php` | `audfact-security-guardrails` |
| `core/Logger.php` | `audfact-security-guardrails` |
| `core/Validator.php` | `audfact-api-rest` + `audfact-security-guardrails` |
| `AGENTS.md`, `CLAUDE.md` | `audit-skill-router` |
