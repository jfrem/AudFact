# SDD — Métricas activas de jobs asíncronos sin drift

> [CONFIRMADO] Fecha: 2026-08-12  
> [CONFIRMADO] Estado: listo para implementación  
> [CONFIRMADO] Política: `clean-rebuild-policy`  
> [CONFIRMADO] Alcance: Redis, pipeline batch, observabilidad, frontend, pruebas y despliegue

## FASE 0 — Descubrimiento Obligatorio

### Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Causa raíz del job fantasma | [CONFIRMADO] | `BatchJobStore::initJob()` crea `job:{jobId}:state` con `AUDIT_JOB_TTL` y después incrementa `telemetry:async_metrics.jobs_queued` en operaciones separadas (`app/Services/Audit/Pipeline/BatchJobStore.php:62-101`). |
| Pérdida de la transición terminal | [CONFIRMADO] | El Lua de `markAuditCompletedInJob()` retorna `0` cuando la llave de estado ya no existe y, por tanto, no decrementa `jobs_queued` ni `jobs_running` (`app/Services/Audit/Pipeline/BatchJobStore.php:380-464`). |
| Persistencia del contador corrupto | [CONFIRMADO] | `RedisClient::hIncrBy()` ejecuta `HINCRBY` sin expiración y el hash `telemetry:async_metrics` se lee directamente en `/metrics/async` (`core/RedisClient.php:262-270`; `app/Controllers/ObservabilityController.php:61-70`). |
| Expiración del estado del job | [CONFIRMADO] | `AUDIT_JOB_TTL=604800` y cada escritura del estado renueva ese TTL (`.env.example:108`; `app/Services/Audit/Pipeline/BatchJobStore.php:33-35, 464`). |
| Política de memoria Redis | [CONFIRMADO] | Redis 7 usa `volatile-lru`; una llave de estado con TTL puede desaparecer antes de su expiración nominal bajo presión de memoria (`docker-compose.yml:59-75`; `.env.example:86-94`). |
| Productores de `batch_requested` | [CONFIRMADO] | `AuditController::async()` y `bin/schedule-daily-batches.php` repiten el flujo reclamar idempotencia → crear estado → publicar evento (`app/Controllers/AuditController.php:356-397`; `bin/schedule-daily-batches.php:137-190`). |
| Consumidor de `batch_requested` | [CONFIRMADO] | `BatchRequestedWorker` exige `job_id` y llama al orquestador con ese identificador (`app/Services/Audit/Pipeline/BatchRequestedWorker.php:84-124`). |
| Rama de compatibilidad interna | [CONFIRMADO] | `AuditBatchOrchestrator::enqueueBatch()` acepta `?string $jobId = null`, genera un job cuando falta y documenta esa ruta como `backward compat` (`app/Services/Audit/AuditBatchOrchestrator.php:32-47`). |
| Cierre de lote vacío | [CONFIRMADO] | `publishEmptyBatch()` usa el patch genérico para asignar `completed`; esa mutación no actualiza las métricas activas (`app/Services/Audit/AuditBatchOrchestrator.php:293-315`). |
| Limpieza de job | [CONFIRMADO] | `deleteJob()` elimina únicamente el estado y deja intacto el contador activo (`app/Services/Audit/Pipeline/BatchJobStore.php:147-149`). |
| Contrato de `/metrics/async` | [CONFIRMADO] | El endpoint devuelve `jobs.queued`, `jobs.running`, `jobs.completed` y `jobs.failed`; el esquema Zod exige esos campos (`app/Controllers/ObservabilityController.php:66-85`; `frontend/lib/schemas/domain.ts:63-83`). |
| Semántica de `queueDepth` | [CONFIRMADO] | El valor es la suma de `XPENDING` por consumer group, es decir, eventos entregados y no confirmados; no es un conteo de jobs (`app/Controllers/ObservabilityController.php:30-55`). |
| Desajuste visual | [CONFIRMADO] | Dos componentes suman `queueDepth + jobs.running + jobs.queued`, mezclando eventos en vuelo con jobs activos (`frontend/components/dashboard/async-queue-summary.tsx:38-49`; `frontend/app/(dashboard)/dashboard/page.tsx:59-64`). |
| Históricos terminales | [CONFIRMADO] | `completed_with_errors` incrementa `jobs_completed`; `jobs_completed` y `jobs_failed` son históricos monotónicos y no representan actividad actual (`app/Services/Audit/Pipeline/BatchJobStore.php:446-460`). |
| Runtime Redis | [CONFIRMADO] | El despliegue fuente de verdad usa una instancia Redis standalone; `RedisClient::eval()` antepone el prefijo a cada `KEYS[]` y soporta Lua multillave (`docker-compose.yml:59-75`; `.env.example:88,93`; `core/RedisClient.php:423-443`). |
| Cobertura actual | [CONFIRMADO] | `BatchJobStoreMetricsTest` inspecciona texto Lua sin Redis real y `ObservabilityControllerTest` no valida conteos activos (`tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php:11-68`; `tests/Controllers/ObservabilityControllerTest.php:13-60`). |
| Baseline | [CONFIRMADO] | En la verificación previa de esta solicitud finalizaron 412 tests PHPUnit con 1437 aserciones y 1 omitido; `frontend` typecheck finalizó sin errores; el worktree estaba limpio. |

### Información Faltante Crítica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [CONFIRMADO] Ninguno | El contrato, los productores, el consumidor, las llaves Redis, el TTL, el runtime y los puntos de transición están identificados en código. | La implementación puede ejecutarse sin una decisión funcional adicional. |

### Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [DESCONOCIDO] Mecanismo concreto que invoca el cron en el host productivo | El repositorio documenta un ejemplo de `crontab`, pero la programación del host no forma parte de Zero-Source (`README.md:267-282`). | No cambia el código. El despliegue queda bloqueado hasta que Operaciones registre el scheduler real y confirme su pausa durante la ventana. |

### Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| [DESCONOCIDO] Pico histórico de jobs activos simultáneos | No existe una serie temporal versionada de esta métrica. | No afecta la corrección; la consulta recorre únicamente miembros de dos índices activos, nunca el histórico de jobs. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| [CONFIRMADO] S-000 | No se usan supuestos para definir el comportamiento de implementación. | Las decisiones normativas quedan fijadas en las secciones 5, 6, 8, 10 y 13. | Ninguno. |

### Clasificación de Completitud Inicial

[CONFIRMADO] `Nivel A — Implementable`. No existe una dependencia crítica desconocida. El único dato operativo desconocido tiene un gate explícito y no altera el diseño ni los contratos.

## FASE 1 — Especificación

### 1. Objetivo

- [CONFIRMADO] El problema actual es un valor positivo permanente en `jobs.queued` o `jobs.running` aunque no exista un job ejecutándose.
- [CONFIRMADO] La causa raíz es el doble almacenamiento de un mismo hecho: el estado activo vive en una llave con TTL y también en un contador acumulado sin TTL. La expiración o evicción del estado omite la transición que compensa el contador.
- [CONFIRMADO] El impacto es observabilidad falsa, alertas incorrectas y una suma visual que mezcla unidades distintas.
- [CONFIRMADO] El resultado objetivo es derivar `queued` y `running` de índices de membresía activos, autocurables y acoplados atómicamente a la vida del estado del job.
- [CONFIRMADO] El cambio existe para eliminar la categoría arquitectónica de drift, no para volver a ejecutar una limpieza manual periódica.

### 2. Alcance

#### Incluido

- [CONFIRMADO] Sustituir `telemetry:async_metrics.jobs_queued` y `jobs_running` por dos ZSET Redis de actividad.
- [CONFIRMADO] Unificar la idempotencia, el estado inicial, el índice queued y `XADD batch_requested` en una operación Lua de envío.
- [CONFIRMADO] Convertir todas las transiciones activas de job en operaciones Lua que actualizan estado e índices en la misma ejecución.
- [CONFIRMADO] Eliminar las APIs internas y la rama de compatibilidad que permiten crear jobs fuera del flujo único.
- [CONFIRMADO] Conservar el JSON público de `GET /metrics/async` y la respuesta HTTP de `POST /audit/async`.
- [CONFIRMADO] Separar en la UI los eventos en vuelo de los jobs activos.
- [CONFIRMADO] Incorporar pruebas de integración opt-in con Redis 7 y ejecutarlas obligatoriamente en CI.
- [CONFIRMADO] Ejecutar una migración no rolling con drenaje, validación y rollback.
- [CONFIRMADO] Sincronizar documentación y skills afectadas después de implementar el código.

