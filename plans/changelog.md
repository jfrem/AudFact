# Changelog — AudFact

## [2026-03-03] CD Pipeline — Despliegue Automático a Producción

**Tipo**: Infraestructura / CI-CD

**Cambios realizados**:
- Nuevo job `deploy` en `.github/workflows/ci.yml` que despliega automáticamente al servidor `172.16.0.3` tras CI exitoso en `main`.
- Usa `appleboy/ssh-action@v1` con autenticación SSH por password vía GitHub Secrets.
- Script remoto: `git pull` → `docker compose down` → `docker compose up --build -d` → health check.
- Documentación `plans/deployment-and-ci.md` reescrita para reflejar flujo automatizado.
- `responseIA/` condicionado a `APP_ENV=dev|test` para evitar acumulación en producción.


## [2026-03-03] Quick Fix — Purgado masivo de código muerto en AuditOrchestrator

**Tipo**: Refactoring / Clean Code

**Cambios realizados**:
- **QUAL-004**: Eliminación de código no alcanzable/muerto (dead code) en `app/Services/Audit/AuditOrchestrator.php`. La función privada `terminate()` jamás era invocada. Las variables de inyección exclusivas (`$dispensationModel` y `$attachmentsModel`) no se utilizaban en lo absoluto desde que sus rutinas originarias mutaron al nuevo componente pre-validador. Sus firmas de constructor asociadas en la carga inicial (`AuditController`) también fueron desvinculadas. Las pruebas unitarias confirman la integridad asilada.

## [2026-03-03] Sprint 4 — Reconciliación Documental y Soporte Multibranch en CI/CD

**Tipo**: Documentación / Infraestructura

**Cambios realizados**:
- **CI/CD**: `ci.yml` modificado para ejecutar el pipeline de QA en ramas `develop` y `feature/*`, en adición a `main`, alineando el comportamiento con el workflow git actual.
- **Docs Drift (PHPUnit)**: `plans/testing-strategy.md` actualizado para constatar que PHPUnit está actualmente configurado y en ejecución.
- **Docs Drift (AuditOrchestrator)**: `README.md`, `AGENTS.md` y `plans/architecture.md` saneados. Las referencias históricas y obsoletas a `app/worker/GeminiAuditService.php` fueron reemplazadas en su totalidad por el namespace correcto actual: `app/Services/Audit/AuditOrchestrator.php`.

## [2026-03-03] Sprint 3 — Nginx Hardening, Health y CI Tests

**Tipo**: Infrastructure / Security / Governance

**Cambios realizados**:
- **INFRA-001**: Nginx hardened en `nginx-ha.conf.template` y `nginx.conf`: gzip (JSON/text), 5 security headers (nosniff, DENY, XSS, CSP, Referrer-Policy), `server_tokens off`, `client_max_body_size 10m`.
- **INFRA-003**: `HealthController` ampliado: checks de disk (espacio libre), memory (usage/peak/limit), uptime, PHP version y environment.
- **GOV-001b**: PHPUnit integrado al GitHub Actions CI pipeline — tests se ejecutan automáticamente en cada push/PR.

## [2026-03-03] Sprint 2 — Calidad de Código y Quick Wins

**Tipo**: Refactoring / Quality / Security

**Cambios realizados**:
- **QUAL-003**: Eliminados 2 archivos de código muerto: `AuditPromptBuilder copy 2.php` y `AuditPromptBuilder copy.php`.
- **SEC-004**: `MCP_WEBHOOK_SECRET` generado (64 chars hex) y configurado en `.env`.
- **QUAL-002**: Extraída nueva clase `AuditPreValidator.php` con 7 validaciones pre-IA. `AuditOrchestrator::auditInvoice()` reducido de ~183 a ~80 líneas. `AuditController` factory actualizado con inyección.
- **QUAL-001**: PHPUnit 10 instalado. `phpunit.xml` creado. 14 tests unitarios (10 `AuditPreValidatorTest` + 4 `AuditPersistenceServiceTest`), 32 assertions — todos pasan.

## [2026-03-03] Sprint 1 — Remediación de Hallazgos de Auditoría

**Tipo**: Security / Governance (Sprint de remediación)

