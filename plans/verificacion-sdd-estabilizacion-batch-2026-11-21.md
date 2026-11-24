# Verificación de implementación — SDD-BATCH-001

**Documento verificado:** `plans/sdd-estabilizacion-batch-y-mantenimiento.md`
**Fecha de verificación:** 2026-11-21 16:45Z
**Verificador:** sesión de diagnóstico de producción (evidencia en solo lectura)
**Baseline del repositorio:** `main @ 2d1a4676a3996e0850b0758337d31411485842c0`
**Baseline declarado por el SDD:** `main @ 3396897`
**Producción:** `172.16.0.3`, imágenes `ghcr.io/jfrem/audfact-*:2d1a4676a3996e0850b0758337d31411485842c0`

---

## 1. Resumen ejecutivo

**No se encontró implementación del SDD-BATCH-001 en el código fuente ni en producción.**

| Métrica | Resultado |
|---|---|
| Archivos `NEW` del perímetro implementados | **0 de 6** |
| Símbolos/estructuras clave del diseño presentes (35 probados) | **0 de 35** |
| Requisitos `REQ-01..REQ-10` implementados | **0 completos** (1 parcial: REQ-09 solo redacción genérica preexistente) |
| Criterios de aceptación 1–16 verificables | **0 de 16** |
| Pruebas nuevas del SDD | **0 de 2** (`AuditStaleJobReaperRedisTest`, `LoggerSanitizationTest`) |
| Archivos `MODIFIED` que contienen el cambio esperado | **0 de 26** (25 existen sin el cambio; 1 ni existe) |
| Imágenes desplegadas con el diseño v2 | **0 de 15** servicios |

La afirmación de "cumplimiento del 100%" **no está sostenida por el repositorio ni por el despliegue**. La única entrega posterior al baseline (`2d1a467`) es **SDD-SCALE-001** (réplicas 36/12 y caché 3 GB), un SDD distinto.

---

## 2. Alcance y método

Se verificaron cuatro fuentes independientes:

1. **Repositorio local**: `HEAD`, `origin/main`, ramas remotas, tags, stash y worktrees; búsqueda de símbolos con `git log --all -S` y búsqueda case-sensitive en `app/`, `bin/`, `tests/`.
2. **Perímetro declarado por el SDD**: las 60 filas de la tabla de "Perímetro de Impacto" (`NEW`/`MODIFIED`/`IMPACTED`/`INSPECTED`), comprobando existencia y contenido.
3. **Producción**: etiqueta de imagen por servicio (`docker compose ps`/`docker inspect`) y búsqueda de símbolos dentro de los contenedores `php` y `worker-batch`.
4. **Estado en Redis**: jobs `ed15eb4e` y `5c4ca134`, locks, streams y DLQ, contrastados con los hallazgos `P-F2-06` del propio SDD.

Criterio de "implementado": el artefacto existe **y** contiene la estructura/firma que el SDD especifica (método, Lua, campo o clave). La mera existencia del archivo no cuenta: 25 de 26 `MODIFIED` existen porque ya existían antes del SDD.

---

## 3. Evidencia de repositorio

```
HEAD            2d1a4676a3996e0850b0758337d31411485842c0
origin/main     2d1a4676a3996e0850b0758337d31411485842c0
ahead/behind    0 / 0
último commit   2026-11-20 "perf: sintonizacion concurrencia y cache SDD-SCALE-001"
tags            (ninguno)
worktrees       1 (el principal)
stash@{0}       solo app/Models/InvoicesModel.php (27+/9-), 0 símbolos v2
```

Búsqueda en **todas** las refs (`git log --all -S`):

| Símbolo | Commits que lo introducen |
|---|---|
| `claimJobGenerationFencedLock` | 0 |
| `storeFrozenCandidates` | 0 |
| `JobGenerationBusyException` | 0 |
| `reapStaleJob` | 0 |

Ramas existentes revisadas: `Dev`, `staging`, `feat/fair-queuing-multiclient-batch`, `orq/b7j-*`, `revision/b7j-h2-h5`, entre otras. Ninguna contiene el diseño.

---

## 4. Evidencia de despliegue