#### Excluido

- [CONFIRMADO] Cambios en tablas, vistas, queries o datos de SQL Server.
- [CONFIRMADO] Cambios en los payloads de eventos `batch_requested`, `batch_created`, `batch_completed` o `batch_completed_with_errors`.
- [CONFIRMADO] Cambios en los estados públicos del job.
- [CONFIRMADO] Reconciliación mediante `SCAN` en cada request.
- [CONFIRMADO] Migración de Redis standalone a Redis Cluster.
- [CONFIRMADO] Cambio de RDB a AOF.
- [CONFIRMADO] Rediseño de reintentos parciales de `AuditBatchOrchestrator`.
- [CONFIRMADO] Rediseño del claim previo a la publicación del evento terminal del batch.
- [CONFIRMADO] Creación de un estado terminal nuevo para `batch_requested` que llega a DLQ.

### 3. Non Goals

- [CONFIRMADO] No se agregará un reconciliador periódico, cron de reparación, adaptador de compatibilidad ni contador sombra.
- [CONFIRMADO] No se conservarán `jobs_queued` o `jobs_running` como fallback después del corte.
- [CONFIRMADO] No se introducirá una abstracción genérica de métricas para casos futuros.
- [CONFIRMADO] No se modificará la semántica histórica: `completed_with_errors` seguirá sumando en `jobs.completed`.
- [CONFIRMADO] No se redefinirá `queueDepth`; se documentará y etiquetará como eventos entregados sin ACK.
- [CONFIRMADO] No se garantizará durabilidad superior a la persistencia RDB existente.

### 4. Estado Actual

#### Arquitectura y flujo

[CONFIRMADO] El alta actual contiene cuatro escrituras independientes:

```text
AuditController / cron
  -> SETNX batch:idem:{key}
  -> SETNX job:{jobId}:state EX AUDIT_JOB_TTL
  -> HINCRBY telemetry:async_metrics jobs_queued 1
  -> XADD audit.batch.inbox
```

[CONFIRMADO] La transición terminal usa el estado previo para compensar contadores; si `GET job:{jobId}:state` no encuentra la llave, retorna antes de cualquier compensación.

```text
job state con TTL --expiración/evicción--> inexistente
telemetry hash sin TTL -----------------> jobs_running permanece en 1
```

#### Dependencias involucradas

- [CONFIRMADO] `AuditController` y el cron construyen el mismo evento mediante código duplicado.
- [CONFIRMADO] `BatchRequestedWorker` consume `audit.batch.inbox` y entrega un `jobId` ya creado al orquestador.
- [CONFIRMADO] `AuditBatchOrchestrator` conserva una ruta alternativa que crea el job cuando no recibe `jobId`; las únicas llamadas sin ID están en pruebas.
- [CONFIRMADO] `ObservabilityController` mezcla índices de streams, longitud de DLQ y el hash de telemetría en una respuesta.
- [CONFIRMADO] `RedisClient::eval()` permite resolver la consistencia de estado, índices y stream dentro de una ejecución Lua.

#### Limitaciones conocidas

- [CONFIRMADO] La corrección manual `HSET jobs_queued 0 jobs_running 0` documentada en `plans/changelog.md:12-19` repara una instantánea, pero no elimina la causa.
- [CONFIRMADO] `deleteJob()` y el cierre del lote vacío omiten las métricas activas.
- [CONFIRMADO] Redis usa RDB `--save 60 1000`; un fallo del proceso Redis conserva una ventana de pérdida de datos ya existente.
- [CONFIRMADO] El orquestador evita rollback destructivo después de publicar el primer evento de auditoría; cambiar esa política requiere idempotencia de publicación parcial (`app/Services/Audit/AuditBatchOrchestrator.php:163-176`).
- [CONFIRMADO] Los eventos sin `auditId`, incluido `batch_requested`, no cierran un job al llegar a DLQ (`app/Services/Audit/Pipeline/AuditEventConsumer.php:464-468`).

### 5. Estado Objetivo

#### Llaves y propiedad

- [CONFIRMADO] `BatchJobStore::ACTIVE_QUEUED_KEY` tendrá el valor lógico `jobs:active:queued`.
- [CONFIRMADO] `BatchJobStore::ACTIVE_RUNNING_KEY` tendrá el valor lógico `jobs:active:running`.
- [CONFIRMADO] Cada llave será un ZSET sin TTL.
- [CONFIRMADO] Cada miembro será `KEYS[1]`, la llave física completa que `RedisClient::eval()` entrega al Lua; con el prefijo por defecto adopta la forma `audfact:job:{uuid}:state`.
- [CONFIRMADO] Cada score será `Redis TIME` en milisegundos más `AUDIT_JOB_TTL * 1000`.
- [CONFIRMADO] El estado JSON `job:{jobId}:state` conserva su estructura y TTL actuales.
- [CONFIRMADO] `telemetry:async_metrics` conserva `jobs_completed`, `jobs_failed`, `retries`, `terminal_failures` y las métricas Gemini existentes; deja de contener los dos campos activos.

#### Flujo de envío

```text
AuditController / cron
  -> crea AuditEvent(batch_requested)
  -> BatchJobStore::submitJob(event, idempotencyKey, ttl)
       -> EVAL atómico:
          idempotencia + estado EX + ZADD queued + XADD batch stream
  -> created=true: 202 / conteo cron queued
  -> created=false: 409 / conteo cron duplicate
```

- [CONFIRMADO] `submitJob()` validará antes del Lua: tipo `batch_requested`, `jobId` no vacío, llave de idempotencia no vacía, TTL de idempotencia positivo y payload con `fac_nit_sec >= 1`, fechas no vacías y `limit >= 1`.
- [CONFIRMADO] El Lua validará con `TYPE` que job e idempotencia sean `none|string`, el índice sea `none|zset` y el stream sea `none|stream` antes de la primera escritura del nuevo job.
- [CONFIRMADO] Si la llave de idempotencia existe, el Lua retornará el primer `job_id` sin crear estado, miembro ni evento.
- [CONFIRMADO] Si el UUID propuesto ya existe sin esa idempotencia, `submitJob()` lanzará `RuntimeException` y no escribirá el nuevo evento.
- [CONFIRMADO] El Lua escribirá state, queued e idempotencia antes de ejecutar `XADD` como última escritura. Usará `redis.pcall()` y, si `ZADD`, `SET` de idempotencia o `XADD` retornan error, revertirá state, miembro e idempotencia del nuevo job antes de retornar el código de error.
- [CONFIRMADO] La poda `ZREMRANGEBYSCORE` ejecutada antes del alta es una limpieza idempotente y no forma parte del rollback del nuevo job.

#### Contrato normativo de scripts Lua

| Operación | `KEYS[]` en orden | `ARGV[]` en orden | Retorno |
| --- | --- | --- | --- |
| [CONFIRMADO] `submitJob()` | `1=job state`, `2=batch idempotency`, `3=active queued`, `4=batch stream` | `1=state JSON`, `2=event JSON`, `3=jobId`, `4=job TTL s`, `5=idempotency TTL s`, `6=stream maxlen; 0=sin trim` | `[1,jobId,streamId]` creado; `[0,existingJobId,""]` duplicado; `[-1,"JOB_EXISTS",""]`, `[-2,"WRONGTYPE",""]` o `[-3,"WRITE_FAILED",""]` provocan `RuntimeException`. |
| [CONFIRMADO] `registerAuditInJob()` | `1=job state`, `2=active queued` | argumentos actuales, `now ISO`, `job TTL s` | `1` actualizado; `0` state ausente. |
| [CONFIRMADO] `sealJob()` | `1=job state`, `2=active queued` | `1=metadata JSON`, `2=total`, `3=now ISO`, `4=job TTL s` | `1` sellado; `0` state ausente. |
| [CONFIRMADO] `markAuditCompletedInJob()` | `1=job state`, `2=telemetry hash`, `3=active queued`, `4=active running` | `auditId`, `audit status`, `now ISO`, `job TTL s`, `duration ms`, `failed stage` | `1` transición o repetición idempotente; `0` state/audit ausente después de limpiar índices. |
| [CONFIRMADO] `completeEmptyJob()` | `1=job state`, `2=telemetry hash`, `3=active queued`, `4=active running` | `1=skipped locked`, `2=skipped existing`, `3=now ISO`, `4=job TTL s` | `1` cierre o repetición idempotente; `0` state ausente después de limpiar índices. |
| [CONFIRMADO] `cancelJob()` | `1=job state`, `2=active queued`, `3=active running` | ninguno | `1` si eliminó state o membresía; `0` si no existía nada. |
| [CONFIRMADO] `getActiveJobCounts()` | `1=active queued`, `2=active running` | ninguno | `[queuedCount,runningCount]` como enteros no negativos. |

