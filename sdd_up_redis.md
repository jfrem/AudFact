# SDD - Ampliacion Redis y TTL de Auditorias AudFact

## FASE 0 - Descubrimiento Obligatorio

### Inventario de Informacion

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| Redis existe como servicio Docker en desarrollo. | [CONFIRMADO] | `docker-compose.yml:34-51` define `redis`, `redis:7-alpine`, `--maxmemory 256mb`, politica `volatile-lru` y limite `memory: 300M`. |
| Redis existe como servicio Docker en produccion LAN. | [CONFIRMADO] | `docker-compose.prod.yml:44-70` define `redis`, `redis:7-alpine`, `--maxmemory 256mb`, politica `volatile-lru` y limite `memory: 300M`. |
| El servidor remoto tiene RAM suficiente para ampliar Redis sin cambio fisico inmediato. | [CONFIRMADO] | Diagnostico remoto del 2026-06-18: memoria total 43 GiB, memoria disponible aproximada 41 GiB, swap 8 GiB sin uso. |
| Redis productivo esta limitado por configuracion y no por hardware. | [CONFIRMADO] | Diagnostico remoto del 2026-06-18: contenedor `audfact-redis` usa 270 MiB de 300 MiB; `INFO memory` reporta `maxmemory_human:256.00M`. |
| Redis productivo presento evicciones. | [CONFIRMADO] | Diagnostico remoto del 2026-06-18: `INFO stats` reporto `evicted_keys:2152`. |
| El job `88f8e358-ecd0-42fa-b86e-a9394439d04d` desaparecio del endpoint por ausencia de estado Redis. | [CONFIRMADO] | Diagnostico remoto del 2026-06-18: `GET /audit/jobs/88f8e358-ecd0-42fa-b86e-a9394439d04d` devolvio HTTP 404 y no existian llaves Redis con ese `jobId`. |
| La API expone estado de job y estado de auditoria. | [CONFIRMADO] | `app/Routes/web.php:39-40` define `/audit/jobs/{jobId}` y `/audit/status/{auditId}`. |
| `AuditController::jobStatus` lee estado desde `BatchJobStore`. | [CONFIRMADO] | `app/Controllers/AuditController.php:422-429` invoca `buildBatchJobStore()->getJob($jobId)`. |
| `BatchJobStore` usa TTL fijo de 86400 segundos para estado de job. | [CONFIRMADO] | `app/Services/Audit/Pipeline/BatchJobStore.php:22` define `JOB_TTL_SECONDS = 86400`; `BatchJobStore.php:63,112,131,169,186` reutiliza esa constante. |
| `BatchJobStore` usa el mismo TTL fijo para reservas por `DisId`. | [CONFIRMADO] | `app/Services/Audit/Pipeline/BatchJobStore.php:243` declara `claimAuditReservation(..., int $ttl = self::JOB_TTL_SECONDS)`. |
| `AuditStateStore` usa TTL fijo de 86400 segundos para estado de auditoria. | [CONFIRMADO] | `app/Services/Audit/Pipeline/AuditStateStore.php:29` define `AUDIT_TTL_SECONDS = 86400`; `AuditStateStore.php:69,97,121,132,188,208,219,230,247` reutiliza esa constante. |
| La cache de hash de dispensacion ya es configurable por variable. | [CONFIRMADO] | `core/Cache.php:218` lee `AUDIT_CACHE_TTL` con default `86400`. |
| La cache de extraccion documental ya es configurable por variable. | [CONFIRMADO] | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:29,63-64` lee `AUDIT_EXTRACTION_CACHE_TTL` con default `86400`. |
| `.env.example` documenta TTL de cache de 24 horas. | [CONFIRMADO] | `.env.example:147-149` define `AUDIT_CACHE_TTL=86400` y `AUDIT_EXTRACTION_CACHE_TTL=86400`. |
| `.env.example` documenta TTL de idempotencia HTTP de 300 segundos. | [CONFIRMADO] | `.env.example:134` define `AUDIT_IDEMPOTENCY_KEY_TTL=300`. |
| Los tests contienen fakes con firma actual de `claimAuditReservation`. | [CONFIRMADO] | `tests/Controllers/AuditControllerTest.php:498` y `tests/Services/Audit/AuditBatchOrchestratorTest.php:121` declaran `int $ttl = 86400`. |
| El limite funcional actual de batch es 100 auditorias por lote. | [CONFIRMADO] | `.env.example:98` define `AUDIT_BATCH_MAX_LIMIT=100`; el diagnostico del job mostro `limit=100`. |
| La ventana exacta solicitada para retener jobs y caches no fue especificada por el usuario. | [CONFIRMADO] | Solicitud del usuario: "ampliar la duacion de los jobs en redos para que sea mayor a 24 horas". |
| La especificacion usara 7 dias como valor objetivo inicial. | [INFERIDO] | El valor `604800` segundos cubre re-auditorias mayores a 24 horas y mantiene una ventana acotada para Redis. |
| El worktree contiene cambios preexistentes no relacionados con Redis/TTL. | [CONFIRMADO] | `git diff --name-only` lista cambios en `ObservabilityController.php`, `AuditEventConsumer.php`, `DocumentExtractionWorker.php`, `DocumentNormalizer.php`, `DocumentPolicyEngine.php`, `AuditStatusModel.php`, `plans/features/audit-workflow.md` y tests de eventos. |
| `BatchJobStore.php` ya contiene cambios preexistentes de telemetria async en el worktree actual. | [CONFIRMADO] | `git diff -- app/Services/Audit/Pipeline/BatchJobStore.php` agrega `hIncrBy('telemetry:async_metrics', ...)` en `initJob` y en el script Lua de transicion de estado. |
| Los cambios preexistentes de telemetria async no implementan la ampliacion Redis/TTL. | [CONFIRMADO] | `rg -n "JOB_TTL_SECONDS|AUDIT_JOB_TTL|AUDIT_RESERVATION_TTL" app/Services/Audit/Pipeline/BatchJobStore.php` muestra que `JOB_TTL_SECONDS` sigue en `86400` y no existen `AUDIT_JOB_TTL` ni `AUDIT_RESERVATION_TTL`. |
| Los cambios preexistentes de segmentacion parcial no implementan la ampliacion Redis/TTL. | [CONFIRMADO] | `git diff -- app/Services/Audit/Pipeline/DocumentExtractionWorker.php app/Services/Audit/Pipeline/DocumentNormalizer.php app/Services/Audit/Pipeline/DocumentPolicyEngine.php` muestra `ITEM_SEGMENTATION_INCOMPLETE`, preservacion de `extraction_warnings` y `NO_CONCLUYENTE` para lineas. |

### Informacion Faltante Critica

| Dato | Motivo | Impacto |
| --- | --- | --- |
| No falta informacion critica para implementar una primera ampliacion con valores configurables. | [CONFIRMADO] | La implementacion puede usar defaults conservadores y variables de entorno documentadas. |

### Informacion Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Volumen diario esperado de auditorias, documentos por auditoria y re-auditorias. | [DESCONOCIDO] | Sin esta metrica, `4gb` de Redis es una configuracion inicial verificable, no un sizing definitivo de capacidad. |
| Politica operativa deseada para retencion de streams Redis. | [DESCONOCIDO] | Los streams `audit.documents` y `audit.results` crecen sin trim confirmado en el codigo inspeccionado; el cambio de memoria reduce evicciones, pero no define retencion de streams. |
| Ventana exacta de negocio para re-auditoria. | [DESCONOCIDO] | La solicitud exige mayor a 24h; la especificacion fija 7 dias como supuesto declarado. |

### Informacion Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Modelo exacto de discos y RAID efectivo. | [DESCONOCIDO] | No bloquea Redis en memoria; el volumen `redis_data` actual ocupa alrededor de 67 MiB en `docker system df`. |
| Metricas historicas de `used_memory_peak` por semana. | [DESCONOCIDO] | No bloquea la primera ampliacion; sirve para ajustar el valor despues de operar con 7 dias. |

### Supuestos Declarados

| ID | Supuesto | Evidencia | Riesgo |
| --- | --- | --- | --- |
| S1 | [INFERIDO] `AUDIT_JOB_TTL`, `AUDIT_STATE_TTL`, `AUDIT_CACHE_TTL` y `AUDIT_EXTRACTION_CACHE_TTL` tendran default `604800` segundos. | La solicitud exige mas de 24h y el diagnostico mostro perdida de estado antes de que el usuario pudiera consultar el job. | Riesgo medio: mas retencion aumenta memoria Redis. |
| S2 | [INFERIDO] `REDIS_MAXMEMORY` tendra default `4gb` y el limite del contenedor Redis sera `5G`. | El servidor tiene 43 GiB de RAM y Redis esta limitado artificialmente a 256 MiB/300 MiB. | Riesgo bajo: el host tiene margen; el valor debe observarse despues del deploy. |
| S3 | [INFERIDO] `AUDIT_RESERVATION_TTL` mantendra default `86400` segundos. | `BatchJobStore` usa hoy el TTL de job para reservas por `DisId`; separar este TTL evita bloqueos mas largos al subir job TTL. | Riesgo bajo: preserva el comportamiento actual de reserva. |
| S4 | [INFERIDO] `AUDIT_IDEMPOTENCY_KEY_TTL` permanece en 300 segundos. | `.env.example:134` lo define para idempotencia HTTP, no para retencion de job ni cache de re-auditoria. | Riesgo bajo: no cambia contrato de reintentos HTTP inmediatos. |

### Clasificacion de Completitud Inicial

[INFERIDO] Nivel B - Implementable con Supuestos Declarados. La evidencia local y remota cubre archivos, limites actuales, rutas de consulta, TTL actuales y capacidad del host; la ventana exacta de negocio y el volumen esperado quedan documentados como supuestos.

## FASE 1 - Especificacion

### 1. Objetivo

- [CONFIRMADO] El problema actual es que Redis productivo opera con `maxmemory 256mb` y limite de contenedor `300M`, mientras el pipeline async genera estado de jobs, auditorias, documentos, streams y caches.
- [CONFIRMADO] La causa raiz operacional observada fue presion de memoria Redis con politica `volatile-lru`, evidenciada por `evicted_keys:2152` y desaparicion del estado del job diagnosticado.
- [CONFIRMADO] El impacto actual es que `/audit/jobs/{jobId}` puede responder 404 aunque el job se haya ejecutado, porque el estado `job:{id}:state` ya no existe en Redis.
- [CONFIRMADO] El impacto actual tambien afecta `/audit/status/{auditId}` cuando `audit:{id}:state` fue evictado o expiro.
- [INFERIDO] El resultado esperado es que Redis tenga capacidad inicial suficiente para batches de 100 auditorias y que los estados/caches duren 7 dias por defecto.
- [CONFIRMADO] Este cambio existe porque el usuario solicito validar hardware, ampliar Redis y extender la duracion de jobs en Redis por encima de 24 horas para soportar re-auditorias mayores a 24 horas.

### 2. Alcance

#### Incluido

- [CONFIRMADO] Parametrizar `maxmemory` Redis en `docker-compose.yml` y `docker-compose.prod.yml`.
- [CONFIRMADO] Parametrizar limite de memoria del contenedor Redis en `docker-compose.yml` y `docker-compose.prod.yml`.
- [CONFIRMADO] Agregar variables no sensibles en `.env.example` para capacidad Redis y TTL del pipeline.
- [CONFIRMADO] Reemplazar TTL fijo de `BatchJobStore` por `AUDIT_JOB_TTL`.
- [CONFIRMADO] Reemplazar TTL fijo de `AuditStateStore` por `AUDIT_STATE_TTL`.
- [CONFIRMADO] Separar TTL de reservas por `DisId` mediante `AUDIT_RESERVATION_TTL`.
- [CONFIRMADO] Mantener `AUDIT_IDEMPOTENCY_KEY_TTL` para la barrera HTTP de `/audit/async`.
- [CONFIRMADO] Actualizar fakes de tests que extienden `BatchJobStore`.
- [CONFIRMADO] Actualizar documentacion operacional y changelog.

#### Excluido

- [CONFIRMADO] No se modifica SQL Server ni tablas `AudDispEst` o `AdjuntosDispensacion`; la evidencia inspeccionada ubica el problema en Redis y Docker.
- [CONFIRMADO] No se cambia la logica de segmentacion Gemini que genero dos auditorias sin cierre en el diagnostico.
- [CONFIRMADO] No se cambia la logica de persistencia que fallo con adjuntos duplicados para `D12260600644`.
- [CONFIRMADO] No se modifica la telemetria async basada en `telemetry:async_metrics`; si existe en el worktree antes de implementar este SDD, debe preservarse sin redisenarla.
- [CONFIRMADO] No se modifica `ObservabilityController.php` ni `AuditEventConsumer.php` como parte de la ampliacion Redis/TTL.
- [CONFIRMADO] No se modifica `DocumentExtractionWorker.php`, `DocumentNormalizer.php` ni `DocumentPolicyEngine.php` para resolver segmentacion parcial como parte de este SDD.
- [CONFIRMADO] No se modifica `AuditStatusModel.php` para marcado de dispensacion auditada como parte de este SDD.
- [CONFIRMADO] No se implementa Redis Sentinel ni Redis Cluster.
- [CONFIRMADO] No se purgan streams ni se ejecutan comandos destructivos en Redis como parte de esta especificacion.
- [CONFIRMADO] No se modifica el limite `AUDIT_BATCH_MAX_LIMIT=100`.

### 3. Non Goals

- [CONFIRMADO] No convertir Redis en una base de datos historica permanente.
- [CONFIRMADO] No reemplazar Redis Streams por otra cola.
- [CONFIRMADO] No redisenar el pipeline `audit_created -> document_registered -> document_extracted -> document_normalized -> rules_evaluated -> audit_completed`.
- [CONFIRMADO] No absorber cambios paralelos del worktree dentro del alcance Redis/TTL; telemetria async, segmentacion parcial y marcado SQL deben revisarse en tareas separadas.
- [CONFIRMADO] No agregar soporte multi-nodo Redis en este cambio.
- [CONFIRMADO] No cambiar credenciales, secretos ni contenido de `.env` real versionado.

### 4. Estado Actual

- [CONFIRMADO] La arquitectura actual ejecuta Redis como contenedor `redis:7-alpine` dentro de Docker Compose.
- [CONFIRMADO] En desarrollo, `docker-compose.yml:40` arranca Redis con `--maxmemory 256mb --maxmemory-policy volatile-lru`.
- [CONFIRMADO] En desarrollo, `docker-compose.yml:51` limita el contenedor Redis a `memory: 300M`.
- [CONFIRMADO] En produccion, `docker-compose.prod.yml:56` y `docker-compose.prod.yml:58` arrancan Redis con `--maxmemory 256mb --maxmemory-policy volatile-lru`.
- [CONFIRMADO] En produccion, `docker-compose.prod.yml:70` limita el contenedor Redis a `memory: 300M`.
- [CONFIRMADO] `BatchJobStore` guarda `job:{jobId}:state` con TTL `86400`.
- [CONFIRMADO] `BatchJobStore` refresca el TTL del job al registrar auditorias, aplicar parches, marcar auditorias terminales y reclamar evento terminal de batch.
- [CONFIRMADO] `AuditStateStore` guarda `audit:{auditId}:state` con TTL `86400`.
- [CONFIRMADO] `AuditStateStore` refresca el TTL de auditoria en transiciones de documentos, rules evaluation, completion y telemetria.
- [CONFIRMADO] `DocumentExtractionWorker` usa `AUDIT_EXTRACTION_CACHE_TTL` para cache documental.
- [CONFIRMADO] `core/Cache` usa `AUDIT_CACHE_TTL` para cache de hash de dispensacion.
- [CONFIRMADO] Los endpoints `/audit/jobs/{jobId}` y `/audit/status/{auditId}` dependen de estado Redis transitorio.
- [CONFIRMADO] La evidencia remota mostro `audit.documents pending: 103` y `audit.results pending: 14` durante el diagnostico, aunque `/metrics/async` reporto ceros.
- [CONFIRMADO] El worktree actual ya contiene cambios en `BatchJobStore.php` para telemetria `telemetry:async_metrics`; la implementacion Redis/TTL debe hacer merge sobre ese estado y no revertir esos hunks.
- [CONFIRMADO] El worktree actual ya contiene cambios fuera de alcance en segmentacion documental, observabilidad y persistencia SQL; la implementacion Redis/TTL no debe agregar nuevos hunks funcionales en esos archivos.

### 5. Estado Objetivo

- [INFERIDO] Redis arrancara con `REDIS_MAXMEMORY=4gb` por defecto en desarrollo y produccion.
- [INFERIDO] Redis mantendra politica `volatile-lru` como default mediante `REDIS_MAXMEMORY_POLICY=volatile-lru`.
- [INFERIDO] El contenedor Redis tendra limite `REDIS_CONTAINER_MEMORY=5G` por defecto.
- [INFERIDO] `BatchJobStore` resolvera el TTL de jobs desde `AUDIT_JOB_TTL`, con fallback `604800`.
- [INFERIDO] `AuditStateStore` resolvera el TTL de auditorias desde `AUDIT_STATE_TTL`, con fallback `604800`.
- [INFERIDO] `BatchJobStore::claimAuditReservation` resolvera reservas por `DisId` desde `AUDIT_RESERVATION_TTL`, con fallback `86400`.
- [CONFIRMADO] `AUDIT_IDEMPOTENCY_KEY_TTL` seguira siendo leido por `AuditController` para la llave `X-Idempotency-Key`.
- [INFERIDO] `.env.example` documentara `REDIS_MAXMEMORY`, `REDIS_MAXMEMORY_POLICY`, `REDIS_CONTAINER_MEMORY`, `AUDIT_JOB_TTL`, `AUDIT_STATE_TTL` y `AUDIT_RESERVATION_TTL`.
- [INFERIDO] Los valores de cache `AUDIT_CACHE_TTL` y `AUDIT_EXTRACTION_CACHE_TTL` pasaran de `86400` a `604800` en `.env.example`.

### 6. Decisiones Arquitectonicas

| ID | Decision | Alternativas Rechazadas | Justificacion |
| --- | --- | --- | --- |
| ADR-001 | [INFERIDO] Parametrizar `maxmemory` y limite de contenedor por variables de entorno. | Hardcodear `4gb` y `5G` en Compose. | [CONFIRMADO] El proyecto ya usa variables `.env` para runtime y workers; `audfact-runtime-docker` exige escalar por variables. |
| ADR-002 | [INFERIDO] Usar default `604800` segundos para jobs, auditorias y caches. | Mantener `86400`; usar TTL ilimitado. | [CONFIRMADO] El usuario solicito mas de 24h; [INFERIDO] 7 dias permite re-auditoria sin retencion indefinida. |
| ADR-003 | [INFERIDO] Separar `AUDIT_RESERVATION_TTL` del TTL de job. | Reusar `AUDIT_JOB_TTL` para reservas por `DisId`. | [CONFIRMADO] `claimAuditReservation` usa hoy el TTL de job; [INFERIDO] subirlo a 7 dias bloquearia re-auditorias por mas tiempo ante fallos. |
| ADR-004 | [CONFIRMADO] Mantener `AUDIT_IDEMPOTENCY_KEY_TTL=300`. | Elevar idempotencia HTTP a 7 dias. | [CONFIRMADO] La variable se usa para `X-Idempotency-Key`, no para retencion de jobs. |
| ADR-005 | [CONFIRMADO] No modificar SQL Server. | Agregar persistencia historica de jobs en SQL. | [CONFIRMADO] La solicitud se enfoca en Redis y hardware; no hay requerimiento de tabla nueva. |
| ADR-006 | [INFERIDO] Mantener `volatile-lru` en esta iteracion. | Cambiar a `noeviction` o `allkeys-lru`. | [CONFIRMADO] La politica actual es `volatile-lru`; [INFERIDO] cambiar politica alteraria comportamiento operativo mas alla de la ampliacion solicitada. |

### 7. Dependencias

| Dependencia | Tipo | Version | Impacto |
| --- | --- | --- | --- |
| Redis | cola / cache / infraestructura | [CONFIRMADO] `redis:7-alpine` | [CONFIRMADO] Requiere cambio de memoria y variables Compose. |
| Docker Compose | infraestructura | [DESCONOCIDO] Version exacta no inventariada en esta especificacion | [CONFIRMADO] Ejecuta `docker-compose.yml` y `docker-compose.prod.yml`. |
| PHP | libreria / runtime | [CONFIRMADO] PHP 8.2 por lineamientos del repo y health remoto `8.2.31` | [CONFIRMADO] Ejecuta `BatchJobStore` y `AuditStateStore`. |
| `Core\Env` | componente interno | [CONFIRMADO] Sin version | [CONFIRMADO] Lee variables mediante `Env::get`; `core/Env.php` mantiene cache por proceso. |
| SQL Server | base de datos | [CONFIRMADO] Externo via PDO `sqlsrv` | [CONFIRMADO] Sin cambio en este SDD. |
| GitHub Actions self-hosted runner | infraestructura | [DESCONOCIDO] Version exacta | [INFERIDO] El despliegue normal debe publicar imagenes y recrear Redis via runner LAN. |

### 8. Invariantes

| Invariante | Enforcement | Validacion |
| --- | --- | --- |
| [CONFIRMADO] No imprimir secretos en logs ni documentacion. | No incluir valores de `.env` real; documentar solo nombres de variables no sensibles. | Revision manual del diff y ausencia de `DB_PASS`, `GEMINI_API_KEY`, `REDIS_PASSWORD` con valor real. |
| [CONFIRMADO] `XACK` ocurre solo despues de exito. | La implementacion Redis/TTL no modifica semantica de `AuditEventConsumer`; cambios preexistentes de telemetria en ese archivo se tratan como fuera de alcance. | Revision de diff Redis/TTL confirma que no cambia ack, retry, DLQ ni orden de publicacion de eventos. |
| [CONFIRMADO] La telemetria async preexistente se preserva. | En `BatchJobStore.php`, cambiar TTL sin eliminar `hIncrBy('telemetry:async_metrics', ...)` agregado en el worktree actual. | Revision de diff confirma que los contadores `jobs_queued`, `jobs_running`, `jobs_completed` y `jobs_failed` siguen presentes despues de aplicar TTL. |
| [CONFIRMADO] Redis conserva prefijo `REDIS_PREFIX=audfact:`. | No modificar `core/RedisClient.php` ni `.env.example:106` salvo documentacion aditiva. | `rg -n "REDIS_PREFIX=audfact:" .env.example`. |
| [CONFIRMADO] SQL Server permanece externo a Docker. | No agregar servicio SQL Server en Compose. | `docker compose config` no contiene servicio `sqlserver` ni `mssql`. |
| [CONFIRMADO] Cache y estados Redis siguen teniendo TTL finito. | Validar que las variables TTL acepten solo enteros positivos y tengan fallback finito. | Tests unitarios de resolucion TTL y revision de codigo. |

### 9. Modelo de Datos

[CONFIRMADO] Sin impacto en persistencia SQL Server. La evidencia local indica que los cambios afectan configuracion Docker, variables de entorno y llaves Redis transitorias.

#### DDL

```sql
-- [CONFIRMADO] No hay DDL para este cambio.
```

#### Orden de Ejecucion

1. [CONFIRMADO] No ejecutar migraciones SQL.
2. [CONFIRMADO] Aplicar cambios de codigo y configuracion.
3. [CONFIRMADO] Reconstruir y desplegar imagenes segun flujo normal.
4. [CONFIRMADO] Recrear el servicio Redis para tomar nuevos flags de `redis-server`.

#### Migracion de Datos

| Origen | Transformacion | Destino | Validacion |
| --- | --- | --- | --- |
| [CONFIRMADO] No aplica SQL. | [CONFIRMADO] No aplica. | [CONFIRMADO] No aplica. | [CONFIRMADO] No ejecutar DDL ni DML. |
| [INFERIDO] Volumen Docker `redis_data` existente. | [INFERIDO] Redis conserva persistencia RDB configurada con `--save 60 1000`; recrear contenedor conserva volumen. | [INFERIDO] Mismo volumen `redis_data`. | `docker volume inspect` y `docker exec audfact-redis redis-cli DBSIZE` despues del deploy. |

#### Rollback

```sql
-- [CONFIRMADO] No hay rollback SQL para este cambio.
```

### 10. Contratos

#### Antes

##### Contrato Docker Redis desarrollo

```yaml
redis:
  image: redis:7-alpine
  command: redis-server --maxmemory 256mb --maxmemory-policy volatile-lru --save 60 1000 --requirepass ${REDIS_PASSWORD:-audfact_dev_default}
  deploy:
    resources:
      limits:
        memory: 300M