Todos los servicios usan la misma etiqueta: `ghcr.io/jfrem/audfact-php:2d1a4676...` (php, worker-batch, worker-orchestrator, worker-downloader, worker-extraction-*, worker-semantic-*, worker-policy, worker-persistence, worker-normalization/maintenance). `frontend`, `nginx` y `docs` comparten el mismo SHA.

Dentro de los contenedores `php` y `worker-batch` (`/var/www/html`):

| Comprobación | Resultado |
|---|---|
| `resolveLockedConcurrentResponse` en `AuditBatchOrchestrator.php` | 2 (código anterior) |
| `claimJobGenerationLock` en `BatchJobStore.php` | 1 (método que el SDD retira) |
| `JobLockClaimStatus` / `JobGenerationBusyException` | ausentes |

---

## 5. Archivos `NEW` del perímetro (0/6)

| Archivo | Rol especificado | Estado |
|---|---|---|
| `app/Services/Audit/Pipeline/JobLockClaimStatus.php` | Enum `acquired/busy/terminal/missing` | **AUSENTE** |
| `app/Services/Audit/Pipeline/JobLockClaimResult.php` | Resultado tipado del claim | **AUSENTE** |
| `app/Services/Audit/Pipeline/JobGenerationBusyException.php` | Diferir sin `XACK` ante `BUSY` | **AUSENTE** |
| `app/Services/Audit/Pipeline/JobGenerationFailedException.php` | Fallo con epoch | **AUSENTE** |
| `tests/Integration/AuditStaleJobReaperRedisTest.php` | Reaper, reaping, reservas | **AUSENTE** |
| `tests/Core/LoggerSanitizationTest.php` | Redacción PII (NIT) | **AUSENTE** |

---

## 6. Archivos `MODIFIED` — verificación de contenido

| Archivo | Cambio esperado por el SDD | Observado |
|---|---|---|
| `AuditBatchOrchestrator.php` | `storeFrozenCandidates` + `claimJobGenerationFencedLock` + epoch | Sigue con `initJob`, `claimJobGenerationLock`, `resolveLockedConcurrentResponse` |
| `BatchJobStore.php` | Retirar `initJob`/`claimJobGenerationLock`; añadir `initJobExclusive`, `commitChunkCheckpoint`, `markContinuationDispatched`, `reapStaleJob`, `repairJobStates`, `migrateBatchV2`… | Métodos ausentes; `initJob` y `claimJobGenerationLock` presentes |
| `BatchChunkState.php` | `candidateOffset`, `last_committed_chunk` | Solo `cursor/chunkIndex/accumulated*/chunkSizeOverride` |
| `AuditExecutionConfig.php` | `batchLockTtlSeconds`, `jobStaleAfterSeconds`, `freezeQueryTimeoutSeconds` | Ninguno |
| `InvoicesModel.php` | Firma de 7 parámetros (`$jobId`, `$queryTimeoutSeconds`) y `BATCH_MAX_FREEZE_LIMIT=50000` | Firma de 5 parámetros; cap 1000; sin `DATEADD(day,1,…)` (REQ-03 tampoco) |
| `AuditEventConsumer.php` | `catch (JobGenerationBusyException)` sin `XACK`/DLQ | Ausente |
| `AuditFailureFinalizer.php` | `cancelUndispatchedAudits()` | Ausente |
| `AuditExecutionMaintenance.php` | Reaper, `HSCAN` reanudable, patrón `disdetnro`, `repairCorruptedJobs` | Ausentes; solo flujo anterior |
| `AuditExecutionInspector.php` | Contabilidad v2/`getJobSummary` | Sin cambios v2 |
| `AuditTaskEffects.php` | UUID determinista v4 | Sin `deterministicUuidV4` en el orquestador |
| `JsonRedisStoreTrait.php` | Store con TTL coherente | `storeState` sigue sin `EX` (sin cambios) |
| `Logger.php` | Claves sensibles incluyendo NIT/PII | Solo `password/token/secret` (preexistente) |
| `AuditController.php` | `409` por solapamiento NIT (`initJobExclusive`) | `initJob`; validación de `limit` máx 10000 |
| `bin/schedule-daily-batches.php` | Idempotencia/lease por job | Sigue con `claimIdempotencyKey` horaria |
| `.env.example` | `LOCK_TTL=1800`, `FREEZE_QUERY_TIMEOUT=300`, `JOB_STALE_AFTER=21600`, `RECLAIM=1800000` | `LOCK_TTL=300` (L149); las dos nuevas ausentes; `RECLAIM=1800000` ya existía |
| `.github/workflows/deploy-production.yml` | `migrate-batch-v2`/`rollback-batch-v2`, `OPERATION` | Sin referencias; default `LOCK_TTL=300` (L404) |
| `AGENTS.md` | Contrato operativo v2 | Sin las cláusulas del SDD |
| `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` | Test modificado | **El archivo no existe** |