**Descripción**: Primer sprint de remediación basado en la auditoría end-to-end del proyecto.

**Cambios realizados**:
- **SEC-003** `core/Database.php`: DSN ya no tiene `Encrypt=no;TrustServerCertificate=yes` hardcodeados. Ahora lee `DB_ENCRYPT` y `DB_TRUST_SERVER_CERT` desde env vars. Producción puede habilitar `Encrypt=yes` sin cambiar código.
- **SEC-001** `.env.example`: Sección de seguridad agregada con instrucciones para migrar secretos a Docker secrets o Azure Key Vault.
- **GOV-001** `.github/workflows/ci.yml`: Pipeline CI con PHP syntax lint, validación composer, check de estructura de proyecto y scan básico de secretos hardcodeados.
- **GOV-002** `.github/CODEOWNERS`: Define ownership por directorio para enforzar code reviews. `.github/pull_request_template.md`: Template con checklist de calidad.
- `.env` y `.env.example`: Variables `DB_ENCRYPT` y `DB_TRUST_SERVER_CERT` agregadas.

## [2026-03-02] Inserción de Observaciones de Auditoría IA en `AdjuntosDispensacionDetalle`

**Tipo**: Feature (Backend)

**Descripción**: Las auditorías IA (Gemini) que detectan hallazgos ahora insertan automáticamente una observación textual en la tabla core `AdjuntosDispensacionDetalle`, mimetizando el comportamiento de un auditor humano en el ERP.

**Cambios realizados**:
- **AuditStatusModel.php**: Nuevo método `insertAuditObservation()` — resuelve cadena de PKs `FacNro → (DisId, DisDetId) → AdjDisId`, calcula `DisDetAdjDetSec` con bloqueo pesimista (`UPDLOCK, HOLDLOCK`), retry en duplicate key (SQLSTATE 23000), usuario `Z-IA`, concepto fijo `SubRecConSopCod = 30` (*RESPUESTA AUDITORIA AUTOMATIZADA*), nombre de adjunto dinámico (`AdjDisNom`).
- **AuditOrchestrator.php**: Nuevo campo `_errorOrigin` en resultados de auditoría. `terminate()` (filtros pre-IA) → `'business'`; `errorResponse()` default → `'infrastructure'`. Permite discriminar errores de negocio (campos MIPRES incompletos, documentos faltantes) de errores técnicos (timeout, quota, API caída).
- **AuditPersistenceService.php**: Guard mejorado: `insertObservationIfNeeded()` solo se ejecuta si `_errorOrigin !== 'infrastructure'`. Errores de infra se registran en log pero NO se persisten en `AdjuntosDispensacionDetalle`.
- **AuditStatusModel.php** (Idempotencia): `insertAuditObservation()` ahora verifica con `SELECT COUNT(1)` si ya existe una observación de `Z-IA` para el mismo `(DisId, DisDetId, AdjDisId)` antes de insertar. Si existe, retorna `true` sin duplicar.
- **ConceptosRechazoSoporte**: Nuevo registro `Código 30` creado en BD para clasificar observaciones de auditoría automatizada.


## [2026-02-26] AuditPromptBuilder v6.0 — Reescritura completa del System Instruction

**Tipo**: Optimización de Prompt IA (MAJOR)

**Descripción**: Reescritura completa de `AuditPromptBuilder.php` con framework de auditoría modular (§01–§09).