```

##### Contrato Docker Redis produccion

```yaml
redis:
  image: redis:7-alpine
  command:
    - sh
    - -c
    - |
      if [ -n "$$REDIS_PASSWORD" ]; then
        exec redis-server --maxmemory 256mb --maxmemory-policy volatile-lru --save 60 1000 --requirepass "$$REDIS_PASSWORD"
      fi
      exec redis-server --maxmemory 256mb --maxmemory-policy volatile-lru --save 60 1000
  deploy:
    resources:
      limits:
        memory: 300M
```

##### Contrato TTL Redis

| Llave / Uso | Antes | Evidencia |
| --- | --- | --- |
| `job:{jobId}:state` | [CONFIRMADO] `86400` segundos fijo | `BatchJobStore.php:22,63,112,131,169,186` |
| `audit:{auditId}:state` | [CONFIRMADO] `86400` segundos fijo | `AuditStateStore.php:29,69,97,121,132,188,208,219,230,247` |
| `audit:reservation:disid:{disId}` | [CONFIRMADO] `86400` segundos por default heredado del TTL de job | `BatchJobStore.php:243` |
| `batch:idem:{key}` | [CONFIRMADO] `AUDIT_IDEMPOTENCY_KEY_TTL`, default `300` | `AuditController.php:365`; `.env.example:134` |
| Cache hash auditoria | [CONFIRMADO] `AUDIT_CACHE_TTL`, default `86400` | `core/Cache.php:218`; `.env.example:147` |
| Cache extraccion documental | [CONFIRMADO] `AUDIT_EXTRACTION_CACHE_TTL`, default `86400` | `DocumentExtractionWorker.php:29,63-64`; `.env.example:149` |

#### Despues

##### Contrato Docker Redis desarrollo

```yaml
redis:
  image: redis:7-alpine
  environment:
    REDIS_MAXMEMORY: ${REDIS_MAXMEMORY:-4gb}
    REDIS_MAXMEMORY_POLICY: ${REDIS_MAXMEMORY_POLICY:-volatile-lru}
  command: redis-server --maxmemory ${REDIS_MAXMEMORY:-4gb} --maxmemory-policy ${REDIS_MAXMEMORY_POLICY:-volatile-lru} --save 60 1000 --requirepass ${REDIS_PASSWORD:-audfact_dev_default}
  deploy:
    resources:
      limits:
        memory: ${REDIS_CONTAINER_MEMORY:-5G}
