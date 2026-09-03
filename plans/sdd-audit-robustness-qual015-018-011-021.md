# SDD — Robustez de Consistencia Distribuida: QUAL-015, QUAL-018, QUAL-011, QUAL-021

> **Rama**: `feat/fair-queuing-multiclient-batch`
> **Base**: `2cff9a5`
> **Fecha**: 2026-09-02
> **Origen**: Auditoría externa de calidad — veredicto No-Go condicionado

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Bug (corrección de regresiones de consistencia en pipeline distribuido) |
| Riesgo | **Alto** — Afecta invariantes de estado distribuido (Redis ↔ SQL Server ↔ Streams) |
| Persistencia afectada | **Sí** — Redis keys (`audit:{id}:state`, `job:{id}:state`, streams DLQ) y SQL Server (`AudDispEst`) `[CONFIRMADO]` |
| Contrato externo afectado | **No** — Los contratos REST y de eventos internos no cambian de forma `[CONFIRMADO]` |
| Cambio arquitectónico | **No** — Son correcciones de invariantes dentro de la arquitectura existente `[CONFIRMADO]` |
| Producción afectada | **Sí** — Producción LAN `admon@172.16.0.3` `[CONFIRMADO]` |
| Requiere 0.3.1 (cobertura de abstracciones) | **No** — No se reemplazan mapeos estáticos por abstracciones dinámicas `[CONFIRMADO]` |

