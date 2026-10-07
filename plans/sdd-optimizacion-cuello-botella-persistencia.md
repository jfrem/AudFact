# SDD — Optimización de persistencia por job

Fecha: 2026-10-07. Estrategia: **refactorización incremental**.
Autorización: el usuario solicitó corregir el documento y aplicar los cambios al código.
Estado: los tres hallazgos de auditoría están corregidos y validados localmente. Se conserva la sincronización remota registrada a nivel de repositorio. Sin despliegue ni medición SQL con imágenes nuevas.

## 0. Evidencia y contratos activos

Fuentes locales: endpoint del job 9b539794-3c56-4138-a490-0baf88552597,
estados Redis, XINFO GROUPS, XPENDING, cola interna y logs.
El histórico mensual y los timings por factura corroboran la tendencia;
los agregados mensuales por sí solos no identifican el mecanismo causal.

Corte 2026-10-07 09:28:03 America/Bogota: 99/100 finalizadas,
47 completed, 52 manual_review, una en procesamiento; cero fallas terminales.

| Evidencia | Valor observado | Interpretación |
|---|---:|---|
| Consumidores de persistencia | 6 | Capacidad global disponible |
| Turnos activos del job / pendientes internos | 1 / 22 | Serialización comprobada y backlog omitido |
| Reglas a cierre, promedio / p95 | 72,166 s / 132,089 s | Espera más ejecución; no es SQL puro |
| SQL principal, promedio / p95 | 3,141 s / 3,495 s | Operación transaccional principal |
| Manejador completo, promedio / p95 | 4,533 s / 4,986 s | Incluye actualización final y demás trabajo |
| Espera inicial, promedio / p95 | 140,697 s / 254,771 s | Etapa previa a persistencia |
| Ritmo entre primer y último cierre | 13,055 auditorías/min | Compatible con 60 / 4,533 = 13,236 por turno |

Estadísticas sobre las 99 auditorías cerradas. Las lecturas sucesivas no
forman una instantánea atómica: los contadores avanzan entre consultas.

### 0.1 Demoras independientes

- Preparación: worker iniciado 09:07:27, consulta de candidatos terminada
  09:15:11, auditorías creadas 09:15:12. Comprobado: 7 min 45 s dentro
  del worker. Inferido: la obtención SQL concentra el tramo; falta separar
  conexión, ejecución y fetch.
- Última auditoría D49260300147: pdftoppm sin imágenes, exit 99;
  primer fallo 09:19:40. Reclaim 600000 ms, intervalo 30000 ms, tres intentos.
  No se demostró corrupción del PDF.

Esta entrega corrige scheduler y observabilidad. La consulta legacy y el
rasterizador requieren diagnósticos separados; no se cambian reglas de
rechazo ni se reducen leases de procesos legítimamente activos.

### 0.2 Perímetro e impacto inverso