- [CONFIRMADO] Todos los scripts validarán tipos de todas sus `KEYS[]` antes de mutar y usarán `KEYS[1]` como miembro físico del job.
- [CONFIRMADO] Todos los scripts que creen o renueven una membresía calcularán `nowMs = seconds*1000 + floor(microseconds/1000)` desde `redis.call('TIME')` y `expiresAtMs = nowMs + jobTtlSeconds*1000`.
- [CONFIRMADO] `submitJob()` construirá el state JSON en PHP con exactamente los campos actuales de `initJob()` y el evento mediante `AuditEvent::toJson()`.
- [CONFIRMADO] `submitJob()` convertirá el array Redis de éxito al contrato PHP `{created, job_id, stream_id}`; ningún código de error se presentará como duplicado.
- [CONFIRMADO] `AuditEventPublisher::configuredStreamMaxLen()` retornará `100000` ante valor ausente/no numérico, el entero positivo configurado o `null` ante valor menor o igual a cero; constructor y `submitJob()` usarán ese método.

#### Flujo de transición

- [CONFIRMADO] `registerAuditInJob()` y `sealJob()` renovarán el score de queued cuando el estado permanezca `pending`.
- [CONFIRMADO] La primera auditoría terminal moverá el miembro de queued a running si el job pasa a `processing`.
- [CONFIRMADO] La terminación del job eliminará el miembro de ambos índices y aumentará una sola vez `jobs_completed` o `jobs_failed` según las reglas existentes.
- [CONFIRMADO] `completed_with_errors` eliminará actividad y aumentará `jobs_completed`.
- [CONFIRMADO] `completeEmptyJob()` reemplazará el patch genérico, cerrará el estado, retirará la actividad y aumentará `jobs_completed` una sola vez.
- [CONFIRMADO] `cancelJob()` reemplazará `deleteJob()`, eliminará estado y membresía activa en una ejecución Lua.
- [CONFIRMADO] Si una transición encuentra el estado ausente, retirará el miembro de ambos índices, retornará `false` y registrará un warning sin datos sensibles.

#### Lectura autocurable

- [CONFIRMADO] `getActiveJobCounts()` ejecutará un único Lua sobre ambos ZSET.
- [CONFIRMADO] El Lua eliminará scores vencidos con `ZREMRANGEBYSCORE`, recorrerá únicamente miembros restantes con `ZRANGE`, eliminará miembros cuyo estado físico no exista y retornará ambos `ZCARD`.
- [CONFIRMADO] El endpoint no ejecutará `SCAN`, no leerá estados históricos y no usará el hash como fallback.

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| [CONFIRMADO] ADR-001 | Representar actividad con dos ZSET autocurables. | Contadores `HINCRBY`; `SCAN job:*`; reconciliador periódico. | El ZSET expresa membresía, permite expiración por score y elimina decrementos perdidos. |
| [CONFIRMADO] ADR-002 | Mantener `completed` y `failed` en el hash histórico. | Recalcular el histórico desde jobs expirables; cambiar la respuesta pública. | Los jobs expiran y no pueden reconstruir un histórico; el contrato actual permanece estable. |
| [CONFIRMADO] ADR-003 | Ejecutar el alta completa en `BatchJobStore::submitJob()`. | Mantener idempotencia, init y publish separados; crear un servicio genérico de transacciones Redis. | La unidad atómica pertenece al agregado batch y el MVP no requiere una abstracción adicional. |
| [CONFIRMADO] ADR-004 | Exponer `AuditEventPublisher::configuredStreamMaxLen()` como única fuente del límite. | Duplicar parsing de `AUDIT_STREAM_MAXLEN`; omitir MAXLEN en `submitJob()`. | Evita divergencia entre el envío atómico y el resto de publicaciones. |
| [CONFIRMADO] ADR-005 | Usar la llave física del estado como miembro ZSET. | Guardar solo UUID; construir prefijo dentro de Lua. | Permite `EXISTS member` sin duplicar conocimiento de `REDIS_PREFIX`. |
| [CONFIRMADO] ADR-006 | Usar tiempo Redis en milisegundos para scores. | Tiempo PHP; score secuencial; TTL en el ZSET completo. | Evita skew entre réplicas PHP y alinea score con el servidor que aplica el TTL. |
| [CONFIRMADO] ADR-007 | Eliminar las APIs internas legacy en el mismo cambio. | Wrappers deprecados; doble ruta temporal. | La política clean rebuild prohíbe conservar rutas sin consumidor MVP. |
| [CONFIRMADO] ADR-008 | Hacer un despliegue no rolling después de drenar. | Compatibilidad dual old/new; backfill en caliente. | Una versión antigua no conoce los ZSET y una nueva no usa los contadores activos. |
| [CONFIRMADO] ADR-009 | Conservar el contrato JSON y corregir solo su fuente. | Renombrar campos públicos; publicar `/metrics/async/v2`. | El problema es de integridad interna, no del contrato público. |
| [CONFIRMADO] ADR-010 | Separar en UI eventos en vuelo y jobs activos. | Mantener una suma de unidades diferentes. | `XPENDING` cuenta eventos entregados sin ACK y los ZSET cuentan jobs. |
| [CONFIRMADO] ADR-011 | Validar con Redis 7 real en CI. | Inspección de strings Lua; mocks exclusivamente. | TTL, `TIME`, tipos Redis, idempotencia y atomicidad no quedan probados por mocks. |
| [CONFIRMADO] ADR-012 | Mantener standalone como límite de arquitectura. | Redis Cluster en este cambio. | El runtime confirmado es standalone y los scripts multillave actuales ya dependen de ese modo. |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| [CONFIRMADO] PHP | runtime | 8.2 | Aloja controladores, CLI, stores, workers y PHPUnit. |
| [CONFIRMADO] Redis | servicio / cola / persistencia | `redis:7-alpine` | Ejecuta Lua, ZSET, Streams, TTL y `TIME`. |
| [CONFIRMADO] `Core\RedisClient` | infraestructura interna | repositorio actual | Antepone prefijos a `KEYS[]` y ejecuta `EVAL`; no requiere cambio. |
| [CONFIRMADO] `AuditEventPublisher` | integración interna | repositorio actual | Conserva mapping evento→stream y centraliza `AUDIT_STREAM_MAXLEN`. |
| [CONFIRMADO] Nginx/PHP-FPM | infraestructura | Nginx 1.25 / PHP 8.2 | Deben entrar en ventana de mantenimiento para impedir escritura mixta. |
| [CONFIRMADO] Next.js/React/Zod | frontend | Next 15.x / React 19 / Zod 3.x | El esquema público permanece; cambian cálculos y etiquetas. |
| [CONFIRMADO] PHPUnit | testing | configuración Composer actual | Incorpora integración Redis opt-in ejecutada obligatoriamente en CI. |
| [CONFIRMADO] GitHub Actions | CI | `ubuntu-latest` | Levanta Redis 7 como service container. |
| [CONFIRMADO] SQL Server | base de datos | conexión actual `sqlsrv` | Sin lectura, escritura, migración ni DDL para este cambio. |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| [CONFIRMADO] La respuesta de `/metrics/async` conserva todos sus campos y tipos. | Controller y `AsyncMetricsSchema` sin renombres. | Test de controller y typecheck. |
| [CONFIRMADO] Un job activo pertenece a un solo índice. | Cada Lua elimina del índice opuesto antes de `ZADD`. | Integración Redis por transición. |
| [CONFIRMADO] Un job terminal no pertenece a ningún índice activo. | Lua terminal y `completeEmptyJob()`. | Integración para completed, completed_with_errors y empty. |
| [CONFIRMADO] Un estado inexistente no puede sostener actividad visible. | `EXISTS` en lectura y limpieza en transición. | Integración de `DEL state` seguida de lectura. |
| [CONFIRMADO] Una idempotencia duplicada no crea un segundo evento. | Rama inicial del Lua de `submitJob()`. | `XLEN` y conteos antes/después. |
| [CONFIRMADO] La renovación de TTL de un job activo renueva su score. | `TIME` + TTL en register, seal y mark. | Comparación de `ZSCORE` en Redis real. |
| [CONFIRMADO] Los conteos activos nunca son negativos. | Se calculan con `ZCARD`. | Tests y ausencia de `HINCRBY jobs_queued/jobs_running`. |
| [CONFIRMADO] `completed_with_errors` cuenta como completed histórico. | Rama Lua terminal existente, preservada. | Integración Redis. |
| [CONFIRMADO] El stream conserva `MAXLEN ~ AUDIT_STREAM_MAXLEN`. | Configuración compartida y argumento Lua. | Test unitario de argumentos y lectura de `XLEN` controlada. |
| [CONFIRMADO] No existe fallback a contadores activos legacy. | Eliminación de lecturas/escrituras y guard de arquitectura. | Test de arquitectura por búsqueda de símbolos/campos. |
| [CONFIRMADO] Los secretos Redis no aparecen en logs ni comandos versionados. | Uso de configuración existente y contexto de log limitado a IDs/errores. | Escaneo de secretos de CI. |