**Calibración**: Riesgo Alto → Descubrimiento completo + infraestructura + operación + rollback. Todas las secciones obligatorias.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| # | Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `MultiClientBatchDispatcher.php` | `app/Services/Audit/MultiClientBatchDispatcher.php` | MODIFIED | Dispatcher round-robin de lotes multi-cliente | L554-647 (`enrollCandidateInvoices`), L655-703 (`compensateFailedEnrollment`), L1277-1348 (`discoverPendingUnpublishedBatches`), L1355-1426 (`reconcilePendingCompensationsInJob`) | Sí |
| 2 | `AuditStateStore.php` | `app/Services/Audit/Pipeline/AuditStateStore.php` | MODIFIED | Estado Redis de auditoría individual | L107-110 (`deleteAudit`), nuevo método `reopenAuditForReprocess` | Sí |
| 3 | `AuditEventConsumer.php` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | MODIFIED | Base abstracta de consumers con deduplicación y lease | L492-596 (`handleFailure`), L837-913 (`finalizeDeadLetterAudit`), L955-999 (`sendToDeadLetter`) | Sí |
| 4 | `AuditDlqController.php` | `app/Controllers/AuditDlqController.php` | MODIFIED | Controlador REST de DLQ (listado y reproceso) | L74-133 (`reprocess`) | Sí |
| 5 | `BatchJobStore.php` | `app/Services/Audit/Pipeline/BatchJobStore.php` | MODIFIED | Estado Redis de jobs batch | Nuevo método `reopenAuditInJob` | Sí |
| 6 | `AuditPersistenceWorker.php` | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | IMPACTED | Worker de persistencia final SQL/Redis | L61-184 (`handle`), L102-110 (`completeAudit` call), L186-198 (`afterTerminalFailure`) | Sí |
| 7 | `JsonRedisStoreTrait.php` | `app/Services/Audit/Pipeline/JsonRedisStoreTrait.php` | INSPECTED | Trait de serialización JSON y ejecución Lua | Sin cambios — `runScript` con `acceptValues` ya funcional | Sí |
| 8 | `RedisClient.php` | `core/RedisClient.php` | INSPECTED | Singleton Redis con Predis | L383-396 (`del`): siempre retorna `true` si no lanza excepción — ya idempotente | Sí |
| 9 | `AuditPersistenceQueue.php` | `app/Services/Audit/Pipeline/AuditPersistenceQueue.php` | INSPECTED | Cola serializada de persistencia por job | L35-37 (`reprocess`): usado en `AuditDlqController::reprocess()` | Sí |
| 10 | `.gitattributes` | `.gitattributes` | NEW | Normalización de EOL | Archivo completo | N/A (nuevo) |
| 11 | `MultiClientBatchDispatcherTest.php` | `tests/Services/Audit/MultiClientBatchDispatcherTest.php` | MODIFIED | Tests unitarios del dispatcher | Nuevos test methods | Sí |
| 12 | `AuditEventConsumerTest.php` | `tests/Services/Audit/Events/AuditEventConsumerTest.php` | MODIFIED | Tests unitarios del consumer | Nuevos test methods | Sí |
| 13 | `AuditDlqControllerTest.php` | `tests/Controllers/AuditDlqControllerTest.php` | MODIFIED | Tests unitarios del controlador DLQ | Actualización por nuevo flujo de `reprocess()` | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `compensateFailedEnrollment` | 4 refs en `MultiClientBatchDispatcher.php` (3 call sites + 1 declaración) | grep `*.php` |
| Búsqueda por símbolo | `deleteAudit` | 24 refs: `AuditStateStore` (def), `MultiClientBatchDispatcher` (6 calls), `AuditBatchOrchestrator` (1), `AuditController` (1), tests (14) | grep `*.php` |
| Búsqueda por símbolo | `afterTerminalFailure` | 14 refs: `AuditEventConsumer` (def + 1 call), `AuditPersistenceWorker` (override), tests (10) | grep `*.php` |
| Búsqueda por símbolo | `sendToDeadLetter` | 2 refs: `AuditEventConsumer` (def + 1 call) | grep `*.php` |
| Búsqueda por símbolo | `reopenAudit` | 0 refs (método nuevo) | grep `*.php` |
| Búsqueda por símbolo | `markEventCompleted` | 5 refs: `AuditEventConsumer` (def + 2 calls + 2 logs) | grep `*.php` |
| Búsqueda en configuración | `.gitattributes` | No existe | `Test-Path` |
| Búsqueda textual | `setnx` | `RedisClient::setnx()` L363 (lock atómico con TTL) | grep `core/RedisClient.php` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo |
| --- | --- | --- | --- | --- | --- | --- |
| `MultiClientBatchDispatcher` | `AuditStateStore::deleteAudit` | `Pipeline/AuditStateStore.php` | L107-110 | Directa | Estática | Repo local |
| `MultiClientBatchDispatcher` | `BatchJobStore::releaseAuditReservation` | `Pipeline/BatchJobStore.php` | (via `enrollCandidateInvoices`) | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditStateStore` (nuevo: `reopenAuditForReprocess`) | `Pipeline/AuditStateStore.php` | (nuevo) | Directa | Estática | Repo local |
| `AuditDlqController` | `BatchJobStore` (nuevo: `reopenAuditInJob`) | `Pipeline/BatchJobStore.php` | (nuevo) | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditEventPublisher::publish` | `Pipeline/AuditEventPublisher.php` | L120 | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditPersistenceQueue::reprocess` | `Pipeline/AuditPersistenceQueue.php` | L118 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::setnx` | `core/RedisClient.php` | L363 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `AuditEventPublisher::publishDeadLetter` | `Pipeline/AuditEventPublisher.php` | L978 | Directa | Estática | Repo local |
| `AuditPersistenceWorker` | `AuditStateStore::completeAudit` | `Pipeline/AuditStateStore.php` | L247-256 | Directa | Estática | Repo local |
| `AuditPersistenceWorker` | `COMPLETE_AUDIT_LUA` | `Pipeline/AuditStateStore.php` | L463-488 | Transitiva | Estática | Repo local |
| Tests | Todos los archivos MODIFIED | `tests/` | Múltiples | Directa | Estática | Repo local |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| `compensateFailedEnrollment` retorna `array` en vez de `bool` | `enrollCandidateInvoices` 3 call sites | `MultiClientBatchDispatcher.php` L594, L603, L632 | Runtime | Actualizar los 3 call sites para usar `$result['audit_deleted']` y `$result['reservation_released']` |
| `discoverPendingUnpublishedBatches` cambia orden de reconciliación | Jobs terminales que antes se filtraban | `MultiClientBatchDispatcher.php` L1306-1308 | Runtime | Mover reconciliación antes del filtro, pero mantener el `continue` para jobs no-pending/processing post-reconciliación (no se procesan para publicación) |
| `reopenAuditForReprocess` nuevo método en `AuditStateStore` | `AuditDlqController::reprocess` | `AuditDlqController.php` L106-124 | Test | Actualizar tests para verificar la reapertura |
| `sendToDeadLetter` con flag idempotente `setnx` | Reentregas PEL que antes publicaban duplicados en DLQ | `AuditEventConsumer.php` L955-999 | Test | Agregar test de reentrega idempotente |
| `finalizeDeadLetterAudit` con flag idempotente | Reentregas PEL que antes publicaban `audit_failed` duplicados | `AuditEventConsumer.php` L837-913 | Test | Agregar test de reentrega idempotente |
| `afterTerminalFailure` con flag idempotente | Reentregas PEL que reejecutan hook | `AuditEventConsumer.php` L548-560 | Test | Agregar test |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| Redis `DEL` | `DEL key` retorna entero con el número de claves eliminadas (0 si no existía) | Documental | [Redis DEL docs](https://redis.io/commands/del/) | Sí — `RedisClient::del()` ignora el valor de retorno y siempre retorna `true` si no hay excepción (`core/RedisClient.php` L390-391) `[CONFIRMADO]` |
| Redis `SET NX EX` | `SET key value NX EX ttl` retorna `OK` si se estableció, `nil` si la clave ya existía | Documental | [Redis SET docs](https://redis.io/commands/set/) | Sí — Usado para flags idempotentes de reentrega. `RedisClient::setnx()` retorna `bool` (`core/RedisClient.php` L363-378) `[CONFIRMADO]` |
| Redis Lua `EVAL` | Scripts Lua se ejecutan atómicamente en el contexto del servidor Redis | Documental | [Redis EVAL docs](https://redis.io/commands/eval/) | Sí — Los nuevos scripts `REOPEN_AUDIT_LUA` y `REOPEN_AUDIT_IN_JOB_LUA` usarán la misma semántica que `COMPLETE_AUDIT_LUA` `[CONFIRMADO]` |
| Predis `$client->del([key])` | Predis acepta array de claves para `DEL` | Empírica | `core/RedisClient.php` L390 usa `$this->client->del([$this->prefix . $key])` | Sí `[CONFIRMADO]` |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose con PHP-FPM + Redis standalone | `docker compose up` | Sí | Los cambios son internos a la lógica PHP; no afectan configuración Docker `[CONFIRMADO]` |
| CI (GitHub Actions) | PHPUnit con mocks de Redis y SQL Server | `vendor/bin/phpunit` | Sí | Tests usan mocks/stubs — sin dependencia de Redis real `[CONFIRMADO]` |
| Producción | Docker en `admon@172.16.0.3`, PHP-FPM/workers + Redis + SQL Server | Deploy vía `docker compose pull && docker compose up -d` | Sí | Los métodos nuevos usan la misma conexión Redis; `.gitattributes` no afecta runtime `[CONFIRMADO]` |
| Testing aislado | PHPUnit sin servicios externos | `vendor/bin/phpunit` | Sí | Idéntico a CI `[CONFIRMADO]` |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| `compensateFailedEnrollment` retorna `bool` | Confirmado | `MultiClientBatchDispatcher.php` L655-702 |
| 3 call sites de `compensateFailedEnrollment` registran ambos recursos como no compensados | Confirmado | `MultiClientBatchDispatcher.php` L594-596 (solo reserva), L603-605 (ambos), L632-634 (ambos) |
| `deleteAudit` delega a `RedisClient::del()` que siempre retorna `true` | Confirmado | `AuditStateStore.php` L107-110, `RedisClient.php` L383-396 |
| `reconcilePendingCompensationsInJob` se invoca después del filtro de status en `discoverPendingUnpublishedBatches` | Confirmado | `MultiClientBatchDispatcher.php` L1306-1313 |
| `COMPLETE_AUDIT_LUA` retorna `2` para estados terminales sin aplicar el patch | Confirmado | `AuditStateStore.php` L463-488 |
| `completeAudit` acepta `[1, 2]` como valores de éxito | Confirmado | `AuditStateStore.php` L247-256 |
| `AuditDlqController::reprocess()` no reabre la auditoría en Redis | Confirmado | `AuditDlqController.php` L74-133 — no hay llamada a reapertura |
| `sendToDeadLetter` usa UUID determinista pero `XADD` siempre crea nueva entrada | Confirmado | `AuditEventConsumer.php` L957-974 |
| `handleFailure` ejecuta `sendToDeadLetter` → `finalizeDeadLetterAudit` → `afterTerminalFailure` → `markEventCompleted` | Confirmado | `AuditEventConsumer.php` L521-592 |
| Si `markEventCompleted` falla, el mensaje permanece en PEL para reentrega | Confirmado | `AuditEventConsumer.php` L574-580 |
| `RedisClient::setnx()` disponible con semántica `SET NX EX` | Confirmado | `RedisClient.php` L363-378 |
| No existe `.gitattributes` en el repositorio | Confirmado | `Test-Path .gitattributes` → `False` |
| 3 archivos de test solo tienen cambios de EOL (CRLF/LF) | Confirmado | `git diff --ignore-space-at-eol` produce diff vacío para esos 3 archivos |

### 0.7 Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| (ninguna) | — | — |

### 0.8 Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| (ninguna) | — | — |

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Volumen histórico de eventos DLQ en producción | No se tiene acceso directo al Redis de producción durante el descubrimiento | No afecta la implementación; solo relevante para dimensionar TTL de flags idempotentes (86400s es conservador) |

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| --- | --- | --- | --- | --- |
| S1-001 | `RedisClient::del()` siempre retorna `true` cuando no hay excepción, incluso si la clave no existía | S1 | `core/RedisClient.php` L383-396: el wrapper ignora el valor de retorno de `Predis::del()` y retorna `true` incondicionalmente | Ninguno — implica que `AuditStateStore::deleteAudit()` ya es idempotente por diseño del wrapper |
| S1-002 | Los TTL de 86400s para flags idempotentes son suficientes para cubrir el ciclo de vida más largo de una reentrega PEL | S1 | La reentrega PEL se reclama tras `min-idle-time` (default 60s en el consumer) | Riesgo mínimo: una auditoría no se reprocesará 24h después |

### 0.11 Clasificación de Completitud Inicial

**Nivel A — Implementable**: Toda la información necesaria está confirmada por lectura directa del código. No hay supuestos S3/S4. Los dos supuestos S1 no afectan decisiones de diseño.

---

## FASE 1 — Especificación

### 1. Objetivo

**Problema actual**: La auditoría externa de calidad identificó 4 hallazgos que impiden la aprobación para producción de la rama `feat/fair-queuing-multiclient-batch`:

1. **QUAL-015** (P1/Medium): `compensateFailedEnrollment()` devuelve un único `bool`, registrando ambos recursos como no compensados aunque solo uno haya fallado. Además, la reconciliación diferida no alcanza jobs terminales.
2. **QUAL-018** (P1/Medium): El reproceso desde DLQ no reabre la auditoría en Redis, dejando inconsistencia entre Redis (`status=failed`) y SQL Server (`completed`).
3. **QUAL-011** (P2/Medium): Reentregas PEL por fallo en `markEventCompleted()` duplican publicaciones en DLQ y eventos `audit_failed`.
4. **QUAL-021** (P3/Low): Falta `.gitattributes`, causando churn de EOL que infla el diff.

**Causa raíz**: Falta de granularidad en retornos de compensación, ausencia de reapertura atómica de estado pre-reproceso, y ausencia de idempotencia en efectos terminales ante reentregas.

**Impacto actual**: Deuda de compensación permanente, inconsistencia Redis/SQL post-reproceso DLQ, eventos duplicados en streams, y diff inflado.

**Resultado esperado**: Los 4 hallazgos quedan corregidos, la suite completa pasa, y el diff refleja únicamente cambios funcionales.

### 2. Alcance

#### Incluido

- Cambio de firma y retorno de `compensateFailedEnrollment()` a array granular
- Actualización de los 3 call sites de `compensateFailedEnrollment()` en `enrollCandidateInvoices()`
- Reordenamiento de reconciliación en `discoverPendingUnpublishedBatches()`
- Nuevo método `AuditStateStore::reopenAuditForReprocess()`
- Nuevo método `BatchJobStore::reopenAuditInJob()`
- Invocación de reapertura en `AuditDlqController::reprocess()`
- Flags idempotentes en `sendToDeadLetter()`, `finalizeDeadLetterAudit()`, y `afterTerminalFailure()`
- Creación de `.gitattributes`
- Restauración de 3 archivos de test con cambios solo de EOL
- Tests unitarios para cada corrección

#### Excluido

- Cambios en `COMPLETE_AUDIT_LUA` — el script ya funciona correctamente; el problema era que la auditoría llegaba con `status=failed` en vez de `status=processing`
- Modificaciones a `AuditPersistenceWorker::handle()` — su lógica es correcta dado un estado inicial válido
- Cambios en esquema SQL Server

### 3. Non Goals

- Implementación de idempotencia a nivel de persistencia SQL Server (el reproceso DLQ puede generar duplicados en SQL si se ejecuta dos veces — esto requiere un `MERGE` o `INSERT ... NOT EXISTS` separado)
- Refactorización del mecanismo de lease/deduplicación de `AuditEventConsumer`
- Implementación de circuit breaker en la conexión Redis

### 4. Estado Actual

#### QUAL-015: Compensación granular

**Antes** — `compensateFailedEnrollment()` retorna `bool`:

```php
// MultiClientBatchDispatcher.php L655-702
private function compensateFailedEnrollment(
    string $auditId, string $disId, string $reservationToken,
    bool $hasAuditCreated, array &$createdAuditIds, array &$createdReservations
): bool {
    $cleanupSuccess = true;
    if ($hasAuditCreated) {
        // intenta deleteAudit → si falla, $cleanupSuccess = false
    }
    // intenta releaseAuditReservation → si falla, $cleanupSuccess = false
    return $cleanupSuccess;
}
```

**Call site actual** (L632-634):
```php
if (!$this->compensateFailedEnrollment($auditId, $disId, $reservationToken, true, $createdAuditIds, $createdReservations)) {
    $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
    $uncompensatedAuditIds[] = $auditId;
}
```

**Problema**: Si `deleteAudit` tiene éxito pero `releaseAuditReservation` falla, `$cleanupSuccess = false`, y **ambos** recursos se registran como no compensados aunque la auditoría ya fue eliminada.

**Nota sobre `deleteAudit`**: `AuditStateStore::deleteAudit()` (L107-110) delega a `RedisClient::del()` (L383-396). El wrapper `del()` siempre retorna `true` cuando Predis no lanza excepción, incluso si la clave no existía. `[CONFIRMADO]` — `deleteAudit` ya es idempotente por diseño del wrapper. El problema real es que `compensateFailedEnrollment` no reporta cuál recurso falló.

**Reconciliación en `discoverPendingUnpublishedBatches`** (L1306-1313):
```php
$jobStatus = (string) ($job['status'] ?? '');
if (!in_array($jobStatus, [BatchJobStore::JOB_STATUS_PENDING, BatchJobStore::JOB_STATUS_PROCESSING], true)) {
    continue; // ← filtra jobs terminales ANTES de reconciliar
}
// ...
$this->reconcilePendingCompensationsInJob($jobId, $job); // ← nunca alcanza jobs completed/failed
```

#### QUAL-018: Reapertura de auditoría en reproceso DLQ

**Antes** — `AuditDlqController::reprocess()` (L106-124):
```php
$event = AuditEvent::create(
    eventType: (string) ($original['event_type'] ?? ''),
    auditId: ..., jobId: ..., documentId: ..., payload: ...,
    parentEventId: AuditEvent::isUuidV4($originalEventId) ? $originalEventId : null,
);
if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
    $this->buildPersistenceQueue()->reprocess($event);
} else {
    $this->buildEventPublisher()->publish($event);
}
```

**Problema**: La auditoría en Redis tiene `status=failed` (establecido por `finalizeDeadLetterAudit` L859). Cuando el evento reprocesado llega a `AuditPersistenceWorker::handle()`, ejecuta `$this->stateStore->completeAudit(...)` → `COMPLETE_AUDIT_LUA` ve `status='failed'` → retorna `2` (terminal) sin aplicar el patch → `completeAudit` acepta `2` como éxito (L255: `acceptValues: [1, 2]`) → el worker publica `audit_completed` y persiste en SQL → Redis conserva `status=failed`.

#### QUAL-011: Idempotencia de efectos terminales

**Antes** — `handleFailure()` (L521-592):
```php
if ($attempts >= $this->maxRetries || $nonRetryable) {
    // 1. sendToDeadLetter → XADD a DLQ stream
    // 2. finalizeDeadLetterAudit → completeAudit(failed) + publish(audit_failed)
    // 3. afterTerminalFailure → hook de subclase
    // 4. markEventCompleted → SET key 'completed' (puede fallar)
    // Si (4) falla → no ACK → PEL retiene → (1)(2)(3) se re-ejecutan
}
```

**Problema**: `XADD` siempre crea una nueva entrada en el stream independientemente del contenido del payload. El UUID determinista (`dlq:` + eventId) está en el payload, no en el stream ID. Reentregas generan entradas DLQ duplicadas, eventos `audit_failed` duplicados, y ejecuciones repetidas del hook terminal.

#### QUAL-021: EOL y `.gitattributes`

El repositorio no tiene `.gitattributes`. Tres archivos de test aparecen en el diff solo por cambios CRLF/LF:
- `tests/Models/InvoicesModelTest.php`
- `tests/Services/Audit/Events/AuditPersistenceWorkerTest.php`
- `tests/Services/Audit/TextNormalizationTest.php`

### 5. Estado Objetivo

#### QUAL-015: Compensación con retorno granular

`compensateFailedEnrollment()` retorna `array{audit_deleted: bool, reservation_released: bool}`. Los call sites registran solo el recurso que realmente falló. `reconcilePendingCompensationsInJob()` se ejecuta para **todos** los jobs sellados con `compensation_pending`, incluyendo los que ya están en estado terminal.

#### QUAL-018: Reapertura atómica pre-reproceso

`AuditDlqController::reprocess()` invoca `AuditStateStore::reopenAuditForReprocess()` y `BatchJobStore::reopenAuditInJob()` antes de publicar el evento. El script Lua `REOPEN_AUDIT_LUA` transiciona atómicamente `status` de `failed`/`error` a `processing`, registra trazabilidad (`reprocessed_at`, `reprocessed_by_event_id`, `previous_status`), y limpia campos de error.

#### QUAL-011: Idempotencia con flags `SETNX`

Cada efecto terminal usa un flag Redis con TTL de 86400s:
- `sendToDeadLetter`: `dlq:sent:{group}:{eventId}` — si ya existe, omite `publishDeadLetter()` y retorna `true`
- `finalizeDeadLetterAudit`: `terminal:finalized:{auditId}:{eventId}` — si ya existe, omite toda la finalización y retorna `true`
- `afterTerminalFailure`: `terminal:hook:{group}:{eventId}` — si ya existe, omite el hook

#### QUAL-021: `.gitattributes` y restauración de EOL

`.gitattributes` con `* text=auto eol=lf`. Los 3 archivos con cambios solo de EOL se restauran con `git checkout HEAD -- <file>`.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| DA-1 | Usar `SETNX` con TTL como flag idempotente para efectos terminales | (a) Verificar existencia de la entrada DLQ en el stream con `XRANGE` antes de `XADD`; (b) Usar el UUID determinista como ID de stream en vez de auto-generado | (a) Race condition entre `XRANGE` y `XADD` sin atomicidad; (b) Redis Streams no permiten IDs personalizados con formato UUID — solo acepta `<ms>-<seq>` o `*` |
| DA-2 | Script Lua para reapertura atómica de auditoría | (a) `patchAudit()` secuencial con `GET`+`SET`; (b) Pipeline Redis | (a) Race condition entre GET y SET; (b) Pipeline no es atómico — otro consumer puede leer el estado intermedio |
| DA-3 | Retorno de `compensateFailedEnrollment` como array en vez de struct/DTO | (a) Crear una clase `CompensationResult`; (b) Usar excepciones granulares | (a) Overengineering para un método privado con 3 call sites; (b) Las excepciones ya se capturan internamente |
| DA-4 | Reconciliación ejecuta para todos los jobs sellados, no solo los activos | (a) Crear un set Redis separado de "jobs con deudas pendientes" | (a) Agrega complejidad y otro artefacto Redis que mantener; la iteración `SCAN` existente es suficiente |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| Redis (Predis) | Librería | ^2.0 | Operaciones `SETNX`, `EVAL`, `DEL` usadas por los nuevos métodos `[CONFIRMADO]` |
| `RedisClient` | Servicio interno | N/A | Usa `setnx()`, `del()`, `eval()` — todos verificados `[CONFIRMADO]` |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto? |
| --- | --- | --- | --- |
| Estado de auditoría | Código (`AuditStateStore.php`) | L14-28 (constantes de status) | No |
| Estado de job | Código (`BatchJobStore.php`) | L17-21 (constantes de status) | No |
| Contrato DLQ | Código (`AuditEventConsumer.php`) | L955-999 (`sendToDeadLetter`) | No |
| Flujo de reproceso | Código (`AuditDlqController.php`) | L74-133 (`reprocess`) | No |

`[CONFIRMADO]` Sin conflictos detectados entre fuentes de verdad.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| Una auditoría en Redis con `status=failed` no acepta transición a `completed` vía `COMPLETE_AUDIT_LUA` | Script Lua `COMPLETE_AUDIT_LUA` L468-472 retorna `2` sin aplicar patch | Test existente + nuevo test post-reapertura |
| Cada efecto terminal (DLQ, finalización, hook) se ejecuta exactamente una vez por evento | Flag `SETNX` con TTL de 86400s | Nuevo test de reentregas consecutivas |
| `compensateFailedEnrollment` registra solo los recursos que realmente fallaron en compensar | Array de retorno con booleanos independientes | Nuevo test de resultados mixtos |
| Jobs sellados con `compensation_pending=true` son reconciliados independientemente de su status | Reconciliación ejecuta antes del filtro de status en `discoverPendingUnpublishedBatches` | Nuevo test con job terminal y deudas |

### 9. Modelo de Datos

`[CONFIRMADO]` Sin impacto en persistencia SQL Server. Los cambios afectan únicamente estructuras en Redis (keys efímeras con TTL).

**Estructuras Redis nuevas** (todas con TTL de 86400s):

| Clave Redis | Tipo | TTL | Propósito |
| --- | --- | --- | --- |
| `dlq:sent:{group}:{eventId}` | STRING (`"1"`) | 86400s | Flag idempotente para `sendToDeadLetter` |
| `terminal:finalized:{auditId}:{eventId}` | STRING (`"1"`) | 86400s | Flag idempotente para `finalizeDeadLetterAudit` |
| `terminal:hook:{group}:{eventId}` | STRING (`"1"`) | 86400s | Flag idempotente para `afterTerminalFailure` |

**Estructura Redis modificada** (campo `audit:{auditId}:state` — patched por `REOPEN_AUDIT_LUA`):

| Campo | Antes | Después |
| --- | --- | --- |
| `status` | `failed` / `error` | `processing` |
| `detail_error` | string con error | eliminado (`nil`) |
| `requires_manual_review` | `true`/`false` | eliminado (`nil`) |
| `failed_stage` | string | eliminado (`nil`) |
| `failed_event_type` | string | eliminado (`nil`) |
| `completed_at` | timestamp | eliminado (`nil`) |
| `reprocessed_at` | — | timestamp UTC |
| `reprocessed_by_event_id` | — | UUID del nuevo evento |
| `previous_status` | — | `failed` / `error` |
| `reprocess_count` | — | incrementado (+1) |

### 10. Contratos

#### Contrato interno: `compensateFailedEnrollment` (método privado)

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno (método privado) |
| Visibilidad | Interno |
| Productor | `MultiClientBatchDispatcher::enrollCandidateInvoices` |
| Consumidores | 3 call sites en `enrollCandidateInvoices` |
| Compatibilidad requerida | Ninguna (método privado) |

**Antes**: `bool` — `true` si ambos recursos compensados, `false` si alguno falló.

**Después**: `array{audit_deleted: bool, reservation_released: bool}` — resultado independiente por recurso.

#### Contrato interno: `AuditStateStore::reopenAuditForReprocess` (método público nuevo)

```php
public function reopenAuditForReprocess(string $auditId, string $reprocessEventId): bool
```
- Retorna `true` si la auditoría fue reabierta exitosamente (`status` pasó de `failed`/`error` a `processing`).
- Retorna `false` si la auditoría no existe, no está en estado terminal compatible, o el script Lua falló.

#### Contrato interno: `BatchJobStore::reopenAuditInJob` (método público nuevo)

```php
public function reopenAuditInJob(string $jobId, string $auditId, string $newEventId): bool
```
- Retorna `true` si la auditoría en el job fue reabierta exitosamente.
- Retorna `false` si el job o la auditoría no existen en Redis.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| QUAL-015-a | `compensateFailedEnrollment` retorna resultado granular por recurso | `MultiClientBatchDispatcher::compensateFailedEnrollment()` retorna `array{audit_deleted, reservation_released}` | Test: solo el recurso fallido aparece en `$uncompensated*` |
| QUAL-015-b | `discoverPendingUnpublishedBatches` reconcilia deudas en jobs terminales | `reconcilePendingCompensationsInJob` se mueve antes del filtro de status | Test: job `completed` con `compensation_pending=true` es reconciliado |
| QUAL-018-a | Reapertura atómica de auditoría en Redis pre-reproceso DLQ | `AuditStateStore::reopenAuditForReprocess()` con `REOPEN_AUDIT_LUA` | Test: auditoría pasa de `failed` a `processing` con trazabilidad |
| QUAL-018-b | Reapertura de auditoría en job pre-reproceso DLQ | `BatchJobStore::reopenAuditInJob()` con `REOPEN_AUDIT_IN_JOB_LUA` | Test: contadores del job actualizados |
| QUAL-018-c | `AuditDlqController::reprocess()` reabre auditoría antes de publicar | Invocación secuencial de reapertura + publicación | Test: flujo completo de reproceso |
| QUAL-011-a | `sendToDeadLetter` es idempotente ante reentregas PEL | Flag `SETNX` `dlq:sent:{group}:{eventId}` | Test: segunda entrega no publica a DLQ |
| QUAL-011-b | `finalizeDeadLetterAudit` es idempotente ante reentregas PEL | Flag `SETNX` `terminal:finalized:{auditId}:{eventId}` | Test: segunda entrega no publica `audit_failed` |
| QUAL-011-c | `afterTerminalFailure` es idempotente ante reentregas PEL | Flag `SETNX` `terminal:hook:{group}:{eventId}` | Test: segunda entrega no ejecuta hook |
| QUAL-021-a | Crear `.gitattributes` con normalización LF | `.gitattributes` con `* text=auto eol=lf` | `cat .gitattributes` |
| QUAL-021-b | Restaurar archivos con cambios solo de EOL | `git checkout HEAD -- <3 archivos>` | `git diff --ignore-space-at-eol` vacío para esos archivos |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `enrollCandidateInvoices` | `compensateFailedEnrollment` | Cambio de retorno `bool` → `array` | Actualizar 3 call sites para usar campos individuales | `MultiClientBatchDispatcher.php` L594, L603, L632 |
| `AuditPersistenceWorker` | `AuditStateStore::completeAudit` + `COMPLETE_AUDIT_LUA` | `reopenAuditForReprocess` resetea `status` a `processing` → `COMPLETE_AUDIT_LUA` aplicará el patch normalmente (retorna `1`) | Ninguno — funciona correctamente dado estado inicial válido | `AuditPersistenceWorker.php` L102-110 |
| `AuditBatchOrchestrator` | `AuditStateStore::deleteAudit` | Sin impacto — `deleteAudit` no cambia de firma ni comportamiento | Ninguno | `AuditBatchOrchestrator.php` L473 |
| `AuditController` | `AuditStateStore::deleteAudit` | Sin impacto — misma razón | Ninguno | `AuditController.php` L98 |
| `MultiClientBatchDispatcherTest` | `compensateFailedEnrollment` (indirecto via mocks) | Mocks de `deleteAudit` y `releaseAuditReservation` ya retornan `true`/`false` | Agregar nuevos tests para resultados mixtos; tests existentes no se rompen porque el método es privado | `tests/Services/Audit/MultiClientBatchDispatcherTest.php` |

### 13. Cambios por Archivo

---

#### [MODIFY] [`MultiClientBatchDispatcher.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/MultiClientBatchDispatcher.php)

**Clase**: `MultiClientBatchDispatcher`

##### `compensateFailedEnrollment()`, líneas observadas: 655-702

**Antes**:
```php
private function compensateFailedEnrollment(
    string $auditId, string $disId, string $reservationToken,
    bool $hasAuditCreated, array &$createdAuditIds, array &$createdReservations
): bool {
    $cleanupSuccess = true;
    // ... deleteAudit / releaseAuditReservation ...
    return $cleanupSuccess;
}
```

**Después**:
```php
/** @return array{audit_deleted: bool, reservation_released: bool} */
private function compensateFailedEnrollment(
    string $auditId, string $disId, string $reservationToken,
    bool $hasAuditCreated, array &$createdAuditIds, array &$createdReservations
): array {
    $result = ['audit_deleted' => true, 'reservation_released' => true];

    if ($hasAuditCreated) {
        try {
            if ($this->stateStore->deleteAudit($auditId)) {
                array_pop($createdAuditIds);
            } else {
                $result['audit_deleted'] = false;
                Logger::error('MultiClientBatchDispatcher: deleteAudit devolvió false en rollback de enrolamiento', [
                    'audit_id' => $auditId, 'dis_id' => $disId,
                ]);
            }
        } catch (Throwable $e) {
            $result['audit_deleted'] = false;
            Logger::error('MultiClientBatchDispatcher: excepción en deleteAudit en rollback de enrolamiento', [
                'audit_id' => $auditId, 'error' => $e->getMessage(),
            ]);
        }
    }

    try {
        if ($this->jobStore->releaseAuditReservation($disId, $reservationToken)) {
            array_pop($createdReservations);
        } else {
            $result['reservation_released'] = false;
            Logger::error('MultiClientBatchDispatcher: releaseAuditReservation devolvió false en rollback de enrolamiento', [
                'dis_id' => $disId,
            ]);
        }
    } catch (Throwable $e) {
        $result['reservation_released'] = false;
        Logger::error('MultiClientBatchDispatcher: excepción en releaseAuditReservation en rollback de enrolamiento', [
            'dis_id' => $disId, 'error' => $e->getMessage(),
        ]);
    }

    return $result;
}
```

##### `enrollCandidateInvoices()`, líneas observadas: 554-647

**Antes** (L594-596 — fallo en `initAudit`):
```php
if (!$this->compensateFailedEnrollment($auditId, $disId, $reservationToken, false, $createdAuditIds, $createdReservations)) {
    $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
}
```

**Después**:
```php
$compResult = $this->compensateFailedEnrollment($auditId, $disId, $reservationToken, false, $createdAuditIds, $createdReservations);
if (!$compResult['reservation_released']) {
    $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
}
```

**Antes** (L603-605 — fallo en `patchAudit`):
```php
if (!$this->compensateFailedEnrollment($auditId, $disId, $reservationToken, true, $createdAuditIds, $createdReservations)) {
    $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
    $uncompensatedAuditIds[] = $auditId;
}
```

**Después**:
```php
$compResult = $this->compensateFailedEnrollment($auditId, $disId, $reservationToken, true, $createdAuditIds, $createdReservations);
if (!$compResult['reservation_released']) {
    $uncompensatedReservations[] = ['dis_id' => $disId, 'token' => $reservationToken];
}
if (!$compResult['audit_deleted']) {
    $uncompensatedAuditIds[] = $auditId;
}
```

**Antes** (L632-634 — fallo en `registerAuditInJob`): mismo patrón que L603-605.

**Después**: mismo patrón que el cambio anterior.

##### `discoverPendingUnpublishedBatches()`, líneas observadas: 1277-1348

**Antes** (L1305-1314):
```php
$jobStatus = (string) ($job['status'] ?? '');
if (!in_array($jobStatus, [BatchJobStore::JOB_STATUS_PENDING, BatchJobStore::JOB_STATUS_PROCESSING], true)) {
    continue;
}

$facNitSec = (int) ($job['fac_nit_sec'] ?? 0);
$clientName = (string) ($job['client_name'] ?? "Cliente {$facNitSec}");
$this->reconcilePendingCompensationsInJob($jobId, $job);
```

**Después**:
```php
// Reconciliar deudas de compensación ANTES del filtro de status (QUAL-015)
// para que jobs terminales con compensation_pending sean limpiados
if (!empty($job['compensation_pending'])) {
    $this->reconcilePendingCompensationsInJob($jobId, $job);
    $job = $this->jobStore->getJob($jobId) ?? $job;
}

$jobStatus = (string) ($job['status'] ?? '');
if (!in_array($jobStatus, [BatchJobStore::JOB_STATUS_PENDING, BatchJobStore::JOB_STATUS_PROCESSING], true)) {
    continue;
}

$facNitSec = (int) ($job['fac_nit_sec'] ?? 0);
$clientName = (string) ($job['client_name'] ?? "Cliente {$facNitSec}");
// Reconciliar también deudas a nivel de auditorías individuales
$this->reconcilePendingCompensationsInJob($jobId, $job);
```

---

#### [MODIFY] [`AuditStateStore.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditStateStore.php)

**Clase**: `AuditStateStore`

##### Nuevo método `reopenAuditForReprocess()`, insertar después de L110 (`deleteAudit`)

```php
/**
 * Reabre atómicamente una auditoría terminal para reproceso desde DLQ (QUAL-018).
 *
 * Transiciona status de 'failed'/'error' a 'processing', limpia campos de error,
 * y registra trazabilidad del reproceso.
 *
 * @param  string  $auditId           UUID de la auditoría a reabrir
 * @param  string  $reprocessEventId  UUID del nuevo evento que disparó el reproceso
 * @return bool    true si se reabrió exitosamente, false si no existe o no está en estado terminal compatible
 */
public function reopenAuditForReprocess(string $auditId, string $reprocessEventId): bool
{
    return $this->runScript(
        self::REOPEN_AUDIT_LUA,
        [self::auditKey($auditId)],
        [$reprocessEventId, self::nowUtc(), self::auditTtlSeconds()],
        'No se pudo reabrir la auditoría para reproceso',
        ['audit_id' => $auditId, 'reprocess_event_id' => $reprocessEventId]
    );
}
```

##### Nueva constante `REOPEN_AUDIT_LUA`, insertar antes de `RECORD_EVENT_TELEMETRY_LUA` (L490)

```php
private const REOPEN_AUDIT_LUA = <<<'LUA'
    local raw = redis.call('GET', KEYS[1])
    if not raw then return 0 end

    local audit = cjson.decode(raw)
    local status = tostring(audit['status'] or '')

    if status ~= 'failed' and status ~= 'error' then
        return 0
    end

    local reprocessEventId = ARGV[1]
    local now = ARGV[2]
    local ttl = tonumber(ARGV[3])

    audit['previous_status'] = status
    audit['status'] = 'processing'
    audit['reprocessed_at'] = now
    audit['reprocessed_by_event_id'] = reprocessEventId
    audit['reprocess_count'] = (tonumber(audit['reprocess_count']) or 0) + 1
    audit['updated_at'] = now

    -- Limpiar campos de error de la ejecución previa
    audit['detail_error'] = nil
    audit['requires_manual_review'] = nil
    audit['failed_stage'] = nil
    audit['failed_event_type'] = nil
    audit['completed_at'] = nil

    redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', ttl)
    return 1
LUA;
```

---

#### [MODIFY] [`BatchJobStore.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/BatchJobStore.php)

**Clase**: `BatchJobStore`

##### Nuevo método `reopenAuditInJob()` y constante `REOPEN_AUDIT_IN_JOB_LUA`

```php
/**
 * Reabre una auditoría dentro de un job para reproceso DLQ (QUAL-018).
 *
 * Transiciona el estado de la auditoría de 'failed' a 'processing' en el job,
 * decrementa el contador 'failed' del job, y actualiza el event_id.
 */
public function reopenAuditInJob(string $jobId, string $auditId, string $newEventId): bool
{
    return $this->runScript(
        self::REOPEN_AUDIT_IN_JOB_LUA,
        [self::jobKey($jobId)],
        [$auditId, $newEventId, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
        'No se pudo reabrir la auditoría en el job para reproceso',
        ['job_id' => $jobId, 'audit_id' => $auditId]
    );
}

private const REOPEN_AUDIT_IN_JOB_LUA = <<<'LUA'
    local raw = redis.call('GET', KEYS[1])
    if not raw then return 0 end

    local job = cjson.decode(raw)
    local auditId = ARGV[1]
    local newEventId = ARGV[2]
    local now = ARGV[3]
    local ttl = tonumber(ARGV[4])

    if type(job['audits']) ~= 'table' then return 0 end
    local auditState = job['audits'][auditId]
    if type(auditState) ~= 'table' then return 0 end

    local prevStatus = tostring(auditState['status'] or '')
    if prevStatus ~= 'failed' and prevStatus ~= 'error' then return 0 end

    auditState['status'] = 'processing'
    auditState['event_id'] = newEventId
    auditState['reprocessed_at'] = now
    job['audits'][auditId] = auditState

    if prevStatus == 'failed' then
        job['failed'] = math.max(0, (tonumber(job['failed']) or 0) - 1)
    end

    job['updated_at'] = now
    redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
    return 1
LUA;
```

---

#### [MODIFY] [`AuditDlqController.php`](file:///c:/Users/USER/Desktop/AudFact/app/Controllers/AuditDlqController.php)

**Clase**: `AuditDlqController`

##### `reprocess()`, líneas observadas: 74-133

**Antes** (L106-124):
```php
try {
    $originalEventId = (string) ($original['event_id'] ?? '');
    $event = AuditEvent::create(
        eventType: ..., auditId: ..., jobId: ..., documentId: ..., payload: ...,
        parentEventId: AuditEvent::isUuidV4($originalEventId) ? $originalEventId : null,
    );
    if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
        $this->buildPersistenceQueue()->reprocess($event);
    } else {
        $this->buildEventPublisher()->publish($event);
    }
} catch (\Throwable $e) {
    Response::error('No se pudo reprocesar el evento DLQ', 503);
}
```

**Después**:
```php
try {
    $originalEventId = (string) ($original['event_id'] ?? '');
    $event = AuditEvent::create(
        eventType: ..., auditId: ..., jobId: ..., documentId: ..., payload: ...,
        parentEventId: AuditEvent::isUuidV4($originalEventId) ? $originalEventId : null,
    );

    // QUAL-018: Reabrir auditoría en Redis antes de republicar
    if ($event->auditId !== null) {
        $stateStore = $this->buildStateStore();
        $reopened = $stateStore->reopenAuditForReprocess($event->auditId, $event->eventId);

        if ($event->jobId !== null && $reopened) {
            $this->buildJobStore()->reopenAuditInJob($event->jobId, $event->auditId, $event->eventId);
        }
    }

    if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
        $this->buildPersistenceQueue()->reprocess($event);
    } else {
        $this->buildEventPublisher()->publish($event);
    }
} catch (\Throwable $e) {
    Response::error('No se pudo reprocesar el evento DLQ', 503);
}
```

##### Nuevos métodos factory

```php
protected function buildStateStore(): AuditStateStore
{
    return new AuditStateStore($this->buildRedisClient());
}

protected function buildJobStore(): BatchJobStore
{
    return new BatchJobStore($this->buildRedisClient());
}
```

---

#### [MODIFY] [`AuditEventConsumer.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditEventConsumer.php)

**Clase**: `AuditEventConsumer`

##### `sendToDeadLetter()`, líneas observadas: 955-999

**Antes** (L977-992):
```php
try {
    $dlqStreamId = $this->publisher->publishDeadLetter($deadLetter);
    // ...
    return true;
} catch (\Throwable $e) {
    // ...
    return false;
}
```

**Después**:
```php
// QUAL-011: Flag idempotente para evitar DLQ duplicado en reentregas PEL
$idempotencyKey = "dlq:sent:{$this->group()}:{$event->eventId}";
if (!$this->redis->setnx($idempotencyKey, '1', 86400)) {
    // Ya fue publicado a DLQ en una entrega anterior
    Logger::info('AuditEventConsumer: sendToDeadLetter omitido (ya publicado previamente)', [
        'event_id' => $event->eventId,
    ]);
    return true;
}

try {
    $dlqStreamId = $this->publisher->publishDeadLetter($deadLetter);
    // ... (sin cambios en el resto del bloque try)
    return true;
} catch (\Throwable $e) {
    // Limpiar flag si la publicación falló para permitir reintento
    $this->redis->del($idempotencyKey);
    // ... (sin cambios en el log)
    return false;
}
```

##### `finalizeDeadLetterAudit()`, líneas observadas: 837-913

**Antes** (L843-905):
```php
try {
    $stateStore = new AuditStateStore($this->redis);
    // ... completeAudit, markAuditCompletedInJob, releaseAuditReservation, publish(audit_failed) ...
    return true;
} catch (Throwable $finalizeError) {
    // ...
    return false;
}
```

**Después**: Insertar guard al inicio del bloque try:
```php
try {
    // QUAL-011: Flag idempotente para evitar finalización duplicada en reentregas PEL
    $finalizeKey = "terminal:finalized:{$event->auditId}:{$event->eventId}";
    if (!$this->redis->setnx($finalizeKey, '1', 86400)) {
        Logger::info('AuditEventConsumer: finalizeDeadLetterAudit omitido (ya finalizado previamente)', [
            'event_id' => $event->eventId,
            'audit_id' => $event->auditId,
        ]);
        return true;
    }

    $stateStore = new AuditStateStore($this->redis);
    // ... (resto del bloque sin cambios) ...
```

##### `handleFailure()` — protección del hook, líneas observadas: 548-560

**Antes**:
```php
// 3. Hook terminal ANTES de deduplicación (QUAL-018)
try {
    $this->afterTerminalFailure($event, $error);
} catch (Throwable $hookEx) {
    // ...
}
```

**Después**:
```php
// 3. Hook terminal ANTES de deduplicación (QUAL-018), con idempotencia (QUAL-011)
$hookKey = "terminal:hook:{$this->group()}:{$event->eventId}";
$hookAlreadyExecuted = !$this->redis->setnx($hookKey, '1', 86400);
if (!$hookAlreadyExecuted) {
    try {
        $this->afterTerminalFailure($event, $error);
    } catch (Throwable $hookEx) {
        // Limpiar flag para permitir reintento del hook
        $this->redis->del($hookKey);
        Logger::critical('AuditEventConsumer: afterTerminalFailure falló; reteniendo en PEL para reintento', [
            'event_id' => $event->eventId,
            'audit_id' => $event->auditId,
            'hook_error' => $hookEx->getMessage(),
            'original_error' => $error->getMessage(),
        ]);
        $this->releaseEventLease($event->eventId, $leaseToken);
        return;
    }
}
```

---

#### [NEW] [`.gitattributes`](file:///c:/Users/USER/Desktop/AudFact/.gitattributes)

```gitattributes
# Normalización de finales de línea (QUAL-021)
* text=auto eol=lf
*.php text eol=lf
*.md text eol=lf
*.json text eol=lf
*.yml text eol=lf
*.yaml text eol=lf
*.js text eol=lf
*.ts text eol=lf
*.tsx text eol=lf
*.css text eol=lf
*.html text eol=lf
*.sh text eol=lf

# Binarios
*.png binary
*.jpg binary
*.gif binary
*.ico binary
*.woff binary
*.woff2 binary
*.ttf binary
*.eot binary
```

---

#### [MODIFY] Tests (3 archivos)

##### [`MultiClientBatchDispatcherTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/MultiClientBatchDispatcherTest.php)

**Nuevos tests**:
1. `testEnrollmentCompensationOnlyTracksReservationWhenAuditDeleteSucceeds`: `deleteAudit` → `true`, `releaseAuditReservation` → `false`. Verificar: `pending_reservations` contiene el recurso, `pending_audit_ids` vacío.
2. `testEnrollmentCompensationOnlyTracksAuditWhenReservationReleaseSucceeds`: `deleteAudit` → exception, `releaseAuditReservation` → `true`. Verificar: `pending_audit_ids` contiene el auditId, `pending_reservations` vacío.
3. `testReconcilePendingCompensationsRunsOnTerminalCompletedJob`: Job con `status=completed`, `sealed=true`, `compensation_pending=true`. Verificar que `reconcilePendingCompensationsInJob` se ejecuta y las deudas se limpian.

##### [`AuditEventConsumerTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/AuditEventConsumerTest.php)

**Nuevos tests**:
1. `testConsecutiveTerminalDeliveriesDoNotDuplicateDlqPublication`: Simular dos entregas terminales del mismo evento. Primera entrega: `setnx` retorna `true` → se publica a DLQ. Segunda entrega: `setnx` retorna `false` → no se publica.
2. `testConsecutiveTerminalDeliveriesDoNotDuplicateAuditFailedEvent`: Similar para `finalizeDeadLetterAudit`.
3. `testConsecutiveTerminalDeliveriesDoNotReExecuteHook`: Similar para `afterTerminalFailure`.

##### [`AuditDlqControllerTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Controllers/AuditDlqControllerTest.php)

**Tests actualizados/nuevos**:
1. `testReprocessReopensFailedAuditBeforePublishing`: Verificar que `reopenAuditForReprocess` es invocado antes de `publish`/`reprocess`.
2. `testReprocessReopensAuditInJobWhenJobIdPresent`: Verificar que `reopenAuditInJob` es invocado si el evento tiene `jobId`.

### 14. Plan de Migración

#### Prerequisitos

1. Suite de tests PHPUnit pasa al 100% antes del cambio.
2. Branch limpio sin cambios pendientes.

#### Ejecución

1. Crear `.gitattributes` (QUAL-021).
2. Restaurar 3 archivos de test con cambios solo de EOL: `git checkout HEAD -- tests/Models/InvoicesModelTest.php tests/Services/Audit/Events/AuditPersistenceWorkerTest.php tests/Services/Audit/TextNormalizationTest.php`.
3. Implementar `AuditStateStore::reopenAuditForReprocess()` + `REOPEN_AUDIT_LUA` (QUAL-018).
4. Implementar `BatchJobStore::reopenAuditInJob()` + `REOPEN_AUDIT_IN_JOB_LUA` (QUAL-018).
5. Modificar `AuditDlqController::reprocess()` con reapertura + factories (QUAL-018).
6. Modificar `compensateFailedEnrollment()` → retorno `array` (QUAL-015).
7. Actualizar 3 call sites en `enrollCandidateInvoices()` (QUAL-015).
8. Mover reconciliación en `discoverPendingUnpublishedBatches()` (QUAL-015).
9. Añadir flags idempotentes en `sendToDeadLetter()`, `finalizeDeadLetterAudit()`, `handleFailure()` hook (QUAL-011).
10. Escribir/actualizar tests unitarios.
11. Ejecutar `vendor/bin/phpunit`.
12. Ejecutar `git diff --stat` para verificar diff limpio.

#### Validaciones Previas

- `vendor/bin/phpunit` — 617 tests, 0 failures
- `php -l` en archivos modificados

#### Validaciones Posteriores

- `vendor/bin/phpunit` — ≥ 625 tests, 0 failures (nuevos tests)
- `git diff --check` — limpio
- `git diff --stat` — sin archivos tocados solo por EOL
- `node .agent/skills/_shared/scripts/validate-skills.mjs` — PASS

#### Rollback

No se requiere rollback de datos persistidos. Los cambios son puramente lógicos en PHP.
- **Código**: `git revert <commit>` o `git checkout <prev-commit> -- <archivos>`.
- **Redis**: Las nuevas claves (`dlq:sent:*`, `terminal:*`) tienen TTL de 86400s y se auto-eliminan.
- **Auditorías reabiertas**: Si se hace rollback del código, las auditorías ya reabiertas en Redis permanecerán con `status=processing` hasta que expiren por TTL (7 días).

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| `compensateFailedEnrollment`: `deleteAudit` OK, `releaseAuditReservation` falla | Retorna `{audit_deleted: true, reservation_released: false}`. Solo `$uncompensatedReservations` crece | Test unitario |
| `compensateFailedEnrollment`: `deleteAudit` lanza excepción, `releaseAuditReservation` OK | Retorna `{audit_deleted: false, reservation_released: true}`. Solo `$uncompensatedAuditIds` crece | Test unitario |
| `reopenAuditForReprocess`: auditoría en `status=completed` | Retorna `false` — no reabre auditorías ya completadas exitosamente | Test unitario |
| `reopenAuditForReprocess`: auditoría no existe en Redis (TTL expirado) | Retorna `false` (Lua retorna `0` porque `raw == nil`) | Test unitario |
| `reopenAuditInJob`: auditoría no está en el job | Retorna `false` | Test unitario |
| Reentrega PEL tras `markEventCompleted` falla: segunda entrega | `sendToDeadLetter` retorna `true` sin publicar (flag existe). `finalizeDeadLetterAudit` retorna `true` sin publicar. Hook no se ejecuta | Test unitario |
| `sendToDeadLetter` falla en `publishDeadLetter` tras `setnx` exitoso | Flag se limpia con `del()` para permitir reintento legítimo | Lógica del catch |
| DLQ `reprocess()` con `auditId = null` | Se omite reapertura, se procede directamente a publicación | Condicional `if ($event->auditId !== null)` |
| DLQ `reprocess()` con `jobId = null` | Se omite `reopenAuditInJob`, se procede con publicación | Condicional `if ($event->jobId !== null && $reopened)` |
| `reconcilePendingCompensationsInJob` en job terminal sin `compensation_pending` | No se ejecuta (guard `!empty($job['compensation_pending'])`) | Lógica existente |

### 16. Testing

#### Nuevos Tests

1. **`testEnrollmentCompensationOnlyTracksReservationWhenAuditDeleteSucceeds`** (`MultiClientBatchDispatcherTest`)
   - Precondiciones: Mock `stateStore->deleteAudit` → `true`, mock `jobStore->releaseAuditReservation` → `false`
   - Pasos: Ejecutar enrolamiento con fallo en `registerAuditInJob`
   - Resultado: `pending_reservations` contiene recurso, `pending_audit_ids` vacío

2. **`testEnrollmentCompensationOnlyTracksAuditWhenReservationReleaseSucceeds`** (`MultiClientBatchDispatcherTest`)
   - Precondiciones: Mock `stateStore->deleteAudit` → throws `RuntimeException`, mock `jobStore->releaseAuditReservation` → `true`
   - Pasos: Ejecutar enrolamiento con fallo en `registerAuditInJob`
   - Resultado: `pending_audit_ids` contiene auditId, `pending_reservations` vacío

3. **`testReconcilePendingCompensationsRunsOnTerminalCompletedJob`** (`MultiClientBatchDispatcherTest`)
   - Precondiciones: Job con `status=completed`, `sealed=true`, `compensation_pending=true`, deudas en `pending_audit_ids` y `pending_reservations`
   - Pasos: Ejecutar `discoverPendingUnpublishedBatches`
   - Resultado: `deleteAudit` y `releaseAuditReservation` invocados para las deudas; job actualizado con `compensation_pending=false`

4. **`testDlqReprocessReopensFailedAuditInRedis`** (`AuditDlqControllerTest`)
   - Precondiciones: Evento DLQ con `audit_id` y auditoría en `status=failed`
   - Pasos: Invocar `reprocess()`
   - Resultado: `reopenAuditForReprocess` invocado con audit_id y nuevo event_id

5. **`testDlqReprocessReopensAuditInJobWhenJobPresent`** (`AuditDlqControllerTest`)
   - Precondiciones: Evento DLQ con `audit_id` y `job_id`
   - Pasos: Invocar `reprocess()`
   - Resultado: `reopenAuditInJob` invocado con job_id, audit_id y nuevo event_id

6. **`testConsecutiveTerminalDeliveriesDoNotDuplicateDlqPublication`** (`AuditEventConsumerTest`)
   - Precondiciones: Mock `redis->setnx` retorna `false` (flag ya existe)
   - Pasos: Invocar `handleFailure` con `attempts >= maxRetries`
   - Resultado: `publishDeadLetter` no invocado

7. **`testConsecutiveTerminalDeliveriesDoNotDuplicateFinalization`** (`AuditEventConsumerTest`)
   - Precondiciones: Mock `redis->setnx` para finalize key retorna `false`
   - Pasos: Invocar flujo terminal
   - Resultado: `completeAudit(failed)` no invocado, `publish(audit_failed)` no invocado

8. **`testConsecutiveTerminalDeliveriesDoNotReExecuteHook`** (`AuditEventConsumerTest`)
   - Precondiciones: Mock `redis->setnx` para hook key retorna `false`
   - Pasos: Invocar flujo terminal
   - Resultado: `afterTerminalFailure` no invocado

#### Tests Modificados

- Tests existentes en `AuditDlqControllerTest` que verifican `reprocess()`: agregar mocks para `buildStateStore()` y `buildJobStore()`.

#### Verificaciones Manuales

1. `vendor/bin/phpunit` — suite completa pasa con 0 fallos
2. `git diff --stat` — verificar que el conteo de líneas refleja solo cambios funcionales
3. `git diff --check` — sin errores de trailing whitespace

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Flag idempotente `SETNX` falla por Redis caído durante reentrega PEL | Operativo | Media | `setnx` retorna `false` cuando Redis no disponible (`RedisClient::setnx` L365-367), lo que haría que el efecto se omita incorrectamente. Sin embargo, si Redis está caído, el consumer completo falla y no llega a ejecutar efectos terminales. El flag solo actúa cuando Redis está operativo. |
| Reapertura de auditoría en Redis pero fallo antes de publicar evento | Consistencia de datos | Baja | La auditoría queda en `processing` en Redis pero no se procesa. Tiene TTL de 7 días y expirará naturalmente. El administrador puede reintentar el reproceso desde la DLQ. |
| `.gitattributes` causa re-checkout masivo de archivos en la primera sincronización | DX | Baja | El `text=auto` con `eol=lf` normaliza en el siguiente commit. Los desarrolladores verán archivos "modified" que deben hacer commit una vez. |

### 18. Criterios de Aceptación

1. `vendor/bin/phpunit` pasa con ≥ 625 tests, 0 failures, 0 errors.
2. Test `testEnrollmentCompensationOnlyTracksReservationWhenAuditDeleteSucceeds` pasa: cuando `deleteAudit` tiene éxito pero `releaseAuditReservation` falla, solo `pending_reservations` contiene el recurso.
3. Test `testReconcilePendingCompensationsRunsOnTerminalCompletedJob` pasa: un job con `status=completed` y `compensation_pending=true` es reconciliado.
4. Test `testDlqReprocessReopensFailedAuditInRedis` pasa: `reopenAuditForReprocess` es invocado en `reprocess()`.
5. Test `testConsecutiveTerminalDeliveriesDoNotDuplicateDlqPublication` pasa: segunda entrega no publica a DLQ.
6. `git diff --check` limpio.
7. `git diff --stat` sin archivos modificados únicamente por EOL.
8. `node .agent/skills/_shared/scripts/validate-skills.mjs` — PASS.

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| `AuditEventConsumer: sendToDeadLetter omitido` | Log (INFO) | No existía | Aparece cuando una reentrega PEL encuentra flag idempotente | Logs del worker | Cualquier ocurrencia indica reentrega de PEL correctamente deduplicada | Informativo |
| `AuditEventConsumer: finalizeDeadLetterAudit omitido` | Log (INFO) | No existía | Aparece en reentregas PEL | Logs del worker | Idem | Informativo |
| `telemetry:async_metrics.terminal_failures` | Métrica Redis | Se incrementa por cada `sendToDeadLetter` exitoso | Se incrementa una sola vez por evento (no en reentregas) | Redis hash | Si crece desproporcionadamente respecto a eventos totales | Investigar |
| `audit:{id}:state.reprocess_count` | Campo Redis | No existía | Se incrementa por cada reproceso DLQ | Redis key | `> 2` para la misma auditoría | Investigar causa raíz de fallo persistente |

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo (Docker Compose recreate en producción LAN) |
| Orden entre productores y consumidores | Simultáneo — todos los contenedores PHP usan la misma imagen |
| Coexistencia entre versiones | No — deploy atómico via `docker compose up -d` |
| Compatibilidad requerida durante rollout | N/A — deploy atómico |
| Condición para avanzar rollout | Suite PHPUnit pasa, health check `/health` retorna 200, worker consume eventos correctamente |
| Condición para detener rollout | Health check falla o errores CRITICAL en logs del worker |
| Condición de rollback | Worker no arranca, o errores de runtime en los primeros 15 minutos |
| Acción de rollback | `docker compose pull` con tag anterior + `docker compose up -d` |
| Tiempo máximo para iniciar rollback | 30 minutos post-deploy |
| Responsable de decisión | Administrador del sistema (`admon@172.16.0.3`) |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas están definidas | PASS | Redis keys con formato documentado en §9; SQL Server no afectado |
| Todas las columnas mencionadas existen | PASS | N/A — no hay cambios en SQL Server |
| Todos los contratos documentados con clasificación | PASS | §10: 3 contratos internos documentados |
| Todos los requisitos tienen trazabilidad | PASS | §11: 10 requisitos → implementación → validación |
| Todos los consumidores analizados | PASS | §0.2: grafo completo con 11 aristas |
| Todas las migraciones tienen rollback | PASS | §14: rollback documentado (no hay migración SQL) |
| Todas las referencias a archivos, clases, funciones definidas | PASS | Todas las rutas verificadas por lectura directa |
| Toda compatibilidad tiene evidencia | PASS | §0.5: 4 entornos verificados |
| Todos los criterios son verificables | PASS | §18: 8 criterios con resultado observable |
| Observabilidad documentada | PASS | §19: 4 señales documentadas |
| Rollout documentado | PASS | §20: tabla completa |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No | §6: 4 decisiones explícitas con alternativas rechazadas |
| ¿Existe algún contrato sin documentar? | No | §10: todos los contratos nuevos/modificados documentados |
| ¿Existe algún consumidor no analizado? | No | §0.2: grafo cerrado por búsqueda grep exhaustiva |
| ¿Existe alguna migración sin rollback? | No | §14: no hay migración SQL; rollback de código documentado |
| ¿Existe algún dato persistido sin migración? | No | Redis keys nuevas son efímeras (TTL 86400s); no requieren migración |
| ¿Existe alguna afirmación sin evidencia? | No | Todas las afirmaciones referenciadas con ruta:línea |
| ¿Existen referencias huérfanas? | No | Todos los métodos nuevos tienen call sites documentados |
| ¿Dos implementadores producirían soluciones diferentes? | No | Código before/after completo en §13; scripts Lua completos |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque que invoque algo eliminado? | Runtime | NO | No se eliminan archivos, clases ni funciones. Solo se modifica la firma de `compensateFailedEnrollment` (método privado sin consumidores externos). §0.1 |
| 2 | ¿Existe algún paso de build que dependa de algo eliminado? | Build | NO | No se eliminan paquetes, binarios ni archivos. `.gitattributes` es aditivo. §0.1, §0.4 |
| 3 | ¿Existe pipeline con flujo diferente al evaluado? | Pipeline | NO | CI ejecuta `vendor/bin/phpunit` con mocks — mismo entorno evaluado en §0.5. El workflow `deploy.yml` construye imagen Docker que contiene todo el código PHP. |
| 4 | ¿Se asume comportamiento de herramienta sin verificar? | Semántica | NO | §0.4: Redis `DEL`, `SET NX EX`, `EVAL` verificados con documentación oficial y comportamiento empírico del wrapper `RedisClient` |
| 5 | ¿El cambio solo fue evaluado para un entorno? | Paridad | NO | §0.5: 4 entornos verificados como compatibles |
| 6 | ¿Existe override en runtime que anule el cambio? | Runtime por Override | NO | Los métodos PHP son compilados en la imagen Docker inmutable. Variables de entorno no controlan el comportamiento de los flags idempotentes ni de la reapertura. |
| 7 | ¿Se aplicó patrón genérico sin verificar convención local? | Dogmatismo | NO | Los flags idempotentes `SETNX` siguen el mismo patrón que `claimEventProcessingLease` existente en `AuditEventConsumer.php` L603-650 (clave Redis + TTL + guard atómico). La reapertura Lua sigue el patrón de `COMPLETE_AUDIT_LUA` existente. |
| 8 | ¿Se modifica interfaz pública sin compatibilidad? | Contract | NO | `compensateFailedEnrollment` es `private`. Los métodos nuevos (`reopenAuditForReprocess`, `reopenAuditInJob`) son aditivos. No se modifica ningún endpoint REST ni evento. §10 |
| 9 | ¿Se afectan datos persistidos sin migración? | Data | NO | Redis keys nuevas son efímeras (TTL 86400s). Redis keys existentes (`audit:{id}:state`) se modifican atómicamente por Lua con la misma semántica de `SET ... EX ttl` que usa el código actual. SQL Server no se modifica. §9 |
| 10 | ¿Se introduce código muerto o overengineering? | Clean Architecture | NO | Cada cambio está vinculado a un hallazgo QUAL específico. No hay código condicional para casos no validados. Los flags idempotentes usan TTL auto-limpiante. |
| 11 | ¿Se reemplaza mapeo estático por abstracción dinámica sin verificar cobertura? | Abstracción Incorrecta | NO | No se reemplazan mapeos estáticos. El triage marcó §0.3.1 como N/A. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel A — Implementable**

### Justificación

- Toda la información requerida está confirmada por lectura directa del código fuente (§0.6: 12 elementos confirmados, 0 desconocidos).
- No existen supuestos S3 ni S4 (§0.10: solo 2 supuestos S1).
- Todas las verificaciones de FASE 2 pasan (`PASS`).
- Todas las preguntas de FASE 3 resultan `No` con evidencia.
- Todas las preguntas adversariales resultan `NO` con evidencia.
- No hay información faltante crítica ni importante (§0.7, §0.8 vacíos).
- Los scripts Lua, el código PHP before/after, y los tests están completamente especificados — dos implementadores producirán soluciones sustancialmente equivalentes.
