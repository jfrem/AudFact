# Especificación SDD — Despachador Equitativo Multi-Job y Multi-Cliente (Fair Queuing)

## Triage y Clasificación del Cambio

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :---: | :--- |
| **Tipo** | `Feature / Refactor` | Unificación de la ingesta batch y entrelazado Round-Robin a nivel de Job (`job_id`) para eliminar Head-of-Line Blocking entre jobs del mismo o diferentes clientes. |
| **Riesgo** | `Bajo` | No altera contratos de eventos JSON ni esquemas de persistencia en base de datos (`sqlsrv`). |
| **Persistencia afectada** | `No [CONFIRMADO]` | Cero migraciones SQL y cero cambios de DDL en `sqlsrv` (`vw_discolnet_dispensas`, `dbo.AudDispEst`). |
| **Contrato externo afectado** | `No [CONFIRMADO]` | Los eventos `batch_created`, `audit_created`, `batch_completed` y endpoints `/audit/jobs` mantienen intacto su payload JSON. |
| **Cambio arquitectónico** | `Sí [CONFIRMADO]` | Despacho equitativo $O(N)$ por Job (`job_id`) con recuperación durable de pendientes en Redis, lease atómico propietario de deduplicación y publicación estricta a DLQ. |
| **Producción afectada** | `Sí [CONFIRMADO]` | Aplica al pipeline distribuido en producción LAN (`172.16.0.3`) y desarrollo local Docker. |

---

## 1. Arquitectura de los Dos Flujos de Ingesta

El sistema dispone de dos flujos de ingesta batch que convergen en el stream `audit.inbox.batch`:

### Flujo 1 — Cron CLI Diario (`MultiClientBatchDispatcher`)
```
bin/schedule-daily-batches.php
  → MultiClientBatchDispatcher::dispatch()
    → discoverPendingUnpublishedBatches() // Recuperación durable paginada con event_id estable y filtro publication_status != published
    → discoverAndInitializeClients()     // Inicializa jobs por cliente elegible
    → prepareAndSealClientBatches()      // Keyset pagination SQL + reservas atómicas + event_id estable + sealJob
    → interleaveAndPublishAuditEvents()   // Round-Robin por $jobId en ventanas (chunks K=10..20) + markAuditPublishedInJob
```
- **Proceso**: CLI de ejecución periódica (PHP single-process).
- **Entrelazado**: Controlado en memoria dentro de `interleaveAndPublishAuditEvents()`. Itera Round-Robin sobre `$readyClients[$jobId]` despachando ventanas de tamaño `$chunkSize`.
- **Garantías de Consistencia, Durabilidad y No-Duplicación (QUAL-004)**:
  - **Identidad de Evento Estable (`event_id`)**: Cada auditoría genera un `event_id` estable en la fase de preparación y se registra en `$job['audits'][$auditId]['event_id']`.
  - **Estado Atómico de Publicación (`publication_status`)**: El job store registra el estado de publicación (`publication_status = 'pending' | 'published'`, `stream_id`, `published_at`). Tras un `publish()` exitoso en Redis Streams, se invoca `BatchJobStore::markAuditPublishedInJob()` de forma atómica.
  - **Recuperación Durable Paginada (`discoverPendingUnpublishedBatches`)**: Si el proceso CLI se interrumpe o finaliza abruptamente, el despachador pagina sobre el índice `jobs:index` y recupera **exclusivamente** las auditorías con `publication_status == 'pending'` (ignorando aquellas ya publicadas), reutilizando el **mismo `event_id` estable** para garantizar que la deduplicación del consumidor proteja el pipeline contra republicaciones repetidas.
  - **Shift Confirmado y Reconciliación Protegida**: Los eventos se retiran de `$state['prepared_events']` **únicamente** tras confirmación de publicación o reconciliación confirmada en Redis (`$reconciled === true`). Si Redis no responde, el estado y las reservas se conservan intactos.

