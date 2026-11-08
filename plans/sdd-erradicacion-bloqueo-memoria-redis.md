# SDD-OPS-025: Erradicación Estructural y Definitiva del Bloqueo de Memoria Redis al 80%

**ID:** `SDD-OPS-025`  
**Fecha:** 2026-11-06  
**Estado:** Aprobado para Implementación Limpia (`/clean-rebuild-policy`)  
**Componentes Afectados:**

- `app/Services/Audit/Pipeline/AuditTaskCommitter.php`
- `app/Services/Audit/Pipeline/AuditTaskOutbox.php`
- `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php`
- `app/Services/Audit/Pipeline/AuditExecutionInspector.php`
- `app/Services/Audit/Pipeline/AuditTaskRunner.php`
- `app/Services/Audit/Pipeline/AuditTaskState.php`
- `app/Services/Audit/Pipeline/AuditTaskEffects.php`
- `app/Services/Audit/Pipeline/FairAuditTaskQueue.php`
- `app/Services/Audit/Pipeline/AuditEventPublisher.php`
- `app/Services/Audit/Pipeline/DocumentAuditOrchestrator.php`
- `app/Services/Audit/GeminiGateway.php`
- `app/Controllers/ObservabilityController.php`
- `app/Models/InvoicesModel.php`
- `.env.example`, `.env`, `.github/workflows/deploy-production.yml`, `AGENTS.md`

---

## 1. Diagnóstico Forense y Evidencia Empírica en Producción (`172.16.0.3`)

Tras purgar los Redis Streams en producción (`19.20 GiB -> 17.40 GiB`), el procesamiento de nuevas facturas llevó nuevamente a Redis a `20,635,984,632` bytes (`19.22 GiB` / `80.0782%` de `24.00 GiB`) en menos de 3 horas, a pesar de que todos los streams tenían `queueDepth = 0` y `lag = 0`.

El muestreo forense de **20,004 llaves** en producción demostró que el **97.2% de las llaves en Redis** eran artefactos internos de coordinación de tareas (`v2`) que se seguían almacenando innecesariamente después de completada la tarea y la auditoría:

1. **Almacenamiento e Infraestructura Redundante de `audit:commit:v2:*` / `AuditTaskCommitter::PENDING` / `repair()` (63% del peso en bytes):**
   - Cada recibo `audit:commit:v2:{taskId}:{fence}` almacenaba entre **5.6 KB y 150.6 KB** (promedio **35.2 KB**).
   - `AuditTaskCommitter::COMMIT` se ejecuta en un único script Lua atómico (`EVAL`) que valida y simula todas las transiciones antes de escribir. Escribir `audit:commit:v2:{taskId}:{fence}`, `audit:commits:v2:pending` (`AuditTaskCommitter::PENDING`), el estado intermedio `committing` (`AuditTaskState::COMMITTING`), hacer lecturas previas `$redis->get('audit:commit:v2:...')` en cada commit y ejecutar `repair()` en cada tick de `AuditTaskRunner` y `AuditExecutionMaintenance` es **100% redundante**: `KEYS[1]` (`audit:task:v2:{taskId}`) ya registra durablemente `current.state = 'completed'` y `current.fence = task.fence`.
2. **Retención Redundante de Planes Semánticos Consumidos y Andamiaje `v2` en Auditorías Terminales:**
   - Cuando `RulesEvaluationWorker` consume un plan semántico (`plan.state = 'consumed'`), la evaluación documental queda persistida en `audit:{id}:state` dentro del mismo commit atómico. Conservar el plan consumido (~27 KB) en `audit:semantic:v2:*` carece de consumidor.
   - Asimismo, una vez que la auditoría raíz (`audit:{id}:state`) alcanza un estado terminal (`completed`, `completed_with_errors`, `manual_review`, `error`, `failed`) y no tiene referencias activas (`SCARD(audit:execution:refs:{auditId}) == 0`), conservar en Redis el andamiaje interno (`audit:task:v2:*`, `audit:semantic:v2:*`, `gemini:attempt:v2:*`) durante 24h o 7 días es innecesario.
3. **Retención Redundante de Efectos Entregados y Campo `'state'` en `audit:outbox:v2:*`:**
   - Una vez que `AuditTaskOutbox` publica (`PUBLISH`) o confirma (`CONFIRM`) un efecto y lo retira atómicamente de `audit:outbox:v2:pending` (`ZREM`) y de `audit:execution:refs:{auditId}` (`SREM`), conservar `audit:outbox:v2:{effectId}`, `audit:outbox:v2:sent:{effectId}` o el campo `'state' = 'pending' | 'sent'` en Redis es **100% redundante**.