### 9. Modelo de Datos

[CONFIRMADO] Sin impacto en persistencia SQL Server. Los archivos afectados no incluyen modelos, queries, tablas, vistas, procedimientos ni migraciones SQL.

#### Persistencia Redis objetivo

| Llave lógica | Tipo | TTL | Contenido | Escritor | Lector |
| --- | --- | --- | --- | --- | --- |
| [CONFIRMADO] `job:{jobId}:state` | string JSON | `AUDIT_JOB_TTL` | Estado existente del job. | `BatchJobStore` | job status, workers, orquestador. |
| [CONFIRMADO] `batch:idem:{key}` | string | TTL recibido por productor | Primer `job_id`. | `submitJob()` | `submitJob()`. |
| [CONFIRMADO] `jobs:active:queued` | ZSET | sin TTL | miembro=llave física state; score=expiración epoch ms. | `BatchJobStore` Lua | `getActiveJobCounts()`. |
| [CONFIRMADO] `jobs:active:running` | ZSET | sin TTL | miembro=llave física state; score=expiración epoch ms. | `BatchJobStore` Lua | `getActiveJobCounts()`. |
| [CONFIRMADO] `audit.batch.inbox` | stream | sin TTL; trim aproximado | campo `event` con JSON `batch_requested`. | `submitJob()` | `BatchRequestedWorker`. |
| [CONFIRMADO] `telemetry:async_metrics` | hash | sin TTL | históricos y telemetría no activa. | workers/stores | `ObservabilityController`. |

#### DDL

```sql
-- [CONFIRMADO] No aplica: este cambio no modifica SQL Server.
```

#### Orden de Ejecución

1. [CONFIRMADO] No ejecutar DDL.
2. [CONFIRMADO] Drenar el pipeline y detener todos los escritores antiguos.
3. [CONFIRMADO] Eliminar solo los campos hash `jobs_queued` y `jobs_running`.
4. [CONFIRMADO] Eliminar cualquier ZSET objetivo preexistente antes de iniciar la versión nueva.
5. [CONFIRMADO] Desplegar todos los productores y consumidores nuevos como una unidad.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| [CONFIRMADO] `telemetry:async_metrics.jobs_queued` | Descartar después de comprobar cero jobs activos reales. | Campo eliminado. | `HEXISTS` retorna `0`. |
| [CONFIRMADO] `telemetry:async_metrics.jobs_running` | Descartar después de comprobar cero jobs activos reales. | Campo eliminado. | `HEXISTS` retorna `0`. |
| [CONFIRMADO] Jobs activos previos | Drenar; no ejecutar backfill. | ZSET inicialmente vacío. | No existe state con status `pending|processing`; ambos `ZCARD` son `0`. |
| [CONFIRMADO] `jobs_completed`, `jobs_failed`, `retries`, `terminal_failures` y métricas Gemini | Sin transformación. | Mismo hash y mismos valores. | Snapshot `HGETALL` antes/después, excluyendo los dos campos retirados. |

#### Rollback

```sql
-- [CONFIRMADO] No aplica: no existe cambio SQL que revertir.
```

### 10. Contratos

#### API `GET /metrics/async`

##### Antes

[CONFIRMADO] Forma pública actual:

```json
{
  "success": true,
  "data": {
    "queueDepth": 1,
    "streamDepths": {
      "inbox": 0,
      "documents": 0,
      "persistence": 1,
      "results": 0,
      "batchInbox": 0
    },
    "deadLetterDepth": 8842,
    "jobs": {
      "queued": 0,
      "running": 1,
      "completed": 20,
      "failed": 0
    },
    "retries": 26817,
    "terminalFailures": 8842
  }
}
```

- [CONFIRMADO] `jobs.queued` y `jobs.running` provienen del hash.
- [CONFIRMADO] `queueDepth` proviene de `XPENDING`.

##### Después

- [CONFIRMADO] El JSON, los nombres, tipos y fallback HTTP permanecen idénticos.
- [CONFIRMADO] `jobs.queued` y `jobs.running` provienen de `getActiveJobCounts()`.
- [CONFIRMADO] `jobs.completed`, `jobs.failed`, `retries` y `terminalFailures` continúan en el hash.
- [CONFIRMADO] No se agregan, eliminan, modifican ni deprecan campos públicos.
- [CONFIRMADO] Compatibilidad backward y forward: preservada para clientes que validan el esquema actual.

#### API `POST /audit/async`

##### Antes

- [CONFIRMADO] Exige `X-Idempotency-Key`, valida body, reclama idempotencia, inicializa job y publica en tres pasos.
- [CONFIRMADO] Retorna 202 con `job_id` y `status=pending`; un duplicado retorna 409 con el primer `job_id`.

##### Después

- [CONFIRMADO] Conserva validación, códigos y payloads de respuesta.
- [CONFIRMADO] Construye primero el mismo `AuditEvent` y llama una sola vez a `submitJob()`.
- [CONFIRMADO] `created=true` produce 202; `created=false` produce 409; una excepción produce el 503 sanitizado existente.
- [CONFIRMADO] Compatibilidad pública: total. Compatibilidad interna con `initJob()` y `claimIdempotencyKey()`: eliminada intencionalmente.

#### Evento `batch_requested`

##### Antes

```json
{
  "event_type": "batch_requested",
  "audit_id": null,
  "job_id": "<uuid-v4>",
  "document_id": null,
  "payload": {
    "fac_nit_sec": "2426",
    "date_from": "2026-08-12",
    "date_to": "2026-08-12",
    "limit": 100
  }
}
```

##### Después

- [CONFIRMADO] El contrato JSON y el stream `audit.batch.inbox` no cambian.
- [CONFIRMADO] El cron conserva el campo adicional `payload.source="cron"`.
- [CONFIRMADO] `AuditEventPublisher::streamForEventType()` sigue siendo la fuente de mapping.
- [CONFIRMADO] El único cambio es que `XADD` participa en el mismo Lua que el estado inicial.

#### Contrato interno `BatchJobStore`

##### Antes

```php
claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string
initJob(string $jobId, int $facNitSec, string $dateFrom, string $dateTo, int $limit): bool
patchJob(string $jobId, array $patch): bool
deleteJob(string $jobId): bool
```

##### Después

```php
/** @return array{created:bool,job_id:string,stream_id:?string} */
submitJob(AuditEvent $event, string $idempotencyKey, int $idempotencyTtl): array

/** @return array{queued:int,running:int} */
getActiveJobCounts(): array

completeEmptyJob(string $jobId, int $skippedLocked, int $skippedExisting): bool
cancelJob(string $jobId): bool
```

