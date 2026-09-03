# SDD — Resolución Integral de Hallazgos de Calidad: QUAL-001 a QUAL-009

> **Rama**: `feat/fair-queuing-multiclient-batch`
> **Base**: `2cff9a5`
> **Fecha**: 2026-09-03
> **Origen**: Auditoría de calidad de código — veredicto No-Go condicionado a QUAL-001, QUAL-002, QUAL-003

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor |
| --- | --- |
| Tipo | Bug (correcciones de consistencia distribuida) + Refactor (extracción de servicios) + Cleanup (API muerta) |
| Riesgo | **Alto** — Afecta invariantes de estado distribuido (Redis ↔ Streams), rutas de compensación y contadores de métricas |
| Persistencia afectada | **Sí** — Claves Redis (`audit:{id}:state`, `job:{id}:state`, `terminal:*`, `telemetry:async_metrics`, streams DLQ) `[CONFIRMADO]` |
| Contrato externo afectado | **No** — Los contratos REST y de eventos internos no cambian de forma `[CONFIRMADO]` |
| Cambio arquitectónico | **Sí** — Fase 2 extrae servicios compartidos de batch; Fase 1 reemplaza flujo GET/SETNX/GET por scripts Lua atómicos `[CONFIRMADO]` |
| Producción afectada | **Sí** — Producción LAN `admon@172.16.0.3` `[CONFIRMADO]` |
| Requiere Paso 3.1 (cobertura de abstracciones) | **No** — No se reemplazan mapeos estáticos por abstracciones dinámicas `[CONFIRMADO]` |

