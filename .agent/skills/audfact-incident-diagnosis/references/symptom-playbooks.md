# Playbooks por Síntoma y Guía de Falsos Positivos

Usar esta referencia para acelerar el diagnóstico una vez identificado el síntoma principal en el Paso 0.

---

## Playbook A — Jobs Batch Detenidos en `pending` (`total: 0, done: 0`)

**Síntoma:** `GET /audit/jobs` muestra lotes nuevos en `status: pending`, `total: 0`, con `updated_at === created_at`.

### Secuencia de verificación
1. **Revisar `/metrics/async`:** confirmar `streamBacklogs.batchInbox.batch-workers.lag` y `pending`.
2. **Comprobar compuertas en Redis (Capa 3):**
   - ¿`audfact:audit:admission:paused == "1"`? (dejado frecuentemente por `bin/audit-execution-maintenance.php observe`).
   - ¿`audfact:audit:execution:paused == "1"`?
   - ¿Memoria Redis >= 80% de `maxmemory` o alguna etapa de `FairAuditTaskQueue` >= 80% de `maxQueued`? (activa `AuditAdmissionGuard::atCapacity()` dentro de `AuditEventConsumer::intakeBlocked()`).
3. **Inspeccionar las réplicas de `worker-batch` (Capa 4):**
   - Leer las últimas 20 líneas de **cada réplica individual** (`audfact-prod-worker-batch-1..N`).
   - Buscar `SqlServerConnectionExecutor: reintento transitorio`, `sql_state: 08S01` y `elapsed_ms` superior a varios minutos.
   - Identificar si todas las réplicas están monopolizadas por el mismo cliente masivo (ej. `fac_nit_sec: 2624` en `InvoicesModel::getInvoicesForAuditBatch`) → ***Head-of-Line blocking***.
4. **Verificar `XPENDING` de `batch-workers` en `audfact:audit.batch.inbox`:**
   - Si los IDs de los jobs recientes están en `lag` (aún no entregados) o en `XPENDING` con `idle_ms` creciente, el bloqueo es **activo**.
   - Si `lag == 0` y los jobs `pending` son de ventanas horarias antiguas (ayer o hace varias horas), pasar al **Falso Positivo #3** (jobs huérfanos históricos).

### Remediación típica (requiere aprobación explícita, en este orden)
1. Si los workers están colgados en sockets TCP `08S01`: `docker compose restart worker-batch` (los mensajes del PEL se reclaman al reiniciar; no se pierden).
2. Si `audit:admission:paused == "1"`: confirmar antes `heartbeatAgeMs <= 10000` y que no hay `atCapacity`; solo entonces `docker compose exec -T php php bin/audit-execution-maintenance.php resume`. Si responde `maintenance_unhealthy`, una de esas dos precondiciones falla: resolverla, no reintentar a ciegas.
3. Si la causa fue un cliente masivo monopolizando réplicas, registrar la deuda (consulta pesada / falta de fairness por cliente) en la recomendación preventiva del RCA.
4. Verificar que `lag` caiga hacia `0` y que los jobs pasen a `processing` o `completed`.

---

## Playbook B — HTTP `503` (`audit_capacity_unavailable`) al Encolar Auditorías

**Síntoma:** `POST /audit/single` o `POST /audit/async` rechaza solicitudes con `503`.

### Causas posibles en `AuditAdmissionGuard::assertAccepting()` (orden real de evaluación)
1. **Protocolo o durabilidad Redis rota (`AuditExecutionConfig::assertProtocol()`):** se evalúa primero; en ese caso `/metrics/async` también responde 503.
   - `audit:execution:version !== '2'`
   - `appendonly !== 'yes'`, `appendfsync !== 'always'`, `maxmemory-policy !== 'noeviction'`, `aof_last_write_status !== 'ok'` o `aof_rewrite_in_progress !== 0` (verificar con `durability-check`).
2. **Pausa operativa activa:** `audit:admission:paused === '1'` o `audit:execution:paused === '1'` (tras `pause` u `observe`).
3. **Heartbeat vencido (`heartbeatAgeMs > 10000` o `null`):** `worker-execution-maintenance` caído, bloqueado o sin iniciar. Revisar `docker compose ps` y sus logs.
4. **Capacidad saturada (`atCapacity()`):**
   - Alguna etapa (`orchestration`, `extraction`, `policy`, `semantic`) tiene `queued + delayed >= floor(maxQueued * 0.8)`.
   - Redis `used_memory >= maxmemory * 0.8`.