- [CONFIRMADO] `sealJob()`, `registerAuditInJob()` y `markAuditCompletedInJob()` conservan sus firmas y actualizan los índices dentro de sus Lua.
- [CONFIRMADO] Los cuatro métodos anteriores se eliminan, sin wrapper de compatibilidad.

#### Contrato interno `AuditBatchOrchestrator`

##### Antes

```php
enqueueBatch(int $facNitSec, string $dateFrom, string $dateTo, int $limit, ?string $jobId = null): array
```

##### Después

```php
enqueueBatch(int $facNitSec, string $dateFrom, string $dateTo, int $limit, string $jobId): array
```

- [CONFIRMADO] El método exige que el productor ya haya creado el job; `initJobOrFail()` y la rama nullable se eliminan.
- [CONFIRMADO] No existe compatibilidad interna con invocaciones sin `jobId`; las pruebas se migran al contrato productivo.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| [CONFIRMADO] R-001 | Eliminar drift de queued/running. | ZSET y lectura autocurable. | Redis integration: expiración y evicción simulada. |
| [CONFIRMADO] R-002 | Conservar contrato público. | Controller mantiene forma JSON. | Controller test + Zod typecheck. |
| [CONFIRMADO] R-003 | Alta sin gaps normales. | `submitJob()` Lua. | Integración create/duplicate/error. |
| [CONFIRMADO] R-004 | Cubrir HTTP y cron. | Ambos llaman `submitJob()`. | Tests de controller y guard de arquitectura. |
| [CONFIRMADO] R-005 | Cubrir cada transición activa. | Lua en register/seal/mark/empty/cancel. | Matriz de transición Redis. |
| [CONFIRMADO] R-006 | Autocurar expiración y evicción. | score por TTL + `EXISTS`. | Tests con score vencido y state eliminado. |
| [CONFIRMADO] R-007 | Evitar `SCAN` en runtime. | Lectura limitada a miembros ZSET. | Guard de arquitectura. |
| [CONFIRMADO] R-008 | Eliminar legado interno. | DELETE de métodos y rama nullable. | Búsqueda negativa en tests. |
| [CONFIRMADO] R-009 | No mezclar unidades en UI. | Cálculos y etiquetas separados. | typecheck, build y smoke visual. |
| [CONFIRMADO] R-010 | Probar semántica Redis real. | Service Redis 7 en CI. | Job CI obligatorio. |
| [CONFIRMADO] R-011 | Evitar versión mixta. | Migración no rolling. | Checklist de despliegue firmado. |
| [CONFIRMADO] R-012 | Preservar históricos. | Hash excluye solo dos campos. | Snapshot Redis antes/después. |
| [CONFIRMADO] R-013 | No ampliar el pipeline. | Eventos, DLQ, SQL y retries fuera de cambio. | Diff e inventario de archivos. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| [CONFIRMADO] `BatchJobStore` | Redis state/hash | Alto | Nueva operación de alta, ZSET, transiciones dedicadas y eliminación legacy. | Métodos y Lua actuales en líneas 62-464. |
| [CONFIRMADO] `AuditEventPublisher` | Config de streams | Bajo | Exponer resolver estático de MAXLEN y reutilizarlo en constructor. | Parsing privado actual en líneas 42-53. |
| [CONFIRMADO] `AuditController` | `BatchJobStore` | Medio | Reemplazar tres pasos por `submitJob()`. | Líneas 356-397. |
| [CONFIRMADO] Cron diario | `BatchJobStore`/publisher | Medio | Reemplazar duplicación y retirar publisher sin uso. | Líneas 137-190. |
| [CONFIRMADO] `AuditBatchOrchestrator` | estado job | Alto | Exigir ID, cierre vacío dedicado y cancelación coherente. | Líneas 32-47, 188-192, 293-304, 393-404. |
| [CONFIRMADO] `BatchRequestedWorker` | orquestador | Sin cambio de código | Ya entrega `string $jobId`; validar por regresión. | Líneas 84-124. |
| [CONFIRMADO] `AuditPersistenceWorker` | transición terminal | Sin cambio de código | El método invocado conserva firma; validar terminal. | Línea 348. |
| [CONFIRMADO] `AuditEventConsumer` | cierre por DLQ | Sin cambio de código | La llamada conserva firma; `batch_requested` sin auditId permanece fuera. | Líneas 464-496. |
| [CONFIRMADO] `ObservabilityController` | Redis | Medio | Obtener actividad desde store y conservar históricos del hash. | Líneas 61-85. |
| [CONFIRMADO] Frontend dashboard | contrato async | Medio | Separar eventos y jobs; cambiar etiquetas. | Cálculos citados en inventario. |
| [CONFIRMADO] Esquema Zod | JSON público | Sin cambio de código | La forma no cambia. | `frontend/lib/schemas/domain.ts:63-83`. |
| [CONFIRMADO] CI | Redis | Medio | Agregar service Redis y ejecución de integración. | `.github/workflows/ci.yml:101-104` no levanta Redis. |
| [CONFIRMADO] SQL Server | Ninguna | Sin impacto | Ningún cambio. | No hay modelos en el conjunto de archivos. |
| [CONFIRMADO] Documentación/skills | Código afectado | Medio | Sincronizar arquitectura, API, operaciones, workflow y skills. | `.agent/skills/audfact-docs-sync/SKILL.md`. |

### 13. Cambios por Archivo

#### Backend e infraestructura

| Estado | Ruta y líneas | Símbolos | Antes | Después |
| --- | --- | --- | --- | --- |
| [MODIFY] | `app/Services/Audit/Pipeline/BatchJobStore.php:12-464` | `initJob`, `claimIdempotencyKey`, `patchJob`, `deleteJob`, Lua de transición | Contadores activos y operaciones separadas. | `submitJob`, `getActiveJobCounts`, `completeEmptyJob`, `cancelJob`, ZSET y eliminación de cuatro métodos legacy. |
| [MODIFY] | `app/Services/Audit/Pipeline/AuditEventPublisher.php:42-53` | constructor, nuevo `configuredStreamMaxLen()` | Parsing disponible solo en instancia. | Resolver estático único usado por publisher y submit. |
| [MODIFY] | `app/Controllers/AuditController.php:339-407` | `async()` | claim → init → publish. | Crear evento → `submitJob()` → mapear resultado. |
| [MODIFY] | `bin/schedule-daily-batches.php:23-190` | imports, dependencias, loop | Store y publisher con tres pasos. | Solo store con `submitJob()`; import/variable publisher eliminados. |
| [MODIFY] | `app/Services/Audit/AuditBatchOrchestrator.php:32-47,188-192,293-315,393-404` | `enqueueBatch`, `initJobOrFail`, `publishEmptyBatch`, cleanup | ID nullable, init alternativo, patch/delete genéricos. | ID obligatorio, sin init alternativo, complete/cancel dedicados. |
| [MODIFY] | `app/Controllers/ObservabilityController.php:20-105` | `asyncMetrics`, nuevo factory de store | Lee cuatro jobs del hash. | Lee active del store e históricos del hash. |
| [MODIFY] | `.github/workflows/ci.yml:14-104` | job `lint` | PHPUnit sin Redis. | Redis 7 service, healthcheck y variables opt-in de integración. |

#### Frontend

| Estado | Ruta y líneas | Símbolos | Antes | Después |
| --- | --- | --- | --- | --- |
| [MODIFY] | `frontend/components/dashboard/async-queue-summary.tsx:38-49` | `queuePressure`, primera tarjeta | Suma eventos y jobs bajo “Activos”. | `activeJobs=running+queued`, etiqueta “Jobs activos”. |
| [MODIFY] | `frontend/app/(dashboard)/dashboard/page.tsx:59-64,122-130` | `activeQueueCount`, StatCard | Valor agregado heterogéneo. | Valor=`queueDepth`; hint incluye jobs activos y DLQ. |
| [MODIFY] | `frontend/components/dashboard/dashboard-health-strip.tsx:63-69` | chip Cola | Etiqueta XPENDING como pendientes. | Etiqueta XPENDING como eventos en vuelo. |
| [MODIFY] | `frontend/app/(dashboard)/observability/page.tsx:154-161` | bloques métricos | “Queue Depth”. | “Eventos en vuelo”; jobs continúan separados. |

#### Tests