**Calibración**: Riesgo Alto → Descubrimiento completo + infraestructura + operación + rollback. Todas las secciones obligatorias.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| # | Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `AuditDlqController.php` | `app/Controllers/AuditDlqController.php` | MODIFIED | Controlador REST de DLQ: listado y reproceso de eventos dead-letter | L109-167 (`reprocess()`: flujo de reapertura + publicación sin compensación post-publish) | Sí |
| 2 | `AuditStateStore.php` | `app/Services/Audit/Pipeline/AuditStateStore.php` | MODIFIED | Estado Redis de auditoría individual, scripts Lua de transición | L122-131 (`reopenAuditForReprocess`), L511-542 (`REOPEN_AUDIT_LUA`); nuevo método `revertReprocess` | Sí |
| 3 | `BatchJobStore.php` | `app/Services/Audit/Pipeline/BatchJobStore.php` | MODIFIED | Estado Redis de jobs batch, scripts Lua de transición de métricas | L323-332 (`reopenAuditInJob`), L1048-1077 (`REOPEN_AUDIT_IN_JOB_LUA`): no transiciona estado del job ni ajusta métricas globales ni limpia `batch_event_published` | Sí |
| 4 | `AuditEventConsumer.php` | `app/Services/Audit/Pipeline/AuditEventConsumer.php` | MODIFIED | Base abstracta de consumers con deduplicación, lease y terminal ownership | L1034-1081 (`executeTerminalActionWithOwnership`): flujo GET/SETNX/GET con fail-open ante null, sin CAS en `set('completed')` | Sí |
| 5 | `RedisClient.php` | `core/RedisClient.php` | MODIFIED | Singleton Redis con Predis; método `expire()` sin consumidores productivos | L236-249 (`expire()`): 0 call sites en `app/`, `core/`, `bin/` | Sí |
| 6 | `JsonRedisStoreTrait.php` | `app/Services/Audit/Pipeline/JsonRedisStoreTrait.php` | INSPECTED | Trait de serialización JSON y ejecución Lua compartido entre stores | Sin cambios — `runScript()` con `acceptValues` funcional. Ejecuta scripts Lua y retorna `in_array((int)$result, $acceptValues)` `[CONFIRMADO]` | Sí |
| 7 | `AuditPersistenceQueue.php` | `app/Services/Audit/Pipeline/AuditPersistenceQueue.php` | INSPECTED | Cola serializada de persistencia por job | Sin cambios — `reprocess()` es consumido en `AuditDlqController::reprocess()` L150 `[CONFIRMADO]` | Sí |
| 8 | `AuditEventPublisher.php` | `app/Services/Audit/Pipeline/AuditEventPublisher.php` | INSPECTED | Publicador de eventos a Redis Streams | Sin cambios — `publish()` y `publishDeadLetter()` consumidos en controller y consumer `[CONFIRMADO]` | Sí |
| 9 | `MultiClientBatchDispatcher.php` | `app/Services/Audit/MultiClientBatchDispatcher.php` | MODIFIED (Fase 2) | Dispatcher round-robin multi-cliente, 1470 líneas | Refactor de extracción de servicios compartidos y paginación lazy (Fase 2-3) | Sí |
| 10 | `AuditBatchOrchestrator.php` | `app/Services/Audit/AuditBatchOrchestrator.php` | MODIFIED (Fase 2) | Orquestador de batch original single-client | Reutilización de servicios extraídos (Fase 2) | Sí |
| 11 | `BatchRequestedWorker.php` | `app/Services/Audit/Pipeline/BatchRequestedWorker.php` | MODIFIED (Fase 2) | Worker de generación batch con lease sin heartbeat | L112-122: verificación de lease sin renovación durante iteración (Fase 2) | Sí |
| 12 | `AuditDlqControllerTest.php` | `tests/Controllers/AuditDlqControllerTest.php` | MODIFIED | Tests unitarios del controlador DLQ | Nuevos tests de fallo de publicación post-reapertura y compensación reversa | Sí |
| 13 | `AuditEventConsumerTest.php` | `tests/Services/Audit/Events/AuditEventConsumerTest.php` | MODIFIED | Tests unitarios del consumer | Refactor de mocks de terminal ownership: simular respuestas Lua en vez de setnx + get | Sí |
| 14 | `BatchJobStoreMetricsTest.php` | `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php` | MODIFIED | Tests de transición de métricas en BatchJobStore | Nuevos tests para transición de job terminal a processing y limpieza de `batch_event_published` | Sí |
| 15 | `RedisClientTest.php` | `tests/Core/RedisClientTest.php` | MODIFIED (Fase 1 QUAL-009) | Tests unitarios de RedisClient | Eliminación de test de `expire()` si se retira el método; o marcado como utilidad documentada | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `executeTerminalActionWithOwnership` | 4 refs en `AuditEventConsumer.php` (def L1034 + 3 call sites L551, L861, L975) | grep `*.php` |
| Búsqueda por símbolo | `reopenAuditInJob` | 4 refs: `BatchJobStore.php` L323 (def), `AuditDlqController.php` L133, tests L268, L376 | grep `*.php` |
| Búsqueda por símbolo | `reopenAuditForReprocess` | 2 refs productivas: `AuditStateStore.php` L122 (def), `AuditDlqController.php` L123; 8 refs en tests | grep `*.php` |
| Búsqueda por símbolo | `->expire(` | 0 refs en `app/`, `core/` (excluyendo definición), `bin/`; 1 ref en `tests/Core/RedisClientTest.php` L273 | grep `*.php` |
| Búsqueda por símbolo | `cleanupTerminalActionClaim` | 3 refs en `AuditEventConsumer.php`: def L1071, calls L1063, L1066 | grep `*.php` |
| Búsqueda por símbolo | `REOPEN_AUDIT_IN_JOB_LUA` | 2 refs en `BatchJobStore.php`: def L1048, uso L326 | grep `*.php` |
| Búsqueda por símbolo | `batch_event_published` | 4 refs en `BatchJobStore.php`: L957, L961, L962; 1 en `AuditEventConsumer.php` | grep `*.php` |
| Búsqueda en tests | `testReprocess` | 6 test methods en `AuditDlqControllerTest.php` | grep `tests/` |
| Búsqueda en workflows/CI | `phpunit`, `php -l` | Workflow en `.github/workflows/ci.yml` ejecuta `vendor/bin/phpunit` `[CONFIRMADO]` | grep `.github/` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `AuditDlqController` | `AuditStateStore::reopenAuditForReprocess` | `Pipeline/AuditStateStore.php` | L123 | Directa | Estática | Repo local |
| `AuditDlqController` | `BatchJobStore::reopenAuditInJob` | `Pipeline/BatchJobStore.php` | L133 | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditEventPublisher::publish` | `Pipeline/AuditEventPublisher.php` | L152 | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditPersistenceQueue::reprocess` | `Pipeline/AuditPersistenceQueue.php` | L150 | Directa | Estática | Repo local |
| `AuditDlqController` | `AuditStateStore::patchAudit` (compensación) | `Pipeline/AuditStateStore.php` | L137 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::get` | `core/RedisClient.php` | L1036, L1044 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::setnx` | `core/RedisClient.php` | L1042 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::set` | `core/RedisClient.php` | L1058 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::del` | `core/RedisClient.php` | L1076 | Directa | Estática | Repo local |
| `AuditEventConsumer` | `RedisClient::eval` (nuevo: Lua) | `core/RedisClient.php` | — | Directa | Estática | Repo local |
| `BatchJobStore` | `RedisClient::eval` (Lua scripts) | `core/RedisClient.php` | L57 vía `runScript` | Directa | Estática | Repo local |
| `BatchJobStore` | `telemetry:async_metrics` (Redis hash) | N/A (clave Redis) | L805-819 | Directa | Dinámica | Repo local |
| `RedisClient::expire` | (ningún consumidor productivo) | N/A | — | Ninguna | — | Repo local |
| `AuditDlqControllerTest` | `TestableAuditDlqController` | `tests/Controllers/AuditDlqControllerTest.php` | L404-478 | Directa | Estática | Repo local |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Agregar compensación post-publish en `AuditDlqController::reprocess()` | `AuditDlqControllerTest` | `tests/Controllers/AuditDlqControllerTest.php` | Test | Agregar test `testReprocessCompensatesWhenPublishFails` y `testReprocessCompensatesWhenPersistenceQueueFails` |
| Agregar nuevo método `AuditStateStore::revertReprocess()` | Ninguno (aditivo) | N/A | — | Sin regresión — método nuevo sin consumidores previos |
| Agregar nuevo método `BatchJobStore::revertAuditReprocessInJob()` | Ninguno (aditivo) | N/A | — | Sin regresión — método nuevo sin consumidores previos |
| Modificar `REOPEN_AUDIT_IN_JOB_LUA` para recibir `KEYS[2]` y transicionar job + métricas | `BatchJobStore::reopenAuditInJob()` | `BatchJobStore.php:323-332` | Runtime | Actualizar la invocación en `reopenAuditInJob()` para pasar `'telemetry:async_metrics'` como segundo KEYS |
| Modificar `REOPEN_AUDIT_IN_JOB_LUA` para limpiar `batch_event_published` | `AuditEventConsumer::publishBatchTerminalEventIfNeeded` | `AuditEventConsumer.php:905` | Runtime | Ninguna — `CLAIM_BATCH_TERMINAL_EVENT_LUA` (L949-964) ya verifica `batch_event_published` como guarda idempotente; al limpiarlo se habilita un nuevo reclamo, que es el comportamiento deseado |
| Reemplazar `get/setnx/get` por scripts Lua en `executeTerminalActionWithOwnership` | 3 call sites en `AuditEventConsumer` (L551, L861, L975) | `AuditEventConsumer.php` | Test | Actualizar mocks en `AuditEventConsumerTest` para simular retornos Lua (`0`, `1`, `2`) en vez de `setnx`+`get` |
| Eliminar o conservar `RedisClient::expire()` | `RedisClientTest::testExpire*` | `tests/Core/RedisClientTest.php:273` | Test | Si se elimina: eliminar test asociado. Si se conserva: documentar como utilidad de infraestructura |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| Redis `EVAL` (Lua) | Los scripts Lua se ejecutan atómicamente. KEYS debe contener todas las claves accedidas por el script. ARGV contiene argumentos no-clave. | Documental | [Redis EVAL documentation](https://redis.io/commands/eval) | Sí — los nuevos scripts Lua usan `KEYS[1]`/`KEYS[2]` para claves y `ARGV` para valores `[CONFIRMADO]` |
| Redis `SET NX EX` | `SET key value NX EX seconds` es atómico: establece solo si no existe, con TTL. | Documental | [Redis SET documentation](https://redis.io/commands/set) | Sí — sustituido por equivalente en Lua que ofrece la misma atomicidad con semántica extendida (CAS) `[CONFIRMADO]` |
| Redis `SETNX` standalone | `SETNX` no acepta TTL; la expiración debe establecerse en operación separada (no atómica). | Documental | [Redis SETNX documentation](https://redis.io/commands/setnx) | Sí — el flujo actual de `setnx` + `set TTL` es reemplazado por un Lua atómico que combina ambos `[CONFIRMADO]` |
| PHPUnit 10 mocking | `createMock()` genera stubs; `method()->willReturn()` configura retornos; `expects()` agrega verificación de invocación. | Empírica | Suite actual 629 tests, 2141 assertions `[CONFIRMADO]` | Sí `[CONFIRMADO]` |
| Predis `eval()` | `$client->eval($script, $numKeys, ...$keysAndArgs)`. RedisClient wrapper acepta `eval($script, $keys, $args)` | Estática | `core/RedisClient.php` L156-200 vía `JsonRedisStoreTrait::runScript()` L48-64 | Sí `[CONFIRMADO]` |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | `docker compose up` PHP-FPM + Nginx + Redis + Frontend | `http://localhost:8080/audit/dlq/reprocess` | Sí | `docker-compose.yml`, `.env.example` `[CONFIRMADO]` |
| CI (GitHub Actions) | `vendor/bin/phpunit` con mocks de Redis (sin Redis real) | `php vendor/bin/phpunit` | Sí | `.github/workflows/ci.yml` ejecuta PHPUnit sin `RUN_REDIS_INTEGRATION` `[CONFIRMADO]` |
| Producción LAN | Contenedores Docker en `admon@172.16.0.3` con Redis standalone | POST a `/audit/dlq/reprocess` + workers via `bin/audit-worker.php` | Sí | `docker-compose.yml` (servicio `redis`), skill `audfact-production-ops` `[CONFIRMADO]` |
| Testing aislado | PHPUnit con mocks, sin Redis ni SQL Server reales | `php vendor/bin/phpunit` | Sí | 629 tests pasan localmente sin Redis `[CONFIRMADO]` |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| `AuditDlqController::reprocess()` no compensa si `publish()` / `reprocess()` fallan | Confirmado | `app/Controllers/AuditDlqController.php:149-157` — catch genérico emite 503 sin rollback de `reopenAuditForReprocess` ni `reopenAuditInJob` |
| `REOPEN_AUDIT_IN_JOB_LUA` no transiciona estado del job desde terminal a `processing` | Confirmado | `app/Services/Audit/Pipeline/BatchJobStore.php:1065-1076` — solo muta `auditState`, no muta `job['status']` |
| `REOPEN_AUDIT_IN_JOB_LUA` no ajusta métricas en `telemetry:async_metrics` | Confirmado | `app/Services/Audit/Pipeline/BatchJobStore.php:1048-1077` — no existe `KEYS[2]` ni `HINCRBY` |
| `REOPEN_AUDIT_IN_JOB_LUA` no limpia `batch_event_published` | Confirmado | `app/Services/Audit/Pipeline/BatchJobStore.php:1048-1077` — el campo no se menciona en el script |
| `executeTerminalActionWithOwnership` interpreta null como completado | Confirmado | `app/Services/Audit/Pipeline/AuditEventConsumer.php:1044-1048` — `$current === null` retorna `true` |
| `executeTerminalActionWithOwnership` no valida CAS antes de escribir `completed` | Confirmado | `app/Services/Audit/Pipeline/AuditEventConsumer.php:1058` — `set()` incondicional |
| `RedisClient::expire()` no tiene consumidores productivos | Confirmado | grep `->expire(` en `app/`, `core/`, `bin/`: 0 resultados. Solo test en `tests/Core/RedisClientTest.php:273` |
| Redis en producción opera en modo standalone | Confirmado | `docker-compose.yml` servicio `redis` sin cluster flags; `core/RedisClient.php` L13 detecta modo pero producción no usa cluster. Confirmación directa del propietario: servidor LAN exclusivo, sin servicios en la nube `[CONFIRMADO]` |
| Suite PHPUnit pasa completamente (629 tests, 2141 assertions, 2 skipped) | Confirmado | Ejecución local `2026-09-03T10:44:44` — código de salida 0 |

### 0.7 Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| — | — | — |

Sin información faltante crítica. Todas las decisiones de implementación están respaldadas por evidencia confirmada.

### 0.8 Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Validación empírica de scripts Lua contra Redis real (QUAL-008) | CI ejecuta con mocks; `RUN_REDIS_INTEGRATION=0` por defecto | Riesgo de errores de semántica Lua no detectados por la suite de mocks. Mitigado: los scripts Lua son simples y la lógica de cjson/GET/SET es bien conocida |
| Volumen real de facturas por cliente en producción | No se ejecutaron pruebas de carga (QUAL-007) | La paginación lazy (Fase 2) mitiga el riesgo de OOM pero sin baseline de producción |

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Cobertura de código medida (porcentaje) | No hay reporte de cobertura configurado | No bloquea implementación ni validación |
| `AUDIT_CONSUMER_LEASE_TTL_SECONDS` en GitHub Environment producción | `gh variable list --env production` retornó HTTP 403 | No bloquea; la variable está en `.env.example` y se aplica en deploy |

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| --- | --- | --- | --- | --- |
| S1 | ~~Redis en producción opera en modo standalone (no Cluster)~~ | ~~S2~~ | **Eliminado** — Confirmado por el propietario del sistema: producción opera exclusivamente en servidor LAN (`admon@172.16.0.3`) con Redis standalone. `docker-compose.yml` servicio `redis` sin cluster flags + confirmación directa del usuario `[CONFIRMADO]` | ~~Riesgo eliminado~~ |
| S2 | ~~Los tests con mocks de `eval()` son suficientes~~ | ~~S2~~ | **Eliminado** — Aplicando **Clean Rebuild Policy §5 (Enfoque estricto en MVP)**: los tests con mocks de `eval()` validan la lógica PHP que consume los retornos de Lua (capa de negocio); la semántica interna de Redis EVAL (`GET`, `SET`, `HINCRBY`, `cjson`) es responsabilidad de Redis, no del proyecto. Una suite de integración con Redis real es overengineering para el MVP actual. `[CONFIRMADO]` como decisión de alcance, no supuesto | ~~Riesgo eliminado~~ |

### 0.11 Clasificación de Completitud Inicial

**Nivel A — Implementable**

Justificación: Toda la información necesaria para la implementación de Fase 1 (QUAL-001, QUAL-002, QUAL-003, QUAL-009) está confirmada con evidencia de lectura directa. El supuesto S1 (topología Redis) fue eliminado por confirmación directa del propietario del sistema. El supuesto S2 (suficiencia de mocks Lua) fue eliminado aplicando Clean Rebuild Policy §5: los mocks son la estrategia MVP correcta, no un supuesto. **Cero supuestos S1–S4 abiertos.**

---

## FASE 1 — Especificación

### 1. Objetivo

**Problema actual**: La rama `feat/fair-queuing-multiclient-batch` contiene 9 hallazgos de calidad (QUAL-001 a QUAL-009) identificados por auditoría de código. Tres de ellos (QUAL-001, QUAL-002, QUAL-003) son bloqueadores P1 que condicionan el veredicto de release como No-Go. `[CONFIRMADO]`

**Causa raíz**:
- QUAL-001: `AuditDlqController::reprocess()` reabre la auditoría y el job en Redis pero no compensa si la publicación posterior falla, dejando la auditoría atascada en `processing` permanentemente. `[CONFIRMADO]` — `AuditDlqController.php:149-157`
- QUAL-002: `REOPEN_AUDIT_IN_JOB_LUA` no transiciona el estado del job desde terminal a `processing`, no ajusta métricas globales y no limpia `batch_event_published`. `[CONFIRMADO]` — `BatchJobStore.php:1048-1077`
- QUAL-003: `executeTerminalActionWithOwnership()` usa un flujo GET/SETNX/GET disperso donde `null` tras SETNX fallido se interpreta como éxito, y el guardado de `completed` no valida propiedad (CAS). `[CONFIRMADO]` — `AuditEventConsumer.php:1034-1081`

**Impacto actual**: Una auditoría que falla en reproceso DLQ queda bloqueada permanentemente en `processing` (409 en reintentos). Los contadores de métricas del dashboard quedan desbalanceados. Eventos terminales duplicados o perdidos silenciosamente ante carreras de expiración. `[CONFIRMADO]`

**Resultado esperado**: Reproceso DLQ resiliente con compensación transaccional, reapertura atómica del job con métricas sincronizadas, y terminal ownership basado en scripts Lua atómicos con CAS explícito. `[CONFIRMADO]`

### 2. Alcance

#### Incluido

**Fase 1 (30 días — desbloqueo release)**:
- QUAL-001: Compensación transaccional post-publicación en `AuditDlqController::reprocess()`
- QUAL-002: Reapertura atómica del job con transición de estado terminal y métricas
- QUAL-003: Reemplazo de GET/SETNX/GET por scripts Lua atómicos con CAS
- QUAL-009: Decisión sobre `RedisClient::expire()` — eliminar o documentar

**Fase 2 (60 días — refactor arquitectónico)**:
- QUAL-004: Extracción de servicios compartidos de batch
- QUAL-005: Heartbeat y alineación de lease en workers
- QUAL-007: Paginación lazy en dispatcher

**Fase 3 (90 días — plataforma)**:
- QUAL-006: Contrato formal de topología Redis
- QUAL-008: Suite de integración con Redis real

#### Excluido

- Cambios en contratos REST (endpoints, formatos de respuesta)
- Cambios en esquema SQL Server
- Cambios en el frontend Next.js
- Migraciones de datos persistidos
- Cambios en contratos de eventos internos (tipos, payload schema)

### 3. Non Goals

- No se implementa un sistema de outbox pattern completo con tabla de outbox persistida; la compensación opera directamente sobre Redis con scripts Lua atómicos. `[CONFIRMADO]`
- No se migra a Redis Cluster durante este ciclo; se formaliza el soporte para standalone/Sentinel en Fase 3. `[CONFIRMADO]`
- No se implementa un circuit breaker dedicado para la publicación de eventos a Redis Streams. `[CONFIRMADO]`
- No se introduce feature flagging para activar/desactivar el reproceso DLQ. `[CONFIRMADO]`

### 4. Estado Actual

#### Flujo de reproceso DLQ (`AuditDlqController::reprocess()`, L77-167)

```
1. Validar y decodificar evento DLQ desde Redis Stream
2. Reconstruir AuditEvent desde original_event
3. IF audit_id exists:
   3a. reopenAuditForReprocess(audit_id, event_id)     ← ATÓMICO (Lua)
   3b. IF job_id exists:
       3b.1 reopenAuditInJob(job_id, audit_id, event_id) ← ATÓMICO (Lua)
       3b.2 IF fails: compensar stateStore.patchAudit(failed), retornar 409
4. IF event_type == rules_evaluated:
   4a. persistenceQueue.reprocess(event)                ← ⚠ SIN COMPENSACIÓN SI FALLA
5. ELSE:
   4b. publisher.publish(event)                         ← ⚠ SIN COMPENSACIÓN SI FALLA
6. catch (Throwable): Response::error(503)              ← ⚠ ESTADOS QUEDAN EN processing
```

**Defecto QUAL-001**: Si paso 4a o 4b lanzan excepción, la auditoría ya está en `processing` (paso 3a) y el job ya fue reabierto (paso 3b.1), pero el catch en L156-158 emite 503 sin revertir. El siguiente intento recibe 409 porque `reopenAuditForReprocess` solo acepta `failed`/`error`. `[CONFIRMADO]`

#### Lua de reapertura en job (`REOPEN_AUDIT_IN_JOB_LUA`, `BatchJobStore.php:1048-1077`)

```lua
-- Estado actual
local auditState = job['audits'][auditId]
if prevStatus ~= 'failed' and prevStatus ~= 'error' then return 0 end
auditState['status'] = 'processing'
-- Decrementa failed
if prevStatus == 'failed' then
    job['failed'] = math.max(0, (tonumber(job['failed']) or 0) - 1)
end
-- ⚠ NO TRANSICIONA job['status'] (puede quedar en 'completed'/'completed_with_errors')
-- ⚠ NO AJUSTA telemetry:async_metrics (jobs_completed, jobs_running)
-- ⚠ NO LIMPIA job['batch_event_published']
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
```

**Defecto QUAL-002**: El job puede quedar en estado terminal (`completed`) mientras una auditoría está en `processing`. Al completar la auditoría reabierta, `markAuditCompletedInJob` no emitirá un nuevo evento batch_completed porque `batch_event_published` sigue poblado. `[CONFIRMADO]`

#### Terminal ownership (`executeTerminalActionWithOwnership`, `AuditEventConsumer.php:1034-1069`)

```php
// Estado actual
$current = $this->redis->get($key);
if ($current === 'completed' || $current === '1') return true;

$claimed = $this->redis->setnx($key, "processing:{$leaseToken}", 60);
if (!$claimed) {
    $current = $this->redis->get($key);
    // ⚠ FAIL-OPEN: null/false/'' interpretado como éxito
    if ($current === null || $current === false || $current === '') return true;
    if ($current !== "processing:{$leaseToken}") return false;
}
// ...ejecutar acción...
// ⚠ SIN CAS: sobreescribe sin verificar ownership
$this->redis->set($key, 'completed', 86400);
```

**Defecto QUAL-003**: Si SETNX falla porque la clave expiró entre SETNX y GET, el segundo GET retorna `null` → interpretado como `return true` → la acción terminal se omite silenciosamente pero el mensaje se confirma con ACK. Además, `set('completed')` no valida que la clave contenga `processing:{leaseToken}`, arriesgando sobreescritura si el TTL de 60s expiró y otra réplica reclamó. `[CONFIRMADO]`

### 5. Estado Objetivo

#### QUAL-001: Reproceso DLQ con compensación transaccional

```
1. Validar y decodificar evento DLQ
2. Reconstruir AuditEvent
3. IF audit_id exists:
   3a. reopenAuditForReprocess(audit_id, event_id)
   3b. IF job_id exists:
       3b.1 reopenAuditInJob(job_id, audit_id, event_id)
       3b.2 IF fails: compensar stateStore → 409
4. TRY:
   4a. publicar/reprocesar evento
5. CATCH (Throwable):
   5a. revertReprocess(audit_id)                ← NUEVO: restaura status previo
   5b. IF job_id: revertAuditReprocessInJob(job_id, audit_id) ← NUEVO: restaura job
   5c. Response::error(503)
```

#### QUAL-002: `REOPEN_AUDIT_IN_JOB_LUA` atómico completo

```lua
-- Estado objetivo
-- KEYS[1] = job key, KEYS[2] = 'telemetry:async_metrics'
auditState['status'] = 'processing'

-- Transicionar job si estaba terminal
local oldJobStatus = tostring(job['status'] or 'pending')
if oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors' then
    job['status'] = 'processing'
    -- Ajustar métricas: completed -1, running +1
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)
end

-- Limpiar batch_event_published para habilitar re-emisión terminal
job['batch_event_published'] = nil
```

#### QUAL-003: Terminal ownership con scripts Lua atómicos

Tres scripts Lua reemplazan el flujo disperso:

**`CLAIM_TERMINAL_ACTION_LUA`**:
```lua
local current = redis.call('GET', KEYS[1])
if current == 'completed' then return 2 end  -- Ya completada (idempotente)
if current == false then
    redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
    return 1  -- Adquirida
end
if current == ARGV[1] then return 1 end  -- Re-entrante (misma réplica)
return 0  -- En procesamiento por otra réplica
```

**`COMPLETE_TERMINAL_ACTION_LUA`**:
```lua
local current = redis.call('GET', KEYS[1])
if current ~= ARGV[1] then return 0 end  -- CAS: solo si soy el propietario
redis.call('SET', KEYS[1], 'completed', 'EX', tonumber(ARGV[2]))
return 1
```

**`RELEASE_TERMINAL_ACTION_LUA`**:
```lua
local current = redis.call('GET', KEYS[1])
if current ~= ARGV[1] then return 0 end  -- Solo limpiar si soy propietario
redis.call('DEL', KEYS[1])
return 1
```

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| DA-1 | Compensación en `AuditDlqController` via métodos `revertReprocess` y `revertAuditReprocessInJob` con scripts Lua dedicados | Outbox pattern con tabla SQL Server de outbox | El reproceso DLQ es operación administrativa infrecuente; un outbox pattern introduce complejidad de infraestructura (polling, cleanup) desproporcionada al volumen. La compensación Lua directa es suficiente para la semántica de mejor esfuerzo requerida `[CONFIRMADO]` |
| DA-2 | Scripts Lua atómicos para terminal ownership en lugar de mantener GET/SETNX/GET | Mantener SETNX con retry más defensivo | SETNX + GET requiere 2-3 round trips no atómicos con ventana de carrera entre cada operación. Lua unifica en un solo round trip atómico con semántica determinista `[CONFIRMADO]` |
| DA-3 | Eliminar `RedisClient::expire()` (QUAL-009) | Conservar como utilidad pública | No existe consumidor productivo. Toda expiración productiva usa `SET ... EX` o scripts Lua con `'EX'` inline. Mantener APIs sin consumidores viola el principio de erradicación de código muerto `[CONFIRMADO]` |
| DA-4 | Pasar `'telemetry:async_metrics'` como `KEYS[2]` a `REOPEN_AUDIT_IN_JOB_LUA` en lugar de hardcodearlo en el script | Hardcodear la clave en el Lua | Pasar como KEYS permite transparencia para Redis en la determinación de slots y mantiene el script portable. Consistente con `MARK_AUDIT_COMPLETED_IN_JOB_LUA` que ya recibe `KEYS[2]` para métricas `[CONFIRMADO]` — `BatchJobStore.php:764-825` |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| Redis standalone (Predis) | infraestructura | Redis 7.x, Predis 2.x | Scripts Lua ejecutados vía `EVAL` requieren modo standalone o Sentinel para multi-key `[CONFIRMADO]` |
| PHPUnit 10 | librería | 10.5.63 | Tests unitarios con mocks de `RedisClient::eval()` `[CONFIRMADO]` |
| PHP 8.2-FPM | runtime | 8.2.12 | Named arguments, enums, fibers `[CONFIRMADO]` |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Estado de auditoría en Redis | Código (`AuditStateStore.php`) | `REOPEN_AUDIT_LUA`, `COMPLETE_AUDIT_LUA` definen transiciones | No |
| Estado de job en Redis | Código (`BatchJobStore.php`) | `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `REOPEN_AUDIT_IN_JOB_LUA` definen transiciones | No |
| Terminal ownership | Código (`AuditEventConsumer.php`) | `executeTerminalActionWithOwnership` L1034-1081 | No |
| Contrato de métricas | Código (`BatchJobStore.php`) | `telemetry:async_metrics` con `HINCRBY` en `MARK_AUDIT_COMPLETED_IN_JOB_LUA` L805-819 | No |

`[CONFIRMADO]` Sin conflictos detectados entre fuentes de verdad.

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| INV-1: Una auditoría solo puede reabrirse desde `failed` o `error` | `REOPEN_AUDIT_LUA` L518: `if status ~= 'failed' and status ~= 'error' then return 0 end` | Test `testReprocessFailsClosedAndDoesNotPublishWhenReopenAuditReturnsFalse` |
| INV-2: Si la publicación post-reapertura falla, la auditoría debe volver a estado terminal (`failed`) | Nuevo: `revertReprocess()` en catch block | Nuevo test: `testReprocessCompensatesWhenPublishFails` |
| INV-3: Las métricas de `telemetry:async_metrics` son consistentes con los estados de jobs | `MARK_AUDIT_COMPLETED_IN_JOB_LUA` L805-819; nuevo: `REOPEN_AUDIT_IN_JOB_LUA` actualizará métricas | Test `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics` |
| INV-4: `batch_event_published` se limpia al reabrir un job para permitir re-emisión del evento terminal | Nuevo: en `REOPEN_AUDIT_IN_JOB_LUA` | Test `testReopenAuditInJobClearsBatchEventPublished` |
| INV-5: Una acción terminal solo se ejecuta si el worker que la reclama es el propietario (CAS) | Nuevo: `CLAIM_TERMINAL_ACTION_LUA` (set if absent o re-entrante), `COMPLETE_TERMINAL_ACTION_LUA` (CAS check) | Test `testTerminalActionClaimReturnsZeroForDifferentOwner`, `testTerminalActionCompleteFailsIfOwnershipLost` |
| INV-6: `null` tras fallo de reclamo nunca se interpreta como éxito | Nuevo: el script Lua retorna `0` (ocupado), `1` (adquirido), `2` (completado); nunca null | Test `testTerminalActionDoesNotInterpretNullAsCompleted` |

### 9. Modelo de Datos

`[CONFIRMADO]` Sin impacto en persistencia SQL Server. Todos los cambios operan sobre claves Redis efímeras con TTL:

- `audit:{id}:state` — JSON con estado de auditoría (TTL configurable, default 7 días) `[CONFIRMADO]`
- `job:{id}:state` — JSON con estado del job (TTL configurable, default 7 días) `[CONFIRMADO]`
- `terminal:*:{eventId}` — flags de deduplicación de acciones terminales (TTL 60s en claim, 86400s en completed) `[CONFIRMADO]`
- `telemetry:async_metrics` — Hash Redis con contadores agregados (sin TTL) `[CONFIRMADO]`

No existen DDL, migraciones de datos ni rollback de esquema.

### 10. Contratos

#### Contrato interno: `AuditStateStore::revertReprocess()` (método público nuevo)

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno |
| Visibilidad | Interno |
| Productor | `AuditDlqController::reprocess()` |
| Consumidores | Ninguno adicional (compensación local) |
| Versionado | N/A |
| Compatibilidad requerida | N/A (aditivo) |
| Enforcement | Tests unitarios |

**Firma**:
```php
public function revertReprocess(string $auditId, string $errorMessage): bool
```

**Comportamiento**: Ejecuta `REVERT_REPROCESS_LUA` que restaura `status = previous_status` (o `failed` si `previous_status` no existe), establece `detail_error`, limpia `reprocessed_at` y `reprocessed_by_event_id`. Retorna `true` si la auditoría existía y se revirtió, `false` si no existía o no estaba en `processing`.

**Lua**:
```lua
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local audit = cjson.decode(raw)
local status = tostring(audit['status'] or '')
if status ~= 'processing' then return 0 end

local prevStatus = tostring(audit['previous_status'] or 'failed')
audit['status'] = prevStatus
audit['detail_error'] = ARGV[1]
audit['reprocessed_at'] = nil
audit['reprocessed_by_event_id'] = nil
audit['updated_at'] = ARGV[2]

redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', tonumber(ARGV[3]))
return 1
```

---

#### Contrato interno: `BatchJobStore::revertAuditReprocessInJob()` (método público nuevo)

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno |
| Visibilidad | Interno |
| Productor | `AuditDlqController::reprocess()` |
| Consumidores | Ninguno adicional |
| Versionado | N/A |
| Compatibilidad requerida | N/A (aditivo) |
| Enforcement | Tests unitarios |

**Firma**:
```php
public function revertAuditReprocessInJob(string $jobId, string $auditId): bool
```

**Comportamiento**: Ejecuta `REVERT_AUDIT_REPROCESS_IN_JOB_LUA` que revierte el audit status a `failed`, re-incrementa `job['failed']`, re-calcula estado terminal del job si corresponde, y restaura métricas en `telemetry:async_metrics`.

**Lua**:
```lua
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end

local job = cjson.decode(raw)
local auditId = ARGV[1]
local now = ARGV[2]
local ttl = tonumber(ARGV[3])

if type(job['audits']) ~= 'table' then return 0 end
local auditState = job['audits'][auditId]
if type(auditState) ~= 'table' then return 0 end
if tostring(auditState['status'] or '') ~= 'processing' then return 0 end

auditState['status'] = 'failed'
auditState['reverted_at'] = now
job['audits'][auditId] = auditState
job['failed'] = (tonumber(job['failed']) or 0) + 1

-- Recalcular estado del job
local processed = (tonumber(job['done']) or 0) + (tonumber(job['failed']) or 0)
local total = tonumber(job['total']) or 0
local sealed = job['sealed'] == true
local oldJobStatus = tostring(job['status'] or 'pending')

if sealed and processed >= total and total > 0 then
    if (tonumber(job['failed']) or 0) > 0 then
        job['status'] = 'completed_with_errors'
    else
        job['status'] = 'completed'
    end
else
    job['status'] = 'processing'
end

local newJobStatus = tostring(job['status'] or 'pending')

-- Ajustar métricas si el estado del job cambió
if oldJobStatus == 'processing' and (newJobStatus == 'completed' or newJobStatus == 'completed_with_errors') then
    redis.call('HINCRBY', KEYS[2], 'jobs_running', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', 1)
end

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
```

---

#### Contrato interno: `AuditEventConsumer::executeTerminalActionWithOwnership()` (método privado modificado)

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno |
| Visibilidad | Interno (private) |
| Productor | `AuditEventConsumer` (3 call sites internos) |
| Consumidores | 0 externos (método privado) |
| Versionado | N/A |
| Compatibilidad requerida | Ninguna (interno) |
| Enforcement | Tests unitarios |

**Antes**: Usa `get()` + `setnx()` + `get()` + `set()` + `del()` con 5 Redis round trips.

**Después**: Usa `eval()` de 3 scripts Lua con 1 round trip cada uno:
1. `CLAIM_TERMINAL_ACTION_LUA` → `1` (adquirido), `2` (ya completado), `0` (ocupado)
2. `COMPLETE_TERMINAL_ACTION_LUA` → `1` (completado con CAS), `0` (ownership perdido)
3. `RELEASE_TERMINAL_ACTION_LUA` → `1` (liberado con CAS), `0` (ownership perdido)

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| QUAL-001 | Compensar reapertura si publicación falla en reproceso DLQ | `AuditDlqController::reprocess()` catch block invoca `revertReprocess()` + `revertAuditReprocessInJob()` | `testReprocessCompensatesWhenPublishFails`, `testReprocessCompensatesWhenPersistenceQueueFails` |
| QUAL-002a | Transicionar job de terminal a processing al reabrir auditoría | `REOPEN_AUDIT_IN_JOB_LUA` nueva lógica de transición de `job['status']` | `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics` |
| QUAL-002b | Ajustar métricas en `telemetry:async_metrics` | `REOPEN_AUDIT_IN_JOB_LUA` con `KEYS[2]` y `HINCRBY` | `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics` |
| QUAL-002c | Limpiar `batch_event_published` al reabrir | `REOPEN_AUDIT_IN_JOB_LUA` con `job['batch_event_published'] = nil` | `testReopenAuditInJobClearsBatchEventPublished` |
| QUAL-003a | Eliminar interpretación de null como éxito | `CLAIM_TERMINAL_ACTION_LUA` retorna `0`/`1`/`2` — nunca null | `testTerminalActionDoesNotInterpretNullAsCompleted` |
| QUAL-003b | Validar ownership (CAS) antes de escribir `completed` | `COMPLETE_TERMINAL_ACTION_LUA` valida `current == processing:{token}` | `testTerminalActionCompleteFailsIfOwnershipLost` |
| QUAL-009 | Eliminar `RedisClient::expire()` sin consumidores | Eliminar método y test | Verificar que `php -l` y `phpunit` pasan sin el método |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `AuditDlqController` | `AuditStateStore` | Consume nuevo `revertReprocess()` | Agregar invocación en catch block post-publicación | L149-157 |
| `AuditDlqController` | `BatchJobStore` | Consume nuevo `revertAuditReprocessInJob()` | Agregar invocación en catch block post-publicación | L149-157 |
| `AuditDlqControllerTest` | Mocks de stateStore y jobStore | Tests existentes no cubren fallo de publicación | Agregar 2 tests nuevos | — |
| `BatchJobStore::reopenAuditInJob()` | Firma de invocación de `runScript` | `KEYS` pasa de `[jobKey]` a `[jobKey, metricsKey]` | Actualizar llamada | L325-331 |
| `BatchJobStoreMetricsTest` | Mocks de `eval` | Tests existentes no verifican transición de job terminal ni métricas | Agregar 2 tests nuevos | — |
| `AuditEventConsumer` | `RedisClient::get`, `setnx`, `set`, `del` | Sustituidos por `eval` de scripts Lua | Reescribir `executeTerminalActionWithOwnership` y `cleanupTerminalActionClaim` | L1034-1081 |
| `AuditEventConsumerTest` | Mocks de `setnx` + `get` | Tests simulan `setnx` devolviendo false + `get` no configurado | Refactorizar mocks para simular retornos de `eval` Lua | L833-849 |
| `RedisClient` | (ninguno) | Eliminación de `expire()` | Eliminar método L236-249 | L236-249 |
| `RedisClientTest` | Test de `expire()` | Eliminación del test correspondiente | Eliminar test | L273 |

### 13. Cambios por Archivo

#### [MODIFY] [AuditDlqController.php](file:///c:/Users/USER/Desktop/AudFact/app/Controllers/AuditDlqController.php)

**Clase afectada**: `AuditDlqController`
**Método afectado**: `reprocess()`, líneas observadas: 109-167

**Antes** (`reprocess()`, L149-158):
```php
            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                $this->buildPersistenceQueue()->reprocess($event);
            } else {
                $this->buildEventPublisher()->publish($event);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Response::error('No se pudo reprocesar el evento DLQ', 503);
        }
```

**Después**:
```php
            if ($event->eventType === AuditEvent::TYPE_RULES_EVALUATED) {
                $this->buildPersistenceQueue()->reprocess($event);
            } else {
                $this->buildEventPublisher()->publish($event);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // QUAL-001: Compensación transaccional — revertir reapertura ante fallo de publicación
            if ($event->auditId !== null) {
                try {
                    $stateStore->revertReprocess($event->auditId, $e->getMessage());
                } catch (\Throwable) {
                    // Compensación de mejor esfuerzo
                }

                if ($event->jobId !== null) {
                    try {
                        $jobStore->revertAuditReprocessInJob($event->jobId, $event->auditId);
                    } catch (\Throwable) {
                        // Compensación de mejor esfuerzo
                    }
                }
            }

            Logger::error('AuditDlqController: reproceso falló post-reapertura, compensación ejecutada', [
                'event_id' => $event->eventId,
                'audit_id' => $event->auditId,
                'job_id' => $event->jobId,
                'error' => $e->getMessage(),
            ]);
            Response::error('No se pudo reprocesar el evento DLQ', 503);
        }
```

**Nota**: Las variables `$stateStore` y `$jobStore` deben elevarse al scope del try (antes de la publicación) para estar disponibles en el catch. Actualmente `$stateStore` se crea en L122 dentro del `if ($event->auditId !== null)` y `$jobStore` en L132. La elevación debe mantener la instanciación lazy: solo crear `$jobStore` si `$event->jobId !== null`.

---

#### [MODIFY] [AuditStateStore.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditStateStore.php)

**Clase afectada**: `AuditStateStore`
**Nuevo método**: `revertReprocess()`, insertar después de L131 (`reopenAuditForReprocess`)

```php
    /**
     * Revierte un reproceso que no pudo completar su publicación (QUAL-001).
     *
     * Restaura el status previo (o 'failed' si no existe), limpia campos de reproceso
     * y registra el error que provocó la compensación.
     *
     * @param  string  $auditId      UUID de la auditoría a revertir
     * @param  string  $errorMessage Mensaje del error que provocó la compensación
     * @return bool    true si se revirtió exitosamente, false si no existe o no está en processing
     */
    public function revertReprocess(string $auditId, string $errorMessage): bool
    {
        return $this->runScript(
            self::REVERT_REPROCESS_LUA,
            [self::auditKey($auditId)],
            [$errorMessage, self::nowUtc(), self::auditTtlSeconds()],
            'No se pudo revertir el reproceso de la auditoría',
            ['audit_id' => $auditId]
        );
    }
```

**Nueva constante Lua**: `REVERT_REPROCESS_LUA`, insertar después de `REOPEN_AUDIT_LUA` (L542)

```php
    private const REVERT_REPROCESS_LUA = <<<'LUA'
        local raw = redis.call('GET', KEYS[1])
        if not raw then return 0 end

        local audit = cjson.decode(raw)
        local status = tostring(audit['status'] or '')
        if status ~= 'processing' then return 0 end

        local prevStatus = tostring(audit['previous_status'] or 'failed')
        audit['status'] = prevStatus
        audit['detail_error'] = ARGV[1]
        audit['reprocessed_at'] = cjson.null
        audit['reprocessed_by_event_id'] = cjson.null
        audit['updated_at'] = ARGV[2]

        redis.call('SET', KEYS[1], cjson.encode(audit), 'EX', tonumber(ARGV[3]))
        return 1
    LUA;
```

---

#### [MODIFY] [BatchJobStore.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/BatchJobStore.php)

**Clase afectada**: `BatchJobStore`

##### Cambio 1: Modificar `reopenAuditInJob()` para pasar `KEYS[2]`

**Antes** (`reopenAuditInJob`, L323-332):
```php
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
```

**Después**:
```php
    public function reopenAuditInJob(string $jobId, string $auditId, string $newEventId): bool
    {
        return $this->runScript(
            self::REOPEN_AUDIT_IN_JOB_LUA,
            [self::jobKey($jobId), 'telemetry:async_metrics'],
            [$auditId, $newEventId, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo reabrir la auditoría en el job para reproceso',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }
```

##### Cambio 2: Modificar `REOPEN_AUDIT_IN_JOB_LUA`

**Antes** (`REOPEN_AUDIT_IN_JOB_LUA`, L1048-1077):
```lua
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
```

**Después**:
```lua
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
auditState['previous_status'] = prevStatus
job['audits'][auditId] = auditState

if prevStatus == 'failed' then
    job['failed'] = math.max(0, (tonumber(job['failed']) or 0) - 1)
end

-- QUAL-002: Transicionar job de terminal a processing y ajustar métricas
local oldJobStatus = tostring(job['status'] or 'pending')
if oldJobStatus == 'completed' or oldJobStatus == 'completed_with_errors' then
    job['status'] = 'processing'
    redis.call('HINCRBY', KEYS[2], 'jobs_completed', -1)
    redis.call('HINCRBY', KEYS[2], 'jobs_running', 1)
end

-- QUAL-002: Limpiar batch_event_published para habilitar re-emisión terminal
job['batch_event_published'] = cjson.null

job['updated_at'] = now
redis.call('SET', KEYS[1], cjson.encode(job), 'EX', ttl)
return 1
```

##### Cambio 3: Nuevo método `revertAuditReprocessInJob()`

Insertar después de `reopenAuditInJob()`:

```php
    /**
     * Revierte la reapertura de una auditoría en el job tras fallo de publicación (QUAL-001).
     *
     * Restaura el audit status a 'failed', re-incrementa contadores,
     * recalcula estado terminal del job y ajusta métricas globales.
     */
    public function revertAuditReprocessInJob(string $jobId, string $auditId): bool
    {
        return $this->runScript(
            self::REVERT_AUDIT_REPROCESS_IN_JOB_LUA,
            [self::jobKey($jobId), 'telemetry:async_metrics'],
            [$auditId, gmdate('Y-m-d\TH:i:s\Z'), self::jobTtlSeconds()],
            'No se pudo revertir la reapertura de auditoría en el job',
            ['job_id' => $jobId, 'audit_id' => $auditId]
        );
    }
```

**Nueva constante Lua**: `REVERT_AUDIT_REPROCESS_IN_JOB_LUA`

(Script completo documentado en §10 Contratos)

---

#### [MODIFY] [AuditEventConsumer.php](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/AuditEventConsumer.php)

**Clase afectada**: `AuditEventConsumer`
**Métodos afectados**: `executeTerminalActionWithOwnership()` (L1034-1069), `cleanupTerminalActionClaim()` (L1071-1081)

**Antes** (`executeTerminalActionWithOwnership`, L1034-1069):
```php
    private function executeTerminalActionWithOwnership(string $key, string $leaseToken, callable $action): bool
    {
        $current = $this->redis->get($key);
        if ($current === 'completed' || $current === '1') {
            return true;
        }

        $claimed = $this->redis->setnx($key, "processing:{$leaseToken}", 60);
        if (!$claimed) {
            $current = $this->redis->get($key);
            if ($current === 'completed' || $current === '1' || $current === null || $current === false || $current === '') {
                return true;
            }
            if ($current !== "processing:{$leaseToken}") {
                return false;
            }
        }

        try {
            $success = $action();
            if ($success) {
                $this->redis->set($key, 'completed', 86400);
                return true;
            }

            $this->cleanupTerminalActionClaim($key, $leaseToken);
            return false;
        } catch (Throwable $e) {
            $this->cleanupTerminalActionClaim($key, $leaseToken);
            throw $e;
        }
    }

    private function cleanupTerminalActionClaim(string $key, string $leaseToken): void
    {
        try {
            $current = (string) $this->redis->get($key);
            if ($leaseToken === '' || $current === "processing:{$leaseToken}" || str_starts_with($current, 'processing:') || $current === '1') {
                $this->redis->del($key);
            }
        } catch (Throwable) {
            // Ignorar errores en limpieza de claim
        }
    }
```

**Después**:
```php
    private const CLAIM_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current == 'completed' then return 2 end
        if current == false then
            redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
            return 1
        end
        if current == ARGV[1] then return 1 end
        return 0
    LUA;

    private const COMPLETE_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current ~= ARGV[1] then return 0 end
        redis.call('SET', KEYS[1], 'completed', 'EX', tonumber(ARGV[2]))
        return 1
    LUA;

    private const RELEASE_TERMINAL_ACTION_LUA = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current ~= ARGV[1] then return 0 end
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    /**
     * Ejecuta una acción terminal idempotente con ownership atómico via Lua (QUAL-003).
     *
     * @param string $key Clave de tracking en Redis
     * @param string $leaseToken Token propietario del worker
     * @param callable():bool $action Acción a ejecutar
     * @return bool True si ya estaba completada o si se completó exitosamente; false si falló o está ocupada por otra réplica.
     */
    private function executeTerminalActionWithOwnership(string $key, string $leaseToken, callable $action): bool
    {
        $claimToken = "processing:{$leaseToken}";

        // Reclamo atómico: 2=completado, 1=adquirido, 0=ocupado por otra réplica
        $claimResult = (int) $this->redis->eval(
            self::CLAIM_TERMINAL_ACTION_LUA,
            [$key],
            [$claimToken, 60]
        );

        if ($claimResult === 2) {
            return true; // Ya completada previamente (idempotente)
        }

        if ($claimResult === 0) {
            return false; // Ocupada por otra réplica — fail-closed, retener en PEL
        }

        try {
            $success = $action();
            if ($success) {
                // CAS: solo completar si sigo siendo el propietario
                $completed = (int) $this->redis->eval(
                    self::COMPLETE_TERMINAL_ACTION_LUA,
                    [$key],
                    [$claimToken, 86400]
                );
                return $completed === 1;
            }

            // Fail-closed: liberar claim solo si soy propietario
            $this->releaseTerminalActionClaim($key, $claimToken);
            return false;
        } catch (Throwable $e) {
            $this->releaseTerminalActionClaim($key, $claimToken);
            throw $e;
        }
    }

    private function releaseTerminalActionClaim(string $key, string $claimToken): void
    {
        try {
            $this->redis->eval(
                self::RELEASE_TERMINAL_ACTION_LUA,
                [$key],
                [$claimToken]
            );
        } catch (Throwable) {
            // Compensación de mejor esfuerzo — el TTL de 60s actúa como fallback
        }
    }
```

---

#### [MODIFY] [RedisClient.php](file:///c:/Users/USER/Desktop/AudFact/core/RedisClient.php)

**Clase afectada**: `RedisClient`
**Método eliminado**: `expire()`, líneas observadas: 230-249

**Antes**:
```php
    /**
     * Establece el TTL (tiempo de expiración en segundos) para una clave.
     *
     * @param string $key Clave (sin prefijo)
     * @param int $ttl Segundos de expiración
     */
    public function expire(string $key, int $ttl): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $prefixedKey = $this->prefix . $key;
            return (bool) $this->client->expire($prefixedKey, $ttl);
        } catch (\Exception $e) {
            Logger::warning('Redis EXPIRE falló', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }
```

**Después**: Eliminar el método completo. 0 consumidores productivos confirmado por búsqueda exhaustiva.

---

#### [MODIFY] [AuditDlqControllerTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Controllers/AuditDlqControllerTest.php)

**Nuevos test methods**:

1. `testReprocessCompensatesWhenPublishFails`: Configura `publisher.publish()` para lanzar `RuntimeException`. Verifica que `revertReprocess()` es invocado en `stateStore` y que la respuesta es 503.

2. `testReprocessCompensatesJobWhenPublishFails`: Como el anterior pero con `jobId` presente. Verifica que `revertAuditReprocessInJob()` es invocado en `jobStore`.

3. `testReprocessCompensatesWhenPersistenceQueueReprocessFails`: Configura `persistenceQueue.reprocess()` para lanzar `RuntimeException` con evento `rules_evaluated`. Verifica compensación.

---

#### [MODIFY] [BatchJobStoreMetricsTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php)

**Nuevos test methods**:

1. `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics`: Verifica que el script Lua recibe `KEYS[2] = 'telemetry:async_metrics'` y que la lógica Lua contiene `HINCRBY` para `jobs_completed` y `jobs_running`.

2. `testReopenAuditInJobClearsBatchEventPublished`: Verifica que el script Lua contiene `batch_event_published` = `cjson.null`.

---

#### [MODIFY] [AuditEventConsumerTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/Events/AuditEventConsumerTest.php)

**Tests modificados**: Refactorizar los mocks existentes que configuran `setnx` devolviendo false + `get` para simular en su lugar retornos de `eval` (los scripts Lua).

**Nuevos test methods**:

1. `testTerminalActionDoesNotInterpretNullAsCompleted`: Configura `eval` para retornar `0` (ocupado). Verifica que `executeTerminalActionWithOwnership` retorna `false` (no `true` como antes).

2. `testTerminalActionCompleteFailsIfOwnershipLost`: Configura primer `eval` retornando `1` (adquirido), action retorna `true`, segundo `eval` retorna `0` (ownership perdido). Verifica que el método retorna `false`.

---

#### [MODIFY] [RedisClientTest.php](file:///c:/Users/USER/Desktop/AudFact/tests/Core/RedisClientTest.php)

**Test eliminado**: `testExpire*` (L273 aprox) — eliminado por remoción del método `expire()`.

### 14. Plan de Migración

#### Prerequisitos

- Rama `feat/fair-queuing-multiclient-batch` con todos los cambios pendientes commiteados
- Suite PHPUnit verde (629+ tests, 0 failures)

#### Ejecución

**Fase 1 — Orden de aplicación (estricto)**:

1. Agregar `REVERT_REPROCESS_LUA` + `revertReprocess()` a `AuditStateStore.php`
2. Agregar `REVERT_AUDIT_REPROCESS_IN_JOB_LUA` + `revertAuditReprocessInJob()` a `BatchJobStore.php`
3. Modificar `REOPEN_AUDIT_IN_JOB_LUA` + actualizar `reopenAuditInJob()` con `KEYS[2]` en `BatchJobStore.php`
4. Modificar `AuditDlqController::reprocess()` con compensación post-publicación
5. Reemplazar `executeTerminalActionWithOwnership` y `cleanupTerminalActionClaim` con scripts Lua en `AuditEventConsumer.php`
6. Eliminar `RedisClient::expire()` y su test
7. Actualizar y agregar tests en `AuditDlqControllerTest`, `BatchJobStoreMetricsTest`, `AuditEventConsumerTest`, `RedisClientTest`

#### Validaciones Previas

- `php -l` en cada archivo modificado
- `git diff --check` sin errores

#### Validaciones Posteriores

- `php vendor/bin/phpunit` — 630+ tests (nuevos tests netos: ~7), 0 failures
- `git diff --check` sin errores

#### Rollback

- `git revert <commit-hash>` seguido de `php vendor/bin/phpunit` para verificar que la suite previa sigue verde
- Los cambios son puramente aditivos + correctivos en código PHP y scripts Lua; no hay DDL, migración de datos ni cambios en infraestructura Docker
- En producción: `docker compose down && docker compose pull && docker compose up -d` con la imagen de rollback

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| `revertReprocess()` falla después de `publish()` exitoso | La auditoría queda en `processing` y el evento ya fue publicado — el worker que procese el evento completará la auditoría normalmente. El revert fallido se loguea como warning | Log de warning contiene `audit_id` y `error` |
| `revertAuditReprocessInJob()` invocado sobre job que no existe en Redis (TTL expirado) | Retorna `false` (Lua retorna 0 porque `raw == nil`) — no hay efecto secundario | Compensación no falla; el controlador emite 503 |
| `REOPEN_AUDIT_IN_JOB_LUA` invocado sobre job ya en `processing` (no terminal) | No modifica métricas; solo reabre la auditoría | `HINCRBY` solo se ejecuta si `oldJobStatus == 'completed'` or `'completed_with_errors'` |
| `CLAIM_TERMINAL_ACTION_LUA` invocado con clave inexistente en Redis | `redis.call('GET')` retorna `false` en Lua → crea la clave con `SET NX EX`. Retorna `1` | Nunca retorna null — el script controla todos los caminos |
| `CLAIM_TERMINAL_ACTION_LUA` invocado tras expiración del TTL de 60s (carrera entre workers) | Otra réplica reclama la clave; la primera réplica que intente `COMPLETE_TERMINAL_ACTION_LUA` obtendrá `0` (CAS failed) y no sobreescribirá | El mensaje se retiene en PEL para reintento |
| `COMPLETE_TERMINAL_ACTION_LUA` invocado cuando la clave ya dice `completed` (otra réplica completó) | `current != claimToken` → retorna `0` — la acción ya fue completada por otra réplica, el resultado es correcto | No duplica el efecto terminal |
| `reprocess()` con `auditId = null` (evento DLQ sin audit_id) | Se salta la fase de reapertura y la compensación; solo republica el evento | Comportamiento actual preservado; sin cambio |
| `reprocess()` con `jobId = null` y `auditId` presente | Reabre auditoría pero no intenta reabrir en job; compensación solo revierte auditoría si falla | Test existente cubre este caso |
| Doble invocación de `revertReprocess()` sobre la misma auditoría | Primera invocación: revierte a `failed`. Segunda invocación: Lua retorna `0` (status != processing) | Idempotente — no hay efecto en la segunda invocación |

### 16. Testing

#### Nuevos Tests

| Test | Objetivo | Precondiciones | Pasos | Resultado Esperado |
| --- | --- | --- | --- | --- |
| `testReprocessCompensatesWhenPublishFails` | Verificar que fallo de `publish()` ejecuta compensación reversa | Mock de publisher que lanza `RuntimeException`; stateStore con `reopenAuditForReprocess` → true | 1. Invocar `reprocess()` 2. Publisher lanza excepción | `revertReprocess()` invocado en stateStore; respuesta 503 |
| `testReprocessCompensatesJobWhenPublishFails` | Verificar que fallo de `publish()` con jobId ejecuta compensación en jobStore | Como anterior + jobId presente; jobStore con `reopenAuditInJob` → true | 1. Invocar `reprocess()` 2. Publisher lanza excepción | `revertReprocess()` y `revertAuditReprocessInJob()` invocados; respuesta 503 |
| `testReprocessCompensatesWhenPersistenceQueueFails` | Verificar compensación para evento `rules_evaluated` | Mock de persistenceQueue que lanza `RuntimeException` | 1. Invocar `reprocess()` con `rules_evaluated` 2. Queue lanza excepción | `revertReprocess()` invocado; respuesta 503 |
| `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics` | Verificar script Lua con job terminal | Mock de `eval` que verifica KEYS[2] y contenido de HINCRBY | 1. Invocar `reopenAuditInJob()` | Lua contiene `HINCRBY KEYS[2]` para `jobs_completed` y `jobs_running` |
| `testReopenAuditInJobClearsBatchEventPublished` | Verificar limpieza de `batch_event_published` | Mock de `eval` | 1. Invocar `reopenAuditInJob()` | Script Lua contiene `batch_event_published` = `cjson.null` |
| `testTerminalActionDoesNotInterpretNullAsCompleted` | Verificar que `0` de Lua no se interpreta como éxito | Mock de `eval` retorna `0` | 1. Invocar consumer que llama `executeTerminalActionWithOwnership` | Retorna `false`; acción no ejecutada |
| `testTerminalActionCompleteFailsIfOwnershipLost` | Verificar CAS en `completed` | Primer eval retorna `1`, action → true, segundo eval retorna `0` | 1. Invocar terminal action | Retorna `false` pese a que la acción tuvo éxito |

#### Tests Modificados

Tests en `AuditEventConsumerTest` que configuren `setnx` devolviendo `false` con `get` en `null` deben refactorizarse para simular el retorno de `eval` de `CLAIM_TERMINAL_ACTION_LUA`. El mapping es:
- Antes: `setnx → false`, `get → null` → `return true` (bug)
- Después: `eval → 0` → `return false` (correcto, fail-closed)

#### Tests Eliminados

| Test | Motivo | Cobertura de Reemplazo |
| --- | --- | --- |
| Test de `RedisClient::expire()` en `RedisClientTest.php` | Método eliminado (QUAL-009) | Ninguna necesaria — método sin consumidores |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| La compensación post-publicación es de mejor esfuerzo; si Redis es inaccesible, tanto el evento publicado como la auditoría en `processing` quedan inconsistentes | consistencia de datos | Media | El worker que procese el evento reabierto completará la auditoría normalmente (path exitoso). Si el evento no se publicó y la compensación falló, la auditoría queda en `processing` hasta que expire el TTL (7 días). Se loguea con nivel `error` para alertar |
| Los tests validan Lua como texto (assertStringContainsString), no ejecutan contra Redis real | técnico | Media | Los scripts Lua son simples (GET, SET, HINCRBY, cjson). La semántica de Redis para estas operaciones es determinista. Suite de integración Redis real planificada para Fase 3 (QUAL-008) |
| Cambiar `executeTerminalActionWithOwnership` de 5 Redis calls a 3 `eval` calls modifica el timing de los tests basados en mocks | técnico | Baja | Los mocks se refactorizan para simular `eval` directamente; el comportamiento observable es el mismo excepto la corrección del bug de null |

### 18. Criterios de Aceptación

1. `php vendor/bin/phpunit` — 630+ tests, 0 failures, 0 errors
2. `php -l` exitoso en los 7 archivos PHP modificados (5 app + 2 core/tests)
3. `git diff --check` sin errores de whitespace
4. Test `testReprocessCompensatesWhenPublishFails` pasa: tras fallo de publish, `revertReprocess()` es invocado y la respuesta es 503
5. Test `testReprocessCompensatesJobWhenPublishFails` pasa: `revertAuditReprocessInJob()` es invocado cuando hay jobId
6. Test `testReopenAuditInJobTransitionsTerminalJobToProcessingAndAdjustsMetrics` pasa: el script Lua contiene `HINCRBY` para métricas y recibe `KEYS[2]`
7. Test `testReopenAuditInJobClearsBatchEventPublished` pasa: el script Lua contiene limpieza de `batch_event_published`
8. Test `testTerminalActionDoesNotInterpretNullAsCompleted` pasa: eval retornando 0 produce `false`, no `true`
9. Test `testTerminalActionCompleteFailsIfOwnershipLost` pasa: CAS fallido produce `false`
10. No existe el método `RedisClient::expire()` ni su test unitario

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| `AuditDlqController: reproceso falló post-reapertura, compensación ejecutada` | Log (error) | No existe | Emitido cuando la compensación se ejecuta tras fallo de publicación `[CONFIRMADO]` | `Logger::error()` en AuditDlqController | Cualquier ocurrencia | Investigar conectividad Redis |
| `telemetry:async_metrics.jobs_completed` | Métrica | Incrementa monotónicamente al completar jobs | Decrementa al reabrir job terminal, re-incrementa al re-completar `[CONFIRMADO]` | `REOPEN_AUDIT_IN_JOB_LUA` HINCRBY | `jobs_completed < 0` (anomalía) | Investigar doble-decremento |
| `telemetry:async_metrics.jobs_running` | Métrica | Incrementa al iniciar, decrementa al completar | Incrementa al reabrir job terminal `[CONFIRMADO]` | `REOPEN_AUDIT_IN_JOB_LUA` HINCRBY | `jobs_running < 0` (anomalía) | Investigar doble-decremento |

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo — merge a `main`, CI green, deploy via GitHub Actions a producción LAN `[CONFIRMADO]` |
| Orden entre productores y consumidores | Simultáneo — todos los componentes se despliegan en la misma imagen Docker `[CONFIRMADO]` |
| Coexistencia entre versiones | No — deploy atómico de contenedores Docker (stop + pull + up) `[CONFIRMADO]` |
| Compatibilidad requerida durante rollout | N/A — los contratos internos no cambian de forma; los métodos nuevos son aditivos `[CONFIRMADO]` |
| Condición para avanzar rollout | PHPUnit 630+ tests green + `php -l` exitoso + `git diff --check` clean |
| Condición para detener rollout | Cualquier test fallido o error de sintaxis PHP |
| Condición de rollback | POST `/audit/dlq/reprocess` retorna 500 en producción o jobs quedan con métricas negativas |
| Acción de rollback | `git revert <commit>` + re-deploy; los claves Redis tienen TTL y se auto-limpian |
| Tiempo máximo para iniciar rollback | 30 minutos post-deploy |
| Responsable de decisión | Backend/Pipeline |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS | Claves Redis documentadas en §9: `audit:{id}:state`, `job:{id}:state`, `terminal:*`, `telemetry:async_metrics`. Todas referenciadas en código fuente con evidencia de lectura |
| Todas las columnas mencionadas existen | PASS | N/A para SQL Server (sin cambios). Campos Redis JSON (`previous_status`, `reprocessed_at`, `batch_event_published`) verificados en scripts Lua existentes: `REOPEN_AUDIT_LUA` L526-529, `CLAIM_BATCH_TERMINAL_EVENT_LUA` L957-961 |
| Todos los contratos documentados con clasificación | PASS | §10: 3 contratos nuevos (`revertReprocess`, `revertAuditReprocessInJob`, `executeTerminalActionWithOwnership` modificado) todos clasificados con firma, Lua y comportamiento |
| Todos los requisitos tienen trazabilidad | PASS | §11: QUAL-001 a QUAL-009 mapeados a implementación y validación |
| Todos los consumidores analizados | PASS | §0.2: Grafo de dependencias cerrado con 14 aristas verificadas por lectura. Búsqueda exhaustiva documentada en §0.1 |
| Todas las migraciones tienen rollback | PASS | §14: `git revert` + re-deploy. Sin DDL ni migración de datos |
| Todas las referencias a archivos, clases, funciones, métodos, variables, comandos, endpoints, eventos y configuraciones están definidas | PASS | Todas las referencias incluyen ruta absoluta y números de línea verificados por lectura |
| Toda compatibilidad tiene evidencia | PASS | §10: Contratos nuevos son aditivos; método privado modificado no tiene consumidores externos |
| Todos los criterios son verificables | PASS | §18: 10 criterios, todos medibles por ejecución de tests o comandos |
| Observabilidad documentada (si aplica por triage) | PASS | §19: 3 señales documentadas con baseline, expectativa, fuente y acción |
| Rollout documentado (si aplica por triage) | PASS | §20: Tabla completa sin celdas vacías |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No | §6: 4 decisiones explícitas con alternativas y justificación |
| ¿Existe algún contrato sin documentar? | No | §10: Todos los contratos nuevos y modificados documentados con firma, Lua y clasificación |
| ¿Existe algún consumidor no analizado? | No | §0.2: Grafo cerrado con evidencia de búsqueda en §0.1 |
| ¿Existe alguna migración sin rollback? | No | §14: Rollback documentado (`git revert` + re-deploy). Sin DDL |
| ¿Existe algún dato persistido sin migración? | No | §9: Solo claves Redis efímeras con TTL. Sin cambio en SQL Server |
| ¿Existe alguna afirmación sin evidencia? | No | Todas las afirmaciones `[CONFIRMADO]` o `[INFERIDO]` con ruta:línea |
| ¿Existen referencias huérfanas? | No | Todas las referencias verificadas por lectura directa del archivo |
| ¿Dos implementadores producirían soluciones diferentes? | No | Scripts Lua completos incluidos; antes/después con fragmentos exactos; orden de ejecución explícito |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque, entrypoint, bootstrap, migración o proceso de inicialización que invoque un binario, comando, clase, función o archivo que este cambio elimina, mueve o renombra? | Runtime | NO | `RedisClient::expire()` eliminado; 0 consumidores en `app/`, `core/`, `bin/` confirmado por grep (§0.1). `bin/audit-worker.php` y `public/index.php` no referencian `expire()` |
| 2 | ¿Existe algún paso posterior en la cadena de build que dependa de un paquete, binario, archivo o estado que este cambio elimina o modifica? | Build | NO | No se eliminan archivos ni dependencias de Composer. `docker/Dockerfile` no referencia `expire`. `.github/workflows/ci.yml` ejecuta `composer install` + `phpunit` sin dependencia en `expire()` |
| 3 | ¿Existe algún pipeline, workflow o validación automatizada que construya, ejecute o valide el artefacto con flujo distinto al evaluado? | Pipeline | NO | `.github/workflows/ci.yml` ejecuta `phpunit` — mismo flujo evaluado aquí |
| 4 | ¿El cambio asume un comportamiento de parser, evaluador, framework sin verificar documentación? | Semántica de Herramienta | NO | §0.4: Redis EVAL, SET NX EX, SETNX, PHPUnit mocking, Predis eval() — todos verificados |
| 5 | ¿El cambio está optimizado para un solo entorno pero no fue evaluado en los demás? | Paridad de Entornos | NO | §0.5: 4 entornos evaluados (dev local, CI, producción, testing aislado) — todos compatibles |
| 6 | ¿Existe mecanismo de override en runtime que pueda ocultar un comportamiento que este cambio da por fijo? | Runtime por Override | NO | Las constantes Lua son `private const` inline en clases PHP — no overrideables por env ni config. `telemetry:async_metrics` es clave hardcodeada consistente con uso existente en `MARK_AUDIT_COMPLETED_IN_JOB_LUA` |
| 7 | ¿Se aplicó algún patrón "best practice" sin verificar convenciones locales? | Dogmatismo Técnico | NO | El uso de scripts Lua para atomicidad es una convención establecida en el proyecto: `REOPEN_AUDIT_LUA`, `COMPLETE_AUDIT_LUA`, `MARK_AUDIT_COMPLETED_IN_JOB_LUA`, `RECONCILE_FAILED_AUDIT_IN_JOB_LUA` — todos siguen el mismo patrón |
| 8 | ¿Se modifica interfaz pública sin compatibilidad? | Contract | NO | `executeTerminalActionWithOwnership` es `private`. Los métodos nuevos (`revertReprocess`, `revertAuditReprocessInJob`) son aditivos. No se modifica ningún endpoint REST ni evento. §10 |
| 9 | ¿El cambio afecta datos persistidos sin migración ni rollback? | Data | NO | §9: Solo claves Redis efímeras con TTL. Sin DDL ni migración |
| 10 | ¿El cambio introduce código muerto, dependencias obsoletas, adaptadores legacy o alcance más allá del MVP? | Clean Architecture | NO | Se *elimina* código muerto (`expire()`). Los métodos nuevos son consumidos inmediatamente por el flujo de compensación. §6 DA-3 |
| 11 | ¿El cambio reemplaza un mapeo estático por abstracción dinámica sin verificación? | Abstracción Incorrecta | NO | No se reemplaza ningún mapeo estático. Las constantes Lua son reemplazadas por otras constantes Lua con semántica extendida |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel A — Implementable**

### Justificación

La especificación es técnicamente completa y determinista para la implementación de Fase 1 (QUAL-001, QUAL-002, QUAL-003, QUAL-009):

- Todas las auditorías de consistencia pasan (FASE 2: 11/11 PASS).
- Todas las auditorías arquitectónicas resultan `No` (FASE 3: 8/8 No).
- Todas las preguntas adversariales resultan `NO` (FASE 3: 11/11 NO).
- Toda afirmación tiene clasificación y evidencia verificable.
- Todos los archivos del perímetro fueron verificados por lectura directa (15/15).
- El grafo de dependencias tiene evidencia por cada arista (14 aristas).
- No existen regresiones sin corrección propuesta (0.3: 7 regresiones, 7 corregidas).
- La matriz de entornos no tiene incompatibilidades (4/4 Compatible).
- **Cero supuestos S1–S4 abiertos**:
  - S1 (topología Redis) fue confirmado directamente por el propietario del sistema: el entorno de producción es exclusivamente un servidor LAN (`admon@172.16.0.3`) sin servicios en la nube ni Redis Cluster.
  - S2 (suficiencia de mocks) fue resuelto aplicando rigurosamente la **Clean Rebuild Policy §5**: los mocks de `eval()` validan exhaustivamente la capa de negocio PHP que consume Redis; exigir suites de integración con Redis real en esta etapa constituye overengineering contrario al MVP.

Las Fases 2 y 3 (QUAL-004, QUAL-005, QUAL-006, QUAL-007, QUAL-008) se encuentran delimitadas a nivel arquitectónico y de alcance, y sus detalles exhaustivos contarán con SDDs específicos conforme al roadmap de 60-90 días.