```

##### Contrato Docker Redis produccion

```yaml
redis:
  image: redis:7-alpine
  environment:
    REDIS_PASSWORD: ${REDIS_PASSWORD:-}
    REDIS_MAXMEMORY: ${REDIS_MAXMEMORY:-4gb}
    REDIS_MAXMEMORY_POLICY: ${REDIS_MAXMEMORY_POLICY:-volatile-lru}
  command:
    - sh
    - -c
    - |
      if [ -n "$$REDIS_PASSWORD" ]; then
        exec redis-server --maxmemory "$${REDIS_MAXMEMORY:-4gb}" --maxmemory-policy "$${REDIS_MAXMEMORY_POLICY:-volatile-lru}" --save 60 1000 --requirepass "$$REDIS_PASSWORD"
      fi
      exec redis-server --maxmemory "$${REDIS_MAXMEMORY:-4gb}" --maxmemory-policy "$${REDIS_MAXMEMORY_POLICY:-volatile-lru}" --save 60 1000
  deploy:
    resources:
      limits:
        memory: ${REDIS_CONTAINER_MEMORY:-5G}
```

##### Contrato TTL Redis

| Llave / Uso | Despues | Compatibilidad |
| --- | --- | --- |
| `job:{jobId}:state` | [INFERIDO] `AUDIT_JOB_TTL`, default `604800` | [CONFIRMADO] Compatible con consumidores existentes porque cambia TTL, no estructura JSON. |
| `audit:{auditId}:state` | [INFERIDO] `AUDIT_STATE_TTL`, default `604800` | [CONFIRMADO] Compatible con consumidores existentes porque cambia TTL, no estructura JSON. |
| `audit:reservation:disid:{disId}` | [INFERIDO] `AUDIT_RESERVATION_TTL`, default `86400` | [INFERIDO] Compatible con comportamiento actual de reserva de 24h. |
| `batch:idem:{key}` | [CONFIRMADO] `AUDIT_IDEMPOTENCY_KEY_TTL`, default `300` | [CONFIRMADO] Sin cambio. |
| Cache hash auditoria | [INFERIDO] `AUDIT_CACHE_TTL`, default documentado `604800` | [CONFIRMADO] Compatible porque `core/Cache.php` ya lee la variable. |
| Cache extraccion documental | [INFERIDO] `AUDIT_EXTRACTION_CACHE_TTL`, default documentado `604800` | [CONFIRMADO] Compatible porque `DocumentExtractionWorker` ya lee la variable. |

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementacion | Validacion |
| --- | --- | --- | --- |
| R1 | [CONFIRMADO] Redis debe tener mas capacidad que 256 MiB. | Parametrizar `REDIS_MAXMEMORY` y default `4gb` en Compose. | `docker exec audfact-redis redis-cli INFO memory` muestra `maxmemory_human:4.00G`. |
| R2 | [CONFIRMADO] El contenedor Redis no debe quedar limitado a 300 MiB. | Parametrizar `REDIS_CONTAINER_MEMORY` y default `5G`. | `docker stats audfact-redis --no-stream` muestra limite aproximado `5GiB`. |
| R3 | [CONFIRMADO] Jobs Redis deben durar mas de 24h. | `BatchJobStore` usa `AUDIT_JOB_TTL=604800`. | Crear job de prueba y ejecutar `TTL audfact:job:{id}:state`, valor entre `604700` y `604800` despues de creacion. |
| R4 | [CONFIRMADO] Estados de auditoria deben durar mas de 24h. | `AuditStateStore` usa `AUDIT_STATE_TTL=604800`. | Crear auditoria de prueba y ejecutar `TTL audfact:audit:{id}:state`, valor entre `604700` y `604800`. |
| R5 | [CONFIRMADO] Re-auditorias mayores a 24h deben aprovechar cache. | `.env.example` documenta `AUDIT_CACHE_TTL=604800` y `AUDIT_EXTRACTION_CACHE_TTL=604800`. | Repetir auditoria dentro de 7 dias y verificar cache hit en logs de extraccion cuando el documento, contrato y contexto no cambian. |
| R6 | [INFERIDO] Reservas por `DisId` no deben bloquear 7 dias por default. | Agregar `AUDIT_RESERVATION_TTL=86400` y usarlo en `claimAuditReservation`. | Test unitario valida que llamada sin TTL usa `86400` cuando no existe env. |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| Docker Compose dev | Redis | [CONFIRMADO] Aumenta memoria configurada de Redis. | Modificar `docker-compose.yml`. | `docker-compose.yml:34-51`. |
| Docker Compose prod | Redis | [CONFIRMADO] Aumenta memoria configurada de Redis. | Modificar `docker-compose.prod.yml`. | `docker-compose.prod.yml:44-70`. |
| `.env.example` | Runtime config | [CONFIRMADO] Agrega variables y nuevos defaults. | Modificar seccion Redis/cache. | `.env.example:98,106,134,147-149`. |
| `BatchJobStore` | RedisClient / Env | [CONFIRMADO] Cambia TTL de jobs y reservas; debe conservar la telemetria async preexistente en el worktree. | Agregar resolutores de TTL, reemplazar constante fija en escrituras y preservar `telemetry:async_metrics`. | `BatchJobStore.php:22,63,112,131,169,186,243`; `git diff -- app/Services/Audit/Pipeline/BatchJobStore.php`. |
| `AuditStateStore` | RedisClient / Env | [CONFIRMADO] Cambia TTL de auditorias. | Agregar resolutor de TTL y reemplazar constante fija en escrituras. | `AuditStateStore.php:29,69,97,121,132,188,208,219,230,247`. |
| `DocumentExtractionWorker` | Redis cache | [CONFIRMADO] No requiere codigo si solo cambia `.env.example`; ya lee variable. | No modificar codigo. | `DocumentExtractionWorker.php:63-64`. |
| `core/Cache` | Redis cache | [CONFIRMADO] No requiere codigo si solo cambia `.env.example`; ya lee variable. | No modificar codigo. | `core/Cache.php:218`. |
| Tests de controlador/batch | Fakes `BatchJobStore` | [CONFIRMADO] Firma puede requerir ajuste si `claimAuditReservation` acepta `?int`. | Modificar fakes y agregar tests TTL. | `tests/Controllers/AuditControllerTest.php:498`; `tests/Services/Audit/AuditBatchOrchestratorTest.php:121`. |
| Produccion LAN | Docker runtime | [CONFIRMADO] Requiere recreate de Redis para nuevos flags. | Deploy normal via GitHub Actions o `docker compose up -d` autorizado. | Skill `audfact-production-ops` exige aprobacion para `docker compose up`. |
| `ObservabilityController` | Telemetria async | [CONFIRMADO] Cambio preexistente fuera del alcance Redis/TTL. | No modificar como parte de este SDD. | `git diff -- app/Controllers/ObservabilityController.php`. |
| `AuditEventConsumer` | Retry / DLQ / telemetria async | [CONFIRMADO] Cambio preexistente fuera del alcance Redis/TTL. | No modificar ack, retry, DLQ ni telemetria como parte de este SDD. | `git diff -- app/Services/Audit/Pipeline/AuditEventConsumer.php`. |
| `DocumentExtractionWorker`, `DocumentNormalizer`, `DocumentPolicyEngine` | Pipeline Gemini | [CONFIRMADO] Cambios preexistentes de segmentacion parcial fuera del alcance Redis/TTL. | No modificar estos archivos para implementar Redis/TTL; el unico efecto de cache es el valor documentado en `.env.example`. | `git diff -- app/Services/Audit/Pipeline/DocumentExtractionWorker.php app/Services/Audit/Pipeline/DocumentNormalizer.php app/Services/Audit/Pipeline/DocumentPolicyEngine.php`. |
| `AuditStatusModel` | Persistencia SQL Server | [CONFIRMADO] Cambio preexistente fuera del alcance Redis/TTL. | No modificar como parte de este SDD. | `git diff -- app/Models/AuditStatusModel.php`. |

### 13. Cambios por Archivo

#### [MODIFY] `docker-compose.yml`

- [CONFIRMADO] Clase afectada: no aplica.
- [CONFIRMADO] Funciones afectadas: no aplica.
- [CONFIRMADO] Lineas aproximadas: `34-51`.
- [CONFIRMADO] Antes: Redis usa `--maxmemory 256mb`, `--maxmemory-policy volatile-lru` y `memory: 300M`.
- [INFERIDO] Despues: Redis usa `${REDIS_MAXMEMORY:-4gb}`, `${REDIS_MAXMEMORY_POLICY:-volatile-lru}` y `${REDIS_CONTAINER_MEMORY:-5G}`.

#### [MODIFY] `docker-compose.prod.yml`

- [CONFIRMADO] Clase afectada: no aplica.
- [CONFIRMADO] Funciones afectadas: no aplica.
- [CONFIRMADO] Lineas aproximadas: `44-70`.
- [CONFIRMADO] Antes: Redis usa shell command con `--maxmemory 256mb` en rama con password y sin password.
- [INFERIDO] Despues: ambas ramas usan `"$${REDIS_MAXMEMORY:-4gb}"` y `"$${REDIS_MAXMEMORY_POLICY:-volatile-lru}"`.

#### [MODIFY] `.env.example`

- [CONFIRMADO] Clase afectada: no aplica.
- [CONFIRMADO] Funciones afectadas: no aplica.
- [CONFIRMADO] Lineas aproximadas: `104-117`, `134`, `147-149`.
- [INFERIDO] Agregar `REDIS_MAXMEMORY=4gb`.
- [INFERIDO] Agregar `REDIS_MAXMEMORY_POLICY=volatile-lru`.
- [INFERIDO] Agregar `REDIS_CONTAINER_MEMORY=5G`.
- [INFERIDO] Agregar `AUDIT_JOB_TTL=604800`.
- [INFERIDO] Agregar `AUDIT_STATE_TTL=604800`.
- [INFERIDO] Agregar `AUDIT_RESERVATION_TTL=86400`.
- [INFERIDO] Cambiar `AUDIT_CACHE_TTL=86400` a `AUDIT_CACHE_TTL=604800`.
- [INFERIDO] Cambiar `AUDIT_EXTRACTION_CACHE_TTL=86400` a `AUDIT_EXTRACTION_CACHE_TTL=604800`.

#### [MODIFY] `app/Services/Audit/Pipeline/BatchJobStore.php`

- [CONFIRMADO] Clase afectada: `BatchJobStore`.
- [CONFIRMADO] Metodos afectados: `initJob`, `registerAuditInJob`, `patchJob`, `markAuditCompletedInJob`, `claimBatchTerminalEvent`, `claimAuditReservation`.
- [INFERIDO] Agregar `private const DEFAULT_JOB_TTL_SECONDS = 604800`.
- [INFERIDO] Agregar `private const DEFAULT_RESERVATION_TTL_SECONDS = 86400`.
- [INFERIDO] Agregar `private static function jobTtlSeconds(): int`.
- [INFERIDO] Agregar `private static function reservationTtlSeconds(): int`.
- [INFERIDO] Agregar helper privado para leer enteros positivos desde `\Core\Env::get`.
- [INFERIDO] Cambiar `claimAuditReservation(string $disId, string $ownerToken, array $reservation, int $ttl = self::JOB_TTL_SECONDS)` a `claimAuditReservation(string $disId, string $ownerToken, array $reservation, ?int $ttl = null)`.
- [INFERIDO] La implementacion usara `$ttl ?? self::reservationTtlSeconds()` para reservas.
- [CONFIRMADO] Preservar los cambios preexistentes de telemetria `telemetry:async_metrics` en `initJob` y en el script Lua de transicion de estado.
- [CONFIRMADO] No agregar contadores nuevos ni cambiar nombres de campos de telemetria en este SDD.

#### [MODIFY] `app/Services/Audit/Pipeline/AuditStateStore.php`

- [CONFIRMADO] Clase afectada: `AuditStateStore`.
- [CONFIRMADO] Metodos afectados: `initAudit`, `patchAudit`, `markAuditStarted`, `registerDocument`, `markDocumentRejected`, `markDocumentTransition`, `storeRulesEvaluation`, `completeAudit`, `recordEventTelemetry`.
- [INFERIDO] Agregar `private const DEFAULT_AUDIT_TTL_SECONDS = 604800`.
- [INFERIDO] Agregar `private static function auditTtlSeconds(): int`.
- [INFERIDO] Reemplazar cada uso de `self::AUDIT_TTL_SECONDS` por `self::auditTtlSeconds()`.

#### [MODIFY] `tests/Controllers/AuditControllerTest.php`

- [CONFIRMADO] Clase afectada: `StubBatchJobStore`.
- [CONFIRMADO] Metodo afectado: `claimAuditReservation`.
- [INFERIDO] Actualizar firma a `?int $ttl = null` si la firma de produccion cambia.

#### [MODIFY] `tests/Services/Audit/AuditBatchOrchestratorTest.php`

- [CONFIRMADO] Clase afectada: `BatchOrchestratorJobStore`.
- [CONFIRMADO] Metodo afectado: `claimAuditReservation`.
- [INFERIDO] Actualizar firma a `?int $ttl = null` si la firma de produccion cambia.

#### [NEW] `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php`

- [INFERIDO] Objetivo: validar defaults y overrides de TTL sin usar Redis real.
- [INFERIDO] Si las clases actuales no permiten observar TTL sin Redis real, crear tests sobre helpers expuestos con visibilidad `public static` no esta autorizado por esta especificacion; en ese caso validar TTL mediante tests de integracion Redis existentes o fakes que capturen argumentos.

#### [MODIFY] `plans/docker-operations.md`

- [INFERIDO] Documentar variables `REDIS_MAXMEMORY`, `REDIS_MAXMEMORY_POLICY`, `REDIS_CONTAINER_MEMORY` y comandos de verificacion `INFO memory`.

#### [MODIFY] `plans/architecture-executive-report.md`

- [CONFIRMADO] Este archivo documenta TTL de 24 horas en `plans/architecture-executive-report.md:416,742-746`.
- [INFERIDO] Actualizar tabla de TTL a 7 dias para caches, jobs y estados.

#### [MODIFY] `.agent/skills/audfact-runtime-docker/SKILL.md`

- [INFERIDO] Documentar nuevas variables Redis de runtime.

#### [MODIFY] `.agent/skills/audfact-audit-gemini/SKILL.md`

- [INFERIDO] Documentar `AUDIT_JOB_TTL`, `AUDIT_STATE_TTL` y `AUDIT_RESERVATION_TTL`.

#### [MODIFY] `plans/changelog.md`

- [INFERIDO] Registrar el cambio bajo fecha 2026-06-18 cuando se implemente.

### 14. Plan de Migracion

#### Prerequisitos

1. [CONFIRMADO] Obtener aprobacion explicita del usuario para cambios de codigo y runtime.
2. [CONFIRMADO] Crear checkpoint antes de modificar archivos significativos.
3. [CONFIRMADO] Verificar estado actual: `docker compose -f docker-compose.prod.yml ps`.
4. [CONFIRMADO] Verificar health: `curl -sf http://localhost:8080/health`.
5. [INFERIDO] Registrar metricas base Redis: `INFO memory`, `INFO stats`, `DBSIZE`, `XLEN audfact:audit.documents`, `XLEN audfact:audit.results`.