---

## 7. Símbolos clave (35 probados, 0 presentes)

`claimJobGenerationFencedLock`, `renewJobGenerationLock`, `JobLockClaimStatus`, `JobLockClaimResult`, `JobGenerationBusyException`, `JobGenerationFailedException`, `storeFrozenCandidates`, `readFrozenCandidates`, `commitChunkCheckpoint`, `markContinuationDispatched`, `cancelUndispatchedAudits`, `reapStaleJob`, `repairJobStates`, `migrateBatchV2`, `rollbackBatchV2`, `by_dispensa`, `audit:reservation:disdetnro`, `AUDIT_BATCH_FREEZE_QUERY_TIMEOUT`, `AUDIT_JOB_STALE_AFTER_SECONDS`, `batchLockTtlSeconds`, `freezeQueryTimeoutSeconds`, `jobStaleAfterSeconds`, `REAP_STALE_JOB_LUA`, `CLAIM_JOB_GENERATION_FENCED_LOCK_LUA`, `STORE_FROZEN_CANDIDATES_LUA`, `INIT_JOB_EXCLUSIVE_LUA`, `DELETE_JOB_LUA`, `generation_epoch`, `last_committed_chunk`, `last_continuation_dispatched_chunk`, `last_progress_at`, `reaping`, `BATCH_MAX_FREEZE_LIMIT`.

Solo aparecen variables **preexistentes**: `AUDIT_BATCH_CHUNK_SIZE` (5 usos) y `AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS` (1 uso en `BatchRequestedWorker`).

---

## 8. Requisitos funcionales

| REQ | Objetivo | Estado |
|---|---|---|
| REQ-01 | Congelación única de candidatos (elimina re-SQL por chunk) | **AUSENTE** |
| REQ-02 | Reserva 1:1 por `DisDetNro` | **AUSENTE** (sigue `audit:reservation:disid:*`) |
| REQ-03 | Incluir todo el día `dateTo` | **AUSENTE** (`<= :dateToD`) |
| REQ-04 | Erradicar split-brain, `total` sobrescrito, `done>total`, pérdida de continuación | **AUSENTE** |
| REQ-05 | Rechazar jobs solapados por NIT/rango (`409`) | **AUSENTE** |
| REQ-06 | Eliminar reescritura JSON O(n²) (`job:{id}:audits` hash) | **AUSENTE** (mapa inline) |
| REQ-07 | GC v2 con `HSCAN` reanudable y patrón `disdetnro` | **AUSENTE** |
| REQ-08 | Reaper de jobs estancados (`6 h`) | **AUSENTE** (por eso `ed15eb4e` quedó 3 días) |
| REQ-09 | Observabilidad PII + timeout de congelación | **PARCIAL** (solo redacción genérica preexistente) |
| REQ-10 | Migración/cutover/rollback v2 | **AUSENTE** |

---

## 9. Criterios de aceptación (Sección 18)

Ninguno es verificable porque dependen de artefactos inexistentes: `R-EPOCH`, `R-PREPUB`, `R-EMPTY`, `R-CLEAN`, `R-PII`, `R-OBS`, `R-SEAL`, `R-STALE`, `R-SQLTO`, `R-ROLL`, `R-CONT`, corridas `v4s`/`w7k`, migración idempotente y rollback forward-fix.

El propio SDD lo declara: *"Nivel A - Implementable, condicionado a SUP-03 … el despliegue queda bloqueado hasta cerrar SUP-03"* y *"queda pendiente una re-verificación independiente de esta versión antes de congelarla"*.

---

## 10. Coherencia con la producción observada

Los síntomas del 2026-11-21 reproducen exactamente lo que el SDD dice corregir:

