# Changelog — AudFact

## [2026-02-22] Depuración de AGENTS.md y Modularización

**Tipo**: Documentación

**Descripción**: Reducción drástica del tamaño de `AGENTS.md` extrayendo dominios operacionales a archivos exclusivos en `plans/` para reducir latencia cognitiva y evitar *Dilución de Contexto* por parte de la IA.

**Cambios realizados**:
- Movido modelo operativo Testing a `plans/testing-strategy.md`
- Movido modelo de ramas Git a `plans/git-workflow.md`
- Movida matriz de vulnerabilidades a `plans/audit-findings.md`
- Movido glosario de términos a `plans/domain-glossary.md`
- Movidos comandos Docker a `plans/docker-operations.md`
- Movidos procedimientos de despliegue y CI/CD a `plans/deployment-and-ci.md`
- Movidas decisiones de arquitectura a `plans/architecture-decisions.md`

---------------------------------------


## [2026-02-22] Implementación de Guardrails de Seguridad y Optimización (C01-C05)

**Tipo**: Seguridad y Rendimiento

**Descripción**: Resolución de hallazgos críticos de auditoría enfocados en la estabilidad, seguridad y el rendimiento de la aplicación en procesos masivos y endpoints expuestos.

**Cambios realizados**:
- **C01 (Control de Flujo)**: Eliminación de llamadas `exit()` en controladores en favor de una nueva excepción `Core\Exceptions\HttpResponseException` gestionada a nivel global en `public/index.php`.
- **C02 (Rate Limiting)**: Refactorización de `Core\RateLimit` para utilizar memoria compartida con `APCu` de forma atómica y concurrente, manteniendo fallback al sistema de archivos.
- **C03 (Timeouts)**: Asignación dinámica de `set_time_limit(3600)` para procesos por lotes en `AuditController` y `set_time_limit(120)` interno por cada factura procesada en `GeminiAuditService`.
- **C04 (Gestión de Memoria)**: Implementación de la constante `MAX_FILE_SIZE_BYTES` (15 MB) en `AuditFileManager` para bloquear la carga a RAM (y posterior conversión a base64) de BLOBs SQL excesivamente grandes, además de ampliar el límite a 1024M para lotes masivos.
- **C05 (Autenticación MCP)**: Autenticación obligatoria para el Webhook MCP (`app/wrap/webhook.php`) mediante la exigencia de la cabecera HTTP `X-API-KEY` validada contra la nueva variable de entorno `MCP_WEBHOOK_SECRET`.
## [2026-02-20] Rediseño de Prompt v3.0 y Optimización BLOB

### Rediseño de System Instruction (v3.0)
**Tipo**: Refactorización Arquitectónica IA
- **Arquitectura**: Reestructuración de 5 capas a 4 capas (Identidad, Axiomas, Motor de Razonamiento, Formato).
- **Axiomas (A1-A4)**: Introducción de principios abstractos (Primacy of Data, Exhaustive Observation, Inference without Assumption, Derivable Severity) para mejorar determinismo.
- **Protocolo de Evaluación**: Evaluación mandatoria en 6 dimensiones (Identidad, Cuantitativa, Temporal, Descriptiva, Integridad Documental, Análisis Forense Visual).
- **Resultado**: Mejora crítica en detección de firmas faltantes y consistencia en hallazgos complejos.

### Optimización de Latencia BLOB en AuditFileManager
**Tipo**: Optimización de Rendimiento
- **O1 (Instancia Compartida)**: Reutilización de `AttachmentsModel` para evitar reconexiones PDO.
- **O3 (Stream Directo)**: Lectura de BLOBs SQL directamente a memoria (`base64`) sin pasar por archivo temporal en `/tmp`.
- **Mimetypes**: Detección unificada vía magic numbers y `finfo` en memoria.
- **Resultado**: Eliminación de I/O de disco innecesario y reducción de latencia marginal en `filePrepMs`.


## [2026-02-20] Optimización del Prompt de Auditoría IA

**Tipo**: Optimización / Investigación

**Descripción**: Investigación exhaustiva del impacto del system instruction en la latencia de la API Gemini. Se analizaron 70 respuestas JSON en 7 lotes para evaluar tres variantes del prompt: `$philosophy` original (~3,000 tokens), comprimida (~812 tokens) y eliminada (0 tokens).

**Hallazgo clave**: La latencia NO depende del tamaño del system instruction. La variabilidad del servidor Gemini (carga, hora del día, throttling) es el factor dominante. Se validó con evidencia estadística: lotes con 0 tokens y 3,000 tokens producen la misma latencia promedio (~19.5s en ventana de congestión, ~10-12s en ventana normal).

**Cambios realizados**:
- `app/Services/Audit/AuditPromptBuilder.php` — `$philosophy` restaurada a versión original tras validar que no impacta latencia
- `app/Models/DispensationModel.php` — Corrección de campo `Fecha_ori` → `Fecha_solicitud` para FechaEntrega

---

## [2026-02-19] Reestructuración de Documentación (docs-sync)

**Tipo**: Documentación

**Descripción**: Reestructuración completa de la documentación del proyecto para cumplir con el estándar de la skill `docs-sync`. Se archivaron los documentos legacy y se crearon 9 nuevos archivos siguiendo las plantillas estandarizadas.

**Archivos clave modificados**:
- `plans/overview.md` — Visión general del proyecto
- `plans/architecture.md` — Desglose de componentes
- `plans/architecture-diagrams.md` — Diagramas C4 (Level 1-4)
- `plans/data-flows.md` — 3 flujos con diagramas de secuencia
- `plans/api-endpoints.md` — 12 endpoints REST + MCP
- `plans/database-schema.md` — 8 tablas/vistas + diagrama ER
- `plans/features/audit-workflow.md` — Feature de auditoría IA
- `plans/features/mcp-integration.md` — Feature MCP
- `README.md` — Actualización general