#### Ejecucion

1. [CONFIRMADO] Modificar archivos locales definidos en "Cambios por Archivo".
2. [CONFIRMADO] Ejecutar validaciones locales de sintaxis y tests focalizados.
3. [CONFIRMADO] Actualizar documentacion y skills afectadas.
4. [INFERIDO] Publicar imagenes mediante workflow normal de CI/CD.
5. [CONFIRMADO] En produccion, recrear Redis mediante despliegue aprobado para aplicar flags `redis-server`.
6. [CONFIRMADO] No editar codigo dentro del contenedor.

#### Validaciones Previas

1. [CONFIRMADO] `php -l app/Services/Audit/Pipeline/BatchJobStore.php`.
2. [CONFIRMADO] `php -l app/Services/Audit/Pipeline/AuditStateStore.php`.
3. [CONFIRMADO] `docker compose -f docker-compose.prod.yml config`.
4. [CONFIRMADO] `php vendor/bin/phpunit tests/Controllers/AuditControllerTest.php --no-coverage`.
5. [CONFIRMADO] `php vendor/bin/phpunit tests/Services/Audit/AuditBatchOrchestratorTest.php --no-coverage`.
6. [INFERIDO] Ejecutar nuevo test TTL si se agrega archivo dedicado.

#### Validaciones Posteriores