| Estado | Ruta y líneas | Símbolos | Antes | Después |
| --- | --- | --- | --- | --- |
| [MODIFY] | `tests/Services/Audit/Pipeline/BatchJobStoreMetricsTest.php:11-68` | test Lua | Verifica `HINCRBY` por texto. | Verifica keys/args, ausencia de contadores activos y ramas terminales. |
| [MODIFY] | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php:50-120` | tests job TTL | Invoca `initJob()`. | Invoca `submitJob()` y valida TTL/score configurado. |
| [MODIFY] | `tests/Controllers/ObservabilityControllerTest.php:13-60` | controller double | No aserta `jobs`. | Inyecta store, aserta activos ZSET e históricos hash. |
| [MODIFY] | `tests/Controllers/AuditControllerTest.php:100-190,480-560` | async y fake store | Fakes de claim/init/patch/delete. | Fake de submit y contratos complete/cancel. |
| [MODIFY] | `tests/Services/Audit/AuditBatchOrchestratorTest.php:20-220` | llamadas y fake store | Job auto-generado y patch/delete. | Job externo explícito y complete/cancel. |
| [NEW] | `tests/Integration/Audit/Pipeline/BatchJobStoreRedisIntegrationTest.php` | suite Redis real | No existe. | Cubre alta, duplicado, transición, TTL, state ausente, empty y cancel. |
| [NEW] | `tests/Architecture/BatchJobSubmissionPathTest.php` | guard estructural | No existe. | Exige ambos productores en `submitJob()` y prohíbe símbolos/campos legacy. |

#### Documentación y gobernanza

| Estado | Ruta | Antes | Después |
| --- | --- | --- | --- |
| [NEW] | `plans/async-job-metrics-sdd.md` | No existe especificación determinista. | Este SDD Nivel A. |
| [MODIFY] | `README.md` | Métrica async descrita de forma genérica. | Diferencia eventos en vuelo, jobs activos e históricos. |
| [MODIFY] | `plans/api-endpoints.md` | Semántica anterior y drift en obligatoriedad del header. | Contrato real: header requerido y fuentes de cada métrica. |
| [MODIFY] | `plans/architecture.md` | Contadores activos en hash. | Índices activos ZSET y alta Lua. |
| [MODIFY] | `plans/features/audit-workflow.md` | Flujo de alta separado. | Envío atómico y transiciones. |
| [MODIFY] | `plans/docker-operations.md` | Sin runbook de corte de métricas. | Migración y rollback Redis. |
| [MODIFY] | `plans/deployment-and-ci.md` | PHPUnit sin Redis service. | Integración Redis obligatoria. |
| [MODIFY] | `plans/testing-strategy.md` | Lua cubierto principalmente por mocks. | Suite Redis opt-in local y obligatoria en CI. |
| [MODIFY] | `plans/changelog.md` | No registra este SDD/implementación. | Registra especificación y, después, resultado implementado. |
| [MODIFY] | `.agent/skills/audfact-audit-gemini/SKILL.md` | Describe métricas activas actuales. | Documenta ZSET y operación de alta. |
| [MODIFY] | `.agent/skills/audfact-api-rest/SKILL.md` | Contrato de métricas sin fuente nueva. | Documenta contrato preservado y semántica. |
| [MODIFY] | `.agent/skills/audfact-runtime-docker/SKILL.md` | CI sin integración Redis documentada. | Documenta servicio Redis CI y corte no rolling. |
| [MODIFY] | `.agent/skills/audfact-project-overview/SKILL.md` | Vista general anterior. | Sincroniza el flujo batch si su descripción contiene el alta anterior. |

- [CONFIRMADO] No se crea un adapter, shim, clase futura, variable de entorno ni archivo de configuración adicional.
- [CONFIRMADO] `core/RedisClient.php`, `frontend/lib/schemas/domain.ts`, `docker-compose.yml`, `.env.example`, modelos SQL y rutas REST permanecen sin cambios.

### 14. Plan de Migración

#### Prerequisitos

1. [CONFIRMADO] CI completo verde, incluido Redis integration, PHPUnit, PHP lint, frontend typecheck, frontend build, website build y validación de skills.
2. [CONFIRMADO] Imágenes PHP, Nginx y frontend publicadas con el mismo SHA inmutable.
3. [CONFIRMADO] Ventana de mantenimiento aprobada; el despliegue rolling queda prohibido.
4. [CONFIRMADO] Operaciones identifica y pausa el scheduler real que invoca `schedule-daily-batches.php`.
5. [CONFIRMADO] Existe acceso autenticado a Redis sin exponer `REDIS_PASSWORD` en consola, logs o documentación.
6. [CONFIRMADO] Se registra `HGETALL audfact:telemetry:async_metrics` como evidencia de pre-migración.

#### Validaciones Previas

1. [CONFIRMADO] Bloquear nuevas solicitudes HTTP deteniendo temporalmente Nginx; PHP y workers permanecen activos durante el drenaje.
2. [CONFIRMADO] Confirmar que el cron está pausado y que no existe un proceso del scheduler en ejecución.
3. [CONFIRMADO] Para cada consumer group definido en `AuditEventPublisher`, exigir `pending=0` y `lag=0` mediante `XINFO GROUPS` y `XPENDING`.
4. [CONFIRMADO] Ejecutar un `SCAN` operacional único sobre `audfact:job:*:state`, decodificar cada JSON y exigir ausencia de `status=pending|processing`.
5. [CONFIRMADO] Si cualquier gate anterior falla, abortar la migración, reabrir Nginx y reactivar el scheduler sin modificar Redis.

#### Ejecución

1. [CONFIRMADO] Detener todos los contenedores `worker-*` después del drenaje.
2. [CONFIRMADO] Detener los contenedores PHP antiguos.
3. [CONFIRMADO] Guardar como evidencia los valores de todos los campos de `telemetry:async_metrics`.
4. [CONFIRMADO] Registrar el valor efectivo de `REDIS_PREFIX` del contenedor PHP; los comandos siguientes concatenan ese valor exacto con cada llave lógica.
5. [CONFIRMADO] Ejecutar `HDEL <REDIS_PREFIX-efectivo>telemetry:async_metrics jobs_queued jobs_running`.
6. [CONFIRMADO] Ejecutar `DEL <REDIS_PREFIX-efectivo>jobs:active:queued <REDIS_PREFIX-efectivo>jobs:active:running` para asegurar un inicio limpio.
7. [CONFIRMADO] Desplegar y recrear PHP, todos los workers, Nginx y frontend desde el mismo SHA nuevo.
8. [CONFIRMADO] Iniciar workers, PHP y Nginx; mantener el scheduler pausado.
9. [CONFIRMADO] Ejecutar un job controlado sin facturas y comprobar transición queued→completed.
10. [CONFIRMADO] Ejecutar un job controlado con al menos una auditoría y comprobar queued→running→terminal.
11. [CONFIRMADO] Reactivar el scheduler solo después de completar todas las validaciones posteriores.

#### Validaciones Posteriores

1. [CONFIRMADO] `HEXISTS <REDIS_PREFIX-efectivo>telemetry:async_metrics jobs_queued` y `jobs_running` retornan `0`.
2. [CONFIRMADO] Los campos históricos registrados antes del corte conservan el mismo valor, excepto los incrementos provocados por los jobs controlados.
3. [CONFIRMADO] Durante el job vacío: `ZCARD queued` pasa 1→0, `ZCARD running` permanece 0 y `jobs.completed` aumenta 1.
4. [CONFIRMADO] Durante el job no vacío: el miembro aparece primero en queued, luego exclusivamente en running y al final en ninguno.
5. [CONFIRMADO] `GET /metrics/async` responde 200 y sus conteos coinciden con ambos `ZCARD`.
6. [CONFIRMADO] `GET /audit/jobs/{jobId}` conserva el estado y progreso esperado.
7. [CONFIRMADO] Dashboard y observabilidad muestran eventos en vuelo y jobs activos sin sumarlos.
8. [CONFIRMADO] Logs no contienen errores de tipo Redis, errores Lua ni referencias a métodos legacy.

#### Rollback

1. [CONFIRMADO] Pausar Nginx y scheduler nuevamente.
2. [CONFIRMADO] Drenar todos los jobs creados por la versión nueva; exigir ambos ZSET en cero y ningún state activo.
3. [CONFIRMADO] Detener todos los workers y contenedores PHP nuevos.
4. [CONFIRMADO] Restaurar todas las imágenes del SHA anterior como una unidad.
5. [CONFIRMADO] Ejecutar `DEL <REDIS_PREFIX-efectivo>jobs:active:queued <REDIS_PREFIX-efectivo>jobs:active:running`.
6. [CONFIRMADO] Ejecutar `HSET <REDIS_PREFIX-efectivo>telemetry:async_metrics jobs_queued 0 jobs_running 0`; el cero es válido porque el drenaje es gate obligatorio.
7. [CONFIRMADO] Conservar sin cambios los campos históricos y el resto de la telemetría.
8. [CONFIRMADO] Iniciar PHP, workers, Nginx y frontend del SHA anterior.
9. [CONFIRMADO] Validar `/health`, `/metrics/async` y un job controlado.
10. [CONFIRMADO] Reactivar el scheduler y cerrar la ventana.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| [CONFIRMADO] Idempotencia repetida concurrente | Una ejecución crea el job; las demás retornan el mismo ID. | Un state, un miembro queued y un evento. |
| [CONFIRMADO] Job UUID ya existente con idempotencia nueva | Rechazo explícito. | Excepción, sin evento ni nueva membresía. |
| [CONFIRMADO] Tipo Redis incompatible en una key | Abort antes de escribir el nuevo job. | Error logueado y estado preexistente intacto. |
| [CONFIRMADO] `XADD` retorna error | Compensación de escrituras del intento. | Sin state, idempotencia ni miembro del nuevo ID. |
| [CONFIRMADO] Estado expira por TTL | Score vencido se poda. | Conteo activo cero. |
| [CONFIRMADO] Estado se elimina antes del score | `EXISTS` retira el miembro. | Primera lectura retorna conteo corregido. |
| [CONFIRMADO] ZSET pierde un miembro con state activo | La métrica subcuenta hasta otra mutación del job; register, seal o mark reinsertan el miembro según estado. | Test de reparación por transición. |
| [CONFIRMADO] Job vacío | Cierra como completed sin pasar por running. | queued 1→0; completed +1. |
| [CONFIRMADO] Job con un fallo de auditoría | Cierra `completed_with_errors`. | running 1→0; completed +1; failed histórico sin incremento. |
| [CONFIRMADO] Transición terminal repetida | Es idempotente. | Históricos incrementan una sola vez. |
| [CONFIRMADO] Cancelación antes de publicar auditorías | Elimina estado e índices. | `GET` nulo y ambos `ZSCORE` nulos. |
| [CONFIRMADO] Worker terminal recibe state ausente | Limpia índices y retorna false. | Warning y actividad cero. |
| [CONFIRMADO] Redis no disponible | Endpoint conserva fallback de ceros; envío retorna 503. | Tests de controller existentes y modificados. |
| [CONFIRMADO] `AUDIT_STREAM_MAXLEN<=0` | `XADD` omite MAXLEN, igual que publisher actual. | Test unitario de configuración. |
| [CONFIRMADO] `AUDIT_JOB_TTL` inválido | Usa 604800 segundos. | `RedisTtlConfigTest`. |
| [CONFIRMADO] Despliegue mixto | Prohibido. | Checklist bloquea arranque hasta SHA uniforme. |
| [CONFIRMADO] `batch_requested` falla antes de publicar auditorías | Conserva la política de cleanup/retry actual, con `cancelJob()` en vez de `deleteJob()`. | Test de regresión del orquestador. |
| [CONFIRMADO] Falla después de publicar alguna auditoría | No ejecuta rollback destructivo. | Test existente preservado. |

### 16. Testing

#### Nuevos Tests

1. [CONFIRMADO] `BatchJobStoreRedisIntegrationTest::testSubmitCreatesExactlyOneConsistentSubmission`: con Redis 7 vacío, enviar evento; asertar idempotencia, state+TTL, `ZSCORE queued`, ausencia en running y un `XADD`.
2. [CONFIRMADO] `...::testConcurrentDuplicateDoesNotCreateSecondEvent`: enviar dos veces la misma key; asertar `created` true/false, mismo ID y delta de stream igual a uno.
3. [CONFIRMADO] `...::testPendingProcessingCompletedTransition`: registrar, sellar y completar una auditoría; asertar exclusividad de índices y `jobs_completed +1`.
4. [CONFIRMADO] `...::testCompletedWithErrorsCountsAsCompleted`: finalizar auditoría fallida; asertar estado `completed_with_errors`, cero activos y completed +1.
5. [CONFIRMADO] `...::testEmptyCompletionIsIdempotent`: llamar cierre vacío dos veces; asertar completed +1 una sola vez.
6. [CONFIRMADO] `...::testExpiredScoreAndMissingStateArePruned`: insertar score vencido y eliminar otro state; asertar conteos cero.
7. [CONFIRMADO] `...::testActiveMutationRefreshesScore`: capturar `ZSCORE`, ejecutar mutación activa y asertar score mayor.
8. [CONFIRMADO] `...::testCancelRemovesStateAndBothMemberships`: cancelar y asertar ausencia total.
9. [CONFIRMADO] `...::testWrongRedisTypeFailsWithoutNewSubmission`: forzar tipo incorrecto y asertar ausencia de efectos del job.
10. [CONFIRMADO] `BatchJobSubmissionPathTest`: leer controller, cron, store y orquestador; exigir llamadas a `submitJob`, ausencia de `claimIdempotencyKey`, `initJob`, `patchJob`, `deleteJob`, `jobs_queued` y `jobs_running` en código runtime.

- [CONFIRMADO] La integración usará `RUN_REDIS_INTEGRATION=1`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, `REDIS_MODE=standalone` y un `REDIS_PREFIX` exclusivo de CI.
- [CONFIRMADO] La limpieza de tests eliminará solo llaves con ese prefijo; `FLUSHDB` y `FLUSHALL` quedan prohibidos.
- [CONFIRMADO] Cuando `RUN_REDIS_INTEGRATION` no sea `1`, la clase se omite localmente con una razón explícita; CI fija el valor en `1`, por lo que una indisponibilidad de Redis falla el pipeline.

#### Tests Modificados

1. [CONFIRMADO] `BatchJobStoreMetricsTest`: sustituir expectativas de decremento por expectativas de ZREM/ZADD, históricos e invariantes de keys.
2. [CONFIRMADO] `RedisTtlConfigTest`: crear el job por `submitJob()` y verificar TTL default, override e inválidos.
3. [CONFIRMADO] `ObservabilityControllerTest`: inyectar `BatchJobStore`, retornar queued/running conocidos y comprobar que completed/failed aún salen del hash.
4. [CONFIRMADO] `AuditControllerTest`: cubrir created, duplicate y RuntimeException de `submitJob()`; mantener 202, 409 y 503.
5. [CONFIRMADO] `AuditBatchOrchestratorTest`: proporcionar siempre job ID; sustituir contadores fake de patch/delete/init por complete/cancel; preservar caso de publicación parcial.

#### Tests Eliminados

- [CONFIRMADO] Ningún archivo de prueba se elimina.
- [CONFIRMADO] Las aserciones que exigen `HINCRBY jobs_queued/jobs_running` se eliminan porque validan la arquitectura defectuosa; la integración ZSET las reemplaza.

#### Verificaciones Manuales

1. [CONFIRMADO] Backend: ejecutar `vendor/bin/phpunit --configuration phpunit.xml --testdox` y exigir exit code 0.
2. [CONFIRMADO] Redis local: levantar Redis 7, fijar `RUN_REDIS_INTEGRATION=1` y ejecutar exclusivamente la clase de integración; exigir exit code 0.
3. [CONFIRMADO] Sintaxis PHP: ejecutar `php -l` sobre cada PHP modificado; exigir “No syntax errors detected”.
4. [CONFIRMADO] Frontend: ejecutar `npm.cmd run typecheck` y `npm.cmd run build` desde `frontend`; exigir exit code 0.
5. [CONFIRMADO] Docs: ejecutar `npm.cmd run build` desde `website`; exigir exit code 0.
6. [CONFIRMADO] Skills: ejecutar `node .agent/skills/_shared/scripts/validate-skills.mjs`; exigir exit code 0.
7. [CONFIRMADO] Producción: ejecutar los dos jobs controlados de la sección 14 y comparar endpoint contra `ZCARD`.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| [CONFIRMADO] Versión antigua y nueva activas a la vez | migración / consistencia | Crítica | Drenaje, parada total de escritores y despliegue por SHA único. |
| [CONFIRMADO] Scheduler desconocido permanece activo | operativo | Alta | Gate firmado: identificar, pausar y comprobar ausencia de proceso. |
| [CONFIRMADO] Lua falla por tipo de llave inesperado | técnico | Alta | Prevalidación `TYPE`, códigos de error y compensación. |
| [CONFIRMADO] Evicción temprana del state | consistencia de datos | Media | Verificación `EXISTS` y limpieza autocurable. |
| [CONFIRMADO] Costo O(n) sobre jobs activos al leer métricas | rendimiento | Baja | Recorrido limitado a ZSET activos, poda previa y ausencia de SCAN histórico. |
| [CONFIRMADO] Pérdida de un ZSET completo | consistencia de datos | Media | El ZSET no tiene TTL y `volatile-lru` solo selecciona llaves expirables; mutaciones activas reparan membresía individual. |
| [CONFIRMADO] Crash Redis dentro de la ventana RDB | técnico / consistencia de datos | Alta | Riesgo existente aceptado en este MVP; state, índice y evento se ejecutan en el mismo Lua, pero la durabilidad posterior al ACK sigue limitada por RDB. |
| [CONFIRMADO] Evento terminal reclamado y no publicado | técnico | Media | Riesgo preexistente fuera de alcance; se registra para un SDD independiente. |
| [CONFIRMADO] Reintento de `batch_requested` tras cleanup | técnico | Media | Se conserva comportamiento actual y su test; no se introduce transición nueva sin resolver publicación parcial. |
| [CONFIRMADO] Docs y skills divergen del código | gobernanza | Media | Matriz de sincronización y validación obligatoria antes del merge. |

#### Excepciones aceptadas bajo clean rebuild

| Excepción | Razón | Owner | Condición de expiración | Validación |
| --- | --- | --- | --- | --- |
| [CONFIRMADO] Mantener históricos en `telemetry:async_metrics` | Son un contrato operativo distinto de actividad y no dependen de state expirables. | Equipo backend AudFact | Expira solo mediante un SDD de retención histórica. | Snapshot y controller test. |
| [CONFIRMADO] Mantener RDB sin AOF | Cambiar durabilidad excede el MVP de integridad métrica. | Operaciones AudFact | Expira con un SDD de resiliencia Redis. | Config efectiva `redis-server` y prueba de recuperación separada. |
| [CONFIRMADO] Mantener cleanup/retry actual de batch | Resolver publicación parcial exige un contrato idempotente nuevo. | Equipo pipeline AudFact | Expira con un SDD de idempotencia del orquestador. | Tests de fallo antes/después del primer publish. |

### 18. Criterios de Aceptación

1. [CONFIRMADO] No existe ninguna referencia runtime a `telemetry:async_metrics.jobs_queued` ni `jobs_running`.
2. [CONFIRMADO] No existen los métodos `initJob`, `claimIdempotencyKey`, `patchJob`, `deleteJob` ni `initJobOrFail`.
3. [CONFIRMADO] Controller y cron llaman `submitJob()` exactamente una vez por intento de alta.
4. [CONFIRMADO] Una idempotencia duplicada produce un solo state, un miembro queued y un evento.
5. [CONFIRMADO] La expiración o eliminación anticipada del state produce `jobs.queued=0` y `jobs.running=0` en la siguiente lectura.
6. [CONFIRMADO] Un job nunca aparece simultáneamente en queued y running.
7. [CONFIRMADO] Un job terminal no aparece en ningún índice activo.
8. [CONFIRMADO] El cierre vacío incrementa completed una sola vez.
9. [CONFIRMADO] `completed_with_errors` incrementa completed y no failed.
10. [CONFIRMADO] `/metrics/async` responde HTTP 200 con el mismo esquema público.
11. [CONFIRMADO] La UI no suma `queueDepth` con conteos de jobs.
12. [CONFIRMADO] La integración Redis 7 se ejecuta obligatoriamente y pasa en CI.
13. [CONFIRMADO] PHPUnit, lint PHP, frontend typecheck/build, website build y validación de skills terminan con exit code 0.
14. [CONFIRMADO] La migración conserva todos los campos históricos y elimina únicamente los dos campos activos legacy.
15. [CONFIRMADO] El despliegue productivo no contiene simultáneamente imágenes antiguas y nuevas.
16. [CONFIRMADO] No existen cambios SQL, nuevas variables de entorno, adaptadores de compatibilidad, código comentado ni imports sin uso.

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas están definidas | [CONFIRMADO] PASS | No hay tablas SQL; las seis estructuras Redis afectadas están definidas en sección 9. |
| Todas las columnas existen | [CONFIRMADO] PASS | No hay columnas SQL; los campos JSON/hash conservados y retirados están enumerados. |
| Todos los contratos documentados | [CONFIRMADO] PASS | API, evento y APIs internas tienen Antes/Después en sección 10. |
| Todos los requisitos tienen trazabilidad | [CONFIRMADO] PASS | R-001 a R-013 aparecen en implementación y validación. |
| Todos los consumidores analizados | [CONFIRMADO] PASS | Controller, cron, batch worker, orquestador, persistence worker, consumer DLQ y frontend están en sección 12. |
| Todas las migraciones tienen rollback | [CONFIRMADO] PASS | Redis tiene secuencia completa; SQL declara no aplica. |
| Todas las referencias están definidas | [CONFIRMADO] PASS | Llaves, métodos, eventos, archivos y variables citados están inventariados. |
| Toda compatibilidad tiene evidencia | [CONFIRMADO] PASS | Contrato público preservado e interfaces internas legacy eliminadas en sección 10. |
| Todos los criterios son verificables | [CONFIRMADO] PASS | Cada criterio se vincula a búsqueda, test, comando o gate operativo. |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado |
| --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | [CONFIRMADO] No |
| ¿Existe algún contrato sin documentar? | [CONFIRMADO] No |
| ¿Existe algún consumidor no analizado? | [CONFIRMADO] No |
| ¿Existe alguna migración sin rollback? | [CONFIRMADO] No |
| ¿Existe algún dato persistido sin migración? | [CONFIRMADO] No |
| ¿Existe alguna afirmación sin evidencia? | [CONFIRMADO] No |
| ¿Existen referencias huérfanas? | [CONFIRMADO] No |
| ¿Dos implementadores producirían soluciones diferentes? | [CONFIRMADO] No |

### Auditoría `clean-rebuild-policy`

| Control | Resultado | Evidencia |
| --- | --- | --- |
| MVP limitado al problema validado | [CONFIRMADO] PASS | Non Goals excluye resiliencia, Cluster, AOF y nuevos estados. |
| Sin adapter o shim | [CONFIRMADO] PASS | Métodos y rama legacy se eliminan en un corte no rolling. |
| Responsabilidades explícitas | [CONFIRMADO] PASS | Store posee la transacción batch; publisher conserva mapping/config; controller presenta HTTP; UI presenta métricas. |
| Sin abstracción especulativa | [CONFIRMADO] PASS | No se crea framework de métricas ni servicio genérico. |
| Sin código/configuración obsoletos | [CONFIRMADO] PASS | Criterios exigen borrar métodos, campos, imports y variables sin uso. |
| Excepciones documentadas | [CONFIRMADO] PASS | Owner, expiración y validación constan en sección 17. |

## FASE 4 — Resultado Final

### Nivel de Completitud

[CONFIRMADO] `Nivel A — Implementable`.

### Definición de Completitud

- [CONFIRMADO] No requiere reuniones ni aclaraciones para implementar el código.
- [CONFIRMADO] No deja decisiones arquitectónicas pendientes.
- [CONFIRMADO] Define firmas, llaves, tipos, TTL, scores, transiciones, productores, consumidores y errores.
- [CONFIRMADO] Define pruebas objetivas, migración no rolling y rollback completo.
- [CONFIRMADO] Obtiene PASS en todas las verificaciones de consistencia.
- [CONFIRMADO] Obtiene No en todas las preguntas de auditoría arquitectónica.
