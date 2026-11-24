# SDD-BATCH-001 (Fase 2): Estabilización del Pipeline Batch y Almacenamiento v2 en Redis

> **Versión:** 1.2 (revisión 2026-11-22; 1.1 = verificación cruzada del mismo día; 1.0 = redacción original 2026-11-18/19) · **Estado:** borrador sin implementar · ver `Registro de revisión ASEO (2026-11-22)`.
>
> **Documento relacionado (diagnóstico empírico externo):** `Diagnóstico batch NIT 2624.md` (hallazgos `H1–H11`, evidencias `E1–E21`, observaciones `O1–O5`, línea base `main @ 3396897`). El diagnóstico se ejecutó en **Docker Compose local (WSL) contra `DiscolmetsGx2QA`** (`.53`/`.58`); todo impacto productivo derivado es `[INFERIDO]`.
>
> **Prerrequisito obligatorio:** `plans/sdd-hotfix-mantenimiento-ejecucion.md` (**SDD-BATCH-000, Fase 1**) desplegado y estable. La Fase 1 entrega, sin migración de esquema Redis: `gc()` sin OOM con lectura solo-`status` y cursores persistidos (`H11`), heartbeat unificado `30.000 ms` + `audit:execution:heartbeat:meta`, deadline global de `tick()`, outbox en ráfaga, intake con `XACK` por lotes (`H6–H9`) y TTL obligatorio en `batch:idem:*`. Esta Fase 2 **no redefine** esos mecanismos: solo los extiende a las claves v2 (Sección 13, `AuditExecutionMaintenance`).
>
> **Convención de identificadores:** `H1–H11` se usan **exclusivamente** con el significado del diagnóstico. Los defectos detectados en revisiones de esta especificación se identifican como `R-*` (ver «Registro de revisión verificada»); los hallazgos del diagnóstico de producción del 2026-11-19, como `P-F2-*`; las correcciones de la tercera, cuarta y quinta pasada, como `X-F2-*`, `R4-F2-*` y `R5-F2-*`.

### Registro de revisión verificada (quinta pasada, 2026-11-19)

Verificación cruzada de cierre sobre especificaciones y evidencia RCA productiva (`R5-F2-*`).

| ID | Defecto | Evidencia | Corrección |
| --- | --- | --- | --- |
| `R5-F2-B1` | `AuditExecutionConfig::jobStaleAfterSeconds()` aún llamaba a `self::integer('AUDIT_BATCH_LOCK_TTL_SECONDS', ...)` (lanza `InvalidArgumentException` si el valor es `''`), dejando incompleto `R4-F2-B2` para `worker-execution-maintenance` | `AuditExecutionConfig.php:71-79`; Sección 13 `AuditExecutionConfig` | Delegar el piso en `self::batchLockTtlSeconds()`; actualizado en `0.1`, Sección 13, Sección 16 y CA `16(i)` |
| `R5-F2-B2` | Reanudar `reap_job_id` al inicio de `gc()`, antes del bucle `GC_PATTERNS`, dejaba `$keys = []`; un corte por presupuesto en esa rebanada sobrescribía en `finally` `pending_pattern = null` y `pending_keys = []` | Sección 13 `AuditExecutionMaintenance` punto 3; `plans/sdd-hotfix-mantenimiento-ejecucion.md:748-761` | Bandera `$enteredPatternLoop = false` (fijada a `true` al entrar al `while`): el `finally` solo actualiza `pending_pattern`/`pending_keys` y el avance de índice cuando `$enteredPatternLoop === true`; añadido test `testPreLoopReapResumeBudgetCutPreservesPendingKeys` |
| `R5-F2-I1` | Excepción en la última clave de la última página (`cursor = '0'`) de `audit:reservation:disid:*` (índice `5`): fijar `legacy_pass_done = '1'` antes de drenar `$keys` saltaba `disdetnro:*`, mientras fijarlo solo tras vaciar `$keys` reintroducía bucle en `SCAN '0'` | Sección 13 `AuditExecutionMaintenance` puntos 1 y 4 | Fijar `legacy_pass_done = '1'` solo cuando `$keys === []` tras drenar la última página, más rama espejo en `finally` cuando `$legacyBlocks` cierra `cursor:reservations_legacy === '0'` sin avanzar índice; test en `AuditMemoryCompactionAndGcTest` |
| `R5-F2-I2` | `audit:execution:resumed_at_ms` escrita en `resume`, leída en `gc()` y eliminada en `rollbackBatchV2()` faltaba en la tabla Redis de Sección 9 y en `FASE 2` | Sección 9 (`L889`), Sección 13 (`L1531`, `L1569`) | Registrada en tabla Redis de Sección 9 y en `FASE 2` |
| `R5-F2-M1..M4` | (M1) `reapStaleJob()` omitido en el resumen de `BatchJobStore`; `~500 B` en Triage frente a `~600 B`; `0.3` citaba solo `AUDIT_BATCH_LOCK_TTL_SECONDS`; Triage/FASE 3/FASE 4 sin reflejar verificación directa productiva; citas cruzadas `X-F2-I3` hacia Fase 1 movidas +11 líneas tras su 5.ª pasada. (M2) `MIGRATE_JOB_KEY_LUA` declaraba `KEYS[4] = telemetry:async_metrics` sin usarla. (M3) `core/Env.php` cachea en estático `$cache`: pruebas de `Env` requieren `Env::set()`/`Env::clearCache()` en `finally`. (M4) RCA `P-F2-07..08` refuta `SUP-04` | Lectura directa de ambas specs y `core/Env.php:30-67` | Citas recalculadas (`:996`, `:803-815`, `:748-761`, `:612`); `KEYS[4]` renumerado a `by_dispensa`; `SUP-04` marcado `Refutado — diferido a Fase 3 (SDD-BATCH-002)`; `P-F2-07..08` registrados |

### Registro de revisión verificada (cuarta pasada, 2026-11-19)

Pasada estática con Antigravity (`w7k-1-r4f2`), verificada por el orquestador contra el código y la spec. Las citas `AuditControllerTest.php:132,236,778,808` y los ajustes `X-F2-B2`, `X-F2-B3` (algoritmo), `X-F2-I1`, `X-F2-M1`, `X-F2-M2` y `X-F2-M4` quedan confirmados.

| ID | Defecto | Evidencia | Corrección |
| --- | --- | --- | --- |
| `R4-F2-B1` | El punto 1b de `X-F2-B1` liberaba la reserva y marcaba `failed` cualquier auditoría no terminal de un job `failed`, también las registradas y en vuelo (`dispatched = true`) de un job multichunk cuyo chunk posterior fue a DLQ: fuga permanente de `audits_in_flight` (`ADMIT` y el committer descartan la auditoría sin llamar a `MARK_AUDIT_COMPLETED_IN_JOB_LUA`) y reauditoría paralela de esas dispensas por un job nuevo | `FairAuditTaskQueue.php:240`; `AuditTaskCommitter.php:78,104`; Sección 13 `cancelUndispatchedAudits()` (deja intactas las `dispatched = true`) | 1b exige `HEXISTS job:{id}:audits {audit_id} = 0` para todo job terminal (solo el job inexistente queda exento); se retira el «respaldo» de `X-F2-I2` en `gc()`; variante negativa en `testGcReleasesOrphanReservationOfTerminalJob` |
| `R4-F2-B2` | `BatchRequestedWorker::__construct()` llamaba a `AuditExecutionConfig::digitsOrDefault()`, que es `private static`: error fatal al instanciar el worker | Sección 13 `AuditExecutionConfig` | Nuevo `public static batchLockTtlSeconds()` (lectura literal `Env::get` para `ci.yml:114`), usado por el worker y por `AuditBatchOrchestrator` (`:108` convertía `''` en `0`) |
| `R4-F2-I1` | Las citas de `X-F2-I3` hacia SDD-BATCH-000 volvieron a desfasarse al crecer sus registros | Fase 1 actual | Recalculadas tras los registros de la cuarta pasada |
| `R4-F2-I2` | El test de rollback decía «`7` campos» frente a los `9` de Sección 9; y el avance por excepción del `finally` (índice `5` → `6`) no reiniciaba `legacy_pass_done`/`legacy_saw_keys`, con lo que el ciclo siguiente saltaba `disid:*` | Sección 9; punto 4 de `AuditExecutionMaintenance` | «`9` campos»; reinicio explícito en la rama de avance del `finally` |
| `R4-F2-I3` | `finalizeDeadLetterAudit()` leía el job con `getJob()` (`HGETALL` de hasta `50.000` entradas) solo para `status`/`stale_reason`; el test de DLQ contradecía la rama de reentrega de `X-F2-I2` | Sección 13 `BatchJobStore` (`getJobSummary()`, `~600 B`) | `getJobSummary()`; test reescrito con las tres ramas |
| `R4-F2-M1..M3` | (M1) `AGENTS.md:401` es `AUDIT_BATCH_CHUNK_SIZE`; el lock TTL está en `:402`. (M2) `$root` no existe tras SDD-BATCH-000 (`$status`), y el paso 4 usaba `$startOffset` antes de asignarlo en el paso 5. (M3) Al abortar por referencia activa quedaban `hscan_cursor`/`hscan_sha1` residuales; los dos tests de `gc()` nuevos no figuraban en 0.1 | `AGENTS.md:401-402`; SDD-BATCH-000 `collectKey()` | (M1) `:402`; (M2) `$status` y fórmula del offset en línea; (M3) limpieza de los tres `hscan_*` y fila de 0.1 ampliada |

### Registro de revisión verificada (tercera pasada, 2026-11-19)

Pasada estática con Antigravity (`w7k-1-r3f2`; informe en `C:\Users\USER\.herdr-orch\runs\w7k\w7k-1-r3f2.md`), verificada por el orquestador contra el código. 19 correcciones `OK` y 4 parciales (`W-F2-B3`, `W-F2-I3`, `W-F1-I3`, `W-F1-M7`); hallazgos: 3 Bloqueantes, 3 Importantes, 4 Menores.

| ID | Defecto | Evidencia | Corrección |
| --- | --- | --- | --- |
| `X-F2-B1` | Hueco residual de `W-F2-B1`: una fila con reserva `disdetnro:*` y `audit:{id}:state` `pending` que recibe `409` antes de `registerAuditInJob()` queda fuera de `by_dispensa`/`audits`; si el job termina `failed`, ni el reaper ni `cancelUndispatchedAudits()` la ven, y `gc()` no la libera porque su auditoría existe y no es terminal | `AuditStateStore.php:74` (`SET NX` sin `EX`); `AuditExecutionMaintenance.php:333-350` (solo libera si `audit_id` terminal o inexistente) | Sección 13 `AuditExecutionMaintenance`, punto 1b: `gc()` libera reservas huérfanas de jobs terminales (`GC_RELEASE_ORPHAN_RESERVATION_LUA`); test y criterio 16h |
| `X-F2-B2` | `AuditControllerTest.php:132` (`method('initJob')` sobre el mock de `BatchJobStore`) no estaba en el perímetro: al retirar `initJob()`, PHPUnit falla al configurar un método inexistente | `tests/Controllers/AuditControllerTest.php:122-147` | Añadido en `0.1`, `0.3`, Sección 16 y FASE 3 (`method('initJobExclusive')`) |
| `X-F2-B3` | `$legacySawKeys` y `$legacyPassFinished` vivían en RAM: un corte de presupuesto durante `disid:*` perdía el progreso entre ticks (falso `legacy_done = '1'`) y la salida temprana heredada (`cursors['reservations'] === '0'`) saltaba al índice 6 omitiendo `disdetnro:*` | `plans/sdd-hotfix-mantenimiento-ejecucion.md` (bucle de `gc()`) | Campos persistidos `legacy_saw_keys` y `legacy_pass_done` en `gc:cursors`; la fase legada termina solo con `cursor:reservations_legacy === '0'` |
| `X-F2-I1` | Paso 7 decía «`sealJob()` lanza `503` en `false`» frente al `409` de la regla 0 y de su test (deja `W-F2-B3` parcial) | Sección 13, `AuditBatchOrchestrator` | `409` (`generation_fence_stale_on_seal`) en el paso 7 y en el `sealJob()` de `chunkIndex > 1` del paso 4 |
| `X-F2-I2` | Si el proceso cae entre `markJobFailed()` (`true`) y `cancelUndispatchedAudits()`, la reentrega no repetía la cancelación; además no se explicitaba la escritura de `KEYS[1]` en `CANCEL_UNDISPATCHED_AUDITS_LUA` | `AuditEventConsumer.php:636-648` | `finalizeDeadLetterAudit()` repite la cancelación (idempotente) mientras el job siga `failed` sin `stale_reason`; `SET KEYS[1]` sin `EX` explícito |
| `X-F2-I3` | Sección 9 conservaba «`writeHeartbeat` pasa de 8 a 9 campos»; 4 citas de línea desfasadas hacia SDD-BATCH-000 | `plans/sdd-hotfix-mantenimiento-ejecucion.md:579,714,769,962` | «de 10 a 11 campos»; citas `:962`, `:769-781`, `:714-727`, `:579` (recalculadas en 4.ª pasada a `:985`, `:792-804`, `:737-750`, `:601` y en 5.ª pasada a `:996`, `:803-815`, `:748-761`, `:612`, `R5-F2-M1`) |
| `X-F2-M1..M4` | (M1) puntos `5.`/`4.` invertidos en `AuditExecutionMaintenance`; (M2) `$createdAuditIds` registrado tarde; (M3) `LIST_JOBS_LUA` mantenía `or "audfact:"` y la lectura de `AUDIT_BATCH_LOCK_TTL_SECONDS` en el arranque no fijaba default; (M4) `--force-stale=` vacío o no UUID sin rechazo | Sección 13 | Numeración `4.`/`5.`; registro tras `initAudit()`; `or ""` y default `1800`; `exit(1)` |

### Registro de incorporación del diagnóstico de producción (2026-11-19)

Diagnóstico de un incidente en producción aportado por el usuario (otro agente; logs no vistos directamente) y contrastado con el código. La pausa de admisión se incorpora en SDD-BATCH-000 (`P-F1-01`).

| ID | Hallazgo | Verificación en código | Incorporación |
| --- | --- | --- | --- |
| `P-F2-01` | Los 4 `worker-batch` quedaron bloqueados en `getInvoicesForAuditBatch()` contra `db2`; un log `SqlServerConnectionExecutor: reintento transitorio` con `sql_state = 08S01` registró `elapsed_ms = 13.647.092` (~3,8 h) | `[CONFIRMADO]` `Model::read()` usa `db2` en modo `READ` (`app/Models/Model.php:14,22-28`); sin `SQLSRV_ATTR_QUERY_TIMEOUT` (solo `LoginTimeout`, `core/Database.php:91-109`); `08*` se reintenta hasta 4 intentos con esperas `1+5+30 s` (`core/SqlServerConnectionExecutor.php:14-15,182-191`). **Matiz:** no es un bucle sin fin, sino 4 intentos cada uno sin cota: `elapsed_ms` se mide desde el inicio de `execute()` (`:42,124-127`), así que ~3,8 h es lo que tardó en llegar el `08S01`, y cada reintento vuelve a construir `#DISP`/`#CRUZE`/`#ADJ`/`#Sopo` (`InvoicesModel.php:124-220`) | Confirma `R-SQLTO` en producción. `DEC-12` se mantiene; nuevo `SUP-05` sobre si el timeout acota también un socket muerto |
| `P-F2-02` | Bloqueo en cabeza de línea: con las 4 réplicas ocupadas, ningún worker atiende a otros clientes | `[CONFIRMADO]` estructuralmente: `worker-batch` tiene `replicas: 4` (`docker-compose.yml:157`), cada consumidor lee `COUNT 1` y procesa en serie (`AuditEventConsumer.php:240-262`), y la lane `batch` es compartida por todos los NIT. Que los 4 tuvieran el NIT `2624` no es verificable desde el repositorio; es coherente con el solapamiento por la clave horaria del cron (`H1`) | `jobs:active:nit` + lock con fencing limitan a 1 generación por NIT y `DEC-12` acota cada una a `4·T + 36 s`. Riesgo residual `R-HOL` (Sección 17): 4 NIT grandes distintos pueden seguir ocupando las 4 réplicas hasta ~20 min |
| `P-F2-03` | Tras reiniciar, la ingesta de NUEVA EPS siguió en páginas de 100 con cursor | `[CONFIRMADO]` v1 actual: `getInvoicesForAuditBatch(..., int $limit = 100, ?array $cursor)` con cursor keyset `(fecha, DisId, Dispensa)` (`InvoicesModel.php:257-275`) y temporales reconstruidas en cada página | Ningún cambio: la congelación única de Fase 2 sustituye a ese paginado |
| `P-F2-04` | Tras el incidente se «limpiaron» en Redis los jobs huérfanos de las corridas de las 15:00 y 19:00 UTC del 2026-11-18 | `[CONFIRMADO]` con `GET /audit/jobs` de producción (2026-11-19 ~14:05 UTC, solo lectura): no se borraron, se marcaron `failed` en el mismo instante (`updated_at = 2026-11-19T13:46:58Z`) y son **9**, no 8: 6 de las 15:00 (`d19537d4`, `51734d2e`, `5701c65f`, `98bb7f23`, `c2ddc67e`, `832f42a2`) y 3 de las 19:00 (`9911edf7`, `6e93a336`, `7bcb81ab`). 8 tienen `total = 0` (su generación nunca corrió en ~23 h); `832f42a2` (COOSALUD) quedó `failed` con `total = 36`, `pending = 36`. En v1, marcar un job `failed` no cancela sus auditorías ni libera sus reservas | Confirma el reaper `REAP_STALE_JOB_LUA` (`DEC-10`), que además cancela las auditorías vivas y libera sus reservas (`W-F2-B4`). Verificado (solo lectura): `GET /audit/jobs/832f42a2…` devuelve `sealed = true`, `status = failed` y las 36 entradas de `job:{id}:audits` en `pending`, pero `GET /audit/status/{id}` responde `404` («no encontrada o expirada») en 3 de 3 muestras: los `audit:{id}:state` no existen, así que esas auditorías no siguen en el pipeline y el mapa del job quedó desfasado. Sus reservas `audit:reservation:disid:*`, si siguen, las libera `gc()` (`AuditExecutionMaintenance.php:333`, `audit_id` inexistente). Confirmado por SSH en solo lectura (2026-11-19): `0` de `5.927` reservas `audfact:audit:reservation:disid:*` apuntan a esas 36 auditorías y `0` de 36 `audit:{id}:state` existen; las facturas quedan libres para la siguiente corrida. En el mismo momento: sin `audit:admission:paused` ni `audit:execution:paused`, heartbeat con ~1 s de edad y `worker-execution-maintenance` `Up 42 hours` con `0` reinicios (el bucle OOM `H11` medido en QA no se observa en producción) |
| `P-F2-06` | El otro agente afirmó «tablero en 0 pendientes» | `[REFUTADO]` con la misma lectura: NUEVA EPS (`2624`) tiene **5 jobs `processing` a la vez** con rangos solapados (`date_from = 2026-01-01`): `5414de10` (11-17 23:00, 1.600/2.339), `ed15eb4e` (11-18 04:35, petición manual), `7fbed46e` (11-18 11:00, 1.207/2.400), `3ea2e5b5` (11-18 23:00, 1.016/1.900) y `57d80c7e` (11-19 11:00, 872/1.585), con ~3.500 auditorías pendientes; `avg_duration_ms` de NUEVA EPS ≈ 1,7–2,8 M ms frente a 46–190 k ms de los demás clientes. `ed15eb4e` muestra `done = 350 > total = 134` (`progress_percent = 261`) y sigue `processing` sin cambios desde 2026-11-18T23:14Z; FAMISANAR `5c4ca134` tiene `done = 50 = total`, `pending = 0` y sigue `processing` desde 2026-11-19T04:23Z (nunca sellado) | Evidencia de producción de `H1`/`H4` (solapamiento por la clave horaria, que `INV-03` + `jobs:active:nit` + `409` eliminan) y de `H3` (`done > total`, que `repair-jobs` sanea en la migración). `5c4ca134`: **corregido en la sexta pasada (`R6-F2-M1`)** con la lectura posterior de producción (`P-F2-08`): el job está `sealed = true`, `total = 50`, `done = 50`, `pending = 0` pero sigue `processing`; no lo cierra el reaper como `failed` sino `SEAL_JOB_LUA`/`MIGRATE_JOB_KEY_LUA`/`repairJobStates()`, que lo promueven a `completed` por `done + failed >= total` |
| `P-F2-05` | El diagnóstico descartó host, Docker y red local (error < 0,00000003 %), pero **no inspeccionó el lado de SQL Server** (`db2`: sesiones, bloqueos, `KILL`) ni equipos intermedios | `[DESCONOCIDO]` Qué cortó el socket a las ~3,7 h. Un cortafuegos que descarta en silencio conexiones TCP inactivas durante una consulta larga sin tráfico es compatible con lo observado | Refuerza `SUP-05`: un corte silencioso es justo el caso en que no está demostrado que el timeout de cliente baste |
| `P-F2-07` | RCA de 5 capas en `172.16.0.3` (`2026-11-19 14:56Z–15:19Z`): `AuditDocumentCache` osciló `71,4–85,9 %` (`+16` nuevas `prefetch_pauses` en 11 min, `4.412 → 4.428`), frenando a los `16` `worker-downloader` (`documents_batch.downloaders.lag` `33.367 → 35.371`) con `extraction.batch` en `queued = 0` (`24` extractores ociosos) por ráfaga de `1.576 req/h` en `POST /audit/single` desde `52.117.172.102` | `[CONFIRMADO]` `AuditController::single()` (`:94`) enruta `is_priority = true`; `docker/nginx-ha.conf.template:95-99` usa coincidencia exacta `location = /audit` (deja `/audit/single` bajo `api_limit` `10 r/s` y `RateLimit` `100/min`); `worker-extraction-vip` solo tiene `2` réplicas (`docker-compose.yml:184`, y `worker-semantic-vip` `1` en `:349`), acumulando `3.192` tareas en `extraction.priority` (espera `69,6 min` > `TTL 3600 s`) sobre el presupuesto global `audit:document-cache:{budget}` (`AuditDocumentCache.php:15`; `AuditDocumentPrefetchGate.php:38-49`) | Refuta `SUP-04` en producción; **diferido a Fase 3 (`SDD-BATCH-002`)** para no acoplar caché/Nginx al cutover v2 (`SUP-04` y Sección `2. Excluido` actualizados) |
| `P-F2-08` | En el mismo RCA (`172.16.0.3`): `GET /metrics/async` reportó `jobs.pending = 38` con `0` `pending` reales; `4` lotes de `3.000` de NUEVA EPS (`2624`, más un 5.º entrando a las `15:00Z`) saturaron `6` réplicas de `worker-semantic-batch` (`2.222` en cola, `~55 min`); `5c4ca134` apareció con `sealed = true` | `[CONFIRMADO]` en código el desfase histórico de `telemetry:async_metrics` y `worker-semantic-batch` con `replicas: 6` (`docker-compose.yml:359`); `[CONFIRMADO en fuente externa]` mediciones de `172.16.0.3` (discrepancia menor en `sealed` de `5c4ca134` frente a `P-F2-06`) | Refuerza `reconcileJobTelemetryMetrics()`, `INV-03` y documenta verificar `sealed` de `5c4ca134` en Redis antes de cualquier cierre manual |

### Registro de revisión verificada (2026-11-18)

Correcciones incorporadas tras verificación independiente contra `main @ 3396897` (4 agentes verificadores, evidencia `archivo:línea`):

| ID | Defecto verificado | Evidencia | Corrección en esta versión |
| --- | --- | --- | --- |
| `R-SEAL` | `MIGRATE_JOB_KEY_LUA` ponía `sealed = false` en jobs `completed*` con `processed < total`; en v1 un job `completed*` siempre fue sellado (`BatchJobStore.php:255-262`, cierre único en `:558`) y ningún camino lo re-sellaría → job eterno en `processing` bloqueando `jobs:active:nit:{nit}`. `repairJobStates()` reproducía el defecto. | `AuditBatchOrchestrator.php:410-422,580-587`; `BatchJobStore.php:558` | Sección 9: conservar `sealed = true`; detectar generación abandonada vía `audit.batch.inbox`. |
| `R-STALE` | No existía ningún mecanismo que llevara a terminal un job sin progreso (`gc()` solo trata terminales `AuditExecutionMaintenance.php:318-320`; `finalizeDeadLetterAudit` sale si `auditId === null` `AuditEventConsumer.php:694-696`). Con `INV-03` y el rango cron `01-01 → hoy` (`schedule-daily-batches.php:46-52`), un job atascado bloquea el NIT indefinidamente. | Ver evidencia | `DEC-10`, `INV-08`, `REAP_STALE_JOB_LUA` (umbral `6 h`, estado `failed`, cancelación de auditorías vivas). |
| `R-CONT` | El lock se libera en el `finally` del orquestador antes de publicar la continuación; `markContinuationDispatched()` validaba `expectedEpoch` y el consumidor de `k+1` incrementa el epoch en milisegundos → 409 en el flujo normal, continuación duplicada (el publisher no deduplica: `AuditEventPublisher.php:179`) y, al 3er intento, `markJobFailed` con epoch `null` mataba un job sano. | `AuditBatchOrchestrator.php:109,181-184`; `BatchRequestedWorker.php:141-153`; `AuditEventConsumer.php:409-421,636-647` | `DEC-04` ampliada: confirmación por `chunkIndex` monotónico sin epoch, promoción en el claim, Lua de claim especificado, DLQ sin epoch no cierra el job. |
| `R-SQLTO` | `core/Database.php:91-97` no fija timeout de consulta (solo `LoginTimeout` `:109`); en PHP no es posible renovar el lock durante `execute()` bloqueante, contradiciendo la redacción previa de `SUP-01`. | `core/Database.php:91-109`; `InvoicesModel.php:309,323`; `core/SqlServerConnectionExecutor.php:182-191` | `DEC-12`: `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` en la congelación + inecuación de tiempos validada al arranque. |
| `R-ROLL` | Con batch activo, `P(0 hermanas en vuelo) < 1 %` (`E21`: `27/1.000`) y drenar `1.000` reservas a `~180/h` toma `~5,5 h`: el SLA de rollback de `10 min` era inalcanzable. | `E21`, `H10`; `BatchJobStore.php:385-390,611-621` | `DEC-11`: política **forward-fix only**; `rollback-batch-v2` solo con `0` jobs activos. |
| `R-INTAKE` / `R-TICK` | `intake()` hace 1 RT por entrada; `1.500 + 50` exigía `~1.558` RT (`~2,6 s` a p50). La cota `1.200+600+500` omitía `~45` RT fijos y `maintain()` sin presupuesto. | `AuditExecutionMaintenance.php:121-185,430`; `FairAuditTaskQueue.php:55-61,159-175` | Trasladado y corregido en **SDD-BATCH-000**. |
| `R-PHASE` | El arreglo urgente de `H11` estaba acoplado a una migración big-bang de ~30 archivos. | — | División en Fase 1 (SDD-BATCH-000) y Fase 2 (este documento). |
| `R-EPOCH` · `R-PREPUB` · `R-EMPTY` · `R-CLEAN` · `R-PII` · `R-OBS` | Defectos de revisiones anteriores (antes rotulados `H5`–`H7`, `H11`–`H14` con significado distinto al diagnóstico): resultado tipado del lock (`R-EPOCH`), recuperación pre/post-publicación (`R-PREPUB`), cierre vacío multichunk y DLQ (`R-EMPTY`), retiro de `initJob`/`claimJobGenerationLock` y `3 KEYS` (`R-CLEAN`), NIT en logs (`R-PII`), correlación `batch_generation_sql_ms` (`R-OBS`). | Revisiones previas | Ya incorporados; solo se renombran. |
| `V-F2-B1..B7` (corrida `v4s`, Antigravity) | (B1) `REAP_STALE_JOB_LUA` escribía `completed_at` en ms; (B2) sin prefijo `REDIS_PREFIX` en reservas y con campo inexistente `updated_at_ms`; (B3) `candidate_offset` perdido → bucle infinito de chunks (`cursorFromInvoice()` sin `offset`, `AuditBatchOrchestrator.php:678-693`); (B4) `TERMINAL` por `reaping` → `TypeError`; (B5) `initAudit()` no idempotente (`AuditStateStore.php:65-75`); (B6) rollback del workflow sobrescribía `.env` con `<sha_anterior>` antes de `rollback-batch-v2` (`deploy-production.yml:101-109,326`); (B7) envoltorio anulaba `$nonRetryable` (`AuditEventConsumer.php:630-636`). | Informes `v4s-1-f2.md`, `v4s-2-f2a.md`, `v4s-2-f2b.md`; verificados contra código | Sección 13 (`reapStaleJob`, `AuditBatchOrchestrator` pasos 1–7, `BatchRequestedWorker`, `AuditEventConsumer`, `deploy-production.yml`), Sección 15 y 16. |
| `V-F2-I1..I9` · `V-F2-M1..M9` | Test de `tick` de Fase 1 fuera del perímetro y `jobs_reaped` sin cableado; firma de dobles de `InvoicesModel`; `env:` del workflow; cierre vacío multichunk y telemetría; `markJobFailed` lanzando en DLQ y sin guarda `reaping`; `EXISTS` vs `SMEMBERS` no-`plan:`; doble `audits_in_flight`; `reaping` bloqueado tras `resume`; citas desfasadas; migración `completed` con `sealed = false`; `legacy_done`; `isV2State` y limpieza de cursores en rollback; guardas de `MARK_CONTINUATION`; prefijo de `LIST_JOBS_LUA` y `deleteJob` atómico; citas de `0.3`/`0.6`; orden `INV-07/08`; prueba SQL `23:59:59`. | Mismos informes | Aplicados en Secciones 0.1, 0.3, 0.6, 8, 9, 13, 15, 16, 18. |
| `W-F2-B1` (corrida `w7k`, 2026-11-18) | `cleanupAsyncEnqueueState()` con `auditId`/`reservationToken` deterministas borraba `audit:{id}:state` y la reserva de una auditoría viva de otro worker (`epoch + 1`) ante `409` de fencing, y en `R-PREPUB` con `$externalJobId` dejaba `by_dispensa` con `dispatched = false` sin estado → `audit_state_missing` en `ADMIT`. | `AuditBatchOrchestrator.php:646-672`; `FairAuditTaskQueue.php:238` | Sección 13 (`AuditBatchOrchestrator` pasos 5 y 8): limpieza total solo con `!$externalJobId && chunkIndex === 1` + `deleteJob()`; nunca sobre filas registradas en `by_dispensa` con `$externalJobId` o ante `409`; Step 5 recrea reserva + estado si faltan. Secciones 15, 16 y 18 (criterio 16a). |
| `W-F2-B2` (`w7k`) | `INIT_JOB_EXCLUSIVE_LUA`, `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA` y `DELETE_JOB_LUA` accedían a `job:*:state` / `jobs:active:nit:*` dinámicos sin `REDIS_PREFIX` (bajo `audfact:` se podaban todos los jobs activos: `INV-03` anulado). | `core/RedisClient.php:430` | Sección 13: `local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''` obligatorio en los 5 scripts; `0.4`, `INV-03`; test con prefijo no vacío (criterio 16b). |
| `W-F2-B3` (`w7k`) | `STORE_FROZEN_CANDIDATES`, `MARK_AUDITS_DISPATCHED`, `COMMIT_CHUNK_CHECKPOINT`, `SEAL_JOB` y `PUBLISH_EMPTY_BATCH` no rechazaban jobs `reaping`/terminales/sellados y el reaper no incrementaba `generation_epoch`: un worker despierto con el mismo epoch reabría un job `failed` (`INV-08`). | Spec `:1070-1091,1121-1125` | Guarda terminal/`reaping`/`sealed` en los 5 scripts; `REAP_STALE_JOB_LUA` incrementa `generation_epoch` en la 1.ª rebanada; `INV-02`, `INV-08`; criterio 16c. |
| `W-F2-B4` (`w7k`) | El reaper dejaba `audit:{id}:state` no terminal sin TTL (fuga y bloqueo de `gc()`), y `markJobFailed()` desde DLQ dejaba auditorías `dispatched = false` sin publicar ni cancelar (reservas `disdetnro:*` bloqueadas para siempre, fuga de `audits_in_flight`). | `AuditExecutionMaintenance.php:231,254,276,296,342-343`; `AuditTaskCommitter.php:78,96-104`; `FairAuditTaskQueue.php:240` | `REAP_STALE_JOB_LUA` paso 2 marca `audit:{id}:state` `failed` (`stale_no_progress`, ISO, sin `EX`, conserva `documents`); nuevo `BatchJobStore::cancelUndispatchedAudits()` invocado por `finalizeDeadLetterAudit()` cuando `markJobFailed()` devuelve `true` (`batch_dlq_unpublished`); criterio 16d. |
| `W-F2-B5` (`w7k`) | Rama `operation=rollback` de `deploy-production.yml` no ejecutable (`docker run` sin `--entrypoint php`, `$DEPLOY_PATH` indefinido antes de `L101`, `php` con `replicas: 5`, `workflow_run` sin `inputs.operation`) y contradicción entre Secciones 13, 14 y 20 (`V-F2-B6` parcial). | `deploy-production.yml:3-13,23,29,101-109,326,467-478,529-578`; `docker-compose.yml:28-46`; `docker/Dockerfile:65`; `docker/docker-entrypoint.sh:37-51` | Sección 13 rediseñada: `env.OPERATION`, step previo a `L101` con `docker compose run --rm --no-deps -T --entrypoint php php ...` sobre el compose v2 vigente, relanzamiento v2 y `exit 1` si `rollback-batch-v2 != 0`; Secciones 14 y 20 unificadas (operador: `ctl pause` opcional + `workflow_dispatch`); criterio 16e/16f. |
| `W-F2-I1` (`w7k`) | Paso 3 del reaper exigía re-escaneo `O(N)` del Hash o abría una carrera con `MARK_AUDIT_COMPLETED_IN_JOB_LUA` durante `reaping`. | Spec `:1093-1102,1122-1125` | `MARK_AUDIT_COMPLETED_IN_JOB_LUA` retorna `1` sin mutar si `reaping == true`; el reaper acumula `failed` por rebanada y la final solo fija `total = HLEN`. |
| `W-F2-I2` (`w7k`) | Truthiness Lua (`0`/`cjson.null`) en `last_progress_at or ARGV[6]` e imposibilidad de `--force-stale` sin marca de progreso. | Spec `:1123,1127` | Paso 1 con `tonumber()` y evaluación de progreso solo si `threshold > 0`. |
| `W-F2-I3` (`w7k`) | Bucle cerrado de `SCAN '0'` en el índice `5` si sobrevive una reserva `disid:*` no recolectable (inanición de `disdetnro:*`). | Spec `:440,1389`; `AuditExecutionMaintenance.php:342-343` | `$legacySawKeys`; al volver el cursor legado a `'0'` se pasa siempre a `disdetnro:*` en el mismo índice. |
| `W-F2-I4` (`w7k`) | `AuditExecutionMaintenanceTickRedisTest.php` y los 3 dobles de `InvoicesModel` ausentes de `0.3` y Sección 16 (`V-F2-I1`/`V-F2-I2` parciales). | `AuditBatchOrchestratorTest.php:368-374`; `BatchRequestedWorkerTest.php:126-132,219-225` | Añadidos en `0.3` y Sección 16. |
| `W-F2-I5` (`w7k`) | Restos de `EXISTS` en `0.4` e `INV-06` (`V-F2-I6` parcial). | `AuditExecutionMaintenance.php:70-83` | `SMEMBERS` ignorando miembros `plan:`. |
| `W-F2-I6` (`w7k`) | `BatchRequestedWorker::__construct()` y `AuditEventConsumer::handleFailure()` fuera del perímetro `0.1` y del encabezado de Sección 13. | `BatchRequestedWorker.php:35-54`; `AuditEventConsumer.php:630-636` | Añadidos con sus rangos. |
| `W-F2-I7` (`w7k`) | `gh variable set` y verificación remota solo para `AUDIT_BATCH_LOCK_TTL_SECONDS`. | `AGENTS.md` (regla de sincronización) | Comandos para `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` y `AUDIT_JOB_STALE_AFTER_SECONDS` en Sección 13, 14 y criterio 14. |
| `W-F2-M1` (`w7k`) | Cita `BatchJobStore.php:232-235` (es `deleteJob()`) atribuida a `LIST_JOBS_LUA` (`V-F2-M5` parcial). | `BatchJobStore.php:630` | Cita corregida a `:630`. |
| `W-F2-M2` (`w7k`) | `sha1` inicial del `HSCAN` de `gc()` no persistido entre ticks. | Spec `:440,1390` | Campo `hscan_sha1` en `audit:execution:gc:cursors` y en la limpieza de `rollbackBatchV2()`. |
| `W-F2-M3` (`w7k`) | `MIGRATE_JOB_KEY_LUA` sobrescribía `last_progress_at` (y `repair-jobs` reiniciaba el reloj del reaper). | Spec `:540,1402` | Solo inicializa si `<= 0`. |
| `W-F2-M4` (`w7k`) | Step 4 sin `chunkIndex === 1` para `publishEmptyBatch()`; Step 6 omitía `batch_created` si Chunk 1 aceptaba `0`. | Spec `:1153,1168` | Condiciones alineadas. |
| `W-F2-M5` (`w7k`) | `isV2State` sin `stale_reason`, `reaping`, `generation_abandoned`, `error_message` (`V-F2-M3` parcial). | Spec `:698-708,741-744` | Añadidos. |
| `W-F2-M6` (`w7k`) | Compensación de `AuditController::async()` podía convertir `503` en `500`; `AuditEvent::create()` sin `?string $eventId`. | `AuditEvent.php:92-101` | `try/catch (\Throwable)` en la compensación; parámetro opcional `$eventId`. |
| `W-F2-M7` (`w7k`) | Reanudación de `reap_job_id` ambigua; `DELETE_JOB_LUA` sin `audits_in_flight` ni ramas de estado. | Spec `:1118,1391` | Reanudación al inicio de `gc()` + re-encolado; `DELETE_JOB_LUA` precisado. |
| `W-F2-M8` (`w7k`) | Restos: `criterios 1–14`, `EXPIRE_LEGACY_IDEMPOTENCY_LUA` en la tabla de migración, `sealJob(` duplicado. | Spec `:664,1172,1695` | Corregidos. |
| `W-F2-M9` (`w7k`) | `(int) Env::get(...)` convertía `''` en `0` y caía a los pisos en lugar de los defaults. | `AuditExecutionConfig.php:71-79` | Cadena vacía → default; no numérico → `InvalidArgumentException`. |
| `W-F1-I3` · `W-F1-I4(a)(b)` · `W-F1-I5` · `W-F1-M7` (interfaz asignada por `w7k-1-f1`) | Guarda del `finally` heredado de `gc()` (`V-F1-B1`) frente a `legacy_done`/`hscan_job_id`/`reap_job_id`; `expireLegacyIdempotency()` invocado desde `BatchJobStore` (acoplamiento circular) y tabla residual; test de `tick` y `jobs_reaped` fuera de Sección 16; `lastGcReapedJobs` sin reinicio por tick y `T-U4` atado a `initJob`. | `plans/sdd-hotfix-mantenimiento-ejecucion.md:538,666-669,717,807,902` | Sección 13 (`AuditExecutionMaintenance` punto 5, CLI `migrate-batch-v2`), Sección 9, `0.3` y Sección 16. |
| Verificación empírica `w7k` | `scratch\02_repro_concurrent_generation_race.php` (en memoria) reconfirmó `H3` y `H2 + H4` en HEAD. Los scripts SQL `check_candidate_cardinality.php` y `01_bench_candidates_top500_cursors.php ... only-disfecsol` **no pudieron ejecutarse** (sandbox sin TCP saliente, `SQLSTATE[08001]`); no se reintentaron. | Informes `w7k-2-f2a.md`, `w7k-2-f2b.md` | La base empírica de `DEC-01` y `DEC-09` sigue siendo `E19`/`E21` del diagnóstico. |
| `R-DOC` | Identificadores `H#` reutilizados con otro significado (`H12–H14` inexistentes), perímetro sin `LoggerSanitizationTest`, umbrales de heartbeat `10.000`/`30.000` mezclados, tamaños de `job:{id}:state` dispares, nota obsoleta. | Revisión de la spec | Renumeración `R-*`, perímetro y valores unificados (`STRLEN < 2048`, típico `~600 B`). |

---

### Registro de revisión ASEO (2026-11-22)

Revisión derivada de la verificación cruzada de las tres especificaciones (`SDD-BATCH-000/001/002`) contra el código en `HEAD` (`2d1a467`) conforme a `system.md` (CP3, CP4, 17.1). Evidencia: 18 afirmaciones verificadas por dos verificadores independientes (grep del orquestador y agente Sonnet), con `archivo:línea`.

| Campo | Valor |
| --- | --- |
| Versión anterior → nueva | 1.0 → 1.1 |
| Motivo | Cierre de hallazgos de la verificación cruzada 000/001/002 y control de deriva (`system.md` 17.1, 6.5) |
| Cambios | (1) `SUP-04`: aclara que `docker-compose.yml:359` ya fija `12` desde `2d1a467` y que `DEC-F3-08` de `SDD-BATCH-002` sustituye los valores VIP; (2) filas de `AuditDocumentCache` y `AuditDocumentPrefetchGate` en `0.1`: «Sin cambios en esta fase», diferido a Fase 3; exclusión de la Sección 2 corregida a `3 GiB`; (3) `claimIdempotencyKey()` retirado de métodos nuevos/actualizados y marcado como heredado de `SDD-BATCH-000`; (4) guarda `$argc` de `bin/audit-execution-maintenance.php` reescrita (solo valida el segundo argumento cuando existe); (5) notas junto a `v2_services` y `docker compose stop` sobre el renombrado de `worker-downloader` en `SDD-BATCH-002`; (6) fila de perímetro `AGENTS.md`/`CHANGELOG.md`/`plans/changelog.md`; (7) criterios de la Sección 18 con ID `CA-F2-01..16` (ampliados a `CA-F2-17` en la revisión 1.2) y matriz REQ→CA |
| Contratos modificados | Guarda de argumentos de `bin/audit-execution-maintenance.php` (comandos sin segundo argumento no se ven afectados); ningún contrato Redis/HTTP |
| Tareas afectadas | Ninguna implementada |
| Autor del cambio | Claude (Sonnet 5.5), por instrucción del usuario |
| Estado de aprobación | `PENDING_APPROVAL` (sin aprobar ni implementar) |


### Revisión 1.2 — decisiones del usuario (2026-11-22)

Guía paso a paso de `system.md` (CP3) para llevar la spec a Nivel A. Cada decisión fue tomada por el usuario mediante pregunta explícita; las lecturas de código citan `archivo:línea`.

| Campo | Valor |
| --- | --- |
| Versión anterior → nueva | 1.1 → 1.2 |
| Decisiones del usuario | (D3) añadir `CA-F2-17` para `REQ-07`, redactado solo con cifras que la spec ya contenía (`DEC-06`: `500 ms`/`500` ítems/`256 MiB`; test de `50.000` auditorías); (D7) aceptar Nivel A condicionado: se puede implementar y probar ya, el cutover productivo espera `SUP-03` (000 estable `24 h`) |
| Cambios | Sección 18: `CA-F2-17`; matriz REQ→CA: `REQ-07` → `CA-F2-17`; se elimina la brecha abierta de `REQ-07`; conteos `16` → `17` |
| Contratos modificados | Ninguno |
| Nivel | `Nivel A — Implementable, despliegue condicionado a SUP-03` (sin cambio de nivel; se retira la brecha de `REQ-07`) |
| Autor del cambio | Claude (Sonnet 5.5), por decisión explícita del usuario |
| Estado de aprobación | `PENDING_APPROVAL` (sin aprobar ni implementar) |


## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación verificada |
| --- | --- | --- |
| Tipo | Bug / Refactor / Migración / Contrato | `[CONFIRMADO]` Corrige ejecución SQL repetida por página (`H1–H2`), carreras sin fencing tras `XAUTOCLAIM` y corrupción `done > total` (`H3`), solapamiento cron/HTTP e identidad por `DisId` en lugar de `DisDetNro` (`H4`) y reescritura `~2,3 MB` de JSON en `job:{id}:state` (`H5`), añadiendo vencimiento de jobs sin progreso (`R-STALE`). `H6–H9` y `H11` se corrigen en el prerrequisito **SDD-BATCH-000**; aquí solo se extiende `gc()` a las claves v2. |
| Riesgo | Crítico | `[CONFIRMADO]` Modifica persistencia durable de ejecución en Redis (`job:*:state`, `job:*:audits`, `job:*:candidates`, `jobs:active:nit:*`, `audit:reservation:*`, `batch:idem:*`, `batch:claim:*`), el filtro SQL de `InvoicesModel`, la respuesta HTTP de `POST /audit/async` (`409` por solapamiento activo), workers en producción (`worker-batch`, `worker-execution-maintenance`) y el flujo de despliegue en `.github/workflows/deploy-production.yml`. |
| Persistencia afectada | Sí | `[CONFIRMADO]` Cambia el esquema de claves Redis de jobs y reservas, añade scripts idempotentes de migración y rollback, y ajusta el límite superior de fecha SQL en `InvoicesModel::buildOptimizedBatchSql()` sin alterar tablas físicas de SQL Server. |
| Contrato externo afectado | Sí | `[CONFIRMADO]` `POST /audit/async` incorpora rechazo `HTTP 409` ante jobs activos solapados del mismo NIT y rango de fechas (mantiene `409` existente por `X-Idempotency-Key`); `bin/schedule-daily-batches.php` omite creación en solapamiento; `GET /audit/jobs`, `GET /audit/jobs/{jobId}` y `GET /metrics/async` preservan sus esquemas JSON existentes. |
| Cambio arquitectónico | Sí | `[CONFIRMADO]` Separa metadatos del job (`job:{id}:state`, string `~600 B`) del mapa de auditorías (`job:{id}:audits`, Hash `O(1)`), introduce materialización única de candidatas (`job:{id}:candidates`), fencing monotónico (`generation_epoch`), índice de jobs activos por NIT (`jobs:active:nit:{nit}`), identidad de reserva por `DisDetNro`, y `gc()` incremental con lectura de solo `status` en Lua. |
| Producción afectada | Sí | `[CONFIRMADO]` Se despliega en el servidor productivo vía `.github/workflows/deploy-production.yml` y `docker-compose.yml`; incidentes productivos verificados directamente por API/SSH en `172.16.0.3` (`P-F2-01..08`: bloqueo `08S01` ~3,8 h, 5 jobs solapados `2624`, `done > total`, desfase `jobs.pending = 38`, caché cross-lane). Los benchmarks/reproducciones controladas `E1–E21` provienen de entorno local/QA (`DiscolmetsGx2QA`). |
| Requiere 0.3.1 (cobertura de abstracciones) | No | `[CONFIRMADO]` No reemplaza ningún mapeo estático (`switch`/lookup/constantes cerradas) por una abstracción dinámica basada en atributos; reemplaza estructuras de almacenamiento Redis (`JSON` inline → `Hash`/`List`), una llave operativa explícita (`DisId` → `DisDetNro`) y consulta SQL acotada. |

**Justificación de calibración de rigor:** Al clasificarse como **Riesgo Crítico** con persistencia en Redis, contratos REST y despliegue productivo activos, son obligatorias todas las secciones de FASE 0, FASE 1 (incluidas Observabilidad 19 y Estrategia de Rollout 20 con señales medibles de rollback), migración/rollback completos (Sección 9 y 14), FASE 2, FASE 3 (incluyendo auditoría adversarial) y FASE 4. No aplica Perfil Reducido.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `InvoicesModel.php` | `app/Models/InvoicesModel.php` | MODIFIED | Corregir filtro `dateTo` a `< DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`, elevar tope `getInvoicesForAuditBatch()` de `1000` a `50000`, aceptar `?string $jobId = null` para correlación en log SQL y `?int $queryTimeoutSeconds = null` aplicado como `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` en `prepare()` (`R-SQLTO`; hoy `prepare($sql)` sin opciones en `L309`, `execute()` en `L323`). | 134, 257-343 | Sí (L1-344 completas) |
| `JobLockClaimStatus.php` | `app/Services/Audit/Pipeline/JobLockClaimStatus.php` | NEW | Backed Enum cerrado (`acquired`, `busy`, `terminal`, `missing`) que tipifica el resultado de `claimJobGenerationFencedLock()`. | Nuevo archivo | N/A |
| `JobLockClaimResult.php` | `app/Services/Audit/Pipeline/JobLockClaimResult.php` | NEW | Value object `readonly` (`JobLockClaimStatus $status`, `?int $epoch`) con invariante `ACQUIRED => epoch >= 1`. | Nuevo archivo | N/A |
| `JobGenerationBusyException.php` | `app/Services/Audit/Pipeline/JobGenerationBusyException.php` | NEW | Excepción dedicada de contención temporal de lock de generación; evita `XACK` prematuro y no quema intentos hacia DLQ en `AuditEventConsumer`. | Nuevo archivo | N/A |
| `JobGenerationFailedException.php` | `app/Services/Audit/Pipeline/JobGenerationFailedException.php` | NEW | Excepción con `epoch` adjunto lanzada ante fallos de generación tras adquirir el lock; permite a `AuditEventConsumer::finalizeDeadLetterAudit()` respetar fencing al invocar `markJobFailed()`. | Nuevo archivo | N/A |
| `AuditEvent.php` | `app/Services/Audit/Pipeline/AuditEvent.php` | MODIFIED | Agregar `AuditEvent::deterministicUuidV4(string $identity)` compatible con `isUuidV4()` para identidad determinista por candidata, evento y continuación. | 215-245 | Sí (L101-246) |
| `AuditTaskEffects.php` | `app/Services/Audit/Pipeline/AuditTaskEffects.php` | MODIFIED | Delegar `AuditTaskEffects::uuid()` a `AuditEvent::deterministicUuidV4()` eliminando duplicación de formato UUID v4 determinista. | 10-16 | Sí (L1-80) |
| `BatchJobStore.php` | `app/Services/Audit/Pipeline/BatchJobStore.php` | MODIFIED | Desacoplar `job:{id}:audits` (Hash O(1)), `job:{id}:by_dispensa` (Hash idempotencia de despacho), `job:{id}:candidates` (List), `jobs:active:nit:{nit}` (Hash), fencing `generation_epoch` con guarda terminal/`reaping`/`sealed` en todos los Lua de generación, prefijo `REDIS_PREFIX` derivado de `KEYS[1]` en todo acceso dinámico, `releaseIdempotencyKeyIfMatches()`, `reapStaleJob()`, `cancelUndispatchedAudits()`, reserva por `DisDetNro`, retiro limpio de `initJob()`/`claimJobGenerationLock()`, conservación de `patchJob()`, migración/rollback idempotentes. | 57-686 | Sí (L50-686) |
| `AuditBatchOrchestrator.php` | `app/Services/Audit/AuditBatchOrchestrator.php` | MODIFIED | Congelar candidatas en Chunk 1 (`storeFrozenCandidates`) o desde `cursor` residual migrado sin bucle multi-SQL, identidad determinista `(jobId, disDetNro)`, recuperación pre/post-publicación vía `job:{id}:by_dispensa` + `last_continuation_dispatched_chunk`, manejo exhaustivo de `JobLockClaimStatus`, log `batch_generation_sql_ms` con NIT enmascarado, cierre vacío multichunk, compensación `cleanupAsyncEnqueueState()` (`L646-672`) restringida para no destruir estado de filas ya registradas en `by_dispensa`. | 23-694 | Sí (L1-694 completas) |
| `BatchRequestedWorker.php` | `app/Services/Audit/Pipeline/BatchRequestedWorker.php` | MODIFIED | Propagar `candidate_offset`, publicar continuación determinista y confirmar `markContinuationDispatched()` antes de permitir `XACK`, lanzar `JobGenerationBusyException` si el lock está ocupado, adjuntar `epoch` y `chunk_index` al fallar; en `__construct()` validar la inecuación `DEC-12`. | 35-54, 93-205 | Sí (L1-208 completas) |
| `BatchChunkState.php` | `app/Services/Audit/BatchChunkState.php` | MODIFIED | Agregar propiedad inmutable `candidateOffset` (`int >= 0`) desempaquetada desde `payload['candidate_offset'] ?? payload['cursor']['offset'] ?? 0`. | 13-70 | Sí (L1-70 completas) |
| `AuditController.php` | `app/Controllers/AuditController.php` | MODIFIED | En `async()`, usar `initJobExclusive()` para rechazar con `HTTP 409` solapamientos activos del mismo NIT/fechas y compensar `idempotencyKey`/`deleteJob` en `409`/`503`; mantener formato en `jobsList()`/`jobStatus()`. | 396-479, 520-590 | Sí (L1-200, L396-684) |
| `schedule-daily-batches.php` | `bin/schedule-daily-batches.php` | MODIFIED | Detectar/rechazar solapamiento contra jobs activos del NIT antes de crear job; liberar `idempotencyKey` si colisiona; usar `initJobExclusive()`; enmascarar `fac_nit_sec` (`'***' . substr(...)`) en logs. | 160-230 | Sí (L1-243 completas) |
| `Logger.php` | `core/Logger.php` | MODIFIED | Añadir `'fac_nit_sec'` a `$sensitiveKeys` en `sanitizeContext()` como defensa en profundidad contra exposición accidental de NIT en logs. | 43-60 | Sí (L1-100) |
| `AuditStateStore.php` | `app/Services/Audit/Pipeline/AuditStateStore.php` | IMPACTED | `getAuditStatus()`, `readAuditRetentionMetadata()` y heartbeat `HEARTBEAT_MAX_AGE_MS` los entrega **SDD-BATCH-000**; la Fase 2 los consume desde `gc()` (`HEARTBEAT_MAX_AGE_MS` además en la espera de heartbeat del workflow de despliegue); `REAP_STALE_JOB_LUA` no usa ninguno de los tres. | 102-196 | Sí (L1-621 completas) |
| `AuditExecutionMaintenance.php` | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` | MODIFIED | Delta sobre SDD-BATCH-000: patrón de reservas `audit:reservation:disdetnro:*` (mantiene `disid:*` hasta vaciar migración), referencias de job vía `HSCAN job:{id}:audits` por rebanadas (`hscan_job_id`/`hscan_cursor`), borrado atómico de claves v2 del job, e invocación de `reapStaleJob()` para jobs no terminales (`R-STALE`) dentro del presupuesto existente; guarda ampliada del `finally` heredado (`V-F1-B1`) para los índices `4` y `5`, y reinicio de `$this->lastGcReapedJobs` en `tick()`. | 202-365 (`tick()` y `gc()` en su versión de SDD-BATCH-000) | Sí (L1-435 completas) |
| `AuditExecutionConfig.php` | `app/Services/Audit/Pipeline/AuditExecutionConfig.php` | MODIFIED | Añadir `batchLockTtlSeconds()` (`Env::get('AUDIT_BATCH_LOCK_TTL_SECONDS', '1800')`, usado por `jobStaleAfterSeconds()`, `BatchRequestedWorker` y `AuditBatchOrchestrator`), `jobStaleAfterSeconds()` (lectura literal `Env::get('AUDIT_JOB_STALE_AFTER_SECONDS', '21600')`, cota inferior `max(batchLockTtlSeconds(), 3600)`, `R5-F2-B1`) y `freezeQueryTimeoutSeconds()` (`Env::get('AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS', '300')`). Las constantes de heartbeat ya existen por SDD-BATCH-000. | 10-60 | Sí (L1-98 completas) |
| `AuditAdmissionGuard.php` | `app/Services/Audit/Pipeline/AuditAdmissionGuard.php` | INSPECTED | Umbral de heartbeat unificado en SDD-BATCH-000; sin cambios en Fase 2. | 23-29 | Sí (L1-71 completas) |
| `AuditExecutionInspector.php` | `app/Services/Audit/Pipeline/AuditExecutionInspector.php` | MODIFIED | Leer `HLEN job:{id}:audits` en `snapshot()` sin exigir `audits` inline en `job:*:state`, y añadir `repairCorruptedJobs()` para sanear contadores históricos (`done + failed > total`, `actualTotal == 0`, preservando `status == 'failed'`). | 39-77 | Sí (L1-190 completas) |
| `AuditEventConsumer.php` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | MODIFIED | Interceptar `JobGenerationBusyException` en `dispatchMessage()` (sin `XACK` y sin contar reintento DLQ); en `handleFailure()`, ampliar `$nonRetryable` para `JobGenerationFailedException` cuyo `getPrevious()` sea `SqlServerOperationException`; en `finalizeDeadLetterAudit()`, cerrar `TYPE_BATCH_REQUESTED` con `markJobFailed($jobId, $msg, $epoch, $chunkIndex)` respetando fencing y, si devuelve `true`, cancelar las auditorías `dispatched = false` (`cancelUndispatchedAudits()`). | 383-425, 630-636, 692-730 | Sí (L1-813 completas) |
| `audit-execution-maintenance.php` | `bin/audit-execution-maintenance.php` | MODIFIED | Agregar CLI `migrate-batch-v2` (con `--clear-claims`; tras `migrateBatchV2()` invoca `$maintenance->expireLegacyIdempotency()` de SDD-BATCH-000, ya instanciado en `L26`), `rollback-batch-v2` (solo con `0` jobs activos, `DEC-11`), `repair-jobs` y `repair-jobs --force-stale[=jobId]` (`R-STALE`). `resume` y log de arranque ya actualizados en SDD-BATCH-000. | 18-93 | Sí (L1-93 completas) |
| `.env.example` | `.env.example` | MODIFIED | Alinear `AUDIT_BATCH_LOCK_TTL_SECONDS=1800` (hoy `300` en `L149`) con `AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS=1800000`; añadir `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=300` y `AUDIT_JOB_STALE_AFTER_SECONDS=21600`; documentar `DB_TIMEOUT`/`DB2_TIMEOUT` (leídas por concatenación en `core/Database.php:245`, no detectadas por `ci.yml:113`). | 25-43, 146-149 | Sí (L1-160) |
| `deploy-production.yml` | `.github/workflows/deploy-production.yml` | MODIFIED | Añadir input `operation` (`deploy` \| `rollback`) y `env.OPERATION` a nivel de workflow (`workflow_run` ⇒ `deploy`), alinear default `AUDIT_BATCH_LOCK_TTL_SECONDS` (`1800`) y nuevas variables, mover barrera (`ctl pause` + esperar cron + `docker compose stop -t 30 php worker-*`) ANTES del upgrade de extractores, ejecutar `migrate-batch-v2 --clear-claims` + `repair-jobs` + `reconcile` solo en `deploy`; en `rollback`, step previo a `L101` sobre el compose v2 vigente (`docker compose run --rm --no-deps -T --entrypoint php php ...`) que ejecuta `pause`, `stop` y `rollback-batch-v2` (aborta si hay jobs activos, `DEC-11`) y, si falla, relanza v2 y sale con `1`; nunca arrancar `worker-batch` de la imagen previa con datos v2; esperar heartbeat `< HEARTBEAT_MAX_AGE_MS` antes de `resume`. | 3-35, 101-109, 188, 404, 467-478, 529-578 | Sí (L1-115, L375-602) |
| `AGENTS.md` | `AGENTS.md` | MODIFIED | Actualizar default documentado de `AUDIT_BATCH_LOCK_TTL_SECONDS` de `300` a `1800` en el Catálogo de Variables de Entorno y sincronizar `gh variable set AUDIT_BATCH_LOCK_TTL_SECONDS --body "1800" --env production`, `gh variable set AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS --body "300" --env production` y `gh variable set AUDIT_JOB_STALE_AFTER_SECONDS --body "21600" --env production`. | 402 | Sí (L380-430) |
| `audit-identity-contract.md` | `plans/audit-identity-contract.md` | MODIFIED | Actualizar rol de reserva en Redis a `DisDetNro` (`1:1` con `AudDispEst.FacNro`), manteniendo `DisId` en `AudDispEst.FacSec`. | 18-47 | Sí (L1-57 completas) |
| `InvoicesModelTest.php` | `tests/Models/InvoicesModelTest.php` | MODIFIED | Actualizar aserciones SQL `d.DisFecSol < DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`, cubrir límite `50000` y correlación opcional `$jobId`. | 30, 62 | Sí (L1-193 completas) |
| `AuditControllerTest.php` | `tests/Controllers/AuditControllerTest.php` | MODIFIED | Actualizar el mock de `testPausedAdmissionReturns503BeforeCreatingAuditOrIdempotencyKey()` (`method('initJob')` → `method('initJobExclusive')`, `L132`, `X-F2-B2`), la clase anónima en `testAsyncReturns503WhenInitJobFails()` (`initJobExclusive`, `L236`), `StubBatchJobStore` (`initJobExclusive`, `releaseIdempotencyKeyIfMatches`, `sealJob`, `L778-824`), probar rechazo `409` por solapamiento activo y adaptar `T-U4` de SDD-BATCH-000 (conserva `$claimIdempotencyKeyThrowsInvalid`; la aserción «sin `initJob`» pasa a «sin `initJobExclusive`»). | 122-147, 233-250, 743-854 | Sí (L120-310, L540-610, L735-860) |
| `AuditBatchOrchestratorTest.php` | `tests/Services/Audit/AuditBatchOrchestratorTest.php` | MODIFIED | Actualizar doble `BatchOrchestratorJobStore` (`claimJobGenerationFencedLock(): JobLockClaimResult`, `storeFrozenCandidates`, `readFrozenCandidates`, `commitChunkCheckpoint`, `markContinuationDispatched`, `sealJob`, `publishEmptyBatch`, sin `initJob`/`claimJobGenerationLock`) y probar congelación sin re-SQL, `JobLockClaimStatus` completo, recuperación pre/post-publicación, continuaciones recuperables y hermanos `DisDetNro`; doble `BatchOrchestratorInvoicesModel::getInvoicesForAuditBatch()` (`L368-374`) con la firma de 7 parámetros. | 17-495 | Sí (L1-558 completas) |
| `BatchRequestedWorkerTest.php` | `tests/Services/Audit/Events/BatchRequestedWorkerTest.php` | MODIFIED | Sustituir `ReflectionMethod('handle')` por `$worker->processEvent($initialEvent)`, restaurar valor previo de `AUDIT_BATCH_CHUNK_SIZE` en `finally`, actualizar mocks de `BatchJobStore`, probar `JobGenerationBusyException` sin `XACK` ni DLQ y propagación de `candidate_offset` + `markContinuationDispatched`; las dos clases anónimas que extienden `InvoicesModel` (`L126-132`, `L219-225`) con la firma de 7 parámetros. | 103-282 | Sí (L1-283 completas) |
| `AuditPersistenceWorkerTest.php` | `tests/Services/Audit/Events/AuditPersistenceWorkerTest.php` | MODIFIED | Ajustar expectativa en `releasedReservations` al identificador `DisDetNro` (`'T38250701547'`). | 112-115 | Sí (L1-160, L250-584) |
| `BatchJobStoreMetricsTest.php` | `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php` | MODIFIED | Actualizar arreglo `$keys` esperado en `MARK_AUDIT_COMPLETED_IN_JOB_LUA` a las 3 claves exactas `[jobKey, telemetry, jobAuditsKey]` y cubrir preservación de `oldJobStatus == 'failed'`. | 45-48 | Sí (L1-68 completas) |
| `RedisTtlConfigTest.php` | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php` | MODIFIED | Verificar reserva por `audit:reservation:disdetnro:{disDetNro}` (las pruebas de TTL de idempotencia pertenecen a SDD-BATCH-000). | 132-150 | Sí (L1-156 completas) |
| `AuditMemoryCompactionAndGcTest.php` | `tests/Services/Audit/Pipeline/AuditMemoryCompactionAndGcTest.php` | MODIFIED | Delta sobre SDD-BATCH-000: `HSCAN` reanudable de `job:{id}:audits` (`hscan_job_id`/`hscan_cursor`), patrón `disdetnro`, borrado atómico de claves v2, rama de `reapStaleJob()` respetando el presupuesto, preservación de `pending_keys` ante corte previo al bucle por `reap_job_id` (`R5-F2-B2`), ausencia de bucle `SCAN '0'` con reservas `disid:*` sobrevivientes y guarda del `finally` en los índices `4` y `5` (incluida excepción en la última clave de la última página de `disid:*`, `R5-F2-I1`). | 111-188 | Sí (L1-221 completas) |
| `AuditExecutionMaintenanceTickRedisTest.php` | `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` | MODIFIED | Creado por SDD-BATCH-000; Fase 2 añade la aserción de `jobs_reaped` en `heartbeat:meta` (`T-I7`/`CA-F1-07` exigen al menos los 10 campos base, por lo que el campo extra es compatible) y su reinicio a `0` en un tick sin `gc()` (Sección 16, punto 9). | — | N/A (archivo de Fase 1) |
| `LoggerSanitizationTest.php` | `tests/Core/LoggerSanitizationTest.php` | NEW | Verifica redacción de `fac_nit_sec` en `Core\Logger::sanitizeContext()` y ausencia de NIT crudo en logs de `schedule-daily-batches.php` y `AuditBatchOrchestrator`. | Nuevo archivo | N/A |
| `AuditStaleJobReaperRedisTest.php` | `tests/Integration/AuditStaleJobReaperRedisTest.php` | NEW | Redis real: `REAP_STALE_JOB_LUA` (umbral, `batch:claim` vivo, cancelación de auditorías vivas incluido `audit:{id}:state`, liberación de reservas, telemetría, `jobs:active:nit`, incremento de `generation_epoch`), guarda terminal/`reaping` de `MARK_AUDIT_COMPLETED_IN_JOB_LUA` y de los Lua de generación, prefijo no vacío en los Lua de cierre/borrado y `cancelUndispatchedAudits()`; también aloja los tests de `gc()` `testGcReleasesOrphanReservationOfTerminalJob` y `testLegacyReservationPassSurvivesBudgetCutAcrossTicks` (`R4-F2-M3`). | Nuevo archivo | N/A |
| `AuditExecutionMigrationRedisTest.php` | `tests/Integration/AuditExecutionMigrationRedisTest.php` | MODIFIED | Probar en Redis real los escenarios `R-SEAL`, `R-ROLL`, `R-CONT`, `R-PREPUB`, `R-EMPTY` y `H3`: migración/reparación (`0` entradas, `failed` sellado, `a645fccc`), rollback reentrante en 2 ejecuciones, aborto sin pérdida ante colisión de hermanas `DisId`, `XAUTOCLAIM` + fencing, caída pre/post-publicación + continuación y `by_dispensa`. | 106-166 | Sí (L1-186 completas) |
| `FairAuditTaskQueue.php` | `app/Services/Audit/Pipeline/FairAuditTaskQueue.php` | IMPACTED | `ADMIT` recibe `job:{id}:state` como `KEYS[15]`, valida `TYPE == 'string'` (`L236-237`) y ejecuta `PERSIST` (`L258`). Se conserva `job:{id}:state` como `string`. Sin `audit:{id}:state` responde `audit_state_missing` sin `XACK` (`L238`, motivo de `W-F2-B1`); con estado terminal hace `XACK` y retorna `2` (`L240`). | 53-67, 233-261 | Sí (L1-100, L201-300) |
| `JsonRedisStoreTrait.php` | `app/Services/Audit/Pipeline/JsonRedisStoreTrait.php` | IMPACTED | `storeState()` escribe estado JSON sin TTL (`EX`), preservando durabilidad de ejecución mientras no venza retención en `gc()`. | 30-84 | Sí (L1-84 completas) |
| `AuditPersistenceWorker.php` | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | IMPACTED | Invoca `releaseAuditReservationFromAudit($audit)` (`L134`) y `markAuditCompletedInJob()` (`L326`). | 100-146, 311-333 | Sí (L1-170, L300-360) |
| `AuditFailureFinalizer.php` | `app/Services/Audit/Pipeline/AuditFailureFinalizer.php` | IMPACTED | Invoca `markAuditCompletedInJob()` (`L65`) y `releaseAuditReservationFromAudit()` (`L74`). | 55-79 | Sí (L1-80 completas) |
| `ObservabilityController.php` | `app/Controllers/ObservabilityController.php` | IMPACTED | Lee `audit:execution:heartbeat` (`L150`, `L167-169`) y `telemetry:async_metrics` (`L98`, `L113-135`). Mantiene contrato íntegro. | 96-170 | Sí (L1-200) |
| `AuditFlowController.php` | `app/Controllers/AuditFlowController.php` | IMPACTED | Verifica existencia de `$redis->get("job:{$id}:state") !== null` (`L30`). Mantiene compatibilidad al conservar `job:{id}:state`. | 18-35 | Sí (L1-100) |
| `BatchJobPerformanceTest.php` | `tests/Services/Audit/Pipeline/BatchJobPerformanceTest.php` | IMPACTED | Prueba `calculatePerformance()` y `listJobs()` con formato tabular JSON de `LIST_JOBS_LUA`. Contrato preservado sin cambios. | 13-98 | Sí (L1-100) |
| `AuditDlqRecoveryRedisTest.php` | `tests/Integration/AuditDlqRecoveryRedisTest.php` | IMPACTED | Usa `$now - 60000` en `stale_heartbeat` (`L143`), compatible con el nuevo límite `30000 ms` y `TTL = -1` en `audit:{id}:state`. | 128-196 | Sí (L1-200) |
| `AuditResultPersistenceModel.php` | `app/Models/AuditResultPersistenceModel.php` | INSPECTED | Upsert por `WHERE [FacNro] = @FacNro` (`L180`) confirmando `DisDetNro` como llave operativa `1:1`. | — | Sí (L1-120, L121-380) |
| `AuditTaskOutbox.php` | `app/Services/Audit/Pipeline/AuditTaskOutbox.php` | INSPECTED | `flush(int $limit = 100)` (`L15`). Invocado en bucle acotado desde `AuditExecutionMaintenance::tick()`. | — | Sí (L1-88 completas) |
| `AuditEventPublisher.php` | `app/Services/Audit/Pipeline/AuditEventPublisher.php` | INSPECTED | Enruta `document_*` a `audit.documents.*` (`L208-213`) y ejecuta `trimWorkStreams()` (`L137-143`). | — | Sí (L100-225) |
| `AuditDocumentCache.php` | `app/Services/Audit/Pipeline/AuditDocumentCache.php` | INSPECTED | Libera blobs al completar extracción (`release()`, `L82-90`), desbloqueando `AuditDocumentPrefetchGate`. Sin cambios en esta fase; el presupuesto por carril lo modifica `SDD-BATCH-002` (Fase 3). | — | Sí (L1-100) |
| `AuditDocumentPrefetchGate.php` | `app/Services/Audit/Pipeline/AuditDocumentPrefetchGate.php` | INSPECTED | Histéresis `90% / 70%` (`L12-13`, `L38-51`); reabre al drenar `extractors`. Sin cambios en esta fase; el cierre por residencia y la compuerta por carril los modifica `SDD-BATCH-002` (Fase 3). | — | Sí (L1-53 completas) |
| `AGENTS.md`, `CHANGELOG.md`, `plans/changelog.md` | `AGENTS.md`, `CHANGELOG.md`, `plans/changelog.md` | MODIFIED | Sincronización documental obligatoria al implementar (hook `enforce-docs-sync`, `.agent/scripts/check-docs-updated.mjs`, exige `plans/changelog.md`): entrada de esta fase y actualización de `AGENTS.md` donde cambien contratos operativos (workflow con `operation`, comandos de `audit-execution-maintenance`). | — | Sí (existen) |
| `AttachmentDownloadWorker.php` | `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php` | INSPECTED | Publica `document_downloaded` tras guardar en `AuditDocumentCache`. Sin cambios requeridos. | — | Sí (L60-155) |
| `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | INSPECTED | Consume `extraction` desde scheduler cuando `execution-intake` admite `document_downloaded`. | — | Sí (L1-100, L160-240) |
| `DocumentAuditOrchestrator.php` | `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php` | INSPECTED | Consume `TYPE_AUDIT_CREATED`; no interactúa con reservas (`claim`/`release`) en `/audit/single`. | — | Sí (L1-100) |
| `AuditTaskCommitter.php` | `app/Services/Audit/Pipeline/AuditTaskCommitter.php` | INSPECTED | Valida `audit:{id}:state` (`KEYS[11]`), no muta `job:{id}:state`. Con estado terminal (`failed` escrito por el reaper o por `cancelUndispatchedAudits()`) no aplica transiciones (`L78`, `L104`) y lee `audit.documents[...]` (`L97`), por lo que esos escritores conservan `documents`. | — | Sí (L1-100) |
| `AuditPersistenceQueue.php` | `app/Services/Audit/Pipeline/AuditPersistenceQueue.php` | INSPECTED | Limita persistencia SQL por scope de `job_id`. Sin cambios requeridos. | — | Sí (L1-100) |
| `audit-batch-console.tsx` | `frontend/components/audit/audit-batch-console.tsx` | INSPECTED | Llama `enqueueAuditBatch()` (`L77`) y muestra `describeError(error)` en toast `onError` (`L88-103`). | — | Sí (L1-155) |
| `client.ts` & `errors.ts` & `domain.ts` & `audfact.ts` | `frontend/lib/api/client.ts`, `frontend/lib/api/errors.ts`, `frontend/lib/schemas/domain.ts`, `frontend/lib/api/audfact.ts` | INSPECTED | `client.ts:61-67` lanza `ApiError(message, 409)` ante `!response.ok`; `errors.ts:38` retorna `this.message`; `domain.ts:342-459` valida respuestas de `POST /audit/async`, `GET /audit/jobs/{id}` y `GET /audit/jobs`. | — | Sí (`client.ts` L1-118, `errors.ts` L1-79, `audfact.ts` L120-190, `domain.ts` L335-460) |
| `ci.yml` | `.github/workflows/ci.yml` | INSPECTED | Valida consistencia `worker-*` vs `bin/audit-worker.php` (`L52-90`), existencia de `audit-execution-maintenance.php run` (`L89-90`) y paridad `Env::get`/`getenv` vs `.env.example` (`L109-129`). | — | Sí (L1-160 completas) |
| `docker-compose.yml` | `docker-compose.yml` | INSPECTED | Define `worker-batch` (`512M`, `replicas: 4`, `L138-161`) y `worker-execution-maintenance` (`512M`, `0.5 CPU`, `audit-execution-maintenance.php run`, `L327-339`); `php` sin `container_name` y con `replicas: 5` (`L37`, `L46`): no hay un único contenedor `php` que inspeccionar en el rollback. | — | Sí (L26-48, L135-185, L320-350) |
| `Dockerfile` & `docker-entrypoint.sh` | `docker/Dockerfile`, `docker/docker-entrypoint.sh` | INSPECTED | `ENTRYPOINT ["docker-entrypoint-custom.sh"]` (`Dockerfile:65`) ejecuta `composer install` si falta `vendor/.composer.lock.stamp` (`docker-entrypoint.sh:37-48`); por eso toda invocación CLI del workflow usa `--entrypoint php` (`deploy-production.yml:547,551,574`). Sin cambios. | — | Sí (`Dockerfile` L60-66, `docker-entrypoint.sh` L30-55) |
| `setup-production-cron.sh` | `scripts/setup-production-cron.sh` | INSPECTED | Invoca `docker compose exec -T php php bin/schedule-daily-batches.php --limit=$CRON_LIMIT` (`L43`). | — | Sí (L1-70 completas) |
| `RedisClient.php`, `Response.php`, `Database.php`, `Model.php`, `audit-worker.php` | `core/RedisClient.php`, `core/Response.php`, `core/Database.php`, `app/Models/Model.php`, `bin/audit-worker.php` | INSPECTED | Verifican prefijos automáticos en `eval()`/`scanKeys()`, `HttpResponseException` en `Response::error()`, lectura por `db2`, y registro de workers en CI. | — | Sí (rangos verificados en `0.2` y `0.4`) |
| `sdd-batch-generator-idempotency.md`, `sdd-erradicacion-bloqueo-memoria-redis.md` | `plans/sdd-batch-generator-idempotency.md`, `plans/sdd-erradicacion-bloqueo-memoria-redis.md` | INSPECTED | Specs históricas de lock `batch:claim:*` y GC v2 cuyas limitaciones se corrigen aquí. | — | Sí (`L1-234`, `L1-86` completas) |
| `AuditAdmissionGuardTest.php` | `tests/Services/Audit/Pipeline/AuditAdmissionGuardTest.php` | INSPECTED | No afirma `10000 ms`; solo capacidad por colas y memoria (`L17-71`). | — | Sí (L1-72 completas) |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `FairAuditTaskQueue\|AuditExecutionMaintenance\|AuditExecutionInspector\|BatchJobStore\|claimJobGenerationLock\|releaseJobGenerationLock\|sealJob` | `[CONFIRMADO]` Cobertura completa en `app/`, `bin/`, `tests/`, `plans/`. | `git grep -n` sobre `app/ bin/ tests/` |
| Búsqueda por importación/require | `use .*BatchJobStore`, `enqueueAuditBatch`, `auditAsync` | `[CONFIRMADO]` Identificados controladores PHP, workers CLI, dobles de prueba y consumidor Next.js único (`audit-batch-console.tsx`). | `git grep -n` sobre `app/ bin/ frontend/ tests/` |
| Búsqueda textual | `audit:reservation\|claimAuditReservation\|releaseAuditReservation\|storeState\|dateToD\|getInvoicesForAuditBatch\|10000\|STREAM_DOCUMENTS_` | `[CONFIRMADO]` Ubicadas todas las apariciones en PHP, Lua y tests. | `git grep -n -E` sobre `app/ tests/ bin/` |
| Búsqueda en configuración | `AUDIT_STATE_TTL\|AUDIT_JOB_\|AUDIT_BATCH_\|AUDIT_GC_\|AUDIT_EXECUTION_\|audit:execution:heartbeat` | `[CONFIRMADO]` Variables contrastadas contra `.env.example`, `ConfigController`, `docker-compose.yml`, `cron`. | `git grep -n -E` sobre `.env.example .github/ docker-compose.yml bin/ app/` |
| Búsqueda en tests | `markAuditCompletedInJob\|registerAuditInJob\|initJob(\|getJob(\|listJobs(\|releaseAuditReservationFromAudit\|completeAudit(` | `[CONFIRMADO]` Todos los tests y dobles afectados inventariados en `0.1` y `0.3`. | `git grep -n` sobre `tests/` |
| Búsqueda en workflows/CI | `schedule-daily-batches\|audit-execution-maintenance\|Validate env\|worker-\|operation\|AuditDocumentInput` | `[CONFIRMADO]` Verificadas reglas bloqueantes en `.github/workflows/ci.yml`, pasos de despliegue en `.github/workflows/deploy-production.yml` y catálogo en `AGENTS.md:402`. | `git grep -n` y lectura directa `.github/workflows/ci.yml:1-160`, `deploy-production.yml:1-100,170-210,375-602`, `AGENTS.md:380-430` |
| Inventario completo de llamadores internos `BatchJobStore` | `claimJobGenerationLock\|releaseJobGenerationLock\|initJob(\|sealJob(\|patchJob(\|registerAuditInJob\|getJob(` | `[CONFIRMADO]` Enumerados todos los llamadores reales y dobles de prueba en `app/`, `bin/` y `tests/`, incluido `AuditControllerTest.php:132,236,778,808` y `RedisTtlConfigTest.php:64,84,108`. | `git grep -n -E` sobre `app/ bin/ tests/` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `BatchJobStore.php` | `AuditController::async`, `jobsList`, `jobStatus` | `app/Controllers/AuditController.php` | 425-589 | Directa | Estática / Contractual | Repositorio local |
| `BatchJobStore.php` | `AuditBatchOrchestrator::enqueueBatch` | `app/Services/Audit/AuditBatchOrchestrator.php` | 75-672 | Directa | Estática / Contractual | Repositorio local |
| `BatchJobStore.php` | `schedule-daily-batches.php` | `bin/schedule-daily-batches.php` | 118-197 | Directa | Estática / Operacional | Repositorio local (Cron host) |
| `BatchJobStore.php` | `FairAuditTaskQueue::admit()` (`KEYS[15]` = `job:{id}:state`) | `app/Services/Audit/Pipeline/FairAuditTaskQueue.php` | 59, 236-258 | Directa | Contractual (Lua `TYPE` y `PERSIST`) | Repositorio local |
| `BatchJobStore.php` | `AuditExecutionInspector::snapshot()` | `app/Services/Audit/Pipeline/AuditExecutionInspector.php` | 55-68 | Directa | Estática / Contractual (`job:*:state`) | Repositorio local |
| `BatchJobStore.php` | `AuditExecutionMaintenance::gc()` | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` | 311-362 | Directa | Contractual (`job:*:state`, `audit:reservation:*`, `batch:idem:*`) | Repositorio local |
| `BatchJobStore.php` | `AuditPersistenceWorker::handle()` | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | 121, 134, 326 | Directa | Estática | Repositorio local |
| `BatchJobStore.php` | `AuditFailureFinalizer::finalize()` | `app/Services/Audit/Pipeline/AuditFailureFinalizer.php` | 63-74 | Directa | Estática | Repositorio local |
| `BatchJobStore.php` | `AuditEventConsumer::finalizeDeadLetterAudit()` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | 700, 717, 726 | Directa | Estática | Repositorio local |
| `BatchJobStore.php` | `ObservabilityController::asyncMetrics()` | `app/Controllers/ObservabilityController.php` | 98, 113-135 | Directa | Contractual (`telemetry:async_metrics`) | Repositorio local |
| `BatchJobStore.php` | `AuditFlowController::stream()` | `app/Controllers/AuditFlowController.php` | 30 | Directa | Contractual (`job:{id}:state`) | Repositorio local |
| `BatchJobStore.php` | `BatchJobStoreMetricsTest`, `BatchJobPerformanceTest`, `RedisTtlConfigTest` | `tests/Services/Audit/Pipeline/` | Varias | Directa | Estática / Contractual | Repositorio local (Tests) |
| `BatchJobStore.php` | `AuditExecutionMigrationRedisTest` | `tests/Integration/AuditExecutionMigrationRedisTest.php` | 127-166 | Directa | Operacional (Redis real) | Repositorio local (Tests) |
| `InvoicesModel.php` | `AuditBatchOrchestrator::collectChunkCandidates()` | `app/Services/Audit/AuditBatchOrchestrator.php` | 285 | Directa | Estática | Repositorio local |
| `InvoicesModel.php` | `InvoicesController` (vía `searchInvoices`/`countInvoices`) | `app/Models/InvoicesModel.php` | 24-113 | Transitiva | Estática (`buildOptimizedBatchSql`) | Repositorio local |
| `InvoicesModel.php` | `InvoicesModelTest` | `tests/Models/InvoicesModelTest.php` | 15-120 | Directa | Estática | Repositorio local (Tests) |
| `AuditBatchOrchestrator.php` | `BatchRequestedWorker::handleBatchRequested()` | `app/Services/Audit/Pipeline/BatchRequestedWorker.php` | 124-153 | Directa | Estática | Repositorio local |
| `AuditBatchOrchestrator.php` | `AuditBatchOrchestratorTest` | `tests/Services/Audit/AuditBatchOrchestratorTest.php` | 17-489 | Directa | Estática | Repositorio local (Tests) |
| `AuditExecutionMaintenance.php` | `bin/audit-execution-maintenance.php` | `bin/audit-execution-maintenance.php` | 26, 66, 83-85 | Directa | Estática / Operacional | Repositorio local (Docker daemon) |
| `AuditExecutionMaintenance.php` | `ci.yml` check `audit-execution-maintenance.php run` | `.github/workflows/ci.yml` | 89-90 | Directa | Contractual (CI grep) | Repositorio local (CI) |
| `AuditAdmissionGuard.php` | `AuditController::single()` & `async()`, `schedule-daily-batches.php`, `AuditStateStore::recoverFailedAudit()`, `AuditEventConsumer::intakeBlocked()` | Rutas en `0.1` | Varias | Directa | Estática / Contractual (`audit:execution:heartbeat`) | Repositorio local |
| `AuditController::async` | Frontend `AuditBatchConsole` vía `enqueueAuditBatch()` | `frontend/components/audit/audit-batch-console.tsx`, `frontend/lib/api/audfact.ts` | 77, 148 | Directa | Contractual HTTP (`POST /audit/async`) | Repositorio local (Next.js UI) |
| `AuditController::jobsList` / `jobStatus` | Frontend `AuditJobSchema` & `JobsListLive` | `frontend/lib/schemas/domain.ts`, `frontend/components/jobs/jobs-list-live.tsx` | 342-460, 541 | Directa | Contractual HTTP (`GET /audit/jobs*`) | Repositorio local (Next.js UI) |
| `AuditController::async` / `jobs*` / `metrics` | Consumidores externos desconocidos fuera del repo | N/A | N/A | N/A | HTTP REST (`app/Routes/web.php:40-42`) | `[DESCONOCIDO]` fuera del repositorio tras búsqueda en `app/`, `frontend/`, `app/wrap/` |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Modificar `d.DisFecSol <= :dateToD` por `< DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))` en `buildOptimizedBatchSql()` | `InvoicesModelTest::testSearchInvoicesAlwaysUsesPagedRangeQuery` y `testCountInvoicesUsesSameCandidateFilters` | `tests/Models/InvoicesModelTest.php:30,62` | Test | Actualizar aserciones de cadena SQL en ambas pruebas para exigir el nuevo predicado exacto. |
| Cambiar cálculo `total` de `$job['audits']` en `job:*:state` a `job:{id}:audits` (Hash) | `AuditExecutionInspector::snapshot()` (`count($job['audits'] ?? []) !== $total`) | `app/Services/Audit/Pipeline/AuditExecutionInspector.php:60-61` | Runtime / Data | Leer `HLEN` de `job:{id}:audits` en `snapshot()` antes de comparar con `$job['total']`. |
| Cambiar `MARK_AUDIT_COMPLETED_IN_JOB_LUA` a 3 `KEYS` (`jobKey`, `telemetry`, `jobAuditsKey`) manteniendo derivación interna de `jobs:active:nit:{nit}` y preservando `oldJobStatus == 'failed'` | `BatchJobStoreMetricsTest::testCompletionMetricsTransitionUsesPreviousJobStatus` | `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php:45-48` | Test | Actualizar arreglo `$keys` esperado en el mock a las 3 claves `[BatchJobStore::jobKey('job-metrics'), 'telemetry:async_metrics', BatchJobStore::jobAuditsKey('job-metrics')]` conservando intactas las líneas Lua validadas. |
| Cambiar clave de reserva a `audit:reservation:disdetnro:{disDetNro}` y `releaseAuditReservationFromAudit()` | `AuditPersistenceWorkerTest::testRulesEvaluatedPersistsAndCompletesAuditInSqlAndRedis` | `tests/Services/Audit/Events/AuditPersistenceWorkerTest.php:112-115` | Test | Actualizar aserción `releasedReservations` a `[['T38250701547', 'reservation-token']]`. |
| Cambiar clave de reserva a `audit:reservation:disdetnro:{disDetNro}` | `RedisTtlConfigTest::testReservationClaimUsesDurableAtomicSetNx` | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php:140` | Test | `BatchJobStore::auditReservationKey()` mantiene su nombre de método; ajustar prueba para verificar prefijo `audit:reservation:disdetnro:877` con `DisDetNro`. Se conserva `patchJob()` sin `audits` para mantener compatibilidad con `RedisTtlConfigTest.php:64,84,108`. |
| Cambiar clave de reserva y formato de `job:{id}:audits` en `gc()` | `AuditExecutionMigrationRedisTest::testGcCollectsPriorTerminalRecordsAndOrphanedReservationsWhilePreservingActiveOnes` | `tests/Integration/AuditExecutionMigrationRedisTest.php:127-166` | Test | Actualizar prueba de integración para sembrar `job:{id}:audits` y `audit:reservation:disdetnro:*` y añadir cobertura `R-SEAL`, `R-ROLL`, `R-CONT`, `R-PREPUB`, `R-EMPTY`, `H3`. |
| Reemplazar `getAudit()` en `gc()` por `AuditStateStore::getAuditStatus()` (vía `EVAL` en lugar de `GET`) | `AuditMemoryCompactionAndGcTest::testGcImmediatelyDeletesTerminalAuditTasksAndSemanticPlansInSingleStep` | `tests/Services/Audit/Pipeline/AuditMemoryCompactionAndGcTest.php:137-185` | Test | Actualizar stub de `eval()` en la prueba para responder `'failed'` ante el script de `getAuditStatus()`. |
| Retirar `initJob()` y `claimJobGenerationLock()`, reemplazar firma de `sealJob()`, y cambiar `AuditBatchOrchestrator` a `storeFrozenCandidates()` + `readFrozenCandidates()` + `claimJobGenerationFencedLock()` | `AuditBatchOrchestratorTest` (`BatchOrchestratorJobStore`), `BatchRequestedWorkerTest`, `AuditControllerTest` (`L132` mock en `testPausedAdmissionReturns503BeforeCreatingAuditOrIdempotencyKey`, `L236` subclase anónima en `testAsyncReturns503WhenInitJobFails` y `StubBatchJobStore` `L778,808`) | `tests/Services/Audit/AuditBatchOrchestratorTest.php:395-489`, `tests/Services/Audit/Events/BatchRequestedWorkerTest.php:125-278`, `tests/Controllers/AuditControllerTest.php:236,743-854` | Test | Retirar `initJob()`/`claimJobGenerationLock()` en todos los dobles y llamaradores; actualizar `sealJob` (`L808`) y subclase anónima `L236` en `AuditControllerTest`; sustituir `ReflectionMethod('handle')` por `processEvent()` y restaurar `AUDIT_BATCH_CHUNK_SIZE` en `BatchRequestedWorkerTest.php:177,194`. |
| Envolver `job` activo por `jobs:active:nit:{nit}` y rechazar solapamiento antes de crear job | Jobs `TYPE_BATCH_REQUESTED` enviados a DLQ quedarían bloqueando futuros lotes por 24h si permanecen `pending`; inversamente, una DLQ tardía de chunk obsoleto no debe cerrar un job ya tomado por otro epoch | `app/Services/Audit/Pipeline/AuditEventConsumer.php:692-730` | Data / Runtime | En `finalizeDeadLetterAudit()`, si `eventType === TYPE_BATCH_REQUESTED` y `jobId !== null`, invocar `markJobFailed($jobId, $msg, $expectedEpoch, $failedChunkIndex)` con fencing `generation_epoch`, `!sealed` y verificando chunk no confirmado. |
| Agregar `initJobExclusive()` con validación de solapamiento en `AuditController::async()` | `POST /audit/async` si ya reclamó `X-Idempotency-Key` nuevo antes de detectar solapamiento | `app/Controllers/AuditController.php:430-447` | Contract / Runtime | Ejecutar `releaseIdempotencyKeyIfMatches($idempotencyKey, $jobId)` tanto en `409` por solapamiento como en `503` al fallar `initJobExclusive()` o `publish()`. |
| Lanzar `JobGenerationBusyException` en `BatchRequestedWorker` cuando el lock esté ocupado (`BUSY`) | Sin protección explícita, `AuditEventConsumer::dispatchMessage()` ejecutaría `XACK` o quemaría reintentos hacia DLQ ante una contención temporal | `app/Services/Audit/Pipeline/AuditEventConsumer.php:391-425` | Runtime | Interceptar `JobGenerationBusyException` en `AuditEventConsumer::dispatchMessage()` devolviendo sin `ackMessage()` ni incremento de `attempts` (conserva mensaje en PEL para reentrega segura). |
| Enmascarar NIT en logs operativos y proteger `fac_nit_sec` en `Core\Logger` | `schedule-daily-batches.php` y `AuditBatchOrchestrator` podrían exponer NIT sin máscara porque `$sensitiveKeys` (`Logger.php:45`) no redactaba `fac_nit_sec` | `core/Logger.php:45-60`, `bin/schedule-daily-batches.php:146-148,177-190,216-227` (llamadas `Logger::*`; `L205` es el payload del evento, que sí requiere el NIT real) | Seguridad / Privacy | Enmascarar `'***' . substr((string) $facNitSec, -3)` antes de loguear y agregar `'fac_nit_sec'` a `Logger::$sensitiveKeys`. |
| Elevar default `AUDIT_BATCH_LOCK_TTL_SECONDS` de `300` a `1800` y mover barrera de migración en `deploy-production.yml` | `.github/workflows/deploy-production.yml:188,543,570` lee `${{ vars.AUDIT_BATCH_LOCK_TTL_SECONDS }}` (override en GitHub Environment) y actualizaba `extractors` en `L552` antes de `Start production stack`; `workflow_dispatch` de rollback corre sobre checkout de `main` | `.env.example:149`, `.github/workflows/ci.yml:109-129`, `.github/workflows/deploy-production.yml:9,188,404,543-578`, `AGENTS.md:402` | Pipeline | Actualizar `.env.example`, `AGENTS.md:402` y ejecutar `gh variable set AUDIT_BATCH_LOCK_TTL_SECONDS --body "1800" --env production`; mover `ctl pause` + espera cron + `docker compose stop -t 30 php worker-*` ANTES de `L543`; definir `OPERATION: ${{ github.event_name == 'workflow_run' && 'deploy' \|\| inputs.operation }}` en el `env:` del workflow (`L22-29`; en `workflow_run` `inputs.operation` es `''` y sin este valor los despliegues automáticos de `main` omitirían la rama `deploy`); separar rama `operation=rollback` que ejecute `rollback-batch-v2` antes de `L101` sobre el compose v2 vigente y no ejecute comandos v2 en imágenes previas (Sección 13). |
| Cambiar la firma de `InvoicesModel::getInvoicesForAuditBatch()` a 7 parámetros (`?string $jobId`, `?int $queryTimeoutSeconds`) | Dobles que extienden `InvoicesModel` y sobrescriben el método con 5 parámetros | `tests/Services/Audit/AuditBatchOrchestratorTest.php:368-374`; `tests/Services/Audit/Events/BatchRequestedWorkerTest.php:126-132,219-225` | Test (*Fatal error* de firma incompatible en PHP 8.2) | Actualizar los 3 dobles a la firma de 7 parámetros con los mismos defaults. |
| Añadir `jobs_reaped` al `heartbeat:meta` vía `$extraMeta` de SDD-BATCH-000 | `AuditExecutionMaintenanceTickRedisTest` (`T-I7`, `CA-F1-07`) | `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` | Test / Runtime | Aserción de `jobs_reaped` (además de los 10 campos base) y reinicio `$this->lastGcReapedJobs = 0` en cada `tick()` para no repetir conteos de un `gc()` anterior. |
| Retirar `initJob()` (`R-CLEAN`) | `T-U4` de SDD-BATCH-000 (`AuditControllerTest::testAsyncReturns500WhenIdempotencyTtlIsInvalid`, aserción «sin `initJob`») | `tests/Controllers/AuditControllerTest.php:750-766` (doble); `plans/sdd-hotfix-mantenimiento-ejecucion.md:996` | Test | Conservar `$claimIdempotencyKeyThrowsInvalid` en `StubBatchJobStore` y afirmar «sin `initJobExclusive`». |
| `migrate-batch-v2` necesita la expiración de `batch:idem:*` heredados | `AuditExecutionMaintenance::expireLegacyIdempotency()` (SDD-BATCH-000) depende de `BatchJobStore`; invocarla desde `BatchJobStore::migrateBatchV2()` crearía un ciclo `BatchJobStore → AuditExecutionMaintenance` | `bin/audit-execution-maintenance.php:26`; `plans/sdd-hotfix-mantenimiento-ejecucion.md:803-815` | Arquitectura | Invocar `$maintenance->expireLegacyIdempotency()` desde la CLI `migrate-batch-v2` tras `migrateBatchV2()`; `BatchJobStore` no la conoce. |
| Identidad determinista `auditId`/`reservationToken` por `(jobId, disDetNro)` | `AuditBatchOrchestrator::cleanupAsyncEnqueueState()` borra estado y reservas que, por ser deterministas, pueden pertenecer a la auditoría viva de otro worker | `app/Services/Audit/AuditBatchOrchestrator.php:646-672`; `FairAuditTaskQueue.php:238` | Runtime / Data | Restringir la compensación (Sección 13, paso 8) y recrear estado faltante en Step 5 (`W-F2-B1`). |
| Modificar variables o valores en `.env` | CI step `Validate env contract completeness` | `.github/workflows/ci.yml:109-129` | Pipeline | Todo `Env::get`/`getenv` nuevo o ajustado (`AUDIT_BATCH_LOCK_TTL_SECONDS`, `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS`, `AUDIT_JOB_STALE_AFTER_SECONDS`) se refleja en `.env.example`, `AGENTS.md` y `.github/workflows/deploy-production.yml` (`env:` + `write_env_var` + `gh variable set`). |
| Si algún servicio nuevo se llamara `worker-*` en `docker-compose.yml` sin entrar en `bin/audit-worker.php` | CI step `Validate worker services in docker-compose` | `.github/workflows/ci.yml:61-86` | Pipeline | No añadir servicios `worker-*` nuevos en `docker-compose.yml`; optimizar `AuditExecutionMaintenance` dentro del servicio existente `worker-execution-maintenance`. |

### 0.3.1 Verificación de Cobertura de Abstracciones

`N/A` — `[CONFIRMADO]` Verificado en Triage: esta especificación no sustituye mapeos estáticos (`switch`/lookup/constantes cerradas) por abstracciones dinámicas; desacopla almacenamiento Redis por identidad de clave, cambia la clave de reserva a una columna explícita (`DisDetNro`) y corrige un predicado de fecha SQL.

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| SQL Server 2022 (`pdo_sqlsrv`) | `CONVERT(datetime, @param, 23)` con `PDO::PARAM_STR` interpreta formato ISO-8601 `YYYY-MM-DD` independientemente de `SET DATEFORMAT`/`LANGUAGE` de la sesión, y `DATEADD(day, 1, ...)` sobre parámetro convertido mantiene `d.DisFecSol` sin envolver en función (SARGable). | Documental / Estática | Microsoft T-SQL `CAST and CONVERT` estilo `23` (`yyyy-mm-dd`); columnas `Dispensacion.DisFecSol datetime` verificadas en `Diagnóstico batch NIT 2624.md` (`E5`, L192) y `InvoicesModel.php:134,232-233`. | Sí — compatible con binds string existentes en `InvoicesModel::bindInvoiceFilters()` y `getInvoicesForAuditBatch()`. |
| Redis 7 + Predis (`Core\RedisClient`) | `RedisClient::eval()` prepende `$this->prefix` exclusivamente a los elementos de `$keys`, nunca dentro de strings Lua, y serializa cada item de `$argv` con `(string)` o `json_encode`. `scanKeys()` prepende prefijo al `MATCH` y lo recorta al retornar. | Estática | `core/RedisClient.php:423-459`; `tests/Support/IsolatedAuditRedis.php:32-41,77-81`. | Sí — toda clave accedida dinámicamente dentro de Lua extrae el prefijo efectivo desde `KEYS[i]`: `LIST_JOBS_LUA` con `string.match(KEYS[1], '^(.*)jobs:index$') or ''`, y `INIT_JOB_EXCLUSIVE_LUA`, `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA`, `DELETE_JOB_LUA`, `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `REAP_STALE_JOB_LUA` y `CANCEL_UNDISPATCHED_AUDITS_LUA` con `local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''` para `prefix .. 'job:' .. id .. ':state'`, `prefix .. 'jobs:active:nit:' .. nit`, `prefix .. 'audit:' .. auditId .. ':state'` y `prefix .. 'audit:reservation:disdetnro:' .. ddn` (`W-F2-B2`). |
| Redis Lua (`cjson` / atomicidad `SET ... NX EX` / stack `unpack` / `SCAN` / `HSCAN`) | `SET key val NX EX ttl` exige `tonumber(ttl) > 0`; `HSET`, `HGET`, `HLEN`, `HSCAN` operan en `O(1)` por campo sin decodificar el conjunto completo de auditorías; `unpack()` en Lua 5.1 tiene tope de stack (`~8.000` argumentos), por lo que `RPUSH` masivo de hasta `50.000` candidatas y comprobaciones de referencias en `gc()` (`SMEMBERS audit:execution:refs:{auditId}` ignorando miembros con prefijo `plan:`, misma regla que `AuditExecutionMaintenance.php:70-83`) se ejecutan en bloques de `<= 500` elementos; además, Redis `SCAN` trata `COUNT` como sugerencia y puede devolver claves repetidas entre iteraciones. | Documental / Experimental / Estática | Documentación oficial Redis `SCAN` (`redis.io/docs/latest/commands/scan/`); `Diagnóstico batch NIT 2624.md` (`E18`, L336-353: Hash `27.000` entradas RTT `11,75 ms` vs JSON `81,31 ms`); `core/RedisClient.php:342-357`; `AuditTaskEffects.php:10-15` y `AuditEvent.php:226-232`. | Sí — valida `ttl > 0` rechazando `<= 0` con error, particiona `RPUSH` y `SMEMBERS` en bloques `<= 500`, deduplica claves `SCAN` por ciclo e implementa rotación round-robin persistente entre patrones. |
| PHP 8.2 (Arrays Zend / GC / Backed Enums / SHA-256 UUIDv4) | `json_decode($raw, true)` expande arreglos asociativos PHP en `~5,6×` sobre el tamaño JSON en bytes; los Backed Enums garantizan dominio cerrado en respuestas de lock (`JobLockClaimStatus`); `AuditEvent::isUuidV4()` exige versión `4` y variante `[89ab]` exactamente como implementa `AuditTaskEffects::uuid()`. | Empírica / Estática | `Diagnóstico batch NIT 2624.md` (`E20`, L371-385); `bin/audit-execution-maintenance.php:16`; `docker-compose.yml:338`; `AuditEvent.php:226-232`; `AuditTaskEffects.php:10-15`. | Sí — delega extracción de `status` a Lua, centraliza UUID determinista v4 en `AuditEvent::deterministicUuidV4()`, tipa el resultado del lock con Enum y corta `gc()` antes de `256 MB`. |
| GitHub Actions (`ci.yml`) | Exige consistencia entre `Env::get`/`getenv` y `.env.example` (`Validate env contract completeness`) y presencia de `audit-execution-maintenance.php run` en `docker-compose.yml`. | Estática | `.github/workflows/ci.yml:61-90,109-129`. | Sí — se preserva `worker-execution-maintenance` y toda variable leída existe en `.env.example`. |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose (WSL) | `docker compose up -d` + `php bin/audit-worker.php` / `php bin/audit-execution-maintenance.php run` | Sí | `docker-compose.yml:138-161,327-339`; `bin/audit-execution-maintenance.php:1-93` |
| CI automatizado | GitHub Actions (`ci.yml`) | `vendor/bin/phpunit --configuration phpunit.xml --testdox`, `php -l`, `Validate worker services`, `Validate env contract completeness` | Sí | `.github/workflows/ci.yml:12-160` |
| Staging / Preproducción | `N/A` — inexistente en workflows de despliegue | `[CONFIRMADO]` `.github/workflows/` únicamente declara `ci.yml` y `deploy-production.yml` | N/A | `.github/workflows/ci.yml:1-165`; `.github/workflows/deploy-production.yml:1-40` |
| Producción | GitHub Actions self-hosted (`deploy-production.yml`) + Crontab (`setup-production-cron.sh`) | Despliegue en `nodo1` con `docker compose --profile frontend --profile docs up -d`; cron `0 11,15,19,23 * * *` vía `bin/schedule-daily-batches.php` | Sí | `.github/workflows/deploy-production.yml:32-36,540-596`; `scripts/setup-production-cron.sh:43` |
| Testing aislado | PHPUnit Unit + Suite Redis (`RUN_REDIS_INTEGRATION=1`) | `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` y `RUN_REDIS_INTEGRATION=1` con `IsolatedAuditRedis` | Sí | `phpunit.xml:1-27`; `tests/Support/IsolatedAuditRedis.php:12-82`; `.github/workflows/ci.yml:131-160` |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| Significado operativo de `AudDispEst.FacNro` (`== DisDetNro`) y `AudDispEst.FacSec` (`== DisId`) | `[CONFIRMADO]` | Solicitud del usuario (aclaración explícita sobre `[Discolnet].[dbo].[AudDispEst]`); `plans/audit-identity-contract.md:8-27`; `app/Models/AuditResultPersistenceModel.php:154-196` |
| Colisiones falsas en reserva por `DisId`: `27` de `1.000` filas en `#Sopo` y `11.901` de `123.555` `DisId` en servicio | `[CONFIRMADO en fuente externa]` | Fuente externa provista por el usuario (`Diagnóstico batch NIT 2624.md`, `E21`, L412-415; requiere verificación contra SQL/Redis productivo en cierre). |
| Política de solapamiento de jobs (`H4`): rechazar nuevas solicitudes solapadas (`HTTP 409` en API, omisión en cron) mientras exista job activo no terminal del mismo NIT y rango de fechas | `[CONFIRMADO]` | Decisión explícita del usuario en conversación previa del proyecto. |
| Política de congelación de candidatas (`H1–H2`): materializar conjunto tras consulta inicial al iniciar el job, sin re-ejecuciones de `#DISP..#Sopo` por chunk | `[CONFIRMADO]` | Decisión explícita del usuario en conversación previa; código actual confirmado en `app/Models/InvoicesModel.php:118-330`, `app/Services/Audit/AuditBatchOrchestrator.php:274-354`. |
| Filtro superior de fecha SQL (`dateTo`): incluir todo el día mediante `< DATEADD(day, 1, ...)` manteniendo `DisFecSol` sin envolver | `[CONFIRMADO]` | Decisión explícita del usuario; `app/Models/InvoicesModel.php:134,232-233,312`. |
| Clave de idempotencia del cron cambia por hora y `claimIdempotencyKey` omite TTL (`TTL = -1`) | `[CONFIRMADO]` | Código verificado en `bin/schedule-daily-batches.php:171` y `app/Services/Audit/Pipeline/BatchJobStore.php:305-315`; efecto operativo reportado en `Diagnóstico batch NIT 2624.md` (`E21`, L404-410). |
| Job histórico `a645fccc` tiene `total = 1000`, `done = 2975`, `failed = 1`, rompiendo `AuditExecutionInspector::snapshot()` | `[CONFIRMADO en fuente externa]` | Regla estructural verificada en `app/Services/Audit/Pipeline/AuditExecutionInspector.php:60-62`; conteos históricos de producción provienen de `Diagnóstico batch NIT 2624.md` (`E21`, L408). |
| `FairAuditTaskQueue::ADMIT` exige `TYPE KEYS[15] == 'string'` (`job:{jobId}:state`) y ejecuta `PERSIST` | `[CONFIRMADO]` | Código verificado en `app/Services/Audit/Pipeline/FairAuditTaskQueue.php:59,236-258`. |
| Reescritura completa `job:{id}:state` (`REGISTER_AUDIT_IN_JOB_LUA` y `MARK_AUDIT_COMPLETED_IN_JOB_LUA`); tamaño `~2,3 MB` en lotes grandes | `[CONFIRMADO]` / `[INFERIDO]` | Comportamiento de reescritura completa verificado en `BatchJobStore.php:471-609` y `JsonRedisStoreTrait.php:78-82`; volumen `~2,3 MB` / latencia `81 ms` tomado de fuente externa `Diagnóstico batch NIT 2624.md` (`E13`, `E18`). |
| Agotamiento de `512M` en PHP dentro de `gc()` por retención de `$audits[$auditId]` y `json_decode` en `JsonRedisStoreTrait.php:32` | `[CONFIRMADO]` | Riesgo OOM estructural verificado en `bin/audit-execution-maintenance.php:16`, `docker-compose.yml:338` y `AuditExecutionMaintenance.php:211-217`; frecuencia `19–42 s` reportada en fuente externa (`E20`, `H11`). |
| Desalineación de umbral de heartbeat: `EX 30` en writer vs `10000 ms` en lectores (`AuditAdmissionGuard`, `AuditStateStore`, `bin/audit-execution-maintenance.php`) | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:181-184`; `app/Services/Audit/Pipeline/AuditAdmissionGuard.php:25`; `app/Services/Audit/Pipeline/AuditStateStore.php:139`; `bin/audit-execution-maintenance.php:41`. |
| Estancamiento potencial de `extractors` en stream compartido `audit.documents.batch` y saturación de `AuditDocumentPrefetchGate` (`90/70 %`) | `[CONFIRMADO]` | Mecanismo `XREADGROUP COUNT capacity` + descarte sin avance de lecturas confirmado en `AuditExecutionMaintenance.php:106-113,157-170,429-433` y `AuditDocumentPrefetchGate.php:12-51`; backlog `9.940–21.541` reportado en fuente externa (`E8`, `E9`). |
| Cierre de jobs en v1: `sealed = true` solo en `sealJob` (último chunk) o `publishEmptyBatch`; único cierre en `MARK_AUDIT_COMPLETED_IN_JOB_LUA` con `sealed and processed >= total and total > 0` → todo job `completed*` v1 está sellado | `[CONFIRMADO]` | `AuditBatchOrchestrator.php:410-422,580-587`; `BatchJobStore.php:255-262,558` |
| Ausencia de reaper de jobs no terminales: `gc()` solo recoge terminales; DLQ de `batch_requested` no toca el job; `AuditFailureFinalizer` y `recoverFailedAudit` no marcan jobs | `[CONFIRMADO]` | `AuditExecutionMaintenance.php:318-320`; `AuditEventConsumer.php:694-704`; `AuditFailureFinalizer.php:58-61`; `AuditStateStore.php:104-105` |
| El cron usa rango por defecto `Y-01-01 → hoy` en `0 11,15,19,23 * * *`: toda corrida se solapa con cualquier job activo del NIT | `[CONFIRMADO]` | `bin/schedule-daily-batches.php:46-52`; `scripts/setup-production-cron.sh:23-24,43` |
| Sin timeout de consulta SQL: solo `LoginTimeout`; `HYT00` no se reintenta y `08S01` se reintenta 4 veces (`1+5+30 s`) | `[CONFIRMADO]` | `core/Database.php:91-109,245`; `core/SqlServerConnectionExecutor.php:14-15,182-191` |
| En producción, la consulta de candidatos contra `db2` retuvo los 4 `worker-batch` hasta ~3,8 h (`08S01`, `elapsed_ms = 13.647.092`) y bloqueó a los demás clientes; se resolvió con `docker compose restart worker-batch` | `[CONFIRMADO en fuente externa]` (diagnóstico de producción, 2026-11-19) | `P-F2-01`, `P-F2-02` |
| En producción (`172.16.0.3`, RCA `2026-11-19 14:56Z–15:19Z`), ráfaga de `1.576 req/h` en `POST /audit/single` desde `52.117.172.102` saturó `extraction.priority` (`3.192` tareas, `69,6 min` > `TTL 3600 s`) con `2` `worker-extraction-vip` (`1` `worker-semantic-vip`), mantuvo `AuditDocumentCache` en `71,4–85,9 %` (`+16` `prefetch_pauses`) y bloqueó los `16` `worker-downloader` con `extraction.batch` en `queued = 0` (`location = /audit` en Nginx no cubre `/audit/single`) | `[CONFIRMADO]` en código el caché/gate global y Nginx; `[CONFIRMADO en fuente externa]` las métricas de `172.16.0.3` | `AuditDocumentCache.php:15`; `AuditDocumentPrefetchGate.php:38-49`; `AuditController.php:94`; `docker/nginx-ha.conf.template:95-99`; `docker-compose.yml:184,349`; `P-F2-07` |
| En el mismo RCA (`172.16.0.3`): `GET /metrics/async` reportó `jobs.pending = 38` (`0` reales); `4` lotes activos de `3.000` de NUEVA EPS (`2624`, más un 5.º entrando a las `15:00Z`) saturaron `6` réplicas de `worker-semantic-batch` (`2.222` en cola, `~55 min`); `5c4ca134` figuró con `sealed = true` | `[CONFIRMADO en fuente externa]` mediciones de producción; `[CONFIRMADO]` `replicas: 6` (histórico: previo al commit `2d1a467`; `HEAD` = `12`) | `docker-compose.yml:359`; `P-F2-06`; `P-F2-08` |
| El publisher no deduplica por `event_id` en streams de trabajo (`XADD *`); una continuación republicada se duplica | `[CONFIRMADO]` | `AuditEventPublisher.php:100-104,179` |
| Rendimiento de salida `~175–189` auditorías/h: un job de `30.000` mantendría el NIT bloqueado `~7 días` bajo `INV-03` | `[CONFIRMADO en fuente externa]` / `[INFERIDO]` | `Diagnóstico batch NIT 2624.md` (`H10`, `E21`) |
| Volumen máximo futuro en producción de filas candidatas por NIT en una ventana anual por encima de `50.000` filas o latencia SQL en `db2 (.58)` sin índices específicos | `[INFERIDO]` | Extrapolación sobre fuente externa `Diagnóstico batch NIT 2624.md` (`E5`, L180-192: `.53` devolvió `71.325–73.048` anuales; `.58` agotó `600 s`). |

### 0.7 Información Faltante Crítica

Ninguna. `[CONFIRMADO]` Las 4 definiciones funcionales fueron resueltas explícitamente por el usuario y todo el código fuente, scripts SQL/Lua, configuración CI/CD y suites de prueba del perímetro fueron leídos directamente.

### 0.8 Información Faltante Importante

Ninguna. `[CONFIRMADO]`

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Planes de ejecución (`showplan_xml`, `STATISTICS IO`) de `#DISP..#Sopo` comparando `default (.53)` vs `db2 (.58)` y efecto de índice en `DisNitSec nvarchar(20)` vs `PDO::PARAM_INT` | `[CONFIRMADO]` No requeridos para eliminar ejecuciones repetidas por chunk ni para congelar candidatas (`Diagnóstico batch NIT 2624.md` `O1–O3`). | Solo relevante para optimización futura de la consulta base SQL; no modifica los contratos ni archivos de esta especificación. |
| Inventario completo de clientes externos HTTP fuera del repositorio que invoquen `POST /audit/async` | `[DESCONOCIDO]` Fuera del alcance del repositorio local (`app/Routes/web.php`, `frontend/`, `app/wrap/`). | La preservación de esquema HTTP 202/409/200 y la adición estricta de `HTTP 409` por conflicto de solapamiento cumplen semántica REST estándar. |

### 0.10 Supuestos Declarados

| ID | Supuesto | Base | Consecuencia si es Falso | Severidad | Estado | Mitigación y Detección | Condición de Cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `SUP-01` | La ejecución única inicial de `InvoicesModel::getInvoicesForAuditBatch()` limitada a `BATCH_MAX_FREEZE_LIMIT = 50000` completa `execute()` + `fetchAll()` dentro de `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` (default `300`) y cabe en `512 MiB` de `worker-batch`. **El lock NO se renueva durante la consulta** (PHP monohilo, `execute()` bloqueante): se renueva inmediatamente antes de `execute()` y después de `fetchAll()`. | `[INFERIDO]` `E5`: `~71.350` filas anuales en `~134 s` en `.53` (`execute 35–132 s`, `fetch ~88–92 s` = `~1,2–1,3 ms/fila`); la SQL devuelve solo `4` columnas (`InvoicesModel.php:276-282`). En `.58` el rango anual excedió `600 s`. | Con `HYT00`, `SqlServerOperationException` sin reintento (`core/SqlServerConnectionExecutor.php:182-191`) → DLQ → `markJobFailed(epoch)` libera el NIT; no se crean auditorías parciales. | `S2` | `Mitigado` | Mitigación: `DEC-12` (timeout explícito + inecuación `4·T + 36 < min(lockTtl, reclaimIdle)` validada al arranque de `BatchRequestedWorker`). Detección: `batch_generation_sql_ms` y `batch_requested` en `audit.dlq` (Sección 19). | Ejecución medida post-despliegue de `schedule-daily-batches.php --limit=30000` para NIT `2624` en ventana anual confirmando `batch_generation_sql_ms < 300000` y pico RSS `< 256 MiB`. |
| `SUP-02` | El mantenimiento durante despliegue en producción (`nodo1`) permite una ventana breve de pausa y parada ordenada (`pause` → espera cron → `stop -t 30 php worker-*`) antes de actualizar cualquier contenedor (`extractors` inclusive). | `[CONFIRMADO]` `deploy-production.yml:540-578` opera en un único host `nodo1`. | Corrupción de `job:*:state` por coexistencia de escritores v1/v2. | `S2` | `Mitigado` | Mitigación: barrera antes del upgrade de `extractors` (Secciones 14 y 20). Rollback de datos restringido por `DEC-11` (forward-fix only). | Cutover sin coexistencia: `0` errores en `migrate-batch-v2`, `repair-jobs` y `reconcile`. |
| `SUP-03` | SDD-BATCH-000 está desplegado y estable (`0` OOM, heartbeat `< 30.000 ms`, `tick_duration_ms` p99 `<= 6.000 ms` según `CA-F1-06`) durante al menos `24 h` antes de iniciar esta Fase 2. | `[CONFIRMADO]` Decisión de faseo (`R-PHASE`). | La migración de Fase 2 correría con el mantenimiento en bucle OOM, sin heartbeat y sin `gc()` acotado. | `S1` | `Abierto` hasta cierre de Fase 1 | Precondición explícita en Sección 14 (Prerequisitos) y Sección 20. | Señales de Fase 1 verdes `24 h` consecutivas. |
| `SUP-05` | `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` acota también una consulta cuyo socket TCP con `db2` quedó muerto en silencio (`P-F2-01`), y no solo una consulta lenta con el servidor vivo. | `[INFERIDO]` El timeout es del lado cliente (ODBC `SQL_ATTR_QUERY_TIMEOUT`): al vencer, el driver envía una cancelación y espera su confirmación; con el socket muerto esa confirmación puede no llegar. El incidente no distingue si el bloqueo fue de red o del servidor. | Con `DEC-12` desplegado, un worker podría seguir bloqueado hasta que el kernel o el driver detecten el socket caído (keepalive), y `R-HOL` volvería con su duración original. | `S2` | `Mitigado` | Detección: `batch_generation_sql_ms` y ausencia de `Executed SQL audit batch candidates` con `audit.batch.inbox` pendiente (Sección 19); mitigación operativa: `docker compose restart worker-batch`. Si se confirma el fallo, abrir hotfix de keepalive TCP para `worker-batch` (fuera de alcance). | En QA, con aprobación del operador y sin tocar datos: lanzar una consulta de congelación con `T = 60` y cortar la red del contenedor (`docker network disconnect`) a mitad; se cierra si `execute()` termina en `≤ T + 30 s`. |
| `SUP-04` | La saturación de `AuditDocumentCache` (`85–90,9 %`, `H9`) remite al desbloquear la admisión de `extractors` (Fase 1) sin cambiar umbrales `90/70`. | `[CONFIRMADO en fuente externa]` (`P-F2-07`, `172.16.0.3`, `2026-11-19`): la saturación (`71,4–85,9 %`, `4.428` pausas) ocurre incluso con `extraction.batch` en `queued = 0` por ráfagas en `POST /audit/single` sobre `AuditDocumentCache`/`AuditDocumentPrefetchGate` globales con solo `2` `worker-extraction-vip` (`1` `worker-semantic-vip`) y `location = /audit` inefectivo en Nginx. | Con tráfico sostenido en `/audit/single`, los `worker-downloader` vuelven a pausarse en `CLOSED`/`HYSTERESIS` dejando `documents_batch` detenido tras desplegar Fase 1 y Fase 2. | `S2` | `Refutado en producción — diferido a Fase 3 (SDD-BATCH-002)` | Mitigación operativa temporal sin código: escalar en conjunto `worker-extraction-vip` (`2→10`), `worker-semantic-vip` (`1→4`) y `worker-semantic-batch` (`6→12`, tope `50 < 64` inflight Gemini; nota: `docker-compose.yml:359` ya fija `12` desde el commit `2d1a467`, por lo que esa parte ya está aplicada). `SDD-BATCH-002` `DEC-F3-08` **sustituye** estos valores VIP (`10`/`4`) por los defaults permanentes `6`/`3` y conserva `12` en semantic-batch. Cierre estructural diferido a **Fase 3 (`SDD-BATCH-002`)** (cuota por carril en caché/gate, rate limit Nginx efectivo en `/audit/single` y rebalanceo de réplicas). | Aprobación e implementación de **SDD-BATCH-002 (Fase 3)**. |

### 0.11 Clasificación de Completitud Inicial

**`Nivel A — Implementable, condicionado a SUP-03`** (`[CONFIRMADO]`). Todos los archivos del perímetro en `0.1` tienen `Verificado por Lectura = Sí` (o son `NEW`), `0.3` tiene corrección para cada regresión identificada, `0.5` cubre todos los entornos, `0.7` y `0.8` no tienen faltantes abiertos y `0.10` contiene supuestos `S2` `Mitigado` más `SUP-03` (`S1`), cuya única condición de cierre es el despliegue estable de SDD-BATCH-000. La implementación puede escribirse y probarse en paralelo; **el despliegue queda bloqueado hasta cerrar `SUP-03`**. Nivel revalidado tras incorporar las correcciones de la corrida `w7k` (Registro de revisión verificada, filas `W-F2-*`/`W-F1-*`); los scripts SQL de verificación no pudieron ejecutarse en `w7k` (sandbox sin TCP) y la base empírica sigue siendo `E19`/`E21`.

---

## FASE 1 — Especificación

### 1. Objetivo

- **Alcance de fase:** los puntos (5) y (6) siguientes se resuelven en SDD-BATCH-000; esta Fase 2 resuelve (1)–(4) y añade vencimiento de jobs sin progreso (`R-STALE`).
- **Problema actual:** `[CONFIRMADO]` (código) / `[CONFIRMADO en fuente externa]` (volúmenes medidos en local/QA) El pipeline batch de AudFact sufre degradación severa y bloqueos estructurales bajo volumen (`NIT 2624`, lotes de `3.000–30.000` facturas): (1) re-ejecuta la consulta SQL completa `#DISP..#Sopo` en cada página de `500` y ante cada reserva colisionada (`H1`, `H2`); (2) permite concurrencia destructiva entre workers tras expirar `lock_ttl` / `XAUTOCLAIM` (`1.800 s`), corrompiendo `total`, sellando prematuramente e incrementando `done > total` (`H3`); (3) inicia jobs horarios solapados del mismo NIT sin TTL en `batch:idem:*` y bloquea por `DisId` en lugar de `DisDetNro` omitiendo `~2,7 %` de dispensas hermanas (`H4`); (4) reescribe `~2,3 MB` de JSON completo en `job:{id}:state` por cada transición (`H5`); (5) ejecuta outbox, admisión `extractors` (atascada tras eventos previos en `audit.documents.batch`) y `gc()` secuencialmente en un único proceso (`H6–H9`); y (6) agota los `512 MiB` de PHP dentro de `gc()` cada `19–42 s`, dejando `audit:execution:heartbeat = NULL` y provocando rechazo `HTTP 503` global (`H11`).
- **Causa raíz:** `[CONFIRMADO]` Ausencia de materialización inicial de candidatas por job; llave de reserva mal alineada con `AudDispEst.FacNro` (`DisId` vs `DisDetNro`); ausencia de fencing epoch y de índice de jobs activos por NIT; almacenamiento monolítico de `audits` dentro de `job:{id}:state`; bucle único de mantenimiento sin presupuesto de memoria/tiempo que decodifica estados completos de auditoría en RAM y lee solo `100` entradas por ciclo en `audit.documents.*`; y umbral de heartbeat (`10 s`) desalineado del TTL operativo (`30 s`).
- **Resultado esperado:** Ejecución determinista end-to-end donde cada job consulta SQL Server una sola vez al inicio con timeout explícito (incluyendo todo el día `dateTo`), reserva de forma idempotente por `DisDetNro`, rechaza jobs activos solapados del mismo NIT/rango, mantiene contabilidad inviolable `done + failed <= total` bajo fencing epoch, confirma continuaciones sin carreras, opera sobre Hash `O(1)` por auditoría, y nunca deja un NIT bloqueado más de `AUDIT_JOB_STALE_AFTER_SECONDS` (`6 h`) tras el último progreso de su job activo. `gc()` (heredado de SDD-BATCH-000) recolecta las claves v2 dentro de su presupuesto.

### 2. Alcance

#### Incluido

1. Corrección del predicado superior de fechas SQL en `InvoicesModel::buildOptimizedBatchSql()` (`< DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`), soporte de límite hasta `50.000` y correlación opcional `?string $jobId` en `getInvoicesForAuditBatch()`.
2. Materialización única de candidatas por job (`job:{jobId}:candidates`) en Chunk 1 (o desde `cursor` residual si proviene de un job v1 en curso migrado) con reanudación determinista por `candidate_offset` y sin re-consultas SQL posteriores.
3. Cambio de llave de reserva idempotente a `audit:reservation:disdetnro:{disDetNro}` (`1:1` con `AudDispEst.FacNro`), actualizando adquisición, liberación (`releaseAuditReservationFromAudit`) y recolección GC.
4. Desacoplamiento del estado del job en Redis: `job:{jobId}:state` (`string`, metadatos compactos), `job:{jobId}:audits` (`hash` `O(1)` por `auditId`) y `job:{jobId}:by_dispensa` (`hash` de identidad determinista y despacho por `dis_det_nro`), manteniendo compatibilidad de lectura en `GET /audit/jobs`, `GET /audit/jobs/{jobId}` y `FairAuditTaskQueue::ADMIT`.
5. Identidad determinista UUIDv4 `(jobId, disDetNro)` vía `AuditEvent::deterministicUuidV4()`, publicación recuperable ante caídas antes/después de registro y transición recuperable de checkpoint + continuación (`last_committed_chunk` + `last_continuation_dispatched_chunk` + `last_cursor`), junto con diferimiento seguro sin `XACK` (`JobGenerationBusyException`) ante contención `JobLockClaimStatus::BUSY`.
6. Fencing monotónico (`generation_epoch`) y resultado tipado (`JobLockClaimStatus` / `JobLockClaimResult`) en `claimJobGenerationFencedLock()`, `renewJobGenerationLock()`, `storeFrozenCandidates()`, `registerAuditInJob()`, `markAuditsDispatched()`, `commitChunkCheckpoint()`, `sealJob()`, `publishEmptyBatch()` y `markJobFailed()`, más `markContinuationDispatched()` **sin epoch** (monotónico por `chunkIndex`, `R-CONT`) (incluyendo cierre vacío multichunk y protección contra DLQ obsoleto en `AuditEventConsumer`).
7. Política anti-solapamiento de jobs activos por `(fac_nit_sec, date_from, date_to)` mediante `jobs:active:nit:{nit}` y `initJobExclusive()` (`HTTP 409` en `POST /audit/async`; omisión en `bin/schedule-daily-batches.php`) y enmascaramiento NIT + sanitización en `Core\Logger`. (El TTL obligatorio de `batch:idem:*` lo entrega SDD-BATCH-000.)
8. Extensión de `AuditExecutionMaintenance::gc()` (heredado de SDD-BATCH-000) a claves v2: patrón `audit:reservation:disdetnro:*`, `HSCAN` reanudable de `job:{id}:audits` para referencias, borrado atómico de claves v2 del job; comandos CLI `migrate-batch-v2`, `rollback-batch-v2` (forward-fix only, `DEC-11`), `repair-jobs` y `repair-jobs --force-stale`; secuencia de cutover en `.github/workflows/deploy-production.yml` y sincronización en `AGENTS.md` + GitHub Environments.
9. Vencimiento de jobs activos sin progreso (`R-STALE`, `DEC-10`): campo `last_progress_at`, `REAP_STALE_JOB_LUA` invocado desde `gc()` y por CLI, umbral `AUDIT_JOB_STALE_AFTER_SECONDS = 21600`.
10. Timeout explícito de la consulta de congelación (`R-SQLTO`, `DEC-12`).
11. Actualización y ampliación de pruebas unitarias e integración Redis real cubriendo `H1–H5`, `H10` (en lo que depende de generación) y `R-SEAL`, `R-STALE`, `R-CONT`, `R-SQLTO`, `R-ROLL`.

#### Excluido

- Modificación de esquema DDL, índices o reescritura estructural de tablas temporales (`#DISP`, `#CRUZE`, `#ADJ`, `#Sopo`) en SQL Server.
- Renombrar físicamente las columnas históricas `AudDispEst.FacNro` o `AudDispEst.FacSec` en SQL Server.
- Separar `audit.documents.batch` en múltiples streams independientes por tipo de evento (descartado frente a lectura filtrada incremental sin migración de stream offsets).
- Cambios en límites, aislamiento por carril o diseño de `AuditDocumentCache` (`3 GiB` / `4096`, `AuditDocumentCache.php:29-30`) o `AuditDocumentPrefetchGate` (`90/70 %`), rate limiting de `POST /audit/single` en Nginx ni escalado permanente de réplicas `worker-extraction-vip`/`worker-semantic-*`; `SUP-04` quedó **refutado en producción** (`P-F2-07`) y esas mejoras estructurales se difieren a **Fase 3 (`SDD-BATCH-002`)** para no acoplarlas al cutover v2.
- `gc()` incremental, heartbeat, deadline de `tick()`, outbox en ráfaga, intake con `XACK` por lotes y TTL obligatorio de `batch:idem:*` (entregados por SDD-BATCH-000).
- Mejora del rendimiento de salida del pipeline (`H10`, `~180` auditorías/h). Consecuencia aceptada: bajo `INV-03`, un job grande mantiene el NIT bloqueado mientras progrese (p. ej. `30.000` auditorías ≈ `7 días`); se recomienda operar el cron con `CRON_LIMIT` acorde al rendimiento observado.
- Reingeniería del UI Next.js (`frontend/`), que ya muestra `409` y consume el contrato existente de `GET /audit/jobs*`.

### 3. Non Goals

- Implementar paginación SQL infinita sin límite máximo (`> 50.000` candidatas en un solo job); el límite duro explícito será `50.000`.
- Aplicar políticas de TTL automáticas sobre estados activos (`audit:*:state`, `job:*:state`, `job:*:audits`, `audit:reservation:disdetnro:*`); la durabilidad v2 exige ausencia de TTL hasta recolección terminal verificada en `gc()`.
- Alterar el comportamiento de prioridad/enrutamiento de `POST /audit/single`, salvo mantener compatibilidad inocua al liberar reserva inexistente en `AuditPersistenceWorker`.

### 4. Estado Actual

`[CONFIRMADO]` El flujo actual presenta los siguientes acoplamientos defectuosos:

```text
Cron / POST /audit/async
  ├─ claimIdempotencyKey() -> SET NX sin EX (batch:idem:* queda TTL = -1; cron cambia clave cada hora)
  └─ initJob() -> job:{id}:state JSON monolítico con mapa audits inline (~2,3 MB con 27k audits)
       ▼
BatchRequestedWorker (XAUTOCLAIM idle = 1.800s; env lock = 300/900s)
  └─ loop chunk: InvoicesModel::getInvoicesForAuditBatch(cursor)
       ├─ Ejecuta #DISP..#Sopo completo cada 500 filas y por cada skipped_locked
       ├─ Filtro d.DisFecSol <= :dateToD (riesgo de exclusión si existieran horas > 00:00:00)
       ├─ Reserva por audit:reservation:disid:{DisId} (descarta 2,7% Dispensa hermanas)
       └─ Si vence lock (900s) + XAUTOCLAIM (1800s): 2 workers mutan sin epoch, pisan total, sellan antes de tiempo
            ▼
AuditExecutionMaintenance::tick() (1 réplica, 512M PHP, 512M Docker)
  ├─ outbox->flush(100) una vez por tick -> cola de 30.732–33.076 eventos
  ├─ intake() XREADGROUP COUNT <=100 por etapa -> atascado tras document_registered (extractors lag >10k)
  ├─ gc() caches full JSON getAudit() en RAM ($audits[$id]) -> Fatal Error 512M cada 19–42s
  └─ heartbeat escrito solo al final -> permanece NULL -> AuditAdmissionGuard devuelve 503 (>10000ms)
```

### 5. Estado Objetivo

`[CONFIRMADO]` Arquitectura estabilizada con invariantes atómicos en Redis:

```text
Cron / POST /audit/async
  ├─ claimIdempotencyKey() -> SET NX EX max(ttl, AUDIT_JOB_TTL) (entregado por SDD-BATCH-000)
  └─ initJobExclusive() [Lua atómico]:
       ├─ Verifica jobs:active:nit:{nit} -> si [date_from,date_to] se solapa con job no terminal -> rechaza (409 API / skip cron)
       └─ Crea job:{id}:state (~600 B, last_progress_at=now), registra jobs:index + jobs:active:nit:{nit}, telemetry jobs_queued+1
            ▼
BatchRequestedWorker -> AuditBatchOrchestrator
  ├─ claimJobGenerationFencedLock(chunk_index) -> JobLockClaimResult(status: Enum, epoch)  [Lua: epoch+1 solo al adquirir;
  │    │                                            promueve last_continuation_dispatched_chunk = c-1 si c-1 está comprometido]
  │    ├─ BUSY -> lanza JobGenerationBusyException (sin XACK y sin contar intento DLQ)
  │    ├─ TERMINAL -> respuesta temprana sin consultar SQL (XACK)
  │    └─ MISSING -> excepción 503
  ├─ Si !candidates_frozen:
  │    └─ renew lock -> 1 sola consulta SQL TOP(min(limit,50000)) con < DATEADD(day,1,CONVERT(datetime,:dateToD,23))
  │       y SQLSRV_ATTR_QUERY_TIMEOUT = AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS -> renew lock
  │       (desde cursor SQL si proviene de job v1 en curso) -> storeFrozenCandidates() -> LIST job:{id}:candidates
  ├─ Chunks 1..N leen LRANGE job:{id}:candidates [offset..offset+chunkSize-1] (0 SQL posterior)
  │    ├─ Hash job:{id}:by_dispensa + UUIDv4 determinista (jobId:disDetNro) recuperan pre/post-publicación
  │    ├─ Reserva por audit:reservation:disdetnro:{DisDetNro} (1:1 con AudDispEst.FacNro)
  │    ├─ REGISTER_AUDIT_IN_JOB_LUA [valida epoch y !sealed]: HSET audits + by_dispensa; total = HLEN(audits)
  │    ├─ Publish eventos deterministas -> MARK_AUDITS_DISPATCHED_LUA (dispatched=true)
  │    ├─ Intermedio: COMMIT_CHUNK_CHECKPOINT_LUA [valida epoch] -> (lock liberado) Worker publica continuación
  │    │              -> MARK_CONTINUATION_DISPATCHED_LUA [sin epoch; monotónico por chunkIndex] -> XACK
  │    └─ Final (incluso total==0 en multichunk): SEAL_JOB_LUA [valida epoch] -> DEL candidates; cierra si done+failed>=total
  └─ Workers downstream -> MARK_AUDIT_COMPLETED_IN_JOB_LUA (3 KEYS; no muta jobs terminales) -> HSET O(1); cierra si sealed y done+failed>=total
       ▼
AuditExecutionMaintenance::tick() (contrato de SDD-BATCH-000: deadline 2.500 ms, gc acotado, heartbeat EX 30)
  └─ gc() [delta Fase 2]: patrón disdetnro, HSCAN reanudable de job:{id}:audits,
       y para jobs no terminales con now - last_progress_at >= AUDIT_JOB_STALE_AFTER_SECONDS -> REAP_STALE_JOB_LUA
```

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| `DEC-01` | Reservar idempotencia exclusivamente por `audit:reservation:disdetnro:{disDetNro}` (`1:1` con `AudDispEst.FacNro`). | Mantener reserva por `DisId` o clave compuesta `DisId:DisDetNro`. | `[CONFIRMADO]` Resuelve indicación directa del usuario sobre `[Discolnet].[dbo].[AudDispEst]` y elimina el descarte de `27/1.000` filas hermanas verificadas en `E21`, manteniéndose 1:1 con `WHERE [FacNro] = @FacNro` en `AuditResultPersistenceModel.php:180`. |
| `DEC-02` | Separar `job:{id}:state` (string JSON compacto `~600 B` sin `audits`), `job:{id}:audits` (Hash `O(1)` por `auditId`) y `job:{id}:by_dispensa` (Hash de identidad/despacho por `dis_det_nro`). | Cambiar `job:{id}:state` de `string` a `hash` o mantener JSON monolítico, o fiar recuperación únicamente de la reserva liberada tras completar. | `[CONFIRMADO]` Mantener `job:{id}:state` como Redis `string` conserva intacto `FairAuditTaskQueue::ADMIT` (`KEYS[15]` `TYPE == 'string'` y `PERSIST`) y `LIST_JOBS_LUA`, traslada mutaciones a Hash `O(1)`, y `job:{id}:by_dispensa` garantiza unicidad `(jobId, disDetNro)` aun si auditorías previas ya completaron y liberaron su reserva. |
| `DEC-03` | Materializar candidatas al inicio en Redis List `job:{id}:candidates` tras una sola ejecución SQL en Chunk 1 (`TOP <= 50000`, o desde `cursor` SQL en jobs v1 en curso migrados). | Paginación dinámica por chunk en SQL o vista materializada SQL persistente. | `[CONFIRMADO]` Elimina las `N` re-ejecuciones completas `#DISP..#Sopo` (`H1`, `H2`), cumple la decisión funcional de congelación al arranque sin alterar el esquema DDL de SQL Server y maneja sin duplicados ni re-escaneos la reanudación de jobs v1 en vuelo mediante `cursor` inicial + `job:{id}:by_dispensa`. |
| `DEC-04` | Usar fencing monotónico (`generation_epoch`, incrementado **solo** al adquirir en `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA`) validado en los Lua que mutan generación (congelar, registrar, despachar, checkpoint, sellar, fallar) + renovación del lock + resultado tipado `JobLockClaimStatus`/`JobLockClaimResult` y diferimiento sin `XACK` ante `BUSY`. **Excepción deliberada (`R-CONT`):** `markContinuationDispatched()` NO valida epoch; es monotónico por `chunkIndex` y exige `last_committed_chunk >= chunkIndex`; además el claim de un chunk `c > 1` promueve `last_continuation_dispatched_chunk = max(…, c-1)`. | Validar epoch en `markContinuationDispatched()`; mantener el lock durante la publicación de la continuación (el consumidor de `k+1` recibiría `BUSY` y esperaría `30 min` de reclaim por chunk); marcar antes de publicar (pérdida de continuación ante caída). | `[CONFIRMADO]` El lock se libera antes de publicar (`AuditBatchOrchestrator.php:181-184`; `BatchRequestedWorker.php:141-153`) y el consumidor de `k+1` incrementa el epoch en milisegundos: validar epoch en la confirmación producía `409` en el flujo normal, continuaciones duplicadas (sin dedupe en `AuditEventPublisher.php:179`) y fallo de jobs sanos al 3er reintento. Que la continuación de `k` esté publicada es un hecho independiente del propietario actual del lock; `commitChunkCheckpoint(k)` ya está protegido por epoch. |
| `DEC-05` | Rechazar jobs solapados por `(fac_nit_sec, date_from, date_to)` atómicamente en `initJobExclusive()` vía Hash multi-entrada `jobs:active:nit:{nit}` (`HTTP 409` API / skip cron). | Permitir jobs paralelos del mismo cliente/rango o deduplicar por clave horaria fija del cron. | `[CONFIRMADO]` Impone la decisión funcional del usuario (`H4`), elimina el solapamiento futuro y permite que eventuales jobs solapados preexistentes a v2 cierren ordenadamente antes de desbloquear el NIT. |
| `DEC-06` | Extender el `gc()` incremental de SDD-BATCH-000 (rotación `pattern_index`, `pending_keys`, presupuesto `500 ms` / `500` ítems / `256 MiB`, lectura solo-`status`) con: patrón `audit:reservation:disdetnro:*`, `HSCAN` reanudable (`hscan_job_id`/`hscan_cursor`) de `job:{id}:audits` para comprobar referencias, borrado atómico de claves v2 del job y rama de `REAP_STALE_JOB_LUA` para jobs no terminales. | Redefinir `gc()` en esta fase; decodificar `job:{id}:audits` completo en PHP. | `[CONFIRMADO]` Reutiliza el contrato ya desplegado (`R-PHASE`); el `HSCAN` por rebanadas evita cargar en RAM jobs de hasta `50.000` auditorías. |
| `DEC-07` | Intake con `XACK` por lotes (`R-INTAKE`). | — | **Entregado por SDD-BATCH-000.** |
| `DEC-08` | Heartbeat unificado `30.000 ms` y deadline global de `tick()` (`R-TICK`). | — | **Entregado por SDD-BATCH-000.** |
| `DEC-09` | Filtro superior SQL: `d.DisFecSol >= CONVERT(datetime, :dateFromD, 23) AND d.DisFecSol < DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`. | Mantener `<= :dateToD` o usar `CAST(d.DisFecSol AS DATE) <= :dateToD`. | `[CONFIRMADO]` Cumple el requisito del usuario de cubrir todo el día `dateTo` sin envolver la columna `d.DisFecSol` en función (preservando SARGability) y sin sensibilidad al `DATEFORMAT` de sesión. |
| `DEC-10` | Vencimiento de jobs activos sin progreso (`R-STALE`): campo `last_progress_at` (ms UTC) actualizado por `INIT_JOB_EXCLUSIVE`, `REGISTER_AUDIT_IN_JOB`, `MARK_AUDIT_COMPLETED_IN_JOB`, `COMMIT_CHUNK_CHECKPOINT`, `SEAL_JOB` y `MIGRATE_JOB_KEY`; si `now - last_progress_at >= AUDIT_JOB_STALE_AFTER_SECONDS` (default `21600` = `6 h`) y no existe `batch:claim:{jobId}`, `REAP_STALE_JOB_LUA` lleva el job a **`failed`** con `stale_reason = 'stale_no_progress'`, **cancela sus auditorías no terminales** (entrada en `job:{id}:audits` **y** `audit:{id}:state` → `failed`, `failed_stage = 'stale_no_progress'`, liberación de su reserva si pertenece al job) y libera `jobs:active:nit:{nit}`. Se ejecuta automáticamente en `gc()` y manualmente con `repair-jobs --force-stale[=jobId]` (umbral `0`, conserva la comprobación de `batch:claim`). | Sin reaper (bloqueo indefinido del NIT); umbral `24 h`; estado `completed_with_errors`; dejar terminar las auditorías vivas; solo CLI manual. | Decisión explícita del usuario (2026-11-18): `6 h` supera el intervalo de `4 h` del cron; `failed` ya está protegido contra reapertura por la guarda de `MARK_AUDIT_COMPLETED_IN_JOB_LUA` (extendida a todo estado terminal); cancelar libera reservas y evita trabajo huérfano. Cota inferior validada: `AUDIT_JOB_STALE_AFTER_SECONDS >= max(AUDIT_BATCH_LOCK_TTL_SECONDS, 3600)`. |
| `DEC-11` | Política de rollback **forward-fix only** (`R-ROLL`): `rollback-batch-v2` solo se ejecuta con `0` jobs no terminales y `0` reservas `disdetnro:*`; si existen, aborta con código `1` sin mutar nada. La imagen previa **solo** se despliega después de un `rollback-batch-v2` exitoso (ningún proceso v1 escribe sobre datos v2). Ante anomalía con batch activo: `ctl pause` (detiene admisiones; el SLA de `10 min` aplica a la pausa, no a la reversión) y corrección hacia adelante. | Rollback que exige drenar hermanas (`~5,5 h`, incompatible con SLA `10 min`); rollback degradado moviendo hermanas a un set auxiliar (pierde progreso, código extra que v1 no entiende). | Decisión explícita del usuario (2026-11-18). `[CONFIRMADO]` v1 libera por `dis_id + token` y solo borra si el token coincide (`BatchJobStore.php:385-390,611-621`); con hermanas convertidas a `disid:*` una auditoría A liberaría la reserva mientras B sigue en curso, permitiendo reprocesos. |
| `DEC-12` | Timeout explícito de la consulta de congelación (`R-SQLTO`): `PDO::SQLSRV_ATTR_QUERY_TIMEOUT = AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` (default `300`) pasado en `prepare()`; `BatchRequestedWorker` valida al arranque `4·T + 36 < min(AUDIT_BATCH_LOCK_TTL_SECONDS, AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS / 1000)` (peor caso de reintentos `08S01`: 4 intentos + `1+5+30 s`) y aborta si no se cumple. `HYT00` no se reintenta: DLQ → `markJobFailed(epoch)`. | Confiar en la renovación del lock durante la consulta (imposible en PHP monohilo) o en `LoginTimeout`. | `[CONFIRMADO]` `core/Database.php:91-109` solo fija `LoginTimeout`; `core/SqlServerConnectionExecutor.php:14-15,182-191` reintenta `08*` y no `HYT00`. Con `T = 300`: `1.236 s < 1.800 s`. |

#### 6.1 Estrategia de Cambio y Excepciones

| Dimensión | Valor |
| --- | --- |
| Estrategia | Refactorización incremental |
| Fila de la compuerta aplicada | **Componente productivo con API, datos persistidos o consumidores activos** (endpoints REST `POST /audit/async`, `GET /audit/jobs*`, esquema Redis activo, cron en host, frontend Next.js y suites PHPUnit vigentes). |
| Candidatas descartadas y desempate | Clean rebuild / Reconstruir descartadas por regla de desempate conservadora: el sistema procesa colas y auditorías activas en producción y sus contratos HTTP externos no cambian estructuralmente. |
| Fuente del alcance MVP | Hallazgos `H1–H11` reportados en `Diagnóstico batch NIT 2624.md` (con verificación estructural independiente contra código real en Secciones `0.1–0.6`), decisiones funcionales confirmadas por el usuario y contratos consolidados de la plataforma. |

**Excepciones registradas:** `Ninguna`. `[CONFIRMADO]` La migración controlada bloquea la coexistencia (`ctl pause` → espera cron → `docker compose stop -t 30 php worker-*` antes del upgrade de `extractors` → `migrate-batch-v2 --clear-claims` → `repair-jobs` → `reconcile` → arranque de nueva imagen → espera `heartbeat` → `ctl resume`). Rollback bajo `DEC-11` (forward-fix only): `rollback-batch-v2` solo se ejecuta con `0` jobs no terminales y `0` reservas `audit:reservation:disdetnro:*`; en cualquier otro caso aborta con código `1` sin mutar claves. No existen adaptadores duales ni pérdida silenciosa de datos.

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| PHP Runtime + `ext-pdo` + `pdo_sqlsrv` | Infraestructura / Runtime | `8.2-fpm` / CLI (`Dockerfile:41`, `composer.json`) | Exige mantener heap PHP bajo límite `512M` en `worker-execution-maintenance` y `worker-batch`. |
| `predis/predis` | Librería PHP | `^2.0` (`composer.json:20`) | Transporta `EVAL`, `SCAN`, `XREADGROUP`, `XAUTOCLAIM` vía `Core\RedisClient`. |
| Redis | Cola / Base de datos KV | `7-alpine` / standalone `noeviction` + AOF `always` | Motor de scripts Lua atómicos, Hash `O(1)`, List `LRANGE`, Streams y persistencia durable. |
| SQL Server | Base de datos relacional | SQL Server 2022 (`169.46.6.53` CU22 / `169.46.6.58` CU25) | Lectura de candidatas por conexión `db2` (`InvoicesModel`); persistencia por `default` (`AuditResultPersistenceModel`). |
| Next.js Frontend | Consumidor HTTP interno | Next.js App Router (`frontend/`) | Consume `POST /audit/async`, `GET /audit/jobs`, `GET /audit/jobs/{id}`, `GET /metrics/async`. |
| GitHub Actions (`self-hosted` LAN) | Infraestructura CI/CD | `ci.yml`, `deploy-production.yml` | Ejecuta linting de variables `.env.example`, validación de servicios Docker y despliegue en `nodo1`. |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Identidad única de persistencia en `AudDispEst` (`FacNro == DisDetNro`, `FacSec == DisId`) | Código SQL (`AuditResultPersistenceModel.php`) + Confirmación explícita del usuario | `app/Models/AuditResultPersistenceModel.php:154-196`; `plans/audit-identity-contract.md:8-27` | Sí (resuelto): `plans/audit-identity-contract.md:20` listaba `DisId` como llave de auditoría mientras `AuditBatchOrchestrator` reservaba por `DisId`. El usuario confirmó `DisDetNro` como llave operativa `1:1` con `FacNro`; se actualiza `plans/audit-identity-contract.md` y la reserva en Redis a `DisDetNro`. |
| Política de retención durable en Redis | Código (`AuditExecutionConfig`, `AuditExecutionMaintenance`) + `sdd-erradicacion-bloqueo-memoria-redis.md` | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:201-208`; `plans/sdd-erradicacion-bloqueo-memoria-redis.md:42` | No — `[CONFIRMADO]` Estados activos y reservas permanecen sin TTL hasta recolección terminal verificada en `gc()`; únicamente `batch:idem:*` y `batch:claim:*` tienen TTL explícito. |
| Umbral máximo de frescura del heartbeat | Código (`AuditExecutionMaintenance::EX 30` vs `AuditAdmissionGuard::10000`) | `AuditExecutionMaintenance.php:182` vs `AuditAdmissionGuard.php:25`, `AuditStateStore.php:139`, `bin/audit-execution-maintenance.php:41` | Sí (resuelto en **SDD-BATCH-000**): `HEARTBEAT_MAX_AGE_MS = 30000`. Esta Fase 2 usa la constante en todas sus esperas (Secciones 14 y 20). |
| Alineación de timeout de lock batch y reclaim | Código (`AuditBatchOrchestrator::300`, `.env.example:149 = 300`, `BatchRequestedWorker::1800000`) | `AuditBatchOrchestrator.php:26`, `.env.example:146-149`, `BatchRequestedWorker.php:27,52` | Sí (resuelto): lock TTL menor que ventana `XAUTOCLAIM`. Se alinea `.env.example` / `deploy-production.yml` a `AUDIT_BATCH_LOCK_TTL_SECONDS=1800`, renovación en puntos discretos (antes/después de la consulta y por chunk; nunca durante `execute()`), fencing `generation_epoch` y timeout de consulta (`DEC-12`). |

#### Procedimiento de Resolución de Conflictos

`[CONFIRMADO]` Todos los conflictos identificados entre código, documentación y configuración han sido detectados, documentados explícitamente en `7.1` y resueltos en las decisiones de la Sección 6 y cambios de la Sección 13 antes de codificar.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| `INV-01`: Contabilidad cerrada e idempotencia por candidata: `total == HLEN(job:{id}:audits)`, `accepted == total`, `done + failed <= total`, máximo `1` `auditId` determinista por `(jobId, disDetNro)`, nunca decrecen ni se reemiten auditorías ya despachadas. | `AuditEvent::deterministicUuidV4()`, Hash `job:{id}:by_dispensa` (`dis_det_nro -> {audit_id, dispatched}`), `REGISTER_AUDIT_IN_JOB_LUA` (`HEXISTS`, `!sealed`, `total = HLEN`), `MARK_AUDITS_DISPATCHED_LUA`, `MARK_AUDIT_COMPLETED_IN_JOB_LUA` (preserva `failed`), `SEAL_JOB_LUA` (incluyendo `actualTotal == 0`). | Pruebas en Redis real (`AuditExecutionMigrationRedisTest`) + caídas simuladas pre/post publicación + `AuditExecutionInspector::snapshot()`. |
| `INV-02`: Cero splits concurrentes ni continuaciones huérfanas: solo el propietario del `generation_epoch` vigente puede congelar, registrar, despachar, comprometer checkpoint o sellar; `BUSY` nunca `XACK`a el chunk ni quema DLQ; cada chunk intermedio se confirma (`XACK`) recién tras publicar su continuación; `last_continuation_dispatched_chunk` es monotónico y nunca supera `last_committed_chunk`; un fallo en DLQ sin epoch adjunto nunca cierra el job. | Resultado tipado `JobLockClaimStatus`; Lua valida `tonumber(job.generation_epoch or 0) == expectedEpoch` en `STORE_FROZEN_CANDIDATES_LUA`, `REGISTER_AUDIT_IN_JOB_LUA`, `MARK_AUDITS_DISPATCHED_LUA`, `COMMIT_CHUNK_CHECKPOINT_LUA`, `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA`, y todos ellos rechazan sin mutar un job `sealed`, `reaping` o terminal aunque el epoch coincida (`W-F2-B3`; el reaper además incrementa `generation_epoch` en su 1.ª rebanada); `MARK_CONTINUATION_DISPATCHED_LUA` valida `last_committed_chunk >= chunkIndex` (sin epoch, `R-CONT`); `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA` promueve la continuación de `c-1`; `AuditEventConsumer` difiere `JobGenerationBusyException` en PEL y solo llama `markJobFailed` con `JobGenerationFailedException::$epoch !== null`. | Prueba real `XAUTOCLAIM` + consumo inmediato de `k+1` por otro worker antes de confirmar `k` + caída entre checkpoint y continuación + contención `BUSY` (`BatchRequestedWorkerTest` y `AuditExecutionMigrationRedisTest`). |
| `INV-03`: Máximo un job activo no terminal por `(fac_nit_sec, rango [date_from, date_to] solapado)`, y ninguno permanece activo más de `AUDIT_JOB_STALE_AFTER_SECONDS` sin progreso. | `INIT_JOB_EXCLUSIVE_LUA` verifica `jobs:active:nit:{nit}` atómicamente antes de crear `job:{id}:state`, leyendo el estado de cada job listado con el prefijo efectivo derivado de `KEYS[1]` (sin él, bajo `REDIS_PREFIX=audfact:` podaría todos los jobs activos, `W-F2-B2`). Limpia entrada en transición terminal (`MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `SEAL_JOB_LUA` incluyendo vacío, `MARK_JOB_FAILED_LUA`, `REAP_STALE_JOB_LUA`, `deleteJob`, `gc()`). Los jobs solapados preexistentes anteriores a v2 coexisten en el Hash `jobId => rango` sin bloquearse entre sí hasta cerrar cada uno, bloqueando nuevas solicitudes solapadas. | Pruebas unitarias en `AuditControllerTest` y prueba de migración/concurrencia Redis. |
| `INV-04`: Reserva idempotente `1:1` con `AudDispEst.FacNro` y reversión no destructiva: llave única por `DisDetNro` (`audit:reservation:disdetnro:{disDetNro}`), sin TTL automático mientras viva la auditoría; ninguna migración/rollback elimina origen sin destino válido ni descarta hermanas. | `BatchJobStore::claimAuditReservation($disDetNro, ...)` usa `SET NX` sin `EX`; `releaseAuditReservationFromAudit()` exige `dis_det_nro` + `reservation_token`; `MIGRATE_RESERVATION_KEY_LUA` aborta ante identidad irresuelta o colisión; `rollbackBatchV2()` exige la precondición `DEC-11` (`0` jobs no terminales y `0` reservas `disdetnro:*`) o aborta con código `1` sin mutar claves. | `RedisTtlConfigTest`, `AuditPersistenceWorkerTest`, `AuditBatchOrchestratorTest`, `AuditExecutionMigrationRedisTest`. |
| `INV-05`: Ninguna clave de idempotencia HTTP/Cron (`batch:idem:*`) persiste indefinidamente con `TTL = -1`. | **Entregado por SDD-BATCH-000** (`SET NX EX max(ttl, AUDIT_JOB_TTL)`); esta fase solo lo preserva. | Suite de SDD-BATCH-000 (`RedisTtlConfigTest`). |
| `INV-06`: Tamaño acotado de `job:{id}:state` (`STRLEN < 2048`; típico `~600 B`) y recolección de claves v2 sin OOM dentro del presupuesto de `gc()` de SDD-BATCH-000. | `job:{id}:state` excluye `audits` y `candidates`; `gc()` recorre `job:{id}:audits` con `HSCAN COUNT 500` reanudable (`hscan_job_id`/`hscan_cursor`/`hscan_sha1`) y `SMEMBERS audit:execution:refs:{auditId}` por lotes `<= 500`, considerando activa solo una referencia sin prefijo `plan:`. | `AuditMemoryCompactionAndGcTest` (delta Fase 2) con job de `50.000` auditorías cortado a mitad de `HSCAN`. |
| `INV-07`: `job:{id}:state` mantiene tipo Redis `string` y ausencia de TTL hasta recolección terminal en `gc()`, preservando el contrato de `FairAuditTaskQueue::ADMIT` (`KEYS[15]`). | `INIT_JOB_EXCLUSIVE_LUA`, `STORE_STATE_LUA`, `SEAL_JOB_LUA` y `MIGRATE_JOB_KEY_LUA` escriben `KEYS[1]` con `SET` sin `EX`. | `AuditExecutionMigrationRedisTest` con `FairAuditTaskQueue::admit()` sobre job migrado y nuevo. |
| `INV-08`: Todo job no terminal tiene `last_progress_at` (o `updated_at` como respaldo); ninguna auditoría tardía ni ningún worker de generación (aun con epoch coincidente) reabre un job terminal; ninguna auditoría de un job terminal queda con `audit:{id}:state` no terminal por cancelación. | Escritura de `last_progress_at` en los Lua de `DEC-10`; `MARK_AUDIT_COMPLETED_IN_JOB_LUA` retorna sin mutar nada si `reaping == true` y no muta `status`, contadores de job ni `jobs:active:nit` si el job ya es terminal (`completed`, `completed_with_errors`, `failed`) o tiene `stale_reason` (solo actualiza la entrada de la auditoría en `job:{id}:audits` si existe); guarda terminal/`reaping`/`sealed` en los Lua de generación (`W-F2-B3`); el reaper y `cancelUndispatchedAudits()` llevan `audit:{id}:state` a `failed` (`W-F2-B4`). | `AuditStaleJobReaperRedisTest`, `BatchJobStoreMetricsTest`. |

### 9. Modelo de Datos

#### SQL Server (`Discolnet` / `DiscolmetsGx2QA`)

`[CONFIRMADO]` Sin cambios de esquema DDL ni tablas/columnas nuevas. Modificación exclusiva en el filtro superior de la consulta generada en `app/Models/InvoicesModel.php:134`:

- **Antes:** `AND d.DisFecSol >= :dateFromD AND d.DisFecSol <= :dateToD`
- **Después:** `AND d.DisFecSol >= CONVERT(datetime, :dateFromD, 23) AND d.DisFecSol < DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`

#### Redis (Claves, Estructuras, Políticas de Retención)

| Clave Redis (sin prefijo) | Tipo | Contenido | Política de Expiración / Limpieza | Estado |
| --- | --- | --- | --- | --- |
| `job:{jobId}:state` | `string` (JSON `~600 B`) | `job_id, execution_version, status, fac_nit_sec, date_from, date_to, limit, sealed, total, accepted, done, failed, skipped_locked, skipped_existing, accumulated_duration_ms, avg_duration_ms, generation_epoch, generation_owner, candidates_frozen, candidates_count, next_candidate_offset, last_committed_chunk, last_continuation_dispatched_chunk, batch_created_published, last_cursor, last_progress_at, stale_reason, reaping, generation_abandoned, created_at, updated_at, completed_at` (SIN `audits`; `STRLEN < 2048`, típico `~600 B`) | Sin TTL automático; recolectada por `gc()` tras `AUDIT_JOB_TTL` post-terminal cuando no existan referencias no-`plan:` en `audit:execution:refs:{auditId}`. | Modificada |
| `job:{jobId}:audits` | `hash` | Campo: `{auditId}` → Valor JSON `{"dis_det_nro":"...","status":"pending\|processing\|completed\|manual_review\|error\|failed","failed_stage":"..."}` | Sin TTL automático; recolectada atómicamente junto con `job:{jobId}:state` por `gc()` o `deleteJob()`. | Nueva |
| `job:{jobId}:by_dispensa` | `hash` | Campo: `{disDetNro}` → Valor JSON `{"audit_id":"<uuid-determinista>","dispatched":false\|true}` | Sin TTL automático; recolectada atómicamente junto con `job:{jobId}:state` por `gc()` o `deleteJob()`. | Nueva |
| `job:{jobId}:candidates` | `list` | Elementos JSON compactos `{"NitSec":2624,"DisId":"...","Dispensa":"...","DisFecSol":"..."}` en orden SQL `ASC`. | Sin TTL automático; eliminada atómicamente (`DEL`) al ejecutar `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA`, `deleteJob()` o como respaldo en `gc()`. | Nueva |
| `jobs:active:nit:{facNitSec}` | `hash` | Campo: `{jobId}` → Valor JSON `{"date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD"}` (solo jobs activos no terminales; soporta múltiples jobs legados coexistentes de migración mientras rechaza solapamientos nuevos). | Eliminada al alcanzar estado terminal (`completed`, `completed_with_errors`, `failed`, incluido `REAP_STALE_JOB_LUA`), `deleteJob()`, `repair-jobs` o auto-podada por `INIT_JOB_EXCLUSIVE_LUA` (entradas sin estado o terminales). | Nueva |
| `audit:reservation:disdetnro:{disDetNro}` | `string` (JSON) | `{job_id, audit_id, dis_det_nro, fac_nit_sec, dis_id, source, token, claimed_at}` | Sin TTL automático; liberada al completar o fallar terminalmente la auditoría o por `gc()` si auditoría es terminal o ausente (`>= 60s`). | Nueva (reemplaza `audit:reservation:disid:*`) |
| `batch:idem:{key}` | `string` | `{jobId}` | `EX max(ttl, AUDIT_JOB_TTL)` (entregado por SDD-BATCH-000). Fase 2 añade eliminación anticipada por `DEL` condicional (`releaseIdempotencyKeyIfMatches`) si aborta por `409`/fallo de creación. | Modificada |
| `batch:claim:{jobId}` | `string` (JSON) | `{"worker_id":"...","epoch":<int>}` | `EX max(60, AUDIT_BATCH_LOCK_TTL_SECONDS)` (default `1800s`), renovado por chunk/freeze, eliminado al finalizar con token/epoch coincidente o limpiado en cutover bajo parada total de workers (`--clear-claims`). | Modificada |
| `audit:execution:gc:cursors` | `hash` | Creada por SDD-BATCH-000. Fase 2 añade campos `hscan_job_id`, `hscan_cursor`, `hscan_sha1` (recolección de job terminal; `sha1` de `job:{id}:state` leído al iniciar el `HSCAN`, persistido para sobrevivir a cortes entre ticks), `reap_job_id`, `reap_cursor` (reaper por rebanadas), `cursor:reservations_legacy`, `legacy_done`, `legacy_pass_done` y `legacy_saw_keys` (`'0'`/`'1'`; los dos últimos guardan el progreso de la pasada legada entre ticks, `X-F2-B3`), y cambia el patrón del índice `5` (`reservations`) a `audit:reservation:disdetnro:*`; mientras `legacy_done = '0'`, una pasada completa (`'0'` → `'0'`) sobre `audit:reservation:disid:*` ocurre **dentro del índice 5** con su propio cursor, antes del patrón nuevo, y al terminarla se continúa siempre con `disdetnro:*` en el mismo índice (Sección 13, `W-F2-I3`). `loadGcState()`/`saveGcState()` se amplían para leer/escribir estos campos. | Sin TTL; actualizado incrementalmente en cada rebanada de `gc()`. | Modificada |
| `audit:execution:heartbeat` (`string`) / `:meta` (`hash`) | `string` / `hash` | Contrato de SDD-BATCH-000 (`EX 30` / `EX 60`). Fase 2 añade al `meta` el campo `jobs_reaped` (`writeHeartbeat` pasa de `10` a `11` campos, compatible con `CA-F1-07` de SDD-BATCH-000, que exige al menos los 10 campos base; `X-F2-I3`). | Sin cambios de TTL. | Modificada (`meta`) |
| `audit:execution:resumed_at_ms` | `string` (ms UTC) | Timestamp UNIX en ms fijado al ejecutar `bin/audit-execution-maintenance.php resume`; usado por `gc()` en `baseline = max(last_progress_at ?? ms(updated_at), resumed_at_ms ?? 0)` para que las ventanas en pausa no consuman el umbral `AUDIT_JOB_STALE_AFTER_SECONDS` (`DEC-10`, `R5-F2-I2`). | Sin TTL; eliminada por `rollbackBatchV2()`. | Nueva |

#### Script de Cambio (`BatchJobStore::migrateBatchV2` / CLI `bin/audit-execution-maintenance.php migrate-batch-v2 [--clear-claims]`)

La migración se ejecuta en PHP + scripts Lua atómicos utilizando `Core\RedisClient` (aplicando automáticamente `REDIS_PREFIX` mediante `$keys` y `scanKeys()`):

```lua
-- Script Lua 1: MIGRATE_JOB_KEY_LUA
-- KEYS[1] = job:{jobId}:state, KEYS[2] = job:{jobId}:audits, KEYS[3] = jobs:active:nit:{nit}, KEYS[4] = job:{jobId}:by_dispensa
-- ARGV[1] = jobId
-- ARGV[2] = '1' si existe batch_requested pendiente/en PEL para este job en audit.batch.inbox (resuelto en PHP vía XPENDING/XRANGE), '0' si no
-- ARGV[3] = now_ms (inicializa last_progress_at: periodo de gracia post-migración)
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local audits = job.audits
local migrated = 0

if type(audits) == 'table' then
    for auditId, entry in pairs(audits) do
        if type(entry) == 'table' then
            local ddn = tostring(entry.dis_det_nro or '')
            local compact = {
                dis_det_nro = ddn,
                status = tostring(entry.status or 'pending')
            }
            if entry.failed_stage and entry.failed_stage ~= '' then
                compact.failed_stage = tostring(entry.failed_stage)
            end
            redis.call('HSET', KEYS[2], tostring(auditId), cjson.encode(compact))
            if ddn ~= '' and redis.call('HEXISTS', KEYS[4], ddn) == 0 then
                redis.call('HSET', KEYS[4], ddn, cjson.encode({audit_id = tostring(auditId), dispatched = true}))
            end
            migrated = migrated + 1
        end
    end
    job.audits = nil
end

local actualTotal = tonumber(redis.call('HLEN', KEYS[2])) or 0
local actualDone = 0
local actualFailed = 0
local cursor = '0'
repeat
    local scanRes = redis.call('HSCAN', KEYS[2], cursor, 'COUNT', 1000)
    cursor = scanRes[1]
    local entries = scanRes[2]
    for i = 1, #entries, 2 do
        local auditId = tostring(entries[i])
        local parsed = cjson.decode(entries[i + 1])
        local st = tostring(parsed.status or '')
        local ddn = tostring(parsed.dis_det_nro or '')
        if ddn ~= '' and redis.call('HEXISTS', KEYS[4], ddn) == 0 then
            redis.call('HSET', KEYS[4], ddn, cjson.encode({audit_id = auditId, dispatched = true}))
        end
        if st == 'failed' then
            actualFailed = actualFailed + 1
        elseif st == 'completed' or st == 'manual_review' or st == 'error' then
            actualDone = actualDone + 1
        end
    end
until cursor == '0'

-- Invariante H3: reconciliar total/done/failed incluso cuando actualTotal == 0 y preservar status == 'failed'
job.execution_version = 2
job.total = actualTotal
job.accepted = actualTotal
job.done = actualDone
job.failed = actualFailed
local processed = actualDone + actualFailed

if job.status == 'failed' then
    job.sealed = true
    if not job.completed_at or job.completed_at == cjson.null or job.completed_at == '' then
        job.completed_at = job.updated_at or ''
    end
elseif (job.sealed == true or job.status == 'completed' or job.status == 'completed_with_errors') and processed >= actualTotal then
    job.sealed = true   -- R-SEAL: todo job cerrado queda sellado, aunque el dato histórico viniera con sealed = false
    job.status = actualFailed > 0 and 'completed_with_errors' or 'completed'
    if not job.completed_at or job.completed_at == cjson.null or job.completed_at == '' then
        job.completed_at = job.updated_at or ''
    end
elseif (job.status == 'completed' or job.status == 'completed_with_errors') and processed < actualTotal then
    -- R-SEAL: en v1 todo job completed* fue sellado (BatchJobStore.php:255-262, cierre único en :558);
    -- su generación terminó. NO reabrir generación: MARK_AUDIT_COMPLETED_IN_JOB_LUA lo cerrará
    -- al terminar las auditorías restantes, o REAP_STALE_JOB_LUA si no progresan.
    job.status = 'processing'
    job.sealed = true
    job.completed_at = cjson.null
else
    if actualTotal > 0 and (job.status == 'pending' or job.status == '' or job.status == cjson.null) then
        job.status = 'queued'
    end
    if job.sealed ~= true and ARGV[2] ~= '1' then
        -- Generación v1 abandonada (sin batch_requested pendiente): nadie llamará SEAL_JOB_LUA.
        -- Se marca para que REAP_STALE_JOB_LUA lo cierre tras el umbral si no progresa.
        job.generation_abandoned = true
    end
end
-- W-F2-M3: solo inicializar; repair-jobs reutiliza este script y no debe reiniciar el reloj del reaper
if (tonumber(job.last_progress_at) or 0) <= 0 then
    job.last_progress_at = tonumber(ARGV[3]) or 0
end

job.generation_epoch = tonumber(job.generation_epoch) or 0
job.candidates_frozen = job.candidates_frozen == true
job.candidates_count = tonumber(job.candidates_count) or 0
job.next_candidate_offset = tonumber(job.next_candidate_offset) or 0
job.last_committed_chunk = tonumber(job.last_committed_chunk) or 0
job.last_continuation_dispatched_chunk = tonumber(job.last_continuation_dispatched_chunk) or 0

if job.status == 'pending' or job.status == 'queued' or job.status == 'processing' then
    redis.call('HSET', KEYS[3], ARGV[1], cjson.encode({
        date_from = tostring(job.date_from or ''),
        date_to = tostring(job.date_to or '')
    }))
else
    redis.call('HDEL', KEYS[3], ARGV[1])
end

redis.call('SET', KEYS[1], cjson.encode(job))
return migrated + 1
```

```lua
-- Script Lua 2: MIGRATE_RESERVATION_KEY_LUA (no destructivo ante identidad irresuelta o colisión)
-- KEYS[1] = audit:reservation:disid:{disId}
-- ARGV[1] = '' (prefijo extraído automáticamente desde KEYS[1])
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local res = cjson.decode(raw)
local disDetNro = tostring(res.dis_det_nro or '')
local auditId = tostring(res.audit_id or '')
local jobId = tostring(res.job_id or '')
local prefix = string.match(KEYS[1], '^(.*)audit:reservation:disid:') or ''

if disDetNro == '' and auditId ~= '' then
    local auditRaw = redis.call('GET', prefix .. 'audit:' .. auditId .. ':state')
    if auditRaw then
        local a = cjson.decode(auditRaw)
        disDetNro = tostring(a.dis_det_nro or '')
    end
end
if disDetNro == '' and jobId ~= '' and auditId ~= '' then
    local jobAuditRaw = redis.call('HGET', prefix .. 'job:' .. jobId .. ':audits', auditId)
    if jobAuditRaw then
        local ja = cjson.decode(jobAuditRaw)
        disDetNro = tostring(ja.dis_det_nro or '')
    end
end
if disDetNro == '' then
    return -1 -- R-ROLL: identidad dis_det_nro irresuelta -> NO borrar clave origen
end

res.dis_det_nro = disDetNro
local targetKey = prefix .. 'audit:reservation:disdetnro:' .. disDetNro
local existingRaw = redis.call('GET', targetKey)
if existingRaw then
    local existing = cjson.decode(existingRaw)
    if tostring(existing.audit_id or '') == auditId and tostring(existing.token or '') == tostring(res.token or '') then
        redis.call('DEL', KEYS[1])
        return 1 -- idempotente (ya migrada)
    end
    return -2 -- colisión con reserva distinta en destino -> NO borrar clave origen
end

redis.call('SET', targetKey, cjson.encode(res))
redis.call('DEL', KEYS[1])
return 1
```

```lua
-- Script Lua 3: MIGRATE_CLAIM_LOCK_LUA
-- KEYS[1] = batch:claim:{jobId}, KEYS[2] = job:{jobId}:state
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
if string.sub(raw, 1, 1) == '{' then
    local decodedOk = pcall(function() return cjson.decode(raw) end)
    if decodedOk then return 0 end
end
local ttlMs = tonumber(redis.call('PTTL', KEYS[1])) or -1
local epoch = 0
local jobRaw = redis.call('GET', KEYS[2])
if jobRaw then
    local job = cjson.decode(jobRaw)
    epoch = tonumber(job.generation_epoch or 0) or 0
end
local nextVal = cjson.encode({worker_id = tostring(raw), epoch = epoch})
if ttlMs > 0 then
    redis.call('SET', KEYS[1], nextVal, 'PX', ttlMs)
else
    redis.call('SET', KEYS[1], nextVal)
end
return 1
```

```lua
-- Script Lua 4: EXPIRE_LEGACY_IDEMPOTENCY_LUA (SOLO REFERENCIA: la implementación vigente es
-- AuditExecutionMaintenance::expireLegacyIdempotency() de SDD-BATCH-000, invocada por la CLI; ver paso 6)
-- KEYS[1] = batch:idem:{key}, ARGV[1] = default_ttl_seconds
local ttl = tonumber(ARGV[1]) or 0
if ttl <= 0 then
    return redis.error_reply('idempotency_ttl_must_be_positive')
end
if redis.call('TTL', KEYS[1]) == -1 then
    redis.call('EXPIRE', KEYS[1], ttl)
    return 1
end
return 0
```

#### Orden de Ejecución (`BatchJobStore::migrateBatchV2(bool $clearClaims = false)`)

1. Precondición operacional obligatoria antes de invocar `migrate-batch-v2`: `ctl pause`, espera de cron en curso, y `docker compose stop -t 30 php worker-*` (incluyendo `worker-extraction-vip` y `worker-extraction-batch`) ANTES de actualizar contenedores, eliminando coexistencia de escritores.
2. Construir en PHP el conjunto de `job_id` con `batch_requested` pendiente en `audit.batch.inbox` (`XPENDING` del grupo + `XRANGE` de entradas no entregadas, decodificando `job_id`). Iterar con `scanKeys('job:*:state', cursor, 100)` ejecutando `MIGRATE_JOB_KEY_LUA` por cada job con `ARGV[2] = '1'|'0'` según ese conjunto y `ARGV[3] = now_ms` (extrae `audits` → `job:{id}:audits`, puebla `job:{id}:by_dispensa` con `dispatched = true`, repara `total/done/failed/status` incluso en `0` entradas preservando `failed`, puebla `jobs:active:nit:{nit}`).
3. Recalcular contadores exactos en `telemetry:async_metrics` (`jobs_queued`, `jobs_active`, `jobs_running`, `jobs_completed`, `jobs_failed`) desde los `job:*:state` migrados (`BatchJobStore::reconcileJobTelemetryMetrics()`).
4. Iterar con `scanKeys('audit:reservation:disid:*', cursor, 500)` ejecutando `MIGRATE_RESERVATION_KEY_LUA`; si algún retorno es `< 0` (`-1` identidad irresuelta o `-2` colisión), abortar inmediatamente con `\RuntimeException('migrate_reservation_failed')` preservando las claves origen intactas.
5. Iterar `scanKeys('batch:claim:*', cursor, 500)`: si `$clearClaims === true` (cutover bajo trabajadores detenidos), ejecutar `DEL` limpio; en caso contrario (p. ej. prueba directa de normalización), convertir con `MIGRATE_CLAIM_LOCK_LUA` preservando `PTTL`.
6. **Fuera de `BatchJobStore` (`W-F1-I4(a)`):** la CLI `migrate-batch-v2` de `bin/audit-execution-maintenance.php`, tras retornar `migrateBatchV2(...)`, invoca `$maintenance->expireLegacyIdempotency()` de SDD-BATCH-000 (mismo método que el comando `idem-expire-legacy`; `$maintenance` ya está instanciado en `L26`) como paso idempotente de respaldo y añade su resultado al JSON de salida (`legacy_idempotency`); debe resultar en `expired = 0`. `BatchJobStore` no depende de `AuditExecutionMaintenance` (evita el ciclo `BatchJobStore → AuditExecutionMaintenance → BatchJobStore`). `EXPIRE_LEGACY_IDEMPOTENCY_LUA` (arriba) se conserva solo como referencia del contrato y **no** se reimplementa en Fase 2.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| `job:{id}:state` con `audits` inline (`~2,3 MB`) | Extraer mapa `audits` a `HSET`, poblar `job:{id}:by_dispensa`, recalcular `total/done/failed/status` (incluso `total=0`; preserva `failed`), inicializar `generation_epoch`/`offset`/`last_continuation_dispatched_chunk`, reescribir `job:{id}:state` sin `audits` (`~600 B`). | `job:{id}:state` + `job:{id}:audits` + `job:{id}:by_dispensa` + `jobs:active:nit:{nit}` | `STRLEN(job:{id}:state) < 2048` y `HLEN(job:{id}:audits) == total` (`AuditExecutionInspector::snapshot()` pasa sin excepción). |
| `audit:reservation:disid:{disId}` | Resolver `dis_det_nro` (`reserva` → `audit:{id}:state` → `job:{id}:audits`); si resuelve sin colisión, crear `audit:reservation:disdetnro:{disDetNro}` y borrar clave vieja; si falta identidad o colisiona, abortar sin borrar. | `audit:reservation:disdetnro:{disDetNro}` | `0` claves `audit:reservation:disid:*` en Redis y `0` retornos `< 0` en migración. |
| `batch:claim:{jobId}` | En cutover con workers parados (`--clear-claims`): eliminar locks inertes (`DEL`); sin `--clear-claims`: convertir string a JSON `{worker_id, epoch}` conservando `PTTL`. | `batch:claim:{jobId}` (ausente tras `--clear-claims` o JSON v2) | Cero cadenas heredadas incompatibles en Redis tras cutover. |
| `batch:idem:*` con `TTL == -1` | Asignar `EXPIRE 14400` vía `AuditExecutionMaintenance::expireLegacyIdempotency()` (SDD-BATCH-000), invocada por la CLI `migrate-batch-v2` (paso 6). | Mismo patrón `batch:idem:*` | `TTL >= 0` en todas las claves `batch:idem:*`. |
| `telemetry:async_metrics` con desfase histórico | Recuento exacto por estado de todos los `job:*:state` vivos en Redis y `HSET` atómico en `telemetry:async_metrics`. | `telemetry:async_metrics` | Suma `jobs_queued + jobs_active + jobs_running + jobs_completed + jobs_failed == número de jobs existentes`. |

**Migración compleja:**

| Dimensión | Valor |
| --- | --- |
| Estrategia | big-bang en ventana controlada (`ctl pause` → espera cron → `stop -t 30 php worker-*` antes del primer upgrade → migración idempotente → arranque imagen nueva → `resume`) |
| Compatibilidad entre versiones | No coexisten escritores v1/v2 en runtime; los mensajes `v1` pendientes en `audit.batch.inbox` son reanudados de forma segura por v2 usando `$chunkState->cursor` + deduplicación contra `job:{id}:by_dispensa`. El rollback de datos solo ocurre en reposo (`DEC-11`), por lo que no quedan mensajes `v2` pendientes para la imagen previa. |
| Duración esperada de locks | `N/A` en SQL; `< 3 segundos` totales en Redis bajo `stop` de `php` y workers |
| Volumen de datos afectados | Variable en producción (muestra histórica de referencia: `2–20` jobs vivos en `24h`, hasta `~70.000` entradas `audits`, `~1.000–30.000` reservas) |
| Estrategia de backfill | Ejecutada en línea por `bin/audit-execution-maintenance.php migrate-batch-v2 --clear-claims` (idempotente y reentrante) |
| Comportamiento durante despliegue parcial | Bloqueado explícitamente por `deploy-production.yml` (`pause` + `stop -t 30 php worker-*` ubicados ANTES de `Upgrade extractors`) |

#### Rollback (`BatchJobStore::rollbackBatchV2` / CLI `bin/audit-execution-maintenance.php rollback-batch-v2`)

**Política (`DEC-11`, forward-fix only):** el rollback de **datos** solo es válido en reposo. Ante una anomalía con batch activo, el procedimiento es `ctl pause` + corrección hacia adelante (Sección 14); no se revierte el esquema.

**Precondición obligatoria (`R-ROLL`):** antes de modificar ninguna clave, `rollbackBatchV2()` verifica:
1. `0` jobs no terminales (`SCAN job:*:state` con `status ∈ {pending, queued, processing}`); si hay alguno → `\RuntimeException('rollback_requires_no_active_jobs')`, código `1`, sin mutar claves.
2. `0` claves `audit:reservation:disdetnro:*`; si hay alguna → `\RuntimeException('rollback_requires_no_active_reservations')`, código `1`, sin mutar claves.

Con la precondición satisfecha no existen hermanas activas, por lo que la reversión de reservas (`ROLLBACK_RESERVATION_KEY_LUA`) solo actúa sobre residuos y conserva su comportamiento no destructivo ante colisión (`-1`/`-2` abortan sin borrar).

```lua
-- ROLLBACK_JOB_KEY_LUA (estrictamente idempotente / reentrante - R-ROLL)
-- KEYS[1] = job:{jobId}:state, KEYS[2] = job:{jobId}:audits, KEYS[3] = job:{jobId}:candidates, KEYS[4] = job:{jobId}:by_dispensa
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
local hasAuditsHash = redis.call('EXISTS', KEYS[2]) == 1
local hasCandidates = redis.call('EXISTS', KEYS[3]) == 1
local hasByDispensa = redis.call('EXISTS', KEYS[4]) == 1
-- v1 ya escribe execution_version = 2 (BatchJobStore.php:70): no basta como indicador de v2.
local isV2State = (job.execution_version == 2 and type(job.audits) ~= 'table')
    or job.generation_epoch ~= nil
    or job.generation_owner ~= nil
    or job.candidates_frozen ~= nil
    or job.candidates_count ~= nil
    or job.next_candidate_offset ~= nil
    or job.last_committed_chunk ~= nil
    or job.last_continuation_dispatched_chunk ~= nil
    or job.batch_created_published ~= nil
    or job.last_cursor ~= nil
    or job.last_progress_at ~= nil
    or job.stale_reason ~= nil
    or job.reaping ~= nil
    or job.generation_abandoned ~= nil
    or job.error_message ~= nil

if not hasAuditsHash and not hasCandidates and not hasByDispensa and not isV2State and type(job.audits) == 'table' then
    return 0 -- Ya revertido a v1 o nunca migrado: no sobreescribir job.audits
end

local audits = type(job.audits) == 'table' and job.audits or {}
if hasAuditsHash then
    audits = {}
    local cursor = '0'
    repeat
        local scanRes = redis.call('HSCAN', KEYS[2], cursor, 'COUNT', 1000)
        cursor = scanRes[1]
        local entries = scanRes[2]
        for i = 1, #entries, 2 do
            audits[entries[i]] = cjson.decode(entries[i + 1])
        end
    until cursor == '0'
end

local emptyAudits = (next(audits) == nil)
job.audits = audits
job.execution_version = nil
job.generation_epoch = nil
job.generation_owner = nil
job.candidates_frozen = nil
job.candidates_count = nil
job.next_candidate_offset = nil
job.last_committed_chunk = nil
job.last_continuation_dispatched_chunk = nil
job.batch_created_published = nil
job.last_cursor = nil
job.last_progress_at = nil
job.stale_reason = nil
job.reaping = nil
job.generation_abandoned = nil
job.error_message = nil

local encoded = cjson.encode(job)
if emptyAudits then
    encoded = string.gsub(encoded, '"audits":%[%]', '"audits":{}', 1)
end
redis.call('SET', KEYS[1], encoded)
redis.call('DEL', KEYS[2], KEYS[3], KEYS[4])
return 1
```

```lua
-- ROLLBACK_RESERVATION_KEY_LUA (atómica, idempotente, cero pérdida ante colisión)
-- KEYS[1] = audit:reservation:disdetnro:{disDetNro}
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local res = cjson.decode(raw)
local disId = tostring(res.dis_id or '')
local prefix = string.match(KEYS[1], '^(.*)audit:reservation:disdetnro:') or ''
if disId == '' then
    local auditId = tostring(res.audit_id or '')
    if auditId ~= '' then
        local auditRaw = redis.call('GET', prefix .. 'audit:' .. auditId .. ':state')
        if auditRaw then
            local a = cjson.decode(auditRaw)
            disId = tostring(a.fac_sec or a.dis_id or '')
        end
    end
end
if disId == '' then
    return -1
end
res.dis_id = disId
local targetKey = prefix .. 'audit:reservation:disid:' .. disId
local existingRaw = redis.call('GET', targetKey)
if existingRaw then
    local existing = cjson.decode(existingRaw)
    if tostring(existing.dis_det_nro or '') == tostring(res.dis_det_nro or '')
       and tostring(existing.token or '') == tostring(res.token or '') then
        redis.call('DEL', KEYS[1])
        return 1
    end
    return -2
end
redis.call('SET', targetKey, cjson.encode(res))
redis.call('DEL', KEYS[1])
return 1
```

```lua
-- ROLLBACK_CLAIM_LOCK_LUA
-- KEYS[1] = batch:claim:{jobId}
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
if string.sub(raw, 1, 1) ~= '{' then return 0 end
local ok, obj = pcall(function() return cjson.decode(raw) end)
if not ok or type(obj) ~= 'table' or not obj.worker_id then return 0 end
local ttlMs = tonumber(redis.call('PTTL', KEYS[1])) or -1
if ttlMs > 0 then
    redis.call('SET', KEYS[1], tostring(obj.worker_id), 'PX', ttlMs)
else
    redis.call('SET', KEYS[1], tostring(obj.worker_id))
end
return 1
```

Tras revertir sin errores jobs, reservas y `batch:claim:*`, `rollbackBatchV2()` ejecuta `DEL` sobre patrones exclusivamente v2 sin consumidor en `v1` (`jobs:active:nit:*`) y elimina los 9 campos de Fase 2 de `audit:execution:gc:cursors` (`hscan_job_id`, `hscan_cursor`, `hscan_sha1`, `reap_job_id`, `reap_cursor`, `cursor:reservations_legacy`, `legacy_done`, `legacy_pass_done`, `legacy_saw_keys`) más la clave `audit:execution:resumed_at_ms` (la clave y `audit:execution:heartbeat:meta` pertenecen a SDD-BATCH-000 y se conservan). La segunda ejecución consecutiva de `rollbackBatchV2()` resulta idempotente (`reverted_jobs = 0`, `reverted_reservations = 0`) preservando el `100 %` de los datos.

---

### 10. Contratos

#### Contrato 1: API REST `POST /audit/async`

| Dimensión | Valor |
| --- | --- |
| Tipo | API REST (`POST /audit/async`) |
| Visibilidad | Público (CORS) |
| Productor | `App\Controllers\AuditController::async()` |
| Consumidores | `frontend/components/audit/audit-batch-console.tsx` (`enqueueAuditBatch`), invocadores CLI/externos |
| Versionado | No |
| Compatibilidad requerida | Ambas (`202` y `409` idempotente sin cambios de campos obligatorios) |
| Enforcement | Validación de entrada `Controller::validate`, `AuditJobSchema` (frontend Zod), tests `AuditControllerTest` |

- **Antes:** Si existe `X-Idempotency-Key` previa retorna `409` con `{"success":true,"message":"Solicitud ya registrada","data":{"job_id":"..."}}`. Si el key es nuevo, llama `initJob()` y retorna `202` `{"success":true,"data":{"job_id":"...","status":"pending"}}`, permitiendo encolar múltiples jobs paralelos para el mismo NIT y rango de fechas.
- **Después:** Preserva la respuesta `409` por `X-Idempotency-Key` repetido y la respuesta `202` exitosa. Incorpora validación atómica en `initJobExclusive()`: si existe otro job activo (`pending|queued|processing`) para el mismo `facNitSec` cuyo rango `[date_from, date_to]` se solape con `[dateFrom, dateTo]` (`a_from <= b_to && a_to >= b_from`), libera la llave `X-Idempotency-Key` recién creada (`releaseIdempotencyKeyIfMatches`) y retorna **HTTP `409`** con payload `Response::error('Ya existe un job activo para el cliente y rango de fechas especificado', 409, ['active_job_id' => $conflictJobId, 'fac_nit_sec' => $facNitSec, 'date_from' => $dateFrom, 'date_to' => $dateTo])`.
  - Campos agregados en respuesta error `409` por solapamiento: `errors.active_job_id`, `errors.fac_nit_sec`, `errors.date_from`, `errors.date_to`.
  - Campos eliminados: ninguno.
  - Compatibilidad: Frontend `client.ts:61-67` trata cualquier `!response.ok` (`409`) lanzando `ApiError(message, 409, payload)` y mostrando toast con `message` claro sin cambios de esquema.

#### Contrato 2: API REST `GET /audit/jobs`, `GET /audit/jobs/{jobId}` y `GET /metrics/async`

| Dimensión | Valor |
| --- | --- |
| Tipo | API REST |
| Visibilidad | Público (CORS) |
| Productor | `AuditController::jobsList()`, `AuditController::jobStatus()`, `ObservabilityController::asyncMetrics()` |
| Consumidores | `frontend/` (`AuditJobsListSchema`, `AuditJobSchema`, métricas de observabilidad) |
| Versionado | No |
| Compatibilidad requerida | Backward (esquema JSON de respuesta íntegro) |
| Enforcement | Schemas Zod en `frontend/lib/schemas/domain.ts`, `AuditControllerTest`, `ObservabilityControllerTest` |

- **Antes / Después:** Sin cambios en el payload JSON ni en códigos HTTP. Internamente `getJob()` reconstruye `audits` desde Hash `job:{id}:audits` y `LIST_JOBS_LUA` lee `job:*:state` sin `audits` inline.

#### Contrato 3: Evento interno `TYPE_BATCH_REQUESTED`

| Dimensión | Valor |
| --- | --- |
| Tipo | Evento (`audit.batch.inbox`) |
| Visibilidad | Interno |
| Productor | `AuditController::async()`, `bin/schedule-daily-batches.php`, `BatchRequestedWorker::publishContinuationEvent()` |
| Consumidores | `BatchRequestedWorker::handleBatchRequested()`, `AuditEventConsumer::finalizeDeadLetterAudit()` |
| Versionado | No (`execution_version = 2`) |
| Compatibilidad requerida | Ambas (`BatchChunkState::fromPayload`) |
| Enforcement | Tests `BatchRequestedWorkerTest`, `AuditBatchOrchestratorTest` |

- **Antes:** `payload.cursor = {date, disId, dispensa}` usado por SQL Server keyset pagination; `candidate_offset` no existe.
- **Después:** `payload.cursor` conserva `{date, disId, dispensa}` y añade clave entera opcional `offset`; `payload.candidate_offset` explícito (`int >= 0`, default `0`). `BatchChunkState::fromPayload()` lee `payload['candidate_offset'] ?? payload['cursor']['offset'] ?? 0`.
  - Campos agregados: `candidate_offset`, `cursor.offset`.
  - Campos eliminados: ninguno.
  - Manejo de contención y recuperación de continuación: cuando `claimJobGenerationFencedLock()` retorna `JobLockClaimStatus::BUSY`, `BatchRequestedWorker` lanza `JobGenerationBusyException`, el cual es interceptado por `AuditEventConsumer::dispatchMessage()` retornando sin llamar `ackMessage()` ni contar contra `max_retries`; al completar un chunk intermedio, `BatchRequestedWorker` solo retorna (habilitando `XACK`) después de publicar la continuación con `event_id` determinista y persistir `markContinuationDispatched($jobId, $chunkIndex)` (sin epoch, monotónico; `R-CONT`). Como el publisher no deduplica por `event_id` (`AuditEventPublisher.php:179`), una continuación republicada en recuperación produce un mensaje duplicado que el consumidor resuelve por la respuesta temprana idempotente (`last_committed_chunk >= k` y `last_continuation_dispatched_chunk >= k`).
  - Manejo DLQ agregado: cuando `TYPE_BATCH_REQUESTED` agota reintentos y entra en `finalizeDeadLetterAudit()`, **solo si** el error es `JobGenerationFailedException` con `epoch !== null` llama `BatchJobStore::markJobFailed($jobId, $error->getMessage(), $error->epoch, $failedChunkIndex)` (respeta fencing; libera `jobs:active:nit:{nit}`, limpia `job:{id}:candidates` y actualiza `telemetry:async_metrics`) y, **solo si `markJobFailed()` devuelve `true`**, `BatchJobStore::cancelUndispatchedAudits($jobId, 'batch_dlq_unpublished')` cancela las auditorías registradas con `dispatched = false` (`W-F2-B4`). Sin epoch (fallo antes de adquirir lock, o no envuelto) registra `Logger::critical('batch_requested_dlq_without_epoch', ...)` y no muta el job; si el job deja de progresar, lo cierra `REAP_STALE_JOB_LUA` (`DEC-10`).

#### Contrato 4: CLI `bin/schedule-daily-batches.php` y `bin/audit-execution-maintenance.php`

| Dimensión | Valor |
| --- | --- |
| Tipo | Batch / CLI Operacional |
| Visibilidad | Interno (Cron + Deploy workflow) |
| Productor | `scripts/setup-production-cron.sh`, `.github/workflows/deploy-production.yml` |
| Consumidores | Operación LAN / CI/CD |
| Versionado | No |
| Compatibilidad requerida | Backward para comandos existentes (`run`, `pause`, `observe`, `resume`, `protocol`, `outbox`, `reconcile`, `gc`, `durability-check`) y `idem-expire-legacy` (añadido por SDD-BATCH-000) |
| Enforcement | Pruebas de integración + `ci.yml` check `audit-execution-maintenance.php run` |

- **Antes:** `bin/audit-execution-maintenance.php` soporta los 9 subcomandos actuales (`L27,82-86`) más `idem-expire-legacy` de SDD-BATCH-000 (10 en total); `schedule-daily-batches.php` falla silenciosamente en evitar paralelos cuando la hora cambia (`date('Ymd-H')`).
- **Después:** `bin/audit-execution-maintenance.php` añade subcomandos `migrate-batch-v2 [--clear-claims]`, `rollback-batch-v2` (aborta con código `1` si hay jobs activos o reservas `disdetnro:*`, `DEC-11`), `repair-jobs` y `repair-jobs --force-stale[=jobId]` (`DEC-10`). `resume` y el log de arranque ya fueron actualizados por SDD-BATCH-000. `migrate-batch-v2` invoca además `AuditExecutionMaintenance::expireLegacyIdempotency()` tras `migrateBatchV2()`. `.github/workflows/deploy-production.yml` añade input `operation` (`deploy` | `rollback`) y `env.OPERATION` (`deploy` en `workflow_run`), ejecutando migración únicamente en `deploy` tras detener `php` y workers antes del upgrade de `extractors`; en `rollback` ejecuta, antes de `Prepare deployment directory`, `pause` + `stop` + `rollback-batch-v2` sobre el compose v2 vigente y **solo si termina con código `0`** revierte la imagen; si aborta, el workflow relanza el stack v2 sin tocar `.env` ni `docker-compose.yml`, conserva `pause` y termina con `exit 1` hasta la corrección hacia adelante (ningún proceso v1 debe escribir sobre datos v2: `AuditPersistenceWorker`/`AuditFailureFinalizer` v1 reescribirían `job:*:state` con `audits` inline). `schedule-daily-batches.php` ejecuta `initJobExclusive()` y, ante conflicto de solapamiento activo, elimina su llave de idempotencia recién reclamada (`releaseIdempotencyKeyIfMatches`), incrementa `$summary['skipped_duplicate']`, loguea `fac_nit_sec_masked` y omite la creación de job nuevo.

---

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| `REQ-01` | Eliminar re-ejecución SQL completa `#DISP..#Sopo` por chunk/página (`H1`) | `InvoicesModel::getInvoicesForAuditBatch()` (`BATCH_MAX_FREEZE_LIMIT=50000`, `?string $jobId`), `BatchJobStore::storeFrozenCandidates()/readFrozenCandidates()`, `AuditBatchOrchestrator` (congelación única en Chunk 1 o desde `cursor` v1 migrado) | `AuditBatchOrchestratorTest`, `BatchRequestedWorkerTest` verificando `invoicesModel->getCalls() === 1` en lotes multi-chunk |
| `REQ-02` | Eliminar re-consultas por `skipped_locked` y alinear reserva `1:1` por `DisDetNro` (`H2`, `H4`, Decisión 1) | `BatchJobStore::auditReservationKey()` (`audit:reservation:disdetnro:{disDetNro}`), `releaseAuditReservationFromAudit()`, `AuditBatchOrchestrator` con `job:{id}:by_dispensa` | `RedisTtlConfigTest`, `AuditPersistenceWorkerTest`, `AuditBatchOrchestratorTest` con `2` `Dispensa` hermanas del mismo `DisId` |
| `REQ-03` | Incluir todo el día `dateTo` en SQL Server (`dateTo` Decision 4) | `InvoicesModel::buildOptimizedBatchSql()` predicado `< DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))` | `InvoicesModelTest` + validación sintáctica SQL Server |
| `REQ-04` | Erradicar split-brain, sobrescritura de `total`, cierre prematuro, `done > total`, pérdida pre/post-publicación, pérdida o falsa carrera de continuación (`H3`, `R-PREPUB`, `R-CONT`, `R-EMPTY`) | `JobLockClaimStatus` + `JobLockClaimResult`, `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA` (Sección 13), `claimJobGenerationFencedLock()`, `JobGenerationBusyException` en `AuditEventConsumer`, `AuditEvent::deterministicUuidV4()`, `job:{id}:by_dispensa` + `MARK_AUDITS_DISPATCHED_LUA`, `COMMIT_CHUNK_CHECKPOINT_LUA` + `MARK_CONTINUATION_DISPATCHED_LUA`, `SEAL_JOB_LUA` (incluyendo `total==0` multichunk), `MARK_JOB_FAILED_LUA` con epoch | `AuditExecutionMigrationRedisTest` (simulación `XAUTOCLAIM` real + lock vencido + caídas pre/post-publicación y pre-continuación), `AuditBatchOrchestratorTest`, `BatchRequestedWorkerTest` |
| `REQ-05` | Rechazar jobs solapados por NIT y rango `[date_from, date_to]` (`H4`, Decisión 2) | `initJobExclusive()` con `jobs:active:nit:{nit}`, `releaseIdempotencyKeyIfMatches()`, `AuditController::async()` `HTTP 409`, `schedule-daily-batches.php` skip con NIT enmascarado | `AuditControllerTest` (`409` solapado / `202` disjunto) |
| `REQ-06` | Eliminar reescritura JSON `~2,3 MB` en `BatchJobStore` (`H5`) | `job:{id}:state` (`string` metadata `~600 B`) + `job:{id}:audits` (`hash` `O(1)`), `getJobSummary()`, `getJob()`, `LIST_JOBS_LUA`, `AuditExecutionInspector::snapshot()` | `BatchJobStoreMetricsTest` (3 `KEYS`), `BatchJobPerformanceTest`, `AuditExecutionMigrationRedisTest` |
| `REQ-07` | Recolectar claves v2 sin OOM dentro del presupuesto de `gc()` heredado (`H11` preservado) | Delta de `AuditExecutionMaintenance::gc()`: patrón `disdetnro`, `HSCAN` reanudable `hscan_job_id`/`hscan_cursor`, borrado atómico de claves v2 | `AuditMemoryCompactionAndGcTest` (job de `50.000` auditorías cortado a mitad de `HSCAN`, `memory_get_usage(true) < 256 MiB`) |
| `REQ-08` | Ningún NIT bloqueado por un job sin progreso más de `AUDIT_JOB_STALE_AFTER_SECONDS` (`R-STALE`, `DEC-10`) | `last_progress_at` en Lua de `DEC-10`, `BatchJobStore::reapStaleJob()` / `REAP_STALE_JOB_LUA`, rama en `gc()`, CLI `repair-jobs --force-stale`, guarda terminal en `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `cancelUndispatchedAudits()` tras DLQ | `AuditStaleJobReaperRedisTest`, `AuditMemoryCompactionAndGcTest` |
| `REQ-09` | Observabilidad segura (`SUP-01`, PII) y timeout de congelación (`R-SQLTO`, `DEC-12`) | `batch_generation_sql_ms`, `PDO::SQLSRV_ATTR_QUERY_TIMEOUT`, validación de inecuación al arranque, `Logger::$sensitiveKeys` + enmascaramiento NIT | `InvoicesModelTest` (atributo en `prepare`), `BatchRequestedWorkerTest` (arranque rechazado si la inecuación falla), `LoggerSanitizationTest` |
| `REQ-10` | Migración segura de datos vivos sin reabrir jobs sellados, cutover sin coexistencia y rollback forward-fix (`R-SEAL`, `R-ROLL`, `DEC-11`) | `BatchJobStore::migrateBatchV2()` (`0`-audits, preserva `failed`, conserva `sealed = true`, marca `generation_abandoned`, `by_dispensa`, aborta si reserva irresuelta), `rollbackBatchV2()` (precondición `0` jobs activos y `0` reservas `disdetnro:*`, reentrante), `repairCorruptedJobs()`, `deploy-production.yml` (`operation`, barrera antes de `L543`), `AGENTS.md` + `gh variable set` | `AuditExecutionMigrationRedisTest` (job `completed` con pendientes conserva `sealed` y cierra al completar, generación abandonada, 2x rollback reentrante en reposo, aborto con job activo, `a645fccc`), verificación YAML y variables CI/GitHub |

---

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `FairAuditTaskQueue::ADMIT` | `BatchJobStore::jobKey($jobId)` en `KEYS[15]` | Preserva invariante (`KEYS[15]` sigue siendo `string` sin TTL). | Ninguno. | `app/Services/Audit/Pipeline/FairAuditTaskQueue.php:59,236-258` |
| `AuditPersistenceWorker` y `AuditFailureFinalizer` | `BatchJobStore::releaseAuditReservationFromAudit()` | Dejarán de liberar `disid:*` y liberarán `disdetnro:*` de forma transparente sin cambiar firmas invocadas. | Ninguno en dichos workers. | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php:134`; `AuditFailureFinalizer.php:74` |
| `ObservabilityController::asyncMetrics()` | `telemetry:async_metrics` y `audit:execution:heartbeat` | Sigue leyendo el mismo formato numérico en `heartbeat` y los mismos contadores de jobs. | Ninguno (compatible). | `app/Controllers/ObservabilityController.php:96-170` |
| `AuditController::jobsList()` y `jobStatus()` | `BatchJobStore::listJobs()`, `getJob()` | Mismo contrato JSON de respuesta; evita lectura masiva en `listJobs()` y mantiene soporte en `jobStatus()`. | Ninguno en `jobsList()`/`jobStatus()`; sí en `async()`. | `app/Controllers/AuditController.php:481-590` |
| `AuditFlowController::stream()` | `$redis->get("job:{$id}:state")` | Key `job:{id}:state` mantiene existencia y estado JSON. | Ninguno. | `app/Controllers/AuditFlowController.php:30` |
| `worker-execution-maintenance` en `docker-compose.yml` | `ci.yml` validando nombre de servicio y comando `run` | Conserva exactamente el nombre del servicio, memoria `512M` y comando `php bin/audit-execution-maintenance.php run`. | Ninguno en `docker-compose.yml` ni `ci.yml`. | `docker-compose.yml:327-339`; `.github/workflows/ci.yml:61-90` |
| Frontend `AuditBatchConsole` (`enqueueAuditBatch`) | `POST /audit/async` | Recibirá `HTTP 409` también ante solapamiento activo; `ApiError` ya extrae `payload.message` sin marcar `409` como reintentable (`isRetryable = false`). | Ninguno en frontend. | `frontend/components/audit/audit-batch-console.tsx:74-104`; `frontend/lib/api/errors.ts:13-40` |

---

### 13. Cambios por Archivo

#### [MODIFY] `app/Models/InvoicesModel.php`

- **Clases afectadas:** `App\Models\InvoicesModel`
- **Métodos / símbolos:** constante `BATCH_MAX_FREEZE_LIMIT`, `buildOptimizedBatchSql()` (línea `134`), `getInvoicesForAuditBatch()` (`L257-343`)

**Antes** (`L134` y `L264`, `L333`):
```php
                AND d.DisFecSol >= :dateFromD AND d.DisFecSol <= :dateToD AND dd.DisTip <> 'A' AND k.KarUnicp > 0 AND ISNULL(dd.DisAplForRec, 'N') = 'N' AND d.DisNitSec = :facNitSec
```
```php
    public function getInvoicesForAuditBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        ?array $cursor = null
    ): array {
        $limit = min(max($limit, 1), 1000);
```

**Después:** añadir parámetro opcional retrocompatible `?string $jobId = null` para correlación directa del log SQL sin cambiar llamadores existentes:
```php
    private const BATCH_MAX_FREEZE_LIMIT = 50000;
```
```php
                AND d.DisFecSol >= CONVERT(datetime, :dateFromD, 23) AND d.DisFecSol < DATEADD(day, 1, CONVERT(datetime, :dateToD, 23)) AND dd.DisTip <> 'A' AND k.KarUnicp > 0 AND ISNULL(dd.DisAplForRec, 'N') = 'N' AND d.DisNitSec = :facNitSec
```
```php
    public function getInvoicesForAuditBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        ?array $cursor = null,
        ?string $jobId = null,
        ?int $queryTimeoutSeconds = null
    ): array {
        $limit = min(max($limit, 1), self::BATCH_MAX_FREEZE_LIMIT);
        // L309 (hoy prepare($sql) sin opciones) — R-SQLTO / DEC-12:
        $options = $queryTimeoutSeconds !== null && $queryTimeoutSeconds > 0
            ? [\PDO::SQLSRV_ATTR_QUERY_TIMEOUT => $queryTimeoutSeconds]
            : [];
        $stmt = $connection->prepare($sql, $options);
        // ... execute() / fetchAll() sin cambios; HYT00 se propaga como SqlServerOperationException (sin reintento)
        // ... Logger::info('Executed SQL audit batch candidates', ['job_id' => $jobId, 'facNitSec' => $maskedNitSec, ...])
```
`$queryTimeoutSeconds` solo lo pasa `AuditBatchOrchestrator` en la congelación (`AuditExecutionConfig::freezeQueryTimeoutSeconds()`); `searchInvoices`/`countInvoices` no cambian.

---

#### [NEW] `app/Services/Audit/Pipeline/JobLockClaimStatus.php`, `JobLockClaimResult.php`, `JobGenerationBusyException.php` & [MODIFY] `AuditEvent.php`, `AuditTaskEffects.php`

- **`JobLockClaimStatus.php`**:
```php
namespace App\Services\Audit\Pipeline;

enum JobLockClaimStatus: string
{
    case ACQUIRED = 'acquired';
    case BUSY = 'busy';
    case TERMINAL = 'terminal';
    case MISSING = 'missing';
}
```
- **`JobLockClaimResult.php`**:
```php
namespace App\Services\Audit\Pipeline;

final readonly class JobLockClaimResult
{
    public function __construct(
        public JobLockClaimStatus $status,
        public ?int $epoch = null,
    ) {
        if ($this->status === JobLockClaimStatus::ACQUIRED && ($this->epoch === null || $this->epoch < 1)) {
            throw new \InvalidArgumentException('Lock ACQUIRED requiere epoch >= 1');
        }
    }
}
```
- **`JobGenerationBusyException.php`**:
```php
namespace App\Services\Audit\Pipeline;

use RuntimeException;

final class JobGenerationBusyException extends RuntimeException
{
    public function __construct(public readonly string $jobId, public readonly int $chunkIndex)
    {
        parent::__construct("Lock de generación ocupado para job {$jobId} en chunk {$chunkIndex}", 423);
    }
}
```
- **`AuditEvent.php` (`L225`) & `AuditTaskEffects.php` (`L10-15`)**: agregar `AuditEvent::deterministicUuidV4(string $identity): string` generando UUID v4 determinista vía SHA-256 (`-4...-a...`, validado por `AuditEvent::isUuidV4()`), y hacer que `AuditTaskEffects::uuid($identity)` delegue directamente en `AuditEvent::deterministicUuidV4($identity)` para evitar lógica duplicada.
- **`AuditEvent::create()` (`AuditEvent.php:92-101`)**: hoy fija `eventId: self::uuidV4()` sin parámetro. Añadir como último parámetro opcional `?string $eventId = null` y usar `eventId: $eventId ?? self::uuidV4()` (retrocompatible con todos los llamadores posicionales); lo usan los `event_id` deterministas de `audit_created`, `batch_created` y la continuación `batch-cont` (`W-F2-M6`).

---

#### [MODIFY] `app/Services/Audit/Pipeline/BatchJobStore.php`

- **Clases afectadas:** `App\Services\Audit\Pipeline\BatchJobStore`
- **Métodos retirados explícitamente (`R-CLEAN`):** se eliminan `initJob()` y `claimJobGenerationLock()` tras actualizar todos sus llamadores reales (`AuditController`, `schedule-daily-batches.php`, `AuditBatchOrchestrator`) y dobles de prueba (`AuditControllerTest`, `AuditBatchOrchestratorTest`, `BatchRequestedWorkerTest`). Se conserva `patchJob()` (sin mapa `audits` inline) por compatibilidad verificada con `RedisTtlConfigTest`.
- **Métodos nuevos/actualizados:** `initJobExclusive()`, `listJobs`/`LIST_JOBS_LUA`, `getJob()`, `getJobSummary()`, `registerAuditInJob`/`REGISTER_AUDIT_IN_JOB_LUA`, `markAuditsDispatched()`, `getBatchDispensaEntry()`, `commitChunkCheckpoint()`, `markContinuationDispatched()`, `sealJob()`, `publishEmptyBatch()`, `markAuditCompletedInJob`/`MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `markJobFailed()`, `cancelUndispatchedAudits()`, `deleteJob()`, `releaseIdempotencyKeyIfMatches()`, `claimAuditReservation()`, `getAuditReservation()`, `releaseAuditReservation()`, `releaseAuditReservationFromAudit()`, `auditReservationKey()`, `claimJobGenerationFencedLock()`, `renewJobGenerationLock()`, `releaseJobGenerationLock()`, `storeFrozenCandidates()`, `readFrozenCandidates()`, `jobAuditsKey()`, `jobByDispensaKey()`, `jobCandidatesKey()`, `activeNitJobsKey()`, `reapStaleJob()`, `repairJobStates()`, `migrateBatchV2()`, `rollbackBatchV2()`, `reconcileJobTelemetryMetrics()`.
- **Heredado (no se modifica aquí):** `claimIdempotencyKey()` ya existe en HEAD (`BatchJobStore.php:305`) y lo modifica `SDD-BATCH-000` (TTL obligatorio, `INV-05`).

**Contratos deterministas:**
0. **Reglas transversales de los Lua (obligatorias):**
   - **Prefijo (`W-F2-B2`):** `RedisClient::eval()` solo antepone `REDIS_PREFIX` a los elementos de `$keys` (`core/RedisClient.php:430`). Todo script que construya claves dentro de Lua (`INIT_JOB_EXCLUSIVE_LUA`, `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA`, `DELETE_JOB_LUA`, `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `REAP_STALE_JOB_LUA`, `CANCEL_UNDISPATCHED_AUDITS_LUA`) declara al inicio `local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''` y usa exclusivamente `prefix .. 'job:' .. id .. ':state'`, `prefix .. 'jobs:active:nit:' .. tostring(job.fac_nit_sec)`, `prefix .. 'audit:' .. auditId .. ':state'` y `prefix .. 'audit:reservation:disdetnro:' .. ddn`. Ninguna clave dinámica se construye sin `prefix`.
   - **Guarda de generación (`W-F2-B3`):** `STORE_FROZEN_CANDIDATES_LUA`, `REGISTER_AUDIT_IN_JOB_LUA`, `MARK_AUDITS_DISPATCHED_LUA`, `COMMIT_CHUNK_CHECKPOINT_LUA`, `SEAL_JOB_LUA` y `PUBLISH_EMPTY_BATCH_LUA` ejecutan, **antes** de comprobar el epoch y de cualquier escritura: `local st = tostring(job.status or ''); if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then return -2 end`. El método PHP traduce `-2` a `false` (o a `RuntimeException('job_closed_or_reaping', 409)` en `registerAuditInJob`), y el orquestador lo trata como fallo de fencing (`409`). Así un worker que despierta con el mismo epoch tras un reaper no reabre ni muta el job (`INV-08`); además `REAP_STALE_JOB_LUA` incrementa `generation_epoch` en su 1.ª rebanada (defensa en profundidad).
1. **Claves:**
   - `jobAuditsKey(string $jobId): string` → `"job:{$jobId}:audits"`
   - `jobByDispensaKey(string $jobId): string` → `"job:{$jobId}:by_dispensa"`
   - `jobCandidatesKey(string $jobId): string` → `"job:{$jobId}:candidates"`
   - `activeNitJobsKey(int $facNitSec): string` → `"jobs:active:nit:{$facNitSec}"`
   - `auditReservationKey(string $disDetNro): string` → `"audit:reservation:disdetnro:{$disDetNro}"`
2. **`initJobExclusive(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): array`:**
   Retorna `['created' => bool, 'conflict_job_id' => ?string, 'reason' => ?string]`. Ejecuta `INIT_JOB_EXCLUSIVE_LUA` sobre `[self::jobKey($jobId), 'jobs:index', self::activeNitJobsKey($facNitSec), self::TELEMETRY_KEY]`: deriva `prefix` desde `KEYS[1]` (regla 0) y, para cada `activeJobId` de `KEYS[3]`, lee `GET prefix .. 'job:' .. activeJobId .. ':state'`; poda solo las entradas cuyo estado no existe o es terminal y verifica solapamiento `a_from <= dateTo and a_to >= dateFrom` contra las restantes; si no hay conflicto, crea `job:{id}:state` sin `'audits'`, indexa en `jobs:index` y `jobs:active:nit:{nit}`, e incrementa `jobs_queued`.
3. **`getJobSummary(string $jobId): ?array` y `getJob(string $jobId): ?array`:**
   - `getJobSummary()` lee únicamente `job:{id}:state` (`~600 B`).
   - `getJob()` ejecuta `GET_JOB_LUA` sobre `[self::jobKey($jobId), self::jobAuditsKey($jobId)]`, reconstruyendo `audits` desde el Hash sin alterar contrato JSON externo.
4. **`releaseIdempotencyKeyIfMatches(string $key, string $jobId): bool`** (nuevo): borra `KEYS[1]` solo si `GET KEYS[1] == ARGV[1]`. `claimIdempotencyKey()` con TTL obligatorio `max($ttl, AUDIT_JOB_TTL)` y rechazo de `$ttl <= 0` es entregado por SDD-BATCH-000 (`INV-05`) y no se modifica aquí.
5. **Reservas por `DisDetNro` (`claimAuditReservation`, `getAuditReservation`, `releaseAuditReservation`, `releaseAuditReservationFromAudit`):**
   - `claimAuditReservation(string $disDetNro, string $ownerToken, array $reservation): bool` escribe `$reservation['dis_det_nro'] = $disDetNro`, `$reservation['token'] = $ownerToken`, `$reservation['claimed_at'] ??= gmdate('Y-m-d\TH:i:s\Z')` en `audit:reservation:disdetnro:{disDetNro}` (`SET NX`, sin TTL).
   - `releaseAuditReservationFromAudit()` lee `$disDetNro = trim((string) ($audit['dis_det_nro'] ?? ''))` y `$token = trim((string) ($audit['reservation_token'] ?? ''))`.
6. **Fencing tipado (`claimJobGenerationFencedLock`, `renewJobGenerationLock`, `releaseJobGenerationLock`):**
   - `claimJobGenerationFencedLock(string $jobId, string $workerId, int $chunkIndex, int $ttlSeconds = 1800): JobLockClaimResult`: ejecuta `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA` sobre `[self::jobGenerationLockKey($jobId), self::jobKey($jobId)]` con `ARGV = [$workerId, $ttlSeconds, $chunkIndex]`; el resultado es `[status, epoch]`; decodifica `JobLockClaimStatus::tryFrom($r[0])` (lanza `\RuntimeException('invalid_generation_lock_claim_response')` ante valor desconocido) y retorna `new JobLockClaimResult($status, (int) $r[1])`. Script completo (`R-CONT`; antes no especificado):
     ```lua
     -- CLAIM_JOB_GENERATION_FENCED_LOCK_LUA
     -- KEYS[1]=batch:claim:{jobId}  KEYS[2]=job:{jobId}:state  (RedisClient::eval antepone REDIS_PREFIX)
     -- ARGV[1]=worker_id  ARGV[2]=ttl_seconds  ARGV[3]=chunk_index
     local jobRaw = redis.call('GET', KEYS[2])
     if not jobRaw then return {'missing', 0} end
     local ok, job = pcall(cjson.decode, jobRaw)
     if not ok or type(job) ~= 'table' then return redis.error_reply('invalid_job_state') end
     local cur = tonumber(job.generation_epoch or 0) or 0
     local st = tostring(job.status or '')
     if job.sealed == true or job.reaping == true or st == 'completed' or st == 'completed_with_errors' or st == 'failed' then
         return {'terminal', cur}
     end
     if redis.call('EXISTS', KEYS[1]) == 1 then return {'busy', cur} end
     local epoch = cur + 1                      -- único punto donde crece el epoch
     job.generation_epoch = epoch
     job.generation_owner = ARGV[1]
     local c = tonumber(ARGV[3]) or 1
     -- Recibir el chunk c prueba que la continuación de c-1 ya fue publicada (R-CONT)
     if c > 1 and (tonumber(job.last_committed_chunk or 0) or 0) >= c - 1
        and (tonumber(job.last_continuation_dispatched_chunk or 0) or 0) < c - 1 then
         job.last_continuation_dispatched_chunk = c - 1
     end
     redis.call('SET', KEYS[2], cjson.encode(job))          -- sin EX (INV-07)
     redis.call('SET', KEYS[1], cjson.encode({worker_id = ARGV[1], epoch = epoch}),
         'EX', math.max(60, tonumber(ARGV[2]) or 1800))
     return {'acquired', epoch}
     ```
   - `renewJobGenerationLock(string $jobId, string $workerId, int $epoch, int $ttlSeconds = 1800): bool`: verifica `{worker_id, epoch}` coincidente antes de aplicar `EXPIRE`.
   - `releaseJobGenerationLock(string $jobId, string $workerId, ?int $epoch = null): bool`: elimina `batch:claim:{jobId}` solo si coincide `worker_id` (y `epoch` si no es `null`).
7. **Congelación e idempotencia por candidata (`storeFrozenCandidates`, `readFrozenCandidates`, `getBatchDispensaEntry`, `registerAuditInJob`, `markAuditsDispatched`, `commitChunkCheckpoint`, `markContinuationDispatched`):**
   - `storeFrozenCandidates(string $jobId, array $candidates, int $expectedEpoch): bool`: Lua aplica la guarda de generación (regla 0; no recrea `job:{id}:candidates` sobre un job `reaping`/terminal), verifica `generation_epoch == expectedEpoch`, limpia si no estaba `candidates_frozen`, inserta tuplas en lotes `RPUSH` de `500` con `unpack`, fija `candidates_frozen = true`, `candidates_count`, `next_candidate_offset = 0`. Retorna `false` si falta job o epoch obsoleto.
   - `readFrozenCandidates(string $jobId, int $offset, int $count): array`: ejecuta `LRANGE job:{jobId}:candidates offset (offset + count - 1)`.
   - `getBatchDispensaEntry(string $jobId, string $disDetNro): ?array`: ejecuta `HGET job:{jobId}:by_dispensa disDetNro` (`['audit_id' => string, 'dispatched' => bool]`).
   - `registerAuditInJob(string $jobId, string $auditId, string $disDetNro, ?string $disId = null, ?string $reservationToken = null, ?int $expectedEpoch = null): bool`: `KEYS = [jobKey($jobId), self::TELEMETRY_KEY, self::jobAuditsKey($jobId), self::jobByDispensaKey($jobId)]`. Lua valida `expectedEpoch` (`-1` → `throw RuntimeException('generation_fence_stale', 409)`), `!sealed`, `reaping ~= true` y estado no terminal (`-2` → `throw RuntimeException('job_closed_or_reaping', 409)`), registra `HSET job:{id}:by_dispensa` (`NX`, `dispatched: false`), registra `HSET job:{id}:audits` (`NX`), ejecuta `HINCRBY telemetry audits_in_flight 1` **solo si** ese `HSETNX` devolvió `1` (la recuperación `R-PREPUB` re-invoca `registerAuditInJob()` y no debe contar dos veces; hoy el incremento es incondicional, `BatchJobStore.php:502`), fija `total = HLEN(KEYS[3])`, `accepted = total`, transiciona `pending -> queued` y actualiza `telemetry:async_metrics`.
   - `markAuditsDispatched(string $jobId, array $pairs, bool $batchCreatedPublished, int $expectedEpoch): bool`: aplica la guarda de generación (regla 0), valida `expectedEpoch`, actualiza `dispatched = true` en `job:{id}:by_dispensa` para las parejas publicadas y fija `batch_created_published = true` en `job:{id}:state`.
   - `commitChunkCheckpoint(string $jobId, int $chunkIndex, int $nextOffset, int $skippedLockedDelta, int $skippedExistingDelta, int $expectedEpoch, ?array $lastCursor = null): bool`: aplica la guarda de generación (regla 0; un job cerrado nunca devuelve `true` y no habilita continuaciones), luego `if chunkIdx <= prevCommitted then return 1 end` (no duplica deltas en recuperación), actualiza `next_candidate_offset`, `last_cursor` y `last_committed_chunk`, rechazando con `-1` si `expectedEpoch` no coincide.
   - `markContinuationDispatched(string $jobId, int $chunkIndex): bool` — **sin `expectedEpoch`** (`R-CONT`, excepción deliberada de `DEC-04`). Retorna `true` en `1`, `false` en `0` (job inexistente) y lanza `\RuntimeException('continuation_before_checkpoint')` en `-1` (violación de invariante → reintento/DLQ):
     ```lua
     -- MARK_CONTINUATION_DISPATCHED_LUA  KEYS[1]=job:{jobId}:state  ARGV[1]=chunk_index
     local raw = redis.call('GET', KEYS[1]); if not raw then return 0 end
     local job = cjson.decode(raw); local k = tonumber(ARGV[1]) or 0
     if k < 1 then return 0 end
     if job.sealed == true or job.reaping == true or job.status == 'failed' then return 1 end
     if (tonumber(job.last_committed_chunk or 0) or 0) < k then return -1 end
     if (tonumber(job.last_continuation_dispatched_chunk or 0) or 0) >= k then return 1 end
     job.last_continuation_dispatched_chunk = k
     redis.call('SET', KEYS[1], cjson.encode(job))   -- sin EX (INV-07)
     return 1
     ```
8. **Cierre y finalización (`sealJob`, `publishEmptyBatch`, `markAuditCompletedInJob`, `markJobFailed`, `deleteJob`):**
   - `sealJob(string $jobId, int $skippedLockedDelta = 0, int $skippedExistingDelta = 0, ?int $expectedEpoch = null, ?int $chunkIndex = null, ?int $nextOffset = null): bool`: `SEAL_JOB_LUA` deriva `prefix` (regla 0), aplica la guarda de generación (regla 0: retorna `-2` sin mutar si `reaping`, ya sellado o terminal; el cierre de un job `reaping` es exclusivo del reaper), valida `expectedEpoch`, aplica deltas de `skipped_*` solo si aún no sellado, borra `job:{id}:candidates`, fija `sealed = true`, `total = HLEN(KEYS[3])`, `accepted = total`, y **cierra siempre que `done + failed >= total` incluso si `total == 0` en jobs multichunk (`R-EMPTY`)**, limpiando `prefix .. 'jobs:active:nit:' .. job.fac_nit_sec` y actualizando `telemetry:async_metrics`.
   - `publishEmptyBatch(string $jobId, int $skippedLocked, int $skippedExisting, ?int $expectedEpoch = null): bool`: **solo para Chunk 1 sin candidatas** (`$snapshot === []`). `PUBLISH_EMPTY_BATCH_LUA` deriva `prefix` y aplica la guarda de generación (regla 0: nunca convierte un job `failed`/`reaping` en `completed`), valida `expectedEpoch`, cierra `completed`, `sealed = true`, `total = 0`, fija `completed_at`/`updated_at` ISO-8601 (`ARGV`) y `last_progress_at`, borra `candidates`, limpia `jobs:active:nit:{nit}` y transiciona `telemetry:async_metrics` (`jobs_queued - 1`, `jobs_completed + 1`) (`R-EMPTY`).
   - **Formato de fechas (todas las escrituras de cierre):** `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA` y `REAP_STALE_JOB_LUA` reciben `nowIso` por `ARGV` y escriben `completed_at`/`updated_at` en ISO-8601; `last_progress_at` es el único campo en milisegundos.
   - `MARK_AUDIT_COMPLETED_IN_JOB_LUA`: utiliza **exactamente las 3 claves `[self::jobKey($jobId), self::TELEMETRY_KEY, self::jobAuditsKey($jobId)]`** (`R-CLEAN`), resuelve `prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''` para `HDEL jobs:active:nit:{nit}` al cerrar, actualiza `last_progress_at` (`DEC-10`), y **no muta `status`, contadores del job, telemetría de jobs ni `jobs:active:nit` si el job ya es terminal o tiene `stale_reason`** (`INV-08`; extiende la guarda previa `oldJobStatus ~= 'failed'` para que una auditoría tardía no reabra un job cerrado por DLQ o por `REAP_STALE_JOB_LUA`); en ese caso solo actualiza la entrada de la auditoría en `job:{id}:audits` si su estado actual no es terminal. Conserva intactas las sentencias validadas por `BatchJobStoreMetricsTest`:
     ```lua
     local oldJobStatus = tostring(job['status'] or 'pending')
     local jobTerminal = oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors'
         or oldJobStatus == 'failed' or job['reaping'] == true
         or (job['stale_reason'] ~= nil and job['stale_reason'] ~= cjson.null)
     -- W-F2-I1: durante reaping el reaper es el único que contabiliza las entradas restantes
     -- (evita re-escaneo O(N) en la rebanada final y la carrera done + failed < total)
     if job['reaping'] == true then return 1 end   -- ANTES de tocar KEYS[3] o audits_in_flight
     -- ... HGET/HSET KEYS[3] auditId ...
     -- I7: si la entrada de la auditoría pasa de no terminal a terminal, decrementar audits_in_flight
     --     SIEMPRE (también con jobTerminal ya cerrado, reaping == nil), para no fugar el contador en jobs
     --     cerrados por DLQ o reaper; durante reaping el decremento lo hace el propio reaper (paso 2).
     -- if jobTerminal then return 1 end (sin tocar status, contadores del job, telemetría de jobs ni jobs:active:nit)
     if oldJobStatus ~= 'failed' then
         if sealed and processed >= total then
             if (tonumber(job['failed']) or 0) > 0 then
                 job['status'] = 'completed_with_errors'
             else
                 job['status'] = 'completed'
             end
         else
             job['status'] = 'processing'
         end
     end
     -- ... métricas manteniendo ramas if oldJobStatus == 'queued' or oldJobStatus == 'pending' / elseif newJobStatus == 'completed' or newJobStatus == 'completed_with_errors' ...
     ```
   - `markJobFailed(string $jobId, string $errorMessage, ?int $expectedEpoch = null, int $failedChunkIndex = 0): bool`: en Lua (`MARK_JOB_FAILED_LUA`), retorna `0` sin mutar si ya terminal (`completed`, `completed_with_errors`, `failed`), `sealed == true` o `reaping == true`, rechaza `-1` si `expectedEpoch > 0 && generation_epoch ~= expectedEpoch`, ignora `return 0` si `failedChunkIndex > 0 && last_committed_chunk >= failedChunkIndex && last_continuation_dispatched_chunk >= failedChunkIndex` (`R-EMPTY`), transiciona a `failed`, `sealed = true`, borra `candidates`, limpia `prefix .. 'jobs:active:nit:' .. job.fac_nit_sec` (prefijo según regla 0) y actualiza `telemetry:async_metrics`. `BatchJobStore::markJobFailed()` retorna `false` (**sin lanzar excepción**) cuando el Lua devuelve `-1` o `0`, para que `finalizeDeadLetterAudit()` siempre llegue a `sendToDeadLetter()` y `finishTerminalMessage()` (`AuditEventConsumer.php:636-648`).
   - `cancelUndispatchedAudits(string $jobId, string $failedStage = 'batch_dlq_unpublished'): int` (nuevo, `W-F2-B4`): itera en PHP `CANCEL_UNDISPATCHED_AUDITS_LUA` con `KEYS = [jobKey, jobByDispensaKey, jobAuditsKey, TELEMETRY_KEY]` y `ARGV = [cursor, failedStage, nowIso]` hasta `cursor = '0'` (rebanadas `HSCAN KEYS[2] cursor COUNT 500`). El Lua deriva `prefix` (regla 0), solo actúa si `job.status == 'failed'` (si no, retorna `{'0', 0}` sin mutar) y, por cada entrada `{audit_id, dispatched}` de `by_dispensa` con `dispatched == false`: si `prefix .. 'audit:' .. audit_id .. ':state'` existe, pertenece al job (`job_id`) y no es terminal, lo reescribe con `status = 'failed'`, `failed_stage = ARGV[2]`, `completed_at = updated_at = ARGV[3]` (ISO-8601, `SET` sin `EX`, conservando `documents` y el resto de campos); si la entrada de `KEYS[3]` no es terminal, la marca `failed` con el mismo `failed_stage`, incrementa `job.failed` (acotado a `total - done`) y decrementa `audits_in_flight`; si la rebanada canceló alguna, reescribe `KEYS[1]` con `SET` sin `EX` y `updated_at = ARGV[3]` (`X-F2-I2`); libera `prefix .. 'audit:reservation:disdetnro:' .. ddn` solo si su `job_id` y `audit_id` coinciden. Es idempotente (entradas ya terminales se omiten). Retorna `{nextCursor, cancelled}`; el método devuelve el total cancelado. Una auditoría publicada pero aún no confirmada con `markAuditsDispatched()` también se cancela (misma semántica contable que el reaper; `ADMIT` hace `XACK` sobre estado terminal, `FairAuditTaskQueue.php:240`).
   - `LIST_JOBS_LUA`: cambiar la extracción de prefijo `^(.*:)jobs:index$` por `^(.*)jobs:index$` (`BatchJobStore.php:630`, dentro de `LIST_JOBS_LUA` `L624-685`) para soportar `REDIS_PREFIX=''`, y el valor por defecto `or "audfact:"` por `or ""` (`X-F2-M3`).
   - `deleteJob(string $jobId): bool` (hoy solo `DEL job:{id}:state`, `BatchJobStore.php:232-235`): en un único Lua (`DELETE_JOB_LUA`, `KEYS = [jobKey, jobAuditsKey, jobByDispensaKey, jobCandidatesKey, jobGenerationLockKey, 'jobs:index', TELEMETRY_KEY]`) deriva `prefix` (regla 0), lee `fac_nit_sec`/`status` del propio `job:{id}:state`, descuenta `audits_in_flight` por cada entrada no terminal de `job:{id}:audits` (recorrido `HSCAN COUNT 500` dentro del script; el único llamador con entradas es la compensación de Chunk 1, acotada a `<= chunk_size`), borra `job:{id}:state`, `job:{id}:audits`, `job:{id}:by_dispensa`, `job:{id}:candidates`, `batch:claim:{id}`, `ZREM jobs:index`, `HDEL prefix .. 'jobs:active:nit:' .. nit` y decrementa el contador de `telemetry:async_metrics` según el estado leído, cubriendo las 5 ramas: `pending → jobs_queued`, `queued → jobs_active`, `processing → jobs_running`, `completed`/`completed_with_errors → jobs_completed`, `failed → jobs_failed`.
   - En `markJobFailed`: `$expectedEpoch` deja de ser opcional para el llamador DLQ (`AuditEventConsumer` solo invoca con epoch no nulo, `R-CONT`); `repair-jobs` y la migración no lo usan.
9. **Vencimiento de jobs sin progreso (`reapStaleJob`, `DEC-10`, `R-STALE`):**
   - `reapStaleJob(string $jobId, int $nowMs, int $thresholdMs, ?string $cursor = null, string $reason = 'stale_no_progress'): array` (`['done' => bool, 'cursor' => ?string, 'cancelled_audits' => int]`) ejecuta `REAP_STALE_JOB_LUA` con `KEYS = [jobKey, jobAuditsKey, activeNitJobsKey(nit), TELEMETRY_KEY, jobCandidatesKey, jobGenerationLockKey]` (el NIT se lee de `getJobSummary()` antes de invocar; el Lua revalida `job.fac_nit_sec` contra `KEYS[3]`) y `ARGV = [nowMs, thresholdMs, reason, cursor ?? '0', nowIso, fallbackUpdatedAtMs]`, donde `nowIso = gmdate('Y-m-d\TH:i:s\Z')` y `fallbackUpdatedAtMs` lo calcula PHP desde `job['updated_at']` (ISO-8601 → ms; `0` si no parsea, en cuyo caso el reaper **no** actúa por umbral: el sandbox Lua carece de `os.*` para parsear fechas). El Lua obtiene el prefijo efectivo con `local prefix = string.match(KEYS[1], '^(.*)job:[^:]+:state$') or ''` y construye las claves de reserva como `prefix .. 'audit:reservation:disdetnro:' .. ddn` (`RedisClient::eval` solo prefija `KEYS`, `core/RedisClient.php:430`). Si retorna `done = false`, el llamador persiste `reap_job_id`/`reap_cursor` en `audit:execution:gc:cursors` (desde `gc()`) o itera hasta `done = true` (desde CLI).
   - Semántica del Lua (atómico; para jobs muy grandes se ejecuta por rebanadas `HSCAN COUNT 500` con cursor en `ARGV[4]`, marcando `reaping = true` en `job:{id}:state` desde la primera rebanada para que `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `REGISTER_AUDIT_IN_JOB_LUA` y `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA` lo traten como cerrado; el paso 3 (estado y telemetría del job) se aplica **una sola vez**, en la rebanada final):
     1. **Definición de terminal:** terminal ⇔ `status ∈ {completed, completed_with_errors, failed}`; `sealed` **no** cuenta (los jobs migrados por `R-SEAL` quedan `processing` + `sealed = true` y sí son elegibles). Si `reaping == true` (rebanada de continuación), se omiten las comprobaciones de umbral y `batch:claim` y se continúa desde `ARGV[4]`. En otro caso retorna `0` sin mutar si el job no existe, es terminal o `EXISTS KEYS[6] == 1` (propietario de generación vivo; se comprueba **siempre**, también con `--force-stale`). La marca de progreso solo se evalúa con umbral positivo y normalizando con `tonumber()` (en Lua `0` y `cjson.null` son *truthy*; `cjson.null` abortaría `now - x`) (`W-F2-I2`):
        ```lua
        local nowMs = tonumber(ARGV[1]) or 0
        local threshold = tonumber(ARGV[2]) or 0
        if threshold > 0 then
            local lp = tonumber(job.last_progress_at) or 0
            if lp <= 0 then lp = tonumber(ARGV[6]) or 0 end
            if lp <= 0 or (nowMs - lp) < threshold then return 0 end  -- sin marca conocida: el reaper automático no actúa
        end
        -- threshold == 0 (repair-jobs --force-stale): el operador puede cerrar un job sin marca de progreso válida
        ```
        En la **1.ª rebanada** (`reaping ~= true`) fija `job.reaping = true` e incrementa `job.generation_epoch = (tonumber(job.generation_epoch) or 0) + 1` (`W-F2-B3`: invalida cualquier escritura pendiente con el epoch anterior).
     2. Por cada entrada de `job:{id}:audits` con `status` no terminal: la reescribe con `status = 'failed'`, `failed_stage = 'stale_no_progress'`; incrementa `job.failed` en `1` (acumulado por rebanada y persistido con `reaping = true`); si existe `prefix .. 'audit:' .. auditId .. ':state'` con `job_id` igual y `status` no terminal, lo reescribe con `status = 'failed'`, `failed_stage = 'stale_no_progress'`, `completed_at = ARGV[5]`, `updated_at = ARGV[5]` (`SET` sin `EX`, **conservando `documents` y el resto de campos**: `AuditTaskCommitter.php:97` lee `audit.documents[...]` y con estado terminal no aplica transiciones, `:78,104`; `ADMIT` hace `XACK` y retorna `2`, `FairAuditTaskQueue.php:240`), de modo que `gc()` pueda recolectar `audit:*:state`, `audit:task:v2:*`, `audit:semantic:v2:*` y `gemini:attempt:v2:*` (`AuditExecutionMaintenance.php:231,254,276,296`) y la reserva deje de estar retenida por un estado vivo (`:342-343`) (`W-F2-B4`); si existe `prefix .. 'audit:reservation:disdetnro:' .. dis_det_nro` cuyo `job_id` coincide, la borra (liberación de reserva); decrementa `audits_in_flight`.
     3. En la rebanada final (`HSCAN` devuelve cursor `'0'`) **no** re-escanea el Hash (`W-F2-I1`): como `MARK_AUDIT_COMPLETED_IN_JOB_LUA` no muta nada mientras `reaping == true`, cada entrada no terminal fue contabilizada exactamente una vez por el paso 2; fija `total = HLEN(KEYS[2])`, `accepted = total` y, como salvaguarda, `failed = min(failed, total - done)` (`done + failed == total`, `INV-01`); fija `status = 'failed'`, `sealed = true`, `stale_reason = reason`, `completed_at = ARGV[5]` y `updated_at = ARGV[5]` (**ISO-8601**, mismo formato que el resto de escritores; `gc()` usa `strtotime()` en `AuditExecutionMaintenance.php:317` y `BatchJobStore::calculatePerformance()` usa `DateTimeImmutable` en `:168-179`), `reaping = nil`, `error_message = 'stale_no_progress'`; `HDEL KEYS[3] jobId`; `DEL KEYS[5]`; ajusta telemetría según estado previo (`pending → jobs_queued`, `queued → jobs_active`, `processing → jobs_running`) y `+1 jobs_failed`; escribe `job:{id}:state` con `SET` sin `EX` (`INV-07`).
   - **Alcance de la cancelación:** es contable, de estado de auditoría (`audit:{id}:state` → `failed`) y de reservas. Las tareas ya admitidas en las colas de ejecución no se interrumpen; si terminan, su persistencia SQL es idempotente por `FacNro` (`AuditResultPersistenceModel.php:180`) y su `markAuditCompletedInJob` no altera el job (`INV-08`). Una nueva solicitud del NIT puede volver a reservar esas dispensas.
   - Invocación: desde `gc()` (patrón `job:*:state`, jobs no terminales, dentro del presupuesto) y desde `repair-jobs --force-stale[=jobId]` (`thresholdMs = 0`, mantiene la comprobación de `batch:claim`). Retorna `['reaped_jobs' => int, 'cancelled_audits' => int]` en CLI.

---

#### [MODIFY] `app/Services/Audit/AuditBatchOrchestrator.php`

- **Clases afectadas:** `App\Services\Audit\AuditBatchOrchestrator`
- **Métodos / constantes afectados:** `DEFAULT_LOCK_TTL_SECONDS = 1800`, `MAX_FREEZE_LIMIT = 50000`, `enqueueBatch()`, `resolveIdempotentEarlyResponse()`, `resolveLockedConcurrentResponse()`, `collectChunkCandidates()`, `dispatchChunkEvents()`, `initAuditState()`, `publishEmptyBatch()`, `cleanupAsyncEnqueueState()`, `maskNit()`.

**Flujo determinista paso a paso:**
1. **Validación previa de estado (`resolveIdempotentEarlyResponse`):** lee `getJobSummary($jobId)` (`~600 B`). Si `$job === null` en `$externalJobId`, lanza `RuntimeException(..., 503)`. Si `$job['sealed'] === true`, `($job['reaping'] ?? false) === true`, `status ∈ {completed, completed_with_errors, failed}` o (`last_committed_chunk >= chunkIndex && last_continuation_dispatched_chunk >= chunkIndex`), retorna respuesta temprana (`has_more = false`) sin adquirir lock ni ejecutar SQL. Si `last_committed_chunk >= chunkIndex && last_continuation_dispatched_chunk < chunkIndex` (`!sealed`), **no retorna temprano**: continúa al paso 2 para re-emitir la continuación huérfana bajo lock con epoch vigente (`R-CONT`).
2. **Adquisición de lock y manejo exhaustivo de `JobLockClaimStatus` (`R-EPOCH`, `R-CONT`, `R-EMPTY`):**
   - `$lockResult = $this->jobStore->claimJobGenerationFencedLock($jobId, $workerToken, $chunkState->chunkIndex, $lockTtl);`
   - Toda excepción lanzada tras `ACQUIRED` (incluidas `SqlServerOperationException` por `HYT00` y los `409` de fencing) se re-lanza envuelta como `JobGenerationFailedException($msg, $code, $previous, epoch: $epoch)` para que la DLQ pueda aplicar fencing (`INV-02`).
   - `match ($lockResult->status)`:
     - `JobLockClaimStatus::BUSY` → retorna `$this->resolveLockedConcurrentResponse(...)` incorporando `'locked_busy' => true` (`BatchRequestedWorker` lanza `JobGenerationBusyException` para no `XACK`ear).
     - `JobLockClaimStatus::TERMINAL` → retorna **siempre** una respuesta terminal no nula construida desde `getJobSummary($jobId)` (`has_more = false`, totales autoritativos); `resolveIdempotentEarlyResponse()` nunca devuelve `null` en esta rama (evita `TypeError` sobre `enqueueBatch(): array` cuando el claim responde `terminal` por `reaping == true` con `sealed = false`).
     - `JobLockClaimStatus::MISSING` → lanza `RuntimeException("Job {$jobId} no encontrado al adquirir lock de generación", 503)`.
     - `JobLockClaimStatus::ACQUIRED` → toma `$epoch = (int) $lockResult->epoch` (y `finally` llama `releaseJobGenerationLock($jobId, $workerToken, $epoch)`).
3. **Recuperación de continuación huérfana bajo lock (`R-CONT`):** relee `getJobSummary($jobId)` bajo lock. Si `last_committed_chunk >= chunkIndex && last_continuation_dispatched_chunk < chunkIndex && !$sealed`, retorna directamente respuesta con `'has_more' => true`, `'next_cursor' => $summary['last_cursor']`, `'next_candidate_offset' => (int) ($summary['next_candidate_offset'] ?? 0)`, `'generation_epoch' => $epoch`, y totales autoritativos desde `$summary`.
4. **Congelación SQL única cuando `!candidates_frozen` (`H1`, `H4`):**
   - Determina `$freezeCursor = ($chunkState->chunkIndex > 1 && $chunkState->cursor !== null) ? $chunkState->cursor : null;` (permite migrar y completar jobs `v1` ya en curso sin re-procesar páginas previas).
   - Renueva el lock **antes** de la consulta: `if (!$this->jobStore->renewJobGenerationLock($jobId, $workerToken, $epoch, $lockTtl)) { throw new RuntimeException('generation_fence_lost', 409); }` (`R-SQLTO`: no es posible renovar durante `execute()`).
   - Mide `$sqlStartNs = hrtime(true)`, invoca `$snapshot = $this->invoicesModel->getInvoicesForAuditBatch($facNitSec, $dateFrom, $dateTo, max(1, min($limit, self::MAX_FREEZE_LIMIT) - (int) ($summary['accepted'] ?? 0)), $freezeCursor, $jobId, AuditExecutionConfig::freezeQueryTimeoutSeconds())` (`DEC-12`).
   - Emite `Logger::info('AuditBatchOrchestrator: snapshot SQL batch congelado', ['job_id' => $jobId, 'fac_nit_sec_masked' => self::maskNit($facNitSec), 'date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit, 'candidates_count' => count($snapshot), 'batch_generation_sql_ms' => $sqlMs])` (`R-OBS`). Si `count($snapshot) === self::MAX_FREEZE_LIMIT`, emite además `Logger::warning('batch_freeze_limit_reached', ...)`.
   - Renueva el lock **después** de `fetchAll()`: `if (!$this->jobStore->renewJobGenerationLock($jobId, $workerToken, $epoch, $lockTtl)) { throw new RuntimeException('generation_fence_lost', 409); }`.
   - Si `$snapshot === []`, `(int) ($summary['accepted'] ?? 0) === 0` **y** `$chunkState->chunkIndex === 1`: si `!publishEmptyBatch($jobId, 0, 0, $epoch)` lanzar `RuntimeException(..., 409)` (epoch obsoleto o job cerrado por la guarda de generación); si `true`, retornar `completed`, `total = 0`, `has_more = false`. Si `$snapshot === []` con `chunkIndex > 1` (job v1 migrado congelando desde `cursor`), no llamar `publishEmptyBatch()` (sobrescribiría a `0` los `skipped_*` acumulados): invocar `sealJob($jobId, 0, 0, $epoch, $chunkState->chunkIndex, max($chunkState->candidateOffset, (int) ($summary['next_candidate_offset'] ?? 0)))` (misma fórmula que `$startOffset` del paso 5, que aún no está asignado aquí; `R4-F2-M2`) (si devuelve `false`, `RuntimeException('generation_fence_stale_on_seal', 409)`, `X-F2-I1`) y retornar la respuesta final con totales autoritativos (`W-F2-M4`).
   - Llama `if (!$this->jobStore->storeFrozenCandidates($jobId, $snapshot, $epoch)) { throw new RuntimeException('generation_fence_stale_or_job_missing', 409); }` y libera `$snapshot`.
5. **Recolección idempotente y recuperación pre/post-publicación (`R-PREPUB`):**
   - Calcula `$startOffset = max($chunkState->candidateOffset, (int) ($summary['next_candidate_offset'] ?? 0))` (el offset autoritativo es el comprometido en `job:{id}:state`; el del mensaje es solo una pista).
   - Lee `$slice = $remainingLimit > 0 ? $this->jobStore->readFrozenCandidates($jobId, $startOffset, $sliceSize) : []`.
   - Por cada fila, calcula `$disDetNro`, `$disId` y los UUIDs v4 **deterministas**:
     - `$auditId = AuditEvent::deterministicUuidV4("batch-audit:{$jobId}:{$disDetNro}")`
     - `$reservationToken = AuditEvent::deterministicUuidV4("batch-res:{$jobId}:{$disDetNro}")`
   - Consulta `$entry = $this->jobStore->getBatchDispensaEntry($jobId, $disDetNro)`:
     - Si `$entry !== null && ($entry['dispatched'] ?? false) === true`: omitir sin tocar reservas ni incrementar `skipped_locked` (`continue`).
     - Si `$entry !== null && ($entry['dispatched'] ?? false) === false`: caída previa entre registro y publicación → reutilizar `$entry['audit_id']` y comprobar `$audit = $this->stateStore->getAudit($auditId)`. **Defensa `W-F2-B1`:** si `$audit === null` (estado borrado por una compensación previa o nunca creado), re-asegurar antes de publicar: `claimAuditReservation($disDetNro, $reservationToken, ...)` con el token determinista o, si falla, aceptar `getAuditReservation($disDetNro)` solo si pertenece a `(jobId, auditId)` (reutilizando su `token`); luego `initAuditState()` idempotente. Si la reserva pertenece a otro job/single, **no** publicar: registrar `Logger::warning('batch_prepub_reservation_lost', ['job_id' => $jobId, 'audit_id' => $auditId])` y continuar (la entrada queda `dispatched = false` y la cierra `cancelUndispatchedAudits()`/el reaper). Nunca se publica `audit_created` sin `audit:{id}:state` (evita `audit_state_missing` en `FairAuditTaskQueue.php:238`). Con estado presente, obtener `reservation_token` de `$audit` (o determinista), reconstruir `AuditEvent::TYPE_AUDIT_CREATED` con `event_id` determinista `AuditEvent::deterministicUuidV4("audit-created:{$jobId}:{$auditId}")` y agregarlo a `$pendingPublish`.
     - Si `$entry === null`: intentar `claimAuditReservation($disDetNro, $reservationToken, ...)`:
       - Si falla `claimAuditReservation`: consultar `getAuditReservation($disDetNro)`. Si pertenece a este `jobId` y `audit_id`, reutilizar `$existingRes['token']`, ejecutar `initAuditState` idempotente: si `AuditStateStore::initAudit()` retorna `false` (hoy `SET NX`, `AuditStateStore.php:65-75`), `initAuditState()` lee `$existing = $this->stateStore->getAudit($auditId)` y continúa sin error cuando `($existing['job_id'] ?? null) === $jobId` (aplicando `patchAudit` solo si falta `reservation_token`); solo si pertenece a otro job lanza `503`. `AuditStateStore.php` no cambia; `registerAuditInJob` es `NX` y encolar evento determinista en `$pendingPublish`. Si pertenece a otro job/single, incrementar `$skippedLocked++`.
       - Si triunfa `claimAuditReservation`: registrar reserva local para compensación, ejecutar `initAuditState` (`$epoch`) y encolar evento determinista en `$pendingPublish`.
6. **Publicación causal y confirmación `dispatched = true`:**
   - Si `count($pendingPublish) > 0 && !($summary['batch_created_published'] ?? false)` (sin exigir `chunkIndex === 1`: si el Chunk 1 aceptó `0` auditorías por `skipped_locked`, el primer chunk que acepte emite `batch_created`; el `event_id` determinista y `batch_created_published` evitan duplicados, `W-F2-M4`): publicar `TYPE_BATCH_CREATED` con `event_id = AuditEvent::deterministicUuidV4("batch-created:{$jobId}")`.
   - Publicar cada `TYPE_AUDIT_CREATED` en `$pendingPublish` e invocar `if (!$this->jobStore->markAuditsDispatched($jobId, $pairs, $batchCreatedPublishedNow, $epoch)) { throw new RuntimeException('generation_fence_stale_on_dispatch', 409); }`.
7. **Cierre final (incluso vacío multichunk `R-EMPTY`) o checkpoint intermedio:**
   - Releer `getJobSummary($jobId)`; evaluar `$isFinalChunk = $newOffset >= $candidatesCount || $authoritativeAccepted >= $limit || $slice === []`.
   - Si `$isFinalChunk`: ejecutar **siempre** (también con `$authoritativeAccepted === 0`: `SEAL_JOB_LUA` cierra con `total = 0` y conserva los `skipped_*` acumulados de chunks previos; `publishEmptyBatch()` queda reservado al Chunk 1 sin candidatas del paso 4) `sealJob($jobId, $chunkSkippedLocked, $chunkSkippedExisting, $epoch, $chunkState->chunkIndex, $newOffset)`; si devuelve `false` (fencing, `reaping` o job terminal) lanza `RuntimeException('generation_fence_stale_on_seal', 409)` (`X-F2-I1`).
   - Si `!$isFinalChunk`: ejecutar `commitChunkCheckpoint($jobId, $chunkState->chunkIndex, $newOffset, $chunkSkippedLocked, $chunkSkippedExisting, $epoch, $nextCursor)` (lanza `409` en `false`), renovar lock (`renewJobGenerationLock`, lanza `409` en `false`) y retornar `has_more = true`, `next_cursor` (con `'offset' => $newOffset` añadido cuando `$nextCursor !== null`; `cursorFromInvoice()` solo devuelve `date`/`disId`/`dispensa`, `AuditBatchOrchestrator.php:678-693`), `'next_candidate_offset' => $newOffset`, `'generation_epoch' => $epoch`.
8. **Compensación en `cleanupAsyncEnqueueState()` (`W-F2-B1`; hoy `AuditBatchOrchestrator.php:646-672` borra estados y libera reservas sin distinción):** como `auditId` y `reservationToken` son deterministas por `(jobId, disDetNro)`, un token coincidente **no** prueba propiedad: tras perder el lock, el propietario vigente (`epoch + 1`) reutiliza exactamente la misma auditoría y reserva. La compensación distingue tres casos (evaluados en este orden), y en todos solo actúa cuando `$publishedAnyEvent === false`:
   - **(a) Limpieza total** — solo si `!$externalJobId && $chunkState->chunkIndex === 1` y la excepción **no** es de fencing: `deleteJob($jobId)` (`DELETE_JOB_LUA`), `deleteAudit()` de los `$createdAuditIds` y `releaseAuditReservation($res['dis_det_nro'], $res['token'])` de las `$createdReservations` (job creado y aún no visible para otro worker).
   - **(b) Fencing** — excepción `409` de fencing (`generation_fence_stale*`, `generation_fence_lost`, `job_closed_or_reaping`) o `JobGenerationFailedException` cuyo `getPrevious()` sea uno de ellos: **no** ejecutar `deleteAudit()` ni `releaseAuditReservation()` sobre ninguna fila (el propietario vigente las reutiliza; los residuos sin `audit:{id}:state` los libera `gc()` tras `60 s`, `AuditExecutionMaintenance.php:342-343`).
   - **(c) `$externalJobId === true` sin fencing** (p. ej. `503` en `D5` tras registrar `D1..D4`): **prohibido** `deleteAudit()`/`releaseAuditReservation()` sobre filas ya registradas en `job:{id}:by_dispensa` (la recuperación la hace `R-PREPUB` en el reintento: Step 5 republica con el estado intacto). Solo se compensan filas creadas en este intento **sin** entrada en `by_dispensa` (reserva + `initAuditState()` previos a `registerAuditInJob()`), y solo si, al limpiar, `getBatchDispensaEntry($jobId, $ddn) === null` y el estado/reserva siguen perteneciendo a `(jobId, auditId, token)`. `deleteJob()` nunca se invoca en este caso.
   Para ello el orquestador registra, por fila, si `registerAuditInJob()` llegó a ejecutarse (`$registeredDisDetNros`) y `$createdReservations` pasa a `array<int,array{dis_det_nro:string,token:string,audit_id:string}>`. `$createdAuditIds[] = $auditId` se registra inmediatamente después de un `initAudit()` exitoso, antes de `patchAudit()` y `registerAuditInJob()`, para que la compensación `(a)`/`(c)` lo vea si cualquiera de las dos lanza (`X-F2-M2`).

---

#### [NEW] `app/Services/Audit/Pipeline/JobGenerationFailedException.php`

- Envuelve excepciones ocurridas tras adquirir lock con fencing (`?int $epoch`) para que `AuditEventConsumer::finalizeDeadLetterAudit()` propague `$expectedEpoch` a `BatchJobStore::markJobFailed()`:
```php
namespace App\Services\Audit\Pipeline;

use RuntimeException;

final class JobGenerationFailedException extends RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, public readonly ?int $epoch = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
```

---

#### [MODIFY] `app/Services/Audit/BatchChunkState.php`

- **Clases afectadas:** `App\Services\Audit\BatchChunkState`
- **Métodos afectados:** `__construct()`, `initial()`, `fromPayload()` (`L23-70`)

**Antes** (`L23-30`, `L52-69`):
```php
    public function __construct(
        public ?array $cursor = null,
        public int $chunkIndex = 1,
        public int $accumulatedTotal = 0,
        public int $accumulatedSkippedLocked = 0,
        public int $accumulatedSkippedExisting = 0,
        public ?int $chunkSizeOverride = null,
    ) {}
```

**Después:** añadir parámetro final opcional con valor por defecto para compatibilidad de constructores posicionales existentes:
```php
    public function __construct(
        public ?array $cursor = null,
        public int $chunkIndex = 1,
        public int $accumulatedTotal = 0,
        public int $accumulatedSkippedLocked = 0,
        public int $accumulatedSkippedExisting = 0,
        public ?int $chunkSizeOverride = null,
        public int $candidateOffset = 0,
    ) {}
```
En `fromPayload(array $payload)`: resolver `$candidateOffset = max(0, (int) ($payload['candidate_offset'] ?? ($cursor['offset'] ?? 0)));`.

---

#### [MODIFY] `app/Services/Audit/Pipeline/BatchRequestedWorker.php` y `app/Services/Audit/Pipeline/AuditEventConsumer.php`

- **Clases afectadas:** `App\Services\Audit\Pipeline\BatchRequestedWorker`, `App\Services\Audit\Pipeline\AuditEventConsumer`
- **Métodos afectados:** `BatchRequestedWorker::__construct()` (`L35-54`, validación `DEC-12`), `BatchRequestedWorker::handleBatchRequested()` (`L93-164`), `BatchRequestedWorker::publishContinuationEvent()` (`L170-205`), `AuditEventConsumer::dispatchMessage()` (`L383-425`), `AuditEventConsumer::handleFailure()` (`L630-636`, `$nonRetryable`), `AuditEventConsumer::finalizeDeadLetterAudit()` (`L692-730`).

**Cambios deterministas completos:**
1. **`BatchRequestedWorker::handleBatchRequested()`:** si `($result['locked_busy'] ?? false) === true`, lanzar `throw new JobGenerationBusyException($jobId, $chunkState->chunkIndex);` (evita retorno normal y protege PEL).
2. **`BatchRequestedWorker::publishContinuationEvent()`:**
   - Añadir `'candidate_offset' => (int) ($result['next_candidate_offset'] ?? $result['next_cursor']['offset'] ?? $chunkState->candidateOffset),` a `$continuationPayload` (`next_candidate_offset` es la fuente primaria; sin él, la continuación releería el mismo slice indefinidamente).
   - Usar `event_id` determinista: `AuditEvent::deterministicUuidV4("batch-cont:{$jobId}:" . ($chunkState->chunkIndex + 1))`.
   - Inmediatamente tras `$this->publisher->publish($continuationEvent)`, confirmar transición **sin epoch** (`R-CONT`: el lock ya fue liberado y el consumidor de `k+1` pudo incrementar el epoch legítimamente):
     ```php
     if (!$this->jobStore->markContinuationDispatched($jobId, $chunkState->chunkIndex)) {
         throw new RuntimeException('job_missing_on_continuation', 503);
     }
     ```
   - El `claimJobGenerationFencedLock()` del consumidor de `k+1` pasa `chunkIndex = k+1`, de modo que si `A` cae entre `publish` y `markContinuationDispatched`, la confirmación se promueve en el claim y la reentrega de `k` resuelve por respuesta temprana sin republicar.
3. **`AuditBatchOrchestrator` → `AuditEventConsumer::dispatchMessage()` (`L383-425`):**
   - Toda excepción ocurrida bajo lock adquirido `$epoch` se re-lanza como `JobGenerationFailedException(..., epoch: $epoch)`.
   - En `handleFailure()` (`AuditEventConsumer.php:630-636`), ampliar `$nonRetryable` con `|| ($error instanceof JobGenerationFailedException && $error->getPrevious() instanceof SqlServerOperationException)`, para que el envoltorio no anule la regla actual de no reintentar `SqlServerOperationException` (`HYT00` va directo a DLQ, `SUP-01`).
   - En `dispatchMessage()`, agregar `catch (JobGenerationBusyException $busy)` **antes** de `catch (Throwable $e)`: registra `Logger::warning('AuditEventConsumer: lock de generación ocupado, mensaje retenido en PEL', ['job_id' => $busy->jobId, 'event_id' => $event->eventId, 'chunk_index' => $busy->chunkIndex])` y hace `return;` sin invocar `ackMessage()` ni `handleFailure()`.
4. **`AuditEventConsumer::finalizeDeadLetterAudit()` (`L692-696`):**
   ```php
    private function finalizeDeadLetterAudit(AuditEvent $event, Throwable $error): void
    {
        if ($event->auditId === null) {
            if ($event->eventType === AuditEvent::TYPE_BATCH_REQUESTED && $event->jobId !== null) {
                try {
                    $failedChunk = (int) ($event->payload['chunk_index'] ?? 1);
                    $expectedEpoch = $error instanceof JobGenerationFailedException ? $error->epoch : null;
                    if ($expectedEpoch === null) {
                        // R-CONT: sin epoch no hay prueba de propiedad; no cerrar un job posiblemente sano.
                        Logger::critical('batch_requested_dlq_without_epoch', [
                            'job_id' => $event->jobId, 'chunk_index' => $failedChunk, 'error' => $error->getMessage(),
                        ]);
                        return; // REAP_STALE_JOB_LUA lo cerrará si deja de progresar (DEC-10)
                    }
                    $store = new BatchJobStore($this->redis);
                    $closed = $store->markJobFailed(
                        $event->jobId,
                        $error->getMessage(),
                        $expectedEpoch,
                        $failedChunk
                    );
                    $failedJob = $closed ? null : $store->getJobSummary($event->jobId); // R4-F2-I3: solo status/stale_reason, sin HGETALL de audits
                    // X-F2-I2: se repite en cada reentrega mientras el job siga failed por DLQ (sin stale_reason);
                    // es idempotente y cubre una caída entre markJobFailed() y cancelUndispatchedAudits().
                    if ($closed || (($failedJob['status'] ?? '') === 'failed' && ($failedJob['stale_reason'] ?? null) === null)) {
                        // W-F2-B4: auditorías registradas sin publicar (dispatched = false) nunca se procesarán;
                        // sin cancelarlas, sus reservas disdetnro:* y audits_in_flight quedarían retenidos para siempre.
                        $store->cancelUndispatchedAudits($event->jobId, 'batch_dlq_unpublished');
                    }
                } catch (Throwable $finalizeError) {
                    throw new RuntimeException('No se pudo cerrar job batch antes de DLQ', 0, $finalizeError);
                }
            }
            return;
        }
   ```

---

#### [MODIFY] `app/Controllers/AuditController.php`

- **Clases afectadas:** `App\Controllers\AuditController`
- **Métodos afectados:** `async()` (`L429-478`)

**Antes** (`L443-467`):
```php
        try {
            $jobStore->initJob($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        } catch (\RuntimeException $e) {
            Response::error('Error inicializando job batch: ' . $e->getMessage(), 503);
        }
        // ...
        try {
            $publisher->publish($event);
        } catch (\RuntimeException $e) {
            Response::error('Error publicando evento batch_requested: ' . $e->getMessage(), 503);
        }
```

**Después:**
```php
        // W-F2-M6: la compensación es best-effort; un fallo de Redis en ella no debe convertir el 503/409 en 500.
        $compensate = static function (callable $step): void {
            try { $step(); } catch (\Throwable) {}
        };

        try {
            $initResult = $jobStore->initJobExclusive($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        } catch (\RuntimeException $e) {
            $compensate(fn () => $jobStore->releaseIdempotencyKeyIfMatches((string) $idempotencyKey, $jobId));
            Response::error('Error inicializando job batch: ' . $e->getMessage(), 503);
        }

        if (($initResult['created'] ?? false) !== true) {
            $compensate(fn () => $jobStore->releaseIdempotencyKeyIfMatches((string) $idempotencyKey, $jobId));
            $conflictJobId = $initResult['conflict_job_id'] ?? null;
            Response::error(
                'Ya existe un job activo para el cliente y rango de fechas especificado',
                409,
                [
                    'active_job_id' => $conflictJobId,
                    'fac_nit_sec'   => $facNitSec,
                    'date_from'     => $dateFrom,
                    'date_to'       => $dateTo,
                ]
            );
        }

        // ... creación de $event ...

        try {
            $publisher->publish($event);
        } catch (\RuntimeException $e) {
            $compensate(fn () => $jobStore->deleteJob($jobId));
            $compensate(fn () => $jobStore->releaseIdempotencyKeyIfMatches((string) $idempotencyKey, $jobId));
            Response::error('Error publicando evento batch_requested: ' . $e->getMessage(), 503);
        }
```

---

#### [MODIFY] `bin/schedule-daily-batches.php` y `core/Logger.php`

- **Símbolos / líneas afectadas:** bucle por cliente (`bin/schedule-daily-batches.php:170-230`) y `Core\Logger::sanitizeContext()` (`core/Logger.php:45-60`).
- **En `core/Logger.php` (`L45-59`):** agregar `'fac_nit_sec'` a `$sensitiveKeys` para redacción automática en profundidad.
- **En `bin/schedule-daily-batches.php` (`L195-214`):** usar `initJobExclusive()`, liberar `idempotencyKey` si colisiona o falla publicación, y enmascarar NIT antes de invocar `Logger::info()`:
```php
    try {
        $initResult = $jobStore->initJobExclusive($jobId, $facNitSec, $dateFrom, $dateTo, $limit);
        if (($initResult['created'] ?? false) !== true) {
            $jobStore->releaseIdempotencyKeyIfMatches($idempotencyKey, $jobId);
            $conflictJobId = (string) ($initResult['conflict_job_id'] ?? '');
            $maskedNit = '***' . substr((string) $facNitSec, -3);
            fwrite(STDOUT, "  ⏭  {$label} ({$clientName}) — Ya existe job activo solapado (job_id={$conflictJobId})\n");
            Logger::info('schedule-daily-batches: cliente omitido por solapamiento activo', [
                'fac_nit_sec_masked' => $maskedNit,
                'active_job_id'      => $conflictJobId,
            ]);
            $summary['skipped_duplicate']++;
            continue;
        }
        // ... publish($event); ante catch compensar deleteJob + releaseIdempotencyKeyIfMatches
```

---

#### [MODIFY] `app/Services/Audit/Pipeline/AuditExecutionConfig.php`

- **Contexto:** `HEARTBEAT_TTL_SECONDS`/`HEARTBEAT_MAX_AGE_MS`, su uso en `AuditAdmissionGuard.php:25`, `AuditStateStore.php:139` y `resume`, y los helpers `AuditStateStore::getAuditStatus()`/`readAuditRetentionMetadata()` son entregados por **SDD-BATCH-000**. Esta fase no modifica `AuditAdmissionGuard.php` ni `AuditStateStore.php`.
- **En `AuditExecutionConfig.php`:** añadir lectores con `Env::get` literal (para que `ci.yml:113-129` los valide contra `.env.example`):
  ```php
  public static function jobStaleAfterSeconds(): int
  {
      $floor = max(self::batchLockTtlSeconds(), 3600);
      $raw = trim((string) Env::get('AUDIT_JOB_STALE_AFTER_SECONDS', '21600'));
      return max($floor, self::digitsOrDefault('AUDIT_JOB_STALE_AFTER_SECONDS', $raw, 21600));
  }

  public static function freezeQueryTimeoutSeconds(): int
  {
      $raw = trim((string) Env::get('AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS', '300'));
      return max(30, self::digitsOrDefault('AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS', $raw, 300));
  }

  /** R4-F2-B2: punto de lectura único del TTL del lock de generación (BatchRequestedWorker, AuditBatchOrchestrator y jobStaleAfterSeconds). */
  public static function batchLockTtlSeconds(): int
  {
      $raw = trim((string) Env::get('AUDIT_BATCH_LOCK_TTL_SECONDS', '1800'));
      return max(60, self::digitsOrDefault('AUDIT_BATCH_LOCK_TTL_SECONDS', $raw, 1800));
  }

  /** W-F2-M9: '' (variable definida vacía en .env) usa el default; cualquier valor no numérico es error de configuración. */
  private static function digitsOrDefault(string $key, string $raw, int $default): int
  {
      if ($raw === '') {
          return $default;
      }
      if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
          throw new \InvalidArgumentException($key . '_invalid');
      }
      return (int) $raw;
  }
  ```
  (Se mantiene la lectura literal `Env::get('AUDIT_...'` porque `ci.yml:114` detecta las variables con `grep -oP "(?:getenv|Env::get)\(['\"]\K..."`; `self::integer($key, ...)` no sería detectado y además lanza `InvalidArgumentException` ante `''`, `AuditExecutionConfig.php:71-79`. Por la misma razón `R5-F2-B1`, `jobStaleAfterSeconds()` delega el piso a `self::batchLockTtlSeconds()` en lugar de `self::integer('AUDIT_BATCH_LOCK_TTL_SECONDS', ...)`, evitando que `AUDIT_BATCH_LOCK_TTL_SECONDS=''` tumbe `worker-execution-maintenance`.)
- **En `BatchRequestedWorker::__construct()`:** validar `4 * freezeQueryTimeoutSeconds() + 36 < min(AUDIT_BATCH_LOCK_TTL_SECONDS, pendingReclaimIdleMs / 1000)`; si no se cumple, lanzar `\RuntimeException('batch_freeze_timeout_exceeds_lock_window')` (el worker no arranca y el log lo expone). `AUDIT_BATCH_LOCK_TTL_SECONDS` se lee con `AuditExecutionConfig::batchLockTtlSeconds()` (público; `digitsOrDefault()` es `private`, `R4-F2-B2`), de modo que sin la variable o con `''` no cae a `0` ni a `300` (`X-F2-M3`). `AuditBatchOrchestrator` obtiene `$lockTtl` del mismo método en lugar de `(int) Env::get('AUDIT_BATCH_LOCK_TTL_SECONDS', self::DEFAULT_LOCK_TTL_SECONDS)` (`AuditBatchOrchestrator.php:108`, que convierte `''` en `0`).

---

#### [MODIFY] `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` (delta sobre SDD-BATCH-000)

- **Contrato heredado sin cambios:** deadline global de `tick()`, outbox en ráfaga, intake con `XACK` por lotes, `gc()` incremental (`pattern_index` que avanza al completar patrón, reanudación por `pending_keys` tras corte de presupuesto sin avanzar índice (`DEC-F1-02`), dedup `SCAN`, caché solo-`status`, presupuesto `500` ítems / `500 ms` / `256 MiB`, estado persistido en `finally`) y heartbeat. **Extensiones de Fase 2 al contrato** (SDD-BATCH-000 preserva campos desconocidos): campos nuevos en `audit:execution:gc:cursors` (Sección 9) y campo `jobs_reaped` en `heartbeat:meta` (`writeHeartbeat` pasa a `11` campos; la llamada de Fase 2 conserva los argumentos `admission_paused`/`execution_paused` de `REQ-F1-13`).
- **Cambios de Fase 2:**
  1. **Patrón de reservas (`W-F2-I3`, `R5-F2-I1`):** `reservations` pasa a `audit:reservation:disdetnro:*`. Mientras `legacy_done = '0'`, al entrar al índice `5` se hace **una** pasada completa sobre `audit:reservation:disid:*` con el cursor `cursor:reservations_legacy` (`'0'` → … → `'0'`), registrando en el campo persistido `legacy_saw_keys = '1'` si alguna página devolvió claves (`X-F2-B3`: ni este indicador ni el de fin de pasada viven en RAM, para sobrevivir a cortes de presupuesto entre ticks). Mientras `legacy_done !== '1' && legacy_pass_done !== '1'`, el fin de la fase legada se decide **solo** con `cursor:reservations_legacy === '0'` tras una página **y `$keys === []` tras drenar toda la página**, y la salida temprana heredada de SDD-BATCH-000 (`$scanned && $state['cursors'][$p['name']] === '0'` ⇒ `break`) **no** se evalúa para el índice `5`. **Regla `R5-F2-I1`:** `legacy_pass_done = '1'` (y `legacy_done = '1'` si `legacy_saw_keys === '0'`) se fija **únicamente cuando `$keys === []` tras drenar la página final** de `disid:*`, **nunca** mientras queden claves sin procesar en `$keys`; si la última página devuelve `cursor:reservations_legacy = '0'` con claves pendientes y corta el presupuesto o lanza antes de agotar `$keys`, `legacy_pass_done` permanece `'0'` para reanudar esas `pending_keys` en el siguiente tick (y si lanza justo en la última clave ya extraída de `$keys`, la rama espejo en `finally` del punto 4 cierra la pasada sin avanzar el índice). Cuando ese cursor está en `'0'` con `$keys === []`: si `legacy_saw_keys === '0'` se fija `legacy_done = '1'` (y a partir de ahí no se vuelve a visitar el patrón legado); **en ambos casos** se fija `legacy_pass_done = '1'`, se reinicia `$scanned = false` **sin** `break` y se continúa en el mismo índice con `audit:reservation:disdetnro:*`. Las claves `disid:*` sobrevivientes (auditoría viva o `< 60 s`, `AuditExecutionMaintenance.php:342-343`) se reevalúan recién en el siguiente ciclo de `gc()`; nunca se repite `SCAN '0'` sobre el patrón legado dentro de la misma visita del índice (evita el bucle cerrado filtrado por `$seen` que agotaba los `500 ms`). Al completar el índice `5` (cursor `reservations` de vuelta a `'0'` con `legacy_pass_done = '1'`), si `legacy_done !== '1'` se reinician `legacy_pass_done` y `legacy_saw_keys` a `'0'` para la pasada del ciclo siguiente.
  1b. **Reservas huérfanas de un job terminal (`X-F2-B1`):** en la rama `reservations` (`disdetnro:*`), cuando `$status !== null && !AuditStateStore::isTerminalStatus($status)` (mismo `$statusOf` que SDD-BATCH-000; `R4-F2-M2`), `$reservation['job_id'] !== ''` y `claimed_at` tiene `>= 60 s`, leer `job:{job_id}:state` (caché por llamada de `gc()`, solo `status`). Si el job no existe, o si su status es terminal (`failed`, `completed` o `completed_with_errors`) **y** `HEXISTS job:{job_id}:audits {audit_id} = 0`, ejecutar `GC_RELEASE_ORPHAN_RESERVATION_LUA` (`KEYS = [reservationKey, auditStateKey]`, `ARGV = [rawReservation, nowIso]`): si `GET KEYS[1] ~= ARGV[1]` retorna `0`; si `KEYS[2]` existe, no es terminal y su `job_id` coincide, lo reescribe con `status = 'failed'`, `failed_stage = 'orphaned_job_terminal'`, `completed_at = updated_at = ARGV[2]` (`SET` sin `EX`, conservando `documents`); `DEL KEYS[1]`; retorna `1`. No toca `job:{id}:audits` ni contadores del job (la fila no se registró, o el job ya es terminal). Cuenta `1` ítem de presupuesto. Una fila registrada en `job:{id}:audits` de un job `failed` **nunca** se toca aquí (`R4-F2-B1`): si está `dispatched = true` sigue en vuelo y la cerrará `MARK_AUDIT_COMPLETED_IN_JOB_LUA` (que decrementa `audits_in_flight`); si está `dispatched = false`, la cancela `cancelUndispatchedAudits()`, que `finalizeDeadLetterAudit()` repite en cada reentrega (`X-F2-I2`).
  2. **`job:*:state` v2:** leer solo metadatos (`STRLEN < 2048`). Si es terminal y vencido (`AUDIT_JOB_TTL`), comprobar referencias activas con `HSCAN job:{jobId}:audits $hscanCursor COUNT 500` + Lua que, por lotes `<= 500` (`LUAI_MAXCSTACK`-safe), lee `SMEMBERS audit:execution:refs:{auditId}` y considera activa solo una referencia con miembro **sin** prefijo `plan:` (misma regla que `GC_DELETE_JOB_STATE_LUA`, `AuditExecutionMaintenance.php:70-83`, y que la Sección 9). Referencia activa → abortar borrado de ese job y limpiar `hscan_job_id`, `hscan_cursor` y `hscan_sha1` (`R4-F2-M3`). Al iniciar el `HSCAN` de un job se persiste `hscan_sha1 = sha1(job:{id}:state)` junto a `hscan_job_id` (`W-F2-M2`). Corte de presupuesto a mitad de `HSCAN` → persistir `hscan_job_id`/`hscan_cursor`/`hscan_sha1` en `audit:execution:gc:cursors` y re-encolar `$jobKey` al frente de `pending_keys`. Al terminar limpio, borrar atómicamente `job:{id}:state`, `job:{id}:audits`, `job:{id}:by_dispensa`, `job:{id}:candidates`, `ZREM jobs:index` y `HDEL jobs:active:nit:{nit}`, **solo si** `sha1(job:{id}:state)` coincide con `hscan_sha1` (mismo borrado por `sha1` de SDD-BATCH-000); si cambió, abortar el borrado y limpiar `hscan_*`.
  3. **Jobs no terminales (`DEC-10`; terminal ⇔ `status ∈ {completed, completed_with_errors, failed}`, `sealed` no cuenta):** si `audit:execution:paused = 1`, **no** invocar el reaper automático (una pausa de corrección hacia adelante no debe fallar jobs). En otro caso, con `baseline = max(last_progress_at ?? ms(updated_at), audit:execution:resumed_at_ms ?? 0)` (`ms(updated_at)` calculado en PHP desde el ISO-8601; `job:{id}:state` no tiene campo `updated_at_ms`), si `now - baseline >= AuditExecutionConfig::jobStaleAfterSeconds() * 1000`, invocar `BatchJobStore::reapStaleJob($jobId, $nowMs, $thresholdMs, $reapCursor)`; si hay `reap_job_id` pendiente en `audit:execution:gc:cursors`, se reanuda **al inicio de `gc()`**, antes del bucle `GC_PATTERNS` (salvo pausa), con `reap_cursor`, de modo que un corte en la última clave del patrón `jobs` no posponga la reanudación un ciclo completo (`W-F2-M7`). **Guarda `R5-F2-B2`:** inicializar `$enteredPatternLoop = false;` antes del bloque `try` de `gc()` y fijar `$enteredPatternLoop = true;` al entrar al cuerpo de `while ($visited < count(self::GC_PATTERNS))`, de modo que si la reanudación pre-bucle de `reap_job_id` agota el presupuesto antes de entrar al bucle de patrones, `finally` no sobrescriba `pending_pattern`/`pending_keys` ni evalúe el avance de índice (evita pisar con `[]`/`null` las claves pendientes ya persistidas en Redis); si el reaper corta por presupuesto mientras se procesa el índice `4`, se persisten `reap_job_id`/`reap_cursor` y se re-encola `$jobKey` al frente de `pending_keys` (igual que `hscan`); **todo `job:*:state` con `reaping === true` se reanuda de inmediato** (sin exigir umbral ni `baseline`), para no dejarlo bloqueado si el proceso cayó antes de persistir `reap_job_id`; cada invocación cuenta como `1` ítem del presupuesto y cada rebanada `HSCAN` del reaper como `1` ítem adicional; acumular el número de jobs cerrados en `$this->lastGcReapedJobs`, que `tick()` propaga como `writeHeartbeat(..., ['jobs_reaped' => $this->lastGcReapedJobs], $admissionPaused, $executionPaused)` (`$extraMeta` y argumentos de pausa de SDD-BATCH-000, `REQ-F1-13`).
  4. **Guarda del `finally` heredado y reinicio por tick (`W-F1-I3`, `W-F1-M7`, `R5-F2-B2`, `R5-F2-I1`):** el `finally` de `gc()` de SDD-BATCH-000 (`V-F1-B1`, `plans/sdd-hotfix-mantenimiento-ejecucion.md:748-761`) avanza `pattern_index` cuando `!$patternAdvanced && $scanned && $keys === [] && $state['cursors'][$p['name']] === '0'` y asigna `pending_pattern`/`pending_keys`. En Fase 2:
     - **todo ese bloque en `finally` (avance de `pattern_index` y escritura de `pending_pattern`/`pending_keys`) se envuelve en `if ($enteredPatternLoop) { ... }`** (`R5-F2-B2`), preservando intactos `pending_pattern`/`pending_keys` si un corte pre-bucle al reanudar `reap_job_id` impide entrar al `while`;
     - índice `5` (`reservations`): exigir además `$state['legacy_done'] === '1' || $state['legacy_pass_done'] === '1'` (es decir, que se esté recorriendo `disdetnro:*`); mientras se recorre `disid:*`, `$state['cursors']['reservations']` permanece en `'0'` y, sin esta guarda, una excepción en la última clave de una página legada saltaría a `6` omitiendo el resto de `disid:*` y toda la pasada de `disdetnro:*`. Complementariamente (`R5-F2-I1`), si ocurre una excepción al procesar la **última clave ya extraída de `$keys`** en la página final de `disid:*` (`$legacyBlocks && !$patternAdvanced && $scanned && $keys === [] && $state['cursors']['reservations_legacy'] === '0'`), una rama espejo fija `legacy_pass_done = '1'` (y `legacy_done = '1'` si `legacy_saw_keys === '0'`) **sin** avanzar `pattern_index` para que el siguiente tick continúe en `disdetnro:*` en vez de re-escanear `disid:*` desde `'0'`;
     - índice `4` (`jobs`): exigir además `$state['hscan_job_id'] === '' && $state['reap_job_id'] === ''` (ambos cortes re-encolan `$jobKey` en `pending_keys`, por lo que `$keys !== []` ya bloquea el avance en el camino normal; la guarda cubre la excepción).
     ```php
     if ($enteredPatternLoop) {
         $legacyBlocks = $idx === 5 && $state['legacy_done'] !== '1' && $state['legacy_pass_done'] !== '1';
         $jobsBlocks   = $idx === 4 && (($state['hscan_job_id'] ?? '') !== '' || ($state['reap_job_id'] ?? '') !== '');
         if ($legacyBlocks && !$patternAdvanced && $scanned && $keys === []
             && $state['cursors']['reservations_legacy'] === '0') {
             // R5-F2-I1: última página de disid:* terminó con $keys === [] (p. ej. excepción en la última clave);
             // cierra la pasada legada sin avanzar pattern_index para continuar en disdetnro:* en el próximo tick.
             $state['legacy_pass_done'] = '1';
             if ($state['legacy_saw_keys'] === '0') {
                 $state['legacy_done'] = '1';
             }
         } elseif (!$patternAdvanced && $scanned && $keys === [] && $state['cursors'][$p['name']] === '0'
             && !$legacyBlocks && !$jobsBlocks) {
             // avance heredado de SDD-BATCH-000
             if ($idx === 5 && $state['legacy_done'] !== '1') {
                 // R4-F2-I2: mismo reinicio que al completar el índice 5 por el camino normal (punto 1);
                 // sin él, el ciclo siguiente saltaría la pasada sobre disid:*.
                 $state['legacy_pass_done'] = '0';
                 $state['legacy_saw_keys'] = '0';
             }
         }
         $state['pending_pattern'] = $keys === [] ? null : $idx;
         $state['pending_keys'] = $keys;
     }
     ```
     En `tick()`, junto a `$this->gcBudgetStopped = false;` (`plans/sdd-hotfix-mantenimiento-ejecucion.md:612`), añadir `$this->lastGcReapedJobs = 0;` para que un tick sin `gc()` publique `jobs_reaped = 0` y no repita el conteo de un `gc()` anterior.
  5. **Jobs v1 residuales (con `audits` inline):** tras `migrate-batch-v2` no deben existir (`STRLEN < 2048` en todos); si `STRLEN >= 2048`, registrar `Logger::critical('gc_v1_job_state_after_migration', ['job_id' => ...])` y omitir el job (no decodificar), sin abortar `gc()`.

---

#### [MODIFY] `app/Services/Audit/Pipeline/AuditExecutionInspector.php`

- **Clases afectadas:** `App\Services\Audit\Pipeline\AuditExecutionInspector`
- **Métodos afectados:** `snapshot()` (`L39-77`), nuevo `repairCorruptedJobs()`
- En `snapshot()`: leer `HLEN` de `BatchJobStore::jobAuditsKey($job['job_id'])` antes de validar `$auditsCount !== $total` (`job_totals_inconsistent`).
- Añadir `reapStaleJobs(?string $forceJobId, int $thresholdMs): array` que recorre `job:*:state` no terminales (o solo `$forceJobId`) invocando `BatchJobStore::reapStaleJob()` y devuelve `['reaped_jobs' => int, 'cancelled_audits' => int, 'skipped_claimed' => int]`.
- Añadir `repairCorruptedJobs(): array` invocando `BatchJobStore::repairJobStates()` + `BatchJobStore::reconcileJobTelemetryMetrics()` (sanea `total=0`, `done+failed > total`, preserva `failed`, **nunca pone `sealed = false` en un job sellado** (`R-SEAL`), actualiza `jobs:active:nit:{nit}`, sin tocar reservas ni `batch:claim:*`), devolviendo `['repaired_jobs' => int, 'audits_recounted' => int]`. `BatchJobStore::migrateBatchV2()` reutiliza exactamente el mismo método `repairJobStates()`.

---

#### [MODIFY] `bin/audit-execution-maintenance.php`

- **Símbolos afectados:** guarda de argumentos (`L18-21`) y `match ($command)` (`L82-87`). (`resume` y el log de arranque los actualiza SDD-BATCH-000.)
- En `L18`: reemplazar la guarda `$argc > 2` (HEAD `:18`, que rechaza todo segundo argumento y que `SDD-BATCH-000` deja sin cambios) por `$argc > 3` y validar el **segundo argumento solo cuando existe** (`$argc === 3`): únicamente se acepta `--clear-claims` si `$command === 'migrate-batch-v2'` y `--force-stale[=<jobId>]` si `$command === 'repair-jobs'`; cualquier otro segundo argumento sale con `exit(1)` y `'Opcion desconocida'`. Los comandos sin segundo argumento (`pause`, `observe`, `resume`, `protocol`, `run`, `durability-check`, `reconcile`, `gc`, `idem-expire-legacy`, etc.) no se ven afectados.
- En `run`: añadir al log de arranque `'job_stale_after_seconds'` y `'freeze_query_timeout_seconds'`.
- En `resume`: tras validar el heartbeat, escribir `SET audit:execution:resumed_at_ms <now_ms>` (sin TTL) para que el umbral del reaper se mida desde la última reanudación (`DEC-10`, pausa no cuenta como falta de progreso).
- En `match ($command)`:
  - `'migrate-batch-v2' => (new BatchJobStore($redis))->migrateBatchV2($argc === 3 && ($argv[2] ?? '') === '--clear-claims') + ['legacy_idempotency' => $maintenance->expireLegacyIdempotency()],` (paso 6 de la migración, `W-F1-I4(a)`: `$maintenance` ya existe en `L26`; si `migrateBatchV2()` lanza, `expireLegacyIdempotency()` no se ejecuta y el comando termina con código `1`).
  - `'rollback-batch-v2' => (new BatchJobStore($redis))->rollbackBatchV2(),` (código `1` si la precondición `DEC-11` falla)
  - `'repair-jobs' => str_starts_with($argv[2] ?? '', '--force-stale') ? (new AuditExecutionInspector($redis))->reapStaleJobs(forceJobId: <valor tras '=' o null>, thresholdMs: 0) : (new AuditExecutionInspector($redis))->repairCorruptedJobs(),`
  - Si `$argv[2]` empieza por `--force-stale=` y el valor tras `=` es vacío o no cumple `AuditEvent::isUuidV4()`: `fwrite(STDERR, "Opcion desconocida\n")` y `exit(1)` (`X-F2-M4`).

---

#### [MODIFY] `.env.example`, `.github/workflows/deploy-production.yml`, `AGENTS.md` y `plans/audit-identity-contract.md`

- **`.env.example` (`L149`):** `AUDIT_BATCH_LOCK_TTL_SECONDS=300` → `AUDIT_BATCH_LOCK_TTL_SECONDS=1800`; añadir `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=300` y `AUDIT_JOB_STALE_AFTER_SECONDS=21600`; documentar `DB_TIMEOUT=30` y `DB2_TIMEOUT=30` (hoy ausentes; leídas por concatenación en `core/Database.php:245`).
- **`AGENTS.md`:** registrar `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` y `AUDIT_JOB_STALE_AFTER_SECONDS` en el Catálogo de Variables de Entorno.
- **`AGENTS.md` (`L402`):** actualizar tabla del Catálogo de Variables de Entorno `AUDIT_BATCH_LOCK_TTL_SECONDS` default `300` → `1800`, y ejecutar la sincronización obligatoria en GitHub Environment antes del despliegue:
  ```bash
  gh variable set AUDIT_BATCH_LOCK_TTL_SECONDS --body "1800" --env production
  gh variable set AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS --body "300" --env production
  gh variable set AUDIT_JOB_STALE_AFTER_SECONDS --body "21600" --env production
  gh variable list --env production | grep -E '^(AUDIT_BATCH_LOCK_TTL_SECONDS|AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS|AUDIT_JOB_STALE_AFTER_SECONDS)\b'
  ```
  (`W-F2-I7`: las tres variables son nuevas o modificadas y la regla de `AGENTS.md` exige sincronizarlas todas.)
- **`.github/workflows/deploy-production.yml` (`L3-13`, `L22-29`, `L101-109`, `L188`, `L404`, `L467-478`, `L529-578`):**
  1. Añadir input en `workflow_dispatch` y, en el `env:` de nivel workflow (`L22-29`, junto a `IMAGE_TAG`), la variable `OPERATION` que gobierna **todas** las condiciones del workflow (`W-F2-B5`: con `workflow_run`, `L4-6`, `inputs.operation` es `''`; sin este valor por defecto los despliegues automáticos de `main` omitirían la rama `deploy`):
     ```yaml
     on:
       workflow_dispatch:
         inputs:
           operation:
             description: "Operation mode: deploy (forward cutover) or rollback (downgrade image_tag)"
             required: true
             default: "deploy"
             type: choice
             options: [deploy, rollback]
     env:
       OPERATION: ${{ github.event_name == 'workflow_run' && 'deploy' || inputs.operation }}
     ```
     Los steps exclusivos se condicionan con `if: env.OPERATION == 'deploy'` / `if: env.OPERATION == 'rollback'`; nunca con `inputs.operation`.
  2. `L404`: `write_env_var 'AUDIT_BATCH_LOCK_TTL_SECONDS' "${AUDIT_BATCH_LOCK_TTL_SECONDS:-1800}"`, y análogos para `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` (`300`) y `AUDIT_JOB_STALE_AFTER_SECONDS` (`21600`); además mapear `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS: ${{ vars.AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS }}` y `AUDIT_JOB_STALE_AFTER_SECONDS: ${{ vars.AUDIT_JOB_STALE_AFTER_SECONDS }}` en el bloque `env:` del paso `Create production environment file` (junto a `L188`), o el override del GitHub Environment `production` se ignoraría.
  3. **Mover barrera operativa ANTES de `Upgrade extractors before bounded cache producers` (`L543`):**
     - **Si `OPERATION == 'deploy'` (cutover normal; incluye todo `workflow_run`; steps con `if: env.OPERATION == 'deploy'`):**
       a) Ejecutar `pause` con la imagen PHP actualmente en ejecución (`docker compose exec -T php php bin/audit-execution-maintenance.php pause` o `run --rm --no-deps --entrypoint php` si `php` estuviera detenido).
       b) Esperar drenaje de peticiones HTTP en vuelo y cron (`for _ in $(seq 1 15); do pgrep -f schedule-daily-batches.php >/dev/null || break; sleep 2; done`), y detener gracefully `php` y todos los `worker-*` (`worker-downloader` se renombra en `SDD-BATCH-002`, que debe actualizar este `stop`; `docker compose stop -t 30 php worker-batch worker-orchestrator worker-downloader worker-extraction-vip worker-extraction-batch worker-normalizer worker-policy worker-persistence worker-semantic-vip worker-semantic-batch worker-execution-maintenance`) — cero escritores v1/v2 concurrentes antes de tocar `extractors`.
       c) Correr con `${PHP_IMAGE}:${IMAGE_TAG}`: `migrate-batch-v2 --clear-claims`, `repair-jobs`, `reconcile`.
       d) Ejecutar `Upgrade extractors before bounded cache producers` (`worker-extraction-vip`, `worker-extraction-batch`).
       e) Ejecutar `Start production stack preserving Redis` (`up -d "${services[@]}"`), sondear hasta `30 s` la aparición de `audit:execution:heartbeat` con edad `< AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS` (`30.000 ms`) y ejecutar `ctl resume`.
     - **Si `OPERATION == 'rollback'` (`DEC-11`, forward-fix only; `image_tag=<sha_anterior>`; rediseño ejecutable `W-F2-B5`):** el workflow es la **única** ruta que ejecuta `pause`, `stop` y `rollback-batch-v2` (el operador solo dispara `workflow_dispatch`, Sección 14).
       a) **Dos steps nuevos insertados entre `Validate required secrets` y `Prepare deployment directory` (`L101`)**, ambos con `if: env.OPERATION == 'rollback'`: (1) `Log in to GHCR (rollback precheck)` — mismo `docker/login-action@v3` de `L467-472`, necesario porque el login normal ocurre después de `L109`; (2) `Rollback data on current v2 stack`. Corren mientras `$DEPLOY_PATH/docker-compose.yml` y `$DEPLOY_PATH/.env` **todavía apuntan a la imagen v2 vigente** (los reescriben `L101-107` y `L109`/`L326`), por lo que `docker compose` usa la imagen v2 sin capturar IDs de contenedor (`php` tiene `replicas: 5` sin `container_name`, `docker-compose.yml:37,46`, y puede estar detenido):
          ```bash
          set -euo pipefail
          DEPLOY_PATH="${DEPLOY_DIR:-$HOME/audfact-prod}"          # mismo cálculo que L104; aún no está en $GITHUB_ENV
          if [ ! -f "$DEPLOY_PATH/docker-compose.yml" ] || [ ! -f "$DEPLOY_PATH/.env" ]; then
            echo "::error::No existe stack v2 desplegado en $DEPLOY_PATH"; exit 1
          fi
          cd "$DEPLOY_PATH"
          # Guarda bounded-cache (L547-550) evaluada sobre la imagen destino ANTES de mutar datos:
          docker pull "${PHP_IMAGE}:${IMAGE_TAG}"
          if ! docker run --rm --entrypoint php "${PHP_IMAGE}:${IMAGE_TAG}" -r 'exit(is_file("/var/www/html/app/Services/Audit/Pipeline/AuditDocumentInput.php") ? 0 : 1);'; then
            echo "::error::La imagen destino no soporta bounded cache (AuditDocumentInput.php ausente); rollback no permitido"; exit 1
          fi
          if [ -z "$(docker compose ps --status running -q redis)" ]; then
            docker compose up -d --no-deps --no-build redis
            for _ in $(seq 1 30); do
              docker compose exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping' >/dev/null && break; sleep 2
            done
          fi
          # --entrypoint php obligatorio: el ENTRYPOINT (Dockerfile:65) ejecutaría composer install (docker-entrypoint.sh:37-48)
          ctl() { docker compose run --rm --no-deps -T --entrypoint php php bin/audit-execution-maintenance.php "$@"; }
          # NOTA: SDD-BATCH-002 (Fase 3) reemplaza worker-downloader por worker-downloader-vip worker-downloader-batch; debe actualizar esta lista y el `docker compose stop` de abajo
          v2_services=(php worker-batch worker-orchestrator worker-downloader worker-extraction-vip worker-extraction-batch worker-normalizer worker-policy worker-persistence worker-semantic-vip worker-semantic-batch worker-execution-maintenance)
          ctl pause
          for _ in $(seq 1 15); do pgrep -f schedule-daily-batches.php >/dev/null || break; sleep 2; done
          docker compose stop -t 30 "${v2_services[@]}"
          if grep -qx "AUDFACT_IMAGE_TAG=${IMAGE_TAG}" .env; then
            # Re-ejecución tras un fallo posterior a rollback-batch-v2 = 0: L109 solo se alcanza con datos ya
            # revertidos, y la imagen previa no conoce rollback-batch-v2. Se continúa sin re-ejecutarlo.
            echo "::notice::Stack ya en ${IMAGE_TAG}; datos ya revertidos, se omite rollback-batch-v2"
          elif ! ctl rollback-batch-v2; then
            echo "::error::rollback-batch-v2 abortó (DEC-11: jobs activos o reservas disdetnro:*). Se relanza v2 en pausa; corregir hacia adelante."
            docker compose up -d --no-deps --no-build "${v2_services[@]}"   # mismo $DEPLOY_PATH, .env intacto (tag v2)
            exit 1                                                        # pause se conserva; L101+ no se ejecutan
          fi
          ```
          La guarda `AuditDocumentInput.php` tiene una única regla: la imagen destino **debe** contenerla; se evalúa aquí (antes de mutar datos) y se vuelve a evaluar sin cambios en `Upgrade extractors` (`L547-550`). Si falla aquí, no se ha tocado ni Redis ni el stack (el `pause` aún no se ejecutó).
       b) Solo si el step anterior termina con `0`, el workflow sigue con los pasos existentes `Prepare deployment directory` (`L101`), `Create production environment file` (`L109`, ahora con `AUDFACT_IMAGE_TAG=<sha_anterior>` en `L326`), `Log in to GHCR` (`L467`), `Pull release images` (`L474`), `Preflight SQL connectivity` (`L480`), `Ensure Redis is running` (`L529`), `Upgrade extractors` (`L543`) y `Start production stack` (`L570`). Los steps exclusivamente v2 (`migrate-batch-v2 --clear-claims`, `repair-jobs`) y la barrera del punto 3 de `deploy` llevan `if: env.OPERATION == 'deploy'` (en `rollback` el stack ya está detenido y en pausa). Tras `Start production stack`: `ctl reconcile` (si el `<sha_anterior>` lo contiene), espera de heartbeat `< HEARTBEAT_MAX_AGE_MS` y `ctl resume`.
       c) Si cualquier step posterior a `rollback-batch-v2 = 0` falla, los datos ya están en formato v1 y **no** se relanza v2 (escribiría v2 sobre datos v1): el pipeline queda en `pause` y el operador re-ejecuta el workflow `operation=rollback` con el mismo `image_tag`: como `$DEPLOY_PATH/.env` ya contiene `AUDFACT_IMAGE_TAG=<sha_anterior>` (solo se escribe tras `rollback-batch-v2 = 0`), el step de rollback omite `rollback-batch-v2` (que la imagen previa no tiene) y continúa con el despliegue.
- **`plans/audit-identity-contract.md`:** especificar `DisDetNro` como clave de reserva Redis (`audit:reservation:disdetnro:{disDetNro}`) alineada `1:1` con `AudDispEst.FacNro`, conservando `DisId` en `AudDispEst.FacSec`.

---

#### [MODIFY] Archivos de Prueba Afectados (`tests/`)

Los siguientes 12 archivos de test (10 modificados + 2 nuevos) se actualizan atómicamente según lo mapeado en `0.1`, `0.3` y detallado en `16. Testing`:
- `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` (creado por SDD-BATCH-000; Fase 2 añade la aserción de `jobs_reaped` en `heartbeat:meta`, compatible con `T-I7`/`CA-F1-07` que exigen **al menos** los 10 campos base)
- `tests/Core/LoggerSanitizationTest.php` (NEW)
- `tests/Integration/AuditStaleJobReaperRedisTest.php` (NEW)
- `tests/Models/InvoicesModelTest.php`
- `tests/Controllers/AuditControllerTest.php` (`L132` mock `method('initJobExclusive')` + `L236` subclase anónima + `StubBatchJobStore` `L778,808` + nuevos tests `409` + adaptación de `T-U4` de SDD-BATCH-000: se conserva `$claimIdempotencyKeyThrowsInvalid` y «sin `initJob`» pasa a «sin `initJobExclusive`»)
- `tests/Services/Audit/AuditBatchOrchestratorTest.php` (`BatchOrchestratorJobStore` sin `initJob`/`claimJobGenerationLock`, firma `sealJob`/`publishEmptyBatch`, recuperación y `JobLockClaimStatus`; **y** `BatchOrchestratorInvoicesModel::getInvoicesForAuditBatch()` (`L368-374`) con los 7 parámetros nuevos, evitando *Fatal error* de firma incompatible en PHP 8.2)
- `tests/Services/Audit/Events/BatchRequestedWorkerTest.php` (`processEvent()`, restauración `AUDIT_BATCH_CHUNK_SIZE`, `JobGenerationBusyException`, `markContinuationDispatched`; **y** las dos clases anónimas que extienden `InvoicesModel` (`L126-132`, `L219-225`) con la firma de 7 parámetros)
- `tests/Services/Audit/Events/AuditPersistenceWorkerTest.php` (`L113`)
- `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php` (`3` `KEYS` + preservación `'failed'`)
- `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php` (`disdetnro:877`; las pruebas de TTL de idempotencia pertenecen a SDD-BATCH-000)
- `tests/Services/Audit/Pipeline/AuditMemoryCompactionAndGcTest.php` (delta Fase 2: `HSCAN` reanudable, patrón `disdetnro`, rama `reapStaleJob`)
- `tests/Integration/AuditExecutionMigrationRedisTest.php` (suite Redis real: `R-SEAL`, `R-ROLL`, `R-CONT`, `R-PREPUB`, `R-EMPTY`, `H3`)

---

### 14. Plan de Migración

#### Prerequisitos

1. **`SUP-03` cerrado:** SDD-BATCH-000 desplegado con señales verdes `24 h` consecutivas (`0` OOM en `worker-execution-maintenance`, heartbeat `< 30.000 ms`, `tick_duration_ms` p99 `<= 6.000 ms` según `CA-F1-06`).
2. Validar `RUN_REDIS_INTEGRATION=1` en verde en CI y backup/snapshot AOF+RDB previo en volumen Redis de producción.
3. Verificar sincronización remota y variables de entorno alineadas antes del deploy: los tres `gh variable set ... --env production` de Sección 13 (`AUDIT_BATCH_LOCK_TTL_SECONDS=1800`, `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=300`, `AUDIT_JOB_STALE_AFTER_SECONDS=21600`) confirmados con `gh variable list --env production`, más `.env.example`, `AGENTS.md` y `AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS=1800000`.
   - **Valores iniciales recomendados para producción (`R6-F2-I1`, evidencia `172.16.0.3` 19:55Z–20:08Z):** el job `73d30b71` (NIT `2624`) tardó ~`270 s` en **una sola página de 100 filas** en `db2`; congelar hasta `3.000` filas con `300 s` abortaría por `HYT00` (sin reintento → DLQ → `failed`). Fijar al desplegar `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=600` y `AUDIT_BATCH_LOCK_TTL_SECONDS=3600` y `AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS=3600000`, de modo que `4 · 600 + 36 = 2436 < min(3600, 3600)` (inecuación `DEC-12`, validada por `BatchRequestedWorker::__construct()`); subir `AUDIT_JOB_STALE_AFTER_SECONDS` solo si `max(batchLockTtlSeconds(), 3600)` lo exige. Los defaults de código (`300`/`1800`) no cambian. Medir `Executed SQL audit batch candidates` (duración) tras el primer ciclo y reevaluar; la optimización de `#DISP..#Sopo` sigue fuera de alcance (Sección 2).
   - **`SUP-05` (`R6-F2-I1`):** si el corte de `~3,8 h` fue un descarte silencioso de TCP por un equipo intermedio, no está demostrado que `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` despierte el socket; mitigación operativa recomendada, sin código de aplicación: `tcp_keepalive_time` bajo (p. ej. `120 s`) en el host/contenedor `worker-batch`, verificada en la ventana de validación; el resultado se anota en `SUP-05` antes del cutover.

#### Ejecución (en `.github/workflows/deploy-production.yml` con `operation=deploy`)

1. `Ensure Redis is running` (`appendonly=yes`, `appendfsync=always`, `maxmemory-policy=noeviction`).
2. **Antes de actualizar `worker-extraction-*` (`L543`)**: activar pausa (`ctl pause`), esperar finalización de cualquier cron `schedule-daily-batches.php` en curso y ejecutar `docker compose stop -t 30 php worker-batch worker-orchestrator worker-downloader worker-extraction-vip worker-extraction-batch worker-normalizer worker-policy worker-persistence worker-semantic-vip worker-semantic-batch worker-execution-maintenance` (drena peticiones FPM activas y detiene `100 %` de escritores; `worker-downloader` se renombra en `SDD-BATCH-002`, que debe actualizar esta lista).
3. Ejecutar con `${PHP_IMAGE}:${IMAGE_TAG}` migración idempotente + limpieza de locks inertes de trabajadores detenidos:
   `docker compose run --rm --no-deps -T --entrypoint php php bin/audit-execution-maintenance.php migrate-batch-v2 --clear-claims`.
4. Ejecutar reparación explícita y verificar consistencia:
   `docker compose run --rm --no-deps -T --entrypoint php php bin/audit-execution-maintenance.php repair-jobs` y luego `reconcile`.
5. Ejecutar `Upgrade extractors before bounded cache producers` y luego levantar stack completo actualizado (`php`, `nginx`, `frontend`, `docs`, y todos los `worker-*`).
6. Esperar activamente hasta `30 s` a que `audit:execution:heartbeat` exista con edad `< HEARTBEAT_MAX_AGE_MS` (`30.000 ms`) y ejecutar `resume`.

#### Validaciones Previas

- `php bin/audit-execution-maintenance.php durability-check` devuelve código `0` (`appendonly=yes`, `appendfsync=always`, `maxmemory-policy=noeviction`).
- Conteo de claves previo registrado (`ZCARD jobs:index`, `ZCARD audit:outbox:v2:pending`, jobs no terminales y su `job_id`).

#### Validaciones Posteriores

- `php bin/audit-execution-maintenance.php reconcile` finaliza sin excepción `job_totals_inconsistent`.
- `0` claves residuales `audit:reservation:disid:*`, `0` fallos de identidad en `migrate-batch-v2` y `STRLEN(job:*:state) < 2048` en todos los jobs.
- Ningún job previamente `completed*` quedó con `sealed = false` (`R-SEAL`); los jobs con `generation_abandoned = true` se listan en el log de migración.
- `GET /audit/jobs` y `GET /metrics/async` responden `HTTP 200` con `executionMaintenance.heartbeatAgeMs < 30.000` (objetivo operativo `< 5.000`) dentro de los primeros `30 s`.
- Ausencia de `Fatal error: Allowed memory size` en logs de `worker-execution-maintenance`.

#### Rollback (`DEC-11`, forward-fix only; `workflow_dispatch` con `operation=rollback` e `image_tag=<sha_anterior>`)

**Acciones del operador (únicas):** (1) *opcional*, si necesita contención inmediata, `ctl pause` sobre el stack v2 (`docker compose run --rm --no-deps -T --entrypoint php php bin/audit-execution-maintenance.php pause` en `$DEPLOY_PATH`) — **este es el paso con SLA de `10 min`**; (2) disparar `deploy-production.yml` con `workflow_dispatch`, `operation=rollback` e `image_tag=<sha_anterior>`. El operador **no** detiene contenedores ni ejecuta `rollback-batch-v2` a mano (`W-F2-B5`).

**Acciones del workflow** (Sección 13, rama `OPERATION == 'rollback'`):
1. Antes de `Prepare deployment directory` (`L101`), sobre el compose y `.env` v2 vigentes en `$DEPLOY_PATH`: guarda `AuditDocumentInput.php` sobre la imagen destino, asegurar `redis`, `ctl pause` (idempotente si el operador ya pausó), espera de cron, `docker compose stop -t 30 php worker-*` y `ctl rollback-batch-v2` (todas las invocaciones con `docker compose run --rm --no-deps -T --entrypoint php php ...`).
   - Código `!= 0` (`rollback_requires_no_active_jobs` / `rollback_requires_no_active_reservations`): **no se revierte la imagen**. El workflow relanza el stack v2 desde el mismo `$DEPLOY_PATH` sin tocar `.env`, conserva `pause` y termina con `exit 1`; se corrige hacia adelante (hotfix sobre v2). Si es imprescindible liberar al pipeline antes del hotfix, un operador puede cerrar jobs con `repair-jobs --force-stale=<jobId>` (cancela sus auditorías vivas, `DEC-10`) y volver a disparar el workflow.
   - Código `0`: continuar.
2. Solo tras código `0`: `L101`/`L109`/`L474` con `<sha_anterior>` y despliegue de sus contenedores omitiendo pasos exclusivamente v2 (`migrate-batch-v2`, `repair-jobs`).
3. `ctl reconcile` (si aplica al tag), espera de heartbeat `< 30.000 ms` y `ctl resume`.

---

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Lote sin candidatas en SQL Server (`snapshot === []`) o todas las candidatas bloqueadas en job multichunk (`total == 0` al llegar al último chunk) | `publishEmptyBatch` / `SEAL_JOB_LUA` marca `status=completed`, `sealed=true`, `total=0`, `accepted=0`, remueve de `jobs:active:nit:{nit}`, elimina `candidates` y actualiza `telemetry:async_metrics` (`R-EMPTY`). | `GET /audit/jobs/{id}` muestra `completed`, `total=0` y permite nuevo job inmediatamente. |
| `limit` mayor al número real de candidatas en ventana (`limit=30000`, SQL retorna `8400`) | `candidates_count = 8400`; al llegar `$offset >= 8400`, última pieza sella job con `total=8400` y borra `candidates`. | `total == accepted == HLEN(job:{id}:audits) == 8400`. |
| Fecha `DisFecSol` con componente horario (`2026-11-18 23:59:50.000`) igual a `dateTo` | `< DATEADD(day, 1, CONVERT(datetime, '2026-11-18', 23))` evalúa `< 2026-11-19 00:00:00`, incluyendo el registro. | `InvoicesModelTest` valida la cadena SQL con `FakePdo`; la semántica T-SQL se respalda en `0.4` y se verifica manualmente en QA con una fila `23:59:59.000`. |
| Mismo `DisId` compartido entre `Dispensa` `'D1'` y `'D2'` | Reservas separadas `audit:reservation:disdetnro:D1` y `audit:reservation:disdetnro:D2`; ambas se aceptan y encolan. | `accepted = 2`, `skipped_locked = 0`. |
| `DisDetNro` ya en curso desde `/audit/async` paralelo o residual bloqueado (distinto `job_id`) | Falla `claimAuditReservation($disDetNro)`, verifica `existing['job_id'] !== $jobId`, cuenta `skipped_locked++`, avanza offset sin re-ejecutar SQL. | `invoicesModel->getCalls() === 1`; `skipped_locked = 1`. |
| Lock ocupado (`JobLockClaimStatus::BUSY`) o `JobLockClaimStatus::MISSING` o valor desconocido | Ante `BUSY`, `AuditBatchOrchestrator` retorna `locked_busy=true`, `BatchRequestedWorker` lanza `JobGenerationBusyException`, y `AuditEventConsumer` retorna sin `XACK` ni incrementar intentos DLQ. Ante `MISSING` lanza `503`; valor desconocido lanza `invalid_generation_lock_claim_response`. | Mensaje permanece en PEL para reentrega y `attempts` = `0`. |
| **Flujo normal (`R-CONT`):** Worker A comprometió `k`, liberó el lock y publicó `k+1`; Worker B consume `k+1` y adquiere el lock (`epoch E → E+1`) **antes** de que A llame `markContinuationDispatched(k)` | La llamada de A no valida epoch: avanza `last_continuation_dispatched_chunk = k` (o no-op si el claim de B ya lo promovió). A hace `XACK`. | `0` excepciones `409`, `0` continuaciones duplicadas, `attempts = 0` en el mensaje de `k`. |
| Worker A pierde lock tras congelación y `XAUTOCLAIM` entrega a Worker B (`epoch` `1 -> 2`); luego Worker A despierta e intenta mutar, o su mensaje entra en DLQ con `epoch=1` | Worker B reanuda desde `next_candidate_offset`; toda escritura de generación o `markJobFailed` con `epoch=1` falla atómicamente con `generation_fence_stale` sin alterar `total`, continuaciones ni estado del job (`H3`). | `total == HLEN(audits)`, `done + failed <= total`, cero cierres prematuros ni muertes falsas por DLQ obsoleto. |
| `batch_requested` agota reintentos con un error lanzado **antes** de adquirir el lock (sin epoch) | `finalizeDeadLetterAudit()` registra `batch_requested_dlq_without_epoch` y no muta el job; si el job no progresa, `REAP_STALE_JOB_LUA` lo cierra tras `6 h`. | El job no pasa a `failed` por DLQ sin epoch; NIT liberado como máximo `6 h` después del último progreso. |
| Worker A cae tras `claimAuditReservation` o tras `registerAuditInJob` pero ANTES de `publish(audit_created)` (o después de completar y liberar reserva antes de checkpoint) | Worker B toma `epoch=2` y recorre el mismo slice con UUIDs deterministas: si `by_dispensa[ddn]` falta y la reserva es del mismo `(jobId, auditId)` completa registro+publicación; si `dispatched==false` republica `audit_created` faltante y marca `dispatched=true`; si `dispatched==true` omite aunque la reserva ya haya sido liberada (`R-PREPUB`). | Exactamente `N` auditorías únicas en `job:{id}:audits` y `by_dispensa`, `0` faltantes y `0` duplicados. |
| Worker A cae DESPUÉS de `commitChunkCheckpoint(k)` y ANTES de `publishContinuationEvent` | Al reentregar `k`, `resolveIdempotentEarlyResponse` detecta `last_committed_chunk >= k` y `last_continuation_dispatched_chunk < k` y `!sealed`; toma lock y devuelve `has_more = true`, `last_cursor`, `generation_epoch` para emitir la continuación determinista y confirmar `markContinuationDispatched()` antes de `XACK`. | Chunks `k+1..N` se publican sin quedar varados ni duplicar deltas `skipped_*`. |
| Worker A cae DESPUÉS de publicar `k+1` y ANTES de `markContinuationDispatched(k)` | El claim de `k+1` (`chunk_index = k+1`) promueve `last_continuation_dispatched_chunk = k`; la reentrega de `k` resuelve por respuesta temprana sin republicar. Si la reentrega de `k` ocurre antes que el claim de `k+1`, se republica `k+1` (duplicado en stream, `AuditEventPublisher.php:179`) y el duplicado resuelve por respuesta temprana. | `0` auditorías duplicadas; a lo sumo `1` evento `batch_requested` duplicado sin efectos. |
| Todas las auditorías completan antes de ejecutar `sealJob()` | `SEAL_JOB_LUA` detecta `done + failed >= total` al sellar, cierra status → `completed`/`completed_with_errors`, fija `completed_at` y libera `jobs:active:nit:{nit}`. | Job nunca queda atascado en `processing`. |
| Migración de un job v1 `completed*` con auditorías aún pendientes (`processed < actualTotal`, p. ej. `a645fccc`) | `MIGRATE_JOB_KEY_LUA` fija `status = processing` **conservando `sealed = true`** (`R-SEAL`); `MARK_AUDIT_COMPLETED_IN_JOB_LUA` lo cierra al completar las restantes; si no progresan, `REAP_STALE_JOB_LUA` lo cierra tras `6 h`. | Job nunca queda en `processing` indefinidamente; NIT liberado. |
| Migración de un job v1 `sealed = false` sin `batch_requested` pendiente en `audit.batch.inbox` (generación abandonada) | Se marca `generation_abandoned = true` y `last_progress_at = now`; si no progresa, `REAP_STALE_JOB_LUA` lo cierra tras `6 h`. | `jobs:active:nit:{nit}` liberado como máximo `6 h` tras la migración. |
| Job activo sin progreso `>= 6 h` y sin `batch:claim:{jobId}` (`R-STALE`) | `gc()` invoca `REAP_STALE_JOB_LUA`: auditorías no terminales → `failed` (`failed_stage = stale_no_progress`) con liberación de sus reservas, job → `failed` con `stale_reason`, `HDEL jobs:active:nit`, telemetría ajustada. | `done + failed == total`; nueva solicitud del NIT aceptada (`202`). |
| Job activo sin progreso `>= 6 h` **con** `batch:claim:{jobId}` vivo | El reaper no actúa (propietario de generación vivo); reintenta en ciclos posteriores tras expirar el lock (`1.800 s`). | Job intacto mientras exista el lock. |
| Auditoría cancelada por el reaper que termina después | `MARK_AUDIT_COMPLETED_IN_JOB_LUA` detecta job terminal / `stale_reason` y no muta `status`, contadores ni `jobs:active:nit` (`INV-08`); persistencia SQL idempotente por `FacNro`. | Job permanece `failed`; sin reapertura. |
| Consulta de congelación excede `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` (`HYT00`) | `SqlServerOperationException` envuelta en `JobGenerationFailedException(epoch)`; `$nonRetryable` la reconoce por `getPrevious()` → DLQ **inmediata, sin reintentos** → `markJobFailed(epoch)` libera el NIT; `0` auditorías parciales. | `batch_requested` en `audit.dlq` con `HYT00`; job `failed`; NIT desbloqueado. |
| Configuración con `4·T + 36 >= min(lockTtl, reclaimIdle)` | `BatchRequestedWorker` no arranca (`batch_freeze_timeout_exceeds_lock_window`). | Log de error de arranque; contenedor reinicia sin procesar mensajes. |
| Dos solicitudes con fechas colindantes vs solapadas (`[2026-01-01, 2026-06-30]` activa; entra `[2026-07-01, 2026-07-31]` vs `[2026-06-15, 2026-07-31]`) | `[2026-07-01, 2026-07-31]` no se solapa y es aceptada (`202`); `[2026-06-15, 2026-07-31]` se solapa y recibe `HTTP 409` en API (o se omite en cron con NIT enmascarado). | API devuelve `202` en contigua no solapada y `409` en solapada. |
| `TYPE_BATCH_REQUESTED` falla tras `maxRetries` en el worker propietario vigente (con epoch) y pasa a DLQ | `finalizeDeadLetterAudit()` invoca `markJobFailed($jobId, $msg, $epoch, $failedChunk)`, cierra status → `failed` (nunca promovido luego por `MARK_AUDIT_COMPLETED_IN_JOB_LUA`), libera `jobs:active:nit:{nit}`, limpia `candidates`. | NIT queda desbloqueado; `jobs_failed` incrementado en `telemetry:async_metrics`. |
| `gc()` alcanza su presupuesto a mitad de `HSCAN` de un job masivo (`50.000` auditorías) | Guarda `hscan_job_id`, `hscan_cursor` y re-encola el job al frente de `pending_keys`; continúa en ciclos siguientes. | Sin Fatal Error `512M`; job recolectado completamente tras `N` ciclos. |
| `rollback-batch-v2` con jobs activos o reservas `disdetnro:*` | Aborta con código `1` sin mutar claves (`DEC-11`); el workflow de rollback relanza el stack v2 desde `$DEPLOY_PATH` sin reescribir `.env` ni `docker-compose.yml` y termina con `exit 1`. | `0` claves modificadas; stack v2 en `pause`; `.env` conserva el tag v2. |
| Despliegue automático por `workflow_run` (sin `inputs.operation`) | `env.OPERATION` vale `deploy`; se ejecutan barrera, migración y arranque normales. | Steps `deploy` ejecutados; steps `rollback` omitidos. |
| Worker A (`epoch = 1`) pierde el lock; Worker B (`epoch = 2`) reutiliza `A1`/`T1` deterministas, publica `audit_created(A1)`; A falla luego con `409 generation_fence_stale` sin haber publicado | `cleanupAsyncEnqueueState()` (caso b) no borra `audit:{A1}:state` ni la reserva `disdetnro:D1` (`W-F2-B1`). | La auditoría de B continúa; `ADMIT` no devuelve `audit_state_missing`. |
| Chunk con `$externalJobId` registra `D1..D4` (`dispatched = false`) y falla con `503` en `D5` antes de publicar | La compensación (caso c) conserva estado y reservas de `D1..D4`; el reintento republica con estado intacto; si faltara `audit:{id}:state`, Step 5 recrea reserva + estado antes de publicar. | `4` `audit_created` admitidos sin `audit_state_missing`; `0` duplicados. |
| Worker con `epoch` igual al del job despierta tras un `REAP_STALE_JOB_LUA` (o durante `reaping`) e invoca `PUBLISH_EMPTY_BATCH`, `SEAL_JOB`, `STORE_FROZEN_CANDIDATES`, `MARK_AUDITS_DISPATCHED` o `COMMIT_CHUNK_CHECKPOINT` | Todos retornan `-2` sin mutar (guarda de generación); además el reaper incrementó `generation_epoch` (`W-F2-B3`). | Job sigue `failed`, `job:{id}:candidates` inexistente, telemetría sin doble transición, `0` continuaciones publicadas. |
| Reaper cancela auditorías `pending` o `processing` | `audit:{id}:state` pasa a `failed` (`stale_no_progress`, ISO, sin `EX`, `documents` intactos) (`W-F2-B4`). | `gc()` recolecta `audit:*:state` y claves derivadas tras la retención; reservas liberadas. |
| `batch_requested` en DLQ con epoch tras publicar parte del chunk (`A1..A4` publicados, `A5..A10` registrados sin publicar) | `markJobFailed()` → `true` → `cancelUndispatchedAudits()` lleva `A5..A10` (y `A1..A4` si aún `dispatched = false`) a `failed` (`batch_dlq_unpublished`), decrementa `audits_in_flight` y libera sus reservas (`W-F2-B4`). | `0` reservas `disdetnro:*` retenidas por el job; `audits_in_flight` sin fuga. |
| Petición manual `POST /audit/async` con `limit > 10000` o `dateTo < date` | Sigue bloqueada por `Validator` (`422`), igual que hoy. | Respuesta `422` sin llegar a Redis. |

---

### 16. Testing

#### Nuevos Tests

1. **`AuditBatchOrchestratorTest`:**
   - `testFreezeExecutesSqlOnceAndChunksFromCandidatesAndEnforcesFencingEpoch`: 5 candidatas (incluyendo `2` hermanas del mismo `DisId` y distinta `Dispensa`), `chunk_size = 2`; valida `invoicesModel->getCalls() === 1`, que la llamada recibe `queryTimeoutSeconds = freezeQueryTimeoutSeconds()`, renovación del lock antes y después de la consulta, ambas `Dispensa` aceptadas por `DisDetNro`, rechazo de epoch obsoleto, log `batch_generation_sql_ms` con `fac_nit_sec_masked` (`'***624'`) y cierre `total = 5`.
   - `testRecoveryBeforePublishAndAfterReservationReleasePreservesUniqueDeterministicAudits` (`R-PREPUB`): (a) reserva existente con mismo `(jobId, auditId)` antes de `registerAuditInJob`, (b) entrada `by_dispensa` con `dispatched = false` recupera y publica `audit_created` con igual `auditId`, (c) entrada `dispatched = true` tras liberar reserva NO re-audita ni incrementa `skipped_locked`.
   - `testMultiChunkAllSkippedClosesJobWithTotalZero` (`R-EMPTY`).
   - `testClaimLockHandlesTerminalBusyMissingAndInvalidStatuses` (`R-EPOCH`).
   - `testTerminalClaimDuringReapingReturnsNonNullTerminalResponse` (`V-F2-B4`).
   - `testInitAuditStateAcceptsExistingStateOfSameJobAfterCrash` (`V-F2-B5`).
   - `testFinalChunkWithZeroAcceptedUsesSealJobAndKeepsAccumulatedSkipped` (`V-F2-I4`).
   - `testExceptionAfterAcquireIsWrappedWithEpoch` (`INV-02`): `SqlServerOperationException('HYT00')` tras `ACQUIRED` se propaga como `JobGenerationFailedException` con `epoch` del claim.
   - `testFencingFailureCleanupNeverDeletesStateOrReservationOfLiveAudit` (`W-F2-B1`a): `registerAuditInJob` de `D2` lanza `409 generation_fence_stale` tras registrar `D1`; el doble verifica `0` llamadas a `deleteAudit()`/`releaseAuditReservation()` y `0` a `deleteJob()`.
   - `testExternalJobRetryAfter503KeepsRegisteredRowsAndRecreatesMissingState` (`W-F2-B1`b): con `$externalJobId`, `503` en `D5` tras registrar `D1..D4` conserva su estado/reservas; en el reintento, si `getAudit(A1) === null`, Step 5 re-reclama la reserva e invoca `initAuditState()` antes de publicar; sin reserva recuperable no publica y registra `batch_prepub_reservation_lost`.
   - `testFirstChunkNonExternalFailureStillCleansUpFully` (`W-F2-B1`): `!$externalJobId && chunkIndex === 1` sin fencing → `deleteJob()` + `deleteAudit()` + `releaseAuditReservation()`.
   - `testGenerationLuaRejectionAfterReapIsTreatedAsFencing` (`W-F2-B3`): `sealJob`/`publishEmptyBatch`/`commitChunkCheckpoint`/`markAuditsDispatched` → `false` con epoch coincidente producen `409` sin publicar continuación.
   - `testEmptySnapshotOnLaterChunkSealsInsteadOfPublishEmptyBatch` y `testBatchCreatedEmittedByFirstChunkThatAccepts` (`W-F2-M4`).
2. **`AuditControllerTest`:**
   - `testAsyncReturns409WhenActiveJobOverlapsSameNitAndDateRange` y `testAsyncAllowsNonOverlappingDateRangeForSameNit`.
   - `testAsyncCompensationFailureStillReturns503` (`W-F2-M6`): `deleteJob()`/`releaseIdempotencyKeyIfMatches()` lanzando `RuntimeException` dentro de la compensación no cambia la respuesta `503`.
3. **`BatchRequestedWorkerTest`** (invocando `$worker->processEvent()` y restaurando `AUDIT_BATCH_CHUNK_SIZE` en `finally`):
   - `testLockContentionThrowsJobGenerationBusyAndLeavesMessagePendingInConsumer`.
   - `testContinuationConfirmedWithoutEpochAfterNextChunkClaimed` (`R-CONT`): entre `publish(k+1)` y `markContinuationDispatched(k)` se simula el claim de `k+1` (epoch `E+1`); verifica `0` excepciones, `last_continuation_dispatched_chunk = k` y `XACK` de `k`.
   - `testCheckpointContinuationCrashRecoversPendingContinuationBeforeAck`.
   - `testDeadLetterBatchRequestedRespectsEpochFencingAndMarksActiveJobFailed`: con epoch vigente marca `failed`; con `epoch = 1` frente a job en `epoch = 2` no lo destruye; **sin epoch** no llama `markJobFailed` y registra `batch_requested_dlq_without_epoch`.
   - `testWorkerRefusesToStartWhenFreezeTimeoutExceedsLockWindow` (`DEC-12`).
   - `testContinuationCarriesNextCandidateOffsetAndNeverRereadsSlice` (`V-F2-B3`).
   - `testSqlTimeoutWrappedIsNonRetryableAndGoesToDlqOnFirstAttempt` (`V-F2-B7`).
   - `testDeadLetterCancelsUndispatchedAuditsOnlyWhenJobWasClosed` (`W-F2-B4`b): `markJobFailed()` → `true` invoca `cancelUndispatchedAudits($jobId, 'batch_dlq_unpublished')`; → `false` con job `processing` o `failed` con `stale_reason` no la invoca; → `false` con job `failed` sin `stale_reason` sí la invoca (reentrega, `X-F2-I2`, `R4-F2-I3`); la lectura usa `getJobSummary()`, nunca `getJob()`.
   - `testFreezeTimeoutConfigEmptyStringUsesDefaults` (`W-F2-M9`, `R5-F2-B1`, `R5-F2-M3`): `AUDIT_BATCH_LOCK_TTL_SECONDS=''` → `batchLockTtlSeconds() === 1800` y `jobStaleAfterSeconds() === 21600` (no lanza `InvalidArgumentException`); `AUDIT_JOB_STALE_AFTER_SECONDS=''` → `21600`; `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=''` → `300`; `'abc'` → `InvalidArgumentException`. Todos los tests de configuración que muten variables de entorno deben restaurarlas mediante `Env::set()` / `Env::clearCache()` en un bloque `finally` (`R5-F2-M3`, `Core\Env::$cache`).
4. **`InvoicesModelTest`:** `testBatchQueryPassesQueryTimeoutAttributeToPrepare` (`PDO::SQLSRV_ATTR_QUERY_TIMEOUT` presente solo cuando se pasa `$queryTimeoutSeconds`).
5. **`AuditMemoryCompactionAndGcTest`** (delta Fase 2): `testGcResumesJobAuditsHscanAcrossBudgetCuts` (job de `50.000` auditorías, `memory_get_usage(true) < 256 MiB`, con `hscan_sha1` persistido entre ticks y borrado abortado si el `sha1` cambia, `W-F2-M2`), `testGcInvokesReaperForStaleNonTerminalJobsWithinBudget`, `testSurvivingLegacyReservationDoesNotLoopAndDisdetnroIsStillCollected` (`W-F2-I3`: `1` clave `disid:*` con auditoría viva; en el mismo `gc()` se recolectan las `disdetnro:*` huérfanas y `SCAN` del patrón legado se invoca una sola pasada), `testFinallyDoesNotSkipReservationsOrJobsPatternOnException` (`W-F1-I3`, `R5-F2-I1`: excepción en la última clave de una página intermedia `disid:*` no avanza a `6`; excepción en la última clave ya extraída de la **página final** de `disid:*` fija `legacy_pass_done = '1'` sin avanzar `pattern_index` para continuar en `disdetnro:*` en el siguiente tick; corte del reaper con `reap_job_id` no avanza a `5`), `testPendingReapJobResumedAtGcStart` (`W-F2-M7`) y `testPreLoopReapResumeBudgetCutPreservesPendingKeys` (`R5-F2-B2`: reanudar `reap_job_id` antes del `while` y agotar presupuesto sin entrar al bucle mantiene intactos `pending_pattern` y `pending_keys` en `audit:execution:gc:cursors`).
6. **`tests/Core/LoggerSanitizationTest.php`** (NEW): redacción de `fac_nit_sec` en `Core\Logger` y ausencia de `'2624'` crudo en contexto de log de `schedule-daily-batches` / `AuditBatchOrchestrator` (`R-PII`).
7. **`tests/Integration/AuditStaleJobReaperRedisTest.php`** (NEW, `RUN_REDIS_INTEGRATION=1`):
   - `testReaperWritesIsoTimestampsUsesKeyPrefixAndSkipsJobsWithoutProgressMark` (`V-F2-B1`, `V-F2-B2`; con `IsolatedAuditRedis` y prefijo no vacío).
   - `testReapingJobResumedImmediatelyAfterCrashAndResume` (`V-F2-I8`).
   - `testMarkJobFailedReturnsFalseOnStaleEpochAndDuringReaping` (`V-F2-I5`).
   - `testReapsStaleJobCancelsLiveAuditsReleasesReservationsAndUnblocksNit`: job `processing` con `last_progress_at = now - 6 h - 1 s`, `3` auditorías (`1 completed`, `2 pending` con reservas `disdetnro`); tras el reaper: job `failed`, `stale_reason = stale_no_progress`, `done = 1`, `failed = 2`, reservas del job borradas, `jobs:active:nit` sin el job, telemetría `jobs_running -1`, `jobs_failed +1`, `audits_in_flight -2`.
   - `testDoesNotReapWhenBelowThresholdOrGenerationLockAlive`.
   - `testLateCompletionDoesNotReopenReapedJob` (`INV-08`).
   - `testForceStaleCliUsesZeroThresholdButRespectsClaim`.
   - `testReapInSlicesBlocksRegisterClaimAndCompletionCloseUntilFinalSlice` (job de `1.200` auditorías, `HSCAN COUNT 500`): entre rebanadas, `REGISTER_AUDIT_IN_JOB_LUA` rechaza (`job_closed_or_reaping`), el claim devuelve `terminal`, una finalización no cierra el job; la telemetría de job se ajusta una sola vez.
   - `testSealedProcessingMigratedJobIsEligibleForReap` (terminal ⇔ `status`; `sealed` no cuenta).
   - `testReaperSkippedWhilePausedAndThresholdMeasuredFromResume` (`audit:execution:paused = 1`; `resumed_at_ms`).
   - `testAuditsInFlightDecrementsForLateCompletionOnTerminalJob` (sin fuga de `audits_in_flight`).
   - `testReaperMarksLiveAuditStatesFailedPreservingDocuments` (`W-F2-B4`a): `audit:{id}:state` `pending`/`processing` → `failed`, `failed_stage = stale_no_progress`, `completed_at`/`updated_at` ISO, `TTL = -1`, `documents` intacto; `FairAuditTaskQueue::admit()` posterior hace `XACK` y retorna `2`; `gc()` tras la retención recolecta el estado.
   - `testGcReleasesOrphanReservationOfTerminalJob` (`X-F2-B1`): reserva `disdetnro:*` de `claimed_at = now - 61 s` + `audit:{id}:state` `pending` sin `EX`, con `job_id` de un job `failed` que **no** tiene la fila en `job:{id}:audits`; tras `gc()`: reserva borrada, estado `failed` con `failed_stage = orphaned_job_terminal` y `documents` intacto, contadores del job sin cambios. Variantes: job inexistente ⇒ liberada igual que el caso base; job `processing` ⇒ sin cambios; job `completed` con la fila registrada ⇒ sin cambios; job `failed` (sin `stale_reason`) con la fila registrada en `job:{id}:audits` (`dispatched = true` en vuelo) ⇒ sin cambios: ni borra la reserva ni marca `failed`, y `audits_in_flight` intacto (`R4-F2-B1`); reserva reescrita entre lectura y Lua ⇒ `0`.
   - `testLegacyReservationPassSurvivesBudgetCutAcrossTicks` (`X-F2-B3`): `disid:*` en 3 páginas con una clave viva en la 1.ª; corte de presupuesto tras la 1.ª; en el tick siguiente se completan las páginas de `disid:*` (no salta al índice `6`), `legacy_done` queda `'0'` (por `legacy_saw_keys = '1'`) y se recorre `disdetnro:*` antes de avanzar.
   - `testCancelUndispatchedAuditsReleasesReservationsAndInFlight` (`W-F2-B4`b): job `failed` con `10` entradas `by_dispensa` (`4 dispatched = true`, `6 false`): las `6` pasan a `failed` (`batch_dlq_unpublished`) en estado y Hash, `audits_in_flight -6`, reservas del job liberadas, reservas ajenas intactas; 2.ª ejecución cancela `0`; con job no `failed` no muta nada.
   - `testReapIncrementsEpochAndGenerationLuaRejectAfterReap` (`W-F2-B3`): tras el reaper (`epoch` `1 → 2`), `PUBLISH_EMPTY_BATCH_LUA`, `SEAL_JOB_LUA`, `STORE_FROZEN_CANDIDATES_LUA`, `MARK_AUDITS_DISPATCHED_LUA` y `COMMIT_CHUNK_CHECKPOINT_LUA` con `expectedEpoch = 1` **y** con `expectedEpoch = 2` retornan `-2` sin mutar; durante `reaping` (`sealed = false`) `SEAL_JOB_LUA` también retorna `-2`.
   - `testCompletionDuringReapingIsNoOpAndFinalSliceDoesNotRescan` (`W-F2-I1`): finalización de una auditoría entre rebanadas no muta `job:{id}:audits` ni `audits_in_flight`; al cerrar `done + failed == total`.
   - `testForceStaleClosesJobWithoutProgressMarkAndToleratesNullTimestamp` (`W-F2-I2`): `last_progress_at = cjson.null`/`0` no aborta el script; con umbral `> 0` no actúa; con `--force-stale` (umbral `0`) cierra, salvo `batch:claim` vivo.
   - `testInitExclusiveAndCloseScriptsHonorNonEmptyPrefix` (`W-F2-B2`, `IsolatedAuditRedis` con prefijo no vacío): `INIT_JOB_EXCLUSIVE_LUA` rechaza un solapamiento con un job activo existente (no lo poda); `SEAL_JOB_LUA`, `PUBLISH_EMPTY_BATCH_LUA`, `MARK_JOB_FAILED_LUA` y `DELETE_JOB_LUA` eliminan la entrada de `<prefix>jobs:active:nit:{nit}` y no crean claves sin prefijo (`SCAN` de `jobs:active:nit:*` fuera del prefijo vacío); `DELETE_JOB_LUA` descuenta `audits_in_flight` y el contador del estado leído.
8. **`AuditExecutionMigrationRedisTest`** (`RUN_REDIS_INTEGRATION=1`, Redis 7 real aislado):
   - `testMigrateBatchV2RepairsZeroAuditAndPreservesFailedSealedJob`: `0`-audits (`total=5 -> 0`), `status='failed'` sellado con `1` auditoría completada, reparación tipo `a645fccc`, normalización `batch:claim:*`, aborto `-1` si reserva sin `dis_det_nro`, `reconcile()` limpio y compatibilidad `FairAuditTaskQueue::admit()`.
   - `testMigrateKeepsSealedCompletedJobWithPendingAuditsAndClosesOnCompletion` (`R-SEAL`): job v1 `completed`, `sealed = true`, `3` auditorías `1` pendiente → tras migrar `processing` + `sealed = true`; completar la pendiente lo cierra y libera `jobs:active:nit`.
   - `testMigrateFlagsAbandonedGenerationWithoutPendingBatchRequested`.
   - `testRegisterAuditInJobIncrementsInFlightOnlyOnce` (`V-F2-I7`).
   - `testRollbackIgnoresV1JobsWithExecutionVersion2AndCleansPhase2CursorFields` (`V-F2-M3`, `W-F2-M5`: también revierte un estado v2 que solo tiene `stale_reason`/`reaping`/`generation_abandoned`/`error_message`; limpia los `9` campos de Fase 2, incluidos `hscan_sha1`, `legacy_pass_done` y `legacy_saw_keys`; `R4-F2-I2`).
   - `testRepairJobsDoesNotResetLastProgressAt` (`W-F2-M3`).
   - `testMigrateCliExpiresLegacyIdempotencyAfterMigration` (`W-F1-I4(a)`): la salida de `migrate-batch-v2` incluye `legacy_idempotency.expired`; `BatchJobStore` no referencia `AuditExecutionMaintenance`.
   - `testRollbackBatchV2IsReentrantAtRestAndAbortsWithActiveJobs` (`R-ROLL`): en reposo, `2` ejecuciones consecutivas (`reverted_jobs = 0` en la 2ª); con `1` job `processing` o `1` reserva `disdetnro:*`, aborta con código `1` y `0` claves modificadas.
   - `testConcurrentXAutoClaimCrashRecoveryContinuationAndDispensaDeduplication`: (a) expiración de lock + `XAUTOCLAIM` real + rechazo `generation_fence_stale` de Worker A obsoleto, (b) caída post-publicación + liberación de reserva sin duplicar auditoría, (c) caída post-checkpoint antes de continuación, (d) mensaje pendiente v1 con `cursor` continuado sin duplicados tras `migrateBatchV2()`, (e) claim de `k+1` antes de confirmar `k` (`R-CONT`) en Redis real.

9. **`tests/Integration/AuditExecutionMaintenanceTickRedisTest.php`** (creado por SDD-BATCH-000, `RUN_REDIS_INTEGRATION=1`; `W-F1-I5`): `testHeartbeatMetaIncludesJobsReapedAndResetsPerTick` — tras un `tick()` cuyo `gc()` cierra `1` job, `audit:execution:heartbeat:meta` contiene los 10 campos base de `T-I7`/`CA-F1-07` **más** `jobs_reaped = 1`; el siguiente `tick()` sin `gc()` publica `jobs_reaped = 0` (`W-F1-M7`).
10. **Verificación del workflow** (`.github/workflows/deploy-production.yml`, revisión estática en el PR + ejecución en `nodo1`; sin archivo de test PHP): `env.OPERATION` presente con el valor por defecto `deploy` para `workflow_run`; todos los steps exclusivos condicionados por `env.OPERATION`; el step de rollback está antes de `Prepare deployment directory`, usa `docker compose run --rm --no-deps -T --entrypoint php php` y contiene la rama `exit 1` que relanza v2 sin escribir `.env` (`W-F2-B5`).

#### Tests Modificados

- `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php`: aserción de `jobs_reaped` y su reinicio por tick (punto 9).
- `tests/Models/InvoicesModelTest.php`: expectativa SQL `d.DisFecSol >= CONVERT(datetime, :dateFromD, 23) AND d.DisFecSol < DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))` (L30, L62), firma opcional `$jobId` y `$queryTimeoutSeconds`.
- `tests/Controllers/AuditControllerTest.php`: mock de `testPausedAdmissionReturns503BeforeCreatingAuditOrIdempotencyKey` (`L132`, `method('initJob')` → `method('initJobExclusive')`, `X-F2-B2`), clase anónima `testAsyncReturns503WhenInitJobFails` (`L236` → `initJobExclusive`) y `StubBatchJobStore` (`L778` `initJobExclusive`, `L808` `sealJob` con nueva firma, `releaseIdempotencyKeyIfMatches`, `deleteJob`, parámetros `DisDetNro`); `T-U4` de SDD-BATCH-000 adaptado: `StubBatchJobStore` conserva `public bool $claimIdempotencyKeyThrowsInvalid = false;` y la aserción «sin `initJob`» pasa a «sin `initJobExclusive`» (`W-F1-M7`).
- `tests/Services/Audit/AuditBatchOrchestratorTest.php`: doble `BatchOrchestratorInvoicesModel::getInvoicesForAuditBatch()` (`L368-374`) con la firma de 7 parámetros (`?string $jobId = null`, `?int $queryTimeoutSeconds = null`); `BatchOrchestratorJobStore` (`L395-489`: retirar `initJob`/`claimJobGenerationLock`, implementar `claimJobGenerationFencedLock(jobId, workerId, chunkIndex, ttl): JobLockClaimResult`, `storeFrozenCandidates`, `readFrozenCandidates`, `getBatchDispensaEntry`, `markAuditsDispatched`, `commitChunkCheckpoint`, `markContinuationDispatched(jobId, chunkIndex)`, `sealJob`, `publishEmptyBatch`, conservar `patchJob`).
- `tests/Services/Audit/Events/BatchRequestedWorkerTest.php`: `ReflectionMethod('handle')` (`L177`) → `$worker->processEvent($initialEvent)`, guardar/restaurar `getenv('AUDIT_BATCH_CHUNK_SIZE')` en `finally` (`L194`), mocks de `BatchJobStore` con las nuevas firmas, y las dos clases anónimas que extienden `InvoicesModel` (`L126-132`, `L219-225`) con la firma de 7 parámetros.
- `tests/Services/Audit/Events/AuditPersistenceWorkerTest.php`: aserción `L113` a `'T38250701547'`.
- `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php`: `$keys` en `L45-48` a `[jobKey, telemetry, jobAuditsKey]`, preservación de `'failed'` y no-mutación de jobs terminales/`stale_reason` (`INV-08`).
- `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`: `auditReservationKey('877')` → prefijo `disdetnro`; conserva pruebas de `patchJob()` (`L64,84,108`).
- `tests/Services/Audit/Pipeline/AuditMemoryCompactionAndGcTest.php`: delta Fase 2 (punto 5).
- `tests/Integration/AuditExecutionMigrationRedisTest.php`: `testGcCollectsPriorTerminalRecordsAndOrphanedReservationsWhilePreservingActiveOnes` adaptado a `job:{id}:audits` + `audit:reservation:disdetnro:*` y suite del punto 8.

#### Tests Eliminados

Ninguno. `[CONFIRMADO]` Toda cobertura previa se actualiza o se amplía.

#### Verificaciones Manuales

1. Post-despliegue en Redis con volumen representativo tras `migrate-batch-v2 --clear-claims`: `STRLEN job:*:state < 2048`, ausencia de claves `disid:*` y `worker-execution-maintenance` estable bajo `< 256 MiB` (`heartbeat:meta.memory_bytes`).
2. `php bin/schedule-daily-batches.php --dry-run` y corrida consecutiva contra NIT `2624` verificando creación única y omisión en la segunda invocación mientras el primer job permanezca activo (sin NIT crudo en logs).
3. `repair-jobs --force-stale=<jobId>` sobre un job de prueba sin `batch:claim`: verificar cierre `failed` y liberación del NIT.

---

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Un cliente posea `> 50.000` facturas pendientes en un mismo rango anual | operativo / rendimiento | Medio | Clamping `BATCH_MAX_FREEZE_LIMIT = 50000` (cubre el límite cron operativo actual); `Logger::warning('batch_freeze_limit_reached')` al alcanzar el techo. |
| `R-HOL` (`P-F2-02`): 4 NIT grandes distintos generan a la vez y ocupan las 4 réplicas de `worker-batch`; el resto de clientes espera | rendimiento / operativo | Medio | Cota por generación `4·T + 36 s` (`DEC-12`, `~1.236 s` con `T = 300`) y 1 generación por NIT (`jobs:active:nit`); detección por `batch_generation_sql_ms` (Sección 19). Una lane o réplica dedicada a la generación queda fuera de alcance; abrir spec si la señal supera el umbral de forma recurrente. |
| Timeout o caída de SQL Server durante la congelación (`SUP-01`) | rendimiento / consistencia | Alto | `DEC-12` (timeout explícito + inecuación validada al arranque); renovación del lock antes y después de la consulta; sin auditorías parciales; DLQ con epoch → `failed` y liberación del NIT. |
| NIT bloqueado por un job grande que progresa lentamente (`INV-03` + rendimiento `~180/h`, `H10`): un job de `30.000` ≈ `7 días` sin nuevas auditorías del NIT | operativo / negocio | Alto | Consecuencia aceptada y declarada (Sección 2, Excluido); operar `CRON_LIMIT` acorde al rendimiento (p. ej. `<= 3.000`); `repair-jobs --force-stale` como palanca manual. Señal: antigüedad de jobs activos (Sección 19). |
| Reaper cierra un job que aún progresaría (umbral `6 h` insuficiente bajo degradación severa) | operativo | Medio | El reaper solo actúa sin `batch:claim` y con `6 h` sin ningún registro ni cierre de auditoría; auditorías canceladas son re-auditables por un job nuevo; umbral configurable (`AUDIT_JOB_STALE_AFTER_SECONDS`). |
| Ejecución de `deploy-production.yml` sin detener réplicas PHP/workers antes de `migrate-batch-v2` (`SUP-02`) | migración / consistencia | Crítico | Barrera `ctl pause` + espera cron + `docker compose stop -t 30 php worker-*` ANTES de actualizar `extractors` o migrar. |
| Anomalía post-cutover con batch activo (rollback de datos bloqueado por `DEC-11`) | operativo | Alto | `ctl pause` dentro de `10 min`; corrección hacia adelante; `repair-jobs --force-stale` para vaciar jobs si se requiere rollback de imagen. |
| `gc()` con presupuesto acotado ante `> 50.000` claves huérfanas | operativo | Bajo | Contrato de SDD-BATCH-000 (avance de `pattern_index` al completar patrón y reanudación por `pending_keys` tras corte de presupuesto, `DEC-F1-02`) + `HSCAN` reanudable de Fase 2. |

---

### 18. Criterios de Aceptación

*(Los siguientes 17 criterios constituyen la matriz de validación ejecutable de la fase de implementación TDD y verificación integrada.)*

1. **`CA-F2-01`** — `vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit` termina con código `0` y `0` fallos tras actualizar código y los 12 archivos de test listados en Sección `13`.
2. **`CA-F2-02`** — `RUN_REDIS_INTEGRATION=1 vendor/bin/phpunit tests/Integration` termina con código `0` contra Redis 7 real, incluidas `AuditExecutionMigrationRedisTest` y `AuditStaleJobReaperRedisTest`.
3. **`CA-F2-03`** — Para un lote de `1.500` facturas en chunks de `500`, `InvoicesModel::getInvoicesForAuditBatch()` registra exactamente `1` ejecución SQL correlacionada con `job_id`, con `PDO::SQLSRV_ATTR_QUERY_TIMEOUT = AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS`, y la consulta contiene literalmente `< DATEADD(day, 1, CONVERT(datetime, :dateToD, 23))`.
4. **`CA-F2-04`** — Dadas dos filas con igual `DisId` y distinto `Dispensa` (`DisDetNro`), ambas quedan reservadas en `audit:reservation:disdetnro:{disDetNro}` y generan `2` auditorías válidas con `skipped_locked = 0`, tanto ante caída tras registro pre-publicación como tras completar y liberar reserva previa sin duplicar auditorías.
5. **`CA-F2-05`** — Al terminar `releaseIdempotencyKeyIfMatches($key, $jobId)`, la clave `batch:idem:{key}` solo se borra si su valor es `$jobId`; `claimIdempotencyKey()` conserva el contrato de SDD-BATCH-000 (`TTL > 0`).
6. **`CA-F2-06`** — Enviar `POST /audit/async` para el mismo `facNitSec` con rango solapado mientras existe otro job en estado `pending|queued|processing` responde `HTTP 409` con `errors.active_job_id`; `bin/schedule-daily-batches.php` en esa condición omite crear job (`encolados=0`, `duplicados=1`) y emite `fac_nit_sec_masked` sin NIT crudo.
7. **`CA-F2-07`** — En Redis real con `27.000` auditorías en `job:{id}:audits`, `STRLEN(job:{id}:state) < 2048`, `REGISTER_AUDIT_IN_JOB_LUA` y `MARK_AUDIT_COMPLETED_IN_JOB_LUA` (3 `KEYS`) no almacenan `audits` dentro de `job:{id}:state`, y un worker con `generation_epoch` obsoleto recibe `generation_fence_stale` sin alterar `total`, continuaciones ni estado del job (ni siquiera desde DLQ).
8. **`CA-F2-08`** — Ante `BUSY`, `AuditEventConsumer` conserva el mensaje en PEL sin `XACK` ni incremento de `attempts`. Ante caída entre `commitChunkCheckpoint` y `publishContinuationEvent`, el reintento re-emite la continuación determinista. Ante lote vacío multichunk, `SEAL_JOB_LUA` cierra a `completed` y libera `jobs:active:nit:{nit}`.
9. **`CA-F2-09`** — **`R-CONT`:** con el claim de `k+1` (epoch `E+1`) ejecutado entre `publish(k+1)` y `markContinuationDispatched(k)`, la confirmación de `k` retorna `true`, no se lanza ninguna excepción, no se republica `k+1` y el mensaje de `k` recibe `XACK` con `attempts = 0`. Un `batch_requested` en DLQ sin epoch no cambia el estado del job.
10. **`CA-F2-10`** — **`R-SEAL`:** tras `migrate-batch-v2`, ningún job que antes fuera `completed*` tiene `sealed = false`; un job `completed` con auditorías pendientes queda `processing` + `sealed = true` y se cierra al completar la última.
11. **`CA-F2-11`** — **`R-STALE`:** (con el pipeline no pausado y midiendo desde `max(last_progress_at, resumed_at_ms)`) un job no terminal con `now - last_progress_at >= 21.600.000 ms` y sin `batch:claim` es llevado por `gc()` a `failed` (`stale_reason = stale_no_progress`) con sus auditorías no terminales `failed`, sus reservas liberadas, `done + failed == total` y `jobs:active:nit:{nit}` liberado; una finalización tardía no lo reabre.
12. **`CA-F2-12`** — **`R-SQLTO`:** `BatchRequestedWorker` no arranca si `4·T + 36 >= min(AUDIT_BATCH_LOCK_TTL_SECONDS, AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS / 1000)`; con `HYT00` en la congelación el job termina `failed` vía DLQ con epoch y `0` auditorías parciales.
13. **`CA-F2-13`** — **`R-ROLL`:** `rollback-batch-v2` retorna código `1` sin modificar claves con `>= 1` job no terminal o `>= 1` reserva `disdetnro:*`, y en reposo es reentrante (2ª ejecución `reverted_jobs = 0`).
14. **`CA-F2-14`** — `migrate-batch-v2 --clear-claims`, `repair-jobs` y `reconcile` ejecutan con código `0`; `GET /metrics/async` + `GET /audit/jobs` retornan `HTTP 200` sin `job_totals_inconsistent`; CI `Validate env contract completeness` pasa; `AGENTS.md` refleja `AUDIT_BATCH_LOCK_TTL_SECONDS=1800`, `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS` y `AUDIT_JOB_STALE_AFTER_SECONDS`; `gh variable list --env production` muestra `AUDIT_BATCH_LOCK_TTL_SECONDS=1800`, `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS=300` y `AUDIT_JOB_STALE_AFTER_SECONDS=21600` en GitHub Environment `production`.
15. **`CA-F2-15`** — **Corrida `v4s`:** (a) un job multichunk de `5` candidatas con `chunk_size = 2` publica continuaciones con `candidate_offset` `2` y `4` y termina en `3` chunks (sin releer el slice `0..1`); (b) `REAP_STALE_JOB_LUA` deja `completed_at`/`updated_at` en ISO-8601, libera reservas bajo `REDIS_PREFIX = audfact:` y no actúa si falta toda marca de progreso; (c) un `batch_requested` reentregado durante `reaping` recibe respuesta terminal sin `TypeError`; (d) la recuperación tras caída entre `initAudit()` y `registerAuditInJob()` no lanza `503`; (e) `HYT00` en congelación va a DLQ en el primer intento; (f) con `operation=rollback` y `rollback-batch-v2` abortando, `$DEPLOY_PATH/.env` conserva el tag v2.
16. **`CA-F2-16`** — **Corrida `w7k`:** (a) **`W-F2-B1`:** ante `409` de fencing o `$externalJobId` con `503` tras registrar filas, `cleanupAsyncEnqueueState()` ejecuta `0` `deleteAudit()`/`releaseAuditReservation()` sobre filas registradas en `by_dispensa`, y ningún `audit_created` se publica sin `audit:{id}:state` (`0` `audit_state_missing` en `ADMIT`); (b) **`W-F2-B2`:** con `IsolatedAuditRedis` y prefijo no vacío, `initJobExclusive()` devuelve conflicto ante un job activo solapado y los Lua de cierre/borrado no dejan entradas en `<prefix>jobs:active:nit:{nit}` ni crean claves sin prefijo; (c) **`W-F2-B3`:** tras `REAP_STALE_JOB_LUA` (`generation_epoch` incrementado), los 5 Lua de generación retornan `-2` sin mutar aun con epoch coincidente, y el job permanece `failed`; (d) **`W-F2-B4`:** tras el reaper o tras DLQ con `markJobFailed() = true`, `0` `audit:{id}:state` no terminales y `0` reservas `disdetnro:*` del job, `audits_in_flight` sin fuga; (e) **`W-F2-B5`:** un `workflow_run` de `main` ejecuta la rama `deploy` (`env.OPERATION = deploy`); (f) con `operation=rollback`, el step previo a `L101` invoca `pause`, `stop` y `rollback-batch-v2` con `docker compose run --rm --no-deps -T --entrypoint php php` sobre el compose v2 vigente y, si `rollback-batch-v2 != 0`, relanza v2, conserva `pause` y el job termina con código `1`; (g) `heartbeat:meta.jobs_reaped` se reinicia a `0` en un tick sin `gc()` y `migrate-batch-v2` reporta `legacy_idempotency.expired = 0` tras la migración; (h) **tercera pasada:** `testGcReleasesOrphanReservationOfTerminalJob` y `testLegacyReservationPassSurvivesBudgetCutAcrossTicks` pasan (`X-F2-B1`, `X-F2-B3`), `AuditControllerTest` pasa sin configurar `initJob` (`X-F2-B2`), y un `batch_requested` reentregado tras caer entre `markJobFailed()` y `cancelUndispatchedAudits()` deja `0` entradas `dispatched = false` no terminales (`X-F2-I2`); (i) **quinta pasada (`R5-F2-*`):** con `AUDIT_BATCH_LOCK_TTL_SECONDS=''` (con limpieza de caché en `finally`), `AuditExecutionConfig::jobStaleAfterSeconds()` retorna `21600` y `worker-execution-maintenance` arranca sin `InvalidArgumentException` (`R5-F2-B1`); un corte de presupuesto pre-bucle al reanudar `reap_job_id` conserva intactos `pending_pattern`/`pending_keys` en `audit:execution:gc:cursors` (`R5-F2-B2`); y una excepción tras extraer la última clave de la página final de `disid:*` (`cursor:reservations_legacy = '0'`) fija `legacy_pass_done = '1'` sin avanzar `pattern_index`, visitando `disdetnro:*` en el tick siguiente (`R5-F2-I1`).
17. **`CA-F2-17`** — **`REQ-07` (recolección de claves v2 sin OOM):** un job terminal de `50.000` auditorías en `job:{id}:audits`, cortado a mitad de `HSCAN` por el presupuesto de `gc()` (`500 ms` / `500` ítems / `256 MiB`, `DEC-06`), se reanuda en el tick siguiente desde `hscan_cursor`/`hscan_sha1` y termina de borrar las claves v2 del job, sin que ningún tick supere `256 MiB` (`memory_get_usage(true)`; `AuditMemoryCompactionAndGcTest`).

**Matriz REQ → CA** (`system.md` 6.5; derivada del texto de los criterios):

| Requisito | Criterios |
| --- | --- |
| `REQ-01` | `CA-F2-03` |
| `REQ-02` | `CA-F2-04` |
| `REQ-03` | `CA-F2-03` (predicado `dateTo`) |
| `REQ-04` | `CA-F2-07`, `CA-F2-08`, `CA-F2-09`, `CA-F2-15`, `CA-F2-16` |
| `REQ-05` | `CA-F2-05`, `CA-F2-06` |
| `REQ-06` | `CA-F2-07` |
| `REQ-07` | `CA-F2-17` (más las suites genéricas `CA-F2-01`/`02`) |
| `REQ-08` | `CA-F2-11` |
| `REQ-09` | `CA-F2-03` (timeout), `CA-F2-06` (NIT enmascarado), `CA-F2-12` |
| `REQ-10` | `CA-F2-10`, `CA-F2-13`, `CA-F2-14` |


---

### 19. Observabilidad

Las señales de mantenimiento (OOM, `heartbeatAgeMs`, `heartbeat:meta.tick_duration_ms`/`memory_bytes`, `outboxPending`, `extractors.lag`) pertenecen a SDD-BATCH-000 y son **precondición** de esta fase (`SUP-03`); aquí se re-verifican tras el cutover.

| Señal | Tipo | Antes (baseline observado/reportado) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| Señales de SDD-BATCH-000 (OOM, `heartbeatAgeMs`, `tick_duration_ms`, `memory_bytes`) | Log / Métrica | Verdes `24 h` antes del cutover | Siguen verdes tras el cutover | Ver SDD-BATCH-000 | Cualquier regresión en `15 min` post-cutover | `ctl pause` + corrección hacia adelante (`DEC-11`) |
| `SLOWLOG` de Redis sobre `BatchJobStore` | Log servidor Redis | `43,6–56,3 ms` en `EVAL storeState` (`E18`, local/QA) | Ausencia de `EVAL` de `BatchJobStore` `> 10 ms` | `redis-cli SLOWLOG GET` | Entradas repetidas `> 20 ms` de `REGISTER_AUDIT_IN_JOB_LUA` / `MARK_AUDIT_COMPLETED_IN_JOB_LUA` | Verificar `STRLEN job:*:state` post-migración; si `>= 2048 B`, re-ejecutar `migrate-batch-v2` |
| `batch_generation_sql_ms` / `Executed SQL audit batch candidates` (`job_id`, `fac_nit_sec_masked`) | Log aplicación | Sin correlación por `job_id`; `N` ejecuciones por job (`H1`) | Exactamente `1` congelación por job con `batch_generation_sql_ms < AUDIT_BATCH_FREEZE_QUERY_TIMEOUT_SECONDS·1000` y `0` NITs sin máscara | `logs/app-*.log` / stdout | `> 1` invocación SQL con igual `job_id`, `HYT00` recurrente o NIT sin máscara | Investigar pérdida de `batch:claim:*` o degradación de `db2`; corregir redacción |
| `generation_fence_stale` / `continuation_before_checkpoint` | Log aplicación | No existía | `0` en flujo normal (`R-CONT`) | stdout `worker-batch` | `>= 1` por hora sostenido | Revisar caídas de workers / expiración de lock |
| `batch_requested_dlq_without_epoch` | Log CRITICAL | No existía | `0` | stdout `worker-batch` | `>= 1` | Inspeccionar `audit.dlq`; el job será cerrado por el reaper si no progresa |
| Eventos `TYPE_BATCH_REQUESTED` en DLQ | Stream DLQ + Log | Job quedaba colgado sin liberación de NIT | `0`; cierre automático `failed` ante fallo real con epoch | `audit.dlq` (`AuditEventConsumer.php:639`) | `>= 1` en `15 min` por error sistémico SQL | Revisar conectividad `db2`; corrección hacia adelante |
| `jobs_reaped` (`heartbeat:meta`) y log `stale_no_progress` | KV Redis / Log | Jobs atascados sin cierre (`R-STALE`) | `0` en régimen sano | `audit:execution:heartbeat:meta`, stdout `worker-execution-maintenance` | `>= 1` por día | Investigar la causa de la pérdida de progreso (auditorías perdidas, DLQ) |
| Antigüedad del job activo más viejo por NIT (`now - created_at`) | Métrica derivada (`GET /audit/jobs`) | No medida | `< 24 h` en régimen normal | `GET /audit/jobs` | `> 72 h` | Revisar `CRON_LIMIT` frente a rendimiento (`H10`) |
| `AuditExecutionInspector::snapshot()` (`job_totals_inconsistent`) | Excepción CLI | Falla ante `done + failed > total` (`H3`) | `0` excepciones | `reconcile` | Presente después de `migrate-batch-v2` + `repair-jobs` | Abortar `resume`; corrección hacia adelante |

---

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Precondición | `SUP-03`: SDD-BATCH-000 desplegado y verde `24 h`. |
| Estrategia de despliegue | Directo controlado en ventana de pausa única (`deploy-production.yml` sobre `nodo1`) con parámetro `operation` (`deploy` \| `rollback`) |
| Orden entre productores y consumidores | Pausa operativa (`ctl pause`) + espera cron → parada graceful `php` y todos los `worker-*` (`stop -t 30`, ANTES de actualizar `extractors`) → `migrate-batch-v2 --clear-claims` + `repair-jobs` + `reconcile` → upgrade `extractors` y stack completo → espera activa de heartbeat `< 30.000 ms` → `resume` |
| Coexistencia entre versiones | No (`pause` + espera cron + `stop -t 30 php worker-*` bloquean todo escritor previo antes de mutar estructuras Redis o levantar la nueva imagen) |
| Compatibilidad requerida durante rollout | N/A por ventana pausada; mensajes pendientes `v1` en `audit.batch.inbox` son reanudados por v2 vía `cursor` + `by_dispensa`. Ningún proceso v1 escribe sobre datos v2 (`DEC-11`). |
| Condición para avanzar rollout | `migrate-batch-v2 --clear-claims`, `repair-jobs` y `reconcile` con código `0`, `0` claves `audit:reservation:disid:*`, `0` jobs `completed*` con `sealed = false`, variables remotas verificadas, health checks HTTP `200`, `heartbeatAgeMs < 30.000` y ausencia de OOM o `batch_requested` en DLQ por `3` minutos. |
| Condición para detener rollout | Código != `0` en `migrate-batch-v2`, `repair-jobs` o `reconcile`; o fallo de health check HTTP en `deploy-production.yml`. |
| Condición de rollback | Excepción `job_totals_inconsistent`, OOM en `worker-execution-maintenance`, fallo SQL sistémico en congelación (`>= 1` `batch_requested` en DLQ por error regresivo) o `5xx` generalizado en `/audit/async` / `/audit/jobs` dentro de los primeros `15 minutos` post-cutover. |
| Acción de rollback | Sección `14` Rollback (`DEC-11`): el operador ejecuta, si necesita contención inmediata, `ctl pause` y dispara `workflow_dispatch` con `operation=rollback` e `image_tag=<sha_anterior>`; el workflow (antes de `L101`, sobre el compose v2 vigente) ejecuta `pause` → `stop php worker-*` → `rollback-batch-v2` y solo si retorna `0` despliega `<sha_anterior>` → `reconcile` → `resume`; si retorna `!= 0`, relanza v2, conserva `pause` y termina con `exit 1` (corrección hacia adelante). Nada de esto se ejecuta a mano. |
| Tiempo máximo para contener | `10 minutos` desde la detección para ejecutar `ctl pause` (contención). La reversión de datos solo es posible en reposo (`DEC-11`). |
| Responsable de decisión | Operador de despliegue / Mantenedor AudFact ejecutando el workflow en `nodo1` |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS | Sección 9 define SQL (`Dispensacion.DisFecSol`) y las claves Redis afectadas (`job:{id}:state` con `last_progress_at`/`stale_reason`/`generation_abandoned`/`reaping`/`generation_epoch`, `job:{id}:audits`, `job:{id}:by_dispensa`, `job:{id}:candidates`, `jobs:active:nit:{nit}`, `audit:reservation:disdetnro:*`, `batch:idem:*`, `batch:claim:*`, `audit:execution:gc:cursors`, `audit:execution:heartbeat:meta`, `audit:execution:resumed_at_ms`). |
| Todas las columnas mencionadas existen | PASS | `DisFecSol`, `DisId`, `Dispensa`, `DisDetNro`, `FacNro`, `FacSec`, `NitSec` verificadas en `InvoicesModel.php:121-293` y `AuditResultPersistenceModel.php:154-196`. |
| Todos los contratos documentados con clasificación | PASS | Sección 10 (4 contratos), incluida la confirmación de continuación sin epoch y la DLQ sin epoch. |
| Todos los requisitos tienen trazabilidad | PASS | `REQ-01..REQ-10` en Sección 11 enlazados a implementación y criterios `1–17` (`CA-F2-01..17`). |
| Todos los consumidores analizados | PASS | Sección `0.2`; consumidores externos `[DESCONOCIDO]`. |
| Todas las migraciones tienen rollback | PASS (condicionado) | `rollback-batch-v2` existe y es reentrante, **solo en reposo** por decisión `DEC-11` (forward-fix only); declarado explícitamente en Secciones 9, 14 y 20. |
| Todas las referencias a archivos, clases, funciones, métodos, variables, comandos, endpoints, eventos y configuraciones están definidas | PASS | Incluye `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA`, `MARK_CONTINUATION_DISPATCHED_LUA`, `REAP_STALE_JOB_LUA`, `reapStaleJob()`, `reapStaleJobs()`, `jobStaleAfterSeconds()`, `freezeQueryTimeoutSeconds()`, `batchLockTtlSeconds()`, `LoggerSanitizationTest`, `AuditStaleJobReaperRedisTest`, `cancelUndispatchedAudits()`/`CANCEL_UNDISPATCHED_AUDITS_LUA`, `DELETE_JOB_LUA`, `env.OPERATION`, `hscan_sha1`, `AuditEvent::create(..., ?string $eventId)` y `AuditExecutionMaintenance::expireLegacyIdempotency()` (SDD-BATCH-000). |
| Toda compatibilidad tiene evidencia | PASS | `FairAuditTaskQueue.php:236-258`, `AuditControllerTest.php:236,778-854`, `BatchRequestedWorkerTest.php:177,194`, `BatchJobStoreMetricsTest.php:20-55`, `RedisTtlConfigTest.php:64-112`, `domain.ts:342-460`, `ci.yml:61-129`. |
| Todos los criterios son verificables | PASS | Sección 18: `17` criterios cuantitativos/binarios (el 17 cubre `REQ-07`; el 16 cubre los bloqueantes `W-F2-B1..B5` de la corrida `w7k`). |
| Observabilidad documentada (si aplica por triage) | PASS | Sección 19: `9` señales (incluye la fila agregada de señales heredadas de SDD-BATCH-000). |
| Rollout documentado (si aplica por triage) | PASS | Sección 20 completa para Riesgo Crítico, con precondición `SUP-03`. |
| Identificadores consistentes | PASS | `H1–H11` solo con el significado del diagnóstico; evidencia directa de producción en `172.16.0.3` como `P-F2-01..08`; y defectos de revisión como `R-*`, `V-F2-*` (corrida `v4s`), `W-F2-*`/`W-F1-*` (corrida `w7k`), `X-F2-*` (tercera pasada), `R4-F2-*` (cuarta pasada) y `R5-F2-*` (quinta pasada) en el Registro de revisión verificada. |
| Secciones 13, 14 y 20 coherentes en el rollback | PASS | Una sola ruta (`W-F2-B5`): el operador solo ejecuta `ctl pause` opcional y `workflow_dispatch`; `pause`, `stop` y `rollback-batch-v2` los ejecuta el workflow antes de `L101`. |
| Conteos | PASS | `12` archivos de test (`10` modificados + `2` nuevos) idénticos en `0.1`, `13` y `16`; `17` criterios (Sección 18); `9` señales (Sección 19). |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No | `DEC-01` a `DEC-12` registran cada decisión con alternativas y justificación; `DEC-10`/`DEC-11` registran decisiones del usuario del 2026-11-18. |
| ¿Existe algún contrato sin documentar? | No | Endpoints REST, evento `batch_requested` (con confirmación de continuación sin epoch), excepciones, claves Redis, CLIs (`--force-stale`) y workflow `deploy-production.yml` en Sección 10. |
| ¿Existe algún consumidor no analizado? | No | `0.1`, `0.2` y `0.3`; externos `[DESCONOCIDO]`. |
| ¿Existe alguna migración sin rollback? | No (con restricción declarada) | `rollback-batch-v2` reentrante en reposo; con jobs activos la política es forward-fix (`DEC-11`). |
| ¿Existe algún dato persistido sin migración? | No | `job:*:state`, `audit:reservation:disid:*`, `batch:claim:*`, `batch:idem:*` y `telemetry:async_metrics` con migración y reconciliación en Sección 9. |
| ¿Existe alguna afirmación sobre el proyecto sin clasificar, o clasificada con un nivel de certeza superior a su evidencia? | No | Código `[CONFIRMADO]`; mediciones locales `E1–E21` `[CONFIRMADO en fuente externa]` con entorno local/QA explícito; hallazgos directos vía API/SSH en `172.16.0.3` (`P-F2-01..08`) `[CONFIRMADO en producción]`; proyecciones de impacto `[INFERIDO]`. |
| ¿Existen referencias huérfanas? | No | Verificadas frente a `0.1` y `13`, incluidos los archivos `NEW` de pruebas. |
| ¿Queda alguna decisión de implementación sin registrar en la sección 6, o algún cambio de la sección 13 que admita más de una interpretación? | No | Lua de claim, confirmación de continuación, reaper y migración especificados; reglas transversales de prefijo y guarda de generación (Sección 13, `BatchJobStore` regla 0); compensación de `cleanupAsyncEnqueueState()` en 3 casos; script del step de rollback; ubicación única de `LoggerSanitizationTest`. |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque, entrypoint, bootstrap, migración o proceso de inicialización que invoque un binario, comando, clase, función o archivo que este cambio elimina, mueve o renombra? | Runtime | `SÍ-CORREGIDO` | Se retiran `BatchJobStore::initJob()` y `claimJobGenerationLock()` actualizando llamadores (`AuditController.php:444`, `bin/schedule-daily-batches.php:197`, `AuditBatchOrchestrator.php:109,481`) y dobles (`AuditControllerTest.php:132,236,778,808`, `AuditBatchOrchestratorTest.php:418-456`, `BatchRequestedWorkerTest.php:146,238`). `BatchRequestedWorker` puede negarse a arrancar por `DEC-12`: documentado como comportamiento deseado. |
| 2 | ¿Existe algún paso posterior en la cadena de build que dependa de un estado que este cambio elimina o modifica? | Build | `NO` | Sin cambios en `Dockerfile`, `composer.json`, Next.js o `nginx.conf`. Toda invocación CLI nueva del workflow usa `--entrypoint php` porque el `ENTRYPOINT` (`Dockerfile:65`) ejecutaría `composer install` (`docker-entrypoint.sh:37-48`). |
| 3 | ¿Existe algún pipeline o validación automatizada que ejecute el artefacto con un flujo distinto al evaluado? | Pipeline | `SÍ-CORREGIDO` | Barrera antes de `L543`; `env.OPERATION` con valor `deploy` para `workflow_run` (`deploy-production.yml:4-6,29`), que de otro modo dejaría `inputs.operation` vacío; rama `rollback` ejecutada antes de `L101` sobre el compose v2 vigente que relanza v2 y sale con `1` si `rollback-batch-v2` aborta; nuevas variables con `Env::get` literal para `ci.yml:113-129`. |
| 4 | ¿El cambio asume un comportamiento de herramienta sin verificar? | Semántica de Herramienta | `NO` | `0.4` más: `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` aceptado en `prepare()` por `pdo_sqlsrv` (`HYT00` al vencer); `XADD *` sin dedupe por `event_id` (`AuditEventPublisher.php:179`); ejecución bloqueante de `PDOStatement::execute()` en PHP. |
| 5 | ¿El cambio fue validado para un solo entorno? | Paridad de Entornos | `NO` | Matriz `0.5`; se distinguen explícitamente las mediciones `E1–E21` (local/QA) de la telemetría y estado observado directamente en producción `172.16.0.3` (`P-F2-01..08`). |
| 6 | ¿Existe algún mecanismo de override en runtime que pueda anular un comportamiento dado por fijo? | Runtime por Override | `SÍ-CORREGIDO` | `deploy-production.yml:188` inyecta `vars.AUDIT_BATCH_LOCK_TTL_SECONDS`; se exige `gh variable set` y la inecuación de `DEC-12` se valida en runtime con los valores efectivos. |
| 7 | ¿Se aplicó alguna "best practice" sin verificar la convención local? | Dogmatismo Técnico | `NO` | Sin TTL automático en claves activas; `job:{id}:state` sigue `string`; la excepción al fencing en `markContinuationDispatched()` se justifica con el orden real lock/publicación del código. |
| 8 | ¿El cambio modifica alguna interfaz pública sin estrategia de compatibilidad? | Contract | `NO` | Sección 10. |
| 9 | ¿El cambio afecta datos persistidos sin migración, rollback y validación de integridad? | Data | `NO` | Secciones 9 y 14 (migración que conserva `sealed`, reparación, `reconcile`, rollback en reposo). |
| 10 | ¿El cambio introduce código muerto, compatibilidad especulativa o alcance más allá del MVP? | Clean Architecture | `NO` | Mecanismos de mantenimiento delegados a SDD-BATCH-000; el reaper responde a un defecto verificado (`R-STALE`). |
| 11 | ¿El cambio reemplaza un mapeo estático por una abstracción dinámica sin verificación? | Abstracción Incorrecta | `NO` | Confirmado en `0.3.1`. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**`Nivel A — Implementable, despliegue condicionado a SUP-03`** (`[CONFIRMADO]` — lista para implementación TDD `REQ-01..REQ-10` y verificación de los `17` criterios de la Sección `18`; el cutover productivo requiere SDD-BATCH-000 estable `24 h`). El nivel se sostiene tras incorporar los `5` Bloqueantes, `7` Importantes y `9` Menores de la corrida `w7k` (`W-F2-*`), las `7` correcciones parciales de `v4s` (`V-F2-B6`, `I1`, `I2`, `I4`, `I6`, `M3`, `M5`) y los puntos de interfaz `W-F1-I3`, `W-F1-I4(a)(b)`, `W-F1-I5` y `W-F1-M7`. La incorporación de los diagnósticos de producción en `172.16.0.3` (`P-F2-01..03`, `SUP-05` `Mitigado`, riesgo `R-HOL`; y el análisis RCA de 5 capas `P-F2-04..08`, refutación de `SUP-04` diferido a Fase 3 `SDD-BATCH-002`) no altera el nivel: confirma `R-SQLTO`/`DEC-12`, la reconciliación de métricas/estado huérfano y el aislamiento estricto de carriles en `SDD-BATCH-002`. Las pasadas posteriores —tercera (`X-F2-*`: `3` Bloqueantes, `3` Importantes, `4` Menores), cuarta (`R4-F2-*`) y quinta (`R5-F2-*`: `R5-F2-B1..B2`, `R5-F2-I1..I2`, `R5-F2-M1..M4`)— cierran defectos técnicos y consistencia inter-spec sin abrir supuestos nuevos; queda pendiente una re-verificación independiente de esta versión antes de congelarla.

**Justificación según la tabla «Determinación del Nivel»:**
- Perímetro `0.1` cerrado (`Verificado por Lectura = Sí` o `NEW`), incluidos `LoggerSanitizationTest`, `AuditStaleJobReaperRedisTest`, `AuditExecutionConfig.php` y `AuditMemoryCompactionAndGcTest.php`.
- Grafo `0.2` e impacto inverso `0.3` completos.
- `0.7` y `0.8` sin faltantes; decisiones funcionales confirmadas por el usuario (identidad `DisDetNro`, solapamiento, congelación, `dateTo`, umbral y estado del reaper, política de rollback, faseo).
- Supuestos: `SUP-01`, `SUP-02`, `SUP-05` (`Mitigado`), `SUP-04` (`Refutado — diferido a Fase 3 (SDD-BATCH-002)`) y `SUP-03` (`S1`, cierre por despliegue de Fase 1).
- Defectos de la revisión verificada (`R-SEAL`, `R-STALE`, `R-CONT`, `R-SQLTO`, `R-ROLL`, `R-INTAKE`, `R-TICK`, `R-PHASE`, `R-DOC`) resueltos en esta versión o trasladados a SDD-BATCH-000.
- `6.1` clasifica como `Refactorización incremental` bajo `clean-rebuild-policy` con `Excepciones: Ninguna`.
- Base empírica: en la corrida `w7k` los scripts SQL de verificación (`check_candidate_cardinality.php`, `01_bench_candidates_top500_cursors.php ... only-disfecsol`) no pudieron ejecutarse (sandbox sin TCP saliente, `SQLSTATE[08001]`); la base empírica de `DEC-01` y `DEC-09` sigue siendo `E19`/`E21` del diagnóstico. El script en memoria `02_repro_concurrent_generation_race.php` reconfirmó `H3` y `H2 + H4` en HEAD.