1. [CONFIRMADO] `curl -sf http://localhost:8080/health` responde HTTP 200.
2. [CONFIRMADO] `docker exec audfact-redis redis-cli INFO memory` muestra `maxmemory_human` cercano a `4.00G`.
3. [CONFIRMADO] `docker stats audfact-redis --no-stream` muestra limite cercano a `5GiB`.
4. [CONFIRMADO] Crear job async de prueba y verificar que `/audit/jobs/{jobId}` responde 200 durante la ventana inicial.
5. [INFERIDO] Ejecutar `TTL audfact:job:{jobId}:state`; el valor inicial debe ser mayor a `604000`.
6. [INFERIDO] Ejecutar `TTL audfact:audit:{auditId}:state`; el valor inicial debe ser mayor a `604000`.
7. [INFERIDO] Verificar que `evicted_keys` no aumenta durante un batch de prueba de 100 auditorias.

#### Rollback

1. [CONFIRMADO] Revertir los cambios de Compose a `--maxmemory 256mb` y `memory: 300M` mediante rollback de imagen/configuracion.
2. [CONFIRMADO] Revertir `AUDIT_JOB_TTL`, `AUDIT_STATE_TTL`, `AUDIT_CACHE_TTL` y `AUDIT_EXTRACTION_CACHE_TTL` a `86400`.
3. [CONFIRMADO] Revertir `AUDIT_RESERVATION_TTL` a comportamiento anterior solo si se revierte la firma y tests asociados.
4. [CONFIRMADO] Recrear servicio Redis con aprobacion explicita.
5. [CONFIRMADO] Validar `/health` y `INFO memory` despues del rollback.

