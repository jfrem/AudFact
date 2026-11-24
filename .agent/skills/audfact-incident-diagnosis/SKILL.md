---
name: audfact-incident-diagnosis
description: Metodología determinista de diagnóstico causa raíz (RCA) en 5 capas para incidentes en AudFact (producción LAN y local). Usar cuando el usuario reporte que los jobs batch no avanzan, auditorías se quedan en pending o queued, el pipeline IA está lento o bloqueado, /health o /metrics/async fallan, aparecen errores 502/503/504, bloqueos en SQL Server, acumulación en Redis o audit.dlq, degradación de hardware o cualquier fallo operativo. No usar para desplegar o hacer rollback (usar audfact-production-ops), redactar specs SDD (usar write-sdd-spec) ni revisar diffs de código (usar rigorous-code-review).
---

# AudFact Incident Diagnosis (RCA en 5 Capas)

Protocolo basado en evidencia para aislar la **causa raíz real** de un incidente operativo —en producción LAN (`admon@172.16.0.3`, `/home/admon/audfact-prod`) o en Docker local (raíz del repo)— antes de proponer cualquier remediación.

## Principios innegociables

1. **Evidencia antes de mutar.** No reiniciar contenedores, tocar claves Redis ni ejecutar `pause`/`observe`/`resume`/`reconcile`/`gc`/`outbox` sin haber capturado antes el estado que demuestra la causa. Reiniciar a ciegas destruye la evidencia (PEL, logs, sockets colgados) y oculta fallos recurrentes.
2. **De lo barato a lo profundo.** Paso 0 (HTTP de solo lectura) → Capa 1 Host → Capa 2 Docker → Capa 3 Redis v2 → Capa 4 Dependencias externas → Capa 5 Dominio. Saltar una capa solo si el Paso 0 ya la descarta con evidencia explícita.
3. **Buscar causas concurrentes.** No cerrar con el primer hallazgo si no explica el 100 % del síntoma. Caso real: *Head-of-Line blocking* SQL `08S01` en `worker-batch` + `audit:admission:paused = 1` al mismo tiempo.
4. **Descartar falsos positivos** antes de declarar un bloqueo (tabla al final de [symptom-playbooks.md](references/symptom-playbooks.md)).
5. **Medir dos veces.** "Atascado" exige dos lecturas separadas ≥ 60 s sin avance de `done`/`updated_at` ni descenso de `lag`/`pending`.
6. **Seguridad.** En remoto aplicar los guardrails de [audfact-production-ops](../audfact-production-ops/SKILL.md): nunca imprimir `.env`, passwords, tokens ni PII de pacientes; aprobación explícita del usuario antes de toda acción mutante.

## Paso 0 — Triaje HTTP sin SSH