**Cambios realizados**:
- **§01 Documentos válidos**: Exclusión explícita de documentos judiciales (tutelas, desacatos).
- **§02 Mapa autoritativo**: Tabla determinista de documento autoritativo + alternativo por cada campo.
- **§03 Reglas de comparación**: Reglas específicas por tipo (VlrCobrado cero-equivalencia, NombreArticulo tokens críticos, Cliente entidad/régimen, IPS coincidencia parcial, días de tratamiento desambiguación, datos ilegibles).
- **§04 Severidades fijas**: Tabla de severidad por campo (alta/media/baja) en vez de decisión arbitraria de Gemini.
- **§05 Reglas de negocio**: Cantidades (entrega parcial OK, sobreentrega = fraude), orden lógico de fechas, MIPRES, multi-línea, firma acta (admite tercero autorizado).
- **§06 Clasificación**: Sistema ternario + ilegible (COINCIDE / VALOR_DISTINTO / NO_ENCONTRADO / ILEGIBLE). Tolerancia a Cliente Régimen "N/D" en aseguradoras o regímenes especiales.
- **§07 Risk score**: Fórmula explícita de cálculo.
- **§08 Auto-auditoría**: Checklist de 12 puntos de verificación pre-entrega.
- **§09 Formato**: Items vacío en success, solo discrepancias en warning/error.
- **Workflow**: Lee → Calibra → Compara → Auto-audita → Entrega.
- **Preprocesamiento PHP**: IPS limpia sin prefijo de régimen, Cliente split en entidad + régimen.
- **buildUserPrompt**: Eliminado JSON de dispensación redundante, reduciendo el tamaño del prompt y rebajando la latencia en ~50% (de ~25-30s a ~14s).
- **GeminiAuditService**: Se flexibilizan campos requeridos eliminando `IdFact` de la lista obligatoria al evaluar servicios MIPRES, evitando falsos fallos previos al envío a la IA.
- **AttachmentsModel**: Condición de `LEFT JOIN` con `DispensacionDetalleServicio` robustecida en `getAttachmentBlobStreamByIdForDispensation` y `getAttachmentMetadataByIdForDispensation` (se agrega `AND d.DisDetId=a.DisDetId`) garantizando emparejamiento exacto de adjuntos.
- **AuditPromptBuilder (Régimen)**:
  - **Eliminado** el `preg_match` que intentaba parsear el régimen desde el string `Cliente`. Ahora se extrae directamente del campo estructurado `$ref['RegimenPaciente']` de la BD, garantizando 100% de fiabilidad.
  - Regla de comparación de régimen cambiada de **exacta** a **semántica**: tabla de equivalencias (SUBSIDIADO≈S/SUB, CONTRIBUTIVO≈C/CONT, ESPECIAL≈ARL/PREPAGADA, VINCULADO≈V).
  - Exención explícita: si Régimen de Fuente de Verdad es `N/D`, se omite validación del régimen sin reportar error.
  - Reforzado en auto-checklist §08 punto 6 como verificación crítica.
- **AuditPromptBuilder (Firma)**: Regla de `FirmaObligatoria` ampliada para aceptar firma manuscrita, huella dactilar o sello de recibido del paciente/tercero.

---

## [2026-02-24] Migración de Fuente de Datos: `dbo.factura` → `vw_discolnet_dispensas`

**Tipo**: Refactorización de Datos

**Descripción**: Migración de `InvoicesModel` desde la tabla `dbo.factura` a la vista `vw_discolnet_dispensas`, eliminando los aliases SQL temporales (`FacNitSec`, `FacNro`, `DisId`) y adoptando los nombres reales de columna (`NitSec`, `FacSec`, `Dispensa`).

**Cambios realizados**:
- **InvoicesModel.php**: Query migrada a `vw_discolnet_dispensas`. Aliases `AS FacNitSec`, `AS FacNro`, `AS DisId` eliminados. Columna `DisId` eliminada (era duplicado de `FacSec`).
- **AuditController.php**: Variables renombradas (`$FacNro`→`$Dispensa`, `$disId`→`$facSec`). Mensaje de error actualizado.
- **AuditFileManager.php**: Eliminado fallback `$d['DisId']` en log de diagnóstico.
- **AuditSingle.html**: 7 referencias actualizadas (header tabla, mapeo directo, render, visor de docs, ejecución de auditoría).
- **AuditBatch.html**: 5 referencias actualizadas (header tabla, modal detalle, info bar, tabla de resultados).
- **Docs**: `api-endpoints.md` (esquema de respuesta `/invoices` y body `/audit/batch`), `database-schema.md` (nota de migración en tabla `factura`, actualización de ER diagram).

**Archivos no modificados** (confirmados por tracing de código): `GeminiAuditService.php`, `AuditStatusModel.php`, `InvoicesController.php` — usan `DispensationModel` independientemente.

---

## [2026-02-23] Unificación de Previsualizaciones y Migración HttpClient