### 15. Casos Limite

| Condicion | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| [CONFIRMADO] Variable TTL ausente. | [INFERIDO] El codigo usa default finito: job/audit/cache 604800, reserva 86400. | Test sin env valida TTL default. |
| [INFERIDO] Variable TTL con `0`, negativo o texto no numerico. | [INFERIDO] El codigo usa default finito correspondiente. | Test con `putenv('AUDIT_JOB_TTL=0')` y `putenv('AUDIT_JOB_TTL=abc')`. |
| [CONFIRMADO] Redis password vacia en produccion. | [CONFIRMADO] `docker-compose.prod.yml` ya soporta rama sin `--requirepass`. | `docker compose -f docker-compose.prod.yml config` conserva rama shell. |
| [CONFIRMADO] Redis password presente en produccion. | [INFERIDO] La rama con password usa las mismas variables de memoria. | `docker exec audfact-redis redis-cli -a "$REDIS_PASSWORD" INFO memory`. |
| [INFERIDO] Batch con 100 auditorias y 3 documentos por auditoria. | [INFERIDO] Redis no debe evictar llaves durante el batch con `4gb`. | `evicted_keys` antes y despues no aumenta. |
| [CONFIRMADO] Job consultado despues de 24h y antes de 7 dias. | [INFERIDO] `/audit/jobs/{jobId}` responde con estado si Redis no fue reiniciado sin persistencia y la llave no fue evictada. | Prueba operacional programada o TTL observado mayor al tiempo restante. |
| [CONFIRMADO] Auditoria falla y reserva por `DisId` no se libera. | [INFERIDO] La reserva expira a las 24h por `AUDIT_RESERVATION_TTL`, no a los 7 dias. | `TTL audfact:audit:reservation:disid:{disId}` cercano a `86400`. |
| [DESCONOCIDO] Redis supera `4gb` por streams sin trim. | [INFERIDO] La politica `volatile-lru` puede evictar llaves con TTL; se debe abrir un cambio posterior de retencion de streams si ocurre. | `INFO memory` y `XLEN` crecen hasta umbral operativo definido. |

