---
name: audfact-project-overview
description: >
  Visión general del proyecto AudFact. Usar cuando el usuario solicite "entender la arquitectura",
  "mapear dependencias", "obtener visión general", "analizar estructura", "identificar componentes",
  "revisar organización", "hacer diagrama de arquitectura" o "explicar cómo está organizado".
---

# AudFact — Project Overview

## ¿Qué es AudFact?
Sistema de auditoría documental automatizada que compara documentos escaneados (Actas de Entrega) contra datos de dispensación en SQL Server, usando **Google Gemini Flash** como motor de análisis multimodal.

> [!NOTE]
> Para una visión más profunda, consulta [overview.md](/plans/overview.md) y [architecture.md](/plans/architecture.md).

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2-FPM — Framework MVC custom |
| Base de datos | SQL Server (PDO `sqlsrv`) |
| IA | Google Gemini Flash API (Guzzle HTTP) |
| Almacenamiento | Google Drive (JWT) + BLOB en BD |
| Web Server | Nginx 1.25 (vía Docker) |
| Contenedores | Docker Compose (php + nginx) |

## Estructura

```
AudFact/
├── app/
│   ├── Controllers/     # 8 controladores REST
│   ├── Models/          # 6 modelos SQL Server
│   ├── Services/        # GoogleDrive + Audit/ (10 servicios)
│   ├── Routes/web.php   # 15 endpoints
│   └── wrap/            # Integración MCP (4 tools)
├── core/                # Framework: Router, Database, Validator, Response, Logger, RateLimit, Middleware, Env, Route
├── public/index.php     # Bootstrap: CORS, rate limit, exception handler, dispatch
├── docker/              # Dockerfile (PHP + Nginx), nginx.conf, xdebug.ini
├── docker-compose.yml   # php (HA: 5 réplicas) + nginx
├── tests/               # CLI tests + install script
├── responseIA/          # Resultados de auditoría IA
└── logs/                # Logs rotativos
```

## Endpoints REST (15)

| Método | URI | Controlador |
|---|---|---|
| GET | `/` | Controller::index |
| GET | `/health` | HealthController::status |
| GET | `/config/public` | ConfigController::publicConfig |
| GET | `/clients` | ClientsController::index |
| GET | `/clients/{clientId}` | ClientsController::show |
| POST | `/clients` | ClientsController::lookup |
| GET | `/invoices` | InvoicesController::index |
| POST | `/invoices` | InvoicesController::search |
| GET | `/dispensation/{invoiceId}/attachments/{nitSec}` | AttachmentsController::showByDispensation |
| GET | `/dispensation/{invoiceId}/attachments/download/{attachmentId}` | AttachmentsController::downloadByDispensation |
| GET | `/dispensation/{DisDetNro}` | DispensationController::show |
| POST | `/dispensation` | DispensationController::lookup |
| GET | `/audit/results` | AuditController::results |
| POST | `/audit` | AuditController::run |
| POST | `/audit/single` | AuditController::single |

## Flujo principal — Auditoría IA

```
1. POST /audit → AuditController
2. → AuditOrchestrator.orchestrate()
3.   → DispensationModel (source of truth)
4.   → AttachmentsModel → AuditFileManager (BLOB a memoria | Drive URL descarga)
5.   → AuditPromptBuilder (Prompt v3.0 con 4 capas y axiomas)
6.   → GeminiGateway (retry + backoff)
7.   → JsonResponseParser → AuditResultValidator
8.   → AuditPersistenceService → AudDispEst (upsert)
9.   → AuditTelemetryService (métricas)
```

## Skills disponibles

| Skill | Cuándo usarla |
|---|---|
| `audfact-api-rest` | Cambios en rutas, controladores, validación, respuestas HTTP |
| `audfact-audit-gemini` | Pipeline de auditoría IA, prompts, retry, JSON parsing |
| `audfact-sqlsrv-models` | Modelos, queries SQL, Database.php, BLOB |
| `audfact-mcp-wrap` | Integración MCP, tools, webhook, capabilities |
| `audfact-runtime-docker` | Docker, Nginx, PHP-FPM, .env, conectividad |
| `audfact-security-guardrails` | Rate limit, CORS, validación, logging, archivos |

## Patrones de diseño

- **Singleton**: `Database::getConnection()` — pool de conexiones PDO.
- **Strategy**: `GoogleDriveServiceInterface`.
- **Builder**: `AuditPromptBuilder`.
- **Chain of Responsibility**: Middleware pipeline.
- **Facade**: `Response::success()` / `Response::error()`.
- **Retry with Backoff**: `sendGeminiRequestWithRetry()`.

## Instrucciones para el agente

1. **Antes de modificar**, identificar qué skill aplica.
2. **Consultar la documentación en `plans/`** para contexto arquitectónico.
3. **Seguir el flujo de trabajo** definido en la skill específica.
4. **Verificar consistencia** con los diagramas de arquitectura en `plans/architecture-diagrams.md`.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio significativo en el proyecto, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los conteos de controllers, models, services, endpoints siguen correctos?
   - ¿La estructura de directorios refleja la realidad?
   - ¿La tabla de endpoints está completa?
   - ¿El flujo principal de auditoría refleja el orquestador actual?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.