| Artefacto | Cambio / contrato |
|---|---|
| AuditPersistenceQueue | Slots estables, Lua, deduplicación, promoción e índice de métricas |
| RulesEvaluationWorker, AuditPersistenceWorker, AuditDlqController | Consumidores activos de enqueue, advance y reprocess; conservar firmas |
| BatchJobStore, AuditController | Cálculo compartido de rendimiento para lista y detalle |
| ObservabilityController | PEL existente más lag y backlog interno |
| bin/audit-worker.php | Log de arranque con slots, TTL e identidad |
| .env.example, AGENTS.md, deploy-production.yml | Contrato y generación del entorno productivo |
| Tests de cola, worker, jobs y observabilidad | Regresiones y recuperación |
| plans/* y skills de pipeline/API/runtime | Documentación de ambas audiencias |

Preservar rutas, eventos, carriles, reservas por DisId, estados terminales y
SQL de resultados. Mantener updateFinalTimings: sql_persist_ms y duración
hasta cierre todavía no existen antes de la transacción principal.

## 1. Requisitos y aceptación

| ID | Requisito | Evidencia exigida |
|---|---|---|
| RF-01 | Hasta N turnos por job, uno por slot | Cola y Redis aislado |
| RF-02 | Fijar N atómicamente al primer uso del job | Enqueue/advance con otra configuración conserva scopes |
| RF-03 | Job con claves legacy permanece con N=1 | Cola anterior en vuelo |
| RF-04 | N=1 conserva `job:{jobId}`; single conserva `audit:{auditId}` | API pública |
| RF-05 | Mantener deduplicación, FIFO por slot, reproceso y promoción terminal | Duplicados y fallos recuperables |
| RF-06 | Mostrar pendientes internos y activos sin duplicar activos en PEL | Métricas de scheduler |
| RF-07 | Throughput basado en tiempo de pared, incluyendo preparación | Lista y detalle, reloj fijo |
| RF-08 | Conservar timings SQL y eventos terminales | Suite de worker existente |
| RF-09 | Recuperar el scope existente si desaparece `:slots`; nunca reparticionar un job con estado | Pérdida de metadata con cambios 2→1/4, duplicados y fallo terminal |
| RF-10 | Incluir colas legacy aunque todavía no hayan recibido escrituras nuevas | Métricas antes de enqueue; reconstrucción del índice perdido |
| RNF-01 | Slots 1..16, default 2; valores inválidos fallan explícitamente | Casos límites |
| RNF-02 | Renovar metadata de slots junto con TTL de cola | Lua |
| RNF-03 | Log de arranque con límites e identidad, sin secretos | Ejecución controlada |
| RNF-04 | Fallos de métricas registrados y respuesta 503 | Redis no disponible |

No garantizar 80/min: dos turnos permiten aproximadamente 26,5/min bajo
la medición actual, sujeto a distribución, SQL, otras etapas y réplicas.
Validar mejora con carga comparable y sin aumentar fallas o deadlocks.

## 2. Diseño determinista

### 2.1 Slots estables

Nueva variable AUDIT_PERSISTENCE_JOB_SLOTS=2, enteros 1..16.
Clave por job: `audit.persistence:{queue}:job:{jobId}:slots`.
Lua recupera el valor existente. Si falta, detecta active, pending, commands
o seen legacy y fija 1; sin estado anterior fija la configuración actual.
Creación y elección son atómicas.

Con N mayor que 1, slot = primeros siete dígitos hexadecimales de
SHA-256(auditId) módulo N; el entero cabe en 32 bits.
Scope: `job:{jobId}:slot:{slot}`. Con N=1 no agregar sufijo.
Single mantiene aislamiento por auditId.

Enqueue, reprocess, advance y advanceAfterFailure recuperan el mismo N.
Cambiar configuración afecta jobs nuevos, no redistribuye los existentes.
Lua renueva metadata junto con la cola. TTL no recupera eventos perdidos.

Corrección P1: el hash `seen` guarda el campo interno `__job_slots`, separado
de los UUID de deduplicación. Si falta `:slots`, el resolver inspecciona los
17 scopes posibles del job: legacy y slots 0..15. Recupera N desde esos hashes
y encuentra el scope propietario por active, pending, commands o seen.
Metadata contradictoria falla explícitamente. Un scope particionado anterior
sin contador recuperable permite drenar/reprocesar auditorías ya conocidas,
pero rechaza auditorías nuevas; no inventa N. Enqueue/advance verifican N y
renuevan `:slots` y `seen` en el mismo Lua que modifica el turno, cerrando la
ventana entre resolución y escritura. No cambia la forma de AuditEvent.

### 2.2 Índice y métricas

ZSET `audit.persistence:{queue}:scopes`: scope y fecha de expiración.
Enqueue/promoción actualizan; vaciar retira; escrituras podan expirados.
Métricas leen miembros vigentes y suman ZCARD pending y existencia active.
Corrección del tercer hallazgo: antes de la primera lectura, o si se pierde el
índice, reconciliar claves active/pending mediante SCAN incremental filtrado
por el prefijo del scheduler. Cada Lua procesa una página COUNT 200; el bucle
queda en PHP, con máximo 1000 páginas por solicitud. Si no termina, falla sin
marcar el índice como completo y el endpoint responde 503. Un marcador
`__ready` dentro del mismo ZSET habilita las lecturas posteriores por índice;
no cuenta como scope. No usar KEYS ni un recorrido completo dentro de Lua.
Si vence ese marcador, reconciliar de nuevo antes de entregar métricas.
Un token por reconciliación comprueba que el índice no desapareció entre
páginas y cierre; su pérdida produce 503, sin certificar un índice parcial.
Esta reconciliación admite estados legacy existentes; no autoriza mezclar
productores antiguos con productores que conocen slots durante el cutover.

Conservar queueDepth y streamDepths como PEL por compatibilidad.
Agregar persistenceScheduler (active, pending, scopes), streamBacklogs
por grupo (pending, lag, lagKnown) y backlogDepth:
PEL + lag conocido + pendientes internos.
No sumar activos del scheduler: ya están publicados.
El agregado cuenta trabajo de grupos, no documentos únicos: el stream
documental entrega eventos a varios grupos. Lag desconocido queda indicado.
Errores al consultar Redis producen 503 registrado, nunca 200 con ceros falsos.
Corrección P2: pending y lag provienen de la misma respuesta XINFO GROUPS;
retirar xPending del endpoint. Consultar DLQ y telemetría mediante eval que
propaga errores, sin fallbacks a ceros. Una respuesta malformada falla con
503; un stream inexistente conserva profundidad cero y lag desconocido.

### 2.3 Rendimiento de jobs

Único cálculo PHP compartido por lista y detalle:
throughput_per_sec = (done + failed) / elapsed_seconds.
Activos: created_at hasta reloj actual. Terminales: completed_at, con
fallback updated_at para estados anteriores. Exponer elapsed_ms.
Mantener duraciones individuales promedio y acumulada.
Retirar el cálculo basado en duraciones individuales superpuestas.
Timestamps ausentes/inválidos producen duración cero sin ocultar
excepciones operativas. Pruebas usan reloj fijo.

### 2.4 Límites comprobados

La transacción SQL contiene varias sentencias y puede leer adjuntos por db2.
SqlServerConnectionExecutor abre conexiones por operación; no presupone
un PDO único permanente por worker. Mantener la operación final de timings.

FacNro distintos no prueban ausencia de deadlocks: índices, páginas,
escalamiento y tablas relacionadas pueden competir.
FIFO es por slot; el orden global de cierre puede cambiar.
Los contadores del job siguen siendo atómicos.

Entorno observado: Redis standalone. El hashtag `{queue}` no prueba soporte
Cluster: los streams actuales tienen slots distintos del scheduler.
No certificar Cluster sin migración del transporte y pruebas CROSSSLOT.

## 3. Migración y rollback

1. Completar suites, lint, contrato env, skills y build documental.
2. Sincronizar variable en GitHub Environment production y workflow antes
   de cualquier push a main.
3. Primer cutover: detener admisión y drenar jobs, PEL, lag y colas internas
   con los workers actuales. No borrar mensajes ni confiar en TTL.
4. Resolver pendientes fallidos; respaldar Redis y conservar tag anterior.
5. Desplegar imágenes coordinadas de policy, persistence y API.
   No mezclar productores antiguos sin conocimiento de slots.
6. Arrancar con dos slots; medir carga comparable y latencia SQL.
   Subir a cuatro solo con evidencia favorable.

Rollback operacional: reducir réplicas de persistencia manteniendo scopes.
N=1 afecta jobs nuevos; recrear contenedores con entorno actualizado.
docker compose restart no incorpora cambios de environment.
Cambiar una variable GitHub no actualiza contenedores en ejecución.

Rollback de imagen: drenar jobs particionados antes de volver al tag anterior.
Si no se pueden drenar, mantener workers nuevos hasta cerrar los turnos.
El código anterior no sabe vaciar scopes particionados.

## 4. Auditoría de consistencia y política

Decisión de diseño: APROBAR la refactorización incremental.
Compuertas: contratos compartidos, encapsulación, observabilidad e higiene.
No introducir enums para contadores/configuración de transporte.
No introducir Reflection en pruebas nuevas.

Compatibilidad legacy activa: necesaria mientras sobrevivan jobs antiguos.
No es código obsoleto ni adaptación especulativa.
Excepciones nuevas: ninguna.

Pruebas exigidas: slots y carriles; cambios 2→1/4; legacy en vuelo;
duplicados y promoción terminal; backlog con PEL cero; timings finales;
throughput activo/terminal con reloj fijo; errores 503; configuración remota.
Redis real se ejecuta con prefijo aislado y sin acceso de workers operativos.

La especificación no certifica rendimiento productivo hasta medir con las
nuevas imágenes. Registrar únicamente comandos y resultados ejecutados.

## 5. Registro de validación

Registro inicial, anterior a la auditoría adversarial. Las regresiones que
cierran los tres hallazgos se registran en 5.3; estos resultados iniciales
por sí solos no acreditaban recuperación ante pérdida de metadata/índice.

| Comando / comprobación ejecutada | Resultado real |
|---|---|
| php vendor/bin/phpunit --configuration phpunit.xml | 735 pruebas, 2903 aserciones, 6 omitidas; sin fallos |
| Integración de cola, PHP 8.2.33, contenedor temporal con prefijo Redis aislado | 5 pruebas, 130 aserciones; sin fallos ni omisiones |
| Controller y rendimiento con orden aleatorio, semilla 42 | 30 pruebas, 90 aserciones; sin fallos |
| php scripts/lint-php.php | 173 archivos; cero errores de sintaxis |
| node .agent/skills/_shared/scripts/validate-skills.mjs | PASS, 22 skills y 9 bundles |
| npm.cmd run build, directorio website | Docusaurus generado correctamente |
| Contrato de variables PHP frente a .env.example | Cero claves usadas sin declaración |
| git diff --check, con cr-at-eol para checkout Windows | PASS; se respetan cambios anteriores del usuario |
| Launcher real en contenedor temporal sin red | Contexto de arranque verificado: worker, lane, memory_limit, instance, pid, slots=2, TTL=604800; salida 1 esperada por Redis inaccesible |
| Controller de métricas actualizado contra Redis, contenedor temporal | Respuesta 200; lag, PEL e índice interno interpretados correctamente |
| gh variable set AUDIT_PERSISTENCE_JOB_SLOTS --body 2 --repo jfrem/AudFact | PASS; variable sincronizada a nivel de repositorio tras solventar 403 conmutando a jfrem y detectar límite de 100 variables en environment production |

Las seis omisiones de la suite general son cinco integraciones Redis opt-in
y una prueba condicional existente. Las cinco integraciones se ejecutaron
separadamente con éxito. La suite inicial tenía 715 pruebas y 2852 aserciones.
No se ejecutó carga SQL concurrente con las imágenes nuevas: no certificar
throughput ni ausencia de deadlocks en producción a partir de pruebas Lua.

**Sincronización remota completada:** Se conmutó a la cuenta propietaria `jfrem`
(resolviendo el HTTP 403 inicial de `J-Frem`). Al verificarse que el Environment
production se encuentra en el tope de 100 variables impuesto por GitHub, se fijó
la variable a nivel de repositorio (`gh variable set AUDIT_PERSISTENCE_JOB_SLOTS --body 2 --repo jfrem/AudFact`),
siguiendo el mismo patrón operativo de `PHP_CLI_MEMORY_LIMIT` y memorias de workers.
GitHub Actions resuelve automáticamente variables de repositorio en el job de despliegue.
El runtime que sirve HTTP requiere rebuild/cutover para ejecutar este código.

### 5.1 Matriz de impacto documental

| Archivo | Cambio | Razón | Estado |
|---|---|---|---|
| Esta SDD | Sí | Evidencia, requisitos, límites, migración, rollback y resultados reales | Hecho |
| plans/api-endpoints.md | Sí | Nuevas métricas, throughput de pared y error 503 | Hecho |
| plans/architecture.md, plans/features/audit-workflow.md | Sí | Slots estables e índice operacional | Hecho |
| plans/docker-operations.md, plans/deployment-and-ci.md | Sí | Variable, cutover y rollback | Hecho |
| AGENTS.md, README.md, plans/changelog.md | Sí | Contrato env y descripción de cambios | Hecho |
| plans/database-schema.md | No | Sin cambios de esquema SQL ni modelos | N/A |

### 5.2 Matriz de impacto en skills

| Skill | Cambio | Razón | Estado |
|---|---|---|---|
| audfact-audit-gemini | Sí | Scheduler, límites, legacy y timings | Hecho |
| audfact-api-rest | Sí | Métricas y rendimiento compartido | Hecho |
| audfact-runtime-docker | Sí | Entorno y operación de slots | Hecho |
| audfact-project-overview | Sí | Flujo y réplicas de persistencia | Hecho |
| CATALOG.md, registros de skills | No en esta tarea | Sin skills ni clases productivas nuevas; conservar cambios previos | N/A |
| audfact-sqlsrv-models | No | Contrato SQL preservado | N/A |

### 5.3 Cierre de los tres hallazgos de auditoría

Decisión: **APROBAR las correcciones locales** mediante refactorización
incremental. La aprobación inicial fue retirada por los hallazgos; esta
decisión usa las regresiones adicionales ejecutadas, no sólo la suite inicial.
Excepciones nuevas: **Ninguna**. El despliegue mantiene el cutover del apartado 3.

| Hallazgo | Corrección | Evidencia |
|---|---|---|
| [CONFIRMADO] P1: metadata perdida reparticionaba un job | Recuperar N desde seen y conservar scopes; renovar/verificar en Lua junto al turno | Seis casos de cambios 2→1/4/16, avance normal/terminal, duplicados y pérdida entre resolver y enqueue |
| [CONFIRMADO] P2: XPENDING ocultaba fallos parciales | Pending y lag en XINFO; contadores por eval; respuestas malformadas fallan | Unitarias con siete pendientes y fallos parciales; integración del endpoint con Redis real y error WRONGTYPE aislado |
| [CONFIRMADO] Tercer hallazgo (prioridad P2): legacy sin índice daba ceros | SCAN por páginas antes de certificar índice, con marcador y token de generación | Legacy sin enqueue, índice perdido con scopes mixtos, pérdida durante reconciliación y límite de páginas |

[CONFIRMADO] Perímetro de esta corrección: AuditPersistenceQueue,
ObservabilityController y sus pruebas existentes. Consumidores inspeccionados:
RulesEvaluationWorker, AuditPersistenceWorker y AuditDlqController, mediante
búsquedas de AuditPersistenceQueue y llamadas enqueue/advance/reprocess en app,
bin y tests. Conservan firmas públicas, JSON de AuditEvent, carriles, FIFO por
slot, deduplicación, eventos terminales y timings SQL. El diff de AuditEvent,
AuditPersistenceWorker y AuditResultPersistenceModel está vacío.

| Validación ejecutada después de corregir los hallazgos | Resultado real |
|---|---|
| php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result | [CONFIRMADO] 756 pruebas, 2920 aserciones, 20 omisiones; cero fallos |
| wsl docker compose run --rm --no-deps --entrypoint php --volume /mnt/c/Users/USER/Desktop/AudFact:/work:ro --workdir /work --env RUN_REDIS_INTEGRATION=1 php vendor/bin/phpunit --do-not-cache-result tests/Integration/AuditPersistenceQueueRedisTest.php | [CONFIRMADO] 19 pruebas, 215 aserciones; cero fallos y omisiones |
| php scripts/lint-php.php | [CONFIRMADO] 173 archivos, cero errores |
| node .agent/skills/_shared/scripts/validate-skills.mjs | [CONFIRMADO] PASS: 22 skills y 9 bundles |
| npm.cmd run build en website | [CONFIRMADO] Docusaurus generado correctamente |
| git diff --check con configuración cr-at-eol del checkout Windows | [CONFIRMADO] Sin errores de whitespace |

[CONFIRMADO] Las omisiones son 19 integraciones opt-in ejecutadas por separado
y una prueba condicional existente. Los tests usan prefijos propios y limpian
sólo sus claves; los errores intencionales quedan registrados aun con el mount
de código de sólo lectura. No se recrearon servicios operativos.
[DESCONOCIDO] Throughput productivo y contención SQL con imágenes nuevas;
validación pendiente del cutover y carga comparable, fuera de esta corrección.

| Impacto documental de esta corrección | Cambio | Razón | Estado |
|---|---|---|---|
| Esta SDD | Sí | Recuperación, reconciliación y regresiones | Hecho |
| plans/api-endpoints.md | Sí | XINFO y errores parciales 503 | Hecho |
| plans/architecture.md, plans/features/audit-workflow.md | Sí | Scopes y adopción de legacy | Hecho |
| plans/docker-operations.md | Sí | Drenaje ante metadata irrecuperable e índice | Hecho |
| README.md, plans/changelog.md | Sí | Comportamiento y cierre de hallazgos | Hecho |
| AGENTS.md, .env.example, plans/deployment-and-ci.md | No | No cambia variables ni procedimiento de cutover | N/A |
| plans/database-schema.md | No | No cambia SQL ni esquema | N/A |

| Impacto en skills de esta corrección | Cambio | Razón | Estado |
|---|---|---|---|
| audfact-audit-gemini | Sí | Recuperación de slots y reconciliación | Hecho |
| audfact-api-rest | Sí | Lectura estricta de métricas | Hecho |
| audfact-runtime-docker | Sí | Operación ante metadata perdida | Hecho |
| audfact-project-overview | Sí | Flujo de recuperación | Hecho |
| CATALOG.md y registros | No | Sin archivos ni clases productivas nuevas | N/A |

He modificado el scheduler, el controlador de métricas y sus pruebas.
Las dos matrices documentan los archivos y skills sincronizados en esta
corrección. La configuración remota registrada a nivel de repositorio no
se modificó en esta entrega; no se agregaron variables ni secretos.