### 16. Testing

#### Nuevos Tests

| Test | Objetivo | Precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- | --- |
| `RedisTtlConfigTest::testBatchJobTtlDefaultIsSevenDays` | [INFERIDO] Validar default `AUDIT_JOB_TTL`. | Env `AUDIT_JOB_TTL` sin valor. | Instanciar store con Redis fake que capture TTL de `setnx` o ejecutar contra Redis test. | TTL capturado igual a `604800`. |
| `RedisTtlConfigTest::testBatchJobTtlRejectsInvalidValues` | [INFERIDO] Validar fallback ante valores invalidos. | Env `AUDIT_JOB_TTL=0`, `-1`, `abc`. | Ejecutar creacion de job para cada valor. | TTL usado igual a `604800`. |
| `RedisTtlConfigTest::testAuditStateTtlDefaultIsSevenDays` | [INFERIDO] Validar default `AUDIT_STATE_TTL`. | Env `AUDIT_STATE_TTL` sin valor. | Crear auditoria con Redis fake o Redis test. | TTL usado igual a `604800`. |
| `RedisTtlConfigTest::testReservationTtlDefaultStaysOneDay` | [INFERIDO] Validar default `AUDIT_RESERVATION_TTL`. | Env `AUDIT_RESERVATION_TTL` sin valor. | Llamar `claimAuditReservation` sin cuarto parametro. | TTL usado igual a `86400`. |

#### Tests Modificados

| Test | Objetivo | Precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- | --- |
| `tests/Controllers/AuditControllerTest.php` | [CONFIRMADO] Mantener compatibilidad de fake `StubBatchJobStore`. | Firma de `claimAuditReservation` cambia a `?int $ttl = null`. | Actualizar firma del fake. | Suite del controlador pasa. |
| `tests/Services/Audit/AuditBatchOrchestratorTest.php` | [CONFIRMADO] Mantener compatibilidad de fake `BatchOrchestratorJobStore`. | Firma de `claimAuditReservation` cambia a `?int $ttl = null`. | Actualizar firma del fake. | Suite del orquestador pasa. |

#### Tests Eliminados

| Test | Motivo | Cobertura de reemplazo |
| --- | --- | --- |
| [CONFIRMADO] Ningun test debe eliminarse. | [CONFIRMADO] El cambio es aditivo y parametrico. | [CONFIRMADO] Tests existentes mas tests TTL nuevos. |

#### Verificaciones Manuales

