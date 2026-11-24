# SDD-BATCH-000 (Fase 1): Hotfix de Mantenimiento de Ejecución

> **Versión:** 1.2 (revisión 2026-11-22; 1.1 = verificación cruzada del mismo día; 1.0 = redacción original 2026-11-18/19) · **Estado:** borrador sin implementar · ver `Registro de revisión ASEO (2026-11-22)`.
>
> **Documento relacionado (diagnóstico empírico externo):** `Diagnóstico batch NIT 2624.md` (hallazgos `H6–H11`, evidencias `E8–E15`, `E20`; línea base `main @ 3396897`). El diagnóstico se ejecutó en **Docker Compose local (WSL) contra `DiscolmetsGx2QA`**, no en producción: toda cifra suya se etiqueta `[CONFIRMADO en fuente externa]` y todo impacto productivo derivado es `[INFERIDO]`.
>
> **Relación con otras especificaciones:** esta Fase 1 es **prerrequisito** de `plans/sdd-estabilizacion-batch-y-mantenimiento.md` (**SDD-BATCH-001, Fase 2**). La Fase 1 se despliega **sin migración de esquema Redis**: no crea `job:{id}:audits`, `by_dispensa`, `candidates`, no congela SQL, no introduce fencing por epoch ni reserva por `DisDetNro`.
>
> **Convención de identificadores:** `H1–H11` solo con el significado del diagnóstico; requisitos `REQ-F1-xx`; decisiones `DEC-F1-xx`; invariantes `INV-F1-xx`; supuestos `SUP-F1-xx`; riesgos de revisión `R-*`; criterios `CA-F1-xx`; correcciones de revisión `V-F1-xx` (corrida v4s) y `W-F1-xx` (corrida w7k); hallazgos del diagnóstico de producción `P-F1-xx` (2026-11-19); correcciones de la tercera, cuarta y quinta pasada `X-F1-xx` / `R4-F1-xx` / `R5-F1-xx` (2026-11-19).

### Registro de revisión verificada (corrida v4s, 2026-11-18)

Correcciones aplicadas tras la verificación crítica contra `main` (17 hallazgos reales; `M4` pertenece a SDD-BATCH-001 y no se aplica aquí).

| ID | Defecto | Corrección |
| --- | --- | --- |
| V-F1-B1 | `gc()` no avanzaba `pattern_index` si `collectKey()` lanzaba en la última clave de la última página de un patrón → bucle de caída e inanición de los demás patrones | Bandera `$patternAdvanced`; `finally` avanza el índice si el patrón quedó agotado (y fija `cycle_started_at_ms` al volver a `0`); DEC-F1-02/04 ajustadas; nuevo T-I14 (CA-F1-03) |
| V-F1-B2 | `collectKey()` declaraba `: int` y la rama `jobs` «devolvía 0» con memoria ≥ 256 MiB, perdiendo la clave y sin marcar `gcBudgetStopped` | `collectKey(...): ?int`; `null` ⇒ `array_unshift`, `$items--`, `gcBudgetStopped = true`, `return` |
| V-F1-B3 | `pending_pattern` llegaba como string desde `HGETALL`; `=== $idx` (int) siempre falso bajo `strict_types` | `loadGcState()` valida `/^[0-6]$/` y castea `(int)`; `''` → `null`; inválido → `null` + descarta `pending_keys` + warning |
| V-F1-B4 | `intakeStream()` emitía una 9.ª `XREADGROUP` vacía (viola `≤ 8`); T-I10 no aislaba el grupo `policy` | Corte tras ronda con `count < INTAKE_READ_COUNT`; T-I10 cuenta solo llamadas del grupo `extractors` |
| V-F1-B5 | `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` ausente del perímetro; T-I7/CA-F1-07 exigían exactamente 8 campos (rompe con `jobs_reaped` de Fase 2) | Archivo `NEW` en 0.1 y 13.7; T-I7/CA-F1-07 exigen **al menos** los 8 campos base |
| V-F1-I1 | `$stateStore` no llegaba a `collectKey()` (variable indefinida en rama `audits`) | Parámetro `AuditStateStore $stateStore` en firma y llamada |
| V-F1-I2 | `processEntries()` sin firma; `XAUTOCLAIM` no contaba en `$rounds`; alcance de `intake_acked` indefinido | `processEntries(...): array{int,int}`; acumulación en ambas fuentes; `$rounds++` si `XAUTOCLAIM` procesó entradas; `intake_acked` = solo `ackBatch()` |
| V-F1-I3 | `claimIdempotencyKey()` con `eval` + `get` en 2 RT: la clave podía expirar entre ambos y devolver `null` (falso reclamo) | Un único `eval` atómico `SET NX EX` + `GET` |
| V-F1-I4 | `$extraMeta['at_ms']` podía sobrescribir `at_ms` en `HSET` | `unset($extraMeta['at_ms'])` antes de la unión |
| V-F1-I5 | `cycle_started_at_ms = 0` en arranque en frío ⇒ falsa alarma `gc_cycle_age` | `gc()` fija `cycle_started_at_ms = $now` si `<= 0`; redacción de Sección 9 alineada con el pseudocódigo |
| V-F1-I6 | Techo fijo `pending ≤ 800` irreal con ticks rápidos; `operation=deploy/rollback` inexistente en `deploy-production.yml`; `R-MAINTAIN` prometía acotación en SDD-BATCH-001 | Regla primaria `lag + pending` decreciente y techo según cadencia real de ticks; flujo estándar del workflow + `image_tag=<sha_anterior>`; spec independiente si p99 > 6.000 ms |
| V-F1-I7 | VM-1 no medía `tick_duration_ms`; sin tests de corte por tiempo ni por 256 MiB | VM-1 calcula p99 de `heartbeat:meta.tick_duration_ms`; nuevos T-U5 (corte por `budgetMs`) y T-U6 (corte por memoria) + CA-F1-17 |
| V-F1-M1 | Excepciones documentadas de `getAuditStatus()`/`readAuditRetentionMetadata()` inexactas | `eval` propaga `\RuntimeException` (Redis no disponible) o `\Predis\PredisException` |
| V-F1-M2 | `tick()` y `gc()` llamaban ambos a `loadGcState()` (1 RT no contado) | `gc()` acepta `?array $state` precargado desde `tick()` |
| V-F1-M3 | Bucle de `expireLegacyIdempotency()` impreciso; caso `TTL == -1` con job vivo implícito; SUP-F1-01 citaba «detención rollout (20)» | `do { … } while ($cursor !== '0')`; caso explicitado; «acción Escalar si > 30 min (19)» |
| V-F1-M5 | `GC_PENDING_MAX` fuera del bloque de constantes; orden de T-I*/CA-F1-*; 6 rangos de líneas incompletos | Constante movida; T-I1 justificado como reservado, T-I* y CA-F1-* ordenados; rangos corregidos y verificados en código |

### Registro de revisión verificada (corrida w7k, 2026-11-18)

Segunda pasada sobre las correcciones v4s (14 `OK`, 2 `parcial`: `V-F1-B4` y `V-F1-B1`). Hallazgos: 1 Bloqueante, 5 Importantes, 8 Menores; se aplican aquí los de Fase 1 y la parte `(c)` de `I4`. `I3`, `I4(a)(b)`, `I5` y `M7` pertenecen a SDD-BATCH-001 y no se aplican en esta spec.

| ID | Defecto | Corrección |
| --- | --- | --- |
| W-F1-B1 | T-I10 afirmaba `heartbeat:meta.intake_acked = 1.500`, pero `policy` también lee y confirma las 1.550 entradas de `audit.documents.batch` (`AuditExecutionMaintenance.php:110-113`, `AuditTaskStage.php:19`) ⇒ `3.050`; deja `V-F1-B4` parcial | T-I10 crea el grupo `policy` en `$` sobre `audit.documents.batch` **tras** sembrar las 1.550 entradas (`ensureGroups()` ignora `BUSYGROUP`, `AuditExecutionMaintenance.php:395`), por lo que `policy` no ve ninguna; `intake_acked === 1.500` queda determinista y documentado |
| W-F1-I1 | `GC_STATUS_CACHE_MAX = 4096` inalcanzable: `$statuses` es local a `gc()` y `GC_MAX_ITEMS = 500` corta antes; T-U1 (5.000 auditorías distintas) no verificaba ni acotamiento ni reutilización | REQ-F1-01, DEC-F1-01, INV-F1-01, 13.1, T-U1 y CA-F1-01 alineados: cota efectiva `≤ GC_MAX_ITEMS` (500) por llamada; `GC_STATUS_CACHE_MAX` se conserva solo como tope defensivo; T-U1 reescrito (0 `get('audit:*:state')`, 500 tareas de 250 auditorías ⇒ 250 `AUDIT_STATUS_LUA`, corte en 500 ítems) |
| W-F1-I2 | INV-F1-04 y T-I8 omitían la intake forzada cuando el deadline vence en outbox (y T-I8 el `G + F` del arranque en frío con `last_gc_ms = 0`); `atCapacity` imputado a 2 RT | INV-F1-04 añade `I_f` (intake forzada) y `atCapacity = 3 RT` (`metrics()` = `eval` + `nowMs`, más `INFO memory`; `AuditAdmissionGuard.php:37-70`, `FairAuditTaskQueue.php:181-224`), sobrecarga fija recalculada; T-I8 con cota `2.500 + U_outbox + U_intake_forzada + G + F` y variante con `last_gc_ms` presembrado |
| W-F1-I3 | Guarda del `finally` de `gc()` colisiona con `legacy_done`/`reap_job_id` del delta de Fase 2 (deja `V-F1-B1` parcial en la interfaz) | **No aplica en Fase 1**: se corrige en SDD-BATCH-001 (la guarda de Fase 1 es correcta para sus 7 patrones) |
| W-F1-I4 | (a) acoplamiento circular `BatchJobStore → AuditExecutionMaintenance`; (b) tabla residual con `EXPIRE_LEGACY_IDEMPOTENCY_LUA`; (c) 6.1 permitía retirar `idem-expire-legacy` antes de que Fase 2 lo use en `migrate-batch-v2` | (c) aplicado: la condición de retiro de 6.1 exige además «tras desplegar SDD-BATCH-001»; (a)(b) **no aplican en Fase 1** (SDD-BATCH-001) |
| W-F1-I5 | `AuditExecutionMaintenanceTickRedisTest.php` y el caso `jobs_reaped` ausentes de la Sección 16 de Fase 2 | **No aplica en Fase 1** (SDD-BATCH-001); en esta spec el archivo ya figura en 0.1, 13.7 y FASE 2 |
| W-F1-M1 | `gc()` es `public` y no reiniciaba `$this->gcBudgetStopped`: dos llamadas directas consecutivas dejaban el indicador pegado y forzaban `gc_collect_cycles()` | `$this->gcBudgetStopped = false;` al inicio de `gc()` (se mantiene el reinicio en `tick()` para ticks sin GC) |
| W-F1-M2 | T-U5 no exigía `audit_id` distintos: con uno repetido la caché evita el corte por `budgetMs` | T-U5 usa 300 tareas de **300 auditorías distintas** |
| W-F1-M3 | `loadGcState()` no definía `pending_keys` ausente ni `count` ante JSON inválido; `saveGcState()` dependía de la serialización implícita de arrays (`IsolatedAuditRedis::eval()` no la hace, `tests/Support/IsolatedAuditRedis.php:32-36`); `writeHeartbeat()` sin visibilidad; `collectKey()` documentaba `0/1` aunque `GC_DELETE_AUDIT_STATE_LUA` puede devolver `2` (`AuditExecutionMaintenance.php:54`) | `pending_keys` ausente o `''` ⇒ `[]` sin warning; JSON inválido ⇒ warning con `count = 0`; `json_encode($state['pending_keys'], JSON_THROW_ON_ERROR)` explícito; `writeHeartbeat()` `private` (T-I7 la invoca con `ReflectionMethod`); `collectKey()` devuelve `int ≥ 0` o `null` |
| W-F1-M4 | 6 rangos de líneas residuales y orden `70-83, 44-55` | Corregidos y verificados en código: `bin/...` `38-46` (0.1, 0.2); `FairAuditTaskQueue.php` `181-224` (0.1) y `150-224` (0.2); `JsonRedisStoreTrait.php` `30-37`; `ObservabilityController.php` `148-170`; `AuditMemoryCompactionAndGcTest.php` `156-178`; `AuditDlqRecoveryRedisTest.php` `128-174` (atributo `#[DataProvider]` en `:128`, método en `:129`, proveedor `:168-174`); orden `44-55, 70-83` |
| W-F1-M5 | T-I11 sin criterio en la Sección 18; variante `usleep(20000)` de CA-F1-12 no descrita en T-I10 | CA-F1-08 incluye T-I11; T-I10 describe la variante de latencia |
| W-F1-M6 | `catch (RedisUnavailableException)` muerto en 13.5 (`RedisClient::eval()` nunca la lanza) y `\Predis\PredisException` (no deriva de `\RuntimeException`) escapaba al `catch (\RuntimeException)` de `AuditController::async()` | 13.5 captura `RedisUnavailableException\|\Predis\PredisException` y la envuelve en `RuntimeException` ⇒ 503 garantizado; T-U3 añade el caso `ServerException` |
| W-F1-M7 | Reinicio de `lastGcReapedJobs` por tick y adaptación de T-U4 al retirar `initJob()` | **No aplica en Fase 1** (SDD-BATCH-001) |
| W-F1-M8 | Latencia Redis p95 ≈ 85 ms observada en QA (w7k) frente a `p90 = 16,62 ms` de `E13`: sin registrarla, VM-1 no separa regresión de `tick()` de contención en Redis | VM-1 registra la latencia Redis p95 junto a `tick_duration_ms`; dato añadido a 0.6 como evidencia externa local/QA |

**Hechos no verificados empíricamente en w7k:** la versión real de Redis (≥ 7.0, requerida por `EXPIRE ... GT`), `appendonly`/`appendfsync` en vivo y la existencia o el conteo de `batch:idem:*` con `TTL = -1`. El sandbox bloqueó la invocación de `wsl` (`Wsl/E_ACCESSDENIED`, sin reintento por política) y `scratch/check_redis_facts.php` tampoco consulta `redis_version`. Solo hay respaldo estático (`docker-compose.yml:60,73`). Quedan como supuesto abierto `SUP-F1-04` (0.10).

### Registro de revisión verificada (tercera pasada, 2026-11-19)