5. **Cuota Gemini mal configurada:** `GEMINI_QUOTA_SCOPE=UNCONFIGURED` o `GEMINI_QUOTA_{RPM,TPM,RPD,MAX_INFLIGHT} <= 0`.

---

## Playbook C — Auditorías o Jobs Congelados en `processing` / `queued`

**Síntoma:** El batch sí creó auditorías (`total > 0`), pero `done` no incrementa.

### Cómo localizar el cuello de botella exacto usando `/metrics/async`
Recorrer el DAG de izquierda a derecha y ubicar la **primera etapa con cola creciente y salida detenida**:

| Etapa / Señal en `/metrics/async` | Worker responsable | Qué revisar primero |
|---|---|---|
| `inbox_priority/batch` lag > 0 o `executionScheduler.orchestration` | `worker-orchestrator` | Latencia/errores en `AuditDataService` (FDV/catálogo en `db2`) y `DocumentAttachmentMatcher`. |
| `streamBacklogs.documents_*.downloaders` crece | `worker-downloader-vip` / `worker-downloader-batch` | Revisar `documentCache` (`prefetch_pauses`, bytes/entradas >= 90% por `AuditDocumentPrefetchGate`), `GoogleDriveAuthService` o lecturas BLOB en SQL. |
| `streamBacklogs.documents_*.extractors` crece o `executionScheduler.extraction` (`queued`/`delayed` altos) | `worker-extraction-vip` / `worker-extraction-batch` | Revisar `geminiBudget` (RPM/TPM/RPD/inflight agotados), errores HTTP Gemini 429/5xx, o rasterización PDF lenta (`PDF_RASTERIZER_TIMEOUT_SECONDS`). |
| `streamBacklogs.documents_*.normalizers` crece | `worker-normalizer` | Revisar logs de `DocumentNormalizer` (excepciones de payload/parsing). |
| `streamBacklogs.documents_*.policy`, `semantic_*.semantic` o `executionScheduler.policy`/`semantic` crece | `worker-policy` / `worker-semantic-vip/batch` | Revisar `executionMaintenance.semanticPlans`, `geminiBudget` y logs de `SemanticEvaluationWorker`. |
| `persistenceScheduler.pending` crece con `active` inmóvil | `worker-persistence` | Revisar locks/timeouts en SQL Server `default` (`AuditResultPersistenceModel`, `SqlServerConnectionExecutor`) y slots por job (`AUDIT_PERSISTENCE_JOB_SLOTS`). |
| `executionMaintenance.outboxPending > 0` sostenido | `worker-execution-maintenance` | El outbox durable no se está vaciando; revisar logs de `worker-execution-maintenance`. |

---

## Playbook D — Acumulación en Dead Letter Queue (`deadLetterDepth > 0`)

**Síntoma:** `deadLetterDepth` o `terminalFailures` aumentan en `/metrics/async`.

### Secuencia de verificación
1. Consultar `GET /audit/dlq` (requiere `X-API-KEY` o `Bearer`) o inspeccionar `XLEN` / `XREVRANGE` sobre `audfact:audit.dlq` (valor de `AUDIT_DLQ_STREAM`).
2. Agrupar los eventos por `failed_stage` + `last_error_code` + `last_error_message`.
3. Clasificar:
   - **Fallo de dependencia externa** (SQL `08S01`, Drive 5xx, Gemini 503/timeout agotado): corregir la dependencia antes de reprocesar.
   - **Error de contrato o dato no manejado**: abrir bugfix/SDD en el worker causante; reprocesar antes del fix solo volverá a llenar la DLQ.
4. **Regla estricta:** Nunca podar o borrar `audit.dlq` manualmente sin archivo y aprobación. Para recuperar auditorías fallidas tras resolver la causa, usar `POST /audit/dlq/reprocess` bajo aprobación.

---

## Playbook E — `/health` en `unhealthy`, HTTP `502`/`504` o API Lenta