Base URL: `http://172.16.0.3:8080` (producción) o `http://localhost:8080` (local). Comandos en [diagnostic-commands.md § Paso 0](references/diagnostic-commands.md#paso-0--triaje-http-sin-ssh).

| Orden | Endpoint | Qué confirma o descarta |
|---|---|---|
| 1 | `GET /health` | `services.database` (`default`), `database_read` (`db2`), `redis`, `disk` con `status` y `latency_ms`. `memory` es informativo y no degrada el estado global. |
| 2 | `GET /metrics/async` | `streamBacklogs`, `executionScheduler`, `persistenceScheduler`, `geminiBudget`, `documentCache`, `executionMaintenance`, `deadLetterDepth`, `terminalFailures`, `jobs`. **Si responde 503**, la falla ya es Capa 3: Redis caído o protocolo v2 roto (`assertProtocol`). |
| 3 | `GET /audit/jobs` | Lotes recientes: `status`, `total`, `done`, `failed`, `pending`, `created_at` vs `updated_at`. Detalle: `/audit/jobs/{jobId}` (incluye `sealed` y `audits[]` con `dis_det_nro` y `failed_stage`), `/audit/status/{auditId}`, `/audit/results/{facNro}`, `/audit/{facNro}/timings`. |

> **Ojo con los nombres:** en `/metrics/async`, `jobs.pending` = jobs creados **sin auditorías todavía**, `jobs.queued` = auditorías en stream sin ninguna completada, `jobs.running` = al menos una completada. No equivalen al `pending` por job de `/audit/jobs`.

### Señal → capa → playbook

| Señal observada | Capa sospechosa | Siguiente paso |
|---|---|---|
| `/health` timeout o HTTP 502/504 | 2 (Nginx/PHP-FPM) o 4 (SQL/Redis) | [Playbook E](references/symptom-playbooks.md#playbook-e--health-en-unhealthy-http-502504-o-api-lenta) |
| `/metrics/async` → 503 | 3 (Redis/protocolo) | Capa 3.1: `durability-check` y banderas |
| `executionMaintenance.heartbeatAgeMs > 10000` o `null` | 2/3 (`worker-execution-maintenance`) | Provoca 503 en admisión y hace fallar `resume`. [Playbook B](references/symptom-playbooks.md#playbook-b--http-503-audit_capacity_unavailable-al-encolar-auditorías) |
| `streamBacklogs.batchInbox.batch-workers.lag > 0` con jobs `pending`/`total: 0` | 3 (pausa/`atCapacity`) + 4 (`db2` `08S01`) | [Playbook A](references/symptom-playbooks.md#playbook-a--jobs-batch-detenidos-en-pending-total-0-done-0) |
| `total > 0` pero `done` no avanza | 3/4 según la etapa | [Playbook C](references/symptom-playbooks.md#playbook-c--auditorías-o-jobs-congelados-en-processing--queued) |
| `documentCache` ≥ 90 % bytes/entradas o `prefetch_pauses` creciendo | 3/4 (prefetch gate + extracción lenta) | Pausa **intencional** del downloader (histéresis 90/70); buscar la causa aguas abajo en extracción/Gemini. |
| `geminiBudget` (`rpm_used`, `tpm_reserved`, `rpd_used`, `inflight`) cerca del límite o `executionScheduler.extraction/semantic.*.delayed` alto | 4 (cuota Gemini) | Comparar con `GEMINI_QUOTA_*`; logs 429/5xx en `worker-extraction-*` y `worker-semantic-*`. |
| `persistenceScheduler.pending` alto con `active` congelado | 4 (SQL `default`) | Logs de `worker-persistence`; `AUDIT_PERSISTENCE_JOB_SLOTS` (1–16). |
| `executionMaintenance.outboxPending > 0` sostenido | 2/3 (`worker-execution-maintenance`) | Logs del servicio; el outbox durable no drena. |
| `deadLetterDepth > 0` | 4/5 | [Playbook D](references/symptom-playbooks.md#playbook-d--acumulación-en-dead-letter-queue-deadletterdepth--0) |

## Las 5 capas

Si el Paso 0 no aísla la causa, recorrer las capas **en orden** con los comandos de [diagnostic-commands.md](references/diagnostic-commands.md). Cada capa termina con un veredicto: *sana* (con 1 línea de evidencia) o *causa encontrada*.

### Capa 1 — Host, hardware y SO
- Carga relativa a `nproc` e `iowait`; RAM libre > 15 % y sin *OOM kills* en `dmesg`; errores ECC = 0.
- Disco e inodos de `/` y volúmenes Docker < 85 %; RAID si aplica.
- Throttling térmico y errores/drops de interfaz ≈ 0.

### Capa 2 — Topología Docker y runtime
- Servicios de `docker-compose.yml`: `frontend`, `nginx`, `php` (5 réplicas), `redis`, `docs`, `worker-batch` (`AUDIT_WORKER_BATCH_REPLICAS`, default 4), `worker-orchestrator`, `worker-downloader`, `worker-extraction-vip`, `worker-extraction-batch`, `worker-normalizer`, `worker-policy`, `worker-semantic-vip`, `worker-semantic-batch`, `worker-persistence`, `worker-execution-maintenance`.
- `RestartCount`, `OOMKilled`, `ExitCode` y health por contenedor; consumo con `docker stats`.
- Variables faltantes tras un deploy → runner `actions.runner.jfrem-AudFact.produccion-audfact.service` (ver audfact-production-ops).

### Capa 3 — Redis y protocolo durable v2
AudFact opera solo con protocolo v2. Verificar en este orden:
1. **Protocolo y durabilidad:** `bin/audit-execution-maintenance.php durability-check` exige `appendonly=yes`, `appendfsync=always`, `maxmemory-policy=noeviction`, `aof_last_write_status=ok` y `aof_rewrite_in_progress=0`. `audit:execution:version` debe ser `2`.
2. **Banderas y heartbeat:**
   - `audit:execution:paused = 1` → detiene la ingesta de **todos** los consumers.
   - `audit:admission:paused = 1` → bloquea admisión HTTP (503) y al grupo `batch-workers`. Es el estado que deja `observe` (modo drenaje: ejecuta lo admitido, no admite nuevo) y también `pause`.
   - `audit:execution:heartbeat`: edad ≤ 10000 ms.
3. **Capacidad (`AuditAdmissionGuard::atCapacity`)**, que también bloquea a `batch-workers`:
   - Cualquier etapa (`orchestration`, `extraction`, `policy`, `semantic`) con `queued + delayed ≥ floor(AUDIT_EXECUTION_MAX_QUEUED_PER_STAGE × 0.8)` (default 20000 → 16000).
   - Redis `used_memory ≥ 0.8 × maxmemory`.
4. **Streams, grupos, PEL y DLQ:** `XINFO GROUPS` y `XPENDING` (mirar `idle_ms`) en `audit.batch.inbox`, `audit.inbox.*`, `audit.documents.*`, `audit.semantic.*`, `audit.persistence.*`; `XLEN` de la DLQ (`AUDIT_DLQ_STREAM`, default `audit.dlq`).
5. **Trampas:** `redis-cli` exige el prefijo `REDIS_PREFIX` (default `audfact:`) que `Core\RedisClient` agrega solo. `job:{id}:state` y `audit:{id}:state` son **strings JSON** (`GET`), no hashes.

### Capa 4 — Dependencias externas (SQL Server, Gemini, Drive/PDF)
Correlacionar la etapa lenta con logs JSON (stderr de cada contenedor):
- **SQL Server** (`db2` lectura / `default` escritura): `SqlServerConnectionExecutor: reintento transitorio` / `operacion fallida`, `sql_state: 08S01` (enlace TCP caído) y `elapsed_ms` de minutos. Patrón conocido: `InvoicesModel::getInvoicesForAuditBatch` (tablas temporales `#CRUZE`/`#ADJ`/`#Sopo`) de un cliente masivo monopolizando todas las réplicas de `worker-batch`.
- **Gemini:** `geminiBudget` + logs de `GeminiGateway`, `DocumentExtractionWorker`, `SemanticEvaluationWorker` (429, 5xx, `GeminiResponseException`, `DEFERRED`).
- **Drive/BLOB/PDF:** `AttachmentDownloadWorker` (`AttachmentDownloadException`, `AuditDocumentPrefetchGate`) y `DocumentPdfRasterizer` (timeouts `PDF_RASTERIZER_TIMEOUT_SECONDS` vs rechazo esperado `DOCUMENT_EXCEEDS_PAGE_LIMIT`).

### Capa 5 — Dominio y consistencia de datos
- Ciclo de vida del `job_id`/`audit_id` (`/audit/jobs/{id}`, `/audit/status/{id}` o snapshot Redis).
- Job antiguo en `pending`/`queued`: **antes de re-encolar**, comprobar cobertura con `GET /audit/results?facNitSec=<NIT_SEC>&dateFrom=<fecha>` y con los totales de jobs posteriores del mismo cliente. `/audit/results/{facNro}` usa **FacNro**, no `dis_det_nro`.
- `GET /audit/{facNro}/timings` separa espera en cola, descarga, Gemini, semántica y `sql_persist_ms`.
- `manual_review` suele ser resultado de negocio (`DOCUMENT_MAPPING`, `DocumentRejectionReason`), no fallo técnico.

## Ejecución remota segura (Windows → producción)

1. Usar siempre [Invoke-AudFactProdSsh.ps1](../audfact-production-ops/scripts/Invoke-AudFactProdSsh.ps1) con la clave solo en `$env:AUDFACT_SSH_PASSWORD` del proceso, limpiándola en `finally`.
2. Scripts multilínea: codificar en Base64 y ejecutar `echo <b64> | base64 -d | bash` (patrón en [diagnostic-commands.md](references/diagnostic-commands.md#patrón-de-ejecución-remota-powershell--base64--wrapper-ssh)). Nunca incluir credenciales en el script.
3. Si el sandbox local bloquea la red hacia `172.16.0.3`, repetir el **mismo** comando de solo lectura fuera del sandbox; no cambiar su objetivo.
4. PHP CLI en contenedor: `require '/var/www/html/vendor/autoload.php'; \Core\Env::load(); $r = \Core\RedisClient::getInstance();` (en producción las variables llegan por `env_file`; `Env::load()` es inocuo). `RedisClient` expone `get`, `hGetAll`, `scanKeys`, `xLen`, `xRange`, `xPending`, `serverInfo`, `serverConfiguration`, `eval`; **no** existen `keys()`, `getConnection()` ni `executeRaw()`.
5. En local omitir SSH: ejecutar los mismos bloques desde la raíz del repo con `docker compose`.

## Remediación quirúrgica y verificación

1. **Proponer:** evidencia por capa, causa(s) demostrada(s) y el plan mínimo (servicio concreto, nunca todo el stack si no hace falta). Catálogo de comandos mutantes y sus precondiciones en [diagnostic-commands.md § Comandos mutantes](references/diagnostic-commands.md#comandos-mutantes-solo-con-aprobación-explícita).
2. **Esperar aprobación explícita** antes de cambiar Docker, Redis o SQL Server.
3. **Ejecutar** la remediación mínima reversible. `resume` falla con `maintenance_unhealthy` si el heartbeat está vencido o hay `atCapacity`: corregir eso primero. Si un comando de mantenimiento falla, conservar la pausa y leer el log `Audit execution maintenance failed`.
4. **Verificar** tras 30–120 s: `/metrics/async` y `/audit/jobs` muestran caída de `lag`/`pending`, avance de `done`/`updated_at` y ausencia de errores nuevos en logs. Si no hay mejora en dos lecturas, volver a la capa siguiente: la causa no estaba aislada.

## Reporte RCA obligatorio

1. **Síntoma inicial:** endpoint, job/audit afectado y ventana horaria.
2. **Descarte por capas:** Paso 0 y Capas 1–5, una línea de evidencia real por capa evaluada (o "no evaluada: <motivo>").
3. **Causa(s) raíz:** mecanismo técnico exacto con la métrica, log o clave que lo prueba.
4. **Remediación:** propuesta o aplicada, con los comandos ejecutados tras aprobación.
5. **Verificación:** antes vs después, y recomendación preventiva si hay deuda estructural que repita el incidente.

## Recursos

- [references/diagnostic-commands.md](references/diagnostic-commands.md): comandos de solo lectura por capa y catálogo de comandos mutantes con precondiciones.
- [references/symptom-playbooks.md](references/symptom-playbooks.md): playbooks A–E por síntoma y guía de falsos positivos.
- [audfact-production-ops](../audfact-production-ops/SKILL.md) · [audfact-audit-gemini](../audfact-audit-gemini/SKILL.md) · [audfact-runtime-docker](../audfact-runtime-docker/SKILL.md)