**Tipo**: UI/UX, Backend, Código Limpio

**Descripción**: Unificación del comportamiento de previsualización de adjuntos en ambas interfaces (AuditSingle y AuditBatch), eliminación de axios como dependencia externa, y corrección de bugs de persistencia en el pipeline de auditoría.

**Cambios realizados**:
- **Attachment Preview Modal (AuditSingle + AuditBatch)**: Reemplazo del comportamiento de descarga (`<a download>`) y apertura en nueva pestaña (`window.open`) por un modal unificado con iframe que previsualiza documentos inline via `data:` URI (base64+MIME). Ambas UIs ahora usan el endpoint `GET /dispensation/{id}/attachments/download/{docId}` con header `Accept: application/json` para obtener datos en formato JSON (`{ mime, data }`).
- **MIME Detection (Magic Bytes)**: Nuevo método `detectMimeFromContent()` en `AttachmentsController.php` que identifica tipos MIME por magic bytes (PDF, JPEG, PNG, GIF, WEBP, TIFF, ZIP) para archivos sin extensión. Cadena de detección: extensión → magic bytes → `application/octet-stream`.
- **SIN_DOCUMENTOS Handling**: El modal de adjuntos en `AuditSingle.html` ahora maneja correctamente el tipo de almacenamiento `SIN_DOCUMENTOS` mostrando un ícono de advertencia rojo y deshabilitando el botón de previsualización.
- **Migración axios → HttpClient**: `AuditSingle.html` migrado de `axios` (CDN ~51KB) al `HttpClient.js` custom del proyecto (~23KB). Incluye: import ESM, inicialización en bootstrap con health check, adaptación de firma de GET con headers (`api.get(url, {}, { headers })`) y URLs relativas en lugar de hardcodeadas.
- **FacSec Mapping Fix (GeminiAuditService)**: Corregido bug donde `terminate()` no pasaba `$dispensation` a `saveToDatabase()`, causando que `FacSec` se guardara como `$DisDetNro` en lugar del valor real de la dispensación. Añadido parámetro `?array $dispensation = null` a `terminate()`.
- **NitSec Fallback (AuditSingle)**: La función `showServerDocs()` ahora usa `inv.FacNitSec` como fallback cuando `hiddenSelect.value` está vacío (búsqueda directa por ID).
- **Return Statements (AttachmentsController)**: Añadidos `return;` después de `Response::json()` en los bloques JSON de BLOB y URL para evitar caída al bloque de streaming binario.

---

## [Unreleased]

### Added
- **HA Architecture**: Configuración de Alta Disponibilidad (HA) para el stack HTTP con Nginx balanceando mediante `least_conn` hacia un pool de PHP-FPM configurado con `pm = static` para mitigar lentitudes de cold-start y maximizar predictibilidad.
- **Single Audit**: Nuevo endpoint `POST /api/audit/single` para la ejecución síncrona y orientada a Puntos de Dispensación de auditorías IA individuales, necesario para alta concurrencia.
- **Concurrent Logging**: Modificación de `Core\Logger` usando `gethostname()` como fragmento de archivo de logs para prevenir corrupción en volúmenes inter-réplica.
- **HA Hardening (Healthcheck)**: Nuevo script `docker/healthcheck.php` que verifica conexión real a SQL Server (`SELECT 1`) reemplazando el chequeo de socket `cgi-fcgi` que solo verificaba FPM alive.
- **HA Hardening (Nginx Rate Limit)**: Rate limiting centralizado en Nginx con 2 zonas: `api_limit` (10r/s, burst 20) para endpoints generales y `audit_limit` (2r/s, burst 5) para el endpoint de auditoría IA.
- **HA Hardening (Upstream Retry)**: `fastcgi_next_upstream` configurado para failover transparente entre réplicas PHP-FPM ante errores de conexión, timeout o headers inválidos.

### Changed
- Escalamiento del `docker-compose.yml` para soportar `deploy: replicas: 5` del servicio backend `php-fpm` reemplazando los `container_name` estáticos y agregando healthchecks.
- **Docs Sync (HA Runtime)**: Alineación documental de `plans/high-availability.md`, `plans/architecture.md` y `README.md` con la implementación vigente: healthcheck Docker vía `php docker/healthcheck.php`, separación dev (`docker-compose.dev.yml`) vs HA (`docker-compose.ha.yml`) y aclaración de limitación actual por `nginx` único (SPOF).