| Verificacion | Objetivo | Precondiciones | Pasos | Resultado esperado |
| --- | --- | --- | --- | --- |
| `docker compose config` | [CONFIRMADO] Validar YAML final. | Cambios aplicados localmente. | Ejecutar `docker compose -f docker-compose.prod.yml config`. | Comando termina con exit code 0. |
| `INFO memory` | [CONFIRMADO] Validar memoria Redis efectiva. | Deploy aplicado. | Ejecutar `redis-cli INFO memory`. | `maxmemory_human` muestra `4.00G` o valor configurado. |
| `TTL job state` | [CONFIRMADO] Validar retencion de job. | Job async creado. | Ejecutar `TTL audfact:job:{jobId}:state`. | TTL mayor a `604000` inmediatamente despues de creacion. |
| `TTL audit state` | [CONFIRMADO] Validar retencion de auditoria. | Auditoria creada. | Ejecutar `TTL audfact:audit:{auditId}:state`. | TTL mayor a `604000` inmediatamente despues de creacion. |
| `Diff fuera de alcance` | [CONFIRMADO] Validar que Redis/TTL no absorbe cambios paralelos. | Implementacion Redis/TTL aplicada sobre el worktree actual. | Revisar `git diff -- app/Controllers/ObservabilityController.php app/Services/Audit/Pipeline/AuditEventConsumer.php app/Services/Audit/Pipeline/DocumentExtractionWorker.php app/Services/Audit/Pipeline/DocumentNormalizer.php app/Services/Audit/Pipeline/DocumentPolicyEngine.php app/Models/AuditStatusModel.php`. | No aparecen hunks nuevos atribuibles a Redis/TTL; los cambios existentes siguen siendo trabajo paralelo. |

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigacion |
| --- | --- | --- | --- |
| [INFERIDO] Redis consume mas RAM por TTL de 7 dias. | rendimiento | Media | Configurar `REDIS_MAXMEMORY=4gb`, `REDIS_CONTAINER_MEMORY=5G` y monitorear `used_memory_peak`. |
| [CONFIRMADO] Streams Redis tienen backlog observado. | operativo | Alta | Mantener diagnostico de `XLEN` y abrir cambio posterior de trimming si `XLEN` crece sin control. |
| [INFERIDO] Subir TTL de reservas bloquearia re-auditorias si no se separa. | consistencia de datos | Alta | Implementar `AUDIT_RESERVATION_TTL=86400` separado de `AUDIT_JOB_TTL`. |
| [INFERIDO] Compose puede interpolar mal variables si se omiten comillas en shell productivo. | operativo | Media | Validar `docker compose -f docker-compose.prod.yml config` antes de deploy. |
| [CONFIRMADO] Redis recreate es accion con impacto. | operativo | Media | Ejecutar solo con aprobacion explicita y ventana controlada. |
| [INFERIDO] Cache de 7 dias puede reutilizar extracciones obsoletas si documentos cambian sin cambiar hash. | consistencia de datos | Media | Mantener clave de cache basada en hash documental y contrato; no cambiar mecanismo de hash en este SDD. |

### 18. Criterios de Aceptacion

1. [CONFIRMADO] `docker compose -f docker-compose.prod.yml config` termina con exit code 0.
2. [CONFIRMADO] `php -l app/Services/Audit/Pipeline/BatchJobStore.php` termina sin errores.
3. [CONFIRMADO] `php -l app/Services/Audit/Pipeline/AuditStateStore.php` termina sin errores.
4. [CONFIRMADO] Tests focalizados de controlador y orquestador terminan en verde.
5. [INFERIDO] Tests nuevos de TTL terminan en verde.
6. [CONFIRMADO] `INFO memory` en Redis productivo muestra `maxmemory_human` igual al valor configurado por `REDIS_MAXMEMORY`.
7. [CONFIRMADO] `docker stats audfact-redis --no-stream` muestra limite de memoria igual al valor configurado por `REDIS_CONTAINER_MEMORY`.
8. [INFERIDO] Un job nuevo tiene TTL inicial mayor a `604000` segundos en `audfact:job:{jobId}:state`.
9. [INFERIDO] Una auditoria nueva tiene TTL inicial mayor a `604000` segundos en `audfact:audit:{auditId}:state`.
10. [INFERIDO] Una reserva por `DisId` creada sin TTL explicito tiene TTL inicial menor o igual a `86400` y mayor a `86300`.
11. [CONFIRMADO] `/health` responde HTTP 200 despues del deploy.
12. [CONFIRMADO] La implementacion Redis/TTL no elimina los contadores preexistentes `telemetry:async_metrics` en `BatchJobStore.php`.
13. [CONFIRMADO] La implementacion Redis/TTL no introduce cambios funcionales nuevos en `ObservabilityController.php`, `AuditEventConsumer.php`, `DocumentExtractionWorker.php`, `DocumentNormalizer.php`, `DocumentPolicyEngine.php` ni `AuditStatusModel.php`.

## FASE 2 - Auditoria de Consistencia

| Verificacion | Estado | Evidencia |
| --- | --- | --- |
| Todas las tablas estan definidas | PASS | [CONFIRMADO] No hay cambios SQL; se declara sin impacto en persistencia. |
| Todas las columnas existen | PASS | [CONFIRMADO] No hay cambios de columnas. |
| Todos los contratos documentados | PASS | [CONFIRMADO] Se documentan contratos Docker Redis y contratos TTL Redis antes/despues. |
| Todos los requisitos tienen trazabilidad | PASS | [CONFIRMADO] Requisitos R1-R6 tienen implementacion y validacion. |
| Todos los consumidores analizados | PASS | [CONFIRMADO] Se analizan `AuditController`, `BatchJobStore`, `AuditStateStore`, `DocumentExtractionWorker`, `core/Cache`, tests/fakes y los cambios preexistentes fuera de alcance en `ObservabilityController`, `AuditEventConsumer`, `DocumentNormalizer`, `DocumentPolicyEngine` y `AuditStatusModel`. |
| Todas las migraciones tienen rollback | PASS | [CONFIRMADO] No hay migracion SQL; rollback operativo esta definido. |
| Todas las referencias estan definidas | PASS | [CONFIRMADO] Cada archivo citado existe en el repo local o corresponde al diagnostico remoto documentado. |
| Toda compatibilidad tiene evidencia | PASS | [CONFIRMADO] La estructura JSON Redis no cambia; solo TTL y flags de Redis cambian. |
| Todos los criterios son verificables | PASS | [CONFIRMADO] Cada criterio incluye comando, estado esperado o medicion TTL. |

## FASE 3 - Auditoria Arquitectonica

| Pregunta | Resultado |
| --- | --- |
| Existe alguna decision arquitectonica implicita? | No |
| Existe algun contrato sin documentar? | No |
| Existe algun consumidor no analizado? | No |
| Existe alguna migracion sin rollback? | No |
| Existe algun dato persistido sin migracion? | No |
| Existe alguna afirmacion sin evidencia? | No |
| Existen referencias huerfanas? | No |
| Dos implementadores producirian soluciones diferentes? | No |

## FASE 4 - Resultado Final

### Nivel de Completitud

[INFERIDO] Nivel B - Implementable con Supuestos Declarados.

### Definicion de Completitud

- [CONFIRMADO] La especificacion no requiere reuniones adicionales para implementar la primera ampliacion Redis y TTL.
- [CONFIRMADO] La especificacion no requiere aclaraciones adicionales si se acepta el supuesto S1 de 7 dias.
- [CONFIRMADO] La especificacion no requiere decisiones arquitectonicas posteriores para esta iteracion.
- [CONFIRMADO] La especificacion permite implementacion deterministica mediante cambios por archivo, contratos antes/despues y criterios medibles.
- [CONFIRMADO] La especificacion permite revision tecnica independiente con evidencia por archivo y linea aproximada.
- [CONFIRMADO] La especificacion permite validacion objetiva mediante pruebas y verificaciones operativas.
- [CONFIRMADO] La auditoria de consistencia obtiene `PASS` en todas las verificaciones.
- [CONFIRMADO] La auditoria arquitectonica obtiene `No` en todas las preguntas.
- [INFERIDO] La completitud no es Nivel A porque el volumen diario esperado y la ventana exacta de negocio no fueron confirmados; esos puntos estan contenidos por supuestos S1 y S2.