| Síntoma | Job/evidencia | Causa en el diseño actual |
|---|---|---|
| Lock huérfano + mensaje ackeado sin trabajo | `685ebaf6` (06:11–06:38Z) | `claimJobGenerationLock` booleano + `resolveLockedConcurrentResponse` con `has_more=false` |
| `done > total` | `685ebaf6`: 3389/3000; `ed15eb4e`: 350/134 | `MERGE_LUA` con `total` no monótono y re-procesamiento por paginación no determinista |
| Job sellado con `status=processing` | `5c4ca134` (19–21 nov) | `sealJob` no fija `status`; nadie lo recalcula al sellar |
| Job estancado sin reaper | `ed15eb4e` (18–21 nov) | Ausencia de `REAP_STALE_JOB_LUA`/`last_progress_at` |
| Entradas colgantes en `jobs:index` | 85 entradas vs 22 estados | `deleteJob` no limpia el índice; sin `DELETE_JOB_LUA` |
| DLQ de generación | 32 entradas de `BatchRequestedWorker` | Sin fencing, el fallo SQL deja lock huérfano |

Estos mismos casos ya están documentados como `P-F2-06` en el SDD, con la corrección propuesta, pero **la corrección no está desplegada**.

---

## 11. Reconciliación de la afirmación "100%"

Existe una explicación plausible y verificable: el único commit posterior al baseline del SDD (`2d1a467`, 2026-11-20) implementa **SDD-SCALE-001** (otro documento):

- `docker-compose.yml`: réplicas por defecto 36 (`extraction-batch`) y 12 (`semantic-batch`).
- `AuditDocumentCache.php`: default `3221225472` (3 GB).

Ese SDD sí está implementado y su checklist está completo. Es probable que la certificación "100%" corresponda a **SDD-SCALE-001**, no a **SDD-BATCH-001**. Si el equipo sostiene lo contrario, debe aportar: rama/PR, SHA de commit, digest de imagen y evidencia de los símbolos de la Sección 7.

---

## 12. Conclusión

1. El SDD-BATCH-001 está en estado **diseño aprobado, no implementado**; su propio texto condiciona el despliegue a `SUP-03` y a una re-verificación independiente.
2. La producción ejecuta el diseño anterior (imagen = `main`), lo que explica los incidentes del 20–21 de noviembre y los estados anómalos corregidos manualmente.
3. La afirmación de "cumplimiento del 100%" **no es verificable** con el repositorio ni con el despliegue; se solicita evidencia formal antes de aceptarla.
4. Mitigaciones aplicadas por esta sesión (no equivalen al SDD): `.env` con `LOCK_TTL=7200`, `RECLAIM_IDLE=10800000`, `CHUNK_SIZE=500`; cierre manual de `b9a18ddd` y `ed15eb4e`; corrección de `5c4ca134`.

---

## 13. Recomendaciones

1. **Rechazar la certificación** hasta recibir evidencia por REQ (commit + archivo:línea + test + imagen).
2. Si la implementación existe en otro lado, entregar rama/PR y digest; este informe debe repetirse sobre ese artefacto.
3. Si no existe, planificar implementación TDD por fases (REQ-04/01/06 primero) y desplegar con el cutover/rollback que el SDD exige.
4. Mientras tanto, mantener las mitigaciones operativas y monitorizar: locks `batch:claim:*` > TTL, `done>total`, jobs `processing` sin `updated_at` > 6 h y entradas de `jobs:index` sin estado.

---

## 14. Anexo — reproducción

```powershell
git rev-parse HEAD; git rev-parse origin/main
git log --all --oneline -S claimJobGenerationFencedLock
git log --all --oneline -S storeFrozenCandidates
git stash show --stat 'stash@{0}'
Get-ChildItem app,bin,tests -Recurse -Include *.php |
  Select-String -Pattern 'JobLockClaimStatus|reapStaleJob|storeFrozenCandidates' -CaseSensitive
Test-Path app/Services/Audit/Pipeline/JobLockClaimStatus.php
```

```bash
docker compose ps --format '{{.Service}}|{{.Image}}'
docker compose exec -T php grep -c resolveLockedConcurrentResponse app/Services/Audit/AuditBatchOrchestrator.php
docker compose exec -T php ls app/Services/Audit/Pipeline/ | grep -E 'JobLockClaim|JobGenerationBusy'
```