---

## [2026-02-23] Resolución Fase 2: Vulnerabilidades Restantes y Optimizaciones (OOM/Timeouts/XSS)

**Tipo**: Estabilidad, Seguridad y Código Limpio

**Descripción**: Segunda fase de auditoría aplicada. Se cerraron brechas de vulnerabilidades en el marco de trabajo y modelo de ejecución para evitar caídas catastróficas del servidor, OOM, y envenenamiento de los datos de IA.

**Cambios realizados**:
- **Rate Limit (Fail-Closed)**: En `core/RateLimit.php`, se eliminó el comportamiento 'Fail Open' que silenciaba errores del backend de APCu/FS. Ahora responde estrictamente con 503 en caso de falla de hardware o cache para aislar picos.
- **Router Security (Buffer & Sanitization)**: Se eliminó en `core/Router.php` el mapeo forzoso de `FILTER_SANITIZE_SPECIAL_CHARS` ya que todos los modelos usan Prepared Statements SQLSRV, impidiendo que esta función deformara o truncara datos binarios o complejos erróneamente.
- **Gemini Parser (XSS/Poisoning Prevention)**: `JsonResponseParser.php` ahora aplica `htmlspecialchars(ENT_QUOTES)` recursivamente sobre las cadenas de texto del JSON proveído por la IA, neutralizando vectores de Prompt Injection que buscaran inyectar JS/HTML al Dashboard Web.
- **Circuit Breaker & Batch Limits**: `AuditController.php` redujo el límite máximo de facturas de 50 a **10**. El circuito de tiempo máximo interno (Circuit Breaker) bajó a **110 segundos** para estar alineado y ser compatible (proxy-friendly) con Nginx y prevenir los *504 Gateway Timeout* por bloqueo severo del Worker FPM.
- **Desbloqueo de RAM (OOM Prevention)**: `AttachmentsModel.php` se depuró eliminando el sumidero de recursos `getAttachmentBlobsByInvoiceId()`. Esta función obsoleta pre-cargaba masivamente megabytes a RAM causando crasheos por contención. Ahora el flujo está enteramente estandarizado a lectura optimizada con Streams.

---

## [2026-02-22] Resolución de Hallazgos Críticos y Altos (Auditoría de Código)

**Tipo**: Estabilidad, Seguridad y Código Limpio

**Descripción**: Aplicación de 9 correcciones (_fixes_) para subsanar los principales puntos de fallo identificados en el análisis del código. Las correciones aseguran el cumplimiento de códigos de estado HTTP, detienen ejecuciones zombie y protegen el ecosistema de datos.

**Cambios realizados**:
- **Fix #1 y #2 (Router y Response)**: Manejo nativo de `HttpResponseException` en vez de ocultarlas como HTTP 500 genéricos; los helpers `success()` y `paginated()` frenan la ejecución nativamente al igual que `error()`.
- **Fix #3 (Auth)**: Intentos de implementación JWT pospuestos hasta contar con la clase `AuthMiddleware`.
- **Fix #4 (Permisos)**: Endurecimiento de creación de directorios en `GeminiAuditService` de `0777` a `0750`.
- **Fix #5 (Validator)**: La regla `required` ahora admite el valor numérico `0`.
- **Fix #6 y #9 (GeminiAuditService)**: Transición absoluta a `Env::get()` para evitar inestabilidad thread-safe y eliminación del fallback de debugging `'Hola'`.
- **Fix #7 (AuditController)**: Inclusión de límite estricto de `50` facturas por batch + Circuit Breaker por tiempo (10 minutos) que retorna respuestas parciales en lugar de un timeout total del contenedor.
- **Fix #8 (Logger)**: Optimización de I/O de disco asegurando que `loadConfig()` y `cleanupOldLogs()` operen mediante el semáforo en RAM `$initialized`.
- **Bugfix en paginated**: Definida respuesta literal 200 donde `$code` lanzaba excepción.

---

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