### Flujo 2 — API REST Asíncrona (`AuditBatchOrchestrator`)
```
POST /audit/async → AuditController::async()
  → Publica batch_requested en audit.batch.inbox
  → BatchRequestedWorker::handle() (Consumer pool)
    → AuditBatchOrchestrator::enqueueBatch()
      → Keyset pagination SQL + reservas atómicas + event_id estable + sealAndPublishBatch() + markAuditPublishedInJob()
```
- **Proceso**: Ejecución distribuida sobre réplicas del worker `batch-workers`.
- **Entrelazado**: Concurrencia natural a nivel de procesos/workers en el stream Redis `audit.inbox.batch`.

---

## 2. Resiliencia, Deduplicación Atómica y DLQ (QUAL-009, QUAL-011, QUAL-010)

En `AuditEventConsumer` y `DocumentExtractionWorker`:

1. **Lease Atómico de Procesamiento con Token Propietario (`claimEventProcessingLease`)**:
   - Cada adquisición genera un `$leaseToken` único (UUID v4) y adquiere un lease exclusivo vía script Lua por 900s (`DEFAULT_LEASE_TTL_SECONDS`, 3x el timeout de 300s de Gemini):
     - `'completed'`: El evento ya culminó su ciclo de vida; se confirma con `xAck` y se omite.
     - `'processing'`: Otra réplica posee actualmente el lease activo; se omite la ejecución duplicada sin confirmar con `xAck`.
     - `'acquired'`: Concede el lease exclusivo asociado a `processing:{$leaseToken}`.
   - Ante errores de conexión en Redis, degrada a modo fail-safe retornando `'processing'` para no procesar concurrentemente sin lock verificado.
2. **Confirmación Obligatoria de Titularidad antes de ACK (QUAL-009)**:
   - `markEventCompleted()` valida mediante Lua atómico (`compare-and-complete`) que la clave `dedup:{$group}:{$eventId}` siga perteneciendo a `processing:{$leaseToken}` antes de escribir `completed`.
   - Si el lease expiró o fue reclamado por otro worker, `markEventCompleted()` retorna `false`, abortando el ACK y lanzando excepción para retener el mensaje en Redis PEL.
3. **Liberación con Compare-And-Delete (`releaseEventLease`)**:
   - Ante fallos recuperables (`attempts < maxRetries`), un script Lua valida que el valor coincida exactamente con `processing:{$leaseToken}` antes de ejecutar `DEL`, evitando borrar el lease si fue adquirido por otra réplica.
4. **Publicación Terminal a DLQ Obligatoria antes de ACK (QUAL-011)**:
   - `sendToDeadLetter()` propaga cualquier excepción si la publicación al stream DLQ falla. Si DLQ no confirma la recepción del evento, el consumidor **no** marca el evento como completado y **no** ejecuta `xAck`, reteniendo el mensaje en Redis PEL para reintento garantizado.
5. **Eliminación Activa de BLOBs en Redis (QUAL-010)**:
   - `DocumentExtractionWorker` invoca explícitamente `del($blobStorageKey)` tras procesar la extracción documental o rechazo, previniendo acumulación en memoria RAM de Redis.

---

## 3. Matriz de Componentes y Pruebas Verificadas