4. **Asimetría de `SCAN ... MATCH ... COUNT` y Falta de Auto-Drenaje Activo en `AuditExecutionMaintenance`:**
   - En Redis, `SCAN cursor MATCH pattern COUNT 1000` inspecciona 1,000 _buckets_ de la tabla hash global antes de filtrar por patrón, requiriendo múltiples pasos por tick (`scanPattern`) y ejecución inmediata cuando `AuditAdmissionGuard::orchestrationAtCapacity()` es `true`.
5. **Desalineación de los 3 TTLs de Retención (`86400s`) y Código Muerto Residual:**
   - `AuditExecutionMaintenance::gc()` calcula `$retention = max(AUDIT_STATE_TTL, AUDIT_JOB_TTL, AUDIT_PERSISTENCE_QUEUE_TTL)` para los estados consultables (`audit:*:state` y `job:*:state`), por lo que los tres deben estar alineados en `86400` (24h).
   - Código sin valor en el pipeline: operación no-op `'protect_active_ttl'` en `AuditTaskEffects`/`AuditTaskCommitter`, acumulación de `$artifact['attempt_ids']` sin lector en `GeminiGateway`, emisión duplicada de `telemetryPublisher->started()` en `DocumentAuditOrchestrator::handle()`, y `DROP TABLE #FACT` / `#FACT1` inexistentes (en lugar de `#ADJ`) en `InvoicesModel`.

---

## 2. Diseño Determinista de la Solución (5 Pilares)

### Pilar 1: Eliminación Total de `audit:commit:v2:*`, `AuditTaskCommitter::PENDING`, `repair()` y `AuditTaskState::COMMITTING`, más `DEL` Inmediato de Planes Semánticos Consumidos

- En `AuditTaskCommitter`:
  - `commit()` valida efectos y ejecuta directamente el `EVAL` atómico `COMMIT` con 16 llaves base (`KEYS[1..12]` de `FairAuditTaskQueue`, `KEYS[13] = self::OUTBOX`, `KEYS[14] = SemanticEvaluationStore::PLANS`, `KEYS[15] = waiting`, `KEYS[16] = refs`), sin round-trip previo `GET audit:commit:v2:*`.
  - Se eliminan `AuditTaskCommitter::PENDING`, `repair()`, `receiptEffects()`, `AuditTaskState::COMMITTING`, `AuditExecutionInspector::reconcileCommitReceipts()` y la operación muerta `'protect_active_ttl'`.
  - Cuando un cambio semántico alcanza `plan.state == 'consumed'`, `COMMIT` elimina inmediatamente `KEYS[change.key_index]` (`DEL`), retira de `SemanticEvaluationStore::PLANS` (`SREM`) y de `audit:execution:refs:{auditId}` (`SREM`), y decrementa `previous.state` en `audit:semantic:v2:counts`.

### Pilar 2: Eliminación Inmediata (`DEL`) de `audit:outbox:v2:*` al Publicar o Confirmar y Supresión del Campo `'state'` (`AuditTaskOutbox`)

- En `AuditTaskOutbox::PUBLISH` y `AuditTaskOutbox::CONFIRM`: al publicar en el stream (`XADD`) o confirmar el envío a la cola de persistencia/DLQ, se elimina atómicamente `KEYS[1]` (`DEL audit:outbox:v2:{effectId}`) junto con `ZREM` y `SREM`, sin almacenar `audit:outbox:v2:sent:*` ni el campo `'state'` en el hash de outbox.

### Pilar 3: Retiro en Un Solo Paso del Andamiaje `v2` de Auditorías Terminales, Caché Local de `getAudit()`, Barrido Multi-Paso (`GC_SCAN_STEPS = 10`) y Auto-Drenaje Activo (`AuditExecutionMaintenance`)

- `audit:task:v2:*` (`completed`/`failed`), `audit:semantic:v2:*` (huérfanos/terminales) y `gemini:attempt:v2:*` (`!= inflight`) se eliminan en un solo paso en `gc()` en cuanto su auditoría padre (`audit:{id}:state`, cacheada por ciclo) es terminal y `SCARD(audit:execution:refs:{auditId}) == 0`.
- `GC_PAGE_SIZE = 1000` y `GC_SCAN_STEPS = 10`, con ejecución inmediata de `gc()` y `trimWorkStreams()` en `tick()` cuando `orchestrationAtCapacity()` es `true`.