**Síntoma:** El frontend o `curl` recibe timeouts, `502 Bad Gateway`, `504 Gateway Time-out` o `/health` reporta `unhealthy`.

### Secuencia de verificación
1. Leer `services` en `GET /health`:
   - `database` (`default`) o `database_read` (`db2`) en `fail` → revisar `DB_HOST`/`DB2_HOST` (deben ser host/IP limpio sin `host\instancia` y puerto `1433`), firewall LAN y logs de `php`.
   - `redis` en `fail` → revisar contenedor `audfact-redis`, `REDIS_PASSWORD` y `OOMKilled`.
   - `disk` en `fail` → `< 100 MB` libres en `./logs`; revisar rotación de logs y espacio de `/dev/sda`.
2. Si `nginx` responde `502`/`504`:
   - Revisar `docker compose ps` (confirmar réplicas `php` en `healthy`).
   - Revisar `docker logs audfact-nginx --tail=80` (confirmar resolución DNS `127.0.0.11` hacia `php:9000` y saturación de `pm.max_children` en PHP-FPM).

---

## Guía de Falsos Positivos Comunes en AudFact

Revisar siempre esta tabla antes de reportar una incidencia o proponer reinicios:

| Apariencia engañosa | Realidad verificable | Cómo comprobarlo |
|---|---|---|
| Job con `status: completed` y `total: 0` | **Normal (no es fallo).** El worker ejecutó la consulta SQL y no encontró facturas pendientes en el rango. | `sealed: true` y `updated_at > created_at` en `GET /audit/jobs/{jobId}` + log `Executed SQL audit batch candidates ... result: 0`. |
| `queueDepth > 0` o `pending > 0` en `/metrics/async` | **No implica atasco por sí solo.** Suma mensajes activos en vuelo (ej. 4 réplicas `worker-batch` ejecutando chunks legítimos). | Verificar `idle_ms` en `XPENDING` y si `done`/`updated_at` avanzan en 2 lecturas separadas por 60 s. |
| Jobs viejos en `pending` (`total: 0`) mientras jobs más recientes del mismo cliente ya están `completed` | **Huérfanos históricos** de un incidente anterior cuyos documentos ya fueron cubiertos por tandas posteriores del cron. | Verificar `lag == 0` en `audit.batch.inbox` y confirmar con `GET /audit/results?facNitSec=<NIT_SEC>&dateFrom=<fecha>` o con los totales de jobs posteriores del mismo `fac_nit_sec`. |
| `jobs.pending > 0` en `/metrics/async` | **Semántica distinta a `/audit/jobs`.** Cuenta jobs creados que aún no generaron auditorías; `jobs.queued` = auditorías en stream sin ninguna completada. | Contrastar con `GET /audit/jobs` y con `batchInbox.batch-workers.lag`. |
| `prefetch_pauses` creciendo en `documentCache` | **Contrapresión intencional** de `AuditDocumentPrefetchGate` (pausa al 90 %, reanuda al 70 %). No es la causa raíz. | Buscar la etapa lenta aguas abajo (extracción/Gemini) que no libera caché. |
| `resume` devuelve `maintenance_unhealthy` | **El script protege el pipeline**: heartbeat vencido o `atCapacity`. | Revisar `heartbeatAgeMs` y la capacidad (Capa 3) antes de reintentar. |
| `ERR no such key` al ejecutar `redis-cli` | **Falta el prefijo `REDIS_PREFIX`.** AudFact almacena bajo `audfact:*`. | Usar `audfact:audit.batch.inbox`, `audfact:audit.dlq`, `audfact:job:<id>:state`. |
| `WRONGTYPE` al ejecutar `HGETALL job:<id>:state` | **Tipo incorrecto.** Los snapshots de job y auditoría son strings JSON serializados. | Usar `GET audfact:job:<id>:state` o `$redis->get("job:{$id}:state")`. |
| Auditoría terminada en `manual_review` con `EstAud = 0` | **No es fallo técnico.** Es un resultado de negocio válido (incertidumbre documental, `DOCUMENT_MAPPING` o `DocumentRejectionReason` como `DOCUMENT_EXCEEDS_PAGE_LIMIT`). | Confirmar `auditExecuted: true` y `EstadoDetallado: manual_review` en `GET /audit/results/{FacNro}`. |