Pasada estática con Antigravity (`w7k-1-r3f1`, `w7k-1-r3f2`; informes en `C:\Users\USER\.herdr-orch\runs\w7k\`), verificada por el orquestador contra el código. Fase 1: 12 correcciones `OK`, 2 parciales (`W-F1-I5`, `P-F1-01`), 0 Bloqueantes, 2 Importantes, 2 Menores.

| ID | Defecto | Corrección |
| --- | --- | --- |
| X-F1-I1 | Sección 9 de SDD-BATCH-001 seguía diciendo «`writeHeartbeat` pasa de 8 a 9 campos» (deja `W-F1-I5` parcial) | Corregido en SDD-BATCH-001 (`X-F2-I3`): 10 campos base + `jobs_reaped` = 11 |
| X-F1-I2 | T-I15 delegaba la comprobación del aviso `Audit admission paused` a la Sección 14, que no la tenía: ni el throttling de 5 min ni el `stderr` de `pause`/`observe` quedaban probados (deja `P-F1-01` parcial) | T-I15 comprueba `lastAdmissionPausedWarnMs` con `ReflectionProperty` (fijado en el 1.er tick pausado, sin cambio en el 2.º, `0` tras quitar la pausa); el aviso de CLI pasa a la constante `ADMISSION_PAUSED_CLI_NOTICE` y CA-F1-18 exige su uso en las 2 ramas; la condición del aviso admite `lastAdmissionPausedWarnMs === 0` para no depender de un reloj ≥ 300.000 ms |
| X-F1-M1 | T-I8 omitía `GET audit:admission:paused` en `F` y atribuía `saveGcState`/`trimWorkStreams` a `F` en la variante sin GC | Enumeración de `F` completada; la variante dice «`G = 0` (sin `gc()`, `saveGcState` ni `trimWorkStreams`)» |
| X-F1-M2 | FASE 2 seguía en «Sección 11 (12/12)» y 13.8 no mencionaba `REQ-F1-13` | `13/13`; entrada de `CHANGELOG.md` con la visibilidad de pausas |

### Registro de revisión verificada (cuarta pasada, 2026-11-19)

Pasada estática con Antigravity (`w7k-1-r4f1`), verificada por el orquestador contra el código y la spec. Sin bloqueantes; los ajustes de la tercera pasada (`X-F1-*`), los totales y la trazabilidad quedan confirmados.

| ID | Defecto | Corrección |
| --- | --- | --- |
| R4-F1-I1 | `GET audit:execution:paused` (tras la ráfaga de outbox, antes de `intakeAll()`) no figuraba en `F` de INV-F1-04 ni de T-I8: T-I8 podía fallar por la latencia de ese `GET` | Incluido en `F` (INV-F1-04 y T-I8); el desglose de 46 RT lo mueve de «paused 1» a `F` sin GC = 8 |
| R4-F1-M1 | La fila de 0.1 de `bin/audit-execution-maintenance.php` omitía `:28-37` (aviso `stderr` de `pause`/`observe`, 13.6) | Fila ampliada con `28-37` y su propósito |
| R4-F1-M2 | T-I10 decía `extraction pending = 50` entre dos `XINFO GROUPS`, aunque la PEL de `extractors` queda en `0` por el `XACK` de `ADMIT` | Esperado reescrito: PEL `0` y `metrics()['extraction']['batch']['queued'] === 50` |
| R4-F1-M3 | P-F1-01 citaba `plans/changelog.md:426` (`SDD-OPS-023`) para `INC-OPS-022` | Cita corregida a `:439` |

### Registro de revisión verificada (quinta pasada, 2026-11-19)

Verificación cruzada de cierre entre especificaciones (`R5-F1-*`), sin cambio de código respecto a la cuarta pasada.

| ID | Defecto | Corrección |
| --- | --- | --- |
| R5-F1-M1 | FASE 2 y FASE 4 omitían `R4-F1-*` y los encabezados invertían tercera y cuarta pasada | Secciones reordenadas cronológicamente (`tercera` → `cuarta` → `quinta`) y registradas en FASE 2 y FASE 4 |
| R5-F1-M2 | Comandos `docker logs` en Secciones 14 y 18 usaban el nombre literal `audfact-worker-execution-maintenance-1` (en producción el prefijo del proyecto es `audfact-prod-*`) | Unificados a `docker compose logs ... worker-execution-maintenance` |

### Registro de incorporación del diagnóstico de producción (2026-11-19)

Diagnóstico de incidentes en producción (`172.16.0.3`) aportado por el usuario (otros agentes; logs/SSH no observados directamente en este turno) y contrastado con el código. Solo se incorporan aquí los hallazgos de mantenimiento; los de SQL Server y caché cross-lane van a SDD-BATCH-001 (`P-F2-*`).

| ID | Hallazgo | Verificación en código | Corrección |
| --- | --- | --- | --- |
| P-F1-01 | `audit:admission:paused = 1` quedó activo y los 4 `worker-batch` durmieron en `usleep(100000)` sin leer `audit.batch.inbox` | `[CONFIRMADO]` `observe` hace `SET audit:admission:paused 1` sin TTL (`bin/audit-execution-maintenance.php:36-37`); `AuditEventConsumer::intakeBlocked()` lo consulta solo para el grupo `batch` (`AuditEventConsumer.php:229-231,275-281`); `resume` aborta con `maintenance_unhealthy` si el heartbeat es viejo o hay capacidad llena (`:38-46`), y entonces la bandera persiste (mismo patrón en `INC-OPS-022`, `plans/changelog.md:439`). Nada en el sistema avisa de que la bandera sigue puesta | `REQ-F1-13`: `admission_paused`/`execution_paused` en `heartbeat:meta`, aviso `Audit admission paused` cada 5 min mientras siga activa, y aviso por `stderr` en `pause`/`observe` (13.1, 13.6, T-I15, CA-F1-18) |
| P-F1-02 | «La API creaba jobs `pending` con la compuerta cerrada» | `[REFUTADO]` `POST /audit/async` llama a `assertAuditAdmission()` antes de publicar (`AuditController.php:424`), que lanza 503 con `audit:admission:paused = 1` (`AuditAdmissionGuard.php:19-20`); el cron también (`bin/schedule-daily-batches.php:95,162`). Los jobs `pending` observados (`b5808f13`, `e8e63d75`, `c0d9e1e8`, `total = 0`) y el lag `13` de `audit.batch.inbox` encajan con jobs creados antes de la pausa y no despachados: la intake estaba cerrada por la pausa y los 4 consumidores, colgados en SQL | Sin cambio de diseño; se documenta en 0.6 |
| P-F1-03 | RCA de 5 capas en `172.16.0.3` (`2026-11-19 14:56Z–15:19Z`) midió `worker-execution-maintenance-1` en `406,3 MiB / 512 MiB` (`79,35 %` RAM, `Up 42 h`, `0` reinicios) y `18,46 %` CPU con `41` jobs activos y `>38.000` mensajes en streams de descarga | `[CONFIRMADO en fuente externa]`; coherente en código con `memory_limit = 512M` (`bin/audit-execution-maintenance.php:16`), límite `512M` (`docker-compose.yml:338`) y la retención sin cota de `gc()` (`AuditExecutionMaintenance.php:211-217`). Confirma presión progresiva hacia OOM en producción antes de alcanzar el corte de 512 MiB | Sin cambio de código ni diseño; actualiza Triage (`Producción afectada`), `0.6`, `Objetivo` (`Impacto`) y Sección 19 (`memory_bytes`) |

---

### Registro de revisión ASEO (2026-11-22)

Revisión derivada de la verificación cruzada de las tres especificaciones (`SDD-BATCH-000/001/002`) contra el código en `HEAD` (`2d1a467`) conforme a `system.md` (CP3, CP4, 17.1). Evidencia: 18 afirmaciones verificadas por dos verificadores independientes (grep del orquestador y agente Sonnet), con `archivo:línea`.

| Campo | Valor |
| --- | --- |
| Versión anterior → nueva | 1.0 → 1.1 |
| Motivo | Cierre de hallazgos de la verificación cruzada 000/001/002 y control de deriva (`system.md` 17.1) |
| Cambios | Perímetro `0.1` añade `plans/changelog.md` (exigido por el hook `enforce-docs-sync`, `.agent/scripts/check-docs-updated.mjs`) y `AGENTS.md` (TTL de idempotencia, comando `idem-expire-legacy`, umbral de heartbeat) |
| Contratos modificados | Ninguno (cambio documental del perímetro) |
| Tareas afectadas | Ninguna implementada; el perímetro de archivos de la implementación crece en 2 filas |
| Autor del cambio | Claude (Sonnet 5.5), por instrucción del usuario |
| Estado de aprobación | `PENDING_APPROVAL` (sin aprobar ni implementar) |


### Revisión 1.2 — decisiones del usuario (2026-11-22)

Guía paso a paso de `system.md` (CP3) para llevar la spec a Nivel A. Cada decisión fue tomada por el usuario mediante pregunta explícita; las lecturas de código citan `archivo:línea`.

| Campo | Valor |
| --- | --- |
| Versión anterior → nueva | 1.1 → 1.2 |
| Decisiones del usuario | (D4) producción es copia fiel de local: el baseline efectivo es el de `HEAD` (cierra 0.8; `SUP-F1-03` y `SUP-F1-04` pasan a `Mitigado`) |
| Lecturas de código | `docker-compose.yml:60` (`redis:7-alpine`) y `:73` (`--appendonly yes --appendfsync always`) |
| Cambios | Estado de `SUP-F1-03` y `SUP-F1-04`; filas de 0.8; clasificación 0.11 y Fase 4 |
| Contratos modificados | Ninguno |
| Nivel | `Nivel B` → **`Nivel A — Implementable, despliegue condicionado a las Validaciones Previas de la Sección 14`** |
| Autor del cambio | Claude (Sonnet 5.5), por decisión explícita del usuario |
| Estado de aprobación | `PENDING_APPROVAL` (sin aprobar ni implementar) |


## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación |
| --- | --- | --- |
| Tipo | Bug | `[CONFIRMADO]` Corrige el agotamiento de memoria de `gc()` (`H11`), el bloqueo de cabeza de cola en intake (`H8`), la falta de cota temporal de `tick()` (`H6`, `H7`) y el TTL ignorado en `batch:idem:*`. |
| Riesgo | Alto | `[CONFIRMADO]` Toca el único proceso que escribe `audit:execution:heartbeat` (`AuditExecutionMaintenance.php:181-184`), del que depende la admisión HTTP (`AuditAdmissionGuard.php:24-28`). |
| Persistencia afectada | Sí | `[CONFIRMADO]` Dos claves Redis nuevas (`audit:execution:gc:cursors`, `audit:execution:heartbeat:meta`) y TTL en `batch:idem:*`. Sin cambio de estructura de claves existentes. |
| Contrato externo afectado | No | `[CONFIRMADO]` Sin cambios en endpoints ni eventos; `claimIdempotencyKey()` conserva firma (`BatchJobStore.php:305`). El umbral de heartbeat pasa de `10.000` a `30.000 ms` (comportamiento interno de admisión). |
| Cambio arquitectónico | No | `[CONFIRMADO]` Mismo proceso, mismas etapas; solo presupuesto, cursores persistidos y lotes. |
| Producción afectada | Sí | `[CONFIRMADO en fuente externa]` Pausa silenciosa (`P-F1-01`) y presión progresiva de RAM en `worker-execution-maintenance-1` hasta `406,3 MiB / 512 MiB` (`79,35 %`, `Up 42 h`, `0` reinicios) en `172.16.0.3` (`P-F1-03`). `[INFERIDO]` El bucle OOM cada 19–42 s observado en QA (`E20`) se reproducirá en producción al cruzar `512M` (misma imagen y límites, `docker-compose.yml:327-339`). |
| Requiere 0.3.1 | No | `[CONFIRMADO]` No se reemplaza ningún mapeo estático por abstracción dinámica: los 7 patrones de `gc()` se conservan literales. |

Perfil: **completo** (Riesgo Alto), con Observabilidad y Rollout obligatorios.

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| Mantenimiento | `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php` | MODIFIED | `tick()`, `gc()`, `scanPattern()`, `intake()`, constructor (`memoryProbe`) | 12-13, 44-55, 70-83, 85-91, 93, 121-186, 202-385, 404-434 | Sí (1-435 completo) |
| Estado de auditoría | `app/Services/Audit/Pipeline/AuditStateStore.php` | MODIFIED | `RECOVER_FAILED_AUDIT_LUA`, nuevos lectores compactos | 108-120, 131-139, 187-196 | Sí (100-210, 366-385) |
| Configuración | `app/Services/Audit/Pipeline/AuditExecutionConfig.php` | MODIFIED | Constantes de heartbeat | 12-19 | Sí (1-98 completo) |
| Guarda de admisión | `app/Services/Audit/Pipeline/AuditAdmissionGuard.php` | MODIFIED | Umbral de heartbeat | 24-28 | Sí (1-71 completo) |
| Jobs batch | `app/Services/Audit/Pipeline/BatchJobStore.php` | MODIFIED | Solo `claimIdempotencyKey()` | 305-315 | Sí (24-45, 295-323) |
| CLI mantenimiento | `bin/audit-execution-maintenance.php` | MODIFIED | `pause`/`observe` (aviso `stderr`, REQ-F1-13), `resume`, log de arranque, comando `idem-expire-legacy` | 28-37, 38-46, 59-61, 82-87 | Sí (1-93 completo) |
| Cola justa | `app/Services/Audit/Pipeline/FairAuditTaskQueue.php` | IMPACTED | `admit()` (XACK interno), `maintain()`, `nowMs()`, `metrics()` | 53-67, 150-175, 181-224, 233-261 | Sí |
| Outbox | `app/Services/Audit/Pipeline/AuditTaskOutbox.php` | IMPACTED | `flush(100)` invocado en ráfaga | 15-64 | Sí |
| Publicador | `app/Services/Audit/Pipeline/AuditEventPublisher.php` | IMPACTED | `trimWorkStreams()` (9 RT) | 35-45, 137-143 | Sí |
| Cuota Gemini | `app/Services/Audit/Pipeline/GeminiQuotaBudget.php` | INSPECTED | `recoverExpired()` (≤2 RT) | 157-172 | Sí |
| Etapas | `app/Services/Audit/Pipeline/AuditTaskStage.php` | INSPECTED | `eventTypes()` para clasificar intake | 14-22 | Sí |
| Cliente Redis | `core/RedisClient.php` | INSPECTED | `eval()` con ARGV variable y prefijo; `scanKeys()` COUNT ≤1000 | 423-442, 446-459 | Sí |
| JSON trait | `app/Services/Audit/Pipeline/JsonRedisStoreTrait.php` | INSPECTED | `decodeJson()` donde se observa el OOM (`E20`) | 30-37 | Sí |
| Controlador API | `app/Controllers/AuditController.php` | MODIFIED | `async()` (`:396`) llama `claimIdempotencyKey(..., 300)`; captura explícita de `InvalidArgumentException` | 427-433 | Sí (1-17, 396-445) |
| Cron | `bin/schedule-daily-batches.php` | IMPACTED | Llama `claimIdempotencyKey(..., 14400)`; clave horaria | 121, 160-183 | Sí |
| Observabilidad | `app/Controllers/ObservabilityController.php` | INSPECTED | Lee `audit:execution:heartbeat` (no umbral) | 148-170 | Sí |
| Compose | `docker-compose.yml` | INSPECTED | Límites `512M`/`0.5` CPU, `redis:7-alpine` | 60, 327-339 | Sí |
| Deploy | `.github/workflows/deploy-production.yml` | INSPECTED | `ctl` y `up -d --no-deps` de todos los servicios | 529-577 | Sí |
| CI | `.github/workflows/ci.yml` | INSPECTED | Contrato `Env::get` literal vs `.env.example` | 103-129, 146, 165 | Sí |
| Test GC unitario | `tests/Services/Audit/Pipeline/AuditMemoryCompactionAndGcTest.php` | MODIFIED | Afirma 1 lectura `get` de estado completo | 111-187 | Sí |
| Test guarda | `tests/Services/Audit/Pipeline/AuditAdmissionGuardTest.php` | MODIFIED | Añadir casos de heartbeat | 15-72 | Sí |
| Test TTL | `tests/Services/Audit/Pipeline/RedisTtlConfigTest.php` | MODIFIED | Añadir casos de `claimIdempotencyKey` | 49-132 (índice de tests) | Sí |
| Test DLQ | `tests/Integration/AuditDlqRecoveryRedisTest.php` | MODIFIED | Barreras de heartbeat (`now-60000`) | 128-174 | Sí |
| Test GC integración | `tests/Integration/AuditExecutionMigrationRedisTest.php` | IMPACTED | `gc()` único debe cubrir los 7 patrones | 106-166 | Sí |
| Test controlador | `tests/Controllers/AuditControllerTest.php` | MODIFIED | Doble de `claimIdempotencyKey` (firma estable) + caso de TTL inválido | 131, 195-216, 750-766 | Sí |
| Test tick/GC integración | `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` | NEW | T-I2..T-I5, T-I7..T-I15 (tick, GC persistido, intake, heartbeat:meta, pausas) | — (archivo nuevo) | N/A (no existe) |
| Changelog | `CHANGELOG.md` | MODIFIED | Entrada del hotfix | — (append) | Sí (existe, con cambios locales sin commitear según `git status`) |
| Changelog de planes | `plans/changelog.md` | MODIFIED | Entrada del hotfix; el hook `enforce-docs-sync` (`.agent/scripts/check-docs-updated.mjs`) exige este archivo actualizado | — (append) | Sí (existe) |
| Guía del agente | `AGENTS.md` | MODIFIED | Sincronizar contratos operativos afectados: TTL obligatorio de idempotencia (catálogo de variables, `AUDIT_IDEMPOTENCY_KEY_TTL` en `:417`), nuevo comando `idem-expire-legacy` y umbral de heartbeat de admisión | — (según `audfact-docs-sync`) | Sí (existe) |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Símbolo | `new AuditExecutionMaintenance\|AuditExecutionMaintenance::` | `bin/audit-execution-maintenance.php:26,60`, 2 tests, `scratch/04` (ignorado por `.gitignore:18`) | grep sobre repo excl. `vendor` |
| Símbolo | `claimIdempotencyKey` | `AuditController.php:430`, `schedule-daily-batches.php:174`, `AuditControllerTest.php:131,198,753-765` | grep |
| Textual | `audit:execution:heartbeat` | `AuditAdmissionGuard.php:24`, `AuditExecutionMaintenance.php:183`, `AuditStateStore.php:114`, `bin/...:39`, `ObservabilityController.php:150` | grep (`*.php,*.sh,*.yml,*.md`) |
| Textual | `10000` en `app bin core` | Umbral de heartbeat solo en `AuditAdmissionGuard.php:25`, `AuditStateStore.php:139`, `bin/...:41`; intervalo GC en `AuditExecutionMaintenance.php:176`; resto ajeno (`AuditController.php:402`, `usleep`) | grep |
| Textual | `gcCursors\|lastGcMs\|GC_SCAN_STEPS` | Solo `AuditExecutionMaintenance.php` y `scratch/04` (no versionado) | grep + `git check-ignore` |
| Configuración | `AUDIT_IDEMPOTENCY_KEY_TTL\|AUDIT_JOB_TTL` | `.env.example:135-138`, `deploy-production.yml:213,436` | grep |
| Tests | `heartbeat\|gc()\|tick()` en `tests/` | `AuditDlqRecoveryRedisTest`, `AuditControllerTest:560`, 2 llamadas a `gc()`; **ningún** test invoca `tick()` | grep |
| Workflows/CI | `ci.yml`, `deploy-production.yml` | CI exige que toda `Env::get('LITERAL')` esté en `.env.example`; esta spec **no añade variables** | `ci.yml:109-129` |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `AuditExecutionMaintenance` | CLI `run`/`gc`/`outbox` | `bin/audit-execution-maintenance.php` | 26, 60, 66, 83-85 | Directa | Estática | Repositorio local |
| `AuditExecutionMaintenance` | Servicio `worker-execution-maintenance` | `docker-compose.yml` | 327-339 | Directa | Operacional | Operación |
| `AuditExecutionMaintenance` | `FairAuditTaskQueue::admit/maintain/metrics/nowMs` | `FairAuditTaskQueue.php` | 53-67, 150-224 | Directa | Estática | Repositorio local |
| `AuditExecutionMaintenance` | `AuditTaskOutbox::flush` | `AuditTaskOutbox.php` | 15 | Directa | Estática | Repositorio local |
| `AuditExecutionMaintenance` | `AuditStateStore::getAudit` → nuevo `getAuditStatus` | `AuditStateStore.php` | 187-196 | Directa | Estática | Repositorio local |
| Heartbeat | `AuditAdmissionGuard::assertAccepting` | `AuditAdmissionGuard.php` | 16-29 | Directa | Contractual (clave Redis) | Repositorio local |
| Heartbeat | `RECOVER_FAILED_AUDIT_LUA` | `AuditStateStore.php` | 131-139 | Directa | Contractual | Repositorio local |
| Heartbeat | `resume` | `bin/audit-execution-maintenance.php` | 38-46 | Directa | Operacional | Operación manual |
| Heartbeat | `GET /metrics/async` | `ObservabilityController.php` | 148-151 | Directa | Contractual (solo lectura de valor) | Cliente externo |
| `claimIdempotencyKey` | `POST /audit/async` | `AuditController.php` | 427-430 | Directa | Estática | Cliente externo (HTTP) |
| `claimIdempotencyKey` | Cron horario | `bin/schedule-daily-batches.php` | 121, 171-174 | Directa | Estática | Operación (crontab host, `README.md:292-296`) |
| `batch:idem:*` | Rama `idempotency` de `gc()` | `AuditExecutionMaintenance.php` | 352-362 | Directa | Contractual | Repositorio local |
| Heartbeat (umbral) | Alertas/monitores fuera del repo | — | — | — | Operacional | `[DESCONOCIDO]` (sin acceso a inventario externo) → `SUP-F1-03` |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| `gc()` usa `getAuditStatus()` (Lua) en vez de `get` | Test unitario cuenta llamadas a `get` | `AuditMemoryCompactionAndGcTest.php:137-144,185` | Test | Reescribir el stub: contar `eval` con `AUDIT_STATUS_LUA` y devolver `'failed'` (Sección 16). |
| `gc()` lee/escribe `audit:execution:gc:cursors` vía `eval` | Stub `eval` devuelve `0` por defecto | `AuditMemoryCompactionAndGcTest.php:156-178` | Test | El stub devuelve `[]` para el `HGETALL` y `1` para el `HSET`; además `loadGcState()` trata no-array como estado vacío (DEC-F1-03). |
| `gc()` con presupuesto y round-robin | Test de integración espera barrido completo en una llamada | `AuditExecutionMigrationRedisTest.php:156-165` | Test | DEC-F1-02: una llamada recorre los 7 patrones si el presupuesto alcanza; el conjunto del test (<10 claves) cabe. Sin cambio del test. |
| Borrado de `audit:*:state` y `job:*:state` compara `sha1` | `GC_DELETE_AUDIT_STATE_LUA`, `GC_DELETE_JOB_STATE_LUA` | `AuditExecutionMaintenance.php:44-55,70-83` | Runtime | Actualizar ambos Lua a `redis.sha1hex(raw) ~= ARGV[1]` y enviar `sha1($raw)` (Sección 13). |
| Eliminar `GC_SCAN_STEPS` | `scratch/04_profile...php:218` | `scratch/` | DX | `[CONFIRMADO]` `scratch/` está ignorado (`.gitignore:18`); no entra en CI ni imagen versionada. Sin corrección. |
| `GC_PAGE_SIZE` 1000 → 100 | Log de arranque `page_size` | `bin/audit-execution-maintenance.php:60` | DX | Mantener la clave `page_size`; su valor pasa a `100`. |
| Umbral heartbeat `10.000 → 30.000 ms` | Barrera `stale_heartbeat` usa `now-60000` | `AuditDlqRecoveryRedisTest.php:143` | Test | Sigue rechazando (60.000 > 30.000). Añadir caso aceptado `now-20000`. |
| `ARGV[7]` en `RECOVER_FAILED_AUDIT_LUA` | Llamador único | `AuditStateStore.php:110-120` | Runtime | Añadir el argumento en el mismo cambio; el script viaja con el llamador (EVAL), sin coexistencia de versiones. |
| `claimIdempotencyKey` rechaza `$ttl <= 0` | API con `AUDIT_IDEMPOTENCY_KEY_TTL=0` | `AuditController.php:427` | Runtime | La excepción es `InvalidArgumentException` (no `RuntimeException`, no capturada en `:431`) → error no controlado. `[CONFIRMADO]` `.env.example:135` = `300`. Corrección: 13.9 captura `\InvalidArgumentException` en `AuditController::async()` → `Logger::error` + `Response::error('Configuración de idempotencia inválida', 500)`; test T-U4; `R-IDEM-ZERO`. |
| Capacidad de intake compartida entre los 2 streams de una etapa | Admisión de etapa | `AuditExecutionMaintenance.php:134-145` | Runtime | Comportamiento deliberado (DEC-F1-05): hoy cada stream recibe la capacidad completa (hasta `2×100` por etapa). |
| `maintain()` puede omitirse en un tick por deadline | Leases/delayed vencidos | `FairAuditTaskQueue.php:159-175` | Runtime | Rotación de inicio por etapa (`intake_offset` sobre 4 etapas) garantiza ≥1 `maintain()` por par cada ≤4 ticks; `LEASE_MS = 600000` (`AuditExecutionConfig.php:13`). |

### 0.3.1 Verificación de Cobertura de Abstracciones

N/A — `[CONFIRMADO]` el triage registra «No»: los patrones siguen siendo un arreglo literal.

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| Redis `SCAN` | Puede devolver claves repetidas; un barrido completo termina al volver a cursor `0`; claves presentes todo el barrido se devuelven al menos una vez | Documental | redis.io/docs/latest/commands/scan (garantías) | Sí: dedup `$seenKeys` por llamada; repetición entre llamadas es idempotente (Lua comparan valor). |
| Redis Lua | `unpack(ARGV, 2)` expande ARGV variable; `redis.sha1hex(s)` disponible | Documental | redis.io/docs/latest/develop/interact/programmability/lua-api (`redis.sha1hex`) | Sí; 1000 ids por llamada ≪ límite de pila Lua (`LUAI_MAXCSTACK` 8000). |
| Redis `XACK` | Acepta múltiples ids: `XACK key group id [id ...]` | Documental | redis.io/docs/latest/commands/xack | Sí. |
| Redis `XREADGROUP ... >` | Las entradas entregadas y no confirmadas quedan en la PEL del consumidor | Documental | redis.io/docs/latest/commands/xreadgroup | Sí: coincidentes no admitidos quedan en PEL. |
| Redis `XAUTOCLAIM` | Reclama entradas con idle ≥ `min-idle-time`; `COUNT` limita reclamadas | Documental + Estática | `AuditExecutionMaintenance.php:142-146` (`5000` ms) | Sí. |
| Redis `EXPIRE ... GT` | Solo amplía TTL; sobre clave sin TTL no aplica (no-volátil = infinito); requiere Redis ≥ 7.0 | Documental + Estática | redis.io/docs/latest/commands/expire; `docker-compose.yml:60` `redis:7-alpine` | Sí (versión real no verificada empíricamente: `SUP-F1-04`). |
| Redis `SET NX EX` | Atómico; `EX` > 0 obligatorio | Documental | redis.io/docs/latest/commands/set | Sí; `$ttl <= 0` se rechaza antes. |
| Predis vía `RedisClient::eval` | Prefija KEYS, ARGV escalares a string, `false` Lua → `null` PHP | Estática | `core/RedisClient.php:423-442` | Sí. |
| PHP `memory_get_usage(true)` | Memoria reservada por el gestor Zend (bloques de 2 MiB) | Documental | php.net/memory_get_usage | Sí: umbral 256 MiB vs `memory_limit` 512M (`bin/...:16`). |
| PHP `hrtime(true)` | Reloj monotónico en ns | Documental | php.net/hrtime | Sí. |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose | `docker compose up -d worker-execution-maintenance` | Sí | `docker-compose.yml:327-339` |
| CI automatizado | GitHub Actions `ci.yml` | `vendor/bin/phpunit` + job `redis-integration` (`RUN_REDIS_INTEGRATION=1`) | Sí | `ci.yml:103-107,146,165`; sin variables nuevas (`ci.yml:109-129`) |
| Staging / QA | Compose local contra `DiscolmetsGx2QA` | Igual a local | Sí | Entorno del diagnóstico (`E20`) |
| Producción | `deploy-production.yml` self-hosted | `docker compose up -d --no-deps --no-build <servicios>` | Sí | `deploy-production.yml:570-577` |
| Testing aislado | PHPUnit con stubs / `IsolatedAuditRedis` | `phpunit tests/Services`, `tests/Integration` | Sí | `tests/Support/IsolatedAuditRedis.php:12-17` |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia |
| --- | --- | --- |
| `gc()` cachea estados completos en `$audits` sin cota | `[CONFIRMADO]` | `AuditExecutionMaintenance.php:211-217` |
| Los 4 llamadores solo usan `status` | `[CONFIRMADO]` | `:230-231`, `:253-254`, `:295-296`, `:341-342` |
| Cursores y último GC solo en RAM | `[CONFIRMADO]` | `:89-90`, `:372`, `:383` |
| `scanPattern` acumula hasta 1000 claves × 10 pasos | `[CONFIRMADO]` | `:12-13`, `:370-385` |
| Borrado de job reenvía el JSON crudo como ARGV | `[CONFIRMADO]` | `:326-330` |
| Heartbeat `EX 30` al final de `tick()` | `[CONFIRMADO]` | `:181-184` |
| Umbral 10.000 ms en 3 sitios | `[CONFIRMADO]` | `AuditAdmissionGuard.php:25`; `AuditStateStore.php:139`; `bin/...:41` |
| GC cada 10 s o en capacidad | `[CONFIRMADO]` | `AuditExecutionMaintenance.php:176` |
| Intake: 1 `eval XACK` por no coincidente, 1 `admit` por coincidente, `COUNT = capacity ≤ 100` | `[CONFIRMADO]` | `:134`, `:157-161`, `:430`, `:433`; `FairAuditTaskQueue.php:55-61` |
| `admit()` hace `XACK` interno y devuelve `0` (sin XACK) si la etapa está llena | `[CONFIRMADO]` | `FairAuditTaskQueue.php:240,245,247,260` |
| `maintain()` procesa ≤100 ids por índice con 2 RT por id | `[CONFIRMADO]` | `FairAuditTaskQueue.php:159-175` |
| `trimWorkStreams()` = 9 `eval` | `[CONFIRMADO]` | `AuditEventPublisher.php:35-45,137-143` |
| `claimIdempotencyKey` ignora `$ttl` | `[CONFIRMADO]` | `BatchJobStore.php:305-311` |
| El job refresca su TTL a `AUDIT_JOB_TTL` en cada mutación | `[CONFIRMADO]` | `BatchJobStore.php:225,244,284` |
| `AUDIT_JOB_TTL`/`STATE`/`QUEUE` = 86400 por defecto | `[CONFIRMADO]` | `.env.example:136-138`; `BatchJobStore.php:24` |
| API usa TTL 300 s; cron 14400 s con clave `cron-batch-Ymd-H-<nit>` | `[CONFIRMADO]` | `AuditController.php:427`; `schedule-daily-batches.php:121,171` |
| `memory_limit` 512M en CLI; límite Docker 512M, 0,5 CPU | `[CONFIRMADO]` | `bin/...:16`; `docker-compose.yml:335-339` |
| Bucle OOM cada 19–42 s, heartbeat `NULL` | `[CONFIRMADO en fuente externa]` | `E20` §1, `H11` |
| Expansión ~5,6× y 1.530 auditorías → 380 MB | `[CONFIRMADO en fuente externa]` | `E20` §2 |
| Lag `extractors` 9.940–21.541; outbox 30.732–33.076 | `[CONFIRMADO en fuente externa]` | `E8`, `E12`, `E20` §3, `H7`, `H8` |
| Latencia Redis desde mantenimiento p50 1,64 ms / p90 16,62 ms | `[CONFIRMADO en fuente externa]` | `E13` |
| Latencia Redis en QA p95 85,56–85,81 ms (máx. 144,85 ms) en 2 muestras, muy por encima de `E13` | `[CONFIRMADO en fuente externa]` (local/QA, no producción) | Corrida de revisión w7k, `scratch/04_profile_pipeline_and_maintenance_real.php` en solo lectura, 2026-11-18 20:46 -05:00 (W-F1-M8) |
| Versión real de Redis ≥ 7.0, `appendonly`/`appendfsync` en vivo, existencia y conteo de `batch:idem:*` con `TTL = -1` | `[INFERIDO]` solo por configuración estática (`docker-compose.yml:60,73`); no verificados empíricamente (w7k: sandbox bloqueó `wsl`) | → `SUP-F1-04` |
| `outbox.flush(100)` ≈ 3.204 ms / 101 RT en réplica | `[CONFIRMADO en fuente externa]` | `E14` |
| Cardinalidad y latencia de Redis en producción | `[DESCONOCIDO]` | → 0.8, `SUP-F1-01` |
| En el incidente de producción, `audit:admission:paused = 1` (puesto por `observe`) bloqueó la intake de `worker-batch`; se resolvió con reinicio de `worker-batch` y `resume` | `[CONFIRMADO en fuente externa]` (diagnóstico de producción, 2026-11-19); mecanismo `[CONFIRMADO]` en código | `bin/audit-execution-maintenance.php:36-37`; `AuditEventConsumer.php:229-231,275-281`; P-F1-01 |
| Con la admisión pausada, `POST /audit/async` y el cron responden 503 y no crean jobs | `[CONFIRMADO]` | `AuditController.php:424,645-653`; `AuditAdmissionGuard.php:19-20`; `bin/schedule-daily-batches.php:95,162`; P-F1-02 |
| En el RCA de producción (`172.16.0.3`, `2026-11-19 14:56Z–15:19Z`), `worker-execution-maintenance-1` alcanzó `406,3 MiB / 512 MiB` (`79,35 %` RAM, `Up 42 h`, `0` reinicios) y `18,46 %` CPU con `41` jobs activos y `>38.000` mensajes en descarga | `[CONFIRMADO en fuente externa]` (diagnóstico RCA de producción, 2026-11-19) | `bin/audit-execution-maintenance.php:16`; `docker-compose.yml:338`; P-F1-03 |

### 0.7 Información Faltante Crítica

Ninguna. `[CONFIRMADO]` Todos los cambios de la Sección 13 se redactan con código leído.

### 0.8 Información Faltante Importante

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Baseline productivo (heartbeat, `extractors lag`, outbox, número de claves por patrón) | El diagnóstico es local/QA | Umbrales de Sección 19 calibrados con QA; `SUP-F1-01`. **Cerrado 2026-11-22 por decisión del usuario:** producción es copia fiel de local; el baseline efectivo es el de `HEAD`. |
| Monitores externos que dependan del umbral de 10 s | Sin acceso a inventario externo | `SUP-F1-03` (Mitigado; no bloquea la implementación). |

### 0.9 Información Faltante Opcional

| Dato | Motivo | Impacto |
| --- | --- | --- |
| Subfase exacta del OOM | `E20` §2 declara la estimación acotada | Ninguno: la corrección elimina las tres fuentes (caché, página 1000×10, ARGV del job). |

### 0.10 Supuestos Declarados

| ID | Supuesto | Base | Consecuencia si es Falso | Severidad | Estado | Mitigación y Detección | Condición de Cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SUP-F1-01 | Producción tiene cardinalidad de claves y latencia Redis del orden de QA (`E13`, `E14`), de modo que un barrido GC completo cabe en ≤ 10 min con 500 ms/5 s | `[INFERIDO]` misma imagen y compose | El ciclo GC es más largo; crece memoria Redis; no cambia diseño | S2 | Mitigado | Riesgo `R-GC-CYCLE` (17); señal `gc_cycle_age` (19); acción Escalar si > 30 min (19) | Medir `gc:cursors` y `meta` 24 h en producción |
| SUP-F1-02 | `maintain()` (unidad indivisible ≤402 RT) no domina el tick en producción | `[INFERIDO]` `FairAuditTaskQueue.php:159-175` | `tick_duration_ms` supera la cota objetivo; no cambia diseño | S2 | Mitigado | `R-MAINTAIN` (17); señal `tick_duration_ms` (19); acción documentada (20) | p99 de `tick_duration_ms` < 6.000 ms en 24 h |
| SUP-F1-03 | Ningún monitor externo depende del umbral 10.000 ms | `[INFERIDO]` el repositorio no contiene monitores ni umbrales de heartbeat fuera de `app/`, `tests/` y `plans/` (búsqueda de `heartbeat` en archivos de configuración); monitores externos al repositorio `[DESCONOCIDO]`. Aceptado por decisión del usuario (2026-11-22): producción es copia fiel de local | Alertas tardías o falsas; no cambia código | S2 | Mitigado | `R-HB-EXT` (17) | Confirmación del responsable de operación (no bloquea la implementación) |
| SUP-F1-04 | El Redis desplegado (local/QA y producción) es ≥ 7.0, corre con `appendonly yes` y `appendfsync always`, y las `batch:idem:*` con `TTL = -1` son pocas o ninguna | `[INFERIDO]` `docker-compose.yml:60` (`redis:7-alpine`) y `:73` (`--appendonly yes --appendfsync always`); `E14` vio 0 legados en la réplica. No verificado empíricamente en w7k (W-F1-M8) | Con Redis < 7.0, `EXPIRE ... GT` de la rama `idempotency` falla con error Lua ⇒ `collectKey()` lanza y se aplica DEC-F1-04 (1 caída por ciclo, sin refresco del TTL). Sin AOF, la durabilidad que exige `durability-check` no se cumple. Con muchos legados, `idem-expire-legacy` tarda más, pero el diseño no cambia | S2 | Mitigado (`redis:7-alpine` y `--appendonly yes --appendfsync always` verificados en `docker-compose.yml:60,73` el 2026-11-22; producción = copia fiel de local por decisión del usuario; el conteo de `batch:idem:*` con `TTL -1` lo informa `idem-expire-legacy`) | Validaciones Previas (14): el operador verifica la versión y la persistencia antes del despliegue; la señal `gc_cycle_age` (19) detecta caídas por ciclo | El operador lo verifica fuera del sandbox: `redis-server --version` (o `redis-cli INFO server` ⇒ `redis_version` ≥ 7.0), `redis-cli CONFIG GET appendonly` = `yes` y `CONFIG GET appendfsync` = `always`, y el conteo de `batch:idem:*` con `TTL -1` (primera salida de `idem-expire-legacy`) |

### 0.11 Clasificación de Completitud Inicial

Nivel B (clasificación inicial, 2026-11-18): perímetro cerrado y leído; `SUP-F1-03` y `SUP-F1-04` abiertos (S2) y 0.8 abierta. Reclasificado a **Nivel A** el 2026-11-22 (ver Fase 4).

---

## FASE 1 — Especificación

### 1. Objetivo

- **Problema:** `[CONFIRMADO en fuente externa]` el único proceso de mantenimiento muere por `memory_limit` 512M cada 19–42 s dentro de `gc()` y nunca escribe el heartbeat (`H11`, `E20`); en consecuencia `POST /audit/async` responde 503 (`AuditAdmissionGuard.php:25`) y la recuperación DLQ se bloquea (`AuditStateStore.php:139`). Antes del OOM, el intake de `extractors` acumuló lag 9.940–21.541 (`H8`) y el outbox 30.732–33.076 (`H7`).
- **Causa raíz:** `[CONFIRMADO]` (a) caché `$audits` ilimitada con estados completos (`:211-217`); (b) páginas de hasta 1000×10 claves sin presupuesto (`:370-385`); (c) cursores en RAM que reinician a `0` tras cada caída (`:89-90`); (d) intake con 1 RT por entrada no coincidente y `COUNT ≤ capacity` (`:157-161,430`); (e) `tick()` sin deadline; (f) `SET NX` sin `EX` en idempotencia (`BatchJobStore.php:310`).
- **Impacto:** `[CONFIRMADO en fuente externa]` en producción (`172.16.0.3`), `worker-execution-maintenance-1` ya alcanzó `406,3 MiB / 512 MiB` (`79,35 %` RAM tras `42 h` de ejecución, `P-F1-03`); `[INFERIDO]` con la misma imagen y límites que QA, cruzará `512M` hacia la caída OOM continua al acumular volumen/referencias en `gc()`.
- **Resultado esperado:** `tick()` acotado en tiempo y memoria, heartbeat continuo, GC incremental reanudable, intake sin bloqueo de cabeza y claves de idempotencia con TTL.

### 2. Alcance

#### Incluido

1. `REQ-F1-01` — `gc()` sin caché de estados completos: `getAuditStatus()` + caché de status local por llamada, acotada a ≤ `GC_MAX_ITEMS` (500) entradas (tope defensivo `GC_STATUS_CACHE_MAX` = 4096).
2. `REQ-F1-02` — `gc()` sobre `audit:*:state` lee solo `{audit_id,status,completed_at,updated_at,sha1}`.
3. `REQ-F1-03` — Estado GC persistido en Hash `audit:execution:gc:cursors` (round-robin, cursores, `pending_keys ≤ 1000`, `last_gc_ms`; campos desconocidos preservados).
4. `REQ-F1-04` — Presupuesto GC por llamada: 500 ítems / 500 ms / 256 MiB.
5. `REQ-F1-05` — `job:*:state` v1: un job por vez, chequeo de memoria previo, borrado por `sha1`.
6. `REQ-F1-06` — Heartbeat unificado: TTL 30 s, edad máxima 30.000 ms en los 3 consumidores.
7. `REQ-F1-07` — `audit:execution:heartbeat:meta` (Hash, EX 60).
8. `REQ-F1-08` — Deadline global de `tick()` de 2.500 ms con comprobación entre unidades.
9. `REQ-F1-09` — Outbox en ráfaga ≤ 20 × `flush(100)`.
10. `REQ-F1-10` — Intake en rondas `COUNT 200` con `XACK` por lote y progreso garantizado.
11. `REQ-F1-11` — `claimIdempotencyKey()` con `EX max($ttl, AUDIT_JOB_TTL)` y rechazo de `$ttl <= 0`.
12. `REQ-F1-12` — Comando `idem-expire-legacy` y refresco `GT` de `batch:idem:*` con job vivo.
13. `REQ-F1-13` — Visibilidad de las pausas (P-F1-01): `admission_paused` y `execution_paused` en `heartbeat:meta`, `Logger::warning('Audit admission paused')` como máximo cada 5 min mientras `audit:admission:paused = 1`, y aviso por `stderr` en `pause`/`observe` (stdout sin cambios).

#### Excluido

`job:*:audits`, `by_dispensa`, `candidates`; congelación SQL y su timeout; fencing por epoch; reserva por `DisDetNro` (se mantiene `audit:reservation:disid:*`); `jobs:active:nit` y reaper de jobs estancados; filtro `dateTo`; exclusividad por NIT y cambio de la clave horaria del cron. Todo pertenece a SDD-BATCH-001.

### 3. Non Goals

- Escalar `worker-execution-maintenance` a más réplicas o separar procesos (`H6` se mitiga, no se rediseña).
- Cambiar la compuerta de descarga (`H9`) o el rendimiento de salida (`H10`).
- Exponer `heartbeat:meta` en `GET /metrics/async` (sin cambio de contrato HTTP).
- Acotar internamente `FairAuditTaskQueue::maintain()` (se trata como unidad indivisible).

### 4. Estado Actual

`[CONFIRMADO]` `bin/audit-execution-maintenance.php:62-68` llama `tick()` y duerme 100 ms. `tick()` (`AuditExecutionMaintenance.php:121-186`):

1. `GET audit:execution:version` (`:123`); `ensureGroups()` una vez.
2. `recoverExpired()` (`:127`); un `outbox->flush()` de 100 (`:128`).
3. Si no pausado: `metrics()`; por etapa `capacity = min(100, maxQueued - queued)` (`:134`); por cada uno de los 8 streams: `maintain()`, `XAUTOCLAIM COUNT capacity` (`:142-146`), `XREADGROUP COUNT capacity` (`:157-161`); cada entrada → `intake()`: DLQ+XACK si inválida (`:415-427`), `XACK` individual si el tipo no corresponde (`:429-431`), `admit()` si corresponde (`:433`).
4. `orchestrationAtCapacity()` (metrics + `INFO memory`); si capacidad o ≥10 s → `gc()` + `trimWorkStreams()` (`:174-180`).
5. Heartbeat `SET ... EX 30` (`:181-184`).

`gc()` (`:202-365`) recorre 7 patrones con `scanPattern` (≤1000 claves × ≤10 `SCAN COUNT 1000`), cachea estados completos y, en `job:*:state`, decodifica el JSON completo y lo reenvía como `ARGV[1]` (`:311-330`).

Limitación observada: `[CONFIRMADO en fuente externa]` ciclos de 10,5–30,2 s antes del OOM (`E10`) y réplica de 28,0 s / 7.652 RT (`E14`).

### 5. Estado Objetivo

```
tick():
  t0 = hrtime; deadline = t0 + 2.500 ms
  [fijo] version, ensureGroups
  [unidad] recoverExpired
  [ráfaga] outbox: i=0..19; i>0 ⇒ exige tiempo; flush(100); corta si 0
  si no pausado (con `audit:execution:paused = 1` se omite SOLO el intake; outbox, GC y heartbeat siguen activos):
    [fijo] metrics → capacidad por etapa (compartida entre sus 2 streams)
    para 4 etapas empezando en intake_offset (rota +1 por tick); dentro de cada etapa SIEMPRE .priority y luego .batch:
      si deadline vencido y ya hubo ≥1 ronda en el tick → salir
      [unidad] maintain(stage,lane)
      si capacidad etapa < 1 → siguiente
      [unidad] XAUTOCLAIM COUNT capacidad → procesar lote
      rondas: XREADGROUP COUNT 200 → clasificar → 1 XACK-lote → admit() por coincidente mientras capacidad > 0
              repetir mientras capacidad > 0 y (primera ronda forzada del tick o deadline no vencido)
  [fijo] nowMs, orchestrationAtCapacity
  si (capacidad ∨ now - last_gc_ms ≥ 5.000): gc(max(100, min(500, restante))) ; trimWorkStreams
  [fijo] GET audit:admission:paused → aviso throttled (REQ-F1-13)
  [fijo] 1 eval: heartbeat EX 30 + meta HSET + EXPIRE 60
```

Responsabilidades: `AuditExecutionMaintenance` (orquestación del tick, GC, intake); `AuditStateStore` (lectores compactos, umbral en Lua); `AuditExecutionConfig` (constantes de heartbeat); `BatchJobStore` (TTL de idempotencia); CLI (`resume`, `idem-expire-legacy`).

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| DEC-F1-01 | Caché por llamada `array<string,?string>` de status, local a cada invocación de `gc()`. Queda acotada a ≤ `GC_MAX_ITEMS` (500) entradas porque cada ítem consulta a lo sumo 1 status y `gcBudgetExceeded()` corta al llegar a 500 ítems. El vaciado al alcanzar `GC_STATUS_CACHE_MAX` (4.096) se conserva solo como defensa: con los valores actuales es inalcanzable y protege si en el futuro se sube `GC_MAX_ITEMS` (W-F1-I1) | LRU; sin caché (repite el `eval` por cada tarea de la misma auditoría) | 500 × ~100 B ≪ 1 MiB; la caché reutiliza el status entre tareas de la misma auditoría dentro de la llamada; el tope defensivo es determinista. |
| DEC-F1-02 | Una llamada `gc()` recorre patrones desde `pattern_index`; al agotar un patrón (`cursor=0` tras escanear) avanza al siguiente; se detiene al visitar 7 o al agotar presupuesto; **no** avanza el índice al cortar por presupuesto | Avanzar índice al cortar (pierde `pending_keys` de otro patrón) | Reanudación sin pérdida y con una sola ranura `pending_keys`. Al reanudar `pending_keys` se considera el patrón ya escaneado (`$scanned = $keys !== []`): si el cursor persistido es `0`, agotar las pendientes completa el patrón sin reiniciar el barrido. No inanición: con memoria < 256 MiB, cada llamada procesa ≥1 ítem o 1 página `SCAN` antes de cortar (contador e intervalo se reinician por llamada; con memoria ≥ 256 MiB no hay progreso y lo detecta `gc_budget_stopped`/`memory_bytes`, Sección 19), cada barrido `SCAN` termina en número finito de páginas (0.4), y al completarlo el índice avanza —también cuando la última clave del patrón lanza excepción en `collectKey()`: el `finally` detecta el patrón agotado (`$scanned`, `$keys === []`, cursor `'0'`, sin `$patternAdvanced`) y avanza el índice (V-F1-B1)—; por tanto los 7 patrones se visitan en ciclo con un número finito de llamadas. |
| DEC-F1-03 | Estado GC inválido o ausente ⇒ valores por defecto + `Logger::warning('gc_cursor_state_reset')` | Lanzar excepción | El estado es una pista de progreso; lanzar reintroduce bucle de caída. |
| DEC-F1-04 | Guardar estado GC en `finally`; el ítem que lanza excepción ya fue extraído de `pending_keys`; si con ello el patrón quedó agotado (última clave de la última página), el `finally` también avanza `pattern_index` | Guardar solo al final; no avanzar el índice en `finally` (la clave corrupta final reinicia el `SCAN` del mismo patrón en cada llamada) | Una clave corrupta provoca 1 caída por ciclo de barrido, no un bucle continuo, y no bloquea los demás patrones. La excepción se propaga sin cambios. |
| DEC-F1-05 | Capacidad de intake por etapa compartida y decrementada entre sus 2 streams; la rotación del inicio es por **etapa** (4) y dentro de la etapa `.priority` se procesa siempre antes que `.batch` | Capacidad completa por stream (actual); rotación sobre 8 pares (permitiría `.batch` antes que `.priority` y que `.batch` agotara la capacidad compartida) | Respeta `maxQueued`; preserva la prioridad actual (`AuditExecutionMaintenance.php:102-117` declara `.priority` primero y `:135` recorre en ese orden). |
| DEC-F1-06 | Lectura `COUNT 200` independiente de la capacidad; coincidentes no admitidos quedan en PEL para `XAUTOCLAIM` (5.000 ms) | `COUNT = capacity` (actual, causa `H8`); `XACK` de coincidentes no admitidos (pérdida) | Salta no coincidentes 200 por RT sin perder trabajo. |
| DEC-F1-07 | Presupuesto de intake comprobado solo entre rondas; ≥1 ronda por tick sobre el primer stream con capacidad de la etapa en turno (`.priority` antes que `.batch`) | Comprobar por entrada | Un lote clasificado se confirma siempre completo (sin estados intermedios). |
| DEC-F1-08 | Borrado de `audit:*:state` y `job:*:state` compara `redis.sha1hex(raw)` con `sha1()` PHP | Reenviar el JSON crudo (actual) | Evita ARGV de hasta ~2,3 MB (`E13`). |
| DEC-F1-09 | Sin variables de entorno nuevas; constantes de clase | `Env::get` configurable | Evita cambios en `.env.example`/deploy (`ci.yml:109-129`); hotfix mínimo. |
| DEC-F1-10 | Idempotencia `EX max($ttl, AUDIT_JOB_TTL)` + refresco `EXPIRE GT retention` en GC mientras el job exista | `EX $ttl` (300 s permite duplicados durante el job); sin TTL (actual) | Conserva la intención del diseño (`AuditExecutionMaintenance.php:355`): la clave vive al menos lo que el job. |
| DEC-F1-11 | Heartbeat y meta en un único `eval` al final del tick | Dos llamadas | 1 RT; coherencia temporal `at_ms` = valor del heartbeat. |
| DEC-F1-12 | GC con presupuesto mínimo 100 ms cuando es elegible aunque el deadline esté vencido | Omitir GC | Sin GC no se libera memoria Redis; cota explícita en INV-F1-04. |

#### 6.1 Estrategia de Cambio y Excepciones

| Dimensión | Valor |
| --- | --- |
| Estrategia | Parche excepcional (hotfix acotado) |
| Fila de la compuerta aplicada | Incidente con caída continua (`H11`) y rediseño completo planificado en SDD-BATCH-001 |
| Candidatas descartadas y desempate | Rebuild interno del mantenimiento: descartado por depender de la migración v2 de Fase 2 |
| Fuente del alcance MVP | Solicitud del usuario (división Fase 1/Fase 2, registro `R-PHASE` de SDD-BATCH-001) |

| Regla Exceptuada | Motivo Verificable | Responsable | Superficie | Condición de Retiro | Validación | Rollback |
| --- | --- | --- | --- | --- | --- | --- |
| Compatibilidad con `job:*:state` v1 con audits inline | Fase 2 aún no migra a Hash | pendiente de confirmación | Rama `jobs` de `gc()` | SDD-BATCH-001 desplegada: la rama se reemplaza por lectura compacta | Test CA-F1-05 | Revertir imagen |
| Comando `idem-expire-legacy` | Claves `batch:idem:*` con TTL -1 previas al hotfix | pendiente de confirmación | `bin/audit-execution-maintenance.php` | 0 claves `batch:idem:*` con TTL -1 durante 7 días **y tras desplegar SDD-BATCH-001**, cuya migración `migrate-batch-v2` invoca `expireLegacyIdempotency()` (W-F1-I4) ⇒ eliminar comando | `idem-expire-legacy` devuelve `expired=0` | N/A (comando sin estado) |

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| Redis | base de datos | `redis:7-alpine` (`docker-compose.yml:60`); ≥ 7.0 en vivo pendiente de verificar (`SUP-F1-04`) | `EXPIRE GT`, `redis.sha1hex`, `XACK` múltiple |
| Predis | librería | la de `composer.lock` | `eval` variádico vía `RedisClient` |
| PHP CLI | runtime | imagen `audfact-php` | `hrtime`, `memory_get_usage` |

#### 7.1 Fuentes de Verdad

| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Umbral de heartbeat | Código (`AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS`) | Hoy literal en 3 sitios | Sí: doc histórica `website/docs/sdd-homologacion-semantica-asincrona-ejecucion-justa.md:588` dice «10 s». Resolución: esa spec es histórica; se documenta el nuevo valor en `CHANGELOG.md` sin reescribirla. |
| TTL idempotencia | Código (`BatchJobStore::claimIdempotencyKey`) | Docblock `:298` vs cuerpo `:310` | Sí (docblock promete TTL, código lo ignora): lo resuelve REQ-F1-11. |

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| INV-F1-01: una llamada a `gc()` nunca retiene más de `min(GC_MAX_ITEMS, GC_STATUS_CACHE_MAX)` = 500 status ni ningún arreglo `documents` | `$statuses` local a la llamada + corte en `GC_MAX_ITEMS`; vaciado defensivo en `GC_STATUS_CACHE_MAX`; Lua solo devuelve escalares | CA-F1-01 |
| INV-F1-02: `memory_get_usage(true)` se comprueba antes de cada ítem GC; con ≥256 MiB no se procesan más ítems | `gcBudgetExceeded()` | CA-F1-02 |
| INV-F1-03: tras cualquier reinicio, `gc()` reanuda desde `audit:execution:gc:cursors` | `loadGcState()` | CA-F1-03 |
| INV-F1-04: `T(tick) ≤ 2.500 ms + U_max + I_f + G + F`, con `U_max` = mayor unidad iniciada antes del deadline (una de: `flush(100)`, `maintain()`, `XAUTOCLAIM`+lote, ronda de intake). `I_f` = intake forzada: vale `0` si el deadline no ha vencido al entrar en `intakeAll()`; si vence durante la ráfaga de outbox, `intakeAll()` igual se ejecuta y, como `$rounds === 0`, no corta hasta completar 1 ronda con entradas. Entonces `I_f` = `metrics()` (2 RT) + por cada par visitado hasta esa ronda (`maintain()` + `XAUTOCLAIM` + `XREADGROUP` vacío) + la ronda forzada (`XREADGROUP` + `XACK`-lote + `admit()` de los coincidentes); en el peor caso recorre los 8 pares (W-F1-I2). `G ≤ max(100 ms, …) + 1 ítem GC`. `F` = `GET audit:execution:paused` (1 RT, R4-F1-I1) + `nowMs` (1 RT) + `atCapacity` (**3 RT**: `FairAuditTaskQueue::metrics()` = `eval` + `nowMs`, más `serverInfo('memory')`; ≤ 3 por el cortocircuito de `stagesAtCapacity()`, `AuditAdmissionGuard.php:37-70`) + `loadGcState` (1 RT, una sola vez por tick: `tick()` pasa `$state` a `gc()`) + `GET audit:admission:paused` (1 RT, REQ-F1-13) + heartbeat (1 RT), y si el GC es elegible además `saveGcState` (1 RT) + `trimWorkStreams` (9 RT). Sobrecarga fija total del tick con 8 pares sin trabajo ≈ 46 RT sin GC: version 1, `metrics()` 2, `recoverExpired` ≤ 2, primer `flush` 1, 8 × (`maintain` 2 RT + `XAUTOCLAIM` + `XREADGROUP`) = 32, `F` sin GC = 8 (incluye `GET audit:execution:paused`, que se ejecuta tras la ráfaga de outbox y antes de `intakeAll()`). Con GC elegible son ≈ 56 RT (+10 RT, sin contar los `SCAN`/ítems del propio `gc()`) | Comprobación `hrtime` antes de cada unidad | CA-F1-06 |
| INV-F1-05: ninguna entrada coincidente se confirma sin `admit()` exitoso | `XACK`-lote solo con ids inválidos/no coincidentes; `admit()` confirma internamente | CA-F1-08 |
| INV-F1-06: el mismo umbral (30.000 ms) rige en guarda PHP, Lua de recuperación y `resume` | Constante única | CA-F1-09 |
| INV-F1-07: toda `batch:idem:*` creada tras el despliegue tiene TTL > 0 y ≥ `AUDIT_JOB_TTL` | `SET NX EX` | CA-F1-10 |
| Preservado: borrado GC solo si el valor no cambió desde la lectura | Lua compara `HGET`/`GET`/`sha1` | Tests existentes + CA-F1-04 |

### 9. Modelo de Datos

`[CONFIRMADO]` Sin cambio en claves existentes; nuevas claves (con `REDIS_PREFIX` aplicado por `RedisClient`):

| Clave | Tipo | Campos | TTL | Escritor | Lector |
| --- | --- | --- | --- | --- | --- |
| `audit:execution:gc:cursors` | Hash | `pattern_index` (`0`..`6`), `cursor:tasks`, `cursor:plans`, `cursor:audits`, `cursor:gemini_attempts`, `cursor:jobs`, `cursor:reservations`, `cursor:idempotency` (dígitos), `pending_pattern` (`''` o `0`..`6`), `pending_keys` (JSON lista ≤1000; típico ≤100); campos adicionales permitidos para extensiones (p. ej. Fase 2: `hscan_job_id`, `hscan_cursor`, `reap_job_id`, `reap_cursor`, `cursor:reservations_legacy`, `legacy_done`): se ignoran al leer y se preservan al escribir, `last_gc_ms`, `cycle_started_at_ms` | Sin TTL (≤ ~8 KB) | `AuditExecutionMaintenance::saveGcState()` | `loadGcState()` |
| `audit:execution:heartbeat:meta` | Hash | `tick_duration_ms`, `outbox_flushed`, `intake_admitted`, `intake_acked`, `gc_removed`, `memory_bytes`, `gc_budget_stopped` (`0`/`1`), `admission_paused` (`0`/`1`), `execution_paused` (`0`/`1`), `at_ms` | 60 s | `tick()` | Operación (`redis-cli HGETALL`) |
| `batch:idem:{key}` (existente) | String | `job_id` | **Antes** -1; **Después** `max($ttl, AUDIT_JOB_TTL)`, ampliado con `GT` mientras exista el job | `claimIdempotencyKey`, `gc()` | API, cron |

`cycle_started_at_ms` se fija a `now` cuando `pattern_index` vuelve a `0` (al completar el patrón `idempotency`, en el bucle o en el `finally`), y también al inicio de `gc()` si vale `<= 0` (arranque en frío o estado reiniciado), para no disparar una falsa alarma; sirve para la señal `gc_cycle_age`.

#### Script de Cambio

No hay migración de esquema. Expiración de claves legadas (ejecutada por `idem-expire-legacy`, una llamada por página `SCAN COUNT 1000`):

```lua
-- KEYS = claves batch:idem:* de la página; ARGV[1] = 14400
local n = 0
for i = 1, #KEYS do
  if redis.call('TTL', KEYS[i]) == -1 then
    redis.call('EXPIRE', KEYS[i], tonumber(ARGV[1]))
    n = n + 1
  end
end
return n
```

#### Orden de Ejecución

1. Desplegar imagen. 2. Verificar heartbeat (Sección 14). 3. `ctl idem-expire-legacy`.

#### Migración de Datos

| Origen | Transformación | Destino | Validación |
| --- | --- | --- | --- |
| `batch:idem:*` con TTL -1 | `EXPIRE 14400` | Misma clave con TTL | Segunda ejecución devuelve `expired = 0` |

| Dimensión | Valor |
| --- | --- |
| Estrategia | rolling (sin cambio de formato) |
| Compatibilidad entre versiones | Sí: la versión anterior ignora las 2 claves nuevas |
| Duración esperada de locks | N/A (Lua por página ≤1000 claves) |
| Volumen de datos afectados | `[DESCONOCIDO]` número de `batch:idem:*` legados; `E14` vio 0 en la réplica |
| Estrategia de backfill | N/A |
| Comportamiento durante despliegue parcial | API vieja crea `batch:idem:*` sin TTL; el refresco GC y una reejecución de `idem-expire-legacy` las cubren |

#### Rollback

```bash
docker compose exec -T redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli DEL "${REDIS_PREFIX:-audfact:}audit:execution:gc:cursors" "${REDIS_PREFIX:-audfact:}audit:execution:heartbeat:meta"'
```

Los TTL aplicados a `batch:idem:*` no se revierten (`[INFERIDO]` sin consumidor que requiera persistencia indefinida; la rama GC antigua solo actúa con TTL -1, `AuditExecutionMaintenance.php:357`).

### 10. Contratos

#### Contrato C1: Clave `audit:execution:heartbeat`

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno (clave Redis) |
| Visibilidad | Interno / Temporal |
| Productor | `AuditExecutionMaintenance::tick()` |
| Consumidores | `AuditAdmissionGuard::assertAccepting`, `RECOVER_FAILED_AUDIT_LUA`, `resume`, `ObservabilityController` |
| Versionado | No |
| Compatibilidad requerida | Ambas |
| Enforcement | Runtime check + tests |

- **Antes:** valor ms (Redis `TIME`), `EX 30`; edad máxima aceptada 10.000 ms.
- **Después:** mismo valor y `EX AuditExecutionConfig::HEARTBEAT_TTL_SECONDS` (30); edad máxima `HEARTBEAT_MAX_AGE_MS` (30.000). Campos: sin cambios. Backward/forward: compatibles (solo cambia el umbral del lector).

#### Contrato C2: `BatchJobStore::claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string`

| Dimensión | Valor |
| --- | --- |
| Tipo | Mensaje interno (método PHP) |
| Visibilidad | Interno |
| Productor | `BatchJobStore` |
| Consumidores | `AuditController.php:430`, `schedule-daily-batches.php:174`, doble `AuditControllerTest.php:763` |
| Versionado | No |
| Compatibilidad requerida | Backward |
| Enforcement | Tests |

- **Antes:** `$ttl` ignorado; clave sin TTL.
- **Después:** firma idéntica; `$ttl <= 0` → `InvalidArgumentException('idempotency_ttl_invalid')`; clave con `EX max($ttl, AUDIT_JOB_TTL)`. Retorno idéntico.

#### Contrato C3: CLI `bin/audit-execution-maintenance.php idem-expire-legacy`

Nuevo. Salida `{"scanned":<int>,"expired":<int>}` en stdout, exit 0; error → exit 1 con el mensaje existente (`:89-92`).

#### Contrato C4: Clave `audit:execution:heartbeat:meta`

Nuevo, Interno/Temporal, sin consumidores en código; Sección 9.

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| REQ-F1-01 | Status acotado | 13.1 `statusOf`, 13.2 `getAuditStatus` | CA-F1-01, T-U1 |
| REQ-F1-02 | Metadatos de retención | 13.2 `readAuditRetentionMetadata`, 13.1 rama `audits` | CA-F1-04, T-I2 |
| REQ-F1-03 | Estado GC persistido | 13.1 `loadGcState/saveGcState`, `finally` de `gc()` | CA-F1-03, T-I3, T-I14 |
| REQ-F1-04 | Presupuesto GC | 13.1 `gcBudgetExceeded`, `memoryBytes` | CA-F1-02, CA-F1-17, T-I3, T-I4, T-U5, T-U6 |
| REQ-F1-05 | Job v1 de uno en uno | 13.1 rama `jobs` | CA-F1-05, CA-F1-17, T-I5, T-U6 |
| REQ-F1-06 | Heartbeat unificado | 13.3, 13.4, 13.2, 13.6 | CA-F1-09, T-U2, T-I6 |
| REQ-F1-07 | Meta del heartbeat | 13.1 `writeHeartbeat` | CA-F1-07, T-I7 |
| REQ-F1-08 | Deadline de tick | 13.1 `tick` | CA-F1-06, T-I8 |
| REQ-F1-09 | Outbox en ráfaga | 13.1 `tick` | CA-F1-11, T-I9 |
| REQ-F1-10 | Intake por lotes con `.priority` antes que `.batch` | 13.1 `intakeAll/intakeStream/processEntries/ackBatch` | CA-F1-08, CA-F1-12, CA-F1-16, T-I10, T-I11, T-I13 |
| REQ-F1-11 | TTL idempotencia | 13.5, 13.9 | CA-F1-10, CA-F1-15, T-U3, T-U4 |
| REQ-F1-12 | Legados y refresco GT | 13.1 rama `idempotency`, 13.6 | CA-F1-13, T-I12 |
| REQ-F1-13 | Visibilidad de pausas (P-F1-01) | 13.1 `tick`/`writeHeartbeat`, 13.6 `pause`/`observe` | CA-F1-18, T-I15 |

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| Admisión HTTP | Heartbeat | Deja de dar 503 por heartbeat ausente; acepta edad ≤30 s | 13.4 | `AuditAdmissionGuard.php:25` |
| Recuperación DLQ | Heartbeat en Lua | Ídem | 13.2 | `AuditStateStore.php:139` |
| `extractors` | Intake | Lag desciende ≥200 entradas/tick en el stream en turno | 13.1 | `H8` |
| Outbox | `tick` | Hasta 2.000 efectos/tick en vez de 100 | 13.1 | `E12`, `H7` |
| Cron y API | Idempotencia | Claves con TTL; misma deduplicación durante la vida del job | 13.5 | `BatchJobStore.php:305-311` |
| `GET /metrics/async` | Heartbeat | Sin cambio (solo lee el valor) | Ninguno | `ObservabilityController.php:148-151` |
| Otros workers | Imagen nueva | No invocan código modificado salvo `AuditStateStore::recoverFailedAudit` | Ninguno | grep 0.1 |

### 13. Cambios por Archivo

#### 13.1 [MODIFY] `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php`

**Constantes (antes, `:12-13`):** `GC_PAGE_SIZE = 1000; GC_SCAN_STEPS = 10;`
**Después:**

```php
public const GC_PAGE_SIZE = 100;
public const TICK_BUDGET_MS = 2500;
public const GC_INTERVAL_MS = 5000;
public const GC_MAX_ITEMS = 500;
public const GC_TIME_BUDGET_MS = 500;
public const GC_MIN_BUDGET_MS = 100;
public const GC_MEMORY_SOFT_LIMIT_BYTES = 268435456; // 256 MiB
public const GC_STATUS_CACHE_MAX = 4096;   // tope defensivo: con GC_MAX_ITEMS = 500 la caché local nunca lo alcanza (DEC-F1-01)
public const OUTBOX_MAX_BATCHES_PER_TICK = 20;
public const INTAKE_READ_COUNT = 200;
public const INTAKE_ACK_CHUNK = 1000;
public const LEGACY_IDEM_TTL_SECONDS = 14400;
public const GC_PENDING_MAX = 1000;
public const GC_CURSORS_KEY = 'audit:execution:gc:cursors';
public const HEARTBEAT_META_KEY = 'audit:execution:heartbeat:meta';
private const HEARTBEAT_META_TTL_SECONDS = 60;
private const GC_PATTERNS = [
    ['name' => 'tasks', 'pattern' => 'audit:task:v2:*'],
    ['name' => 'plans', 'pattern' => 'audit:semantic:v2:*'],
    ['name' => 'audits', 'pattern' => 'audit:*:state'],
    ['name' => 'gemini_attempts', 'pattern' => 'gemini:attempt:v2:*'],
    ['name' => 'jobs', 'pattern' => 'job:*:state'],
    ['name' => 'reservations', 'pattern' => 'audit:reservation:disid:*'],
    ['name' => 'idempotency', 'pattern' => 'batch:idem:*'],
];
```

`GC_SCAN_STEPS` se elimina (0.3). Se añade `use Core\Logger;`. Propiedades `$gcCursors` y `$lastGcMs` (`:89-90`) se eliminan; se añaden `private int $intakeOffset = 0; private bool $gcBudgetStopped = false; private int $lastAdmissionPausedWarnMs = 0;` y las constantes `public const ADMISSION_PAUSED_WARN_INTERVAL_MS = 300000;` y `public const ADMISSION_PAUSED_CLI_NOTICE = "AVISO: audit:admission:paused=1 sin TTL; worker-batch no leera audit.batch.inbox ni la API admitira hasta ejecutar resume\n";` (REQ-F1-13, X-F1-I2). El constructor (`:93`) gana un tercer parámetro opcional `private readonly ?\Closure $memoryProbe = null` (compatible con los llamadores actuales: `bin/...:26`, 2 tests) y se añade `private function memoryBytes(): int { return $this->memoryProbe !== null ? (int) ($this->memoryProbe)() : memory_get_usage(true); }`; es la única lectura de memoria de `gcBudgetExceeded()`, de la rama `jobs` y de `writeHeartbeat()` (costura de test para T-U6, la clase es `final`).

**Lua (antes `:44-47`, `:70-73`):** `if raw ~= ARGV[1]` / `if redis.call('GET', KEYS[1]) ~= ARGV[1]`. **Después:** `local raw = redis.call('GET', KEYS[1]); if not raw or redis.sha1hex(raw) ~= ARGV[1] then return 0 end` en ambos; los llamadores envían `sha1($raw)` (rama `jobs`) y `$meta['sha1']` (rama `audits`).

**`tick()` (antes `:121-186`, Sección 4). Después:**

```php
public function tick(): int
{
    $deadline = hrtime(true) + self::TICK_BUDGET_MS * 1_000_000;
    $started = hrtime(true);
    // version check y ensureGroups idénticos a :123-126
    ($this->quota ??= new GeminiQuotaBudget($this->redis))->recoverExpired();
    $flushed = 0;
    for ($i = 0; $i < self::OUTBOX_MAX_BATCHES_PER_TICK; $i++) {
        if ($i > 0 && hrtime(true) >= $deadline) { break; }
        $n = $this->outbox->flush(100);
        $flushed += $n;
        if ($n === 0) { break; }
    }
    $admitted = 0; $acked = 0;
    $executionPaused = $this->redis->get('audit:execution:paused') === '1';   // misma lectura que antes, ahora guardada
    if (!$executionPaused) {
        [$admitted, $acked] = $this->intakeAll($deadline);
    }
    $now = $this->queue->nowMs();
    $atCapacity = (new AuditAdmissionGuard($this->redis, $this->config))->orchestrationAtCapacity();
    $removed = 0; $this->gcBudgetStopped = false;
    $state = $this->loadGcState();
    if ($atCapacity || $now - $state['last_gc_ms'] >= self::GC_INTERVAL_MS) {
        $remainingMs = intdiv(max(0, $deadline - hrtime(true)), 1_000_000);
        $removed = $this->gc(max(self::GC_MIN_BUDGET_MS, min(self::GC_TIME_BUDGET_MS, $remainingMs)), $now, $state);
        (new AuditEventPublisher($this->redis))->trimWorkStreams();
    }
    // REQ-F1-13 (P-F1-01): la pausa de admisión no tiene TTL y bloquea la intake de worker-batch sin otro aviso
    $admissionPaused = $this->redis->get('audit:admission:paused') === '1';
    if ($admissionPaused && ($this->lastAdmissionPausedWarnMs === 0
            || $now - $this->lastAdmissionPausedWarnMs >= self::ADMISSION_PAUSED_WARN_INTERVAL_MS)) {   // X-F1-I2
        $this->lastAdmissionPausedWarnMs = $now;
        Logger::warning('Audit admission paused', ['execution_paused' => $executionPaused ? 1 : 0,
            'action' => 'php bin/audit-execution-maintenance.php resume']);
    }
    if (!$admissionPaused) { $this->lastAdmissionPausedWarnMs = 0; }   // una pausa nueva avisa en su primer tick
    $this->writeHeartbeat(intdiv(hrtime(true) - $started, 1_000_000), $flushed, $admitted, $acked, $removed,
        [], $admissionPaused, $executionPaused);
    return $flushed + $admitted;
}
```

**`intakeAll(int $deadline): array{int,int}` (nuevo, reemplaza `:129-173`):**

```php
$metrics = $this->queue->metrics();
$stages = self::stages();              // 4 etapas; 'streams' ya ordenados [.priority, .batch] (:102-117)
$names = array_keys($stages);
$capacity = []; // por etapa: min(100, maxQueued - (queued+delayed)) como en :133-134
$offset = $this->intakeOffset;
$this->intakeOffset = ($this->intakeOffset + 1) % count($names);
$rounds = 0; $admitted = 0; $acked = 0;
for ($k = 0; $k < count($names); $k++) {
    $name = $names[($offset + $k) % count($names)];
    $stage = AuditTaskStage::from($name);
    foreach ($stages[$name]['streams'] as $stream) {      // .priority SIEMPRE antes que .batch
        if ($rounds > 0 && hrtime(true) >= $deadline) { break 2; }
        $this->queue->maintain($stage, str_ends_with($stream, '.priority') ? AuditLane::PRIORITY : AuditLane::BATCH);
        if ($capacity[$name] < 1) { continue; }
        [$a, $c, $r] = $this->intakeStream($stage, $stream, $stages[$name]['group'], $capacity[$name], $deadline, $rounds === 0);
        $admitted += $a; $acked += $c; $rounds += $r;
    }
}
return [$admitted, $acked];
```

`$this->intakeOffset` rota sobre 4 etapas. Si la etapa en turno no tiene capacidad o sus streams están vacíos, `$rounds` sigue en 0 y el siguiente stream recibe la ronda forzada. `.batch` solo recibe la capacidad que `.priority` no consumió en ese tick.

**`intakeStream(AuditTaskStage $stage, string $stream, string $group, int &$capacity, int $deadline, bool $forceFirst): array{int,int,int}` (nuevo):**

```php
$admitted = 0; $acked = 0; $rounds = 0;
// 1. XAUTOCLAIM idéntico a :142-153 (COUNT $capacity, cursor $this->reclaimCursors; validaciones
//    intake_reclaim_invalid / intake_pending_message_deleted preservadas)
if ($raw[1] !== []) {
    [$a, $c] = $this->processEntries($stage, $stream, $group, $raw[1], $capacity);
    $admitted += $a; $acked += $c; $rounds++;      // cuenta como ronda: habilita el corte por deadline en intakeAll()
}
// 2. Rondas XREADGROUP
while ($capacity > 0 && (($forceFirst && $rounds === 0) || hrtime(true) < $deadline)) {
    $raw = /* XREADGROUP GROUP $group execution-intake COUNT self::INTAKE_READ_COUNT STREAMS $stream > (mismo eval de :157-161) */;
    if ($raw === null || $raw === []) { break; }                 // vacío
    /* respuesta inválida → RuntimeException('intake_read_invalid') */
    [$a, $c] = $this->processEntries($stage, $stream, $group, $raw[0][1], $capacity);
    $admitted += $a; $acked += $c; $rounds++;
    if (count($raw[0][1]) < self::INTAKE_READ_COUNT) { break; }   // página corta ⇒ no quedan entradas nuevas (evita una XREADGROUP vacía extra)
}
return [$admitted, $acked, $rounds];
```

**`processEntries(AuditTaskStage $stage, string $stream, string $group, array $entries, int &$capacity): array{int,int}` (reemplaza `intake()` `:404-434`), devuelve `[admitidos, confirmadosPorLote]`:** por entrada en orden: `intake_message_invalid` si la forma es inválida (igual que `:406-408`); evento ilegible → publicar DLQ exactamente como `:416-425` y añadir id a `$ackIds`; tipo ∉ `$stage->eventTypes()` → añadir id a `$ackIds`; resto → `$matching[] = [$id, $event]`. Luego `ackBatch($stream, $group, $ackIds)` y `$ackedBatch = count($ackIds)`; después, para cada coincidente mientras `$capacity > 0`: `admit()` verdadero → `$capacity--`, `$admitted++`; falso (etapa llena, `FairAuditTaskQueue.php:247`) → `$capacity = 0`. Los coincidentes no intentados quedan en PEL.

**Alcance de `intake_acked`:** suma solo los ids confirmados por `ackBatch()` (inválidos + no coincidentes). Los `XACK` internos de `admit()` (`FairAuditTaskQueue.php:240-260`) **no** se cuentan; quedan reflejados en `intake_admitted`.

**`ackBatch(string $stream, string $group, array $ids): void`:** por cada trozo de ≤1000 ids, `eval('return redis.call("XACK",KEYS[1],ARGV[1],unpack(ARGV,2))', [$stream], array_merge([$group], $chunk))`; lista vacía → sin llamada.

**`gc(int $budgetMs = self::GC_TIME_BUDGET_MS, ?int $nowMs = null, ?array $state = null): int` (antes `:202-365`):** `$state` permite a `tick()` reutilizar el estado ya cargado (un solo `HGETALL` por tick, INV-F1-04); llamadores sin estado (`bin/...:85`, tests) lo omiten.

```php
$this->gcBudgetStopped = false;   // gc() es public: cada llamada directa empieza limpia (W-F1-M1); tick() lo reinicia también para ticks sin GC
$retention = /* idéntico a :204-208 */;
$now = $nowMs ?? $this->queue->nowMs();
$stateStore = new AuditStateStore($this->redis);
$statuses = [];
$statusOf = static function (string $auditId) use ($stateStore, &$statuses): ?string {
    if (!array_key_exists($auditId, $statuses)) {
        if (count($statuses) >= self::GC_STATUS_CACHE_MAX) { $statuses = []; }   // defensivo (inalcanzable con GC_MAX_ITEMS = 500)
        $statuses[$auditId] = $stateStore->getAuditStatus($auditId);
    }
    return $statuses[$auditId];
};
$state ??= $this->loadGcState();
if ($state['cycle_started_at_ms'] <= 0) { $state['cycle_started_at_ms'] = $now; }   // arranque en frío (V-F1-I5)
$deadline = hrtime(true) + $budgetMs * 1_000_000;
$items = 0; $count = 0; $seen = []; $visited = 0; $keys = []; $idx = $state['pattern_index'];
$scanned = false; $patternAdvanced = true;   // valores neutros si se sale antes de entrar al bucle
try {
    while ($visited < count(self::GC_PATTERNS)) {
        $idx = $state['pattern_index']; $p = self::GC_PATTERNS[$idx];
        $patternAdvanced = false;
        $keys = $state['pending_pattern'] === $idx ? $state['pending_keys'] : [];   // pending_pattern ya es ?int (loadGcState)
        // Las pendientes provienen de un SCAN ya hecho cuyo cursor está persistido: si ese cursor es '0',
        // al agotarlas el patrón está completo y NO se reinicia el barrido (DEC-F1-02).
        $scanned = $keys !== [];
        while (true) {
            if ($keys === []) {
                if ($scanned && $state['cursors'][$p['name']] === '0') { break; }
                if ($this->gcBudgetExceeded($items, $deadline)) { return $count; }
                [$cursor, $page] = $this->redis->scanKeys($p['pattern'], $state['cursors'][$p['name']], self::GC_PAGE_SIZE);
                $state['cursors'][$p['name']] = $cursor; $scanned = true;
                $keys = array_values(array_filter($page, static fn(string $k): bool => !isset($seen[$k])));
                if (count($keys) > self::GC_PENDING_MAX) {   // SCAN COUNT es orientativo
                    Logger::warning('gc_scan_page_truncated', ['pattern' => $p['name'], 'keys' => count($keys)]);
                    $keys = array_slice($keys, 0, self::GC_PENDING_MAX); // el resto se revisita en el siguiente barrido
                }
                continue;
            }
            if ($this->gcBudgetExceeded($items, $deadline)) { return $count; }
            $key = array_shift($keys);
            $seen[$key] = true; $items++;
            $collected = $this->collectKey($p['name'], $key, $now, $retention, $statusOf, $stateStore);
            if ($collected === null) {             // rama jobs sin memoria: reencolar y cortar por presupuesto
                array_unshift($keys, $key);
                unset($seen[$key]);
                $items--;
                $this->gcBudgetStopped = true;
                return $count;
            }
            $count += $collected;
        }
        $state['pattern_index'] = ($idx + 1) % count(self::GC_PATTERNS);
        if ($state['pattern_index'] === 0) { $state['cycle_started_at_ms'] = $now; }
        $patternAdvanced = true;
        $visited++;
    }
    return $count;
} finally {
    // Excepción en la última clave de la última página: el patrón quedó agotado aunque no se llegó al
    // `break`; avanzar aquí evita reiniciar el SCAN del mismo patrón en bucle (V-F1-B1, DEC-F1-04).
    if (!$patternAdvanced && $scanned && $keys === [] && $state['cursors'][$p['name']] === '0') {
        $state['pattern_index'] = ($idx + 1) % count(self::GC_PATTERNS);
        if ($state['pattern_index'] === 0) { $state['cycle_started_at_ms'] = $now; }
    }
    // Corte por presupuesto, excepción o fin: las claves aún no extraídas de la página quedan pendientes.
    $state['pending_pattern'] = $keys === [] ? null : $idx;
    $state['pending_keys'] = $keys;
    $state['last_gc_ms'] = $now;
    $this->saveGcState($state);
    if ($this->gcBudgetStopped) { gc_collect_cycles(); }
}
```

`gcBudgetExceeded(int $items, int $deadline): bool` → `true` y `$this->gcBudgetStopped = true` si `$items >= GC_MAX_ITEMS || hrtime(true) >= $deadline || $this->memoryBytes() >= GC_MEMORY_SOFT_LIMIT_BYTES`.

**`collectKey(string $name, string $key, int $now, int $retention, \Closure $statusOf, AuditStateStore $stateStore): ?int`** — un `match ($name)` con los cuerpos de bucle actuales; devuelve el número de claves borradas (`int ≥ 0`: `GC_DELETE_AUDIT_STATE_LUA` ejecuta `DEL KEYS[1] KEYS[2]` y puede devolver `2`, `AuditExecutionMaintenance.php:54`) o `null` **solo** en la rama `jobs` cuando no hay memoria para leer el job (la clave no se consume). Cambia solo:

| Rama | Antes | Después |
| --- | --- | --- |
| `tasks` (`:222-243`) | `$root = $getAudit(...)`; `$root !== null && !isTerminalStatus($root['status'])` | `$status = $statusOf(...)`; `$status !== null && !isTerminalStatus($status)` |
| `plans` (`:248-266`) | `$audit === null \|\| isTerminalStatus($audit['status'] ?? '')` | `$status === null \|\| isTerminalStatus($status)` |
| `audits` (`:270-284`) | `get` + `json_decode` completo; ARGV `$raw` | `$meta = $stateStore->readAuditRetentionMetadata($key)`; `null` → 0; mismas condiciones sobre `$meta['status']`, `completed_at`/`updated_at`; ARGV `$meta['sha1']` |
| `gemini_attempts` (`:288-308`) | `$root` completo | `$status` con la misma regla |
| `jobs` (`:312-330`) | decodifica y reenvía `$raw` | antes de `GET`: si `$this->memoryBytes() >= GC_MEMORY_SOFT_LIMIT_BYTES` → devolver `null` sin leer (`gc()` reencola la clave en `pending_keys` y corta con `gc_budget_stopped = 1`); tras decidir: ARGV `[sha1($raw), $jobId]`; `unset($raw, $job, $refs)` antes de retornar |
| `reservations` (`:334-349`) | `$root` completo | `$status` con la misma regla; patrón `disid` sin cambios |
| `idempotency` (`:353-361`) | `EXPIRE` si job ausente y TTL -1 | Lua: `if EXISTS(job)==1 then return redis.call("EXPIRE",KEYS[1],ARGV[1],"GT") end; if TTL(KEYS[1])==-1 then return redis.call("EXPIRE",KEYS[1],ARGV[1]) end; return 0` con `ARGV[1] = $retention`; el retorno no suma a `$count`. Clave legada con `TTL == -1` y job vivo: `EXPIRE ... GT` no aplica sobre una clave sin TTL (devuelve `0`), de modo que la clave conserva `TTL -1` hasta que `idem-expire-legacy` le fije 14.400 s (después el refresco `GT` la amplía a `retention`) o hasta que el job desaparezca y la segunda condición le asigne `retention` (coherente con `R-IDEM-LEGACY`) |

Para que la rama `jobs` reencole la clave cuando falta memoria, `collectKey` devuelve `null` en ese caso y `gc()` hace `array_unshift($keys, $key); $items--; $this->gcBudgetStopped = true; return $count;` (pseudocódigo de `gc()`); la clave queda en `pending_keys` vía `finally`.

**`loadGcState(): array`** — `eval('return redis.call("HGETALL",KEYS[1])', [self::GC_CURSORS_KEY])`; no-array o lista impar → defaults + `Logger::warning('gc_cursor_state_reset')`. Validación **por campo conocido**, sin reiniciar los demás: `pattern_index` `^[0-6]$` (si no, 0); cada cursor `ctype_digit` (si no, `'0'` solo ese cursor); `pending_pattern`: string que cumple `/^[0-6]$/` → `(int)` (`HGETALL` devuelve siempre strings y `gc()` compara con `===` contra un `int` bajo `strict_types=1`), `''` o ausente → `null`, cualquier otro valor → `null` y se descartan `pending_keys = []` con `Logger::warning('gc_pending_keys_discarded', ['count' => n])`; `pending_keys` ausente o `''` (arranque en frío: `HGETALL` devuelve `[]`) → `[]` **sin** warning; si no, JSON lista de strings con `count ≤ GC_PENDING_MAX` (1000) — si es inválida o mayor, se descartan **solo** `pending_keys`/`pending_pattern` con `Logger::warning('gc_pending_keys_discarded', ['count' => n])`, donde `n` = número de elementos si es una lista JSON válida pero mayor que el tope, y `n = 0` si el JSON es inválido o no es una lista (las claves se revisitan en el siguiente barrido); `last_gc_ms`/`cycle_started_at_ms` dígitos o 0. **Campos desconocidos** (campos adicionales permitidos para extensiones, p. ej. Fase 2) se ignoran sin warning ni reinicio.
**`saveGcState(array $state): void`** — un `eval('return redis.call("HSET",KEYS[1],unpack(ARGV))', [self::GC_CURSORS_KEY], [...pares campo/valor de los campos conocidos...])`. `HSET` no borra campos no enviados: los campos de extensiones **se preservan**; nunca se usa `DEL` ni reescritura completa del Hash. `pending_pattern = null` se serializa como `''`; `pending_keys` se serializa **explícitamente** con `json_encode($state['pending_keys'], JSON_THROW_ON_ERROR)` (lista vacía ⇒ `'[]'`). `RedisClient::eval()` codifica los arrays de ARGV (`core/RedisClient.php:433-435`), pero `IsolatedAuditRedis::eval()` pasa `$argv` directo a Predis (`tests/Support/IsolatedAuditRedis.php:32-36`) y fallaría en `tests/Integration` (W-F1-M3). Todos los demás valores se envían como string/int escalar. `GC_PENDING_MAX` está en el bloque de constantes.

**`private function writeHeartbeat(int $durationMs, int $flushed, int $admitted, int $acked, int $removed, array $extraMeta = [], bool $admissionPaused = false, bool $executionPaused = false): void`** — reemplaza `:181-184`. Los dos últimos parámetros (REQ-F1-13) van después de `$extraMeta` para no romper la llamada de Fase 2. Es privado (solo lo invoca `tick()`; Fase 2 lo extiende desde la misma clase); T-I7 lo invoca directamente con `ReflectionMethod::invoke()` (W-F1-M3). `$extraMeta` (`array<string,int|string>`) permite a extensiones (Fase 2: `jobs_reaped`) añadir campos sin cambiar el script:

```php
unset($extraMeta['at_ms']);   // at_ms lo fija el script con TIME; HSET aplicaría el último par repetido
$fields = ['tick_duration_ms' => $durationMs, 'outbox_flushed' => $flushed, 'intake_admitted' => $admitted,
    'intake_acked' => $acked, 'gc_removed' => $removed, 'memory_bytes' => $this->memoryBytes(),
    'gc_budget_stopped' => $this->gcBudgetStopped ? 1 : 0,
    'admission_paused' => $admissionPaused ? 1 : 0, 'execution_paused' => $executionPaused ? 1 : 0] + $extraMeta;   // los 9 base no se sobrescriben
$argv = [AuditExecutionConfig::HEARTBEAT_TTL_SECONDS, self::HEARTBEAT_META_TTL_SECONDS];
foreach ($fields as $field => $value) { $argv[] = $field; $argv[] = $value; }
$this->redis->eval(
    'local t=redis.call("TIME"); local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000); '
    . 'redis.call("SET",KEYS[1],now,"EX",ARGV[1]); '
    . 'redis.call("HSET",KEYS[2],"at_ms",now,unpack(ARGV,3)); '
    . 'redis.call("EXPIRE",KEYS[2],ARGV[2]); return now',
    ['audit:execution:heartbeat', self::HEARTBEAT_META_KEY],
    $argv
);
```

**`expireLegacyIdempotency(): array{scanned:int,expired:int}` (nuevo):**

```php
$cursor = '0'; $scanned = 0; $expired = 0;
do {
    [$cursor, $page] = $this->redis->scanKeys('batch:idem:*', $cursor, 1000);
    $scanned += count($page);
    if ($page !== []) {
        $expired += (int) $this->redis->eval(/* Lua de la Sección 9 */, $page, [self::LEGACY_IDEM_TTL_SECONDS]);
    }
} while ($cursor !== '0');
return ['scanned' => $scanned, 'expired' => $expired];
```

`scanPattern()` (`:370-385`) se elimina (sin otros llamadores, 0.1).

#### 13.2 [MODIFY] `app/Services/Audit/Pipeline/AuditStateStore.php`

- `recoverFailedAudit()` (`:115-120`): añadir `AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS` como 7.º ARGV.
- `RECOVER_FAILED_AUDIT_LUA` (`:139`): **antes** `now-heartbeat>10000`; **después** `now-heartbeat>tonumber(ARGV[7])`.
- Añadir junto a `getAudit()` (`:187-196`, sin modificarlo):

```php
private const AUDIT_STATUS_LUA = <<<'LUA'
    local raw = redis.call('GET', KEYS[1])
    if not raw then return false end
    local a = cjson.decode(raw)
    if type(a.status) ~= 'string' then return '' end
    return a.status
LUA;
private const AUDIT_RETENTION_LUA = <<<'LUA'
    local raw = redis.call('GET', KEYS[1])
    if not raw then return false end
    local a = cjson.decode(raw)
    return cjson.encode({audit_id = a.audit_id, status = a.status,
        completed_at = a.completed_at, updated_at = a.updated_at, sha1 = redis.sha1hex(raw)})
LUA;

public function getAuditStatus(string $auditId): ?string
{   // null si no existe; \RuntimeException si Redis no disponible; \Predis\PredisException si falla el script
    $status = $this->redis->eval(self::AUDIT_STATUS_LUA, [self::auditKey($auditId)]);
    return $status === null ? null : (string) $status;
}

/** @return array{audit_id:mixed,status:mixed,completed_at:mixed,updated_at:mixed,sha1:string}|null */
public function readAuditRetentionMetadata(string $key): ?array
{
    $raw = $this->redis->eval(self::AUDIT_RETENTION_LUA, [$key]);
    return $raw === null ? null : self::decodeJson((string) $raw, 'AuditStateStore');
}
```

A diferencia de `getAudit()` (`:189-193`, que envuelve la `RedisUnavailableException` de `get()`), ambos usan `RedisClient::eval()`, que **no** lanza `RedisUnavailableException`: con Redis no disponible lanza `\RuntimeException('Redis no disponible para eval')` (`core/RedisClient.php:425-426`) y los fallos del script o de conexión en `client->eval()` propagan `\Predis\PredisException` sin envolver (`:441`). No se añade `try/catch`: `gc()` deja propagar ambas, igual que hoy propaga los errores de `get`. Un JSON inválido produce error Lua (`PredisException`), equivalente al `JSON_THROW_ON_ERROR` actual (`AuditExecutionMaintenance.php:274`).

#### 13.3 [MODIFY] `app/Services/Audit/Pipeline/AuditExecutionConfig.php`

Tras `:19` añadir `public const HEARTBEAT_TTL_SECONDS = 30;` y `public const HEARTBEAT_MAX_AGE_MS = 30000;`.

#### 13.4 [MODIFY] `app/Services/Audit/Pipeline/AuditAdmissionGuard.php`

`:25` **antes** `$now - (int) $heartbeat > 10000`; **después** `$now - (int) $heartbeat > AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS`.

#### 13.5 [MODIFY] `app/Services/Audit/Pipeline/BatchJobStore.php` — solo `claimIdempotencyKey()` (`:305-315`)

```php
public function claimIdempotencyKey(string $key, string $jobId, int $ttl): ?string
{
    if ($ttl <= 0) {
        throw new \InvalidArgumentException('idempotency_ttl_invalid');
    }
    $redisKey = self::idempotencyKey($key);
    try {
        // Un único eval atómico: SET NX EX y, si ya existía, GET en el mismo script (sin ventana de 2 RT
        // en la que la clave pueda expirar entre ambas llamadas y producir un falso reclamo).
        $existing = $this->redis->eval(
            'local r=redis.call("SET",KEYS[1],ARGV[1],"NX","EX",ARGV[2]); if r then return false end; '
            . 'return redis.call("GET",KEYS[1])',
            [$redisKey], [$jobId, max($ttl, self::jobTtlSeconds())]
        );
        return $existing === null ? null : (string) $existing;   // Lua false → null PHP (0.4)
    } catch (RedisUnavailableException|\Predis\PredisException $e) {
        // eval() no lanza RedisUnavailableException hoy (se conserva por compatibilidad con dobles); PredisException
        // (transporte o error del script) no deriva de \RuntimeException: se envuelve para que async() responda 503.
        throw new RuntimeException('Redis no disponible al reclamar llave de idempotencia', 0, $e);
    }
}
```

Propagación de errores (W-F1-M6): con Redis no disponible, `RedisClient::eval()` lanza directamente `\RuntimeException('Redis no disponible para eval')` (`core/RedisClient.php:425-426`), que no se captura aquí y llega al `catch (\RuntimeException)` de `AuditController::async()` (503). Los fallos de Predis (`\Predis\PredisException`, `:441`; clase abstracta que extiende `\Exception`, no `\RuntimeException`) se envuelven en `RuntimeException` con `previous`, y así también producen 503 en lugar de escapar como error no controlado. El antiguo `catch (RedisUnavailableException)` aislado (`BatchJobStore.php:312-314`) era inalcanzable con `eval()` y se sustituye por esta captura conjunta.

Docblock `:298`: «TTL efectivo = max($ttl, AUDIT_JOB_TTL); el GC lo amplía mientras exista el job».

#### 13.6 [MODIFY] `bin/audit-execution-maintenance.php`

- `:41`: `10000` → `AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS`.
- `:59-61`: añadir al contexto `'tick_budget_ms' => AuditExecutionMaintenance::TICK_BUDGET_MS, 'gc_max_items' => AuditExecutionMaintenance::GC_MAX_ITEMS, 'gc_time_budget_ms' => AuditExecutionMaintenance::GC_TIME_BUDGET_MS, 'gc_memory_soft_limit_bytes' => AuditExecutionMaintenance::GC_MEMORY_SOFT_LIMIT_BYTES, 'heartbeat_max_age_ms' => AuditExecutionConfig::HEARTBEAT_MAX_AGE_MS`.
- `:82-87`: añadir `'idem-expire-legacy' => $maintenance->expireLegacyIdempotency(),`. La guarda `$argc > 2` (`:18`) no cambia.
- `:28-37` (REQ-F1-13, P-F1-01): tras el `eval` de `pause` y de `observe`, `fwrite(STDERR, AuditExecutionMaintenance::ADMISSION_PAUSED_CLI_NOTICE);` (X-F1-I2). La salida JSON de stdout y el código de salida no cambian.

#### 13.7 [MODIFY/NEW] Tests — ver Sección 16.

- MODIFIED: `AuditMemoryCompactionAndGcTest.php`, `AuditAdmissionGuardTest.php`, `RedisTtlConfigTest.php`, `AuditDlqRecoveryRedisTest.php`, `AuditControllerTest.php`.
- NEW: `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php` (T-I2..T-I5, T-I7..T-I15; requiere `RUN_REDIS_INTEGRATION=1` como el resto de `tests/Integration`).

#### 13.8 [MODIFY] `CHANGELOG.md`

Entrada «SDD-BATCH-000»: heartbeat 30 s, `heartbeat:meta`, `gc:cursors`, `idem-expire-legacy`, TTL de idempotencia, prioridad `.priority` en intake, 500 controlado por TTL de idempotencia inválido, visibilidad de pausas (`admission_paused`/`execution_paused` en `heartbeat:meta`, aviso cada 5 min y `stderr` en `pause`/`observe`, REQ-F1-13).

#### 13.9 [MODIFY] `app/Controllers/AuditController.php` — solo `async()` (`:396`), bloque `:429-433`

**Antes:**

```php
try {
    $existingJobId = $jobStore->claimIdempotencyKey((string) $idempotencyKey, $jobId, $ttl);
} catch (\RuntimeException $e) {
    Response::error('Error validando idempotencia: ' . $e->getMessage(), 503);
}
```

**Después:**

```php
try {
    $existingJobId = $jobStore->claimIdempotencyKey((string) $idempotencyKey, $jobId, $ttl);
} catch (\InvalidArgumentException $e) {
    Logger::error('AuditController::async idempotencia mal configurada', [
        'setting' => 'AUDIT_IDEMPOTENCY_KEY_TTL', 'ttl' => $ttl, 'error' => $e->getMessage(),
    ]);
    Response::error('Configuración de idempotencia inválida', 500);
} catch (\RuntimeException $e) {
    Response::error('Error validando idempotencia: ' . $e->getMessage(), 503);
}
```

`[CONFIRMADO]` `Core\Logger` ya está importado (`AuditController.php:17`); `InvalidArgumentException` no es subclase de `RuntimeException` (jerarquía SPL: `LogicException`), por lo que el orden de `catch` es indiferente pero se deja explícito. No se valida el TTL antes de `claimIdempotencyKey` para no duplicar la regla (fuente de verdad única en `BatchJobStore`).

### 14. Plan de Migración

#### Prerequisitos

CI verde (unitarios + `redis-integration`); imagen publicada; acceso a `ctl` (`deploy-production.yml:574`).

#### Ejecución

1. Registrar baseline: `GET audit:execution:heartbeat`, `ZCARD audit:outbox:v2:pending`, `XINFO GROUPS audit.documents.batch`, `docker compose logs -t --tail 50 worker-execution-maintenance`.
2. Despliegue estándar (`deploy-production.yml`: disparo automático por `workflow_run` tras «Publish Images - AudFact», o `workflow_dispatch` con `image_tag=<sha_nuevo>`; el workflow actual solo tiene el input `image_tag`, `deploy-production.yml:7-13`); recrea todos los servicios con la misma imagen; solo `worker-execution-maintenance`, `php` (API, cron) y quien invoque `recoverFailedAudit` ejecutan código modificado. No se pausa el pipeline.
3. Esperar 60 s; ejecutar validaciones posteriores.
4. `ctl idem-expire-legacy`; repetir hasta `expired = 0`.
5. Si `audit:admission:paused` quedó a `1` por incidente previo: `ctl resume` (ahora con umbral 30 s).

#### Validaciones Previas

`ctl durability-check` exit 0; `audit:execution:version = 2`; el operador confirma `redis_version` ≥ 7.0 (`redis-server --version` o `redis-cli INFO server`) y `appendonly yes`/`appendfsync always` (`CONFIG GET`), lo que cierra `SUP-F1-04`. Si la versión es < 7.0, no se despliega.

#### Validaciones Posteriores

- `HGETALL audit:execution:heartbeat:meta` presente y `at_ms` con edad < 30.000 ms en 10 lecturas consecutivas cada 6 s.
- 0 líneas `Allowed memory size` en `docker compose logs -t --since 15m worker-execution-maintenance`.
- `HGET audit:execution:gc:cursors pattern_index` cambia al menos una vez en 5 min.
- `HGET audit:execution:heartbeat:meta admission_paused` = `0` tras el paso 5; si es `1`, la intake de `worker-batch` sigue cerrada (P-F1-01).

#### Rollback

1. Redesplegar el tag anterior con el flujo estándar: `workflow_dispatch` de `deploy-production.yml` con `image_tag=<sha_anterior>` («Use a commit SHA for rollback», `deploy-production.yml:9-12`).
2. Ejecutar el script de rollback de la Sección 9.
3. Volverá el comportamiento OOM documentado (`H11`); mitigación temporal: `ctl pause` y escalar.

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Hash `gc:cursors` corrupto | Defaults + warning | Log `gc_cursor_state_reset`; `gc()` retorna sin excepción |
| Clave borrada entre `SCAN` y lectura | Rama devuelve 0 | Sin excepción |
| Valor cambiado entre lectura y borrado | Lua compara `sha1`/valor y no borra | Clave persiste |
| `SCAN` devuelve clave repetida en la misma llamada | Se procesa una vez | `$seen` |
| Job v1 de 2,3 MB con memoria ≥256 MiB | No se lee; clave vuelve a `pending_keys` | `gc_budget_stopped = 1` |
| Stream con 0 entradas | `XREADGROUP` vacío → siguiente par | Sin RT extra |
| Etapa llena (`admit` = 0) | Capacidad a 0; coincidentes restantes en PEL | `XPENDING` > 0, reclamados tras 5 s |
| Entrada inválida | DLQ + incluida en `XACK`-lote | 1 evento `dead_letter`, PEL sin el id |
| Caída entre DLQ y `XACK`-lote | Reentrega → DLQ con mismo `event_id` determinista (`:424`) | Igual que hoy |
| `pause` activo | Sin intake; GC y heartbeat siguen | `intake_admitted = 0` en meta |
| `AUDIT_IDEMPOTENCY_KEY_TTL=0` | `claimIdempotencyKey` lanza `InvalidArgumentException`; `async()` la captura | HTTP 500 `Configuración de idempotencia inválida` + `Logger::error`; sin job creado ni evento publicado (T-U4) |
| Job vivo > `AUDIT_JOB_TTL` | GC amplía la clave idem con `GT` cada ciclo | `TTL batch:idem:*` ≥ retention mientras exista `job:*:state` |
| Dos instancias simultáneas (recreate) | Ambas escriben cursores; GC idempotente | Sin borrados indebidos (Lua comparan valor) |

### 16. Testing

#### Nuevos Tests

- **T-U1** (`AuditMemoryCompactionAndGcTest::testGcCachesOnlyStatusAndBoundsCache`): stub con 600 tareas terminales `audit:task:v2:*` en 6 páginas de 100 (`SCAN` devuelve cursor no nulo hasta la 6.ª). Las 500 primeras referencian 250 auditorías, cada una en 2 tareas consecutivas de la misma página (`audit_id = 'a-' . intdiv($i, 2)`); las 100 restantes, auditorías nuevas. `eval` de `AUDIT_STATUS_LUA` devuelve `'failed'`; `memoryProbe` constante muy por debajo del límite y `gc(60000)` para que solo actúe el corte por ítems. Esperado: (a) `get` nunca se llama con `audit:*:state` (0 lecturas completas); (b) exactamente **250** `eval` de `AUDIT_STATUS_LUA` (la caché se reutiliza dentro de la llamada); (c) corte en `GC_MAX_ITEMS`: 500 tareas procesadas, 5 `scanKeys` (la 6.ª página no se lee), `gcBudgetStopped === true` (vía `ReflectionProperty`), `pending_keys` vacío y `cursor:tasks` = cursor de la 5.ª página en el `HSET` de `gc:cursors`, con `pattern_index` en `0`. La cota de la caché (≤ 500, INV-F1-01) se deduce de (b) y (c).
- **T-U2** (`AuditAdmissionGuardTest::testHeartbeatAgeThreshold`, data provider): `now = 1.000.000`; heartbeat `now-29.999` acepta, `now-30.001` rechaza 503, `null` rechaza, futuro rechaza.
- **T-U3** (`RedisTtlConfigTest`): `claimIdempotencyKey('k','j',300)` envía `["j", 86400]` en un único `eval` cuyo script contiene `"NX","EX"` y `GET` (ninguna llamada a `get()`); con `AUDIT_JOB_TTL=100` envía `300`; stub `eval` → `null` ⇒ retorno `null` (reclamada), stub `eval` → `'job-previo'` ⇒ retorno `'job-previo'`; `$ttl = 0` y `-1` lanzan `InvalidArgumentException` sin llamar a `eval`; stub `eval` que lanza `new \Predis\Response\ServerException('ERR x')` ⇒ `RuntimeException` cuyo `getPrevious()` es esa `ServerException` (W-F1-M6).
- **T-U4** (`AuditControllerTest::testAsyncReturns500WhenIdempotencyTtlIsInvalid`): el doble de `BatchJobStore` (`:750-766`) gana `public bool $claimIdempotencyKeyThrowsInvalid = false;` y, si es `true`, `claimIdempotencyKey` lanza `new \InvalidArgumentException('idempotency_ttl_invalid')`. Con header `X-Idempotency-Key` presente: respuesta 500, mensaje `Configuración de idempotencia inválida`, `$publisher->published` vacío y sin `initJob`.
- **T-U5** (`AuditMemoryCompactionAndGcTest::testGcStopsWhenTimeBudgetExpires`): stub con 300 claves `audit:task:v2:*` terminales de **300 auditorías distintas** (sin repetir `audit_id`, para que la caché de status no evite el `eval` y el corte por tiempo sea determinista, W-F1-M2) en páginas de 100 y `usleep(5000)` en cada `eval` de `AUDIT_STATUS_LUA`; `gc(20)` → retorna con menos de 300 ítems procesados; el `HSET` de `gc:cursors` lleva `pending_keys` no vacío y `pending_pattern = '0'`; `gcBudgetStopped === true` (vía `ReflectionProperty`); `pattern_index` sigue en `0`.
- **T-U6** (`AuditMemoryCompactionAndGcTest::testGcRequeuesJobWhenMemorySoftLimitReached`): `AuditExecutionMaintenance` construida con `memoryProbe` que devuelve `GC_MEMORY_SOFT_LIMIT_BYTES - 1` hasta la primera comprobación de la rama `jobs` y `GC_MEMORY_SOFT_LIMIT_BYTES` desde ahí; estado inicial `pattern_index = 4` (`jobs`) con 2 claves `job:*:state`. Esperado: ningún `get('job:*:state')`; la clave sigue en `pending_keys` (primera posición) con `pending_pattern = '4'`; `gcBudgetStopped === true`; `gc()` retorna `0`. Variante con la sonda siempre ≥ límite: `gc()` no hace `SCAN` y retorna `0`; tras `tick()` el `HSET` de `heartbeat:meta` lleva `gc_budget_stopped = 1`.
- **T-I2** (nuevo `tests/Integration/AuditExecutionMaintenanceTickRedisTest.php`): `audit:{id}:state` terminal vencido de 100 KB → `gc()` lo borra; modificado tras `readAuditRetentionMetadata` → no lo borra.
- **T-I3** (mismo archivo): 750 claves `audit:task:v2:*` terminales; `gc(budget)` con `GC_MAX_ITEMS` alcanzado → `HGET gc:cursors pending_keys` no vacío; nueva instancia de `AuditExecutionMaintenance` → siguiente `gc()` continúa sin repetir las ya borradas y termina con 0 claves.
- **T-I4**: 1.200 auditorías de ~40 KB referenciadas por tareas terminales; `memory_get_peak_usage(true)` medido alrededor de `gc()` crece < 32 MiB.
- **T-I5**: `job:*:state` terminal vencido con `audits` de 3.000 entradas (~2 MB) → borrado; `ZSCORE jobs:index` nulo; script recibió ARGV de 40 bytes.
- **T-I6** (`AuditDlqRecoveryRedisTest`): heartbeat `now-20000` → recuperación aceptada.
- **T-I7**: tras `tick()`, `TTL audit:execution:heartbeat` ∈ (0,30], `HGETALL heartbeat:meta` contiene **al menos** los 10 campos base de la Sección 9 (se permiten campos extra, p. ej. `jobs_reaped` de SDD-BATCH-001), `at_ms` = valor del heartbeat, `TTL` ∈ (0,60]; además `writeHeartbeat(..., ['at_ms' => 1])`, invocado vía `new \ReflectionMethod(AuditExecutionMaintenance::class, 'writeHeartbeat')` (método privado), no altera `at_ms`.
- **T-I8**: outbox con 3.000 efectos + 1.500 no coincidentes; `tick()` termina y `tick_duration_ms ≤ 2.500 + U_outbox + U_intake_forzada + G + F` (INV-F1-04). Los cuatro términos se miden en el mismo test con el `RedisClient` instrumentado (marcas `hrtime` por llamada): `U_outbox` = duración máxima de un `flush(100)`; `U_intake_forzada` = duración de `intakeAll()` si empezó con el deadline vencido (`0` en otro caso); `G` = duración de `gc()` + `trimWorkStreams()`; `F` = resto de llamadas fijas (`GET audit:execution:paused`, `nowMs`, `atCapacity`, `loadGcState`, `GET audit:admission:paused`, heartbeat; X-F1-M1, R4-F1-I1). Sobre `IsolatedAuditRedis` limpio, `gc:cursors` no existe (`last_gc_ms = 0`), así que el GC siempre es elegible y `G > 0`. Variante sin GC: presembrar `gc:cursors.last_gc_ms = nowMs()` antes del `tick()`, con la orquestación sin capacidad llena ⇒ `G = 0` (sin `gc()`, `saveGcState` ni `trimWorkStreams`); se mantiene el término `U_intake_forzada`.
- **T-I9**: 450 efectos en outbox → un `tick()` publica 450 (`ZCARD = 0`).
- **T-I10**: `audit.documents.batch` con 1.500 `document_registered` + 50 `document_downloaded` intercalados. **Aislamiento de `policy` (W-F1-B1):** la etapa `policy` comparte `audit.documents.batch` (`AuditExecutionMaintenance.php:110-113`) y ninguno de los dos tipos sembrados está en sus `eventTypes()` (`AuditTaskStage.php:19`). Sin aislarla, `ackBatch()` confirmaría también las 1.550 entradas en `policy` y `intake_acked` valdría 3.050. Por eso el setup crea el grupo `extractors` en `0` y, **después** de sembrar las 1.550 entradas, el grupo `policy` en `$` (`XGROUP CREATE audit.documents.batch policy $`). `ensureGroups()` ignora `BUSYGROUP` (`AuditExecutionMaintenance.php:395`) y no lo recoloca, así que `policy` no recibe ninguna entrada (`XREADGROUP` vacío, 0 confirmaciones). El `RedisClient` instrumentado cuenta `eval` **solo de llamadas cuyo grupo (`ARGV`) es `extractors`**: ≤8 `XREADGROUP` (la 8.ª ronda, con 150 < 200, corta sin 9.ª lectura), ≤8 `XACK`-lote y 50 `admit`. Esperado: `XINFO GROUPS` de `extractors` con `lag = 0` y `pending = 0` (PEL vacía: `ADMIT` hace `XACK` de cada entrada admitida, `FairAuditTaskQueue.php:260`); `metrics()['extraction']['batch']['queued'] === 50` (contador `extraction:batch:pending` de `FairAuditTaskQueue::COUNTS`, R4-F1-M2); `XINFO GROUPS` de `policy` con `lag = 0` y `pending = 0`; `heartbeat:meta.intake_acked === 1.500` (solo `ackBatch()` de `extractors`; `policy` aporta 0). **Variante de latencia (CA-F1-12, W-F1-M5):** el mismo setup, con `policy` aislado igual y las 200 primeras entradas del stream no coincidentes; el `RedisClient` instrumentado ejecuta `usleep(20000)` antes de cada llamada Redis. Un único `tick()` ⇒ ≥1 ronda `XREADGROUP` del grupo `extractors` (la forzada) y `heartbeat:meta.intake_acked ≥ 200`, aunque el deadline venza durante el tick.
- **T-I11**: capacidad 10 y 50 coincidentes → 10 admitidos, 40 en PEL; tras `XAUTOCLAIM` (idle forzado con `XCLAIM ... IDLE 6000` en el test) se admiten en ticks siguientes; ninguna entrada coincidente confirmada sin tarea.
- **T-I12**: `batch:idem:x` TTL -1 → `expireLegacyIdempotency()` devuelve `expired=1`, `TTL ≤ 14400`; segunda llamada `expired=0`. Con `job:{id}:state` presente y idem TTL 100, `gc()` lo amplía a `retention`.
- **T-I13** (prioridad de intake): etapa `extraction` con capacidad 10 (`maxQueued` y contadores sembrados), 20 `document_downloaded` en `audit.documents.priority` y 20 en `audit.documents.batch`; un `tick()` con `intakeOffset` apuntando a `extraction` → las 10 tareas admitidas provienen de `.priority`; ninguna de `.batch`. Repetir con cada valor inicial de `intakeOffset` (0..3): el resultado no cambia.
- **T-I14** (reanudación tras excepción en la última clave, V-F1-B1/B3): patrón `audits` con una única clave `audit:{id}:state` corrupta (JSON inválido) y cursor de `SCAN` que vuelve a `0` en esa página; primer `gc()` lanza; `HGET gc:cursors pattern_index` = `3` (avanzó) y `pending_keys` vacío; un segundo `gc()` en nueva instancia (sembrando claves borrables en `gemini:attempt:v2:*`) empieza su `SCAN` por el patrón `gemini_attempts` y las borra antes de volver a `audits` (que vuelve a lanzar como 7.º patrón: 1 caída por ciclo, DEC-F1-04). Variante: `pending_pattern = '2'` y `pending_keys` sembrados en Redis ⇒ la nueva instancia los procesa (comparación `int` tras `loadGcState()`); `pending_pattern = 'x'` ⇒ warning `gc_pending_keys_discarded` y `pending_keys` vacío.
- **T-I15** (REQ-F1-13, P-F1-01): sin `audit:admission:paused`, tras `tick()` `HGET heartbeat:meta admission_paused` = `0` y `execution_paused` = `0`; con `SET audit:admission:paused 1`, el siguiente `tick()` publica `admission_paused = 1` y `execution_paused = 0`; con además `audit:execution:paused = 1`, `execution_paused = 1` e `intake_acked = 0`. Tras `DEL` de ambas, vuelven a `0`. Throttling (X-F1-I2), con `$p = new \ReflectionProperty(AuditExecutionMaintenance::class, 'lastAdmissionPausedWarnMs')`: tras el 1.er `tick()` con la pausa, `$p->getValue($m) > 0`; un 2.º `tick()` inmediato no cambia el valor (no hay 2.º aviso dentro de 5 min); tras `DEL audit:admission:paused` y otro `tick()`, vale `0`. La emisión del `Logger::warning` va dentro de la misma rama que fija la propiedad, por lo que el valor es su testigo.

`T-I1` queda **reservado sin uso** (numeración heredada de borradores; los tests de integración empiezan en `T-I2` para no renumerar las referencias de Secciones 11 y 18).

#### Tests Modificados

- `AuditMemoryCompactionAndGcTest::testGcImmediatelyDeletesTerminalAuditTasksAndSemanticPlansInSingleStep` (`:111-187`): el stub responde `[]` al `HGETALL` de `gc:cursors`, `1` al `HSET`, `'failed'` a `AUDIT_STATUS_LUA`; la aserción `:185` pasa a contar `eval` de status = 1.
- `AuditDlqRecoveryRedisTest::admissionBarriers` (`:168-174`): sin cambios de datos; se añade T-I6.

#### Tests Eliminados

Ninguno.

#### Verificaciones Manuales

- **VM-1:** en QA con el dataset del diagnóstico, 30 min de `run`: 0 `Allowed memory size`, heartbeat nunca `NULL` (muestreo 5 s) y, en cada muestra, `HGET audit:execution:heartbeat:meta tick_duration_ms` (descartando muestras con `at_ms` repetido); al final se calcula el p99 de esas muestras (≥ 300) y debe ser ≤ 6.000 ms (CA-F1-06). **Junto a cada muestra se registra la latencia Redis** vista desde el contenedor `worker-execution-maintenance` (p. ej. 20 `PING` cronometrados por muestra), y se informa su p95 en la misma ventana (W-F1-M8). Así se separa una regresión de `tick()` de la contención o el `fsync` de Redis (`appendfsync always`): la corrida de revisión w7k observó en QA p95 ≈ 85 ms (85,56–85,81 ms; `[CONFIRMADO en fuente externa]` local/QA, 0.6), frente a p90 = 16,62 ms de `E13`. Si el p99 de `tick_duration_ms` supera 6.000 ms con p95 de Redis muy por encima de `E13`, el resultado se imputa a `SUP-F1-02`/`R-MAINTAIN` (latencia de una unidad `maintain()` de hasta 402 RT) y no se considera una regresión del deadline.
- **VM-2:** cada 60 s durante 30 min, `XINFO GROUPS audit.documents.batch` (`lag`) y `XPENDING audit.documents.batch extractors` (count). **Regla primaria:** la suma `lag + pending` de `extractors` es monótona decreciente mientras no haya generación nueva; un descenso de `lag` con `pending` creciente **no** cuenta como mejora. **Techo de `pending` (secundario):** bajo saturación continua, `pending ≤ 200 × ⌈5.000 / (T̄ + 100)⌉`, donde `T̄` es la media de `tick_duration_ms` de `heartbeat:meta` en la misma ventana y `100` ms el `usleep` del bucle (`bin/audit-execution-maintenance.php:67`): número real de ticks que caben antes de que `XAUTOCLAIM` (5.000 ms) reclame los remanentes. Al cesar la saturación (capacidad libre en `extraction`), `pending` debe drenar de forma monótona hasta < 200 en ≤ 5 min.

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| R-GC-CYCLE: si la cardinalidad productiva es alta, entonces un barrido completo de 7 patrones tarda más (SCAN COUNT 100 con páginas vacías consume tiempo, no ítems) | rendimiento | Media | Señal `gc_cycle_age` (19); si > 30 min, reducir `GC_INTERVAL_MS` en hotfix siguiente |
| R-MAINTAIN: si `maintain()` procesa 200 ids con latencia p90 (~16 ms), entonces una unidad dura ~6,4 s y el tick excede la cota objetivo (con el p95 ≈ 85 ms observado en QA en w7k, el riesgo aumenta; VM-1 registra ambas métricas) | rendimiento | Media | Señal `tick_duration_ms`; la rotación limita a 1 `maintain` tras deadline; si p99 > 6.000 ms en producción, abrir spec independiente para acotar `FairAuditTaskQueue::maintain()` (SDD-BATCH-001 no lo modifica) |
| R-HB-EXT: si un monitor externo usa 10 s, entonces alertas desalineadas | operativo | Baja | Comunicar en CHANGELOG; `SUP-F1-03` |
| R-IDEM-ZERO: si `AUDIT_IDEMPOTENCY_KEY_TTL ≤ 0`, entonces `POST /audit/async` responde 500 controlado | operativo | Baja | `.env.example:135` = 300; deploy usa `${...:-300}` (`deploy-production.yml:436`); captura explícita y log en 13.9; T-U4; CA-F1-15 |
| R-IDEM-LEGACY: si un job vivo tiene clave legada con TTL -1, entonces `idem-expire-legacy` fija 14.400 s y el refresco `GT` la amplía a `retention` en el siguiente ciclo GC | consistencia de datos | Baja | Rama `idempotency` de GC |
| R-PEL: si muchos coincidentes quedan en PEL, entonces se re-entregan cada ≥5 s vía `XAUTOCLAIM` y el `lag` de `extractors` desciende en falso (el trabajo se traslada a PEL) | rendimiento | Media | <200 por stream y tick; regla primaria `lag + pending` decreciente en VM-2 y señal 19; techo de `XPENDING extractors` expresado según la cadencia real de ticks en 5 s (`200 × ⌈5.000 / (T̄ + 100)⌉`) y drenaje al cesar la saturación |
| R-ROLLBACK-OOM: si se revierte, entonces vuelve `H11` | operativo | Alta | `ctl pause` + escalar; forward-fix preferente |
| R-ADM-PAUSE (P-F1-01): si `observe`/`pause` deja `audit:admission:paused = 1` y `resume` aborta por capacidad o heartbeat, entonces `worker-batch` no lee `audit.batch.inbox` y la API responde 503 indefinidamente | operativo | Alta | REQ-F1-13 lo hace visible (`heartbeat:meta`, aviso cada 5 min, `stderr`); la bandera sigue sin TTL a propósito (una pausa de mantenimiento no debe caducar sola); el operador ejecuta `resume` cuando el aviso aparezca |
| R-SQL-HANG (diagnóstico de producción, P-F2-01): hasta desplegar SDD-BATCH-001, una consulta de candidatos sin timeout puede retener un `worker-batch` durante horas y, con las 4 réplicas ocupadas, ningún otro cliente avanza | operativo | Alta | Fuera de alcance aquí (timeout `DEC-12` de SDD-BATCH-001). Mitigación operativa: si `docker logs` de `worker-batch` muestra `SqlServerConnectionExecutor: reintento transitorio` con `elapsed_ms` > 1.800.000, o no hay `Executed SQL audit batch candidates` en 30 min con `audit.batch.inbox` pendiente, `docker compose restart worker-batch` |

### 18. Criterios de Aceptación

- **CA-F1-01:** T-U1 pasa: `gc()` no invoca `get('audit:*:state')`, reutiliza el status cacheado (500 tareas de 250 auditorías ⇒ 250 `AUDIT_STATUS_LUA`) y corta en `GC_MAX_ITEMS` = 500 ítems, por lo que no retiene más de 500 status por llamada.
- **CA-F1-02:** T-I4 pasa: pico de memoria de `gc()` < 32 MiB sobre 1.200 auditorías de 40 KB.
- **CA-F1-03:** T-I3 y T-I14 pasan: tras reinstanciar, el GC continúa desde `audit:execution:gc:cursors` (incluido `pending_pattern` leído como string) y una excepción en la última clave de un patrón no impide avanzar al siguiente.
- **CA-F1-04:** T-I2 pasa (borrado por `sha1`, no borrado si cambió).
- **CA-F1-05:** T-I5 pasa (ARGV del borrado de job = 40 bytes de `sha1` + `job_id`).
- **CA-F1-06:** T-I8 pasa; en VM-1, p99 de `heartbeat:meta.tick_duration_ms` ≤ 6.000 ms.
- **CA-F1-07:** T-I7 pasa (heartbeat TTL ≤30; meta con **al menos** los 10 campos base —campos extra permitidos— y TTL ≤60; `at_ms` no sobrescribible por `$extraMeta`).
- **CA-F1-08:** T-I10 y T-I11 pasan. T-I10 (con `policy` aislado en el setup): ante 1.500 no coincidentes + 50 coincidentes el intake del grupo `extractors` emite ≤ ⌈1.550/200⌉ = 8 `XREADGROUP` (corte tras la ronda corta, sin lectura vacía extra) + 8 `XACK`-lote + 50 `admit` (≤ 66 RT, excluidos `maintain`, `XAUTOCLAIM` y las llamadas del grupo `policy`); con Redis local (p50 ≤ 2 ms) las 50 se admiten en 1 tick y `heartbeat:meta.intake_acked = 1.500`. T-I11: con capacidad 10 y 50 coincidentes, 10 admitidos y 40 en PEL, admitidos en ticks posteriores vía `XAUTOCLAIM`, sin confirmar ninguna coincidente sin tarea (INV-F1-05).
- **CA-F1-09:** T-U2 y T-I6 pasan; `grep -rn "> 10000\|>10000" app/Services/Audit/Pipeline bin` sin coincidencias de heartbeat.
- **CA-F1-10:** T-U3 pasa; en producción `TTL batch:idem:<nueva>` > 0 tras un `POST /audit/async`.
- **CA-F1-11:** T-I9 pasa (450 efectos en 1 tick).
- **CA-F1-12:** con cualquier latencia, cada `tick()` ejecuta ≥1 ronda de intake sobre el primer par en turno con capacidad ≥1; si ese stream tiene ≥200 entradas no coincidentes pendientes de entrega, confirma ≥200 (variante de latencia de T-I10: `RedisClient` instrumentado con `usleep(20000)` por llamada y `policy` aislado).
- **CA-F1-13:** T-I12 pasa; en producción `idem-expire-legacy` repetido devuelve `expired = 0`.
- **CA-F1-14 (rollback):** en producción, dentro de 15 min tras el despliegue: 0 `Allowed memory size` y heartbeat con edad < 30.000 ms en ≥ 99 % de muestras a 5 s. Incumplimiento ⇒ condición de rollback (Sección 20).
- **CA-F1-15:** T-U4 pasa: con `AUDIT_IDEMPOTENCY_KEY_TTL ≤ 0`, `POST /audit/async` responde 500 `Configuración de idempotencia inválida` y registra `Logger::error`.
- **CA-F1-16:** T-I13 pasa: dentro de una etapa, `.priority` consume la capacidad compartida antes que `.batch`, para todo `intakeOffset`.
- **CA-F1-17:** T-U5 y T-U6 pasan: `gc()` corta por `budgetMs` vencido y por `GC_MEMORY_SOFT_LIMIT_BYTES` dejando `pending_keys` persistido (la clave `job:*:state` no leída se reencola en primera posición) y `gc_budget_stopped = 1`.
- **CA-F1-18:** T-I15 pasa; en producción, con `audit:admission:paused = 1`, `heartbeat:meta.admission_paused = 1` y `docker compose logs --since 6m worker-execution-maintenance` contiene 1 o 2 líneas `Audit admission paused` (no una por tick), comprobable solo si hay una pausa activa en una ventana de mantenimiento; `grep -c 'ADMISSION_PAUSED_CLI_NOTICE' bin/audit-execution-maintenance.php` = `2` (ramas `pause` y `observe`, X-F1-I2).

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| Reinicios OOM | Log | Cada 19–42 s (`E20`, QA) | 0 | `docker logs -t` | ≥1 en 15 min | Rollback |
| Edad del heartbeat | Métrica | `NULL` (`E20`, `E21`, QA) | < 3.500 ms típico | `GET audit:execution:heartbeat` / `heartbeatAgeMs` | > 30.000 ms en 3 muestras seguidas | Rollback |
| `tick_duration_ms` | Métrica | 10,5–30,2 s por ciclo (`E10`, QA) | ≤ 2.500 ms + `U_max` + `I_f` + `G` + `F` (INV-F1-04) | `heartbeat:meta` (+ latencia Redis p95 de VM-1 para interpretar) | p99 > 6.000 ms en 1 h | Investigar (`SUP-F1-02`) |
| `memory_bytes` | Métrica | `507,9 MiB / 512 MiB` (`E11`, QA); `406,3 MiB / 512 MiB` (`79,35 %`, `Up 42 h`, `P-F1-03`, prod) | < 300 MiB | `heartbeat:meta` | > 400 MiB | Investigar |
| `gc_budget_stopped` | Métrica | N/A | 0/1 alternante bajo carga | `heartbeat:meta` | 1 en 100 % de ticks con GC durante 1 h | Investigar (`R-GC-CYCLE`) |
| `gc_cycle_age` = `now - cycle_started_at_ms` | Métrica | N/A (cursores en RAM) | < 10 min | `gc:cursors` (`cycle_started_at_ms` nunca `0` tras el primer `gc()`, V-F1-I5) | > 30 min | Escalar |
| `extractors lag` | Métrica | 9.940–21.541 (`E8`, `E20`, QA) | `lag + pending` descendente (el `lag` solo no basta: `R-PEL`) | `XINFO GROUPS audit.documents.batch` + `XPENDING` | `lag + pending` sin descenso en 30 min con capacidad libre | Investigar |
| `extractors` pendiente (PEL) | Métrica | `[DESCONOCIDO]` (no medido en el diagnóstico) | Regla primaria: `lag + pending` decreciente; techo bajo saturación `200 × ⌈5.000 / (T̄ + 100)⌉` (`T̄` = media de `tick_duration_ms`; ticks que caben antes del `XAUTOCLAIM` a 5 s); drena a < 200 en ≤ 5 min al cesar la saturación | `XPENDING audit.documents.batch extractors` (count) + `heartbeat:meta.tick_duration_ms` | `lag + pending` sin descenso en 30 min con capacidad libre; o `pending` por encima del techo calculado durante 10 min; o sin drenaje a < 200 en 5 min con capacidad libre | Investigar (`R-PEL`) |
| Outbox pendiente | Métrica | 30.732–33.076 (`E12`, `E20`, QA) | Drena ≤ 2.000/tick | `ZCARD audit:outbox:v2:pending` / `outboxPending` | Creciente 30 min con `outbox_flushed` < 100 | Investigar |
| `admission_paused` | Métrica/Log | Sin señal (P-F1-01) | `0` fuera de mantenimiento | `heartbeat:meta` + `Audit admission paused` en logs de mantenimiento | `1` durante > 15 min fuera de una ventana de mantenimiento | `ctl resume`; si aborta, revisar heartbeat y capacidad |
| 503 `audit_capacity_unavailable` | Log/HTTP | Continuo con heartbeat `NULL` `[INFERIDO]` | Solo por capacidad/pausa | Logs API | >0 con heartbeat fresco y sin capacidad llena | Investigar |

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Directo (workflow estándar) |
| Orden entre productores y consumidores | Simultáneo: productor del heartbeat y lectores cambian en la misma imagen; el umbral nuevo (30 s) es más permisivo que el viejo (10 s), por lo que lectores viejos con productor nuevo siguen aceptando |
| Coexistencia entre versiones | Sí, segundos durante `up -d` (`deploy-production.yml:577`) |
| Compatibilidad requerida durante rollout | Ambas (claves nuevas ignoradas por la versión vieja) |
| Condición para avanzar rollout | CA-F1-14 cumplido a los 15 min |
| Condición para detener rollout | `tick_duration_ms` p99 > 6.000 ms o `memory_bytes` > 400 MiB |
| Condición de rollback | ≥1 `Allowed memory size` en 15 min, o heartbeat > 30.000 ms en 3 muestras seguidas |
| Acción de rollback | Sección 14 «Rollback» |
| Tiempo máximo para iniciar rollback | 15 min desde la detección |
| Responsable de decisión | Responsable de operación AudFact (pendiente de confirmación) |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Entidades persistentes definidas | PASS | Sección 9 (2 claves nuevas + `batch:idem:*`) |
| Columnas mencionadas existen | PASS | Sin SQL; campos Redis definidos en 9 |
| Contratos documentados con clasificación | PASS | C1–C4 |
| Requisitos con trazabilidad | PASS | Sección 11 (13/13; REQ-F1-10 y REQ-F1-11 ampliados; REQ-F1-13 por P-F1-01) |
| Consumidores analizados | PASS | 0.2; externo del umbral como `SUP-F1-03` |
| Migraciones con rollback | PASS | Sección 9 «Rollback» |
| Referencias definidas | PASS | Todos los símbolos nuevos especificados en 13 |
| Compatibilidad con evidencia | PASS | 0.4, Sección 20 |
| Criterios verificables | PASS | CA-F1-01..18 con test o comando (18 criterios; 20 tests nuevos: T-U1..T-U6 y T-I2..T-I15, con T-I6 en `AuditDlqRecoveryRedisTest` y T-I1 reservado; 2 VM) |
| Perímetro de tests | PASS | `AuditExecutionMaintenanceTickRedisTest.php` (NEW) en 0.1 y 13.7 (V-F1-B5) |
| Revisión verificada v4s | PASS | V-F1-B1..B5, I1..I7, M1..M3, M5 aplicadas (registro inicial); `M4` fuera de alcance (SDD-BATCH-001) |
| Revisión verificada w7k | PASS | W-F1-B1, I1, I2, I4(c), M1–M6 y M8 aplicadas; W-F1-I3, I4(a)(b), I5 y M7 asignadas a SDD-BATCH-001 (registradas sin aplicar); hechos de Redis no verificables en el sandbox → `SUP-F1-04` |
| Revisión verificada 3.ª, 4.ª y 5.ª pasada + diagnóstico prod | PASS | `X-F1-I1..I2`, `X-F1-M1..M2`, `R4-F1-I1`, `R4-F1-M1..M3`, `R5-F1-M1..M2` y `P-F1-01..03` incorporados y ordenados cronológicamente |
| Observabilidad | PASS | Sección 19 |
| Rollout | PASS | Sección 20 |

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Decisión implícita? | No | DEC-F1-01..12 cubren caché, recorrido, fallos, capacidad, lectura, presupuesto, `sha1`, configuración, TTL, heartbeat, GC mínimo |
| ¿Contrato sin documentar? | No | C1–C4; `gc()` cambia firma con parámetros opcionales (`$nowMs`, `$state`; llamadores `bin/...:85`, tests); el constructor gana `?\Closure $memoryProbe = null` opcional (llamadores `bin/...:26`, 2 tests); `collectKey(): ?int`, `processEntries(): array{int,int}` y `writeHeartbeat()` son privados (T-I7 usa `ReflectionMethod`) |
| ¿Consumidor no analizado? | No | 0.2 con búsquedas de 0.1; externo declarado |
| ¿Migración sin rollback? | No | Sección 9 |
| ¿Dato persistido sin migración? | No | `batch:idem:*` legados → `idem-expire-legacy` |
| ¿Afirmación sin clasificar o sobreclasificada? | No | Cifras de QA y del RCA productivo (`P-F1-01`, `P-F1-03`) clasificadas como `[CONFIRMADO en fuente externa]`; impacto OOM `[INFERIDO]` |
| ¿Referencias huérfanas? | No | `GC_SCAN_STEPS` solo en `scratch/` ignorado |
| ¿Decisión sin registrar o cambio ambiguo? | No | Sección 13 con pseudocódigo y Lua literales |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta | Regresión | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | Entrypoint que invoque algo eliminado | Runtime | NO | `scanPattern`, `GC_SCAN_STEPS`, `intake()` sin llamadores fuera de la clase (0.1) |
| 2 | Paso de build dependiente | Build | NO | Sin dependencias nuevas (Sección 7) |
| 3 | Pipeline con flujo distinto | Pipeline | NO | `ci.yml:103-129`; sin `Env::get` nuevos |
| 4 | Semántica de herramienta sin verificar | Semántica | NO | 0.4 (documental) |
| 5 | Validado en un solo entorno | Paridad | SÍ-CORREGIDO | Baseline solo QA → `SUP-F1-01/02` mitigados con señales 19 y condiciones 20 |
| 6 | Override en runtime | Override | SÍ-CORREGIDO | `ini_set('memory_limit','512M')` (`bin/...:16`) prevalece sobre `768M` de la imagen (`E20` §1); umbral 256 MiB definido contra 512M |
| 7 | Best practice contra convención local | Dogmatismo | NO | Se conserva patrón Lua compare-and-delete existente (`:15-83`) |
| 8 | Interfaz pública alterada | Contract | SÍ-CORREGIDO | `claimIdempotencyKey` añade excepción para `$ttl<=0`; C2, 13.9 (captura en `async()`) y `R-IDEM-ZERO` |
| 9 | Datos persistidos sin migración | Data | SÍ-CORREGIDO | `batch:idem:*` legados → Sección 9 |
| 10 | Código muerto / compatibilidad especulativa | Clean | NO | Se eliminan `scanPattern`, `GC_SCAN_STEPS`, `$gcCursors`, `$lastGcMs`; excepciones registradas en 6.1 con retiro |
| 11 | Mapeo estático → dinámico | Abstracción | NO | Triage: no aplica |

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel A — Implementable, despliegue condicionado a las Validaciones Previas de la Sección 14** (reclasificado desde Nivel B el 2026-11-22). Condición que lo fijaba como B: `SUP-F1-03` y `SUP-F1-04` (S2) en estado `Abierto` y 0.8 abierta (baseline productivo); ahora `SUP-F1-03` y `SUP-F1-04` están `Mitigado` y 0.8 cerrada por decisión del usuario (producción = copia fiel de local; `docker-compose.yml:60,73` verificado). Todas las auditorías en `PASS` / `No` / `NO` o `SÍ-CORREGIDO`; sin S3/S4. La revisión verificada v4s (2026-11-18) no altera el nivel: sus 16 correcciones aplicables (V-F1-B1..B5, I1..I7, M1..M3, M5) están incorporadas y no abren supuestos nuevos. La revisión verificada w7k (2026-11-18) tampoco lo altera: aplica 11 correcciones de Fase 1 (W-F1-B1, I1, I2, I4(c), M1–M6, M8), deja 4 asignadas a SDD-BATCH-001 y abre `SUP-F1-04` (S2, verificación por el operador, cerrable en las Validaciones Previas de la Sección 14). La incorporación de los diagnósticos de producción (2026-11-19) tampoco lo altera: añade `REQ-F1-13` (P-F1-01) sin supuestos nuevos, documenta como refutado P-F1-02 y registra la presión de RAM `406,3 MiB / 512 MiB` en `worker-execution-maintenance-1` (`P-F1-03`). Las pasadas 3.ª, 4.ª y 5.ª (2026-11-19) tampoco lo alteran: sus correcciones (`X-F1-*`, `R4-F1-*`, `R5-F1-*`) son de precisión operativa, verificación y redacción. Totales: 13 REQ, 12 DEC, 7 INV + 1 preservada, 4 contratos, 4 supuestos (`SUP-F1-01..04`), 18 CA (`CA-F1-01..18`), 20 tests nuevos + 2 modificados (T-I10 con variante de latencia, sin test nuevo), 2 VM, 11 señales de observabilidad.

### Decisiones pendientes de un responsable externo

- Confirmar el responsable de la decisión de rollback (Sección 20) y de las excepciones de 6.1.
- Confirmar si existen monitores externos con umbral de heartbeat de 10 s (`SUP-F1-03`, `Mitigado` el 2026-11-22: no bloquea la implementación; la confirmación lo cerraría del todo).
- Redis ≥ 7.0 y `appendonly`/`appendfsync`: verificados en `docker-compose.yml:60,73` el 2026-11-22 (`SUP-F1-04`, `Mitigado`). Queda como validación previa al despliegue (Sección 14) el conteo de `batch:idem:*` con `TTL -1`, que informa `idem-expire-legacy`.