| Componente | Archivo | Responsabilidad | Cobertura Automatizada |
| :--- | :--- | :--- | :--- |
| `MultiClientBatchDispatcher` | `app/Services/Audit/MultiClientBatchDispatcher.php` | Despacho Round-Robin por `$jobId` con recuperación durable paginada por `ZSCAN` cursor seguro, `event_id` estable con fallback determinístico a `auditId`, `publication_status`, shift confirmado, rollback y compensaciones parciales estrictas con aislamiento granular de recursos de compensación y reconciliación en jobs terminales (QUAL-015), confirmación durable de publicación (QUAL-016) y fases estructuradas CLI (QUAL-014). Entrypoint CLI productivo en `bin/schedule-daily-batches.php`. | `MultiClientBatchDispatcherTest` (35 tests, 183 assertions). |
| `BatchJobStore` | `app/Services/Audit/Pipeline/BatchJobStore.php` | Almacenamiento de jobs, `registerAuditInJob` con `event_id` y `publication_status`, `markAuditPublishedInJob`, `listJobIds` con cursor `ZSCAN`, reconciliación atómica en Lua (`RECONCILE_FAILED_AUDIT_IN_JOB_LUA`), reapertura atómica en job para reproceso DLQ (`REOPEN_AUDIT_IN_JOB_LUA`) (QUAL-018), liberación idempotente de reservas (`RELEASE_AUDIT_RESERVATION_LUA`) y `deleteJob` atómico en Lua (`ZREM` + `DEL`). | `BatchJobStoreMetricsTest` (3 tests, 16 assertions). |
| `AuditEventConsumer` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | Consumo con lease atómico propietario, ejecución de hook `afterTerminalFailure()` antes de deduplicación (QUAL-018), compare-and-delete/complete atómicos fail-closed con token obligatorio (QUAL-009), `renewEventLease`, `renewActiveLease`, verificación de fencing (`isCurrentLeaseValid`), TTL configurable (`AUDIT_CONSUMER_LEASE_TTL_SECONDS`), fail-closed ante carreras SETNX (QUAL-009), retención en PEL ante fallos en DLQ (QUAL-011) e idempotencia terminal compatible ante reentregas con flags SETNX para DLQ, finalización y hook (QUAL-011). | `AuditEventConsumerTest` (38 tests, 81 assertions). |
| `AuditBatchOrchestrator` | `app/Services/Audit/AuditBatchOrchestrator.php` | Orquestación atómica por lote individual API, `event_id` estable y registro/validación de publicación vía `markAuditPublishedInJob` (QUAL-016). | `AuditBatchOrchestratorTest` (6 tests, 43 assertions). |
| `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | Extracción documental, renovación activa de lease (`renewActiveLease`), guarda de fencing en caminos exitosos y de rechazo (`ensureActiveLease`) (QUAL-009) y liberación activa de BLOBs en Redis en todos los caminos terminales (QUAL-010). | `DocumentExtractionWorkerTest` (29 tests, 143 assertions). |
| `AttachmentDownloadWorker` | `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php` | Descarga de adjuntos, renovación de lease y persistencia de BLOBs con validación de fencing. | `AttachmentDownloadWorkerTest` (3 tests, 15 assertions). |

---

## 4. Criterios de Aceptación y Estado

1. **Prueba de Recuperación Durable, No-Duplicación y Huecos en ZSET (QUAL-004)**: `testDiscoversAndRecoversPendingAuditsFromSealedJobs`, `testDiscoversAndRecoversPendingAuditsAcrossMultiplePages`, `testDiscoversAndRecoversPendingAuditsWhenFirstPageIsEmptyWithNonZeroCursor` y `testDiscoversAndRecoversPendingAuditsWithHolesInFirstPage` verifican la recuperación paginada por `ZSCAN` cursor seguro, atravesando huecos de miembros expirados o eliminados y páginas con cursor no cero y lista vacía sin interrupción prematura ni desplazamientos de offset y reutilizando el `event_id` estable (o fallback determinístico a `auditId`). `[PASS]`
2. **Prueba de Depuración del Índice, Borrado y Transición de Métricas (QUAL-004 & QUAL-008)**: `testEmptyBatchSealingAtomicallyTransitionsMetrics`, `testCompletionMetricsTransitionUsesPreviousJobStatus`, `testListJobIdsPassesCursorAndLimitToLuaAndReturnsArray` y `testDeleteJobExecutesAtomicLuaScript` verifican la transición atómica de métricas, paginación y remoción atómica con scripts Lua (`ZREM` + `DEL`). `[PASS]`
3. **Prueba de Registro y Confirmación Durable de Publicación (QUAL-004, QUAL-012 & QUAL-016)**: `testSuccessfulPublishingMarksAuditPublishedInJobStore`, `testBatchOrchestratorJobIsRecognizedAsPublishedAndNotDuplicatedByDispatcher`, `testEnqueueBatchHandlesMarkAuditPublishedInJobReturningFalseWithoutHaltingPublishing` y `testMarkAuditPublishedInJobReturningFalseIsLoggedAndStillEnqueued` verifican el registro y manejo robusto de `markAuditPublishedInJob` en streams y estado durable. `[PASS]`
4. **Prueba de Caída de Redis y Shift Confirmado (QUAL-004)**: `testHandlesSharedRedisOutageWithoutDroppingUnreconciledEvents` y `testFailedReconciliationPreservesStateAndReservation` verifican que los eventos no confirmados se preservan en memoria y no eliminan estado de base de datos. `[PASS]`
5. **Prueba de Exclusión, Propiedad, Renovación Activa y Fencing en Rechazos (QUAL-008 & QUAL-009)**: `testLeaseCurrentlyProcessingByAnotherReplicaSkipsWithoutAck`, `testLeaseCompletedByAnotherReplicaSkipsAndAcksMessage`, `testSetnxRaceLostReturnsProcessingInFallback`, `testMarkEventCompletedFailsWhenKeyAlreadyCompletedOrTokenChanged`, `testMarkEventCompletedFailsClosedWhenLuaThrows`, `testReleaseEventLeaseFailsClosedWhenLuaThrows`, `testReleaseEventLeaseSucceedsWhenTokenMatchesLua`, `testRenewEventLeaseFailsClosedWhenLuaThrows`, `testRenewEventLeaseExtendsTtlWhenTokenMatchesLua`, `testRenewActiveLeaseDuringHandlerExecution`, `testRenewActiveLeaseReturnsFalseWhenNoActiveLease`, `testWorkerRenewsActiveLeaseDuringExtraction`, `testIntegrityRejectionThrowsAndPreventsMutationWhenLeaseStolen`, `testGeminiRejectionThrowsAndPreventsMutationWhenLeaseStolen` e `testEnsureActiveLeaseSucceedsWhenTokenMatches` verifican exclusión mutua, retención en PEL, renovación positiva en tiempo de ejecución del handler y a nivel worker, fail-closed ante errores/carreras de Redis y detección de fencing tokens tanto en extracción exitosa como en rechazos documentales. `[PASS]`
6. **Prueba de Pérdida de Ownership (QUAL-009)**: `testEnsureActiveLeaseThrowsWhenTokenStolenByAnotherReplica`, `testEnsureActiveLeaseThrowsWhenLeaseExpiredInRedis`, `testRenewEventLeaseFailsWhenKeyExpiredOrStolen` y `testMarkEventCompletedFailsWhenOwnershipLost` verifican que la pérdida de propiedad impida el ACK y conserve el mensaje en PEL. `[PASS]`
7. **Prueba de Fallo en Publicación DLQ, Hook Terminal e Idempotencia Robusta (QUAL-011 & QUAL-018)**: `testDeadLetterPublishFailurePreventsAckAndMarkCompleted`, `testAfterTerminalFailureExecutedBeforeMarkEventCompleted`, `testAfterTerminalFailureExceptionRetainsEventInPEL`, `testCompleteAuditReturnsTrueOnAlreadyTerminalLuaReturnTwo`, `testReleaseAuditReservationReturnsTrueOnMissingKeyLuaReturnTwo`, `testConsecutiveTerminalDeliveriesDoNotDuplicateDlqPublication`, `testConsecutiveTerminalDeliveriesDoNotDuplicateAuditFailedEvent`, `testConsecutiveTerminalDeliveriesDoNotReExecuteHook`, `testFinalizeDeadLetterFailureCleansUpClaimAndDoesNotAck`, `testReprocessReopensFailedAuditBeforePublishing`, `testReprocessReopensAuditInJobWhenJobIdPresent`, `testReprocessFailsClosedAndDoesNotPublishWhenReopenAuditReturnsFalse` y `testReprocessFailsClosedCompensatesStateStoreAndDoesNotPublishWhenReopenJobReturnsFalse` verifican:
   - Que si la publicación DLQ falla no se confirma ACK y se conserva en PEL.
   - Que el modelo de idempotencia terminal (QUAL-011) usa ciclo de vida `processing:<leaseToken>` con ownership y limpieza fail-closed (`cleanupTerminalActionClaim`) si `completeAudit`, `markAuditCompletedInJob` o la publicación DLQ fallan, evitando flags prematuros y garantizando reintentos limpios en reentregas PEL.
   - Que el hook `afterTerminalFailure()` se ejecuta antes de la deduplicación con ownership idempotente.
   - Que el reproceso DLQ (QUAL-018) opera fail-closed: aborta con HTTP 409 sin republicar si la auditoría o el job no pueden reabrirse, y ejecuta rollback compensatorio en `AuditStateStore` si el job falla post-reapertura de auditoría. `[PASS]`
8. **Prueba de Eliminación Activa de BLOBs (QUAL-010)**: `testActivelyDeletesBlobFromRedisOnSuccessfulExtraction`, `testActivelyDeletesBlobFromRedisOnIntegrityRejection` y `testActivelyDeletesBlobFromRedisOnGeminiRejection` verifican la invocación de `del` sobre la llave del BLOB en todos los flujos de éxito y rechazo. `[PASS]`
9. **Prueba de Rollback Aislado, Compensación Granular y Reconciliación en Jobs Terminales (QUAL-015)**: `testHealthyBatchSealsWithoutCompensationPendingMetadata`, `testCrashAfterSealingHealthyBatchRecoversAllAuditsIntactWithoutDeletion`, `testBatchWithFailedCompensationSealsWithOnlyUncompensatedResources`, `testEnrollmentCompensationOnlyTracksReservationWhenAuditDeleteSucceeds`, `testEnrollmentCompensationOnlyTracksAuditWhenReservationReleaseSucceeds`, `testReconcilePendingCompensationsRunsOnTerminalCompletedJob`, `testReconcileIndividualAuditCompensationClearsPendingDebt`, `testIsolatedRollbackExecutesAllStepsAndRetainsIdempotencyOnPartialFailure`, `testIsolatedRollbackRetainsIdempotencyWhenCleanupReturnsFalse`, `testPartialRollbackRetainsTrackingWhenDeleteAuditOrReleaseReservationReturnsFalse` y `testFailedReconciliationPreservesEventWhenDeleteAuditOrReleaseReservationReturnsFalse` verifican:
   - Que un batch sano jamás persiste metadatos de compensación pendiente.
   - Que `compensateFailedEnrollment()` retorna un array granular `{audit_deleted, reservation_released}` aislando únicamente los recursos con fallo real.
   - Que `RECONCILE_FAILED_AUDIT_IN_JOB_LUA` y el fallback en `MultiClientBatchDispatcher` eliminan atómicamente `compensation_pending`, `compensation_dis_id` y `compensation_token` de `auditState` y persisten el job en Redis, extinguiendo la deuda para evitar detecciones redundantes en scans posteriores. `[PASS]`
10. **Prueba de Callback Estructurado CLI (QUAL-014)**: `testProgressCallbackReceivesAllStructuredPhasesAndPayloads` verifica la emisión consistente de todas las fases (`recovery_started`, `discovery_started`, `client_discovered`, `preparation_started`, `client_prepared`, `publishing_started`, `chunk_published`) con contratos alineados con `schedule-daily-batches.php`. `[PASS]`
11. **Integración CLI y Despachador Productivo (QUAL-012)**: `bin/schedule-daily-batches.php` conectado directamente a `MultiClientBatchDispatcher::dispatch()` con feedback visual y flags de ejecución (`--date-from`, `--date-to`, `--limit`, `--chunk-size`, `--dry-run`). `[PASS]`
12. **Guardrail de Variables de Entorno (QUAL-013)**: `AUDIT_CONSUMER_LEASE_TTL_SECONDS` configurada en `.env.example`, documentada en `AGENTS.md` y sincronizada en GitHub Remoto (`gh variable set`). `[PASS]`
13. **Higiene EOL y .gitattributes (QUAL-021)**: `.gitattributes` configurado con `* text=auto eol=lf` y reglas explícitas para `.php`, `.json`, `.md`, `.sql`, `.sh`, `.yml` eliminando inconsistencias y churn de finales de línea entre plataformas Windows y Linux. `[PASS]`
14. **Suite Completa PHPUnit**: 629 tests, 2141 assertions, 0 errores, 0 fallos (2 skipped). `[PASS]`
15. **Validación de Skills**: 21/21 PASS (8 bundles). `[PASS]`