### Pilar 4: Unificación de los 3 TTLs de Retención de Estado y Caché Semántica a 24 Horas (`86400s`)

- `AUDIT_STATE_TTL = 86400`, `AUDIT_JOB_TTL = 86400`, `AUDIT_PERSISTENCE_QUEUE_TTL = 86400` (incluyendo las constantes `DEFAULT_*_TTL_SECONDS = 86400` en `AuditStateStore`, `BatchJobStore` y `AuditPersistenceQueue`).
- Reducción del TTL de caché semántica de 30 días (`2592000s`) a 24 horas (`86400s`) en `SemanticFlightStore::WRITE` y `SemanticMatchJudge::CACHE_TTL`.

### Pilar 5: Poda Determinista de Streams (`AuditEventPublisher`), Eliminación de Streams Terminales Huérfanos (`audit.results.*`) y Compactación Integral Post-Commit / Post-Persistencia

- `DEFAULT_STREAM_MAXLEN = 3000` y `WORK_STREAM_LUA` con `LIMIT 5000`.
- Eliminación de los streams sin consumidor `STREAM_RESULTS_PRIORITY` (`audit.results.priority`) y `STREAM_RESULTS_BATCH` (`audit.results.batch`), `publishTerminalOnce()`, `publishBatchTerminalEventIfNeeded()`, `claimBatchTerminalEvent()` y recibos `audit:terminal:v2:sent:*`.
- Compactación del efecto "bola de nieve" en payloads y planes semánticos:
  - `DocumentExtractionWorker` excluye `fuente_verdad`, `extraction_contract` y `system_prompt` al publicar `document_extracted` (y `fields_config`, `visual_checks` al publicar `document_rejected`).
  - `RulesEvaluationWorker` y `SemanticEvaluationStore` excluyen `extraction_contract`, `extraction_result`, `normalized_result` y `system_prompt` de `snapshot.document_state` y evitan triplicar `normalized_payload` en `source_event`.
  - `AuditTaskCommitter::COMMIT` limpia `stage_artifact` y `original_event.payload` al marcar la tarea como `completed`, y depura `extraction_contract`, `fields_config`, `system_prompt` y `fuente_verdad.items` al evaluar cada documento (`mark_evaluated`).
- Compactación post-persistencia de `audit:{id}:state` (`AuditStateStore::COMPLETE_AUDIT_LUA` y `AuditFailureFinalizer`): elimina `documents`, `rules_evaluated_result`, `audit_result`, `operation_receipts`, `event_timings`, `synthetic_rejections` y `reservation_token` al entrar en estado terminal.
- Limpieza inmediata de `KEYS[4]` (`:seen`) y `KEYS[6]` (`:slots`) en `AuditPersistenceQueue::ADVANCE_LUA` para auditorías individuales (`slots == 0`) y recolección de `audit.persistence:{queue}:job:{jobId}*` en `AuditExecutionMaintenance::gc()`.
- Compactación de `job:{jobId}:state['audits'][auditId]` en `BatchJobStore`: conserva únicamente `dis_det_nro`, `status` y `failed_stage`.
- Depuración de `$artifact['attempt_ids']` en `GeminiGateway`, telemetría duplicada en `DocumentAuditOrchestrator::handle()`, y limpieza de `#ADJ` en lugar de `#FACT`/`#FACT1` en `InvoicesModel`.
- **Refactorización Clean Code sobre el diff `.php` (`SDD-OPS-025`)**: extracción de `FINALIZE_FAILED_AUDIT_LUA` en `AuditFailureFinalizer`, `TRANSIENT_EXTRACTION_PAYLOAD_KEYS`/`TRANSIENT_REJECTION_PAYLOAD_KEYS` + helpers en `DocumentExtractionWorker`, `SEMANTIC_SNAPSHOT_EXCLUDED_DOCUMENT_KEYS` + `buildSemanticSnapshot()` en `RulesEvaluationWorker`, `SOURCE_EVENT_ROUTING_KEYS` en `SemanticEvaluationStore`, `GC_DELETE_*_LUA` en `AuditExecutionMaintenance`, `CACHE_TTL_SECONDS` en `SemanticFlightStore` y alineación del recibo DLQ en `AuditEventConsumer::TERMINAL_ACK_LUA` a `86400s`.
